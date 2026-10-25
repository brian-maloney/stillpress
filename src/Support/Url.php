<?php
/**
 * URL parsing and resolution helpers.
 *
 * @package Stillpress
 */

namespace Stillpress\Support;

/**
 * Pure functions for working with URLs (RFC 3986). No WordPress calls.
 */
final class Url {

	/**
	 * Parses a URL into components, or returns null if it can't be parsed.
	 *
	 * @param string $url URL to parse.
	 * @return array<string,mixed>|null
	 */
	public static function parse( string $url ): ?array {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper; wp_parse_url() is a thin wrapper that adds nothing here.
		if ( false === $parts ) {
			return null;
		}
		return $parts;
	}

	/**
	 * Resolves a reference against a base URL (RFC 3986 section 5.2).
	 *
	 * @param string $base      Absolute base URL.
	 * @param string $reference Possibly relative reference.
	 * @return string|null Absolute URL, or null when either input can't be parsed.
	 */
	public static function resolve( string $base, string $reference ): ?string {
		$reference  = trim( $reference );
		$base_parts = self::parse( $base );
		if ( null === $base_parts || ! isset( $base_parts['scheme'], $base_parts['host'] ) ) {
			return null;
		}

		// A scheme followed by ":" makes it absolute (http:, mailto:, javascript: ...).
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $reference ) ) {
			$ref = self::parse( $reference );
			if ( null === $ref ) {
				return null;
			}
			if ( isset( $ref['path'] ) ) {
				$ref['path'] = self::remove_dot_segments( $ref['path'] );
			}
			return self::build( $ref );
		}

		$ref = self::parse( '' === $reference ? '' : $reference );
		if ( null === $ref ) {
			return null;
		}

		$target = array( 'scheme' => $base_parts['scheme'] );

		if ( 0 === strpos( $reference, '//' ) ) {
			$ref = self::parse( $base_parts['scheme'] . ':' . $reference );
			if ( null === $ref || ! isset( $ref['host'] ) ) {
				return null;
			}
			$ref['path'] = self::remove_dot_segments( $ref['path'] ?? '' );
			return self::build( $ref );
		}

		$target['host'] = $base_parts['host'];
		if ( isset( $base_parts['port'] ) ) {
			$target['port'] = $base_parts['port'];
		}
		if ( isset( $base_parts['user'] ) ) {
			$target['user'] = $base_parts['user'];
		}
		if ( isset( $base_parts['pass'] ) ) {
			$target['pass'] = $base_parts['pass'];
		}

		$ref_path = $ref['path'] ?? '';
		if ( '' === $ref_path ) {
			$target['path'] = $base_parts['path'] ?? '/';
			if ( isset( $ref['query'] ) ) {
				$target['query'] = $ref['query'];
			} elseif ( isset( $base_parts['query'] ) ) {
				$target['query'] = $base_parts['query'];
			}
		} else {
			if ( '/' === $ref_path[0] ) {
				$target['path'] = self::remove_dot_segments( $ref_path );
			} else {
				$base_path = $base_parts['path'] ?? '';
				if ( '' === $base_path ) {
					$merged = '/' . $ref_path;
				} else {
					$merged = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 ) . $ref_path;
				}
				$target['path'] = self::remove_dot_segments( $merged );
			}
			if ( isset( $ref['query'] ) ) {
				$target['query'] = $ref['query'];
			}
		}//end if
		if ( isset( $ref['fragment'] ) ) {
			$target['fragment'] = $ref['fragment'];
		}
		return self::build( $target );
	}

	/**
	 * Removes "." and ".." segments from a path (RFC 3986 section 5.2.4).
	 *
	 * @param string $path Path, possibly containing dot segments.
	 * @return string
	 */
	public static function remove_dot_segments( string $path ): string {
		$input  = $path;
		$output = '';
		while ( '' !== $input ) {
			if ( 0 === strpos( $input, '../' ) ) {
				$input = substr( $input, 3 );
			} elseif ( 0 === strpos( $input, './' ) ) {
				$input = substr( $input, 2 );
			} elseif ( 0 === strpos( $input, '/./' ) ) {
				$input = substr( $input, 2 );
			} elseif ( '/.' === $input ) {
				$input = '/';
			} elseif ( 0 === strpos( $input, '/../' ) ) {
				$input  = substr( $input, 3 );
				$output = self::pop_segment( $output );
			} elseif ( '/..' === $input ) {
				$input  = '/';
				$output = self::pop_segment( $output );
			} elseif ( '.' === $input || '..' === $input ) {
				$input = '';
			} else {
				$start = ( '/' === $input[0] ) ? 1 : 0;
				$next  = strpos( $input, '/', $start );
				if ( false === $next ) {
					$output .= $input;
					$input   = '';
				} else {
					$output .= substr( $input, 0, $next );
					$input   = substr( $input, $next );
				}
			}//end if
		}//end while
		return $output;
	}

	/**
	 * Removes the last segment (and its preceding slash) from a path buffer.
	 *
	 * @param string $output Path buffer.
	 * @return string
	 */
	private static function pop_segment( string $output ): string {
		$pos = strrpos( $output, '/' );
		return false === $pos ? '' : substr( $output, 0, $pos );
	}

	/**
	 * Builds a URL string from parse_url()-style components.
	 *
	 * @param array<string,mixed> $parts Components.
	 * @return string
	 */
	public static function build( array $parts ): string {
		$url = '';
		if ( isset( $parts['scheme'] ) ) {
			$url .= $parts['scheme'] . ':';
		}
		if ( isset( $parts['host'] ) ) {
			$url .= '//';
			if ( isset( $parts['user'] ) ) {
				$url .= $parts['user'];
				if ( isset( $parts['pass'] ) ) {
					$url .= ':' . $parts['pass'];
				}
				$url .= '@';
			}
			$url .= $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$url .= ':' . $parts['port'];
			}
		}
		$url .= $parts['path'] ?? '';
		if ( isset( $parts['query'] ) ) {
			$url .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) ) {
			$url .= '#' . $parts['fragment'];
		}
		return $url;
	}

	/**
	 * Returns the default port for a scheme.
	 *
	 * @param string $scheme Lowercase scheme.
	 * @return int|null
	 */
	public static function default_port( string $scheme ): ?int {
		if ( 'http' === $scheme ) {
			return 80;
		}
		if ( 'https' === $scheme ) {
			return 443;
		}
		return null;
	}
}
