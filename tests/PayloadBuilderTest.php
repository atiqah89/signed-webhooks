<?php
/**
 * Payload builder tests.
 *
 * @package SignedWebhooks
 */

use PHPUnit\Framework\TestCase;
use SignedWebhooks\Payload_Builder;

final class PayloadBuilderTest extends TestCase {

	/**
	 * @dataProvider transitions
	 */
	public function test_status_transitions_map_to_events( string $new, string $old, ?string $event ): void {
		$this->assertSame( $event, Payload_Builder::event_for_transition( $new, $old ) );
	}

	public static function transitions(): array {
		return array(
			'draft to publish'     => array( 'publish', 'draft', 'post.published' ),
			'scheduled goes live'  => array( 'publish', 'future', 'post.published' ),
			'edit published post'  => array( 'publish', 'publish', 'post.updated' ),
			'trash published post' => array( 'trash', 'publish', 'post.unpublished' ),
			'back to draft'        => array( 'draft', 'publish', 'post.unpublished' ),
			'draft saved'          => array( 'draft', 'draft', null ),
			'auto draft created'   => array( 'auto-draft', 'new', null ),
		);
	}

	public function test_envelope_shape(): void {
		$payload = Payload_Builder::post_event(
			'post.published',
			array( 'id' => '42', 'title' => 'Hello / Selamat datang', 'url' => 'https://example.com/hello/' ),
			'https://example.com',
			'5f0c2c1e-1111-4222-8333-444455556666',
			1762840000
		);

		$this->assertSame( '5f0c2c1e-1111-4222-8333-444455556666', $payload['id'] );
		$this->assertSame( 'post.published', $payload['type'] );
		$this->assertSame( '2025-11-11T05:46:40+00:00', $payload['created_at'] );
		$this->assertSame( 42, $payload['data']['post']['id'] );
		$this->assertSame( '', $payload['data']['post']['author'] );
	}

	public function test_encoding_is_stable_and_readable(): void {
		$json = Payload_Builder::encode( array( 'url' => 'https://example.com/a/', 'title' => 'Café' ) );

		$this->assertSame( '{"url":"https://example.com/a/","title":"Café"}', $json );
	}
}
