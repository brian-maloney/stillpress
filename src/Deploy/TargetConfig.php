<?php
/**
 * Settings of individual deploy targets.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;

/**
 * Stores, validates and resolves the fields declared by ConfigurableDeployerInterface::fields().
 *
 * Lookup order for every field: `STILLPRESS_<DEPLOYER>_<FIELD>` constant → environment variable → stored value →
 * default. Secret fields are stored by Secrets (encrypted, separate option); everything else lives in the
 * `targets` entry of the settings array, keyed by deployer ID.
 */
final class TargetConfig {

	/** The value is the field's default. */
	const SOURCE_DEFAULT = 'default';

	/**
	 * Field definitions of a deployer, with defaults filled in.
	 *
	 * @param DeployerInterface $deployer Deployer.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields( DeployerInterface $deployer ): array {
		if ( ! $deployer instanceof ConfigurableDeployerInterface ) {
			return array();
		}
		$out = array();
		foreach ( $deployer->fields() as $name => $field ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_]{0,39}$/', (string) $name ) ) {
				continue;
			}
			$out[ (string) $name ] = array_merge(
				array(
					'label'       => (string) $name,
					'type'        => 'text',
					'required'    => false,
					'default'     => '',
					'pattern'     => '',
					'options'     => array(),
					'description' => '',
					'restricted'  => false,
					'multiline'   => false,
				),
				$field
			);
		}
		return $out;
	}

	/**
	 * Resolved value of a field.
	 *
	 * @param DeployerInterface $deployer Deployer.
	 * @param string            $field    Field name.
	 * @return string|bool Strings for text, select and secret fields ('' when unset); booleans for bool fields.
	 */
	public static function value( DeployerInterface $deployer, string $field ) {
		$def = self::fields( $deployer )[ $field ] ?? null;
		if ( null === $def ) {
			return '';
		}
		$id = $deployer->id();
		if ( 'secret' === $def['type'] ) {
			return (string) Secrets::get( $id, $field );
		}
		list( $source, $value ) = Secrets::pinned( $id, $field );
		if ( Secrets::SOURCE_NONE === $source ) {
			$stored = self::stored()[ $id ] ?? array();
			$value  = array_key_exists( $field, $stored ) ? $stored[ $field ] : $def['default'];
		}
		if ( 'bool' === $def['type'] ) {
			return is_bool( $value ) ? $value : in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (string) $value;
	}

	/**
	 * Where a field's value comes from.
	 *
	 * @param DeployerInterface $deployer Deployer.
	 * @param string            $field    Field name.
	 * @return string A Secrets::SOURCE_* constant, or SOURCE_DEFAULT.
	 */
	public static function source( DeployerInterface $deployer, string $field ): string {
		$def = self::fields( $deployer )[ $field ] ?? null;
		if ( null === $def ) {
			return Secrets::SOURCE_NONE;
		}
		if ( 'secret' === $def['type'] ) {
			return Secrets::source( $deployer->id(), $field );
		}
		list( $source ) = Secrets::pinned( $deployer->id(), $field );
		if ( Secrets::SOURCE_NONE !== $source ) {
			return $source;
		}
		return array_key_exists( $field, self::stored()[ $deployer->id() ] ?? array() ) ? Secrets::SOURCE_STORED : self::SOURCE_DEFAULT;
	}

	/**
	 * Validates and stores a field.
	 *
	 * @param DeployerInterface $deployer Deployer.
	 * @param string            $field    Field name.
	 * @param mixed             $raw      New value; '' clears a stored value (the default applies again).
	 * @return void
	 * @throws \InvalidArgumentException When the field is unknown, the value is invalid, or the caller may not change it.
	 * @throws \LogicException           When a constant pins the field.
	 */
	public static function set( DeployerInterface $deployer, string $field, $raw ): void {
		$def = self::fields( $deployer )[ $field ] ?? null;
		if ( null === $def ) {
			/* translators: 1: field name, 2: deployer label. */
			throw new \InvalidArgumentException( sprintf( __( '%1$s has no setting named "%2$s".', 'stillpress' ), $deployer->label(), $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( $def['restricted'] && ! self::can_edit_restricted() ) {
			/* translators: %s: field label. */
			throw new \InvalidArgumentException( sprintf( __( 'Only a network administrator can change %s.', 'stillpress' ), $def['label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$id = $deployer->id();
		if ( 'secret' === $def['type'] ) {
			Secrets::set( $id, $field, is_scalar( $raw ) ? (string) $raw : '' );
			return;
		}
		list( $source ) = Secrets::pinned( $id, $field );
		if ( Secrets::SOURCE_CONSTANT === $source ) {
			/* translators: %s: constant name. */
			throw new \LogicException( sprintf( __( 'This setting is set in wp-config.php (%s).', 'stillpress' ), Secrets::name( $id, $field ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$targets = self::stored();
		if ( '' === $raw || null === $raw ) {
			unset( $targets[ $id ][ $field ] );
		} else {
			$clean = self::clean( $def, $raw );
			if ( null === $clean ) {
				/* translators: %s: field label. */
				throw new \InvalidArgumentException( sprintf( __( 'That is not a valid value for %s.', 'stillpress' ), $def['label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			$targets[ $id ][ $field ] = $clean;
		}
		Settings::update( array( 'targets' => $targets ) );
	}

	/**
	 * Labels of required fields that have no value.
	 *
	 * @param DeployerInterface $deployer Deployer.
	 * @return string[]
	 */
	public static function missing( DeployerInterface $deployer ): array {
		$missing = array();
		foreach ( self::fields( $deployer ) as $name => $def ) {
			if ( $def['required'] && '' === self::value( $deployer, $name ) ) {
				$missing[] = (string) $def['label'];
			}
		}
		return $missing;
	}

	/**
	 * Names of restricted fields per deployer, for the settings form's privilege rule.
	 *
	 * @return array<string,string[]>
	 */
	public static function restricted_fields(): array {
		$out = array();
		foreach ( Registry::instance()->all() as $id => $deployer ) {
			foreach ( self::fields( $deployer ) as $name => $def ) {
				if ( $def['restricted'] ) {
					$out[ $id ][] = $name;
				}
			}
		}
		return $out;
	}

	/**
	 * Sanitizes the `targets` settings entry: fields of registered deployers are validated against their
	 * definitions (secret fields are never kept here); entries of unknown deployers keep only plain scalars, so
	 * settings survive a deployer's plugin being switched off for a while.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<string,array<string,string|bool>>
	 */
	public static function sanitize( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$registry = Registry::instance();
		foreach ( $raw as $id => $values ) {
			$id = (string) $id;
			if ( ! is_array( $values ) || ! preg_match( '/^[a-z0-9][a-z0-9\-]{0,39}$/', $id ) ) {
				continue;
			}
			$deployer = $registry->get( $id );
			$fields   = null === $deployer ? null : self::fields( $deployer );
			foreach ( $values as $name => $value ) {
				$name = (string) $name;
				if ( null === $fields ) {
					if ( preg_match( '/^[a-z0-9][a-z0-9_]{0,39}$/', $name ) && ( is_bool( $value ) || ( is_string( $value ) && strlen( $value ) <= 2048 ) ) ) {
						$out[ $id ][ $name ] = $value;
					}
					continue;
				}
				// Secrets never live here; an empty value means "use the default", so it isn't stored either.
				if ( ! isset( $fields[ $name ] ) || 'secret' === $fields[ $name ]['type'] || '' === $value || null === $value ) {
					continue;
				}
				$clean = self::clean( $fields[ $name ], $value );
				if ( null !== $clean ) {
					$out[ $id ][ $name ] = $clean;
				}
			}
		}//end foreach
		return $out;
	}

	/**
	 * True when the current user may change restricted fields (anyone with the manage capability on a single
	 * site; only super admins on multisite; always from WP-CLI, which is trusted at the shell level).
	 *
	 * @return bool
	 */
	public static function can_edit_restricted(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return \Stillpress\Admin\SettingsPage::can_edit_paths();
	}

	/**
	 * Validates one non-secret value.
	 *
	 * @param array<string,mixed> $def   Field definition (from fields()).
	 * @param mixed               $value Raw value.
	 * @return string|bool|null Clean value, or null when invalid.
	 */
	public static function clean( array $def, $value ) {
		if ( 'bool' === $def['type'] ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			return is_scalar( $value ) ? in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true ) : null;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) );
		if ( strlen( $value ) > 2048 ) {
			return null;
		}
		if ( 'select' === $def['type'] ) {
			return array_key_exists( $value, (array) $def['options'] ) ? $value : null;
		}
		if ( '' !== $def['pattern'] && '' !== $value && ! preg_match( '~^(?:' . $def['pattern'] . ')$~D', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * The stored `targets` entry.
	 *
	 * @return array<string,array<string,string|bool>>
	 */
	private static function stored(): array {
		$stored = get_option( Settings::OPTION, array() );
		return is_array( $stored ) && isset( $stored['targets'] ) && is_array( $stored['targets'] ) ? $stored['targets'] : array();
	}
}
