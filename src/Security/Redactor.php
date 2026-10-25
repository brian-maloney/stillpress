<?php
/**
 * Removes secrets from text before it is logged or displayed.
 *
 * @package Stillpress
 */

namespace Stillpress\Security;

/**
 * Keeps a process-wide list of secret values and scrubs them from any string (threat T10).
 *
 * Secrets are registered by the secrets resolver when they are read, so anything that later echoes them
 * (an exception message from an SDK, a URL with credentials) is scrubbed on the way into the log.
 */
final class Redactor {

	/**
	 * Registered secret values.
	 *
	 * @var array<string,bool>
	 */
	private static $secrets = array();

	/**
	 * Registers a secret value. Values shorter than 4 characters are ignored to avoid scrubbing common text.
	 *
	 * @param string $value Secret.
	 * @return void
	 */
	public static function register( string $value ): void {
		if ( strlen( $value ) >= 4 ) {
			self::$secrets[ $value ] = true;
		}
	}

	/**
	 * Scrubs registered secrets and common credential shapes from a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function scrub( string $text ): string {
		if ( self::$secrets ) {
			// Longest first, so a secret that contains another is replaced whole.
			$values = array_keys( self::$secrets );
			usort(
				$values,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$text = str_replace( $values, '[redacted]', $text );
		}
		// Credentials embedded in URLs: scheme://user:pass@host.
		$text = (string) preg_replace( '#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $text );
		// Authorization headers and bearer tokens.
		$text = (string) preg_replace( '#(authorization:\s*)(bearer|basic|token)?\s*[^\s,;]+#i', '$1[redacted]', $text );
		return $text;
	}

	/**
	 * Forgets all registered secrets (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$secrets = array();
	}
}
