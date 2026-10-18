<?php
require_once __DIR__ . '/inc.php';

// If a passphrase is already set, this page is done — bounce to login (or straight through if logged in).
if ( Auth::passSet() ) { redirect( 'login.php' ); }

$err = '';
$ok  = '';
$step = 'welcome';

// Auto-generate a workspace if none exists (MVP: single-workspace UI).
if ( Workspace::count() === 0 && $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	Workspace::create( '', 'Support', '' );
}
$ws = Workspace::primary();

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	csrf_check();
	if ( ! rate_hit( 'setup:' . client_ip(), 900, 10 ) ) { http_response_code( 429 ); exit( 'Too many attempts. Try again in 15 minutes.' ); }
	$setupKey = trim( (string) Config::get( 'setup_key', '' ) );
	$pass = (string) req( 'pass', '' );
	$pass2 = (string) req( 'pass2', '' );
	$display = trim( (string) req( 'display_name', '' ) );
	$origin  = trim( (string) req( 'allowed_origins', '' ) );
	$op      = trim( (string) req( 'operator_name', 'Support' ) );

	if ( strlen( $setupKey ) < 16 || ! hash_equals( $setupKey, (string) req( 'setup_key', '' ) ) ) { $err = 'Setup key missing or wrong. It is the setup_key value in config.php.'; }
	elseif ( strlen( $pass ) < 10 ) { $err = 'Passphrase must be at least 10 characters.'; }
	elseif ( $pass !== $pass2 ) { $err = 'Passphrases don\'t match.'; }
	elseif ( $origin === '' ) { $err = 'At least one allowed origin is required (e.g. https://your-site.com).'; }
	else {
		Auth::setPassphrase( $pass );
		store()->q(
			'UPDATE workspaces SET display_name = ?, operator_name = ?, allowed_origins = ? WHERE id = ?',
			array( $display, $op ?: 'Support', $origin, (int) $ws['id'] )
		);
		require_once __DIR__ . '/../lib/Vapid.php';
		Vapid::ensureKeys();
		Auth::login( $pass );
		redirect( 'index.php' );
	}
}

$is_ios_safari = is_ios_safari( $_SERVER['HTTP_USER_AGENT'] ?? '' );
$server_url = h( (string) Config::get( 'server_url', 'https://chat.example.com' ) );
$widget_snippet = '<script src="' . $server_url . '/widget.js"' . "\n" . '        data-holler-id="' . h( $ws['install_token'] ) . '"' . "\n" . '        async defer></script>';

admin_head( 'Setup' );
?>
<div class="hb-card">
	<h2>Welcome to Holler Back</h2>
	<p>Set a passphrase for the admin console and tell us where the widget will live.</p>

	<?php if ( $err ) : ?><div class="hb-err"><?= h( $err ) ?></div><?php endif ?>

	<form method="post">
		<?= csrf_field() ?>

		<?php if ( strlen( trim( (string) Config::get( 'setup_key', '' ) ) ) < 16 ) : ?>
		<div class="hb-err">Set <code>setup_key</code> in config.php first (<code>openssl rand -hex 16</code>), then reload. It proves you are the one installing this server.</div>
		<?php endif ?>

		<label>Setup key (the setup_key value from config.php)</label>
		<input type="password" name="setup_key" required autocomplete="off" value="<?= h( (string) ( $_GET['key'] ?? '' ) ) ?>">

		<label>Admin passphrase (min 10 chars)</label>
		<input type="password" name="pass" required minlength="10" autocomplete="new-password">

		<label>Repeat passphrase</label>
		<input type="password" name="pass2" required minlength="10" autocomplete="new-password">

		<label>Display name (shown in the widget header — optional)</label>
		<input type="text" name="display_name" maxlength="120" placeholder="e.g. Acme Store">

		<label>Operator name (who the buyer sees replying)</label>
		<input type="text" name="operator_name" maxlength="80" value="Support">

		<label>Allowed origins (one per line — the domains your widget will be embedded on)</label>
		<textarea name="allowed_origins" placeholder="https://your-site.com&#10;https://www.your-site.com" required></textarea>

		<div class="note">
			<strong>Widget snippet</strong> — after setup you'll paste this into any page you want the bubble on:
			<pre class="hb-code" style="margin-top:6px;white-space:pre-wrap"><?= h( $widget_snippet ) ?></pre>
		</div>

		<?php if ( $is_ios_safari ) : ?>
		<div class="note">
			<strong>Heads up — iPhone / iPad users:</strong> To receive push notifications on iOS,
			you must first tap the <strong>Share</strong> icon in Safari and choose
			<strong>Add to Home Screen</strong> after the console loads. Push works only from the
			installed PWA on iOS 16.4+.
		</div>
		<?php endif ?>

		<button type="submit" class="p">Create passphrase & continue</button>
	</form>
</div>
<?php admin_foot(); ?>
