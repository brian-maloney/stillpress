<?php
/**
 * Data removal on uninstall.
 *
 * @package Stillpress
 */

namespace Stillpress;

use Stillpress\Security\Capabilities;
use Stillpress\Storage\Schema;
use Stillpress\Support\Paths;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Bulk removal of our own rows.

/**
 * Deletes tables, options, capabilities, scheduled actions and the private build files on every site.
 */
final class Uninstaller {

	/**
	 * Runs for every site.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::site();
				restore_current_blog();
			}
		} else {
			self::site();
		}
	}

	/**
	 * Cleans the current site.
	 *
	 * @return void
	 */
	private static function site(): void {
		global $wpdb;
		Schema::drop();
		Capabilities::revoke_all();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'stillpress_' ) . '%' ) );
		wp_cache_flush();

		// Action Scheduler isn't loaded during uninstall; remove our actions from its tables directly.
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) === $actions ) {
			$wpdb->query(
				$wpdb->prepare( 'DELETE a FROM %i a JOIN %i g ON a.group_id = g.group_id WHERE g.slug = %s', $actions, $groups, 'stillpress' )
			);
		}

		self::delete_tree( Paths::cache_dir() );
		self::delete_tree( Paths::zip_dir() );
	}

	/**
	 * Deletes a private directory tree without following symlinks.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function delete_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::delete_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own private directory.
	}
}
