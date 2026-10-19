<?php
/**
 * Operator notifications: Web Push (via the outbox), Discord webhook, and the
 * buyer-facing away message. All sending happens in worker/notify.php, never
 * in the WS loop or a request — a slow Discord or push service can't stall chat.
 *
 * Settings live in kv as JSON under notify_<workspace_id>; the last Discord
 * delivery result under notify_status_<workspace_id>.
 */
require_once __DIR__ . '/Push.php';

class Notify {

	const DISCORD_RE = '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/webhooks/\d{5,25}/[A-Za-z0-9_-]{20,100}$#';

	public static function defaults() {
		return array(
			'discord_url'      => '',
			'include_text'     => false,
			'on_new'           => true,
			'on_unanswered'    => true,
			'unanswered_min'   => 3,
			'away_text'        => '',
			'away_min'         => 5,
			'since'            => 0,      // only announce buyer messages sent after Discord was configured
		);
	}

	public static function settings( $workspace_id ) {
		$row = store()->kvGet( 'notify_' . (int) $workspace_id );
		$s = $row ? json_decode( (string) $row['v'], true ) : null;
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function save( $workspace_id, $s ) {
		store()->kvSet( 'notify_' . (int) $workspace_id, json_encode( $s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	public static function status( $workspace_id ) {
		$row = store()->kvGet( 'notify_status_' . (int) $workspace_id );
		$s = $row ? json_decode( (string) $row['v'], true ) : null;
		return is_array( $s ) ? $s : null;
	}

	/** "…/webhooks/1234…wxyz" — enough to recognise, not enough to post with. */
	public static function urlHint( $url ) {
		if ( ! preg_match( '#/webhooks/(\d+)/(\S+)$#', (string) $url, $m ) ) { return ''; }
		return '…/webhooks/' . substr( $m[1], 0, 4 ) . '…/…' . substr( $m[2], -4 );
	}

	public static function validDiscordUrl( $url ) {
		return (bool) preg_match( self::DISCORD_RE, (string) $url );
	}

	/* ---------------- outbox (written by ws-server, drained by the worker) ---------------- */

	public static function enqueue( $workspace_id, $kind, $payload ) {
		store()->q(
			'INSERT INTO notify_outbox(workspace_id, kind, payload, created_at) VALUES(?,?,?,?)',
			array( (int) $workspace_id, (string) $kind, json_encode( $payload, JSON_UNESCAPED_UNICODE ), time() )
		);
	}

	public static function drainOutbox() {
		$rows = store()->all( 'SELECT * FROM notify_outbox WHERE done_at = 0 ORDER BY id LIMIT 50' );
		foreach ( $rows as $r ) {
			// Mark first: a send that crashes the worker must not repeat forever.
			store()->q( 'UPDATE notify_outbox SET done_at = ? WHERE id = ?', array( time(), (int) $r['id'] ) );
			$p = json_decode( (string) $r['payload'], true ) ?: array();
			try {
				if ( $r['kind'] === 'push' ) {
					if ( ! self::budget( 'push', $r['workspace_id'] ) ) { self::bumpSuppressed( 'push', $r['workspace_id'] ); continue; }
					$skipped = self::takeSuppressed( 'push', $r['workspace_id'] );
					$body = (string) ( $p['body'] ?? '' ) . ( $skipped ? " (+$skipped more)" : '' );
					Push::fanoutNewMessage( (int) $r['workspace_id'], (int) ( $p['conv_id'] ?? 0 ), $body );
				} elseif ( $r['kind'] === 'push_test' ) {
					Push::fanout( (int) $r['workspace_id'], 'Holler Back test', 'Push works on this device.', '/admin/', 0 );
				}
			} catch ( Throwable $e ) {
				hb_log( 'notify: outbox ' . $r['kind'] . ' failed: ' . $e->getMessage() );
			}
		}
		return count( $rows );
	}

	/* ---------------- flood cap ---------------- */

	/** Anyone can open chats, so alerts per channel per hour are capped (config notify_max_per_hour). */
	private static function budget( $channel, $workspace_id ) {
		return rate_hit( "notify:$channel:" . (int) $workspace_id, 3600, max( 1, (int) Config::get( 'notify_max_per_hour', 30 ) ) );
	}
	private static function bumpSuppressed( $channel, $workspace_id ) {
		$k = "notify_suppressed_{$channel}_" . (int) $workspace_id;
		store()->kvSet( $k, (string) ( (int) ( store()->kvGet( $k )['v'] ?? 0 ) + 1 ) );
	}
	private static function takeSuppressed( $channel, $workspace_id ) {
		$k = "notify_suppressed_{$channel}_" . (int) $workspace_id;
		$n = (int) ( store()->kvGet( $k )['v'] ?? 0 );
		if ( $n ) { store()->kvSet( $k, '0' ); }
		return $n;
	}

	/* ---------------- Discord + away message (scanned from the DB) ---------------- */

	/**
	 * One pass over open conversations with buyer messages nobody has answered.
	 *  - Discord: a new conversation is announced at once; after that, a ping only
	 *    when a buyer message has waited unanswered_min minutes (one per stretch).
	 *  - Away: once per conversation, after away_min minutes unanswered, post
	 *    away_text into the chat as a system message.
	 */
	public static function scan() {
		$now = time();
		foreach ( store()->all( 'SELECT * FROM workspaces' ) as $ws ) {
			$s = self::settings( $ws['id'] );
			$discord = $s['discord_url'] !== '' && ( $s['on_new'] || $s['on_unanswered'] );
			$away    = trim( (string) $s['away_text'] ) !== '';
			if ( ! $discord && ! $away ) { continue; }
			if ( $discord && (int) ( store()->kvGet( 'notify_backoff_' . $ws['id'] )['v'] ?? 0 ) > $now ) { $discord = false; }

			$rows = store()->all(
				'SELECT c.id, c.token, c.buyer_name, COALESCE(n.discord_at,0) AS discord_at, COALESCE(n.discord_msg_id,0) AS discord_msg_id, COALESCE(n.away_at,0) AS away_at,
				 (SELECT MAX(id) FROM messages WHERE conversation_id = c.id AND sender = "operator") AS last_op
				 FROM conversations c LEFT JOIN notify_conv n ON n.conv_id = c.id
				 WHERE c.workspace_id = ? AND c.status = "open" AND c.last_activity_at > ?',
				array( (int) $ws['id'], $now - 86400 )
			);
			foreach ( $rows as $c ) {
				// Oldest buyer message since the operator last spoke
				$after = (int) $c['last_op'];
				$first = store()->one(
					'SELECT id, body, sent_at FROM messages WHERE conversation_id = ? AND sender = "buyer" AND id > ? ORDER BY id LIMIT 1',
					array( (int) $c['id'], $after )
				);
				if ( ! $first ) { continue; }
				$waited = $now - (int) $first['sent_at'];

				if ( $away && ! (int) $c['away_at'] && $waited >= 60 * max( 1, (int) $s['away_min'] ) ) {
					self::postAway( $ws, $c, (string) $s['away_text'] );
				}

				if ( ! $discord ) { continue; }
				$last = store()->one(
					'SELECT id, body, sent_at FROM messages WHERE conversation_id = ? AND sender = "buyer" AND id > ? ORDER BY id DESC LIMIT 1',
					array( (int) $c['id'], max( $after, (int) $c['discord_msg_id'] ) )
				);
				if ( ! $last || (int) $last['sent_at'] < (int) $s['since'] ) { continue; }
				$isNew = ! (int) $c['discord_at'];
				$pending = store()->one(
					'SELECT id, body, sent_at FROM messages WHERE conversation_id = ? AND sender = "buyer" AND id > ? ORDER BY id LIMIT 1',
					array( (int) $c['id'], max( $after, (int) $c['discord_msg_id'] ) )
				);
				$due = ( $isNew && $s['on_new'] )
					|| ( $s['on_unanswered'] && $now - (int) $pending['sent_at'] >= 60 * max( 1, (int) $s['unanswered_min'] ) );
				if ( ! $due ) { continue; }

				// Flood cap: past the hourly budget, count it as handled and say so in the next ping
				if ( ! self::budget( 'discord', $ws['id'] ) ) {
					self::markConv( (int) $c['id'], array( 'discord_msg_id' => (int) $last['id'], 'discord_at' => $now ) );
					self::bumpSuppressed( 'discord', $ws['id'] );
					continue;
				}
				$text = self::discordText( $ws, $c, $isNew && $s['on_new'], $waited, $s['include_text'] ? (string) $pending['body'] : '' );
				$skipped = self::takeSuppressed( 'discord', $ws['id'] );
				if ( $skipped ) { $text .= "\n_(+$skipped more not announced: over the hourly alert limit)_"; }
				$res  = static::discordPost( $s['discord_url'], $text ); // static: tests stub the HTTP call
				self::recordStatus( $ws['id'], $res );
				if ( $res['ok'] || ( $res['code'] >= 400 && $res['code'] < 500 && $res['code'] !== 429 ) ) {
					// 4xx (other than 429) won't get better by retrying: count it as handled
					self::markConv( (int) $c['id'], array( 'discord_msg_id' => (int) $last['id'], 'discord_at' => $now ) );
				}
				if ( ! $res['ok'] && ( $res['code'] === 429 || $res['code'] >= 500 || $res['code'] === 0 ) ) {
					store()->kvSet( 'notify_backoff_' . $ws['id'], (string) ( $now + 30 ) );
					break;
				}
			}
		}
	}

	private static function postAway( $ws, $c, $text ) {
		$now = time();
		self::markConv( (int) $c['id'], array( 'away_at' => $now ) );
		$text = mb_substr( trim( $text ), 0, 1000 );
		store()->q( 'INSERT INTO messages(conversation_id, sender, body, sent_at) VALUES(?,?,?,?)', array( (int) $c['id'], 'system', $text, $now ) );
		$id = (int) store()->db->lastInsertId();
		notify_ws( array(
			'type' => 'message.new',
			'workspace_id' => (int) $ws['id'],
			'conv_id' => (int) $c['id'],
			'msg' => array( 'id' => $id, 'sender' => 'system', 'body' => $text, 'sent_at' => $now ),
			'conv_token' => (string) $c['token'],
		) );
	}

	private static function markConv( $conv_id, $fields ) {
		store()->q( 'INSERT OR IGNORE INTO notify_conv(conv_id) VALUES(?)', array( $conv_id ) );
		foreach ( $fields as $k => $v ) {
			store()->q( "UPDATE notify_conv SET $k = ? WHERE conv_id = ?", array( $v, $conv_id ) );
		}
	}

	public static function discordText( $ws, $c, $isNew, $waited, $body ) {
		$who   = self::escape( mb_substr( trim( (string) $c['buyer_name'] ), 0, 60 ) ?: 'A visitor' );
		$where = self::escape( (string) ( $ws['display_name'] ?: 'your site' ) );
		$link  = rtrim( (string) Config::get( 'server_url', '' ), '/' ) . '/admin/#conv=' . (int) $c['id'];
		$head  = $isNew
			? "💬 **New chat** on $where from $who"
			: "⏳ **Still waiting** on $where: $who, " . max( 1, (int) round( $waited / 60 ) ) . ' min unanswered';
		$out = $head . "\n<" . $link . '>';
		if ( $body !== '' ) {
			$q = self::escape( mb_substr( $body, 0, 300 ) . ( mb_strlen( $body ) > 300 ? '…' : '' ) );
			$out .= "\n> " . str_replace( "\n", "\n> ", $q );
		}
		return $out;
	}

	/** Neutralise Discord markdown in buyer-supplied text (mentions are off via allowed_mentions). */
	private static function escape( $s ) {
		return preg_replace( '/([\\\\*_~`|>#\[\]()<@:-])/u', '\\\\$1', (string) $s );
	}

	/** @return array {ok:bool, code:int, error:string} */
	public static function discordPost( $url, $content ) {
		if ( ! self::validDiscordUrl( $url ) ) { return array( 'ok' => false, 'code' => 0, 'error' => 'bad_url' ); }
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode( array( 'content' => $content, 'allowed_mentions' => array( 'parse' => array() ) ), JSON_UNESCAPED_UNICODE ),
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
		) );
		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = (string) curl_error( $ch );
		curl_close( $ch );
		if ( $code >= 200 && $code < 300 ) { return array( 'ok' => true, 'code' => $code, 'error' => '' ); }
		$msg = $err;
		if ( $msg === '' ) { $j = json_decode( (string) $body, true ); $msg = (string) ( $j['message'] ?? substr( (string) $body, 0, 120 ) ); }
		hb_log( 'notify: discord failed code=' . $code . ' err=' . $msg );
		return array( 'ok' => false, 'code' => $code, 'error' => $msg );
	}

	public static function recordStatus( $workspace_id, $res ) {
		store()->kvSet( 'notify_status_' . (int) $workspace_id, json_encode( array( 'at' => time(), 'ok' => $res['ok'], 'code' => $res['code'], 'error' => $res['error'] ) ) );
	}
}
