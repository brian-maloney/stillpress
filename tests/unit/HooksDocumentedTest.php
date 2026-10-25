<?php
/**
 * Every hook is documented, and docs/HOOKS.md is current.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/hooks.php';

final class HooksDocumentedTest extends TestCase {

	public function test_every_hook_has_a_docblock_with_since_and_description(): void {
		$hooks = \Stillpress\Bin\find_hooks( dirname( __DIR__, 2 ) . '/src' );
		$this->assertGreaterThan( 20, count( $hooks ) );
		$problems = array();
		foreach ( $hooks as $hook ) {
			foreach ( \Stillpress\Bin\problems( $hook ) as $problem ) {
				$problems[] = "{$hook['file']}:{$hook['line']} {$hook['name']}: {$problem}";
			}
		}
		$this->assertSame( array(), $problems );
	}

	public function test_hooks_reference_is_up_to_date(): void {
		$expected = \Stillpress\Bin\markdown( \Stillpress\Bin\find_hooks( dirname( __DIR__, 2 ) . '/src' ) );
		$this->assertSame( $expected, file_get_contents( dirname( __DIR__, 2 ) . '/docs/HOOKS.md' ), 'Regenerate with: php bin/hooks.php > docs/HOOKS.md' );
	}
}
