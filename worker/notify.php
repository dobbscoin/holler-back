<?php
/**
 * Notification worker. Long-running under systemd, beside ws-server.php.
 *
 * Drains notify_outbox (Web Push queued by the WS server) about once a second,
 * and scans for Discord pings and away messages every few seconds. Sending
 * here keeps slow push/Discord endpoints off the WS loop.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Notify.php';

$stop = false;
pcntl_async_signals( true );
pcntl_signal( SIGTERM, function () use ( &$stop ) { $stop = true; } );
pcntl_signal( SIGINT,  function () use ( &$stop ) { $stop = true; } );

hb_log( 'notify: worker started' );
$lastScan = 0;
while ( ! $stop ) {
	try {
		Notify::drainOutbox();
		if ( time() - $lastScan >= 5 ) {
			$lastScan = time();
			Notify::scan();
		}
	} catch ( Throwable $e ) {
		hb_log( 'notify: ' . $e->getMessage() );
		sleep( 5 );
	}
	usleep( 1000000 );
}
hb_log( 'notify: worker stopping' );
