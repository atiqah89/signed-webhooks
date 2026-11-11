<?php
/**
 * HMAC signing and verification for webhook payloads.
 *
 * Header format (same idea as Stripe's):  t=1731300000,v1=<hex>,v1=<hex>
 *   - t  is the Unix timestamp the payload was signed at; receivers reject old ones (replay protection).
 *   - v1 is HMAC-SHA256 of "{t}.{raw body}". There can be several, so a receiver keeps working
 *        while the secret is being rotated.
 *
 * This class has no WordPress dependencies, so it is unit-tested directly.
 *
 * @package SignedWebhooks
 */

namespace SignedWebhooks;

/**
 * Signs and verifies payloads.
 */
final class Signer {

	public const HEADER = 'X-Webhook-Signature';

	/**
	 * Signs a payload with one or more secrets.
	 *
	 * @param string   $payload   Raw request body, exactly as it will be sent.
	 * @param string[] $secrets   Current secret first; previous secret during rotation.
	 * @param int      $timestamp Unix time.
	 * @return string Header value.
	 */
	public static function sign( string $payload, array $secrets, int $timestamp ): string {
		$parts = array( 't=' . $timestamp );
		foreach ( array_filter( $secrets ) as $secret ) {
			$parts[] = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		}
		return implode( ',', $parts );
	}

	/**
	 * Verifies a header against a payload. This is what a receiver runs.
	 *
	 * @param string $header    Header value received.
	 * @param string $payload   Raw request body received (not re-encoded JSON).
	 * @param string $secret    Receiver's secret.
	 * @param int    $now       Current Unix time.
	 * @param int    $tolerance Maximum age in seconds.
	 */
	public static function verify( string $header, string $payload, string $secret, int $now, int $tolerance = 300 ): bool {
		$timestamp  = null;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( null === $timestamp || empty( $signatures ) || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) { // Constant-time comparison.
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates a new random secret.
	 */
	public static function generate_secret(): string {
		return 'whsec_' . bin2hex( random_bytes( 24 ) );
	}
}
