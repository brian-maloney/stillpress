<?php
/**
 * Loading the search client on rendered pages.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Search\Frontend;

final class FrontendTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		wp_dequeue_script( Frontend::HANDLE );
		wp_deregister_script( Frontend::HANDLE );
		remove_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) );
		parent::tear_down();
	}

	public function test_live_requests_keep_wordpress_search(): void {
		Frontend::register();
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) ) );
	}

	public function test_the_client_is_deferred_with_its_settings_before_it(): void {
		Frontend::enqueue();
		$scripts = wp_scripts();
		$this->assertTrue( wp_script_is( Frontend::HANDLE, 'enqueued' ) );
		$this->assertSame( 'defer', $scripts->get_data( Frontend::HANDLE, 'strategy' ) );

		$before = trim( implode( "\n", array_filter( (array) $scripts->get_data( Frontend::HANDLE, 'before' ) ) ) );
		$this->assertStringStartsWith( 'window.stillpressSearchSettings = {', $before );
		$this->assertStringContainsString( wp_json_encode( home_url( '/stillpress-search/' ) ), $before );
	}

	public function test_settings_can_be_filtered(): void {
		$filter = static function ( $settings ) {
			$settings['style'] = '';
			return $settings;
		};
		add_filter( 'stillpress_search_settings', $filter );
		$settings = Frontend::settings();
		remove_filter( 'stillpress_search_settings', $filter );
		$this->assertSame( '', $settings['style'] );
		$this->assertSame( Frontend::LIMIT, $settings['limit'] );
	}
}
