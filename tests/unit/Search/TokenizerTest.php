<?php
/**
 * Search tokenizer tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Stillpress\Search\Tokenizer;

final class TokenizerTest extends TestCase {

	/**
	 * The shared examples hold with intl and with the fallback table, so a host without intl matches the browser.
	 *
	 * @dataProvider provide_shared_cases
	 */
	public function test_matches_shared_examples( string $text, array $tokens ): void {
		$this->assertSame( $tokens, ( new Tokenizer( false ) )->tokens( $text ), 'fallback' );
		if ( ! class_exists( '\Normalizer' ) ) {
			$this->markTestIncomplete( 'intl is not loaded; only the fallback was checked.' );
		}
		$this->assertSame( $tokens, ( new Tokenizer() )->tokens( $text ), 'intl' );
	}

	public function provide_shared_cases(): array {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/search-tokenize.json' ), true );
		$cases   = array();
		foreach ( $fixture['cases'] as $case ) {
			$cases[ $case['text'] ] = array( $case['text'], $case['tokens'] );
		}
		return $cases;
	}

	public function test_empty_and_punctuation_only_text_has_no_tokens(): void {
		$tokenizer = new Tokenizer();
		$this->assertSame( array(), $tokenizer->tokens( '' ) );
		$this->assertSame( array(), $tokenizer->tokens( ' — …, !? ' ) );
	}

	public function test_scores_weigh_the_title_and_cap_each_term(): void {
		$scores = ( new Tokenizer() )->scores( 'Static sites', 'Static files. ' . str_repeat( 'files ', 300 ), 10 );
		$this->assertSame(
			array(
				'files'  => 255,
				'sites'  => 5,
				'static' => 6,
			),
			$scores
		);
	}

	public function test_scores_keep_the_best_terms_sorted_by_term(): void {
		$scores = ( new Tokenizer() )->scores( 'zebra', 'apple apple mango kiwi', 2 );
		$this->assertSame(
			array(
				'apple' => 2,
				'zebra' => 5,
			),
			$scores
		);
	}

	public function test_score_ties_keep_the_term_that_sorts_first(): void {
		$this->assertSame( array( 'bb' => 1 ), ( new Tokenizer() )->scores( '', 'cc bb dd', 1 ) );
	}

	public function test_numeric_terms_stay_strings(): void {
		$scores = ( new Tokenizer() )->scores( '', '2026 2026 99', 5 );
		$this->assertSame( array( '2026', '99' ), array_map( 'strval', array_keys( $scores ) ) );
		$this->assertSame( array( 2, 1 ), array_values( $scores ) );
	}
}
