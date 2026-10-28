<?php
/**
 * Search index tables.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Search\Index;
use Stillpress\Storage\UrlRepository;

final class IndexTest extends \WP_UnitTestCase {

	/**
	 * Index under test.
	 *
	 * @var Index
	 */
	private $index;

	public function set_up(): void {
		parent::set_up();
		$this->index = new Index();
		$this->index->clear();
	}

	private function drain( string $kind ): array {
		$keys = $this->index->dirty( $kind, 1000 );
		foreach ( $keys as $key ) {
			$this->index->clean( $kind, $key );
		}
		return $keys;
	}

	public function test_replace_marks_only_the_groups_and_chunk_that_changed(): void {
		$this->index->replace(
			7,
			'Static',
			'Excerpt',
			array(
				'static'    => 5,
				'sites'     => 1,
				'wordpress' => 2,
			)
		);
		$this->assertSame( array( 'si', 'st', 'wo' ), $this->drain( 'group' ) );
		$this->assertSame( array( '0' ), $this->drain( 'docs' ) );

		$this->index->replace(
			7,
			'Static',
			'Excerpt',
			array(
				'static' => 5,
				'sites'  => 2,
				'zebra'  => 1,
			)
		);
		$this->assertSame( array( 'si', 'wo', 'ze' ), $this->drain( 'group' ), 'Score change, removal and addition.' );
		$this->assertSame( array(), $this->drain( 'docs' ), 'Same title and excerpt.' );

		$this->index->replace(
			7,
			'Static',
			'New excerpt',
			array(
				'static' => 5,
				'sites'  => 2,
				'zebra'  => 1,
			)
		);
		$this->assertSame( array(), $this->drain( 'group' ) );
		$this->assertSame( array( '0' ), $this->drain( 'docs' ) );
	}

	public function test_postings_hold_one_group_in_term_order(): void {
		$this->index->replace(
			600,
			'A',
			'',
			array(
				'stone'  => 3,
				'static' => 1,
			)
		);
		$this->index->replace(
			2,
			'B',
			'',
			array(
				'static' => 4,
				'st'     => 1,
				'sun'    => 1,
			)
		);
		$this->assertSame(
			array(
				'st'     => array( 2 => 1 ),
				'static' => array(
					2   => 4,
					600 => 1,
				),
				'stone'  => array( 600 => 3 ),
			),
			$this->index->postings( 'st' )
		);
	}

	public function test_terms_differing_only_by_accent_or_case_stay_apart(): void {
		$this->index->replace(
			1,
			'A',
			'',
			array(
				'øl' => 1,
				'ol' => 2,
			)
		);
		$this->assertSame( array( 'ol' => array( 1 => 2 ) ), $this->index->postings( 'ol' ) );
		$this->assertSame( array( 'øl' => array( 1 => 1 ) ), $this->index->postings( 'øl' ) );
	}

	public function test_single_character_groups_exclude_longer_groups(): void {
		$this->index->replace(
			1,
			'A',
			'',
			array(
				'日'  => 1,
				'日本' => 2,
			)
		);
		$this->assertSame( array( '日' => array( 1 => 1 ) ), $this->index->postings( '日' ) );
	}

	public function test_forget_unpublished_removes_pages_that_left_the_site(): void {
		$urls = new UrlRepository();
		$live = $urls->ensure( 'http://example.org/live/' );
		$gone = $urls->ensure( 'http://example.org/gone/' );
		$feed = $urls->ensure( 'http://example.org/feed/' );
		foreach ( array( $live, $gone, $feed ) as $id ) {
			$urls->update(
				$id,
				array(
					'status_code'  => 200,
					'content_hash' => str_repeat( 'a', 64 ),
					'content_type' => $id === $feed ? 'application/rss+xml' : 'text/html; charset=UTF-8',
					'path'         => 'p' . $id . '/index.html',
				)
			);
			$this->index->replace( $id, 'T', '', array( 'word' . $id => 1 ) );
		}
		$urls->unpublish( $gone );
		$this->drain( 'group' );
		$this->drain( 'docs' );

		$this->assertSame( 2, $this->index->forget_unpublished() );
		$this->assertSame( 1, $this->index->count_docs() );
		$this->assertSame( array( 'wo' ), $this->drain( 'group' ) );
		$this->assertSame( array_values( array_unique( array( (string) Index::chunk_of( $gone ), (string) Index::chunk_of( $feed ) ) ) ), $this->drain( 'docs' ) );
	}
}
