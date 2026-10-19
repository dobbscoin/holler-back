<?php
/** Thin PDO/SQLite wrapper. Creates the schema on first use; no migrations needed. */
class Store {
	/** @var PDO */
	public $db;

	public function __construct( $path ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0770, true ); }
		$this->db = new PDO( 'sqlite:' . $path );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->db->exec( 'PRAGMA journal_mode=WAL' );
		$this->db->exec( 'PRAGMA busy_timeout=5000' ); // api, ws-server and the notify worker all write
		$this->db->exec( 'PRAGMA foreign_keys=ON' );
		$this->db->exec( 'PRAGMA secure_delete=ON' ); // a deleted chat is overwritten, not left in free pages
		$this->migrate();
	}

	private function migrate() {
		// Schema is multi-tenant from day one (workspace_id on every row) so a
		// later multi-workspace UI doesn't require a rewrite. MVP UI shows the
		// first workspace only.
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS workspaces (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				install_token TEXT NOT NULL UNIQUE,     -- the data-holler-id in the widget <script>
				display_name TEXT NOT NULL DEFAULT "",
				operator_name TEXT NOT NULL DEFAULT "Support",
				allowed_origins TEXT NOT NULL DEFAULT "", -- newline-separated list; "*" = allow any (dev only)
				ip_salt TEXT NOT NULL,                  -- per-workspace salt for buyer_visitor_hash
				ip_salt_rotated_at INTEGER NOT NULL,
				created_at INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS conversations (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
				token TEXT NOT NULL UNIQUE,             -- opaque handle the widget uses; not guessable
				buyer_visitor_hash TEXT NOT NULL DEFAULT "",
				buyer_name TEXT NOT NULL DEFAULT "",
				buyer_email TEXT NOT NULL DEFAULT "",
				status TEXT NOT NULL DEFAULT "open",    -- open | closed
				unread_admin INTEGER NOT NULL DEFAULT 0,
				created_at INTEGER NOT NULL,
				last_activity_at INTEGER NOT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_conv_ws_activity ON conversations(workspace_id, last_activity_at DESC);
			CREATE TABLE IF NOT EXISTS messages (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
				sender TEXT NOT NULL,                   -- buyer | operator | system
				body TEXT NOT NULL,
				sent_at INTEGER NOT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id, id);
			CREATE TABLE IF NOT EXISTS push_subscriptions (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
				endpoint TEXT NOT NULL UNIQUE,
				p256dh TEXT NOT NULL,
				auth TEXT NOT NULL,
				ua_hint TEXT NOT NULL DEFAULT "",
				last_ok_at INTEGER NOT NULL DEFAULT 0,
				last_fail_at INTEGER NOT NULL DEFAULT 0,
				fail_count INTEGER NOT NULL DEFAULT 0,
				subscribed_at INTEGER NOT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_push_ws ON push_subscriptions(workspace_id);
			CREATE TABLE IF NOT EXISTS rate_buckets (
				k TEXT PRIMARY KEY,                     -- e.g. "conv:123:msgs"
				window_start INTEGER NOT NULL,
				count INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS kv ( k TEXT PRIMARY KEY, v TEXT NOT NULL, updated_at INTEGER NOT NULL );
			CREATE TABLE IF NOT EXISTS notify_outbox (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				workspace_id INTEGER NOT NULL,
				kind TEXT NOT NULL,                     -- push | push_test
				payload TEXT NOT NULL,                  -- JSON
				created_at INTEGER NOT NULL,
				done_at INTEGER NOT NULL DEFAULT 0
			);
			CREATE INDEX IF NOT EXISTS idx_outbox_pending ON notify_outbox(done_at, id);
			CREATE TABLE IF NOT EXISTS notify_conv (
				conv_id INTEGER PRIMARY KEY REFERENCES conversations(id) ON DELETE CASCADE,
				discord_msg_id INTEGER NOT NULL DEFAULT 0, -- newest buyer message already announced to Discord
				discord_at INTEGER NOT NULL DEFAULT 0,
				away_at INTEGER NOT NULL DEFAULT 0      -- when the away message was posted (once per conversation)
			);'
		);
	}

	public function q( $sql, $args = array() ) {
		$st = $this->db->prepare( $sql );
		$st->execute( $args );
		return $st;
	}
	public function all( $sql, $args = array() ) { return $this->q( $sql, $args )->fetchAll(); }
	public function one( $sql, $args = array() ) { $r = $this->q( $sql, $args )->fetch(); return $r ?: null; }

	public function kvGet( $k, $default = null ) {
		$r = $this->one( 'SELECT v, updated_at FROM kv WHERE k = ?', array( $k ) );
		return $r ?: $default;
	}
	public function kvSet( $k, $v ) {
		$this->q(
			'INSERT INTO kv(k,v,updated_at) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at',
			array( $k, (string) $v, time() )
		);
	}
}
