<?php
/**
 * Indexes a page as it is published.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

/**
 * Extracts a rendered page's text, scores its terms and stores them in the index.
 */
final class PageIndexer {

	/** Most distinct terms kept per page; the highest-scoring win. */
	const MAX_TERMS = 500;

	/**
	 * Extractor.
	 *
	 * @var TextExtractor
	 */
	private $extractor;

	/**
	 * Tokenizer.
	 *
	 * @var Tokenizer
	 */
	private $tokenizer;

	/**
	 * Index tables.
	 *
	 * @var Index
	 */
	private $index;

	/**
	 * Constructor.
	 *
	 * @param TextExtractor $extractor Extractor.
	 * @param Tokenizer     $tokenizer Tokenizer.
	 * @param Index         $index     Index tables.
	 */
	public function __construct( TextExtractor $extractor, Tokenizer $tokenizer, Index $index ) {
		$this->extractor = $extractor;
		$this->tokenizer = $tokenizer;
		$this->index     = $index;
	}

	/**
	 * Indexes a published HTML page.
	 *
	 * @param int    $url_id URL ID.
	 * @param string $url    Origin URL.
	 * @param string $html   Published HTML.
	 * @return void
	 */
	public function index( int $url_id, string $url, string $html ): void {
		$document = $this->extractor->extract( $html );

		/**
		 * Filters what the static search index stores for a page. Return null to leave the page out of search.
		 *
		 * @since 0.3.0
		 *
		 * @param array{title:string,text:string,excerpt:string}|null $document Title, searchable text and excerpt.
		 * @param string                                               $url      Origin URL of the page.
		 */
		$document = apply_filters( 'stillpress_search_document', $document, $url );
		if ( ! is_array( $document ) ) {
			$this->index->forget( $url_id );
			return;
		}

		$title   = (string) ( $document['title'] ?? '' );
		$excerpt = (string) ( $document['excerpt'] ?? '' );
		$scores  = $this->tokenizer->scores( $title, (string) ( $document['text'] ?? '' ), self::MAX_TERMS );
		$this->index->replace( $url_id, $title, $excerpt, $scores );
	}
}
