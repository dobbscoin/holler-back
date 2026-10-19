<?php
/**
 * VAPID (RFC 8292) + Web Push encrypted payload (RFC 8291, "aes128gcm" scheme).
 *
 * Implementation intentionally uses only openssl + hash_hkdf + gmp — no Composer,
 * no `web-token/*`. The ECDSA private key lives in `data/vapid.pem` (mode 0600),
 * generated on first use. The public key is exposed base64url-encoded in the
 * kv table so admin.js can pass it to pushManager.subscribe as applicationServerKey.
 *
 * The trickiest piece is the payload encryption: HKDF the salt + shared ECDH
 * secret + subscription auth secret into a CEK + nonce, then AES-128-GCM the
 * padded plaintext, then prepend the RFC 8188 header (salt || rs || idlen || key).
 * Compared this against the pywebpush reference vectors during dev.
 */
class Vapid {

	/* ---------------- public API used by Push.php + api.php ---------------- */

	public static function publicKeyB64Url() {
		self::ensureKeys();
		$row = store()->kvGet( 'vapid_public' );
		return $row ? (string) $row['v'] : '';
	}

	/**
	 * Send an encrypted push to a single subscription row.
	 * $subscription requires endpoint, p256dh, auth.
	 * @return array {ok:bool, code:int, error:string}
	 */
	public static function send( $subscription, $payload ) {
		self::ensureKeys();

		$endpoint = (string) $subscription['endpoint'];
		$ua_pub_b64 = (string) $subscription['p256dh'];
		$ua_auth_b64 = (string) $subscription['auth'];
		if ( $endpoint === '' || $ua_pub_b64 === '' || $ua_auth_b64 === '' ) {
			return array( 'ok' => false, 'code' => 0, 'error' => 'incomplete_sub' );
		}

		$ua_pub  = self::b64urlDecode( $ua_pub_b64 );
		$ua_auth = self::b64urlDecode( $ua_auth_b64 );

		// Encrypt the payload with aes128gcm content-encoding
		$enc = self::encryptAes128Gcm( $payload, $ua_pub, $ua_auth );
		if ( ! $enc ) { return array( 'ok' => false, 'code' => 0, 'error' => 'encrypt_failed' ); }

		// Build VAPID JWT for the Authorization header
		$origin = parse_url( $endpoint );
		$aud = $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' );
		$jwt = self::vapidJwt( $aud );
		$server_pub_b64 = self::publicKeyB64Url();

		$headers = array(
			'Content-Encoding: aes128gcm',
			'Content-Type: application/octet-stream',
			'TTL: 3600',
			'Urgency: normal',
			'Content-Length: ' . strlen( $enc ),
			'Authorization: vapid t=' . $jwt . ', k=' . $server_pub_b64,
		);

		$ch = curl_init( $endpoint );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $enc,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 8,   // runs in worker/notify.php, off the WS loop
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HEADER         => false,
		) );
		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = (string) curl_error( $ch );
		curl_close( $ch );

		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'code' => $code, 'error' => '' );
		}
		return array( 'ok' => false, 'code' => $code, 'error' => $err ?: substr( (string) $body, 0, 200 ) );
	}

	/* ---------------- keypair mgmt ---------------- */

	public static function keyPath() {
		return dirname( __DIR__ ) . '/data/vapid.pem';
	}

	public static function ensureKeys() {
		$path = self::keyPath();
		if ( is_file( $path ) ) { return; }
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0770, true ); }
		$pk = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $pk ) { throw new RuntimeException( 'openssl_pkey_new failed: ' . openssl_error_string() ); }
		openssl_pkey_export_to_file( $pk, $path );
		@chmod( $path, 0600 );

		$details = openssl_pkey_get_details( $pk );
		// Public key uncompressed: 0x04 || X || Y (65 bytes total for P-256)
		$xy = "\x04" . str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT )
			. str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		store()->kvSet( 'vapid_public', self::b64urlEncode( $xy ) );
	}

	public static function loadPrivateKey() {
		$pem = @file_get_contents( self::keyPath() );
		if ( ! $pem ) { throw new RuntimeException( 'no VAPID keys — call ensureKeys first' ); }
		return openssl_pkey_get_private( $pem );
	}

	/* ---------------- VAPID JWT (RFC 8292) ---------------- */

	private static function vapidJwt( $audience ) {
		$header = array( 'typ' => 'JWT', 'alg' => 'ES256' );
		$claims = array(
			'aud' => $audience,
			'exp' => time() + 12 * 3600,   // spec caps at 24h; 12 is fine
			'sub' => (string) Config::get( 'vapid_subject', 'mailto:ops@example.com' ),
		);
		$h = self::b64urlEncode( json_encode( $header,  JSON_UNESCAPED_SLASHES ) );
		$c = self::b64urlEncode( json_encode( $claims,  JSON_UNESCAPED_SLASHES ) );
		$signing_input = $h . '.' . $c;

		$pk = self::loadPrivateKey();
		$der = '';
		if ( ! openssl_sign( $signing_input, $der, $pk, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'openssl_sign failed' );
		}
		// Convert DER-encoded ECDSA signature to fixed-length R||S (64 bytes for P-256)
		$rs = self::ecdsaDerToRs( $der, 32 );
		return $signing_input . '.' . self::b64urlEncode( $rs );
	}

	/**
	 * Take a DER-encoded ECDSA signature (SEQUENCE { INTEGER r, INTEGER s }) and
	 * return the fixed-length R||S concatenation JOSE ES256 expects.
	 */
	private static function ecdsaDerToRs( $der, $partLen ) {
		$off = 0;
		if ( ord( $der[ $off++ ] ) !== 0x30 ) { throw new RuntimeException( 'bad DER: no sequence' ); }
		$seqLen = ord( $der[ $off++ ] );
		if ( $seqLen & 0x80 ) {
			$n = $seqLen & 0x7F; $off += $n;
		}
		// r
		if ( ord( $der[ $off++ ] ) !== 0x02 ) { throw new RuntimeException( 'bad DER: no int (r)' ); }
		$rl = ord( $der[ $off++ ] );
		$r  = substr( $der, $off, $rl ); $off += $rl;
		// s
		if ( ord( $der[ $off++ ] ) !== 0x02 ) { throw new RuntimeException( 'bad DER: no int (s)' ); }
		$sl = ord( $der[ $off++ ] );
		$s  = substr( $der, $off, $sl ); $off += $sl;

		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		if ( strlen( $r ) > $partLen || strlen( $s ) > $partLen ) { throw new RuntimeException( 'DER int too big' ); }
		return str_pad( $r, $partLen, "\x00", STR_PAD_LEFT ) . str_pad( $s, $partLen, "\x00", STR_PAD_LEFT );
	}

	/* ---------------- aes128gcm content-encoding (RFC 8291) ---------------- */

	private static function encryptAes128Gcm( $plaintext, $ua_pub_uncompressed, $ua_auth_secret ) {
		// 1. Ephemeral ECDH keypair (fresh per message per RFC 8291)
		$eph = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $eph ) { return null; }
		$eph_details = openssl_pkey_get_details( $eph );
		$eph_pub_raw = "\x04" . str_pad( $eph_details['ec']['x'], 32, "\x00", STR_PAD_LEFT )
			. str_pad( $eph_details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

		// 2. ECDH shared secret with the UA's public key
		//    PHP has no direct openssl_ecdh — we shell out to openssl pkeyutl -derive.
		$shared = self::ecdh( $eph, $ua_pub_uncompressed );
		if ( $shared === false ) { return null; }

		// 3. 16-byte random salt
		$salt = random_bytes( 16 );

		// 4. HKDF chain per RFC 8291 §3.3
		// PRK_key = HKDF(auth_secret, ECDH_secret, "WebPush: info\0" || ua_pub || eph_pub, 32)
		$key_info = "WebPush: info\x00" . $ua_pub_uncompressed . $eph_pub_raw;
		$IKM      = hash_hkdf( 'sha256', $shared, 32, $key_info, $ua_auth_secret );

		// CEK = HKDF(salt, IKM, "Content-Encoding: aes128gcm\0", 16)
		$CEK  = hash_hkdf( 'sha256', $IKM, 16, "Content-Encoding: aes128gcm\x00", $salt );
		// NONCE = HKDF(salt, IKM, "Content-Encoding: nonce\0", 12)
		$nonce = hash_hkdf( 'sha256', $IKM, 12, "Content-Encoding: nonce\x00", $salt );

		// 5. Pad the plaintext (single-record so pad byte = 0x02, trailing zeros optional)
		//    RFC 8188 record: plaintext || 0x02 || 0x00 * pad_len
		$padded = $plaintext . "\x02";

		// 6. Encrypt with AES-128-GCM. openssl_encrypt returns ciphertext; tag is out-param.
		$tag = '';
		$ct  = openssl_encrypt( $padded, 'aes-128-gcm', $CEK, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( $ct === false ) { return null; }

		// 7. Build RFC 8188 header: salt(16) || rs(4, big-endian) || idlen(1) || keyid(idlen)
		//    keyid is our raw uncompressed public key (65 bytes)
		$rs = 4096; // record size; single record so any value >= actual length works
		$header = $salt . pack( 'N', $rs ) . chr( 65 ) . $eph_pub_raw;

		return $header . $ct . $tag;
	}

	/**
	 * ECDH shared-secret derivation. PHP's openssl extension does not expose
	 * EC_POINT_mul directly, so we shell out to the openssl CLI. The peer's raw
	 * uncompressed 65-byte public key is wrapped in a P-256 SubjectPublicKeyInfo
	 * DER so `openssl pkeyutl -derive` can read it.
	 */
	private static function ecdh( $our_pk, $peer_pub_raw ) {
		// Write our private key PEM and the peer's SPKI DER to temp files
		$td = sys_get_temp_dir();
		$myPath   = tempnam( $td, 'hbvp_' );
		$peerPath = tempnam( $td, 'hbpp_' );
		$outPath  = tempnam( $td, 'hbss_' );

		openssl_pkey_export_to_file( $our_pk, $myPath );

		// P-256 SPKI header (fixed): SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING pubkey }
		// The DER prefix for a P-256 uncompressed public key is 26 bytes; the pubkey itself is 65 bytes.
		$spki_prefix = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d03010703420000' );
		$spki_der    = substr( $spki_prefix, 0, -1 ) . $peer_pub_raw;
		// Wrap as PEM
		$peer_pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki_der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		file_put_contents( $peerPath, $peer_pem );

		$cmd = 'openssl pkeyutl -derive'
			. ' -inkey '  . escapeshellarg( $myPath )
			. ' -peerkey ' . escapeshellarg( $peerPath )
			. ' -out '    . escapeshellarg( $outPath )
			. ' 2>/dev/null';
		exec( $cmd, $_, $rc );

		$shared = false;
		if ( $rc === 0 ) { $shared = @file_get_contents( $outPath ); }
		@unlink( $myPath ); @unlink( $peerPath ); @unlink( $outPath );
		return $shared === '' ? false : $shared;
	}

	/* ---------------- base64url ---------------- */

	public static function b64urlEncode( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
	public static function b64urlDecode( $s ) {
		$s = strtr( $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) { $s .= str_repeat( '=', 4 - $pad ); }
		return base64_decode( $s );
	}
}
