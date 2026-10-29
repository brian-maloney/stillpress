<?php
/**
 * Plugin Name:       Stillpress
 * Plugin URI:        https://github.com/brian-maloney/stillpress
 * Description:       Publish your WordPress site as fast, secure static files. Every feature is free.
 * Version:           0.3.0-dev
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Stillpress contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stillpress
 * Network:           false
 *
 * @package Stillpress
 */

defined( 'ABSPATH' ) || exit;

define( 'STILLPRESS_VERSION', '0.3.0-dev' );
define( 'STILLPRESS_FILE', __FILE__ );
define( 'STILLPRESS_DIR', __DIR__ );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Stillpress is missing its dependencies. Run "composer install" in the plugin directory, or install a release build.', 'stillpress' );
			echo '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Action Scheduler picks the newest bundled copy across all plugins, so loading it here is safe.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, array( \Stillpress\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Stillpress\Plugin::class, 'deactivate' ) );

\Stillpress\Plugin::instance()->boot();
