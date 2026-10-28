<?php
/**
 * Writing the static search index files.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Build\Pipeline;
use Stillpress\Search\Index;
use Stillpress\Search\IndexWriter;
use Stillpress\Search\PageIndexer;
use Stillpress\Search\TextExtractor;
use Stillpress\Search\Tokenizer;
use Stillpress\Storage\UrlRepository;

final class IndexWriterTest extends \WP_UnitTestCase {

	/**
	 * Components.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	/**
	 * URLs.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Index tables.
	 *
	 * @var Index
	 */
	private $index;

	public function set_up(): void {
		parent::set_up();
		$this->pipeline = Pipeline::for_site();
		$this->urls     = new UrlRepository();
		$this->index    = new Index();
		$this->index->clear();
		$this->pipeline->settings['search'] = 'builtin';
	}

	private function page( string $path, string $title, string $body ): int {
		$id = $this->urls->ensure( 'http://example.org' . $path );
		$this->urls->update(
			$id,
			array(
				'kind'         => 'page',
				'status_code'  => 200,
				'content_hash' => str_repeat( 'a', 64 ),
				'content_type' => 'text/html; charset=UTF-8',
				'path'         => ltrim( $path, '/' ) . 'index.html',
			)
		);
		( new PageIndexer( new TextExtractor(), new Tokenizer(), $this->index ) )->index( $id, 'http://example.org' . $path, "<body><main><h1>{$title}</h1><p>{$body}</p></main></body>" );
		return $id;
	}

	private function write( int $build_id, string $kind = 'incremental' ): void {
		$writer = new IndexWriter(
			$this->pipeline,
			$this->urls,
			$this->index,
			array(
				'id'   => $build_id,
				'kind' => $kind,
			)
		);
		$this->assertTrue( $writer->write( 0.0 ) );
	}

	private function read( string $relative ): ?array {
		$path = $this->pipeline->cache->path( $relative );
		return is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	}

	/**
	 * Generated rows a build wrote, by pseudo-URL.
	 */
	private function written_by( int $build_id ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM %i WHERE url LIKE %s AND last_build_id = %d AND content_hash IS NOT NULL ORDER BY url', \Stillpress\Storage\Schema::table( 'urls' ), $wpdb->esc_like( 'stillpress:search/' ) . '%', $build_id ) );
	}

	public function test_writes_shards_documents_and_meta(): void {
		$hello = $this->page( '/hello/', 'Hello static', 'Static sites are fast.' );
		$about = $this->page( '/about/', 'About', 'Fast and simple.' );
		$this->write( 1 );

		$meta = $this->read( 'stillpress-search/meta.json' );
		$this->assertSame( 1, $meta['version'] );
		$this->assertSame( 2, $meta['docs'] );
		$this->assertSame( array( 'ab', 'an', 'ar', 'fa', 'he', 'si', 'st' ), $meta['keys'] );

		$this->assertSame( array( 'static' => array( $hello, 7 ) ), $this->read( 'stillpress-search/terms/7374.json' ) );
		$fast = $this->read( 'stillpress-search/terms/6661.json' );
		$this->assertSame( array( 'fast' ), array_keys( $fast ) );
		$this->assertEqualsCanonicalizing( array( $hello, 1, $about, 1 ), $fast['fast'] );

		$docs = $this->read( 'stillpress-search/docs/' . Index::chunk_of( $hello ) . '.json' );
		$this->assertSame( array( '/hello/', 'Hello static', 'Hello static Static sites are fast.' ), $docs[ $hello ] );
	}

	public function test_an_edit_rewrites_only_the_files_it_touches(): void {
		$this->page( '/hello/', 'Hello', 'Static sites.' );
		$this->page( '/about/', 'About', 'Simple words.' );
		$this->write( 1 );

		$this->page( '/about/', 'About', 'Simple words, zebra.' );
		$this->write( 2 );
		$terms = array_values( preg_grep( '#^stillpress:search/terms/#', $this->written_by( 2 ) ) );
		$this->assertSame( array( 'stillpress:search/terms/7a65/7a65' ), $terms, 'Only the new term\'s group.' );
	}

	public function test_terms_that_leave_the_index_take_their_shard_and_key_with_them(): void {
		$this->page( '/about/', 'About', 'Zebra crossing.' );
		$this->write( 1 );
		$this->assertNotNull( $this->read( 'stillpress-search/terms/7a65.json' ) );

		$this->page( '/about/', 'About', 'Crossing.' );
		$this->write( 2 );
		$this->assertNull( $this->read( 'stillpress-search/terms/7a65.json' ) );
		$this->assertNotContains( 'ze', $this->read( 'stillpress-search/meta.json' )['keys'] );
	}

	public function test_unpublished_pages_leave_the_index(): void {
		$gone = $this->page( '/gone/', 'Gone', 'Unique wording.' );
		$this->write( 1 );

		$this->urls->unpublish( $gone );
		$this->write( 2 );
		$this->assertNull( $this->read( 'stillpress-search/terms/756e.json' ) );
		$this->assertNull( $this->read( 'stillpress-search/docs/' . Index::chunk_of( $gone ) . '.json' ) );
		$this->assertSame( 0, $this->read( 'stillpress-search/meta.json' )['docs'] );
	}

	public function test_a_full_build_restores_missing_files_once(): void {
		$this->page( '/hello/', 'Hello', 'Static sites.' );
		$this->write( 1 );
		$this->pipeline->cache->delete( 'stillpress-search/terms/7374.json' );

		$this->write( 2 );
		$this->assertNull( $this->read( 'stillpress-search/terms/7374.json' ), 'An incremental build trusts the records.' );

		$this->write( 3, 'full' );
		$this->assertNotNull( $this->read( 'stillpress-search/terms/7374.json' ) );
		$this->assertSame( array(), $this->index->dirty( 'group', 10 ) );
	}

	public function test_switching_search_off_removes_every_file(): void {
		$this->page( '/hello/', 'Hello', 'Static sites.' );
		$this->write( 1 );

		$this->pipeline->settings['search'] = 'off';
		$this->write( 2 );
		$this->assertNull( $this->read( 'stillpress-search/meta.json' ) );
		$this->assertNull( $this->read( 'stillpress-search/terms/7374.json' ) );
		$this->assertSame( 0, $this->index->count_docs() );
	}
}
