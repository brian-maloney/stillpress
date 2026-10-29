<?php
/**
 * Integration test bootstrap: loads the WordPress test suite with the plugin active.
 *
 * @package Stillpress
 */

$stillpress_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $stillpress_tests_dir ) {
	$stillpress_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $stillpress_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test suite in {$stillpress_tests_dir}. Run the tests through wp-env (npm run test:integration).\n" );
	exit( 1 );
}

// wp-env's test config shares the tests site's database and, by default, its "wp_" table prefix, so installing the
// test suite would wipe the site that system tests run against. Use a separate prefix.
putenv( 'WORDPRESS_TABLE_PREFIX=wptests_' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Read by wp-env's wp-tests-config.php.

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Defined by the WP test suite.

require_once $stillpress_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/stillpress.php';
		// The form-plugins group (npm run test:integration:forms) runs against Contact Form 7 and WPForms Lite,
		// installed with npm run forms:plugins. They load only for that run, so the rest of the suite stays plain.
		if ( getenv( 'STILLPRESS_FORM_PLUGINS' ) ) {
			foreach ( array( 'contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php' ) as $stillpress_plugin ) {
				if ( file_exists( WP_PLUGIN_DIR . '/' . $stillpress_plugin ) ) {
					require WP_PLUGIN_DIR . '/' . $stillpress_plugin;
				}
			}
		}
	}
);

require $stillpress_tests_dir . '/includes/bootstrap.php';
