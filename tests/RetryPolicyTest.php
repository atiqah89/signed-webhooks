<?php
/**
 * Retry policy tests.
 *
 * @package SignedWebhooks
 */

use PHPUnit\Framework\TestCase;
use SignedWebhooks\Retry_Policy;

final class RetryPolicyTest extends TestCase {

	/**
	 * @dataProvider statuses
	 */
	public function test_which_responses_are_retried( ?int $status, bool $expected ): void {
		$this->assertSame( $expected, ( new Retry_Policy() )->should_retry( $status, 1 ) );
	}

	public static function statuses(): array {
		return array(
			'network error'     => array( null, true ),
			'request timeout'   => array( 408, true ),
			'rate limited'      => array( 429, true ),
			'server error'      => array( 500, true ),
			'bad gateway'       => array( 502, true ),
			'bad request'       => array( 400, false ),
			'unauthorized'      => array( 401, false ),
			'not found'         => array( 404, false ),
			'unprocessable'     => array( 422, false ),
		);
	}

	public function test_stops_after_max_attempts(): void {
		$policy = new Retry_Policy( max_attempts: 3 );

		$this->assertTrue( $policy->should_retry( 500, 2 ) );
		$this->assertFalse( $policy->should_retry( 500, 3 ) );
	}

	public function test_backoff_doubles_and_is_capped(): void {
		$policy = new Retry_Policy( base_seconds: 60, cap_seconds: 300 );

		$this->assertSame(
			array( 60, 120, 240, 300, 300 ),
			array_map( array( $policy, 'delay_for' ), array( 1, 2, 3, 4, 5 ) )
		);
	}

	public function test_retry_after_wins_but_is_capped(): void {
		$policy = new Retry_Policy( cap_seconds: 3600 );

		$this->assertSame( 90, $policy->delay_for( 1, 90 ) );
		$this->assertSame( 3600, $policy->delay_for( 1, 86400 ) );
		$this->assertSame( 60, $policy->delay_for( 1, 0 ) );
	}
}
