<?php
/**
 * Decides whether and when a failed delivery is retried.
 *
 * @package SignedWebhooks
 */

namespace SignedWebhooks;

/**
 * Exponential backoff: 1 min, 2, 4, 8, 16 ... capped, honouring Retry-After.
 */
final class Retry_Policy {

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Total attempts including the first one.
	 * @param int $base_seconds Delay after the first failure.
	 * @param int $cap_seconds  Longest delay between attempts.
	 */
	public function __construct(
		private readonly int $max_attempts = 6,
		private readonly int $base_seconds = 60,
		private readonly int $cap_seconds = 6 * 3600,
	) {}

	/**
	 * Whether a response is worth retrying.
	 *
	 * Null means the request never got a response (DNS, timeout, TLS): retry.
	 * 408, 429 and 5xx are temporary. Other 4xx mean the receiver rejected the payload,
	 * and sending it again won't change that.
	 *
	 * @param int|null $status        HTTP status, or null for a transport error.
	 * @param int      $attempts_made Attempts so far, including this one.
	 */
	public function should_retry( ?int $status, int $attempts_made ): bool {
		if ( $attempts_made >= $this->max_attempts ) {
			return false;
		}
		if ( null === $status ) {
			return true;
		}
		return 408 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @param int      $attempts_made Attempts so far.
	 * @param int|null $retry_after   Seconds from the receiver's Retry-After header, if any.
	 */
	public function delay_for( int $attempts_made, ?int $retry_after = null ): int {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( $retry_after, $this->cap_seconds );
		}
		$delay = $this->base_seconds * ( 2 ** max( 0, $attempts_made - 1 ) );
		return (int) min( $delay, $this->cap_seconds );
	}

	/**
	 * Maximum attempts.
	 */
	public function max_attempts(): int {
		return $this->max_attempts;
	}
}
