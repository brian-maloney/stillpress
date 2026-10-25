<?php
/**
 * Signed tokens that mark a loopback request as a render.
 *
 * @package Stillpress
 */

namespace Stillpress\Render;

/**
 * Issues and verifies the X-Stillpress-Render header value (threat T7).
 *
 * Format: "<build_id>.<url_id>.<expires>.<hmac>", where hmac = HMAC-SHA256 over the first three parts with a
 * per-site random key. The token grants no privileges; it only turns on dependency recording for that URL.
 */
final class RenderToken {

	/** Request header name. */
	const HEADER = 'X-Stillpress-Render';

	/** Response header name echoed by the recorder. */
	const RECORDED_HEADER = 'X-Stillpress-Recorded';

	/** Option holding the HMAC key. */
	const KEY_OPTION = 'stillpress_render_key';

	/**
	 * HMAC key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key Secret key (at least 32 bytes).
	 * @throws \InvalidArgumentException When the key is too short.
	 */
	public function __construct( string $key ) {
		if ( strlen( $key ) < 32 ) {
			throw new \InvalidArgumentException( 'Render key is too short.' );
		}
		$this->key = $key;
	}

	/**
	 * Builds a token helper using the site's stored key, creating the key on first use.
	 *
	 * @return RenderToken
	 */
	public static function for_site(): RenderToken {
		$key = get_option( self::KEY_OPTION );
		if ( ! is_string( $key ) || strlen( $key ) < 64 ) {
			$key = bin2hex( random_bytes( 32 ) );
			update_option( self::KEY_OPTION, $key, false );
		}
		return new self( $key );
	}

	/**
	 * Issues a token.
	 *
	 * @param int $build_id Build ID.
	 * @param int $url_id   URL row ID.
	 * @param int $ttl      Lifetime in seconds.
	 * @param int $now      Current Unix time (for tests).
	 * @return string
	 */
	public function issue( int $build_id, int $url_id, int $ttl = 900, int $now = 0 ): string {
		$expires = ( $now > 0 ? $now : time() ) + $ttl;
		$payload = $build_id . '.' . $url_id . '.' . $expires;
		return $payload . '.' . hash_hmac( 'sha256', $payload, $this->key );
	}

	/**
	 * Verifies a token.
	 *
	 * @param string $token Header value.
	 * @param int    $now   Current Unix time (for tests).
	 * @return array{build_id:int,url_id:int}|null
	 */
	public function verify( string $token, int $now = 0 ): ?array {
		if ( strlen( $token ) > 200 || ! preg_match( '/^([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.([0-9]{1,12})\.([0-9a-f]{64})$/', $token, $m ) ) {
			return null;
		}
		$payload = $m[1] . '.' . $m[2] . '.' . $m[3];
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, $this->key ), $m[4] ) ) {
			return null;
		}
		if ( (int) $m[3] < ( $now > 0 ? $now : time() ) ) {
			return null;
		}
		return array(
			'build_id' => (int) $m[1],
			'url_id'   => (int) $m[2],
		);
	}
}
