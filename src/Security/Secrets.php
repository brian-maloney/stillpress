<?php
/**
 * Deployer credentials.
 *
 * @package Stillpress
 */

namespace Stillpress\Security;

/**
 * Resolves deployer secrets (threat T10).
 *
 * Lookup order for deployer `s3`, field `secret_key`:
 *
 * 1. the constant `STILLPRESS_S3_SECRET_KEY` (wp-config.php);
 * 2. the environment variable of the same name;
 * 3. the `stillpress_secrets` option (autoload off), sealed with SecretBox under a key derived from the wp-config
 *    salts. Rotating the salts makes stored secrets unreadable; source() then reports `unreadable`.
 *
 * Every value this class returns is registered with the Redactor first, so it is scrubbed from log lines.
 * Nothing here ever outputs a value: callers that list secrets show only where each one comes from.
 */
final class Secrets {

	/** Option holding sealed values: deployer => field => sealed string. */
	const OPTION = 'stillpress_secrets';

	/** The value comes from a wp-config.php constant. */
	const SOURCE_CONSTANT = 'constant';

	/** The value comes from an environment variable. */
	const SOURCE_ENVIRONMENT = 'environment';

	/** The value is stored, encrypted, in the database. */
	const SOURCE_STORED = 'stored';

	/** A stored value exists but no longer decrypts (the salts changed). */
	const SOURCE_UNREADABLE = 'unreadable';

	/** No value. */
	const SOURCE_NONE = '';

	/**
	 * Constant and environment variable name for a secret.
	 *
	 * @param string $deployer Deployer ID (lowercase letters, digits, dashes).
	 * @param string $field    Field name (lowercase letters, digits, underscores).
	 * @return string For example `STILLPRESS_S3_SECRET_KEY`.
	 */
	public static function name( string $deployer, string $field ): string {
		self::check( $deployer, $field );
		return 'STILLPRESS_' . strtoupper( str_replace( '-', '_', $deployer ) ) . '_' . strtoupper( $field );
	}

	/**
	 * Returns a secret.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @return string|null Null when unset or unreadable.
	 */
	public static function get( string $deployer, string $field ): ?string {
		$name  = self::name( $deployer, $field );
		$value = self::constant( $name );
		if ( null === $value ) {
			$value = self::environment( $name );
		}
		if ( null === $value ) {
			$value = self::open( $deployer, $field );
		}
		if ( null === $value || '' === $value ) {
			return null;
		}
		Redactor::register( $value );
		return $value;
	}

	/**
	 * Where a secret comes from, without revealing it.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @return string One of the SOURCE_* constants.
	 */
	public static function source( string $deployer, string $field ): string {
		$name = self::name( $deployer, $field );
		if ( null !== self::constant( $name ) ) {
			return self::SOURCE_CONSTANT;
		}
		if ( null !== self::environment( $name ) ) {
			return self::SOURCE_ENVIRONMENT;
		}
		$stored = self::stored();
		if ( ! isset( $stored[ $deployer ][ $field ] ) ) {
			return self::SOURCE_NONE;
		}
		return null === self::open( $deployer, $field ) ? self::SOURCE_UNREADABLE : self::SOURCE_STORED;
	}

	/**
	 * Stores a secret, encrypted.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @param string $value    Secret value; an empty string deletes it.
	 * @return void
	 * @throws \LogicException When a constant pins the secret, so a stored value would never be used.
	 */
	public static function set( string $deployer, string $field, string $value ): void {
		$name = self::name( $deployer, $field );
		if ( null !== self::constant( $name ) ) {
			/* translators: %s: constant name. */
			throw new \LogicException( sprintf( __( 'This secret is set in wp-config.php (%s).', 'stillpress' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( '' === $value ) {
			self::delete( $deployer, $field );
			return;
		}
		Redactor::register( $value );
		$stored                        = self::stored();
		$stored[ $deployer ][ $field ] = self::box()->seal( $value, self::context( $deployer, $field ) );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Deletes a stored secret. Constants and environment variables are left alone.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @return void
	 */
	public static function delete( string $deployer, string $field ): void {
		self::check( $deployer, $field );
		$stored = self::stored();
		if ( ! isset( $stored[ $deployer ][ $field ] ) ) {
			return;
		}
		unset( $stored[ $deployer ][ $field ] );
		if ( ! $stored[ $deployer ] ) {
			unset( $stored[ $deployer ] );
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Every stored secret slot, readable or not.
	 *
	 * @return array<int,array{deployer:string,field:string}>
	 */
	public static function stored_slots(): array {
		$slots = array();
		foreach ( self::stored() as $deployer => $fields ) {
			foreach ( array_keys( $fields ) as $field ) {
				$slots[] = array(
					'deployer' => (string) $deployer,
					'field'    => (string) $field,
				);
			}
		}
		return $slots;
	}

	/**
	 * A non-empty constant's value.
	 *
	 * @param string $name Constant name.
	 * @return string|null
	 */
	private static function constant( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}
		$value = (string) constant( $name );
		return '' === $value ? null : $value;
	}

	/**
	 * A non-empty environment variable's value.
	 *
	 * @param string $name Variable name.
	 * @return string|null
	 */
	private static function environment( string $name ): ?string {
		$value = getenv( $name );
		return false === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * Decrypts a stored secret.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @return string|null
	 */
	private static function open( string $deployer, string $field ): ?string {
		$stored = self::stored();
		if ( ! isset( $stored[ $deployer ][ $field ] ) ) {
			return null;
		}
		return self::box()->open( $stored[ $deployer ][ $field ], self::context( $deployer, $field ) );
	}

	/**
	 * The stored option, with anything malformed dropped.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $deployer => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field => $sealed ) {
				if ( is_string( $sealed ) ) {
					$out[ (string) $deployer ][ (string) $field ] = $sealed;
				}
			}
		}
		return $out;
	}

	/**
	 * The box keyed by this install's salts.
	 *
	 * @return SecretBox
	 */
	private static function box(): SecretBox {
		return new SecretBox( wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Key-derivation context: binds a sealed value to its site and slot.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @return string
	 */
	private static function context( string $deployer, string $field ): string {
		return get_current_blog_id() . '|' . $deployer . '|' . $field;
	}

	/**
	 * Validates a slot name.
	 *
	 * @param string $deployer Deployer ID.
	 * @param string $field    Field name.
	 * @return void
	 * @throws \InvalidArgumentException When either part has characters outside the allowed set.
	 */
	private static function check( string $deployer, string $field ): void {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{0,39}$/', $deployer ) || ! preg_match( '/^[a-z0-9][a-z0-9_]{0,39}$/', $field ) ) {
			throw new \InvalidArgumentException( 'Secret names use lowercase letters, digits, dashes (deployer) and underscores (field).' );
		}
	}
}
