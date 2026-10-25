<?php
/**
 * Starting points for a full crawl.
 *
 * @package Stillpress
 */

namespace Stillpress\Crawl;

use Stillpress\Support\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Paged ID scans over core tables; caching would only waste memory.

/**
 * Produces seed URLs in resumable batches, so very large sites can be seeded across several queue slices.
 *
 * Stages: core → posts → terms → authors → done. The cursor is a small array stored in the build's stats.
 */
final class Seeds {

	const STAGES = array( 'core', 'posts', 'terms', 'authors', 'done' );

	/** Path probed to render the 404 page. */
	const NOT_FOUND_PROBE = '/stillpress-404-probe-9f3c1e/';

	/**
	 * Returns the next batch of seeds.
	 *
	 * @param array{stage?:string,after?:int} $cursor Position from the previous call.
	 * @param int                             $limit  Maximum seeds per batch.
	 * @return array{seeds:array<int,array{url:string,source:string}>,cursor:array{stage:string,after:int}}
	 */
	public function next( array $cursor, int $limit = 500 ): array {
		$stage = $cursor['stage'] ?? 'core';
		$after = (int) ( $cursor['after'] ?? 0 );

		switch ( $stage ) {
			case 'core':
				return array(
					'seeds'  => $this->core(),
					'cursor' => array(
						'stage' => 'posts',
						'after' => 0,
					),
				);
			case 'posts':
				return $this->posts( $after, $limit );
			case 'terms':
				return $this->terms( $after, $limit );
			case 'authors':
				return array(
					'seeds'  => $this->authors(),
					'cursor' => array(
						'stage' => 'done',
						'after' => 0,
					),
				);
		}//end switch
		return array(
			'seeds'  => array(),
			'cursor' => array(
				'stage' => 'done',
				'after' => 0,
			),
		);
	}

	/**
	 * Home, feeds, robots.txt, sitemaps, post type archives, the 404 page and user-listed URLs.
	 *
	 * @return array<int,array{url:string,source:string}>
	 */
	private function core(): array {
		$seeds = array(
			array(
				'url'    => home_url( '/' ),
				'source' => 'home',
			),
			array(
				'url'    => home_url( self::NOT_FOUND_PROBE ),
				'source' => '404',
			),
			array(
				'url'    => home_url( '/robots.txt' ),
				'source' => 'robots',
			),
		);
		if ( 'none' !== Settings::get( 'feeds' ) ) {
			$seeds[] = array(
				'url'    => get_feed_link(),
				'source' => 'feed',
			);
		}
		if ( function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
			$seeds[] = array(
				'url'    => home_url( '/wp-sitemap.xml' ),
				'source' => 'sitemap',
			);
		}
		foreach ( get_post_types(
			array(
				'public'      => true,
				'has_archive' => true,
			)
		) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( $link ) {
				$seeds[] = array(
					'url'    => $link,
					'source' => 'archive',
				);
			}
		}
		foreach ( (array) Settings::get( 'additional_urls' ) as $extra ) {
			$seeds[] = array(
				'url'    => preg_match( '#^https?://#i', $extra ) ? $extra : home_url( '/' . ltrim( $extra, '/' ) ),
				'source' => 'additional',
			);
		}
		return $seeds;
	}

	/**
	 * Published, non-password-protected posts of public post types.
	 *
	 * @param int $after Last post ID of the previous batch.
	 * @param int $limit Batch size.
	 * @return array{seeds:array<int,array{url:string,source:string}>,cursor:array{stage:string,after:int}}
	 */
	private function posts( int $after, int $limit ): array {
		global $wpdb;
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		if ( ! get_option( 'wp_attachment_pages_enabled', 1 ) ) {
			$types = array_values( array_diff( $types, array( 'attachment' ) ) );
		}
		if ( ! $types ) {
			return array(
				'seeds'  => array(),
				'cursor' => array(
					'stage' => 'terms',
					'after' => 0,
				),
			);
		}
		$in  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in) AND ( post_status = 'publish' OR ( post_type = 'attachment' AND post_status = 'inherit' ) ) AND post_password = '' ORDER BY ID ASC LIMIT %d", array_merge( array( $after ), $types, array( $limit ) ) )
		);
		$seeds = array();
		$last  = $after;
		foreach ( (array) $ids as $id ) {
			$last = (int) $id;
			$link = get_permalink( $last );
			if ( $link ) {
				$seeds[] = array(
					'url'    => $link,
					'source' => 'post',
				);
			}
		}
		$done = count( (array) $ids ) < $limit;
		return array(
			'seeds'  => $seeds,
			'cursor' => array(
				'stage' => $done ? 'terms' : 'posts',
				'after' => $done ? 0 : $last,
			),
		);
	}

	/**
	 * Archives of every term in public taxonomies, including empty terms (WordPress still serves them).
	 *
	 * @param int $after Last term ID of the previous batch.
	 * @param int $limit Batch size.
	 * @return array{seeds:array<int,array{url:string,source:string}>,cursor:array{stage:string,after:int}}
	 */
	private function terms( int $after, int $limit ): array {
		global $wpdb;
		$taxonomies = array_values( get_taxonomies( array( 'publicly_queryable' => true ) ) );
		$taxonomies = array_values( array_diff( $taxonomies, array( 'post_format' ) ) );
		if ( ! $taxonomies ) {
			return array(
				'seeds'  => array(),
				'cursor' => array(
					'stage' => 'authors',
					'after' => 0,
				),
			);
		}
		$in   = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
			$wpdb->prepare( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id > %d AND taxonomy IN ($in) ORDER BY term_id ASC LIMIT %d", array_merge( array( $after ), $taxonomies, array( $limit ) ) )
		);
		$seeds = array();
		$last  = $after;
		foreach ( (array) $rows as $row ) {
			$last = (int) $row->term_id;
			$link = get_term_link( (int) $row->term_id, (string) $row->taxonomy );
			if ( is_string( $link ) ) {
				$seeds[] = array(
					'url'    => $link,
					'source' => 'term',
				);
			}
		}
		$done = count( (array) $rows ) < $limit;
		return array(
			'seeds'  => $seeds,
			'cursor' => array(
				'stage' => $done ? 'authors' : 'terms',
				'after' => $done ? 0 : $last,
			),
		);
	}

	/**
	 * Archives of users who have published posts.
	 *
	 * @return array<int,array{url:string,source:string}>
	 */
	private function authors(): array {
		global $wpdb;
		$ids   = $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );
		$seeds = array();
		foreach ( (array) $ids as $id ) {
			$seeds[] = array(
				'url'    => get_author_posts_url( (int) $id ),
				'source' => 'author',
			);
		}
		return $seeds;
	}
}
