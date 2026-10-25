<?php
/**
 * Smoke test for the unit suite.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {

	public function test_autoloader_finds_plugin_class(): void {
		$this->assertTrue( class_exists( \Stillpress\Plugin::class ) );
	}
}
