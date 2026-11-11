<?php
/**
 * Plugin Name:       Signed Webhooks
 * Plugin URI:        https://github.com/atiqah89/signed-webhooks
 * Description:       Sends HMAC-signed webhooks when posts are published, updated or unpublished, with automatic retries and a delivery log.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            atiqah89
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       signed-webhooks
 *
 * @package SignedWebhooks
 */

defined( 'ABSPATH' ) || exit;

define( 'SIGNED_WEBHOOKS_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/class-signer.php';
require_once __DIR__ . '/includes/class-retry-policy.php';
require_once __DIR__ . '/includes/class-payload-builder.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-dispatcher.php';
require_once __DIR__ . '/includes/class-admin-page.php';

add_action(
	'plugins_loaded',
	static function () {
		$settings   = new SignedWebhooks\Settings();
		$dispatcher = new SignedWebhooks\Dispatcher( $settings, new SignedWebhooks\Retry_Policy() );
		$dispatcher->register();

		if ( is_admin() ) {
			( new SignedWebhooks\Admin_Page( $settings, $dispatcher ) )->register();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/class-settings.php';
		( new SignedWebhooks\Settings() )->ensure_secret();
	}
);
