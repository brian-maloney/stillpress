<?php
/**
 * Confirms the plugin loads inside WordPress.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration;

final class PluginLoadsTest extends \WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'STILLPRESS_VERSION' ) );
	}

	public function test_action_scheduler_is_available(): void {
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
	}
}
