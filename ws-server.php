<?php
/**
 * Holler Back WebSocket process. Long-running under systemd.
 *
 * Bindings (see config.php: ws_bind / ws_port — default 127.0.0.1:8090):
 *   Nginx proxy_pass wss://<host>/ws/  →  http://127.0.0.1:8090/
 *
 * Endpoints:
 *   GET /ws/widget/<conv_token>?workspace=<install_token>  — buyer's socket
 *   GET /ws/admin?token=<mint>                             — operator fan-in
 *
 *   POST /_internal/publish                                — loopback-only.
 *     Called by public/api.php after any DB write that other sockets should
 *     see. Body is JSON {type, workspace_id, conv_id, ...}. We refuse if the
 *     source address isn't loopback.
 *
 * Implementation notes:
 *   - Hand-rolled RFC 6455. No Ratchet, no Composer. Text frames only; ping/pong
 *     handled; close cleanly on 0x8. Fragmented frames are rejected (400) — the
 *     browser never sends them for the tiny messages we deal in, and supporting
 *     reassembly triples the code.
 *   - Single-process, single-threaded, stream_select loop. Fine for hundreds
 *     of concurrent chats on a small VPS. If you outgrow this: shard by
 *     workspace_id across N processes behind a hash-based nginx upstream.
 *   - No auth on the widget socket beyond {workspace_token, conv_token} — the
 *     conv_token is 128 bits of entropy, only known to that buyer. Origin is
 *     enforced on connect against the workspace's allowed_origins.
 *   - Admin socket auth: query-string token (short-lived HMAC, ties to
 *     passphrase hash). See Auth::mintWsToken / verifyWsToken.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Push.php';

class HollerWsServer {

	private $bind;
	private $port;
	private $listener;              // resource, listening socket
	private $clients = array();     // fd_id => [socket, buf, state, endpoint_meta]
	private $stopping = false;

	public function __construct() {
		$this->bind = (string) Config::get( 'ws_bind', '127.0.0.1' );
		$this->port = (int)    Config::get( 'ws_port', 8090 );
	}

	public function run() {
		$ctx = stream_context_create();
		$this->listener = stream_socket_server(
			"tcp://{$this->bind}:{$this->port}",
			$errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx
		);
		if ( ! $this->listener ) {
			fwrite( STDERR, "listen failed: $errstr ($errno)\n" );
			exit( 1 );
		}
		stream_set_blocking( $this->listener, false );
		hb_log( "ws-server: listening on {$this->bind}:{$this->port}" );

		pcntl_async_signals( true );
		pcntl_signal( SIGTERM, function () { $this->stopping = true; } );
		pcntl_signal( SIGINT,  function () { $this->stopping = true; } );

		$lastKeepalive = time();

		while ( ! $this->stopping ) {
			$reads = array( $this->listener );
			foreach ( $this->clients as $id => $c ) { $reads[] = $c['sock']; }
			$writes  = null;
			$excepts = null;

			$n = @stream_select( $reads, $writes, $excepts, 1, 0 );
			if ( $n === false ) { continue; }

			// Accept
			if ( in_array( $this->listener, $reads, true ) ) {
				$peer = @stream_socket_accept( $this->listener, 0, $peername );
				if ( $peer ) {
					stream_set_blocking( $peer, false );
					$id = (int) $peer;
					$this->clients[ $id ] = array(
						'sock'  => $peer,
						'buf'   => '',
						'state' => 'handshake',      // handshake | ws | internal
						'peer'  => $peername,
						'meta'  => array(),
					);
				}
			}

			// Per-client I/O
			foreach ( $reads as $sock ) {
				if ( $sock === $this->listener ) { continue; }
				$id = (int) $sock;
				if ( ! isset( $this->clients[ $id ] ) ) { continue; }
				$chunk = @fread( $sock, 8192 );
				if ( $chunk === false || $chunk === '' ) {
					$eof = feof( $sock );
					if ( $eof ) { $this->drop( $id ); continue; }
				}
				if ( $chunk !== false && $chunk !== '' ) {
					$this->clients[ $id ]['buf'] .= $chunk;
					$this->pump( $id );
				}
			}

			// Periodic housekeeping — ping open WS every 30s so idle NATs don't reap.
			if ( time() - $lastKeepalive > 30 ) {
				$lastKeepalive = time();
				foreach ( $this->clients as $id => $c ) {
					if ( $c['state'] === 'ws' ) { $this->sendFrame( $id, '', 0x9 ); } // ping
				}
			}
		}

		hb_log( 'ws-server: stopping' );
		foreach ( array_keys( $this->clients ) as $id ) { $this->drop( $id ); }
		@fclose( $this->listener );
	}

	/* ---------------- protocol pump ---------------- */

	private function pump( $id ) {
		$c = &$this->clients[ $id ];
		if ( $c['state'] === 'handshake' ) {
			// Wait for full HTTP request head
			$eoh = strpos( $c['buf'], "\r\n\r\n" );
			if ( $eoh === false ) {
				if ( strlen( $c['buf'] ) > 16384 ) { $this->httpAbort( $id, 431 ); }
				return;
			}
			$head = substr( $c['buf'], 0, $eoh );
			$rest = substr( $c['buf'], $eoh + 4 );
			$this->handleHandshake( $id, $head, $rest );
			return;
		}
		if ( $c['state'] === 'internal' ) {
			// Waiting for HTTP body; buf already contains headers + partial body
			return;
		}
		if ( $c['state'] === 'ws' ) {
			$this->readFrames( $id );
		}
	}

	private function handleHandshake( $id, $head, $rest ) {
		$lines  = explode( "\r\n", $head );
		$reqLine = array_shift( $lines );
		if ( ! preg_match( '#^(GET|POST) (\S+) HTTP/1\.[01]$#', (string) $reqLine, $m ) ) {
			$this->httpAbort( $id, 400 ); return;
		}
		$method = $m[1]; $uri = $m[2];
		$headers = array();
		foreach ( $lines as $l ) {
			$p = strpos( $l, ':' );
			if ( $p === false ) { continue; }
			$k = strtolower( trim( substr( $l, 0, $p ) ) );
			$v = trim( substr( $l, $p + 1 ) );
			$headers[ $k ] = $v;
		}

		// Internal publish path — loopback only, no WS upgrade
		if ( $method === 'POST' && $uri === '/_internal/publish' ) {
			if ( ! $this->isLoopback( $this->clients[ $id ]['peer'] ?? '' ) ) {
				$this->httpAbort( $id, 403 ); return;
			}
			$need = (int) ( $headers['content-length'] ?? 0 );
			$body = $rest;
			// buffer more if we don't have the whole body yet
			$this->clients[ $id ]['state'] = 'internal';
			$this->clients[ $id ]['meta']  = array( 'need' => $need, 'body' => $body );
			$this->flushInternal( $id );
			return;
		}

		if ( $method !== 'GET' ) { $this->httpAbort( $id, 405 ); return; }

		// WebSocket upgrade validation (RFC 6455 §4.2.1)
		if ( strcasecmp( $headers['upgrade'] ?? '', 'websocket' ) !== 0 ) { $this->httpAbort( $id, 426 ); return; }
		if ( stripos( $headers['connection'] ?? '', 'upgrade' ) === false ) { $this->httpAbort( $id, 426 ); return; }
		$key = $headers['sec-websocket-key'] ?? '';
		if ( $key === '' ) { $this->httpAbort( $id, 400 ); return; }

		// Route dispatch
		$route = $this->routeFor( $uri, $headers );
		if ( ! $route ) { $this->httpAbort( $id, 404 ); return; }
		if ( $route['error'] ?? null ) { $this->httpAbort( $id, $route['status'] ?? 403 ); return; }

		$accept = base64_encode( sha1( $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true ) );
		$resp = "HTTP/1.1 101 Switching Protocols\r\n"
			. "Upgrade: websocket\r\n"
			. "Connection: Upgrade\r\n"
			. "Sec-WebSocket-Accept: $accept\r\n\r\n";
		@fwrite( $this->clients[ $id ]['sock'], $resp );

		$this->clients[ $id ]['state']  = 'ws';
		$this->clients[ $id ]['buf']    = '';
		$this->clients[ $id ]['meta']   = $route;

		hb_log( 'ws-server: upgraded ' . ( $this->clients[ $id ]['peer'] ?? '?' ) . ' -> ' . $route['kind'] . ' ' . ( $route['conv_id'] ?? '' ) );

		// send a hello envelope so the client knows it's live
		$this->sendJson( $id, array( 'type' => 'hello', 'kind' => $route['kind'] ) );
	}

	private function routeFor( $uri, $headers ) {
		$u = parse_url( $uri );
		$path  = (string) ( $u['path']  ?? '' );
		$query = array();
		parse_str( (string) ( $u['query'] ?? '' ), $query );

		if ( preg_match( '#^/ws/widget/([a-f0-9]{8,64})$#', $path, $m ) ) {
			$ws_tok = (string) ( $query['workspace'] ?? '' );
			$conv_tok = $m[1];
			$ws = Workspace::byToken( $ws_tok );
			if ( ! $ws ) { return array( 'error' => 'no_ws', 'status' => 404 ); }
			$origin = (string) ( $headers['origin'] ?? '' );
			if ( ! Workspace::originAllowed( $ws, $origin ) ) { return array( 'error' => 'bad_origin', 'status' => 403 ); }
			$conv = store()->one(
				'SELECT id, workspace_id, token FROM conversations WHERE workspace_id = ? AND token = ? AND status != "closed"',
				array( (int) $ws['id'], $conv_tok )
			);
			if ( ! $conv ) { return array( 'error' => 'no_conv', 'status' => 404 ); }
			return array(
				'kind'          => 'widget',
				'workspace_id'  => (int) $ws['id'],
				'conv_id'       => (int) $conv['id'],
				'conv_token'    => (string) $conv['token'],
			);
		}

		if ( $path === '/ws/admin' ) {
			$tok = (string) ( $query['token'] ?? '' );
			if ( ! Auth::verifyWsToken( $tok ) ) { return array( 'error' => 'bad_token', 'status' => 401 ); }
			$ws = Workspace::primary();
			return array(
				'kind'         => 'admin',
				'workspace_id' => $ws ? (int) $ws['id'] : 0,
			);
		}

		return null;
	}

	/* ---------------- internal publish handler ---------------- */

	private function flushInternal( $id ) {
		$c = &$this->clients[ $id ];
		$body = (string) $c['meta']['body'];
		// buffer subsequent reads too (rare — nginx sends small JSON in one packet)
		if ( strlen( $body ) < (int) $c['meta']['need'] ) { return; }
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			$this->httpRespond( $id, 400, 'bad json' );
			return;
		}
		$this->dispatchPublish( $json );
		$this->httpRespond( $id, 204, '' );
	}

	private function dispatchPublish( $env ) {
		$type = (string) ( $env['type'] ?? '' );
		$wsId = (int) ( $env['workspace_id'] ?? 0 );
		$convId = (int) ( $env['conv_id'] ?? 0 );

		// Fan out to admins on this workspace
		foreach ( $this->clients as $id => $c ) {
			if ( $c['state'] !== 'ws' ) { continue; }
			$m = $c['meta'];
			if ( ( $m['kind'] ?? '' ) === 'admin' && (int) $m['workspace_id'] === $wsId ) {
				$this->sendJson( $id, $env );
			}
		}
		// And to the buyer socket for this specific conversation (only their own conv)
		foreach ( $this->clients as $id => $c ) {
			if ( $c['state'] !== 'ws' ) { continue; }
			$m = $c['meta'];
			if ( ( $m['kind'] ?? '' ) === 'widget' && (int) $m['conv_id'] === $convId ) {
				// filter what the buyer sees
				if ( $type === 'message.new' ) {
					$sender = (string) ( $env['msg']['sender'] ?? '' );
					// A buyer's own send comes back via the POST response — no need to echo.
					if ( $sender !== 'buyer' ) { $this->sendJson( $id, $env ); }
				} elseif ( $type === 'conversation.closed' ) {
					$this->sendJson( $id, $env );
				}
			}
		}

		// Web Push fan-out — only for buyer→operator new messages, and only if
		// no admin socket is currently connected for this workspace.
		if ( $type === 'message.new' && ( $env['msg']['sender'] ?? '' ) === 'buyer' ) {
			$adminOnline = false;
			foreach ( $this->clients as $c ) {
				if ( $c['state'] === 'ws'
					&& ( $c['meta']['kind'] ?? '' ) === 'admin'
					&& (int) ( $c['meta']['workspace_id'] ?? 0 ) === $wsId ) {
					$adminOnline = true; break;
				}
			}
			if ( ! $adminOnline ) {
				Push::fanoutNewMessage( $wsId, $convId, (string) ( $env['msg']['body'] ?? '' ) );
			}
		}
	}

	private function isLoopback( $peer ) {
		$peer = (string) $peer;
		return $peer === '' || strpos( $peer, '127.0.0.1:' ) === 0 || strpos( $peer, '[::1]:' ) === 0;
	}

	/* ---------------- HTTP helpers (pre-upgrade + internal) ---------------- */

	private function httpAbort( $id, $code ) {
		$this->httpRespond( $id, $code, '' );
	}

	private function httpRespond( $id, $code, $body ) {
		$statusText = array( 200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized',
			403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 426 => 'Upgrade Required',
			431 => 'Request Header Fields Too Large' );
		$st = $statusText[ $code ] ?? 'Error';
		$resp = "HTTP/1.1 $code $st\r\nContent-Length: " . strlen( (string) $body ) . "\r\nConnection: close\r\n\r\n" . $body;
		@fwrite( $this->clients[ $id ]['sock'], $resp );
		$this->drop( $id );
	}

	/* ---------------- WS frame layer ---------------- */

	private function readFrames( $id ) {
		while ( true ) {
			$c = &$this->clients[ $id ];
			$buf = $c['buf'];
			$len = strlen( $buf );
			if ( $len < 2 ) { return; }

			$b1 = ord( $buf[0] );
			$b2 = ord( $buf[1] );
			$fin    = ( $b1 & 0x80 ) !== 0;
			$opcode = $b1 & 0x0F;
			$masked = ( $b2 & 0x80 ) !== 0;
			$plen   = $b2 & 0x7F;
			$off    = 2;

			if ( ! $masked ) { $this->drop( $id ); return; }  // client MUST mask (RFC 6455)
			if ( ! $fin && $opcode !== 0x0 ) { $this->drop( $id ); return; } // no fragments in MVP

			if ( $plen === 126 ) {
				if ( $len < $off + 2 ) { return; }
				$plen = unpack( 'n', substr( $buf, $off, 2 ) )[1];
				$off += 2;
			} elseif ( $plen === 127 ) {
				if ( $len < $off + 8 ) { return; }
				$plen = unpack( 'J', substr( $buf, $off, 8 ) )[1];
				$off += 8;
			}
			if ( $plen > 65535 ) { $this->drop( $id ); return; } // caps at 64K — chat, not file transfer
			if ( $len < $off + 4 + $plen ) { return; }

			$mask = substr( $buf, $off, 4 ); $off += 4;
			$payload = substr( $buf, $off, $plen );
			$off += $plen;

			// Unmask
			$decoded = '';
			for ( $i = 0; $i < $plen; $i++ ) {
				$decoded .= chr( ord( $payload[ $i ] ) ^ ord( $mask[ $i % 4 ] ) );
			}
			$c['buf'] = substr( $buf, $off );

			if ( $opcode === 0x1 ) {           // text
				$this->onText( $id, $decoded );
			} elseif ( $opcode === 0x9 ) {     // ping
				$this->sendFrame( $id, $decoded, 0xA );
			} elseif ( $opcode === 0xA ) {     // pong — no-op
				continue;
			} elseif ( $opcode === 0x8 ) {     // close
				$this->sendFrame( $id, "\x03\xe8", 0x8 );
				$this->drop( $id );
				return;
			} else {
				$this->drop( $id );
				return;
			}
		}
	}

	private function onText( $id, $text ) {
		$j = json_decode( $text, true );
		if ( ! is_array( $j ) ) { return; }
		$type = (string) ( $j['type'] ?? '' );
		// For MVP the browser doesn't need to send anything over WS — messages
		// go via POST /api/message/send. We still accept a "ping" for symmetry.
		if ( $type === 'ping' ) {
			$this->sendJson( $id, array( 'type' => 'pong', 't' => time() ) );
		}
	}

	private function sendJson( $id, $obj ) {
		$this->sendFrame( $id, json_encode( $obj ), 0x1 );
	}

	private function sendFrame( $id, $payload, $opcode = 0x1 ) {
		if ( ! isset( $this->clients[ $id ] ) ) { return; }
		$len = strlen( $payload );
		$hdr = chr( 0x80 | ( $opcode & 0x0F ) );
		if ( $len < 126 ) {
			$hdr .= chr( $len );
		} elseif ( $len < 65536 ) {
			$hdr .= chr( 126 ) . pack( 'n', $len );
		} else {
			$hdr .= chr( 127 ) . pack( 'J', $len );
		}
		@fwrite( $this->clients[ $id ]['sock'], $hdr . $payload );
	}

	private function drop( $id ) {
		if ( ! isset( $this->clients[ $id ] ) ) { return; }
		@fclose( $this->clients[ $id ]['sock'] );
		unset( $this->clients[ $id ] );
	}
}

if ( ! extension_loaded( 'pcntl' ) ) {
	fwrite( STDERR, "pcntl extension required (apt install php-cli, or use CLI SAPI).\n" );
	exit( 1 );
}
( new HollerWsServer() )->run();
