<?php
/**
 * Copy this file to config.php and fill it in. config.php is git-ignored.
 *
 * Nothing here is a shared secret you can't rotate — VAPID keys are generated
 * on first-run and stored in data/holler.sqlite; admin passphrase hash lives
 * in the kv table (set through the first-run wizard at /admin/).
 */

return array(

	// ---- Server identity ----------------------------------------------------
	'server_name'  => 'Holler Back',                    // shown in page titles + admin wordmark
	'server_url'   => 'https://chat.example.com',       // canonical public URL for this install

	// ---- Retention ----------------------------------------------------------
	'retention_days' => 30,                             // conversations older than this get purged by worker/purge.php
	'visitor_ip_salt_days' => 30,                       // per-workspace IP-hash salt rotates on this cadence
	'max_message_len' => 4000,                          // reject longer messages at ingest
	'max_msgs_per_min_per_conv' => 20,                  // simple flood cap; over → dropped with 429

	// ---- WebSocket ----------------------------------------------------------
	// The ws-server.php long-running process binds here. Nginx should
	// `location /ws/ { proxy_pass http://<ws_bind>:<ws_port>; }` (no trailing slash).
	'ws_bind'  => '127.0.0.1',
	'ws_port'  => 8090,

	// ---- Admin console ------------------------------------------------------
	// Leave admin_pass_hash blank. First visit to /admin/ goes to a setup page
	// that stores the hash in the SQLite kv table. If you'd rather pin the
	// hash in config, paste a bcrypt hash here (kv still wins if both set):
	//   php -r "echo password_hash('your-passphrase', PASSWORD_DEFAULT).PHP_EOL;"
	'admin_pass_hash'  => '',
	'cookie_secret'    => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',

	// REQUIRED for first-run setup: the setup page asks for it, so a stranger who
	// reaches a fresh install first cannot claim it. `openssl rand -hex 16`.
	'setup_key'        => '',

	// ---- VAPID (Web Push) ---------------------------------------------------
	// Contact email put in the VAPID "sub" claim. Push providers use it if
	// they need to reach you about abusive push traffic. Any mailto: is fine.
	'vapid_subject' => 'mailto:ops@example.com',

	// ---- Paths --------------------------------------------------------------
	'db_path'   => __DIR__ . '/data/holler.sqlite',
	'log_path'  => __DIR__ . '/data/holler.log',
);
