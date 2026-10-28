<?php
/**
 * Accent folding fallback tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Stillpress\Search\Fold;

final class FoldTest extends TestCase {

	public function test_fallback_matches_normalizer_for_every_letter_in_the_table_ranges(): void {
		if ( ! class_exists( '\Normalizer' ) ) {
			$this->markTestSkipped( 'Needs intl to compare against.' );
		}
		$ranges = array( array( 0xC0, 0x24F ), array( 0x370, 0x4FF ), array( 0x1E00, 0x1FFF ) );
		foreach ( $ranges as $range ) {
			for ( $code = $range[0]; $code <= $range[1]; $code++ ) {
				$char = mb_chr( $code, 'UTF-8' );
				if ( false === $char || ! preg_match( '/^\p{L}$/u', $char ) ) {
					continue;
				}
				$intl = Fold::fold( $char );
				if ( 1 !== mb_strlen( $intl, 'UTF-8' ) ) {
					continue; // NFD leaves more than one letter; neither side folds these to one.
				}
				$this->assertSame( $intl, Fold::fold( $char, false ), sprintf( 'U+%04X', $code ) );
			}
		}
	}

	public function test_fallback_strips_combining_marks_left_in_decomposed_text(): void {
		$this->assertSame( 'cafe', Fold::fold( "cafe\u{0301}", false ) );
	}

	public function test_fallback_leaves_other_scripts_alone(): void {
		$this->assertSame( 'がっこう 한국어 हिन्दी', Fold::fold( 'がっこう 한국어 हिन्दी', false ) );
	}
}
