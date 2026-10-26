<?php
/**
 * AWS Signature Version 4.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Aws;

/**
 * Signs requests to AWS (and S3-compatible) APIs with Signature Version 4.
 *
 * This is request authentication, not encryption: an HMAC-SHA256 over a canonical form of the request, with a
 * signing key derived from the secret key, date, region and service. PHP's hash_hmac() does the cryptography; this
 * class only builds the canonical strings. It is checked against AWS's published SigV4 test suite
 * (tests/fixtures/aws4_testsuite) and AWS's S3 examples; see DECISIONS.md D13.
 *
 * Paths and queries are passed as they go on the wire (percent-encoded). S3 mode signs the path exactly as sent;
 * every other service normalizes dot segments and encodes the path a second time, as the specification requires.
 *
 * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final class SigV4 {

	/** Algorithm name. */
	const ALGORITHM = 'AWS4-HMAC-SHA256';

	/** SHA-256 of an empty payload. */
	const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Access key ID.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Session token for temporary credentials, or ''.
	 *
	 * @var string
	 */
	private $session_token;

	/**
	 * Region, for example us-east-1.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Service, for example s3 or cloudfront.
	 *
	 * @var string
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param string $access_key    Access key ID.
	 * @param string $secret_key    Secret access key.
	 * @param string $region        Region.
	 * @param string $service       Service name.
	 * @param string $session_token Session token, or '' for long-term credentials.
	 */
	public function __construct( string $access_key, string $secret_key, string $region, string $service, string $session_token = '' ) {
		$this->access_key    = $access_key;
		$this->secret_key    = $secret_key;
		$this->region        = $region;
		$this->service       = $service;
		$this->session_token = $session_token;
	}

	/**
	 * Signs a request and returns the headers to add to it.
	 *
	 * @param string                              $method       HTTP method.
	 * @param string                              $host         Host header value (with a port if it isn't the default).
	 * @param string                              $path         Path as sent, percent-encoded.
	 * @param string                              $query        Query string as sent, without "?".
	 * @param array<int,array{0:string,1:string}> $headers Other headers to sign, as name/value pairs (repeats allowed).
	 * @param string                              $payload_hash Hex SHA-256 of the body.
	 * @param int                                 $time         Unix time of the request.
	 * @return array<string,string> Header name => value: Authorization, X-Amz-Date, and X-Amz-Security-Token and
	 *                              x-amz-content-sha256 where they apply. Send these with the request as well as $headers.
	 */
	public function sign( string $method, string $host, string $path, string $query, array $headers, string $payload_hash, int $time ): array {
		$date  = gmdate( 'Ymd\THis\Z', $time );
		$added = array( 'X-Amz-Date' => $date );
		if ( '' !== $this->session_token ) {
			$added['X-Amz-Security-Token'] = $this->session_token;
		}
		if ( 's3' === $this->service ) {
			$added['x-amz-content-sha256'] = $payload_hash;
		}

		$all = array( array( 'host', $host ) );
		foreach ( $headers as $header ) {
			if ( 'host' !== strtolower( $header[0] ) ) {
				$all[] = $header;
			}
		}
		foreach ( $added as $name => $value ) {
			$all[] = array( $name, $value );
		}

		$canonical = $this->canonical_request( $method, $path, $query, $all, $payload_hash );
		$scope     = $this->scope( $time );
		$to_sign   = self::ALGORITHM . "\n" . $date . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$signature = hash_hmac( 'sha256', $to_sign, $this->signing_key( $time ) );

		$added['Authorization'] = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			self::ALGORITHM,
			$this->access_key,
			$scope,
			implode( ';', array_keys( self::canonical_headers( $all ) ) ),
			$signature
		);
		return $added;
	}

	/**
	 * Builds the canonical request.
	 *
	 * @param string                              $method       HTTP method.
	 * @param string                              $path         Path as sent.
	 * @param string                              $query        Query as sent.
	 * @param array<int,array{0:string,1:string}> $headers      Every signed header, including host and x-amz-date.
	 * @param string                              $payload_hash Hex SHA-256 of the body.
	 * @return string
	 */
	public function canonical_request( string $method, string $path, string $query, array $headers, string $payload_hash ): string {
		$canonical = self::canonical_headers( $headers );
		$lines     = '';
		foreach ( $canonical as $name => $value ) {
			$lines .= $name . ':' . $value . "\n";
		}
		return strtoupper( $method ) . "\n"
			. $this->canonical_path( $path ) . "\n"
			. self::canonical_query( $query ) . "\n"
			. $lines . "\n"
			. implode( ';', array_keys( $canonical ) ) . "\n"
			. $payload_hash;
	}

	/**
	 * Builds the string to sign for a canonical request.
	 *
	 * @param string $canonical_request Canonical request.
	 * @param int    $time              Unix time.
	 * @return string
	 */
	public function string_to_sign( string $canonical_request, int $time ): string {
		return self::ALGORITHM . "\n" . gmdate( 'Ymd\THis\Z', $time ) . "\n" . $this->scope( $time ) . "\n" . hash( 'sha256', $canonical_request );
	}

	/**
	 * Credential scope.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function scope( int $time ): string {
		return gmdate( 'Ymd', $time ) . '/' . $this->region . '/' . $this->service . '/aws4_request';
	}

	/**
	 * Derives the signing key for the request date.
	 *
	 * @param int $time Unix time.
	 * @return string Binary key.
	 */
	private function signing_key( int $time ): string {
		$key = hash_hmac( 'sha256', gmdate( 'Ymd', $time ), 'AWS4' . $this->secret_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );
		return hash_hmac( 'sha256', 'aws4_request', $key, true );
	}

	/**
	 * Canonical URI.
	 *
	 * @param string $path Path as sent.
	 * @return string
	 */
	private function canonical_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}
		if ( 's3' === $this->service ) {
			// S3 signs the key exactly as sent, so re-encoding a decoded segment must give back the same bytes.
			return implode( '/', array_map( array( self::class, 'encode' ), array_map( 'rawurldecode', explode( '/', $path ) ) ) );
		}
		$trailing = '/' === substr( $path, -1 );
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = self::encode( $segment );
		}
		$out = '/' . implode( '/', $segments );
		return $trailing && '/' !== $out ? $out . '/' : $out;
	}

	/**
	 * Canonical query string: each name and value decoded, encoded again, and sorted.
	 *
	 * @param string $query Query as sent.
	 * @return string
	 */
	private static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$kv      = explode( '=', $part, 2 );
			$pairs[] = array( self::encode( rawurldecode( $kv[0] ) ), self::encode( rawurldecode( $kv[1] ?? '' ) ) );
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				$by_name = strcmp( $a[0], $b[0] );
				return 0 !== $by_name ? $by_name : strcmp( $a[1], $b[1] );
			}
		);
		return implode(
			'&',
			array_map(
				static function ( $p ) {
					return $p[0] . '=' . $p[1];
				},
				$pairs
			)
		);
	}

	/**
	 * Canonical headers: lowercase names, sorted; values trimmed with inner runs of spaces collapsed; repeated
	 * headers joined with commas in the order they appear.
	 *
	 * @param array<int,array{0:string,1:string}> $headers Name/value pairs.
	 * @return array<string,string>
	 */
	private static function canonical_headers( array $headers ): array {
		$out = array();
		foreach ( $headers as $header ) {
			$name         = strtolower( trim( $header[0] ) );
			$value        = trim( (string) preg_replace( '/\s+/', ' ', $header[1] ) );
			$out[ $name ] = isset( $out[ $name ] ) ? $out[ $name ] . ',' . $value : $value;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Percent-encodes everything except RFC 3986 unreserved characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function encode( string $value ): string {
		return rawurlencode( $value );
	}
}
