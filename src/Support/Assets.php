<?php
/**
 * Version strings for the plugin's scripts and stylesheets.
 *
 * @package Stillpress
 */

namespace Stillpress\Support;

/**
 * Gives each asset the plugin version as its cache-busting `ver`. Development builds (a version ending in "-dev")
 * keep that version while their files change, so browsers would serve stale scripts after an update from git; there
 * the file's modification time is appended. Release builds use the plain version and never touch the filesystem.
 */
final class Assets {

	/**
	 * URL of a file in the plugin.
	 *
	 * @param string $relative Path relative to the plugin folder, for example "assets/js/admin.js".
	 * @return string
	 */
	public static function url( string $relative ): string {
		return plugins_url( $relative, STILLPRESS_FILE );
	}

	/**
	 * Cache-busting version of a file in the plugin.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	public static function version( string $relative ): string {
		$mtime = null;
		if ( self::is_dev( STILLPRESS_VERSION ) ) {
			$file  = dirname( STILLPRESS_FILE ) . '/' . ltrim( $relative, '/' );
			$time  = is_file( $file ) ? filemtime( $file ) : false;
			$mtime = false === $time ? null : $time;
		}
		return self::compose( STILLPRESS_VERSION, $mtime );
	}

	/**
	 * Builds the version string.
	 *
	 * @param string   $version Plugin version.
	 * @param int|null $mtime   File modification time, or null when unknown.
	 * @return string
	 */
	public static function compose( string $version, ?int $mtime ): string {
		return self::is_dev( $version ) && null !== $mtime ? $version . '-' . $mtime : $version;
	}

	/**
	 * True for development versions.
	 *
	 * @param string $version Version.
	 * @return bool
	 */
	private static function is_dev( string $version ): bool {
		return '-dev' === substr( $version, -4 );
	}
}
