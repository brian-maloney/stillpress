<?php
/**
 * Decides which URLs the crawler and renderer may request.
 *
 * @package Stillpress
 */

namespace Stillpress\Security;

use Stillpress\Support\Url;

/**
 * Only http(s) URLs on an allowed origin (host + port) pass. Everything else is refused, which is the
 * plugin's main defense against server-side request forgery (threat T1).
 *
 * The class is pure: it never resolves DNS or performs requests.
 */
final class UrlPolicy {

	/**
	 * Allowed authorities, keyed "host:port".
	 *
	 * @var array<string,bool>
	 */
	private $authorities = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $origins Allowed origins such as "https://example.com" or "http://localhost:8888". Paths are ignored.
	 */
	public function __construct( array $origins ) {
		foreach ( $origins as $origin ) {
			$parts = Url::parse( (string) $origin );
			if ( null === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
				continue;
			}
			$scheme = strtolower( $parts['scheme'] );
			$port   = isset( $parts['port'] ) ? (int) $parts['port'] : Url::default_port( $scheme );
			if ( null === $port ) {
				continue;
			}
			$host = self::normalize_host( $parts['host'] );
			if ( null === $host ) {
				continue;
			}
			$this->authorities[ $host . ':' . $port ] = true;
		}
	}

	/**
	 * Returns true when the URL may be requested.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public function allows( string $url ): bool {
		return null !== $this->normalize( $url );
	}

	/**
	 * Resolves, validates and canonicalizes a URL.
	 *
	 * The canonical form has a lowercase scheme and host, no default port, no fragment, no dot segments,
	 * a path of at least "/", and consistent percent-encoding, so equal URLs compare equal as strings.
	 *
	 * @param string      $url  URL, absolute or (with $base) relative.
	 * @param string|null $base Base URL used to resolve relative references.
	 * @return string|null Canonical URL, or null if the URL is not allowed.
	 */
	public function normalize( string $url, ?string $base = null ): ?string {
		$url = trim( $url );
		if ( '' === $url || preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return null;
		}
		// Browsers treat backslashes as slashes in http(s) URLs; parse_url() doesn't. Refuse them outright.
		if ( false !== strpos( $url, '\\' ) ) {
			return null;
		}
		if ( null !== $base ) {
			$resolved = Url::resolve( $base, $url );
			if ( null === $resolved ) {
				return null;
			}
			$url = $resolved;
		}

		$parts = Url::parse( $url );
		if ( null === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$host = self::normalize_host( $parts['host'] );
		if ( null === $host ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : Url::default_port( $scheme );
		if ( ! isset( $this->authorities[ $host . ':' . $port ] ) ) {
			return null;
		}

		$path = Url::remove_dot_segments( self::normalize_encoding( $parts['path'] ?? '' ) );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		$canonical = array(
			'scheme' => $scheme,
			'host'   => $host,
			'path'   => $path,
		);
		if ( Url::default_port( $scheme ) !== $port ) {
			$canonical['port'] = $port;
		}
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$canonical['query'] = self::normalize_encoding( $parts['query'] );
		}
		return Url::build( $canonical );
	}

	/**
	 * Lowercases a host and checks that it is a plausible DNS name or IP literal.
	 *
	 * @param string $host Host from parse_url().
	 * @return string|null
	 */
	private static function normalize_host( string $host ): ?string {
		$host = rtrim( strtolower( $host ), '.' );
		if ( '' === $host ) {
			return null;
		}
		if ( '[' === $host[0] ) {
			return preg_match( '/^\[[0-9a-f:.]+\]$/', $host ) ? $host : null;
		}
		return preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $host ) ? $host : null;
	}

	/**
	 * Normalizes percent-encoding: uppercase hex digits, decode unreserved characters, encode anything that must be.
	 *
	 * @param string $value Path or query.
	 * @return string
	 */
	public static function normalize_encoding( string $value ): string {
		$value = preg_replace_callback(
			'/%([0-9A-Fa-f]{2})/',
			static function ( $m ) {
				$char = chr( (int) hexdec( $m[1] ) );
				if ( preg_match( '/^[A-Za-z0-9\-._~]$/', $char ) ) {
					return $char;
				}
				return '%' . strtoupper( $m[1] );
			},
			$value
		);
		// Encode bytes that are never valid raw in a URL (spaces, non-ASCII, quotes, brackets ...), and lone "%".
		return (string) preg_replace_callback(
			'/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%]|%(?![0-9A-F]{2})/',
			static function ( $m ) {
				return '%' . strtoupper( bin2hex( $m[0] ) );
			},
			(string) $value
		);
	}
}
