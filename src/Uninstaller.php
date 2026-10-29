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
		// Form rate-limit counters and used relay signatures.
		foreach ( array( '_transient_stillpress_', '_transient_timeout_stillpress_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}
		wp_cache_flush();

		// Action Scheduler isn't loaded during uninstall; remove our actions from its tables directly.
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) === $actions ) {
			$wpdb->query(
				$wpdb->prepare( 'DELETE a FROM %i a JOIN %i g ON a.group_id = g.group_id WHERE g.slug = %s', $actions, $groups, 'stillpress' )
			);
		}

		Paths::delete_tree( Paths::cache_dir() );
		Paths::delete_tree( Paths::zip_dir() );
	}
}
