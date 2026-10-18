<?php
/**
 * Admin auth. Passphrase is the gate; the console is reachable from the open
 * internet by default. Bind /admin/ to a VPN or Tailscale in nginx if you
 * want defence in depth.
 *
 * Hash lookup order: kv table (written by the first-run setup wizard) → then
 * config.php admin_pass_hash. Lets a fresh install self-configure without
 * touching config.php, while still honouring an operator-set config value.
 *
 * If no hash is set anywhere, admin/ redirects to setup.php.
 */
class Auth {
	const TTL = 43200; // 12h
	const COOKIE = 'hbadmin';

	private static function hash() {
		$row = store()->kvGet( 'admin_pass_hash' );
		if ( $row && trim( (string) $row['v'] ) !== '' ) {
			return trim( (string) $row['v'] );
		}
		return trim( (string) Config::get( 'admin_pass_hash', '' ) );
	}

	public static function passSet() { return self::hash() !== ''; }

	public static function loggedIn() {
		if ( ! self::passSet() ) { return false; }
		$c = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		$parts = explode( '.', $c );
		if ( count( $parts ) !== 2 ) { return false; }
		list( $ts, $sig ) = $parts;
		if ( ! ctype_digit( $ts ) || ( time() - (int) $ts ) > self::TTL ) { return false; }
		$want = hb_sign( 'sess', $ts );
		return hash_equals( $want, $sig );
	}

	public static function login( $pass ) {
		if ( ! self::passSet() ) { return false; }
		if ( ! password_verify( (string) $pass, self::hash() ) ) { return false; }
		$ts  = (string) time();
		$sig = hb_sign( 'sess', $ts );
		setcookie( self::COOKIE, $ts . '.' . $sig, array(
			'expires'  => time() + self::TTL,
			'httponly' => true,
			'samesite' => 'Strict',
			'path'     => '/',
			'secure'   => ! empty( $_SERVER['HTTPS'] ),
		) );
		return true;
	}

	public static function logout() {
		setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/' ) );
	}

	public static function setPassphrase( $pass ) {
		$hash = password_hash( (string) $pass, PASSWORD_DEFAULT );
		store()->kvSet( 'admin_pass_hash', $hash );
		return true;
	}

	/** Validate a token minted by mintWsToken() — used by ws-server.php's HTTP upgrade path. */
	public static function verifyWsToken( $tok, $maxAgeSec = 60 ) {
		$parts = explode( '.', (string) $tok );
		if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) { return false; }
		if ( ( time() - (int) $parts[0] ) > $maxAgeSec ) { return false; }
		// We don't have access to the admin cookie's ts here — bind to any
		// currently-valid admin session by reading the session ts from kv? No:
		// simpler is to bind the WS token to the passphrase-hash directly.
		$hash = self::hash();
		if ( $hash === '' ) { return false; }
		$want = hb_sign( 'ws', $parts[0] . '|' . substr( $hash, 0, 12 ) );
		return hash_equals( $want, $parts[1] );
	}

	/** Called by admin/inc.php-issued JS bootstrap to hand the widget a token. */
	public static function mintWsToken() {
		if ( ! self::loggedIn() ) { return ''; }
		$hash = self::hash();
		$ts = (string) time();
		$sig = hb_sign( 'ws', $ts . '|' . substr( $hash, 0, 12 ) );
		return $ts . '.' . $sig;
	}

	public static function require_admin() {
		if ( ! self::passSet() ) { redirect( 'setup.php' ); }
		if ( ! self::loggedIn() )  { redirect( 'login.php' ); }
	}
}
