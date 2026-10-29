<?php
/**
 * The static search index in real builds.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

use Stillpress\Search\ShardKeys;
use Stillpress\Support\Settings;

final class SearchIndexTest extends SystemTestCase {

	/**
	 * Decoded JSON of a deployed file.
	 */
	private function deployed_json( string $relative ): ?array {
		$file = $this->target . '/' . $relative;
		return is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	}

	/**
	 * Public paths of the documents holding a term, read the way the client will.
	 */
	private function search( string $term ): array {
		$meta = $this->deployed_json( 'stillpress-search/meta.json' );
		$key  = ShardKeys::key_for( $term, $meta['keys'] );
		if ( null === $key ) {
			return array();
		}
		$postings = $this->deployed_json( 'stillpress-search/terms/' . ShardKeys::file_name( $key ) )[ $term ] ?? array();
		$paths    = array();
		$count    = count( $postings );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$id      = $postings[ $i ];
			$docs    = $this->deployed_json( 'stillpress-search/docs/' . intdiv( $id, $meta['chunk'] ) . '.json' );
			$paths[] = $docs[ $id ][0];
		}
		sort( $paths );
		return $paths;
	}

	public function test_published_pages_are_searchable_and_unpublished_ones_are_not(): void {
		wp_insert_post(
			array(
				'post_title'   => 'Lighthouse keeping',
				'post_name'    => 'lighthouse',
				'post_content' => '<!-- wp:paragraph --><p>Notes on lenses and fog horns.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		wp_insert_post(
			array(
				'post_title'   => 'Fog in the harbour',
				'post_name'    => 'harbour',
				'post_content' => '<!-- wp:paragraph --><p>Thick fog again.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		wp_insert_post(
			array(
				'post_title'   => 'Unpublished lighthouse draft',
				'post_status'  => 'draft',
				'post_content' => 'Secretword.',
			)
		);
		$this->build( 'full' );

		$this->assertContains( '/lighthouse/', $this->search( 'lighthouse' ) );
		$this->assertContains( '/lighthouse/', $this->search( 'fog' ) );
		$this->assertContains( '/harbour/', $this->search( 'fog' ) );
		$this->assertSame( array(), $this->search( 'secretword' ) );

		$meta = $this->deployed_json( 'stillpress-search/meta.json' );
		$this->assertGreaterThan( 2, $meta['docs'], 'Posts, the home page and archives.' );

		$html = (string) $this->page( '/lighthouse/' );
		$this->assertStringContainsString( 'window.stillpressSearchSettings', $html );
		$this->assertStringContainsString( wp_json_encode( self::PUBLIC_URL . '/stillpress-search/' ), $html, 'The index address is rewritten to the public site.' );
		$this->assertStringNotContainsString( wp_json_encode( home_url( '/stillpress-search/' ) ), $html );
		$this->assertFileExists( $this->target . '/wp-content/plugins/stillpress/assets/js/search.js' );
		$this->assertFileExists( $this->target . '/wp-content/plugins/stillpress/assets/css/search.css', 'Found in the settings and copied, though only loaded on demand.' );
	}

	public function test_deleting_a_post_removes_it_from_search_incrementally(): void {
		$id = wp_insert_post(
			array(
				'post_title'   => 'Quokka facts',
				'post_name'    => 'quokka',
				'post_content' => 'Small marsupials.',
				'post_status'  => 'publish',
			)
		);
		$this->build( 'full' );
		update_option( 'stillpress_published_once', 1 );
		$this->assertContains( '/quokka/', $this->search( 'quokka' ) );

		wp_delete_post( $id, true );
		$this->build( 'incremental' );
		$this->assertNotContains( '/quokka/', $this->search( 'quokka' ) );
	}

	public function test_search_off_publishes_no_index(): void {
		Settings::update( array( 'search' => 'off' ) );
		wp_insert_post(
			array(
				'post_title'  => 'Anything',
				'post_status' => 'publish',
			)
		);
		$this->build( 'full' );
		$this->assertFileDoesNotExist( $this->target . '/stillpress-search/meta.json' );
		$this->assertStringNotContainsString( 'stillpressSearchSettings', (string) $this->page( '/' ) );
	}
}
