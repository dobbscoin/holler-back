<?php
require_once __DIR__ . '/inc.php';

if ( ! Auth::passSet() ) { redirect( 'setup.php' ); }
if ( Auth::loggedIn() )  { redirect( 'index.php' ); }

$err = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! rate_hit( 'login:' . client_ip(), 900, 10 ) ) { http_response_code( 429 ); exit( 'Too many attempts. Try again in 15 minutes.' ); }
	csrf_check();
	if ( Auth::login( (string) req( 'pass', '' ) ) ) { redirect( 'index.php' ); }
	$err = 'Incorrect passphrase.';
}

admin_head( 'Sign in' );
?>
<div class="hb-card">
	<h2>Sign in</h2>
	<?php if ( $err ) : ?><div class="hb-err"><?= h( $err ) ?></div><?php endif ?>
	<form method="post" autocomplete="off">
		<?= csrf_field() ?>
		<label>Passphrase</label>
		<input type="password" name="pass" autocomplete="current-password" required autofocus>
		<button type="submit" class="p">Unlock</button>
	</form>
</div>
<?php admin_foot(); ?>
