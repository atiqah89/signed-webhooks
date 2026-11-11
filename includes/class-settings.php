<?php
/**
 * Stores the plugin settings in a single option.
 *
 * @package SignedWebhooks
 */

namespace SignedWebhooks;

/**
 * Settings repository.
 */
final class Settings {

	public const OPTION = 'signed_webhooks_settings';

	/**
	 * All settings with defaults.
	 *
	 * @return array{endpoint: string, secret: string, previous_secret: string, events: string[]}
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'endpoint'        => '',
				'secret'          => '',
				'previous_secret' => '',
				'events'          => array( 'post.published' ),
			)
		);
	}

	/**
	 * Saves endpoint and events after validation. Returns an error message or null.
	 *
	 * @param string   $endpoint Endpoint URL.
	 * @param string[] $events   Selected events.
	 */
	public function save( string $endpoint, array $events ): ?string {
		$endpoint = esc_url_raw( trim( $endpoint ), array( 'https', 'http' ) );
		$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$scheme   = (string) wp_parse_url( $endpoint, PHP_URL_SCHEME );

		if ( '' !== $endpoint && 'https' !== $scheme && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return __( 'The endpoint must use HTTPS, because the payload and signature would otherwise travel in plain text.', 'signed-webhooks' );
		}

		$settings             = $this->all();
		$settings['endpoint'] = $endpoint;
		$settings['events']   = array_values( array_intersect( $events, Payload_Builder::EVENTS ) );
		update_option( self::OPTION, $settings, false );
		return null;
	}

	/**
	 * Creates a secret if none exists (on activation).
	 */
	public function ensure_secret(): void {
		$settings = $this->all();
		if ( '' === $settings['secret'] ) {
			$settings['secret'] = Signer::generate_secret();
			update_option( self::OPTION, $settings, false );
		}
	}

	/**
	 * Rotates the secret. The old one keeps signing too until the next rotation,
	 * so receivers can switch without dropping events.
	 */
	public function rotate_secret(): void {
		$settings                    = $this->all();
		$settings['previous_secret'] = $settings['secret'];
		$settings['secret']          = Signer::generate_secret();
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Stops signing with the previous secret.
	 */
	public function retire_previous_secret(): void {
		$settings                    = $this->all();
		$settings['previous_secret'] = '';
		update_option( self::OPTION, $settings, false );
	}
}
