<?php
/**
 * System test bootstrap: loads a real, disposable WordPress site (the wp-env tests site) so builds can make real
 * loopback requests against committed data.
 *
 * These tests DELETE ALL CONTENT on the site they run against. They refuse to run unless STILLPRESS_SYSTEM_TESTS=1
 * and the site address is the wp-env tests site.
 *
 * @package Stillpress
 */

if ( '1' !== getenv( 'STILLPRESS_SYSTEM_TESTS' ) ) {
	fwrite( STDERR, "Refusing to run: system tests erase the site. Use npm run test:system.\n" );
	exit( 1 );
}

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.
require dirname( __DIR__, 5 ) . '/wp-load.php';

if ( 'http://localhost:8889' !== untrailingslashit( home_url() ) ) {
	fwrite( STDERR, 'Refusing to run against ' . home_url() . "; system tests only run on the wp-env tests site.\n" );
	exit( 1 );
}

// The plugin must be active so render requests on the web side run the recorder.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_plugin_active( 'stillpress/stillpress.php' ) ) {
	activate_plugin( 'stillpress/stillpress.php' );
}
