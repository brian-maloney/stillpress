<?php
/**
 * Plugin settings.
 *
 * @package Stillpress
 */

namespace Stillpress\Support;

/**
 * Reads, sanitizes and stores the settings array.
 *
 * Some settings can be pinned with constants in wp-config.php; a pinned value always wins and is shown read-only.
 *
 * | Setting       | Constant               |
 * |---------------|------------------------|
 * | public_url    | STILLPRESS_PUBLIC_URL  |
 * | local_path    | STILLPRESS_LOCAL_PATH  |
 * | connect_to    | STILLPRESS_CONNECT_TO  |
 */
final class Settings {

	/** Option name. */
	const OPTION = 'stillpress_settings';

	/**
	 * Setting key => constant name, for settings that can be pinned in wp-config.php.
	 *
	 * @var array<string,string>
	 */
	const CONSTANTS = array(
		'public_url' => 'STILLPRESS_PUBLIC_URL',
		'local_path' => 'STILLPRESS_LOCAL_PATH',
		'connect_to' => 'STILLPRESS_CONNECT_TO',
	);

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'public_url'                      => '',
			// Empty means "same as the site address".
							'target'          => 'local',
			// Deployer ID.
							'local_path'      => '',
			// Empty means the default under the data directory.
							'additional_urls' => array(),
			'exclude'                         => array(),
			'redirects'                       => array(),
			'auto_publish'                    => true,
			'full_rebuild'                    => 'daily',
			'concurrency'                     => 4,
			'connect_to'                      => '',
			'feeds'                           => 'main',
			'max_urls'                        => 50000,
		);
	}

	/**
	 * Returns all settings, with constants applied.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$settings[ $key ] = (string) constant( $constant );
			}
		}

		/**
		 * Filters the effective settings.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $settings Settings after defaults and wp-config.php constants are applied.
		 */
		return apply_filters( 'stillpress_settings', $settings );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Returns true when a setting is pinned by a constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_pinned( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Merges and stores new values after sanitizing them.
	 *
	 * @param array<string,mixed> $values New values (unknown keys are dropped).
	 * @return array<string,mixed> The stored settings.
	 */
	public static function update( array $values ): array {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = self::sanitize( array_merge( self::defaults(), $current, $values ) );
		update_option( self::OPTION, $merged );
		return $merged;
	}

	/**
	 * Sanitizes a settings array. Also the Settings API sanitize callback.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$out['public_url'] = '';
		if ( isset( $input['public_url'] ) && '' !== trim( (string) $input['public_url'] ) ) {
			$url = esc_url_raw( trim( (string) $input['public_url'] ), array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$out['public_url'] = untrailingslashit( $url );
			}
		}

		$target        = isset( $input['target'] ) ? sanitize_key( (string) $input['target'] ) : $defaults['target'];
		$out['target'] = '' === $target ? $defaults['target'] : $target;

		$out['local_path'] = isset( $input['local_path'] ) ? self::sanitize_path( (string) $input['local_path'] ) : '';

		$out['additional_urls'] = self::sanitize_lines( $input['additional_urls'] ?? array(), 500 );
		$out['exclude']         = self::sanitize_lines( $input['exclude'] ?? array(), 500 );
		$out['redirects']       = self::sanitize_redirects( $input['redirects'] ?? array() );

		$out['auto_publish'] = ! empty( $input['auto_publish'] );

		$schedule            = isset( $input['full_rebuild'] ) ? (string) $input['full_rebuild'] : $defaults['full_rebuild'];
		$out['full_rebuild'] = in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ? $schedule : $defaults['full_rebuild'];

		$out['concurrency'] = isset( $input['concurrency'] ) ? max( 1, min( 16, (int) $input['concurrency'] ) ) : $defaults['concurrency'];

		$connect           = isset( $input['connect_to'] ) ? trim( (string) $input['connect_to'] ) : '';
		$out['connect_to'] = preg_match( '/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$|^\[[0-9a-fA-F:.]+\](:[0-9]{1,5})?$/', $connect ) ? $connect : '';

		$feeds        = isset( $input['feeds'] ) ? (string) $input['feeds'] : $defaults['feeds'];
		$out['feeds'] = in_array( $feeds, array( 'none', 'main', 'all' ), true ) ? $feeds : $defaults['feeds'];

		$out['max_urls'] = isset( $input['max_urls'] ) ? max( 1, min( 1000000, (int) $input['max_urls'] ) ) : $defaults['max_urls'];

		return $out;
	}

	/**
	 * Splits text or an array into trimmed, unique, non-empty lines without control characters.
	 *
	 * @param mixed $value Text with one entry per line, or an array of strings.
	 * @param int   $limit Maximum number of entries.
	 * @return string[]
	 */
	public static function sanitize_lines( $value, int $limit ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$lines = array();
		foreach ( $value as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $line ) );
			if ( '' !== $line && strlen( $line ) <= 2048 ) {
				$lines[] = $line;
			}
		}
		return array_slice( array_values( array_unique( $lines ) ), 0, $limit );
	}

	/**
	 * Parses redirect lines of the form "/from /to [301|302|307|308]".
	 *
	 * @param mixed $value Lines.
	 * @return array<int,array{from:string,to:string,code:int}>
	 */
	public static function sanitize_redirects( $value ): array {
		if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
			$value = array_map(
				static function ( $r ) {
					return ( $r['from'] ?? '' ) . ' ' . ( $r['to'] ?? '' ) . ' ' . ( $r['code'] ?? 301 );
				},
				$value
			);
		}
		$out = array();
		foreach ( self::sanitize_lines( $value, 1000 ) as $line ) {
			$parts = preg_split( '/\s+/', $line );
			if ( count( $parts ) < 2 || '/' !== substr( $parts[0], 0, 1 ) ) {
				continue;
			}
			$to = $parts[1];
			if ( '/' !== substr( $to, 0, 1 ) && ! preg_match( '#^https?://#i', $to ) ) {
				continue;
			}
			$code  = isset( $parts[2] ) ? (int) $parts[2] : 301;
			$out[] = array(
				'from' => $parts[0],
				'to'   => esc_url_raw( $to ) ? $to : '',
				'code' => in_array( $code, array( 301, 302, 307, 308 ), true ) ? $code : 301,
			);
		}
		return array_values(
			array_filter(
				$out,
				static function ( $r ) {
					return '' !== $r['to'];
				}
			)
		);
	}

	/**
	 * Normalizes a filesystem path typed by an admin. Validation (where it may point) is TargetPolicy's job.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private static function sanitize_path( string $path ): string {
		$path = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $path ) );
		if ( '' === $path ) {
			return '';
		}
		return untrailingslashit( wp_normalize_path( $path ) );
	}
}
