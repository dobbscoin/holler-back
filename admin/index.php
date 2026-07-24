<?php
require_once __DIR__ . '/inc.php';
Auth::require_admin();

$ws = Workspace::primary();
if ( ! $ws ) { redirect( 'setup.php' ); }

$server_url = h( (string) Config::get( 'server_url', '' ) );
$widget_snippet = '<script src="' . $server_url . '/widget.js"' . "\n" . '        data-holler-id="' . h( $ws['install_token'] ) . '"' . "\n" . '        async defer></script>';

$tab = (string) req( 'tab', 'inbox' );
$is_ios_safari = is_ios_safari( $_SERVER['HTTP_USER_AGENT'] ?? '' );

admin_head( 'Console' );
?>
<div class="hb-shell">
	<div class="hb-bar">
		<h1><?= h( (string) Config::get( 'server_name', 'Holler Back' ) ) ?></h1>
		<span id="hb-conn" style="font-size:12px;color:#888">connecting…</span>
		<div class="hb-spacer"></div>
		<button type="button" id="hb-pushbtn" style="display:none">Enable push</button>
		<a class="btn" href="?tab=inbox" >Inbox</a>
		<a class="btn" href="?tab=install" >Install</a>
		<a class="btn" href="?tab=settings" >Settings</a>
		<a class="btn" href="logout.php">Sign out</a>
	</div>

	<div class="hb-main">
<?php if ( $tab === 'inbox' ) : ?>
		<div class="hb-2col">
			<div class="hb-list" id="hb-list"><div class="hb-empty">Loading…</div></div>
			<div class="hb-pane" id="hb-pane">
				<div class="hb-empty">Select a conversation on the left.</div>
			</div>
		</div>
<?php elseif ( $tab === 'install' ) : ?>
		<div class="hb-card">
			<h2>Install the widget</h2>
			<p>Paste this into any page where you want the chat bubble to appear:</p>
			<pre class="hb-code" style="white-space:pre-wrap"><?= h( $widget_snippet ) ?></pre>
			<p>Your <strong>install token</strong> is <code><?= h( $ws['install_token'] ) ?></code>. Origin allowlist is set on the Settings tab — the widget only works on domains you list there.</p>
		</div>
<?php elseif ( $tab === 'settings' ) : ?>
		<div class="hb-card">
			<h2>Workspace settings</h2>
			<div id="hb-savemsg"></div>
			<form id="hb-wsform">
				<label>Display name</label>
				<input type="text" name="display_name" maxlength="120" value="<?= h( $ws['display_name'] ) ?>">

				<label>Operator name (shown in the widget)</label>
				<input type="text" name="operator_name" maxlength="80" value="<?= h( $ws['operator_name'] ) ?>">

				<label>Allowed origins (one per line — <code>*</code> for any, dev only)</label>
				<textarea name="allowed_origins" style="min-height:120px"><?= h( $ws['allowed_origins'] ) ?></textarea>

				<button type="submit" class="p">Save</button>
			</form>
			<?php if ( $is_ios_safari ) : ?>
			<div class="note" style="margin-top:20px">
				<strong>On iPhone / iPad:</strong> For push notifications, open Safari's Share sheet
				and choose <strong>Add to Home Screen</strong>. Web Push on iOS requires the
				installed PWA (iOS 16.4+).
			</div>
			<?php endif ?>
		</div>
<?php endif ?>
	</div>
</div>

<script>
window.HB = {
	base: <?= json_encode( (string) Config::get( 'server_url', '' ), JSON_UNESCAPED_SLASHES ) ?>,
	csrf: <?= json_encode( csrf_token() ) ?>,
	workspace: <?= json_encode( array( 'id' => (int) $ws['id'], 'token' => $ws['install_token'] ) ) ?>,
	tab: <?= json_encode( $tab ) ?>,
};
</script>
<script src="/admin.js"></script>
<?php admin_foot(); ?>
