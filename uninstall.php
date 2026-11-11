<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package SignedWebhooks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'signed_webhooks_settings' );
wp_unschedule_hook( 'signed_webhooks_deliver' );

$signed_webhooks_ids = get_posts(
	array(
		'post_type'      => 'swh_delivery',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $signed_webhooks_ids as $signed_webhooks_id ) {
	wp_delete_post( $signed_webhooks_id, true );
}
