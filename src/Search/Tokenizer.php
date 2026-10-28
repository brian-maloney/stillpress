<?php
/**
 * Cuts text into search terms.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

/**
 * Turns text into the terms the search index stores. Pure: no WordPress calls.
 *
 * The bundled search client applies the same rules to queries, so a change here must be made there too; both are
 * checked against tests/fixtures/search-tokenize.json (D20). The rules:
 *
 * 1. Lowercase, then fold accents (Fold), and treat final sigma as sigma (browsers lowercase Σ by position).
 * 2. Han, Hiragana and Katakana runs (with the prolonged sound mark) become overlapping pairs of characters, or the
 *    character itself when the run is one long, because those scripts don't put spaces between words.
 * 3. Other runs of letters, marks and digits are terms. Single characters and terms over MAX_LENGTH are dropped.
 */
final class Tokenizer {

	/** Longest term kept, in characters; longer runs are mostly encoded data. */
	const MAX_LENGTH = 40;

	/** Characters indexed as pairs. */
	const CJK = '\p{Han}\p{Hiragana}\p{Katakana}\x{30FC}';

	/** How much more a term in the title counts than one in the body. */
	const TITLE_WEIGHT = 5;

	/** Highest score a term can have in one document. */
	const MAX_SCORE = 255;

	/**
	 * Use Normalizer when loaded; false forces the fallback table.
	 *
	 * @var bool
	 */
	private $intl;

	/**
	 * Constructor.
	 *
	 * @param bool $intl Use the intl extension when it is loaded (tests pass false to check the fallback).
	 */
	public function __construct( bool $intl = true ) {
		$this->intl = $intl;
	}

	/**
	 * Lowercases and folds text.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public function normalize( string $text ): string {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		return str_replace( 'ς', 'σ', Fold::fold( $lower, $this->intl ) );
	}

	/**
	 * Returns the terms of a text in order, repeats included.
	 *
	 * @param string $text UTF-8 text.
	 * @return string[]
	 */
	public function tokens( string $text ): array {
		$text = $this->normalize( $text );
		if ( ! preg_match_all( '/[' . self::CJK . ']+|(?:(?![' . self::CJK . '])[\p{L}\p{M}\p{N}])+/u', $text, $matches ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $matches[0] as $run ) {
			$chars = (array) preg_split( '//u', $run, -1, PREG_SPLIT_NO_EMPTY );
			$count = count( $chars );
			if ( preg_match( '/^[' . self::CJK . ']/u', $run ) ) {
				if ( 1 === $count ) {
					$tokens[] = $run;
				}
				for ( $i = 0; $i < $count - 1; $i++ ) {
					$tokens[] = $chars[ $i ] . $chars[ $i + 1 ];
				}
			} elseif ( $count >= 2 && $count <= self::MAX_LENGTH ) {
				$tokens[] = $run;
			}
		}
		return $tokens;
	}

	/**
	 * Scores a document's terms: each title occurrence counts TITLE_WEIGHT, each body occurrence one, capped at
	 * MAX_SCORE. Keeps the $limit best terms (ties go to the term that sorts first) and returns them sorted by term.
	 *
	 * @param string $title Title.
	 * @param string $body  Body text.
	 * @param int    $limit Most terms to keep.
	 * @return array<string,int> Term => score.
	 */
	public function scores( string $title, string $body, int $limit ): array {
		$scores = array();
		foreach ( $this->tokens( $title ) as $token ) {
			$scores[ $token ] = ( $scores[ $token ] ?? 0 ) + self::TITLE_WEIGHT;
		}
		foreach ( $this->tokens( $body ) as $token ) {
			$scores[ $token ] = ( $scores[ $token ] ?? 0 ) + 1;
		}

		$ranked = array();
		foreach ( $scores as $term => $score ) {
			$ranked[] = array( (string) $term, min( $score, self::MAX_SCORE ) );
		}
		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				return $a[1] === $b[1] ? strcmp( $a[0], $b[0] ) : $b[1] <=> $a[1];
			}
		);

		$kept = array();
		foreach ( array_slice( $ranked, 0, max( 0, $limit ) ) as $entry ) {
			$kept[ $entry[0] ] = $entry[1];
		}
		ksort( $kept, SORT_STRING );
		return $kept;
	}
}
