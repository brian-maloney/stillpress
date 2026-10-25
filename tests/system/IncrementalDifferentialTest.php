<?php
/**
 * Differential test for stale pages: after any sequence of edits, an incremental build must produce exactly what a
 * full build produces.
 *
 * Each iteration applies one to three random edits (seeded and reproducible), runs an incremental build and
 * snapshots the deployed site, then runs a full build and snapshots again. Any difference is a stale or missing
 * page that dependency tracking failed to catch.
 *
 * Environment:
 *   STILLPRESS_DIFF_ITERATIONS  Number of edit sequences (default 20).
 *   STILLPRESS_DIFF_SEED        Seed to reproduce a failing run.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

final class IncrementalDifferentialTest extends SystemTestCase {

	/**
	 * User IDs of authors.
	 *
	 * @var int[]
	 */
	private $authors = array();

	/**
	 * Log of applied edits for failure messages.
	 *
	 * @var string[]
	 */
	private $history = array();

	public function test_incremental_builds_match_full_builds(): void {
		$iterations = (int) ( getenv( 'STILLPRESS_DIFF_ITERATIONS' ) ? getenv( 'STILLPRESS_DIFF_ITERATIONS' ) : 20 );
		$seed       = (int) ( getenv( 'STILLPRESS_DIFF_SEED' ) ? getenv( 'STILLPRESS_DIFF_SEED' ) : random_int( 1, PHP_INT_MAX >> 1 ) );
		$failure    = $this->run_sequences( $seed, $iterations );
		if ( null !== $failure ) {
			$this->fail( $failure );
		}
		$this->addToAssertionCount( $iterations );
	}

	/**
	 * The differential check must be able to fail: with invalidation switched off, it has to notice.
	 */
	public function test_check_detects_broken_invalidation(): void {
		add_filter( 'stillpress_affected_url_ids', '__return_empty_array' );
		try {
			$failure = $this->run_sequences( 42, 8 );
		} finally {
			remove_filter( 'stillpress_affected_url_ids', '__return_empty_array' );
		}
		$this->assertNotNull( $failure, 'With invalidation disabled, incremental builds should have gone stale.' );
		$this->assertStringContainsString( 'stale: ', $failure );
	}

	/**
	 * Seeds content, then runs edit sequences until one diverges.
	 *
	 * @param int $seed       Random seed.
	 * @param int $iterations Number of sequences.
	 * @return string|null Failure description, or null when every sequence matched.
	 */
	private function run_sequences( int $seed, int $iterations ): ?string {
		mt_srand( $seed );
		$this->history = array();
		$this->authors = array();

		$this->seed_content();
		$this->build( 'full' );
		update_option( 'stillpress_published_once', 1 );

		$edits = $this->edits();
		$names = array_keys( $edits );
		for ( $i = 1; $i <= $iterations; $i++ ) {
			$count = mt_rand( 1, 3 );
			for ( $n = 0; $n < $count; $n++ ) {
				$name = $names[ mt_rand( 0, count( $names ) - 1 ) ];
				$desc = $edits[ $name ]();
				if ( null !== $desc ) {
					$this->history[] = "#{$i} {$name}: {$desc}";
				}
			}
			wp_cache_flush();

			$incremental = $this->build( 'incremental' );
			$after_inc   = $this->snapshot();
			$this->build( 'full' );
			$after_full = $this->snapshot();

			if ( getenv( 'STILLPRESS_DIFF_DEBUG' ) ) {
				fwrite( STDERR, sprintf( "iteration %d: %.1f MB\n", $i, memory_get_usage() / 1048576 ) );
			}
			$failure = $this->compare( $after_full, $after_inc, $seed, $i, (int) $incremental['id'] );
			if ( null !== $failure ) {
				return $failure;
			}
		}
		return null;
	}

	/**
	 * Compares two snapshots.
	 *
	 * @param array<string,string> $expected Full build.
	 * @param array<string,string> $actual   Incremental build.
	 * @param int                  $seed     Seed.
	 * @param int                  $i        Iteration.
	 * @param int                  $build_id Incremental build ID.
	 * @return string|null Readable description of the differences, or null when identical.
	 */
	private function compare( array $expected, array $actual, int $seed, int $i, int $build_id ): ?string {
		if ( $expected === $actual ) {
			return null;
		}
		$problems = array();
		foreach ( array_diff_key( $expected, $actual ) as $path => $unused ) {
			$problems[] = "missing after incremental: {$path}";
		}
		foreach ( array_diff_key( $actual, $expected ) as $path => $unused ) {
			$problems[] = "should have been removed: {$path}";
		}
		foreach ( array_intersect_key( $expected, $actual ) as $path => $contents ) {
			if ( $contents !== $actual[ $path ] ) {
				$problems[] = "stale: {$path}\n" . self::first_difference( $contents, $actual[ $path ] );
			}
		}
		return sprintf(
			"Incremental build differs from full build at iteration %d (reproduce with STILLPRESS_DIFF_SEED=%d).\n\nEdits so far:\n%s\n\nDifferences:\n%s\n\nIncremental build log:\n%s",
			$i,
			$seed,
			implode( "\n", array_slice( $this->history, -12 ) ),
			implode( "\n", array_slice( $problems, 0, 10 ) ),
			$this->log( $build_id )
		);
	}

	/**
	 * Shows the first differing line of two documents.
	 *
	 * @param string $expected Expected.
	 * @param string $actual   Actual.
	 * @return string
	 */
	private static function first_difference( string $expected, string $actual ): string {
		$a = preg_split( '/(?<=>)/', $expected );
		$b = preg_split( '/(?<=>)/', $actual );
		foreach ( $a as $n => $line ) {
			if ( ( $b[ $n ] ?? null ) !== $line ) {
				return '  full:        ' . substr( trim( $line ), 0, 200 ) . "\n  incremental: " . substr( trim( (string) ( $b[ $n ] ?? '(end)' ) ), 0, 200 );
			}
		}
		return '  (incremental is longer)';
	}

	/**
	 * Initial content: authors, categories, tags, posts over two years, a page tree, comments.
	 */
	private function seed_content(): void {
		foreach ( array( 'ana', 'ben', 'cho' ) as $login ) {
			$this->authors[] = (int) wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password(),
					'display_name' => ucfirst( $login ),
					'role'         => 'author',
				)
			);
		}
		$cats = array();
		foreach ( array( 'News', 'Guides', 'Releases', 'Events' ) as $name ) {
			$cats[] = (int) wp_insert_term( $name, 'category' )['term_id'];
		}
		foreach ( array( 'php', 'static', 'wordpress', 'speed', 'security' ) as $tag ) {
			wp_insert_term( $tag, 'post_tag' );
		}
		for ( $n = 1; $n <= 10; $n++ ) {
			$this->new_post( "Seed post {$n}" );
		}
		$parent = $this->new_page( 'Company', 0 );
		$this->new_page( 'Team', $parent );
		$this->new_page( 'Contact', 0 );
		unset( $cats );
	}

	/**
	 * Random edit operations. Each returns a description, or null if it did nothing.
	 *
	 * @return array<string,callable>
	 */
	private function edits(): array {
		return array(
			'edit title'      => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => 'Retitled ' . mt_rand( 1, 99999 ),
					)
				);
				return "post {$id}";
			},
			'edit content'    => function () {
				$id    = $this->random_post();
				$other = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				$link = $other ? '<a href="' . get_permalink( $other ) . '">related</a>' : '';
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => '<!-- wp:paragraph --><p>Body ' . mt_rand() . " {$link}</p><!-- /wp:paragraph -->",
					)
				);
				return "post {$id} links to {$other}";
			},
			'change slug'     => function () {
				$id = $this->random_post( array( 'post', 'page' ) );
				if ( ! $id ) {
					return null;
				}
				wp_update_post(
					array(
						'ID'        => $id,
						'post_name' => 'slug-' . mt_rand( 1, 99999 ),
					)
				);
				return "post {$id}";
			},
			'change category' => function () {
				$id  = $this->random_post();
				$cat = $this->random_term( 'category' );
				if ( ! $id || ! $cat ) {
					return null;
				}
				wp_set_post_categories( $id, array( $cat ) );
				return "post {$id} -> category {$cat}";
			},
			'add tag'         => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				$tag = mt_rand( 0, 3 ) ? get_term( (int) $this->random_term( 'post_tag' ) ) : null;
				$tag = $tag instanceof \WP_Term ? $tag->name : 'fresh-' . mt_rand( 1, 9999 );
				wp_set_post_tags( $id, array( $tag ), true );
				return "post {$id} + tag {$tag}";
			},
			'clear tags'      => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				wp_set_post_tags( $id, array() );
				return "post {$id}";
			},
			'new post'        => function () {
				return 'post ' . $this->new_post( 'New post ' . mt_rand( 1, 99999 ) );
			},
			'unpublish'       => function () {
				$id = $this->random_post( array( 'post', 'page' ) );
				if ( ! $id ) {
					return null;
				}
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'draft',
					)
				);
				return "post {$id}";
			},
			'republish'       => function () {
				$id = $this->random_post( array( 'post', 'page' ), 'draft' );
				if ( ! $id ) {
					return null;
				}
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'publish',
					)
				);
				return "post {$id}";
			},
			'trash'           => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				wp_trash_post( $id );
				return "post {$id}";
			},
			'delete'          => function () {
				$id = $this->random_post( array( 'post', 'page' ) );
				if ( ! $id ) {
					return null;
				}
				wp_delete_post( $id, true );
				return "post {$id}";
			},
			'change author'   => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				$author = $this->authors[ mt_rand( 0, count( $this->authors ) - 1 ) ];
				wp_update_post(
					array(
						'ID'          => $id,
						'post_author' => $author,
					)
				);
				return "post {$id} -> author {$author}";
			},
			'change date'     => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				$date = self::random_date();
				wp_update_post(
					array(
						'ID'            => $id,
						'post_date'     => $date,
						'post_date_gmt' => get_gmt_from_date( $date ),
					)
				);
				return "post {$id} -> {$date}";
			},
			'new category'    => function () {
				$term = wp_insert_term( 'Topic ' . mt_rand( 1, 99999 ), 'category' );
				return is_wp_error( $term ) ? null : 'category ' . $term['term_id'];
			},
			'rename category' => function () {
				$cat = $this->random_term( 'category' );
				if ( ! $cat ) {
					return null;
				}
				$n = mt_rand( 1, 99999 );
				wp_update_term(
					$cat,
					'category',
					array(
						'name' => "Renamed {$n}",
						'slug' => "renamed-{$n}",
					)
				);
				return "category {$cat}";
			},
			'delete category' => function () {
				$cat = $this->random_term( 'category' );
				if ( ! $cat || (int) get_option( 'default_category' ) === $cat ) {
					return null;
				}
				wp_delete_term( $cat, 'category' );
				return "category {$cat}";
			},
			'delete tag'      => function () {
				$tag = $this->random_term( 'post_tag' );
				if ( ! $tag ) {
					return null;
				}
				wp_delete_term( $tag, 'post_tag' );
				return "tag {$tag}";
			},
			'new page'        => function () {
				$parent = mt_rand( 0, 1 ) ? (int) $this->random_post( array( 'page' ) ) : 0;
				return 'page ' . $this->new_page( 'Page ' . mt_rand( 1, 99999 ), $parent );
			},
			'move page'       => function () {
				$id     = $this->random_post( array( 'page' ) );
				$parent = $this->random_post( array( 'page' ) );
				if ( ! $id || $parent === $id ) {
					return null;
				}
				wp_update_post(
					array(
						'ID'          => $id,
						'post_parent' => (int) $parent,
					)
				);
				return "page {$id} under {$parent}";
			},
			'site title'      => function () {
				update_option( 'blogname', 'Site ' . mt_rand( 1, 99999 ) );
				return 'blogname';
			},
			'tagline'         => function () {
				update_option( 'blogdescription', 'Tagline ' . mt_rand( 1, 99999 ) );
				return 'blogdescription';
			},
			'toggle sticky'   => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				if ( is_sticky( $id ) ) {
					unstick_post( $id );
				} else {
					stick_post( $id );
				}
				return "post {$id}";
			},
			'approve comment' => function () {
				$id = $this->random_post();
				if ( ! $id ) {
					return null;
				}
				$comment = wp_insert_comment(
					array(
						'comment_post_ID'      => $id,
						'comment_author'       => 'Reader',
						'comment_author_email' => 'reader@example.org',
						'comment_content'      => 'Nice ' . mt_rand(),
						'comment_approved'     => 0,
					)
				);
				wp_set_comment_status( (int) $comment, 'approve' );
				return "post {$id}";
			},
			'author name'     => function () {
				$user = $this->authors[ mt_rand( 0, count( $this->authors ) - 1 ) ];
				wp_update_user(
					array(
						'ID'           => $user,
						'display_name' => 'Writer ' . mt_rand( 1, 9999 ),
					)
				);
				return "user {$user}";
			},
			'posts per page'  => function () {
				$n = mt_rand( 2, 5 );
				update_option( 'posts_per_page', $n );
				return (string) $n;
			},
		);
	}

	private function new_post( string $title ): int {
		$cats = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$tags = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		$date = self::random_date();
		$id   = wp_insert_post(
			array(
				'post_title'    => $title,
				'post_content'  => '<!-- wp:paragraph --><p>' . $title . ' body.</p><!-- /wp:paragraph -->',
				'post_status'   => 'publish',
				'post_author'   => $this->authors[ mt_rand( 0, count( $this->authors ) - 1 ) ],
				'post_date'     => $date,
				'post_category' => $cats ? array( (int) $cats[ mt_rand( 0, count( $cats ) - 1 ) ] ) : array(),
				'tags_input'    => $tags ? array( $tags[ mt_rand( 0, count( $tags ) - 1 ) ] ) : array(),
			)
		);
		return (int) $id;
	}

	private function new_page( string $title, int $parent_id ): int {
		return (int) wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'post_author' => $this->authors[0],
			)
		);
	}

	private function random_post( array $types = array( 'post' ), string $status = 'publish' ): int {
		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => $status,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[ mt_rand( 0, count( $ids ) - 1 ) ] : 0;
	}

	private function random_term( string $taxonomy ): int {
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
			)
		);
		return $ids ? (int) $ids[ mt_rand( 0, count( $ids ) - 1 ) ] : 0;
	}

	private static function random_date(): string {
		return sprintf( '%d-%02d-%02d %02d:%02d:00', mt_rand( 2025, 2026 ), mt_rand( 1, 9 ), mt_rand( 1, 28 ), mt_rand( 0, 23 ), mt_rand( 0, 59 ) );
	}
}
