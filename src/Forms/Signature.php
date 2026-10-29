<?php
/**
 * Signatures on forwarded submissions and webhooks.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

/**
 * Signs a request body with the forms secret: header `X-Stillpress-Signature: t=<unix time>,v1=<hex>`, where v1 is
 * HMAC-SHA256 over "<t>.<body>". The time binds the signature to a five-minute window; the relay also remembers
 * signatures it has accepted, so a captured request can't be replayed inside that window.
 *
 * The standalone receivers sign the same way (tests/fixtures/forms-cases.json has shared vectors).
 */
final class Signature {

	/** Request header. */
	const HEADER = 'X-Stillpress-Signature';

	/** Seconds a signature stays valid either side of its time. */
	const TOLERANCE = 300;

	/**
	 * Signs a body.
	 *
	 * @param string $body   Request body.
	 * @param string $secret Shared secret.
	 * @param int    $time   Unix time.
	 * @return string Header value.
	 */
	public static function sign( string $body, string $secret, int $time ): string {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	/**
	 * Checks a signature.
	 *
	 * @param string $header Header value.
	 * @param string $body   Request body.
	 * @param string $secret Shared secret.
	 * @param int    $now    Current Unix time.
	 * @return bool
	 */
	public static function verify( string $header, string $body, string $secret, int $now ): bool {
		if ( '' === $secret || ! preg_match( '/^t=([0-9]{1,12}),v1=([0-9a-f]{64})$/', trim( $header ), $m ) ) {
			return false;
		}
		$time = (int) $m[1];
		if ( abs( $now - $time ) > self::TOLERANCE ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $time . '.' . $body, $secret ), $m[2] );
	}
}
