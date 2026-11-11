<?php
/**
 * Queues deliveries on post transitions and sends them from WP-Cron, so publishing
 * a post never waits on (or fails because of) a slow receiver.
 *
 * @package SignedWebhooks
 */

namespace SignedWebhooks;

/**
 * Dispatcher.
 */
final class Dispatcher {

	public const CRON_HOOK = 'signed_webhooks_deliver';
	public const POST_TYPE = 'swh_delivery';

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings.
	 * @param Retry_Policy $policy   Retry policy.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Retry_Policy $policy,
	) {}

	/**
	 * Hooks into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'deliver' ) );
	}

	/**
	 * Deliveries are stored as a private post type: no custom table or migrations needed,
	 * and they are cleaned up with the rest of the site's content.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'public'       => false,
				'show_ui'      => false,
				'supports'     => array( 'title' ),
				'can_export'   => false,
				'rewrite'      => false,
				'query_var'    => false,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Handles transition_post_status.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		/**
		 * Filters which post types send webhooks.
		 *
		 * @param string[] $post_types Default: post and page.
		 */
		$post_types = apply_filters( 'signed_webhooks_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $post_types, true ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$event    = Payload_Builder::event_for_transition( (string) $new_status, (string) $old_status );
		$settings = $this->settings->all();
		if ( null === $event || '' === $settings['endpoint'] || ! in_array( $event, $settings['events'], true ) ) {
			return;
		}

		$this->enqueue(
			$event,
			array(
				'id'           => $post->ID,
				'title'        => get_the_title( $post ),
				'url'          => get_permalink( $post ),
				'status'       => $new_status,
				'author'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
				'modified_gmt' => $post->post_modified_gmt,
			)
		);
	}

	/**
	 * Stores a delivery and schedules the first attempt right away.
	 *
	 * @param string $event Event name.
	 * @param array  $post  Post fields.
	 * @return int Delivery id.
	 */
	public function enqueue( string $event, array $post ): int {
		$delivery_id = wp_generate_uuid4();
		$body        = Payload_Builder::encode(
			Payload_Builder::post_event( $event, $post, home_url(), $delivery_id, time() )
		);

		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $event,
				'post_content' => wp_slash( $body ),
				'meta_input'   => array(
					'_swh_delivery_id' => $delivery_id,
					'_swh_status'      => 'pending',
					'_swh_attempts'    => 0,
				),
			)
		);

		if ( $id && ! is_wp_error( $id ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $id ) );
			return (int) $id;
		}
		return 0;
	}

	/**
	 * Sends one attempt. Called by WP-Cron.
	 *
	 * @param int $id Delivery post id.
	 */
	public function deliver( $id ): void {
		$delivery = get_post( (int) $id );
		$settings = $this->settings->all();
		if ( ! $delivery || self::POST_TYPE !== $delivery->post_type || '' === $settings['endpoint'] ) {
			return;
		}

		$body     = $delivery->post_content;
		$attempts = (int) get_post_meta( $delivery->ID, '_swh_attempts', true ) + 1;

		// wp_safe_remote_post blocks private and loopback addresses (SSRF protection).
		$response = wp_safe_remote_post(
			$settings['endpoint'],
			array(
				'body'        => $body,
				'timeout'     => 10,
				'redirection' => 0, // A redirect could forward the signed body somewhere else.
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'User-Agent'         => 'SignedWebhooks/' . SIGNED_WEBHOOKS_VERSION . '; ' . home_url(),
					'X-Webhook-Event'    => $delivery->post_title,
					'X-Webhook-Delivery' => (string) get_post_meta( $delivery->ID, '_swh_delivery_id', true ),
					'X-Webhook-Attempt'  => (string) $attempts,
					Signer::HEADER       => Signer::sign(
						$body,
						array( $settings['secret'], $settings['previous_secret'] ),
						time()
					),
				),
			)
		);

		$status      = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );
		$retry_after = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'retry-after' );
		$detail      = is_wp_error( $response )
			? $response->get_error_message()
			: substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );

		update_post_meta( $delivery->ID, '_swh_attempts', $attempts );
		update_post_meta( $delivery->ID, '_swh_last_status', $status ?? 0 );
		update_post_meta( $delivery->ID, '_swh_last_detail', sanitize_textarea_field( $detail ) );

		if ( null !== $status && $status >= 200 && $status < 300 ) {
			update_post_meta( $delivery->ID, '_swh_status', 'delivered' );
			return;
		}

		if ( $this->policy->should_retry( $status, $attempts ) ) {
			$delay = $this->policy->delay_for( $attempts, is_numeric( $retry_after ) ? (int) $retry_after : null );
			update_post_meta( $delivery->ID, '_swh_status', 'retrying' );
			update_post_meta( $delivery->ID, '_swh_next_attempt', time() + $delay );
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $delivery->ID ) );
			return;
		}

		update_post_meta( $delivery->ID, '_swh_status', 'failed' );
		/**
		 * Fires when a delivery has failed for good, for alerting.
		 *
		 * @param int      $id     Delivery post id.
		 * @param int|null $status Last HTTP status, null for a transport error.
		 */
		do_action( 'signed_webhooks_delivery_failed', $delivery->ID, $status );
	}

	/**
	 * Manually resends a delivery (resets the attempt counter).
	 *
	 * @param int $id Delivery post id.
	 */
	public function redeliver( int $id ): void {
		update_post_meta( $id, '_swh_attempts', 0 );
		update_post_meta( $id, '_swh_status', 'pending' );
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $id ) );
	}

	/**
	 * Most recent deliveries for the admin screen.
	 *
	 * @param int $limit How many.
	 * @return \WP_Post[]
	 */
	public function recent( int $limit = 20 ): array {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}
}
