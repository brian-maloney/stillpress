<?php
/**
 * The origin site's addresses and the public address they map to.
 *
 * @package Stillpress
 */

namespace Stillpress\Rewrite;

use Stillpress\Support\Url;

/**
 * Describes where the site is rendered from (origin) and where it will be served (public).
 *
 * The origin is one host and port, reachable over http and https. Paths below the origin home path map to the
 * public base path. Paths outside the home path (for example "/wp-includes/" when WordPress lives at the root
 * but home is "/blog/") keep their path on the public host.
 *
 * Pure value object; no WordPress calls.
 */
final class OriginSet {

	/**
	 * Lowercase origin host.
	 *
	 * @var string
	 */
	private $origin_host;

	/**
	 * Explicit origin port, or null for the scheme default.
	 *
	 * @var int|null
	 */
	private $origin_port;

	/**
	 * Origin scheme ("http" or "https").
	 *
	 * @var string
	 */
	private $origin_scheme;

	/**
	 * Origin home path without trailing slash ("" for the root).
	 *
	 * @var string
	 */
	private $origin_path;

	/**
	 * Public scheme.
	 *
	 * @var string
	 */
	private $public_scheme;

	/**
	 * Public host.
	 *
	 * @var string
	 */
	private $public_host;

	/**
	 * Explicit public port, or null.
	 *
	 * @var int|null
	 */
	private $public_port;

	/**
	 * Public base path without trailing slash ("" for the root).
	 *
	 * @var string
	 */
	private $public_path;

	/**
	 * Constructor.
	 *
	 * @param string $origin_home Origin home URL, e.g. home_url().
	 * @param string $public_base Public base URL the static site is served from.
	 * @throws \InvalidArgumentException When either URL is not an absolute http(s) URL.
	 */
	public function __construct( string $origin_home, string $public_base ) {
		list( $this->origin_scheme, $this->origin_host, $this->origin_port, $this->origin_path ) = self::split( $origin_home );
		list( $this->public_scheme, $this->public_host, $this->public_port, $this->public_path ) = self::split( $public_base );
	}

	/**
	 * Splits and normalizes a base URL.
	 *
	 * @param string $url Base URL.
	 * @return array{0:string,1:string,2:int|null,3:string}
	 * @throws \InvalidArgumentException When the URL is not usable.
	 */
	private static function split( string $url ): array {
		$parts = Url::parse( trim( $url ) );
		if ( null === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new \InvalidArgumentException( 'Expected an absolute http(s) URL.' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			throw new \InvalidArgumentException( 'Expected an http or https URL.' );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( Url::default_port( $scheme ) === $port ) {
			$port = null;
		}
		$path = rtrim( Url::remove_dot_segments( $parts['path'] ?? '' ), '/' );
		return array( $scheme, rtrim( strtolower( $parts['host'] ), '.' ), $port, $path );
	}

	/**
	 * Origins (scheme://host[:port]) the policy should accept: both schemes of the origin host.
	 *
	 * When the origin uses a non-default port, only that port is accepted; http and https can't both be on it.
	 *
	 * @return string[]
	 */
	public function origins(): array {
		if ( null !== $this->origin_port ) {
			return array( $this->origin_scheme . '://' . $this->origin_host . ':' . $this->origin_port );
		}
		return array( 'https://' . $this->origin_host, 'http://' . $this->origin_host );
	}

	/**
	 * Origin host.
	 *
	 * @return string
	 */
	public function origin_host(): string {
		return $this->origin_host;
	}

	/**
	 * Origin port, or null for the default.
	 *
	 * @return int|null
	 */
	public function origin_port(): ?int {
		return $this->origin_port;
	}

	/**
	 * Origin home path without trailing slash.
	 *
	 * @return string
	 */
	public function origin_path(): string {
		return $this->origin_path;
	}

	/**
	 * Public base URL without trailing slash.
	 *
	 * @return string
	 */
	public function public_base(): string {
		return $this->public_authority() . $this->public_path;
	}

	/**
	 * Public scheme://host[:port].
	 *
	 * @return string
	 */
	public function public_authority(): string {
		return $this->public_scheme . '://' . $this->public_host . ( null !== $this->public_port ? ':' . $this->public_port : '' );
	}

	/**
	 * Public host[:port] without scheme.
	 *
	 * @return string
	 */
	public function public_host_port(): string {
		return $this->public_host . ( null !== $this->public_port ? ':' . $this->public_port : '' );
	}

	/**
	 * Public base path without trailing slash.
	 *
	 * @return string
	 */
	public function public_path(): string {
		return $this->public_path;
	}

	/**
	 * Public scheme.
	 *
	 * @return string
	 */
	public function public_scheme(): string {
		return $this->public_scheme;
	}

	/**
	 * True when rewriting would change nothing.
	 *
	 * @return bool
	 */
	public function is_identity(): bool {
		return $this->origin_host === $this->public_host
			&& $this->origin_port === $this->public_port
			&& $this->origin_scheme === $this->public_scheme
			&& $this->origin_path === $this->public_path;
	}

	/**
	 * Maps an origin URL path to its public URL path.
	 *
	 * @param string $origin_path Path on the origin, starting with "/".
	 * @return string
	 */
	public function public_path_for( string $origin_path ): string {
		if ( '' === $origin_path ) {
			$origin_path = '/';
		}
		if ( '' === $this->origin_path ) {
			return $this->public_path . $origin_path;
		}
		if ( $origin_path === $this->origin_path ) {
			return $this->public_path . '/';
		}
		if ( 0 === strpos( $origin_path, $this->origin_path . '/' ) ) {
			return $this->public_path . substr( $origin_path, strlen( $this->origin_path ) );
		}
		return $origin_path;
	}

	/**
	 * Maps an origin URL path to a URL path relative to the output root (which is served at the public base path).
	 *
	 * @param string $origin_path Path on the origin.
	 * @return string|null Path starting with "/", or null when it falls outside the public base path.
	 */
	public function output_path_for( string $origin_path ): ?string {
		$public = $this->public_path_for( $origin_path );
		if ( '' === $this->public_path ) {
			return $public;
		}
		if ( $public === $this->public_path ) {
			return '/';
		}
		if ( 0 === strpos( $public, $this->public_path . '/' ) ) {
			return substr( $public, strlen( $this->public_path ) );
		}
		return null;
	}
}
