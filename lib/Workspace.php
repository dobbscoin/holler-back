<?php
/**
 * Workspace helpers. MVP UI is single-workspace, but the schema and lookups
 * are already keyed by workspace_id so a multi-workspace UI is additive.
 */
class Workspace {

	/** Get workspace by install_token (the data-holler-id in the widget). */
	public static function byToken( $token ) {
		return store()->one( 'SELECT * FROM workspaces WHERE install_token = ?', array( (string) $token ) );
	}

	public static function byId( $id ) {
		return store()->one( 'SELECT * FROM workspaces WHERE id = ?', array( (int) $id ) );
	}

	/** MVP: the "current" workspace is the first one. Setup wizard creates it. */
	public static function primary() {
		return store()->one( 'SELECT * FROM workspaces ORDER BY id LIMIT 1' );
	}

	public static function count() {
		$r = store()->one( 'SELECT COUNT(*) AS n FROM workspaces' );
		return $r ? (int) $r['n'] : 0;
	}

	/**
	 * Create a workspace. install_token is auto-generated; caller shows it to
	 * the operator once so they can paste it into their site's widget script.
	 */
	public static function create( $display_name = '', $operator_name = 'Support', $allowed_origins = '' ) {
		$tok  = substr( bin2hex( random_bytes( 5 ) ), 0, 8 ); // 8-char id, keeps the <script> tag readable
		$salt = bin2hex( random_bytes( 16 ) );
		$now  = time();
		store()->q(
			'INSERT INTO workspaces(install_token, display_name, operator_name, allowed_origins, ip_salt, ip_salt_rotated_at, created_at) VALUES(?,?,?,?,?,?,?)',
			array( $tok, (string) $display_name, (string) $operator_name, (string) $allowed_origins, $salt, $now, $now )
		);
		return (int) store()->db->lastInsertId();
	}

	/**
	 * Enforce Origin against the workspace's allowed_origins list.
	 * "*" allows any origin (dev only). Empty list = no widget requests will succeed.
	 * Returns true on match, false otherwise. Callers should 403 on false.
	 */
	public static function originAllowed( $workspace, $origin ) {
		$list = trim( (string) ( $workspace['allowed_origins'] ?? '' ) );
		if ( $list === '' ) { return false; }
		$origin = strtolower( trim( (string) $origin ) );
		foreach ( preg_split( '/\s+/', $list ) as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( $entry === '' ) { continue; }
			if ( $entry === '*' ) { return true; }
			if ( $entry === $origin ) { return true; }
		}
		return false;
	}

	/** Rotate the per-workspace IP hash salt if it's older than N days. */
	public static function maybeRotateIpSalt( $workspace ) {
		$days = (int) Config::get( 'visitor_ip_salt_days', 30 );
		if ( $days <= 0 ) { return $workspace; }
		if ( time() - (int) $workspace['ip_salt_rotated_at'] < $days * 86400 ) { return $workspace; }
		$salt = bin2hex( random_bytes( 16 ) );
		store()->q(
			'UPDATE workspaces SET ip_salt = ?, ip_salt_rotated_at = ? WHERE id = ?',
			array( $salt, time(), (int) $workspace['id'] )
		);
		$workspace['ip_salt'] = $salt;
		$workspace['ip_salt_rotated_at'] = time();
		return $workspace;
	}
}
