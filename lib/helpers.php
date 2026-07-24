<?php
/** Small shared helpers. */

function h( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

function redirect( $url ) { header( 'Location: ' . $url ); exit; }

function json_out( $data, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
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
	if ( $s === '' || $s === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' ) {
		// still let dev boot, but flag it hard for anyone reading logs
		error_log( 'holler-back: cookie_secret not set in config.php — CSRF tokens are guessable' );
		return 'insecure-default-change-me';
	}
	return $s;
}
function csrf_token() {
	if ( empty( $_COOKIE['csrf'] ) ) {
		$raw = bin2hex( random_bytes( 16 ) );
		setcookie( 'csrf', $raw, array( 'httponly' => false, 'samesite' => 'Strict', 'path' => '/' ) );
		$_COOKIE['csrf'] = $raw;
	}
	return hash_hmac( 'sha256', $_COOKIE['csrf'], csrf_secret() );
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . h( csrf_token() ) . '">'; }
function csrf_check() {
	$sent = (string) req( '_csrf', '' );
	if ( $sent === '' ) {
		$hdr = isset( $_SERVER['HTTP_X_CSRF'] ) ? (string) $_SERVER['HTTP_X_CSRF'] : '';
		$sent = $hdr;
	}
	$want = isset( $_COOKIE['csrf'] ) ? hash_hmac( 'sha256', $_COOKIE['csrf'], csrf_secret() ) : '';
	if ( $sent === '' || ! hash_equals( $want, $sent ) ) {
		http_response_code( 400 );
		exit( 'Bad or missing CSRF token.' );
	}
}

/* ---- IDs + hashes -------------------------------------------------------- */
function new_token( $bytes = 16 ) { return bin2hex( random_bytes( $bytes ) ); }

/** IP hashed with per-workspace salt. Never store raw IPs. */
function client_ip() {
	// no proxy trust — this box is behind nginx and we set X-Forwarded-For only
	// on the loopback proxy_pass, which is fine to trust here
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
		return trim( $parts[0] );
	}
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
