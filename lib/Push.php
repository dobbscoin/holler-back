<?php
/**
 * Web Push fan-out. Full VAPID + payload encryption is implemented in Vapid.php.
 * This class is just the send loop. ws-server.php queues a push in notify_outbox
 * when a buyer writes and no console is on screen; worker/notify.php sends it.
 */
require_once __DIR__ . '/Vapid.php';

class Push {

	/** Notify all subscriptions on this workspace about a new buyer message. */
	public static function fanoutNewMessage( $workspace_id, $conv_id, $body_preview ) {
		self::fanout( $workspace_id, 'New chat message', mb_substr( (string) $body_preview, 0, 140 ), '/admin/#conv=' . (int) $conv_id, $conv_id );
	}

	/** @return int subscriptions that accepted the push */
	public static function fanout( $workspace_id, $title, $body, $url, $conv_id ) {
		$subs = store()->all( 'SELECT * FROM push_subscriptions WHERE workspace_id = ? AND fail_count < 5', array( (int) $workspace_id ) );
		if ( ! $subs ) { return 0; }
		$payload = json_encode( array(
			'title'   => (string) $title,
			'body'    => (string) $body,
			'conv_id' => (int) $conv_id,
			'url'     => (string) $url,
		) );
		$ok = 0;
		foreach ( $subs as $sub ) {
			$res = Vapid::send( $sub, $payload );
			$now = time();
			if ( $res['ok'] ) {
				$ok++;
				store()->q( 'UPDATE push_subscriptions SET last_ok_at = ?, fail_count = 0 WHERE id = ?', array( $now, (int) $sub['id'] ) );
			} else {
				store()->q( 'UPDATE push_subscriptions SET last_fail_at = ?, fail_count = fail_count + 1 WHERE id = ?', array( $now, (int) $sub['id'] ) );
				// 404/410 = subscription gone (unsubscribed / expired). Prune.
				if ( $res['code'] === 404 || $res['code'] === 410 ) {
					store()->q( 'DELETE FROM push_subscriptions WHERE id = ?', array( (int) $sub['id'] ) );
				}
				hb_log( 'push: send failed sub=' . $sub['id'] . ' code=' . $res['code'] . ' err=' . $res['error'] );
			}
		}
		return $ok;
	}
}
