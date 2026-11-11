<?php
/**
 * Builds the JSON body for each event. Pure PHP, so it is unit-tested without WordPress.
 *
 * @package SignedWebhooks
 */

namespace SignedWebhooks;

/**
 * Payload builder.
 */
final class Payload_Builder {

	public const EVENTS = array( 'post.published', 'post.updated', 'post.unpublished' );

	/**
	 * Maps a post status transition to an event name, or null if nothing should be sent.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 */
	public static function event_for_transition( string $new_status, string $old_status ): ?string {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			return 'post.published';
		}
		if ( 'publish' === $new_status && 'publish' === $old_status ) {
			return 'post.updated';
		}
		if ( 'publish' !== $new_status && 'publish' === $old_status ) {
			return 'post.unpublished';
		}
		return null; // Draft to draft, auto-draft, and so on.
	}

	/**
	 * Builds the envelope. The delivery id lets receivers deduplicate, because a retry
	 * can arrive after a response that timed out on our side but succeeded on theirs.
	 *
	 * @param string $event       Event name.
	 * @param array  $post        Post fields: id, title, url, status, author, modified_gmt.
	 * @param string $site_url    Home URL of this site.
	 * @param string $delivery_id Unique id for this delivery.
	 * @param int    $timestamp   Unix time the event happened.
	 */
	public static function post_event( string $event, array $post, string $site_url, string $delivery_id, int $timestamp ): array {
		return array(
			'id'          => $delivery_id,
			'type'        => $event,
			'created_at'  => gmdate( 'c', $timestamp ),
			'site'        => $site_url,
			'data'        => array(
				'post' => array(
					'id'           => (int) ( $post['id'] ?? 0 ),
					'title'        => (string) ( $post['title'] ?? '' ),
					'url'          => (string) ( $post['url'] ?? '' ),
					'status'       => (string) ( $post['status'] ?? '' ),
					'author'       => (string) ( $post['author'] ?? '' ),
					'modified_gmt' => (string) ( $post['modified_gmt'] ?? '' ),
				),
			),
			'api_version' => '2026-11-01',
		);
	}

	/**
	 * Encodes consistently; the exact bytes are what gets signed.
	 *
	 * @param array $payload Payload.
	 */
	public static function encode( array $payload ): string {
		return (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class, tested without WP.
	}
}
