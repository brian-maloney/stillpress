<?php
/**
 * Plugin wiring.
 *
 * @package Stillpress
 */

namespace Stillpress;

use Stillpress\Security\Capabilities;
use Stillpress\Storage\Schema;

/**
 * Creates the plugin's services and attaches them to WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Capabilities::register();

		add_action( 'init', array( Schema::class, 'maybe_upgrade' ), 1 );
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 20 );
	}

	/**
	 * Activation callback. On network activation, sets up every existing site.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Sets up a site created after network activation.
	 *
	 * @param \WP_Site $site New site.
	 * @return void
	 */
	public static function initialize_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( STILLPRESS_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Per-site activation work.
	 *
	 * @return void
	 */
	private static function activate_site(): void {
		Schema::install();
		Capabilities::grant_defaults();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
	}
}
