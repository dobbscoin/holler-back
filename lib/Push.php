<?php
/**
 * Web Push fan-out. Full VAPID + payload encryption is implemented in Vapid.php.
 * This class is just the "when a buyer sends a message and no admin socket is
 * connected" send loop — called from ws-server.php.
 */
require_once __DIR__ . '/Vapid.php';

class Push {

	/** Notify all subscriptions on this workspace about a new buyer message. */
	public static function fanoutNewMessage( $workspace_id, $conv_id, $body_preview ) {
		$subs = store()->all( 'SELECT * FROM push_subscriptions WHERE workspace_id = ? AND fail_count < 5', array( (int) $workspace_id ) );
		if ( ! $subs ) { return; }
		$preview = mb_substr( (string) $body_preview, 0, 140 );
		$payload = json_encode( array(
			'title'   => 'New chat message',
			'body'    => $preview,
			'conv_id' => (int) $conv_id,
			'url'     => '/admin/#conv=' . (int) $conv_id,
		) );
		foreach ( $subs as $sub ) {
			$res = Vapid::send( $sub, $payload );
			$now = time();
			if ( $res['ok'] ) {
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
	}
}
