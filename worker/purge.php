<?php
/**
 * Retention purge. Runs from cron nightly. Deletes conversations (and their
 * messages, via ON DELETE CASCADE) older than retention_days. Also drops rate
 * bucket rows older than 24h since they're only useful for the current window.
 *
 * Cron:
 *   17 3 * * * www-data /usr/bin/php /var/www/holler-back/worker/purge.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$days   = max( 1, (int) Config::get( 'retention_days', 30 ) );
$cutoff = time() - $days * 86400;

$n = store()->q(
	'DELETE FROM conversations WHERE last_activity_at < ?',
	array( $cutoff )
)->rowCount();
$m = store()->q(
	'DELETE FROM rate_buckets WHERE window_start < ?',
	array( time() - 86400 )
)->rowCount();

store()->q( 'DELETE FROM notify_outbox WHERE done_at > 0 AND done_at < ?', array( time() - 86400 ) );

hb_log( "purge: dropped $n conversations older than {$days}d, $m rate buckets" );
echo "purged $n conversations, $m rate buckets\n";
