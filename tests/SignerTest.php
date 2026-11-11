<?php
/**
 * Signer tests.
 *
 * @package SignedWebhooks
 */

use PHPUnit\Framework\TestCase;
use SignedWebhooks\Signer;

final class SignerTest extends TestCase {

	private const NOW = 1_762_840_000;

	public function test_signature_round_trips(): void {
		$header = Signer::sign( '{"a":1}', array( 'whsec_test' ), self::NOW );

		$this->assertMatchesRegularExpression( '/^t=\d+,v1=[0-9a-f]{64}$/', $header );
		$this->assertTrue( Signer::verify( $header, '{"a":1}', 'whsec_test', self::NOW ) );
	}

	public function test_tampered_body_is_rejected(): void {
		$header = Signer::sign( '{"amount":10}', array( 'whsec_test' ), self::NOW );

		$this->assertFalse( Signer::verify( $header, '{"amount":1000}', 'whsec_test', self::NOW ) );
	}

	public function test_wrong_secret_is_rejected(): void {
		$header = Signer::sign( 'body', array( 'whsec_right' ), self::NOW );

		$this->assertFalse( Signer::verify( $header, 'body', 'whsec_wrong', self::NOW ) );
	}

	public function test_old_signatures_are_rejected_to_stop_replays(): void {
		$header = Signer::sign( 'body', array( 'whsec_test' ), self::NOW );

		$this->assertTrue( Signer::verify( $header, 'body', 'whsec_test', self::NOW + 299 ) );
		$this->assertFalse( Signer::verify( $header, 'body', 'whsec_test', self::NOW + 301 ) );
	}

	public function test_both_secrets_verify_during_rotation(): void {
		$header = Signer::sign( 'body', array( 'whsec_new', 'whsec_old' ), self::NOW );

		$this->assertSame( 2, substr_count( $header, 'v1=' ) );
		$this->assertTrue( Signer::verify( $header, 'body', 'whsec_new', self::NOW ) );
		$this->assertTrue( Signer::verify( $header, 'body', 'whsec_old', self::NOW ) );
	}

	public function test_empty_previous_secret_is_skipped(): void {
		$header = Signer::sign( 'body', array( 'whsec_new', '' ), self::NOW );

		$this->assertSame( 1, substr_count( $header, 'v1=' ) );
	}

	/**
	 * @dataProvider malformed_headers
	 */
	public function test_malformed_headers_are_rejected( string $header ): void {
		$this->assertFalse( Signer::verify( $header, 'body', 'whsec_test', self::NOW ) );
	}

	public static function malformed_headers(): array {
		return array(
			'empty'        => array( '' ),
			'no timestamp' => array( 'v1=abc' ),
			'no signature' => array( 't=' . self::NOW ),
			'bad time'     => array( 't=yesterday,v1=abc' ),
		);
	}

	public function test_generated_secrets_are_random_and_prefixed(): void {
		$a = Signer::generate_secret();
		$b = Signer::generate_secret();

		$this->assertStringStartsWith( 'whsec_', $a );
		$this->assertSame( 54, strlen( $a ) );
		$this->assertNotSame( $a, $b );
	}
}
