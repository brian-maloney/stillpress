<?php
/**
 * The search check in Site Health and `wp stillpress doctor`.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Service\HealthChecks;
use Stillpress\Support\Settings;

final class SearchHealthTest extends \WP_UnitTestCase {

	public function test_modes_that_need_nothing_here_are_good(): void {
		foreach ( array( 'builtin', 'pagefind_host', 'off' ) as $mode ) {
			Settings::update( array( 'search' => $mode ) );
			$this->assertSame( 'good', ( new HealthChecks() )->search()['status'], $mode );
		}
	}

	public function test_pagefind_without_the_constant_is_critical_and_names_it(): void {
		Settings::update( array( 'search' => 'pagefind' ) );
		$check = ( new HealthChecks() )->search();
		$this->assertSame( 'critical', $check['status'] );
		$this->assertStringContainsString( 'STILLPRESS_PAGEFIND_BINARY', $check['description'] );
	}

	public function test_the_setting_accepts_only_known_modes(): void {
		Settings::update( array( 'search' => 'pagefind_host' ) );
		$this->assertSame( 'pagefind_host', Settings::get( 'search' ) );
		Settings::update( array( 'search' => '/usr/bin/pagefind' ) );
		$this->assertSame( 'builtin', Settings::get( 'search' ) );
	}
}
