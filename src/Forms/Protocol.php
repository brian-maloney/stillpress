<?php
/**
 * The rules every form receiver applies.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

/**
 * Field names Stillpress adds to published forms, the limits on a submission and the spam checks, as pure functions.
 *
 * The standalone receivers (receivers/stillpress-form.php and receivers/receiver-core.js) can't load the plugin, so
 * they implement the same rules again. All three are tested against tests/fixtures/forms-cases.json.
 *
 * A submission is a list of [name, value] pairs, in the order the browser sent them, so nested names such as
 * `wpforms[fields][1]` survive the trip through any receiver unchanged; WordPress turns them into the array PHP
 * would have built from the same POST body (`to_array()`).
 */
final class Protocol {

	/** Which published form a submission belongs to, such as "cf7:12" or "form:contact". */
	const FIELD_FORM = 'stillpress_form';

	/** Public address of the page the form was on. */
	const FIELD_PAGE = 'stillpress_page';

	/** Honeypot: a field people can't see, which must stay empty. */
	const FIELD_HONEYPOT = 'stillpress_hp';

	/** Seconds the form was open before it was sent; the client script fills it in. */
	const FIELD_ELAPSED = 'stillpress_t';

	/** Fields captcha widgets add to a form. */
	const CAPTCHA_FIELDS = array( 'cf-turnstile-response', 'h-captcha-response', 'g-recaptcha-response' );

	/** Fewest seconds a person needs to fill in a form. */
	const MIN_SECONDS = 3;

	/** Most fields in one submission. */
	const MAX_FIELDS = 100;

	/** Longest field name, in bytes. */
	const MAX_NAME = 200;

	/** Longest field value, in bytes. */
	const MAX_VALUE = 20000;

	/** Largest submission (names and values), in bytes. */
	const MAX_TOTAL = 100000;

	/** Format of a form key. */
	const KEY_PATTERN = '/^[a-z0-9_-]{1,40}(:[A-Za-z0-9_-]{1,40})?$/';

	/**
	 * Why a submission is refused before it is delivered, or null when it passes.
	 *
	 * Reasons: too_many, too_large, bad_form, honeypot, too_fast. Without JavaScript the elapsed time is empty and
	 * the time check is skipped; such submissions still meet the honeypot.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs Submitted fields.
	 * @return string|null
	 */
	public static function refusal( array $pairs ): ?string {
		if ( count( $pairs ) > self::MAX_FIELDS ) {
			return 'too_many';
		}
		$total = 0;
		foreach ( $pairs as $pair ) {
			$name  = (string) $pair[0];
			$value = (string) $pair[1];
			if ( strlen( $name ) > self::MAX_NAME || strlen( $value ) > self::MAX_VALUE ) {
				return 'too_large';
			}
			$total += strlen( $name ) + strlen( $value );
		}
		if ( $total > self::MAX_TOTAL ) {
			return 'too_large';
		}
		if ( ! preg_match( self::KEY_PATTERN, self::value( $pairs, self::FIELD_FORM ) ) ) {
			return 'bad_form';
		}
		if ( '' !== self::value( $pairs, self::FIELD_HONEYPOT ) ) {
			return 'honeypot';
		}
		$elapsed = trim( self::value( $pairs, self::FIELD_ELAPSED ) );
		if ( '' !== $elapsed && ( ! is_numeric( $elapsed ) || (float) $elapsed < self::MIN_SECONDS ) ) {
			return 'too_fast';
		}
		return null;
	}

	/**
	 * The first value of a field, or an empty string.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs Fields.
	 * @param string                              $name  Field name.
	 * @return string
	 */
	public static function value( array $pairs, string $name ): string {
		foreach ( $pairs as $pair ) {
			if ( $pair[0] === $name ) {
				return (string) $pair[1];
			}
		}
		return '';
	}

	/**
	 * The fields without the ones Stillpress and captcha widgets added.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs Fields.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function strip( array $pairs ): array {
		$ours = array_merge( array( self::FIELD_FORM, self::FIELD_PAGE, self::FIELD_HONEYPOT, self::FIELD_ELAPSED ), self::CAPTCHA_FIELDS );
		return array_values(
			array_filter(
				$pairs,
				static function ( $pair ) use ( $ours ) {
					return ! in_array( $pair[0], $ours, true );
				}
			)
		);
	}

	/**
	 * Normalizes untrusted input into [name, value] string pairs; anything else is dropped.
	 *
	 * @param mixed $pairs Decoded JSON or similar.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function pairs( $pairs ): array {
		$out = array();
		if ( ! is_array( $pairs ) ) {
			return $out;
		}
		foreach ( $pairs as $pair ) {
			if ( is_array( $pair ) && 2 === count( $pair ) && isset( $pair[0], $pair[1] ) && is_string( $pair[0] ) && is_string( $pair[1] ) && '' !== $pair[0] ) {
				$out[] = array( $pair[0], $pair[1] );
			}
		}
		return $out;
	}

	/**
	 * The array PHP builds from a POST body with these fields (what `$_POST` would hold): nested names become
	 * nested arrays, and dots and spaces in top-level names become underscores.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs Fields.
	 * @return array<string,mixed>
	 */
	public static function to_array( array $pairs ): array {
		$query = array();
		foreach ( $pairs as $pair ) {
			$query[] = rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
		}
		$out = array();
		parse_str( implode( '&', $query ), $out );
		return $out;
	}

	/**
	 * The pairs a browser would send for an array like `$_POST`.
	 *
	 * @param array<string|int,mixed> $fields Fields.
	 * @param string                  $prefix Name prefix (for recursion).
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function from_array( array $fields, string $prefix = '' ): array {
		$out = array();
		foreach ( $fields as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::from_array( $value, $name ) );
			} elseif ( is_scalar( $value ) ) {
				$out[] = array( $name, (string) $value );
			}
		}
		return $out;
	}
}
