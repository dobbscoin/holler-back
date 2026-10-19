<?php
require_once __DIR__ . '/inc.php';
Auth::require_admin();

$ws = Workspace::primary();
if ( ! $ws ) { redirect( 'setup.php' ); }

$server_url = h( (string) Config::get( 'server_url', '' ) );
$widget_snippet = '<script src="' . $server_url . '/widget.js"' . "\n" . '        data-holler-id="' . h( $ws['install_token'] ) . '"' . "\n" . '        async defer></script>';

$tab = (string) req( 'tab', 'inbox' );
if ( $tab === 'settings' ) {
	require_once dirname( __DIR__ ) . '/lib/Notify.php';
	$ns = Notify::settings( $ws['id'] );
	$nstatus = Notify::status( $ws['id'] );
	$ndevices = (int) store()->one( 'SELECT COUNT(*) AS n FROM push_subscriptions WHERE workspace_id = ?', array( (int) $ws['id'] ) )['n'];
}
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
		<div class="hb-card">
			<h2>Notifications</h2>
			<div id="hb-notifymsg"></div>
			<form id="hb-notifyform">
				<h3>Discord</h3>
				<label>Webhook URL <span class="hint">(Discord: channel settings → Integrations → Webhooks → Copy URL)</span></label>
				<input type="url" name="discord_url" autocomplete="off" placeholder="<?= $ns['discord_url'] !== '' ? h( 'Saved: ' . Notify::urlHint( $ns['discord_url'] ) . ' (paste a new one to replace)' ) : 'https://discord.com/api/webhooks/…' ?>">
				<label class="ck"><input type="checkbox" name="on_new" <?= $ns['on_new'] ? 'checked' : '' ?>> New conversations</label>
				<label class="ck"><input type="checkbox" name="on_unanswered" <?= $ns['on_unanswered'] ? 'checked' : '' ?>> Unanswered messages after <input class="n" type="number" name="unanswered_min" min="1" max="1440" value="<?= (int) $ns['unanswered_min'] ?>"> minutes</label>
				<label class="ck"><input type="checkbox" name="include_text" <?= $ns['include_text'] ? 'checked' : '' ?>> Include the buyer's message text <span class="hint">(off = name and link only)</span></label>
				<?php if ( $ns['discord_url'] !== '' ) : ?>
				<label class="ck"><input type="checkbox" name="discord_remove"> Remove the saved webhook</label>
				<?php endif ?>
				<p class="hint" id="hb-discordstatus"><?php
					if ( $nstatus && $ns['discord_url'] !== '' ) {
						echo h( ( $nstatus['ok'] ? '✓ Last delivery ' : '✗ Last delivery failed ' ) . gmdate( 'Y-m-d H:i', (int) $nstatus['at'] ) . ' UTC (HTTP ' . (int) $nstatus['code'] . ( $nstatus['ok'] ? '' : ', ' . $nstatus['error'] ) . ')' );
					} elseif ( $ns['discord_url'] !== '' ) {
						echo 'Nothing sent yet.';
					}
				?></p>

				<h3>Away message</h3>
				<label>If nobody replies within <input class="n" type="number" name="away_min" min="1" max="1440" value="<?= (int) $ns['away_min'] ?>"> minutes, post this in the chat (blank = off). Links are clickable.</label>
				<textarea name="away_text" maxlength="1000" placeholder="Nobody's at the desk right now. Leave your email and we'll get back to you."><?= h( $ns['away_text'] ) ?></textarea>

				<button type="submit" class="p">Save</button>
				<button type="button" class="s" id="hb-discordtest">Send Discord test</button>
				<button type="button" class="s" id="hb-pushtest">Send push test</button>
				<p class="hint">Push goes to <?= $ndevices ?> device<?= $ndevices === 1 ? '' : 's' ?> (use Enable push on each), and only when no console window is on screen.</p>
			</form>
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
<script src="/admin.js?v=<?= (int) @filemtime( dirname( __DIR__ ) . '/public/admin.js' ) ?>"></script>
<?php admin_foot(); ?>
