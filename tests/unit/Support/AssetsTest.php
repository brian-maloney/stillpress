<?php
/**
 * Asset version strings.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stillpress\Support\Assets;

final class AssetsTest extends TestCase {

	public function test_releases_use_the_plain_version(): void {
		$this->assertSame( '1.2.0', Assets::compose( '1.2.0', 1700000000 ) );
		$this->assertSame( '1.2.0-beta1', Assets::compose( '1.2.0-beta1', 1700000000 ) );
	}

	public function test_development_builds_change_with_the_file(): void {
		$this->assertSame( '0.1.0-dev-1700000000', Assets::compose( '0.1.0-dev', 1700000000 ) );
		$this->assertNotSame( Assets::compose( '0.1.0-dev', 1700000000 ), Assets::compose( '0.1.0-dev', 1700000060 ) );
	}

	public function test_unknown_file_time_falls_back_to_the_version(): void {
		$this->assertSame( '0.1.0-dev', Assets::compose( '0.1.0-dev', null ) );
	}
}
