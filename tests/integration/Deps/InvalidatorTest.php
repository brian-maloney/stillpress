<?php
/**
 * From changes to affected URLs.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deps;

use Stillpress\Deps\DependencyStore;
use Stillpress\Deps\Invalidator;
use Stillpress\Deps\QuerySignature;
use Stillpress\Storage\UrlRepository;

final class InvalidatorTest extends \WP_UnitTestCase {

	/** @var array<string,int> */
	private $ids = array();

	public function set_up(): void {
		parent::set_up();
		$urls  = new UrlRepository();
		$store = new DependencyStore();
		foreach ( array( 'home', 'post', 'category', 'other', 'archive2025' ) as $name ) {
			$this->ids[ $name ] = $urls->ensure( "https://example.test/{$name}/" );
			$urls->update(
				$this->ids[ $name ],
				array(
					'path'         => "{$name}/index.html",
					'content_hash' => str_repeat( 'a', 64 ),
					'deps_state'   => 'recorded',
				)
			);
		}
		$recent = QuerySignature::from_query_vars( array( 'post_type' => 'post' ) );
		$in_cat = QuerySignature::from_query_vars(
			array(),
			array(
				'rel'     => 'AND',
				'clauses' => array(
					array(
						'op' => 'IN',
						'tt' => array( 50 ),
					),
				),
			)
		);
		$y2025  = QuerySignature::from_query_vars( array( 'year' => 2025 ) );
		$store->replace(
			$this->ids['home'],
			array(
				'option' => array( 'blogname' ),
				'query'  => array( QuerySignature::hash( $recent ) ),
			),
			array( QuerySignature::hash( $recent ) => $recent )
		);
		$store->replace(
			$this->ids['post'],
			array(
				'post' => array( 7 ),
				'term' => array( 5 ),
			)
		);
		$store->replace( $this->ids['category'], array( 'query' => array( QuerySignature::hash( $in_cat ) ) ), array( QuerySignature::hash( $in_cat ) => $in_cat ) );
		$store->replace(
			$this->ids['other'],
			array(
				'option' => array( 'other_option' ),
				'tq'     => array( 'post_tag' ),
			)
		);
		$store->replace( $this->ids['archive2025'], array( 'query' => array( QuerySignature::hash( $y2025 ) ) ), array( QuerySignature::hash( $y2025 ) => $y2025 ) );
	}

	private function change( string $type, string $key, array $payload = array() ): array {
		return array(
			'object_type' => $type,
			'object_key'  => $key,
			'payload'     => $payload,
		);
	}

	private function post_change( array $overrides = array() ): array {
		return $this->change(
			'post',
			(string) ( $overrides['ids'][0] ?? 7 ),
			array_merge(
				array(
					'ids'      => array( 7 ),
					'types'    => array( 'post' ),
					'statuses' => array( 'publish' ),
					'authors'  => array( 1 ),
					'parents'  => array( 0 ),
					'names'    => array( 'p' ),
					'dates'    => array( '2026-01-01' ),
					'tt'       => array( 50 ),
					'urls'     => array( 'https://example.test/p/' ),
				),
				$overrides
			)
		);
	}

	private function affected( array $changes ): array {
		$result = ( new Invalidator() )->affected( $changes );
		$names  = array();
		foreach ( $result['url_ids'] as $id ) {
			$names[] = array_search( $id, $this->ids, true );
		}
		sort( $names );
		return array( $names, $result );
	}

	public function test_post_change_hits_object_and_query_dependents(): void {
		list( $names, $result ) = $this->affected( array( $this->post_change() ) );
		$this->assertSame( array( 'category', 'home', 'post' ), $names );
		$this->assertSame( array( 'https://example.test/p/' ), $result['urls'] );
	}

	public function test_post_leaving_category_still_hits_category(): void {
		list( $names ) = $this->affected( array( $this->post_change( array( 'tt' => array( 50, 60 ) ) ) ) );
		$this->assertContains( 'category', $names );
	}

	public function test_page_change_skips_post_queries(): void {
		list( $names ) = $this->affected(
			array(
				$this->post_change(
					array(
						'ids'   => array( 99 ),
						'types' => array( 'page' ),
						'tt'    => array(),
					)
				),
			)
		);
		$this->assertSame( array(), $names );
	}

	public function test_date_moves_into_archive(): void {
		list( $names ) = $this->affected( array( $this->post_change( array( 'dates' => array( '2026-01-01', '2025-06-01' ) ) ) ) );
		$this->assertContains( 'archive2025', $names );
	}

	public function test_option_change(): void {
		list( $names ) = $this->affected( array( $this->change( 'option', 'blogname' ) ) );
		$this->assertSame( array( 'home' ), $names );
	}

	public function test_term_change_hits_term_and_taxonomy_dependents(): void {
		list( $names, $result ) = $this->affected(
			array(
				$this->change(
					'term',
					'5',
					array(
						'taxonomy' => 'post_tag',
						'urls'     => array( 'https://example.test/tag/x/' ),
					)
				),
			)
		);
		$this->assertSame( array( 'other', 'post' ), $names );
		$this->assertSame( array( 'https://example.test/tag/x/' ), $result['urls'] );
	}

	public function test_full_change(): void {
		$result = ( new Invalidator() )->affected( array( $this->change( 'full', 'code' ) ) );
		$this->assertTrue( $result['full'] );
	}

	public function test_unknown_dependency_pages_always_rebuild(): void {
		( new UrlRepository() )->update( $this->ids['other'], array( 'deps_state' => 'unknown' ) );
		list( $names ) = $this->affected( array( $this->change( 'option', 'blogname' ) ) );
		$this->assertSame( array( 'home', 'other' ), $names );
	}
}
