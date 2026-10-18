<?php
/** Small shared helpers. */

function h( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

function redirect( $url ) { header( 'Location: ' . $url ); exit; }

function json_out( $data, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE );
	exit;
}

function json_in() {
	$raw = file_get_contents( 'php://input' );
	if ( $raw === '' || $raw === false ) { return array(); }
	$d = json_decode( $raw, true );
	return is_array( $d ) ? $d : array();
}

function req( $key, $default = '' ) {
	return isset( $_REQUEST[ $key ] ) ? $_REQUEST[ $key ] : $default;
}

/* ---- CSRF (double-submit cookie, signed) --------------------------------- */
function csrf_secret() {
	$s = (string) Config::get( 'cookie_secret', '' );
	if ( $s === '' || $s === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || strlen( $s ) < 32 ) {
		// fail closed: a guessable secret means forgeable admin sessions
		error_log( 'holler-back: cookie_secret not set in config.php (need 32+ chars) — refusing to run' );
		http_response_code( 500 );
		exit( 'cookie_secret not set in config.php' );
	}
	return $s;
}
/** HMAC with a purpose prefix, so a value signed for one use can't be replayed as another. */
function hb_sign( $purpose, $data ) { return hash_hmac( 'sha256', $purpose . '|' . $data, csrf_secret() ); }
/** Shared secret for api.php -> ws-server.php publishes (header X-HB-Internal). */
function internal_secret() { return hb_sign( 'internal', 'publish' ); }
function csrf_token() {
	if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $_COOKIE['csrf'] ?? '' ) ) ) {
		$raw = bin2hex( random_bytes( 16 ) );
		setcookie( 'csrf', $raw, array(
			'expires'  => time() + Auth::TTL,
			'httponly' => false,
			'samesite' => 'Strict',
			'path'     => '/',
			'secure'   => ! empty( $_SERVER['HTTPS'] ),
		) );
		$_COOKIE['csrf'] = $raw;
	}
	return hb_sign( 'csrf', $_COOKIE['csrf'] );
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . h( csrf_token() ) . '">'; }
function csrf_check() {
	$sent = (string) req( '_csrf', '' );
	if ( $sent === '' ) {
		$hdr = isset( $_SERVER['HTTP_X_CSRF'] ) ? (string) $_SERVER['HTTP_X_CSRF'] : '';
		$sent = $hdr;
	}
	$c    = (string) ( $_COOKIE['csrf'] ?? '' );
	$want = preg_match( '/^[a-f0-9]{32}$/', $c ) ? hb_sign( 'csrf', $c ) : '';
	if ( $sent === '' || ! hash_equals( $want, $sent ) ) {
		http_response_code( 400 );
		exit( 'Bad or missing CSRF token.' );
	}
}

/* ---- IDs + hashes -------------------------------------------------------- */
function new_token( $bytes = 16 ) { return bin2hex( random_bytes( $bytes ) ); }

/** IP hashed with per-workspace salt. Never store raw IPs. */
function client_ip() {
	// REMOTE_ADDR only: under PHP-FPM, X-Forwarded-For is whatever the client sent
	return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
}
function hash_ip( $ip, $salt ) {
	return substr( hash( 'sha256', $ip . '|' . $salt ), 0, 24 );
}

/* ---- Rate limiting (SQLite-backed sliding window) ------------------------ */
function rate_hit( $key, $windowSec, $limit ) {
	$now = time();
	$bucketStart = $now - ( $now % $windowSec );
	$row = store()->one( 'SELECT window_start, count FROM rate_buckets WHERE k = ?', array( $key ) );
	if ( ! $row || (int) $row['window_start'] !== $bucketStart ) {
		store()->q(
			'INSERT INTO rate_buckets(k,window_start,count) VALUES(?,?,1) ON CONFLICT(k) DO UPDATE SET window_start=excluded.window_start, count=1',
			array( $key, $bucketStart )
		);
		return true;
	}
	if ( (int) $row['count'] >= $limit ) { return false; }
	store()->q( 'UPDATE rate_buckets SET count = count + 1 WHERE k = ?', array( $key ) );
	return true;
}

/* ---- Logging ------------------------------------------------------------- */
function hb_log( $line ) {
	$path = (string) Config::get( 'log_path', '' );
	if ( $path === '' ) { return; }
	@file_put_contents( $path, '[' . date( 'c' ) . '] ' . rtrim( $line ) . "\n", FILE_APPEND );
}
