<?php
/**
 * Decides which directories may be used as a deploy target.
 *
 * @package Stillpress
 */

namespace Stillpress\Security;

/**
 * Guards against destructive deploy targets (threat T5).
 *
 * A target is refused when it is the filesystem root, when it equals or contains a protected directory (WordPress
 * itself, wp-content, uploads, plugins, themes), or when it sits inside code directories (plugins, themes, wp-admin,
 * wp-includes). Directories inside wp-content (other than code) or outside WordPress entirely are allowed.
 *
 * Pure: the caller passes the protected and code directories, so the rules can be unit tested.
 */
final class TargetPolicy {

	/** Marker file that must exist in a non-empty target before we write to it. */
	const MARKER = '.stillpress-target';

	/**
	 * Directories that must not be a target or be inside a target.
	 *
	 * @var string[]
	 */
	private $protected;

	/**
	 * Directories a target must not be inside.
	 *
	 * @var string[]
	 */
	private $code_dirs;

	/**
	 * Constructor.
	 *
	 * @param string[] $protected_dirs Directories that must not equal or be inside the target.
	 * @param string[] $code_dirs      Directories the target must not be inside.
	 */
	public function __construct( array $protected_dirs, array $code_dirs ) {
		$this->protected = array_values( array_filter( array_map( array( self::class, 'normalize' ), $protected_dirs ) ) );
		$this->code_dirs = array_values( array_filter( array_map( array( self::class, 'normalize' ), $code_dirs ) ) );
	}

	/**
	 * Builds the policy for the running WordPress install.
	 *
	 * @return TargetPolicy
	 */
	public static function for_wordpress(): TargetPolicy {
		$uploads   = wp_upload_dir( null, false );
		$protected = array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), $uploads['basedir'] );
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$protected[] = WPMU_PLUGIN_DIR;
		}
		$code = array( WP_PLUGIN_DIR, get_theme_root(), ABSPATH . 'wp-admin', ABSPATH . WPINC );
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$code[] = WPMU_PLUGIN_DIR;
		}
		foreach ( (array) $GLOBALS['wp_theme_directories'] as $theme_dir ) {
			$protected[] = $theme_dir;
			$code[]      = $theme_dir;
		}
		return new self( $protected, $code );
	}

	/**
	 * Returns null when the directory is acceptable, or a human-readable reason when it isn't.
	 *
	 * @param string $dir Absolute target directory.
	 * @return string|null
	 */
	public function problem( string $dir ): ?string {
		$raw = trim( $dir );
		if ( '' === $raw ) {
			return __( 'Enter a directory.', 'stillpress' );
		}
		$normalized = str_replace( '\\', '/', $raw );
		if ( '/' !== $normalized[0] && ! preg_match( '#^[A-Za-z]:/#', $normalized ) ) {
			return __( 'Use an absolute path.', 'stillpress' );
		}
		if ( preg_match( '#(^|/)\.\.?(/|$)#', $normalized ) || false !== strpos( $normalized, "\0" ) ) {
			return __( 'The path must not contain "." or ".." segments.', 'stillpress' );
		}

		$target = self::normalize( $raw );
		if ( '' === $target || '/' === $target || preg_match( '#^[A-Za-z]:/?$#', $target ) ) {
			return __( 'The filesystem root can\'t be a deploy target.', 'stillpress' );
		}

		foreach ( $this->protected as $p ) {
			if ( self::is_within( $p, $target ) ) {
				/* translators: %s: directory path. */
				return sprintf( __( 'The target would contain %s, which must never be overwritten.', 'stillpress' ), $p );
			}
		}
		foreach ( $this->code_dirs as $c ) {
			if ( self::is_within( $target, $c ) ) {
				/* translators: %s: directory path. */
				return sprintf( __( 'The target is inside %s, which holds code.', 'stillpress' ), $c );
			}
		}
		return null;
	}

	/**
	 * Normalizes a path, resolving symlinks for the longest existing prefix.
	 *
	 * @param string $path Path.
	 * @return string Without trailing slash.
	 */
	public static function normalize( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path ) {
			return '';
		}
		$suffix   = '';
		$existing = $path;
		while ( '' !== $existing && ! file_exists( $existing ) ) {
			$parent = dirname( $existing );
			if ( $parent === $existing ) {
				break;
			}
			$suffix   = '/' . basename( $existing ) . $suffix;
			$existing = $parent;
		}
		$real = file_exists( $existing ) ? realpath( $existing ) : false;
		$base = false === $real ? $existing : str_replace( '\\', '/', $real );
		$out  = rtrim( $base, '/' ) . $suffix;
		return '' === $out ? '/' : $out;
	}

	/**
	 * True when $path equals $dir or is below it.
	 *
	 * @param string $path Normalized path.
	 * @param string $dir  Normalized directory.
	 * @return bool
	 */
	private static function is_within( string $path, string $dir ): bool {
		$path = rtrim( $path, '/' );
		$dir  = rtrim( $dir, '/' );
		if ( '' === $dir ) {
			return true;
		}
		return $path === $dir || 0 === strpos( $path . '/', $dir . '/' );
	}
}
