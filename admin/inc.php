<?php
// Buffer the whole page: csrf_token() may set a cookie after admin_head() has
// printed ~5 KB of CSS, past FPM's 4 KB output_buffering.
ob_start();
require_once __DIR__ . '/../lib/bootstrap.php';

function admin_head( $title ) {
	$name = h( (string) Config::get( 'server_name', 'Holler Back' ) );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
	echo '<meta name="theme-color" content="#2f6feb">';
	echo '<title>' . h( $title ) . ' — ' . $name . '</title>';
	echo '<link rel="manifest" href="/manifest.json">';
	echo '<link rel="icon" href="data:image/svg+xml;utf8,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="4" fill="#2f6feb"/><path d="M6 8h12v6H10l-4 4z" fill="#fff"/></svg>' ) . '">';
	echo '<style>' . admin_css() . '</style>';
	echo '</head><body>';
}

function admin_foot() { echo '</body></html>'; }

function admin_css() {
	return '
	*{box-sizing:border-box}
	body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f4f5f9;color:#1f2330;font-size:14px}
	a{color:#2f6feb;text-decoration:none} a:hover{text-decoration:underline}
	.hb-shell{display:flex;flex-direction:column;height:100vh}
	.hb-bar{background:#1f2330;color:#fff;padding:10px 16px;display:flex;align-items:center;gap:14px;flex-shrink:0}
	.hb-bar h1{margin:0;font-size:16px;font-weight:600}
	.hb-bar .hb-spacer{flex:1}
	.hb-bar .hb-badge{background:#e53935;color:#fff;font-size:11px;font-weight:600;min-width:18px;height:18px;border-radius:9px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center}
	.hb-bar button, .hb-bar a.btn{background:#2f3444;color:#fff;border:none;border-radius:6px;padding:6px 10px;font-size:13px;cursor:pointer}
	.hb-bar button:hover{background:#3a405a}
	.hb-tabs{display:flex;gap:2px;background:#1f2330;padding:0 12px;flex-shrink:0}
	.hb-tabs a{color:#a4abbf;padding:8px 14px;font-size:13px;border-radius:6px 6px 0 0}
	.hb-tabs a.on{background:#f4f5f9;color:#1f2330}
	.hb-main{flex:1;min-height:0;overflow-y:auto}
	.hb-2col{display:grid;grid-template-columns:280px 1fr;height:100%}
	.hb-list{background:#fff;border-right:1px solid #e4e6ee;overflow-y:auto}
	.hb-conv{padding:10px 12px;border-bottom:1px solid #eef0f5;cursor:pointer}
	.hb-conv:hover{background:#f8f9fc}
	.hb-conv.on{background:#eef4ff}
	.hb-conv .n{font-weight:600;font-size:13px}
	.hb-conv .p{color:#666;font-size:12px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.hb-conv .t{color:#999;font-size:11px;margin-top:2px}
	.hb-conv .dot{display:inline-block;width:7px;height:7px;background:#2f6feb;border-radius:50%;margin-right:4px;vertical-align:middle;visibility:hidden}
	.hb-conv.unread .dot{visibility:visible}
	.hb-pane{background:#f4f5f9;display:flex;flex-direction:column;overflow:hidden}
	.hb-phdr{background:#fff;border-bottom:1px solid #e4e6ee;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
	.hb-plog{flex:1;overflow-y:auto;padding:14px}
	.hb-m{margin:6px 0;padding:8px 12px;border-radius:10px;max-width:70%;line-height:1.4;white-space:pre-wrap;word-wrap:break-word}
	.hb-m.buyer{background:#fff;color:#222;border:1px solid #e2e5ee;border-bottom-left-radius:2px}
	.hb-m.operator{background:#2f6feb;color:#fff;margin-left:auto;border-bottom-right-radius:2px}
	.hb-m.system{background:transparent;color:#888;font-style:italic;text-align:center;font-size:12px;max-width:100%}
	.hb-reply{border-top:1px solid #e4e6ee;background:#fff;padding:10px;display:flex;gap:8px;flex-shrink:0}
	.hb-reply textarea{flex:1;resize:none;border:1px solid #d6d9e3;border-radius:8px;padding:8px;font-family:inherit;font-size:14px;min-height:40px;max-height:120px}
	.hb-reply textarea:focus{outline:none;border-color:#2f6feb}
	.hb-reply button{background:#2f6feb;color:#fff;border:none;border-radius:8px;padding:0 16px;font-weight:600;cursor:pointer}
	.hb-empty{padding:40px 20px;text-align:center;color:#888}
	.hb-card{max-width:520px;margin:60px auto;background:#fff;border-radius:10px;padding:30px;box-shadow:0 4px 24px rgba(0,0,0,.05)}
	.hb-card h2{margin:0 0 14px;font-size:20px}
	.hb-card label{display:block;margin:12px 0 4px;font-size:13px;color:#555}
	.hb-card input,.hb-card textarea{width:100%;padding:8px 10px;border:1px solid #d6d9e3;border-radius:6px;font-family:inherit;font-size:14px}
	.hb-card textarea{min-height:80px;resize:vertical}
	.hb-card button.p{margin-top:16px;background:#2f6feb;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer;font-size:14px}
	.hb-card .note{background:#fef5e1;border:1px solid #f4d47a;color:#5a4200;padding:10px 12px;border-radius:6px;margin:14px 0;font-size:13px}
	.hb-code{background:#f4f5f9;border:1px solid #e4e6ee;padding:8px 10px;border-radius:6px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;word-break:break-all}
	.hb-card + .hb-card{margin-top:-30px}
	.hb-card h3{margin:22px 0 4px;font-size:15px}
	.hb-card label.ck{display:flex;align-items:center;gap:8px;color:#222;font-size:14px;margin:8px 0}
	.hb-card label.ck input{width:auto;margin:0}
	.hb-card input.n{width:64px;display:inline-block;padding:4px 6px}
	.hb-card .hint{font-size:12px;color:#888;margin:4px 0 0}
	.hb-card button.s{margin:16px 0 0 8px;background:#eef1f7;color:#223;border:1px solid #d6d9e3;border-radius:8px;padding:9px 14px;cursor:pointer;font-size:14px}
	.hb-err{color:#c00;font-size:13px;margin:8px 0}
	.hb-ok{color:#080;font-size:13px;margin:8px 0}
	@media (max-width:640px){.hb-2col{grid-template-columns:1fr}.hb-list{display:none}.hb-list.on{display:block}.hb-pane{display:none}.hb-pane.on{display:flex}}
	';
}

/** Detect Safari on iOS (Web Push works but only from installed PWA). */
function is_ios_safari( $ua ) {
	$ua = (string) $ua;
	if ( ! preg_match( '/(iPhone|iPad|iPod)/', $ua ) ) { return false; }
	return preg_match( '/Safari/', $ua ) && ! preg_match( '/CriOS|FxiOS|EdgiOS/', $ua );
}
