<?php
/**
 * Filesystem locations used by the plugin.
 *
 * @package Stillpress
 */

namespace Stillpress\Support;

/**
 * Resolves the data directory (build cache, ZIP archives, default local target).
 *
 * The data directory defaults to wp-content/stillpress and can be moved with the STILLPRESS_DATA_DIR constant.
 * Every site in a network gets its own subdirectories, named after the blog ID.
 */
final class Paths {

	/**
	 * Base data directory.
	 *
	 * @return string
	 */
	public static function data_dir(): string {
		$dir = defined( 'STILLPRESS_DATA_DIR' ) ? (string) STILLPRESS_DATA_DIR : WP_CONTENT_DIR . '/stillpress';

		/**
		 * Filters the base data directory.
		 *
		 * @since 0.1.0
		 *
		 * @param string $dir Absolute path.
		 */
		return untrailingslashit( wp_normalize_path( (string) apply_filters( 'stillpress_data_dir', $dir ) ) );
	}

	/**
	 * Build cache for the current site: the latest rendered copy of every file.
	 *
	 * @return string
	 */
	public static function cache_dir(): string {
		return self::data_dir() . '/cache/' . get_current_blog_id();
	}

	/**
	 * Directory for ZIP archives of the current site.
	 *
	 * @return string
	 */
	public static function zip_dir(): string {
		return self::data_dir() . '/zips/' . get_current_blog_id();
	}

	/**
	 * Default local deploy target for the current site.
	 *
	 * @return string
	 */
	public static function default_local_target(): string {
		return self::data_dir() . '/public/' . get_current_blog_id();
	}

	/**
	 * Creates a private directory (cache, ZIPs) and blocks direct web access to it where the server allows.
	 *
	 * @param string $dir Absolute path.
	 * @return bool True when the directory exists and is writable.
	 */
	public static function ensure_private_dir( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// The data directory itself stays open, so a local target under it can be served if the admin wants.
		self::protect( dirname( $dir ) );
		self::protect( $dir );
		return wp_is_writable( $dir );
	}

	/**
	 * Drops index.php and .htaccess deny files into a directory if they are missing.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function protect( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny guard file in our own directory.
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny guard file in our own directory.
		}
	}
}
