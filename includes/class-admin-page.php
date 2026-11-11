<?php
/**
 * Settings → Signed Webhooks: endpoint, events, secret rotation, test ping and delivery log.
 *
 * @package SignedWebhooks
 */

namespace SignedWebhooks;

/**
 * Admin page.
 */
final class Admin_Page {

	private const SLUG       = 'signed-webhooks';
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Dispatcher $dispatcher Dispatcher.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Dispatcher $dispatcher,
	) {}

	/**
	 * Hooks into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_swh_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_swh_rotate', array( $this, 'handle_rotate' ) );
		add_action( 'admin_post_swh_retire', array( $this, 'handle_retire' ) );
		add_action( 'admin_post_swh_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_swh_redeliver', array( $this, 'handle_redeliver' ) );
	}

	/**
	 * Adds the menu item.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Signed Webhooks', 'signed-webhooks' ),
			__( 'Signed Webhooks', 'signed-webhooks' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Saves endpoint and events.
	 */
	public function handle_save(): void {
		$this->authorize( 'swh_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$endpoint = isset( $_POST['endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['endpoint'] ) ) : '';
		$events   = isset( $_POST['events'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['events'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$error    = $this->settings->save( $endpoint, $events );
		$this->back( null === $error ? 'saved' : 'invalid', $error );
	}

	/**
	 * Rotates the secret.
	 */
	public function handle_rotate(): void {
		$this->authorize( 'swh_rotate' );
		$this->settings->rotate_secret();
		$this->back( 'rotated' );
	}

	/**
	 * Stops signing with the previous secret.
	 */
	public function handle_retire(): void {
		$this->authorize( 'swh_retire' );
		$this->settings->retire_previous_secret();
		$this->back( 'retired' );
	}

	/**
	 * Queues a test event.
	 */
	public function handle_test(): void {
		$this->authorize( 'swh_test' );
		$this->dispatcher->enqueue(
			'post.published',
			array(
				'id'     => 0,
				'title'  => 'Test event from Signed Webhooks',
				'url'    => home_url(),
				'status' => 'publish',
				'author' => wp_get_current_user()->display_name,
			)
		);
		$this->back( 'test' );
	}

	/**
	 * Redelivers one delivery.
	 */
	public function handle_redeliver(): void {
		$this->authorize( 'swh_redeliver' );
		$id = isset( $_POST['delivery'] ) ? absint( $_POST['delivery'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		if ( $id && Dispatcher::POST_TYPE === get_post_type( $id ) ) {
			$this->dispatcher->redeliver( $id );
		}
		$this->back( 'redelivered' );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$settings = $this->settings->all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		$notice = isset( $_GET['swh'] ) ? sanitize_key( wp_unslash( $_GET['swh'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only.
		$error = isset( $_GET['swh_error'] ) ? sanitize_text_field( wp_unslash( $_GET['swh_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Signed Webhooks', 'signed-webhooks' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php elseif ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Done.', 'signed-webhooks' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="swh_save" />
				<?php wp_nonce_field( 'swh_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="swh-endpoint"><?php esc_html_e( 'Endpoint URL', 'signed-webhooks' ); ?></label></th>
						<td><input id="swh-endpoint" name="endpoint" type="url" class="regular-text" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" placeholder="https://example.com/webhooks/wordpress" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events', 'signed-webhooks' ); ?></th>
						<td>
							<?php foreach ( Payload_Builder::EVENTS as $event ) : ?>
								<label><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, $settings['events'], true ) ); ?> /> <code><?php echo esc_html( $event ); ?></code></label><br />
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'signed-webhooks' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Signing secret', 'signed-webhooks' ); ?></h2>
			<p><?php esc_html_e( 'Receivers verify the X-Webhook-Signature header with this secret. See the README for verification code.', 'signed-webhooks' ); ?></p>
			<p><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( $settings['secret'] ); ?>" onclick="this.select()" /></p>
			<?php if ( $settings['previous_secret'] ) : ?>
				<p><?php esc_html_e( 'Rotation in progress: deliveries are signed with both the new and the previous secret.', 'signed-webhooks' ); ?></p>
				<?php $this->button( 'swh_retire', __( 'Stop signing with the previous secret', 'signed-webhooks' ) ); ?>
			<?php endif; ?>
			<?php $this->button( 'swh_rotate', __( 'Rotate secret', 'signed-webhooks' ) ); ?>
			<?php $this->button( 'swh_test', __( 'Send test event', 'signed-webhooks' ) ); ?>

			<h2><?php esc_html_e( 'Recent deliveries', 'signed-webhooks' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'signed-webhooks' ); ?></th>
					<th><?php esc_html_e( 'Event', 'signed-webhooks' ); ?></th>
					<th><?php esc_html_e( 'Status', 'signed-webhooks' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'signed-webhooks' ); ?></th>
					<th><?php esc_html_e( 'Last response', 'signed-webhooks' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $this->dispatcher->recent() as $delivery ) : ?>
					<?php $last = (int) get_post_meta( $delivery->ID, '_swh_last_status', true ); ?>
					<tr>
						<td><?php echo esc_html( get_the_date( 'Y-m-d H:i:s', $delivery ) ); ?></td>
						<td><code><?php echo esc_html( $delivery->post_title ); ?></code></td>
						<td><?php echo esc_html( (string) get_post_meta( $delivery->ID, '_swh_status', true ) ); ?></td>
						<td><?php echo esc_html( (string) get_post_meta( $delivery->ID, '_swh_attempts', true ) ); ?></td>
						<td title="<?php echo esc_attr( (string) get_post_meta( $delivery->ID, '_swh_last_detail', true ) ); ?>">
							<?php echo $last ? esc_html( (string) $last ) : '&mdash;'; ?>
						</td>
						<td><?php $this->button( 'swh_redeliver', __( 'Redeliver', 'signed-webhooks' ), array( 'delivery' => $delivery->ID ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders a small admin-post form button with a nonce.
	 *
	 * @param string $action Action name, also used as nonce action.
	 * @param string $label  Button label.
	 * @param array  $fields Extra hidden fields.
	 */
	private function button( string $action, string $label, array $fields = array() ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Capability + nonce check for every state-changing action.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'signed-webhooks' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirects back to the settings page.
	 *
	 * @param string      $notice Notice key.
	 * @param string|null $error  Error message.
	 */
	private function back( string $notice, ?string $error = null ): void {
		$args = array(
			'page' => self::SLUG,
			'swh'  => $notice,
		);
		if ( $error ) {
			$args['swh_error'] = rawurlencode( $error );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}
}
