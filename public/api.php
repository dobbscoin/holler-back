<?php
/**
 * Single front-controller for all JSON endpoints. Routed by nginx as:
 *   location ~ ^/api/ { try_files $uri /api.php; }
 * with PATH_INFO derived from REQUEST_URI so we can dispatch on the tail.
 *
 * Widget endpoints:
 *   POST /api/conversation/create          — start a new conversation, returns {token, ws_url}
 *   POST /api/message/send                 — buyer sends a message on an existing conversation
 *   POST /api/conversation/identify        — buyer sets name+email mid-conversation
 *
 * Admin endpoints (session cookie required):
 *   GET  /api/admin/vapid-public           — VAPID public key (base64url) for the sw subscribe call
 *   POST /api/admin/subscribe              — persist a Web Push subscription {endpoint, keys.p256dh, keys.auth}
 *   POST /api/admin/unsubscribe            — drop a subscription by endpoint
 *   GET  /api/admin/conversations?status=  — list open (default) or closed conversations, plus counts
 *   GET  /api/admin/messages?conv_id=N     — full transcript for a conversation
 *   POST /api/admin/reply                  — operator replies on a conversation
 *   POST /api/admin/close                  — mark a conversation closed
 *   POST /api/admin/delete                 — erase a conversation {conv_id}, or every closed one {all_closed:true}
 *   GET  /api/admin/ws-token               — mint a short-lived WS token for the admin socket
 *   POST /api/admin/notify/save            — Discord webhook, triggers, away message
 *   POST /api/admin/notify/test            — post a sample to the saved Discord webhook now
 *   POST /api/admin/push-test              — queue a test push to every subscribed device
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// Route on the tail of REQUEST_URI so the front-controller sees the path
$uri  = parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$path = preg_replace( '#^/api/#', '', (string) $uri );
$path = trim( (string) $path, '/' );

$method = $_SERVER['REQUEST_METHOD'];

/* -------------- helpers used only in this front-controller -------------- */

function need_method( $m ) {
	if ( $_SERVER['REQUEST_METHOD'] !== $m ) {
		json_out( array( 'error' => 'method_not_allowed' ), 405 );
	}
}
function widget_workspace() {
	$body = json_in();
	$tok  = trim( (string) ( $body['workspace'] ?? $_GET['workspace'] ?? '' ) );
	if ( $tok === '' ) { json_out( array( 'error' => 'no_workspace' ), 400 ); }
	$ws = Workspace::byToken( $tok );
	if ( ! $ws ) { json_out( array( 'error' => 'unknown_workspace' ), 404 ); }
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
	if ( ! Workspace::originAllowed( $ws, $origin ) ) {
		// CORS header on the refusal too, so the widget sees a 403 rather than a CORS TypeError
		if ( $origin !== '' ) { header( 'Access-Control-Allow-Origin: ' . $origin ); header( 'Vary: Origin' ); }
		json_out( array( 'error' => 'origin_not_allowed', 'origin' => $origin ), 403 );
	}
	// CORS: allow the exact origin that just passed the allowlist
	header( 'Access-Control-Allow-Origin: ' . $origin );
	header( 'Vary: Origin' );
	header( 'Access-Control-Allow-Headers: content-type' );
	return array( Workspace::maybeRotateIpSalt( $ws ), $body );
}
function widget_conversation( $ws, $token ) {
	$conv = store()->one(
		'SELECT * FROM conversations WHERE workspace_id = ? AND token = ? AND status != "closed"',
		array( (int) $ws['id'], (string) $token )
	);
	if ( ! $conv ) { json_out( array( 'error' => 'unknown_conversation' ), 404 ); }
	return $conv;
}
function preflight() {
	if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '*';
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: content-type' );
		header( 'Vary: Origin' );
		http_response_code( 204 );
		exit;
	}
}

preflight();

/* ============================ WIDGET ROUTES ============================ */

if ( $path === 'conversation/create' ) {
	need_method( 'POST' );
	list( $ws, $body ) = widget_workspace();
	if ( ! rate_hit( 'ip:' . client_ip() . ':create', 3600, 20 ) ) {
		json_out( array( 'error' => 'rate_limited' ), 429 );
	}
	$now = time();
	$tok = new_token( 16 );
	$ip  = client_ip();
	$vhash = $ip !== '' ? hash_ip( $ip, (string) $ws['ip_salt'] ) : '';
	store()->q(
		'INSERT INTO conversations(workspace_id, token, buyer_visitor_hash, buyer_name, buyer_email, status, created_at, last_activity_at) VALUES(?,?,?,?,?,"open",?,?)',
		array( (int) $ws['id'], $tok, $vhash, '', '', $now, $now )
	);
	$conv_id = (int) store()->db->lastInsertId();

	// Optional greeter — enqueued as a system message so the widget shows it
	// even for a buyer who hasn't sent anything yet.
	$greet = trim( (string) ( store()->kvGet( 'greeter_' . $ws['id'] )['v'] ?? '' ) );
	if ( $greet === '' ) { $greet = 'Hi! How can we help?'; }
	store()->q(
		'INSERT INTO messages(conversation_id, sender, body, sent_at) VALUES(?,?,?,?)',
		array( $conv_id, 'system', $greet, $now )
	);
	$greet_id = (int) store()->db->lastInsertId();

	notify_ws( array( 'type' => 'conversation.created', 'workspace_id' => (int) $ws['id'], 'conv_id' => $conv_id, 'token' => $tok ) );

	json_out( array(
		'token'        => $tok,
		'conv_id'      => $conv_id,
		'operator_name' => (string) $ws['operator_name'],
		'greeting'     => $greet,
		'greeting_id'  => $greet_id,
	) );
}

if ( $path === 'conversation/identify' ) {
	need_method( 'POST' );
	list( $ws, $body ) = widget_workspace();
	$tok  = (string) ( $body['token'] ?? '' );
	$conv = widget_conversation( $ws, $tok );
	$name  = mb_substr( trim( (string) ( $body['name']  ?? '' ) ), 0, 80 );
	$email = mb_substr( trim( (string) ( $body['email'] ?? '' ) ), 0, 120 );
	store()->q(
		'UPDATE conversations SET buyer_name = ?, buyer_email = ?, last_activity_at = ? WHERE id = ?',
		array( $name, $email, time(), (int) $conv['id'] )
	);
	json_out( array( 'ok' => true ) );
}

if ( $path === 'message/send' ) {
	need_method( 'POST' );
	list( $ws, $body ) = widget_workspace();
	$tok  = (string) ( $body['token'] ?? '' );
	$conv = widget_conversation( $ws, $tok );

	$body_text = trim( (string) ( $body['body'] ?? '' ) );
	$max = (int) Config::get( 'max_message_len', 4000 );
	if ( $body_text === '' ) { json_out( array( 'error' => 'empty' ), 400 ); }
	if ( mb_strlen( $body_text ) > $max ) { $body_text = mb_substr( $body_text, 0, $max ); }

	$limit = (int) Config::get( 'max_msgs_per_min_per_conv', 20 );
	if ( ! rate_hit( 'conv:' . $conv['id'] . ':msgs', 60, $limit ) || ! rate_hit( 'ip:' . client_ip() . ':send', 60, 30 ) ) {
		json_out( array( 'error' => 'rate_limited' ), 429 );
	}

	$now = time();
	store()->q(
		'INSERT INTO messages(conversation_id, sender, body, sent_at) VALUES(?,?,?,?)',
		array( (int) $conv['id'], 'buyer', $body_text, $now )
	);
	$msg_id = (int) store()->db->lastInsertId();
	store()->q(
		'UPDATE conversations SET last_activity_at = ?, unread_admin = unread_admin + 1 WHERE id = ?',
		array( $now, (int) $conv['id'] )
	);

	notify_ws( array(
		'type' => 'message.new',
		'workspace_id' => (int) $ws['id'],
		'conv_id' => (int) $conv['id'],
		'msg' => array( 'id' => $msg_id, 'sender' => 'buyer', 'body' => $body_text, 'sent_at' => $now ),
		'conv_token' => (string) $conv['token'],
	) );

	json_out( array( 'id' => $msg_id, 'sent_at' => $now ) );
}

if ( $path === 'conversation/history' ) {
	// Widget rehydration on page reload — returns messages since a given id.
	list( $ws, $body ) = widget_workspace();
	$tok   = (string) ( $body['token'] ?? $_GET['token'] ?? '' );
	$since = (int) ( $body['since'] ?? $_GET['since'] ?? 0 );
	$conv  = widget_conversation( $ws, $tok );
	$rows  = store()->all(
		'SELECT id, sender, body, sent_at FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id',
		array( (int) $conv['id'], $since )
	);
	json_out( array( 'messages' => $rows ) );
}

/* ============================ ADMIN ROUTES ============================ */

if ( strpos( $path, 'admin/' ) === 0 ) {
	if ( ! Auth::loggedIn() ) { json_out( array( 'error' => 'unauthenticated' ), 401 ); }
	$tail = substr( $path, strlen( 'admin/' ) );

	if ( $tail === 'ws-token' ) {
		need_method( 'GET' );
		json_out( array( 'token' => Auth::mintWsToken() ) );
	}

	if ( $tail === 'vapid-public' ) {
		need_method( 'GET' );
		require_once __DIR__ . '/../lib/Vapid.php';
		json_out( array( 'key' => Vapid::publicKeyB64Url() ) );
	}

	if ( $tail === 'subscribe' ) {
		need_method( 'POST' );
		csrf_check();
		$body = json_in();
		$ep   = (string) ( $body['endpoint'] ?? '' );
		$p256 = (string) ( $body['keys']['p256dh'] ?? '' );
		$auth = (string) ( $body['keys']['auth'] ?? '' );
		if ( $ep === '' || $p256 === '' || $auth === '' ) { json_out( array( 'error' => 'incomplete' ), 400 ); }
		if ( strpos( $ep, 'https://' ) !== 0 || strlen( $ep ) > 1024 ) { json_out( array( 'error' => 'bad_endpoint' ), 400 ); }
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'error' => 'no_workspace' ), 400 ); }
		store()->q(
			'INSERT INTO push_subscriptions(workspace_id, endpoint, p256dh, auth, ua_hint, subscribed_at) VALUES(?,?,?,?,?,?)
			 ON CONFLICT(endpoint) DO UPDATE SET p256dh=excluded.p256dh, auth=excluded.auth, ua_hint=excluded.ua_hint, subscribed_at=excluded.subscribed_at, fail_count=0',
			array( (int) $ws['id'], $ep, $p256, $auth, (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), time() )
		);
		json_out( array( 'ok' => true ) );
	}

	if ( $tail === 'unsubscribe' ) {
		need_method( 'POST' );
		csrf_check();
		$body = json_in();
		$ep   = (string) ( $body['endpoint'] ?? '' );
		store()->q( 'DELETE FROM push_subscriptions WHERE endpoint = ?', array( $ep ) );
		json_out( array( 'ok' => true ) );
	}

	if ( $tail === 'conversations' ) {
		need_method( 'GET' );
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'conversations' => array() ) ); }
		$status = ( $_GET['status'] ?? 'open' ) === 'closed' ? 'closed' : 'open';
		$counts = array( 'open' => 0, 'closed' => 0 );
		foreach ( store()->all( 'SELECT status, COUNT(*) AS n FROM conversations WHERE workspace_id = ? GROUP BY status', array( (int) $ws['id'] ) ) as $r ) {
			$counts[ $r['status'] === 'closed' ? 'closed' : 'open' ] += (int) $r['n'];
		}
		$rows = store()->all(
			'SELECT c.id, c.token, c.buyer_name, c.buyer_email, c.status, c.unread_admin, c.created_at, c.last_activity_at,
			 (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body,
			 (SELECT sender FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_sender
			 FROM conversations c
			 WHERE c.workspace_id = ? AND c.status ' . ( $status === 'closed' ? '= "closed"' : '!= "closed"' ) . '
			 ORDER BY c.last_activity_at DESC
			 LIMIT 200',
			array( (int) $ws['id'] )
		);
		json_out( array( 'conversations' => $rows, 'counts' => $counts ) );
	}

	if ( $tail === 'messages' ) {
		need_method( 'GET' );
		$conv_id = (int) ( $_GET['conv_id'] ?? 0 );
		$since   = (int) ( $_GET['since'] ?? 0 );
		$ws = Workspace::primary();
		$conv = store()->one( 'SELECT * FROM conversations WHERE id = ? AND workspace_id = ?', array( $conv_id, (int) $ws['id'] ) );
		if ( ! $conv ) { json_out( array( 'error' => 'not_found' ), 404 ); }
		$rows = store()->all(
			'SELECT id, sender, body, sent_at FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id',
			array( $conv_id, $since )
		);
		// Mark read
		if ( (int) $conv['unread_admin'] > 0 ) {
			store()->q( 'UPDATE conversations SET unread_admin = 0 WHERE id = ?', array( $conv_id ) );
		}
		json_out( array( 'conversation' => $conv, 'messages' => $rows ) );
	}

	if ( $tail === 'reply' ) {
		need_method( 'POST' );
		csrf_check();
		$body    = json_in();
		$conv_id = (int) ( $body['conv_id'] ?? 0 );
		$text    = trim( (string) ( $body['body'] ?? '' ) );
		if ( $text === '' ) { json_out( array( 'error' => 'empty' ), 400 ); }

		$ws = Workspace::primary();
		$conv = store()->one( 'SELECT * FROM conversations WHERE id = ? AND workspace_id = ?', array( $conv_id, (int) $ws['id'] ) );
		if ( ! $conv ) { json_out( array( 'error' => 'not_found' ), 404 ); }
		if ( $conv['status'] === 'closed' ) { json_out( array( 'error' => 'closed' ), 409 ); }

		$max = (int) Config::get( 'max_message_len', 4000 );
		if ( mb_strlen( $text ) > $max ) { $text = mb_substr( $text, 0, $max ); }

		$now = time();
		store()->q(
			'INSERT INTO messages(conversation_id, sender, body, sent_at) VALUES(?,?,?,?)',
			array( $conv_id, 'operator', $text, $now )
		);
		$msg_id = (int) store()->db->lastInsertId();
		store()->q( 'UPDATE conversations SET last_activity_at = ? WHERE id = ?', array( $now, $conv_id ) );

		notify_ws( array(
			'type' => 'message.new',
			'workspace_id' => (int) $ws['id'],
			'conv_id' => $conv_id,
			'msg' => array( 'id' => $msg_id, 'sender' => 'operator', 'body' => $text, 'sent_at' => $now ),
			'conv_token' => (string) $conv['token'],
		) );

		json_out( array( 'id' => $msg_id, 'sent_at' => $now ) );
	}

	if ( $tail === 'close' ) {
		need_method( 'POST' );
		csrf_check();
		$body = json_in();
		$conv_id = (int) ( $body['conv_id'] ?? 0 );
		$ws = Workspace::primary();
		store()->q(
			'UPDATE conversations SET status = "closed", last_activity_at = ? WHERE id = ? AND workspace_id = ?',
			array( time(), $conv_id, (int) $ws['id'] )
		);
		notify_ws( array( 'type' => 'conversation.closed', 'workspace_id' => (int) $ws['id'], 'conv_id' => $conv_id ) );
		json_out( array( 'ok' => true ) );
	}

	if ( $tail === 'delete' ) {
		need_method( 'POST' );
		csrf_check();
		$body = json_in();
		$ws = Workspace::primary();
		if ( ! empty( $body['all_closed'] ) ) {
			$n = store()->q( 'DELETE FROM conversations WHERE workspace_id = ? AND status = "closed"', array( (int) $ws['id'] ) )->rowCount();
			json_out( array( 'ok' => true, 'deleted' => $n ) );
		}
		$conv_id = (int) ( $body['conv_id'] ?? 0 );
		$conv = store()->one( 'SELECT id, status FROM conversations WHERE id = ? AND workspace_id = ?', array( $conv_id, (int) $ws['id'] ) );
		if ( ! $conv ) { json_out( array( 'error' => 'not_found' ), 404 ); }
		// Tell a still-open widget first, so the buyer isn't left typing into nothing
		if ( $conv['status'] !== 'closed' ) {
			notify_ws( array( 'type' => 'conversation.closed', 'workspace_id' => (int) $ws['id'], 'conv_id' => $conv_id ) );
		}
		store()->q( 'DELETE FROM conversations WHERE id = ?', array( $conv_id ) ); // messages + notify_conv cascade
		json_out( array( 'ok' => true, 'deleted' => 1 ) );
	}

	if ( $tail === 'workspace' ) {
		// Fetch workspace metadata (install token, origins, operator name)
		need_method( 'GET' );
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'workspace' => null ) ); }
		unset( $ws['ip_salt'] );
		json_out( array( 'workspace' => $ws ) );
	}

	if ( $tail === 'workspace/save' ) {
		need_method( 'POST' );
		csrf_check();
		$body = json_in();
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'error' => 'no_workspace' ), 400 ); }
		$display  = mb_substr( trim( (string) ( $body['display_name'] ?? '' ) ), 0, 120 );
		$operator = mb_substr( trim( (string) ( $body['operator_name'] ?? 'Support' ) ), 0, 80 ) ?: 'Support';
		$origins  = trim( (string) ( $body['allowed_origins'] ?? '' ) );
		store()->q(
			'UPDATE workspaces SET display_name = ?, operator_name = ?, allowed_origins = ? WHERE id = ?',
			array( $display, $operator, $origins, (int) $ws['id'] )
		);
		json_out( array( 'ok' => true ) );
	}

	if ( $tail === 'notify/save' ) {
		need_method( 'POST' );
		csrf_check();
		require_once __DIR__ . '/../lib/Notify.php';
		$body = json_in();
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'error' => 'no_workspace' ), 400 ); }
		$s = Notify::settings( $ws['id'] );
		// The saved URL is never sent back to the browser: blank means "keep it".
		$url = trim( (string) ( $body['discord_url'] ?? '' ) );
		if ( ! empty( $body['discord_remove'] ) ) {
			$s['discord_url'] = '';
		} elseif ( $url !== '' ) {
			if ( ! Notify::validDiscordUrl( $url ) ) { json_out( array( 'error' => 'bad_discord_url' ), 400 ); }
			if ( $url !== $s['discord_url'] ) { $s['since'] = time(); }
			$s['discord_url'] = $url;
		}
		$s['include_text']   = ! empty( $body['include_text'] );
		$s['on_new']         = ! empty( $body['on_new'] );
		$s['on_unanswered']  = ! empty( $body['on_unanswered'] );
		$s['unanswered_min'] = max( 1, min( 1440, (int) ( $body['unanswered_min'] ?? 3 ) ) );
		$s['away_text']      = mb_substr( trim( (string) ( $body['away_text'] ?? '' ) ), 0, 1000 );
		$s['away_min']       = max( 1, min( 1440, (int) ( $body['away_min'] ?? 5 ) ) );
		Notify::save( $ws['id'], $s );
		json_out( array( 'ok' => true, 'discord_hint' => Notify::urlHint( $s['discord_url'] ) ) );
	}

	if ( $tail === 'notify/test' ) {
		need_method( 'POST' );
		csrf_check();
		require_once __DIR__ . '/../lib/Notify.php';
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'error' => 'no_workspace' ), 400 ); }
		if ( ! rate_hit( 'notify-test', 60, 5 ) ) { json_out( array( 'error' => 'rate_limited' ), 429 ); }
		$s = Notify::settings( $ws['id'] );
		if ( $s['discord_url'] === '' ) { json_out( array( 'error' => 'no_webhook' ), 400 ); }
		$where = (string) ( $ws['display_name'] ?: 'your site' );
		$res = Notify::discordPost( $s['discord_url'], "🔔 Test from Holler Back ($where). Chat alerts will land in this channel." );
		Notify::recordStatus( $ws['id'], $res );
		json_out( $res, $res['ok'] ? 200 : 502 );
	}

	if ( $tail === 'push-test' ) {
		need_method( 'POST' );
		csrf_check();
		require_once __DIR__ . '/../lib/Notify.php';
		$ws = Workspace::primary();
		if ( ! $ws ) { json_out( array( 'error' => 'no_workspace' ), 400 ); }
		if ( ! rate_hit( 'push-test', 60, 5 ) ) { json_out( array( 'error' => 'rate_limited' ), 429 ); }
		$n = (int) store()->one( 'SELECT COUNT(*) AS n FROM push_subscriptions WHERE workspace_id = ?', array( (int) $ws['id'] ) )['n'];
		if ( ! $n ) { json_out( array( 'error' => 'no_devices' ), 400 ); }
		Notify::enqueue( $ws['id'], 'push_test', array() );
		json_out( array( 'ok' => true, 'devices' => $n ) );
	}

	json_out( array( 'error' => 'unknown_admin_endpoint', 'path' => $tail ), 404 );
}

json_out( array( 'error' => 'unknown_endpoint', 'path' => $path ), 404 );
