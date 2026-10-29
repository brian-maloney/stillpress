<?php
/**
 * Publishing a Pagefind bundle.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Build\Pipeline;
use Stillpress\Search\Pagefind;
use Stillpress\Search\PagefindWriter;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Logger;

final class PagefindWriterTest extends \WP_UnitTestCase {

	/**
	 * Components.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	public function set_up(): void {
		parent::set_up();
		$this->pipeline                     = Pipeline::for_site();
		$this->pipeline->settings['search'] = 'pagefind';
		$this->pipeline->cache->write( 'pf-test/index.html', '<html><body>One</body></html>' );
	}

	public function tear_down(): void {
		$this->pipeline->settings['search'] = 'off';
		$this->writer( 99, null )->write();
		$this->pipeline->cache->delete( 'pf-test/index.html' );
		$this->pipeline->cache->delete( 'pf-test/two/index.html' );
		parent::tear_down();
	}

	private function writer( int $build_id, ?string $binary ): PagefindWriter {
		$pagefind = null === $binary ? null : new Pagefind( dirname( __DIR__, 2 ) . '/fixtures/pagefind/' . $binary );
		return new PagefindWriter( $this->pipeline, new UrlRepository(), new Logger( $build_id ), $build_id, $pagefind );
	}

	private function published(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM %i WHERE url LIKE %s AND content_hash IS NOT NULL ORDER BY path', \Stillpress\Storage\Schema::table( 'urls' ), $wpdb->esc_like( PagefindWriter::PSEUDO ) . '%' ) );
	}

	private function log( int $build_id ): string {
		return implode( "\n", array_column( Logger::lines( $build_id ), 'message' ) );
	}

	private function fragments(): array {
		return array_values( preg_grep( '#^pagefind/fragment/#', $this->published() ) );
	}

	public function test_publishes_the_bundle_and_replaces_files_it_no_longer_has(): void {
		$this->writer( 1, 'fake-pagefind' )->write();
		$this->assertContains( 'pagefind/pagefind.js', $this->published() );
		$this->assertFileExists( $this->pipeline->cache->path( 'pagefind/pagefind.js' ) );
		$first = $this->fragments();
		$this->assertCount( 1, $first );
		$this->assertStringContainsString( 'Pagefind indexed the site', $this->log( 1 ) );

		$this->pipeline->cache->write( 'pf-test/two/index.html', '<html><body>Two</body></html>' );
		$this->writer( 2, 'fake-pagefind' )->write();
		$second = $this->fragments();
		$this->assertCount( 1, $second );
		$this->assertNotSame( $first, $second, 'One more page, another fragment.' );
		$this->assertFileDoesNotExist( $this->pipeline->cache->path( $first[0] ) );
	}

	public function test_a_failed_run_keeps_the_published_bundle(): void {
		$this->writer( 3, 'fake-pagefind' )->write();
		$before = $this->published();

		$this->writer( 4, 'failing-pagefind' )->write();
		$this->assertSame( $before, $this->published() );
		$this->assertStringContainsString( 'no HTML files found', $this->log( 4 ) );
	}

	public function test_without_a_binary_the_bundle_stays_and_the_log_says_why(): void {
		$this->writer( 5, 'fake-pagefind' )->write();
		$before = $this->published();

		$this->writer( 6, null )->write();
		$this->assertSame( $before, $this->published() );
		$this->assertStringContainsString( Pagefind::CONSTANT, $this->log( 6 ) );
	}

	public function test_another_mode_removes_the_bundle(): void {
		$this->writer( 7, 'fake-pagefind' )->write();
		$this->pipeline->settings['search'] = 'builtin';
		$this->writer( 8, 'fake-pagefind' )->write();
		$this->assertSame( array(), $this->published() );
		$this->assertFileDoesNotExist( $this->pipeline->cache->path( 'pagefind/pagefind.js' ) );
	}
}
