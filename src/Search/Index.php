<?php
/**
 * The search index's tables.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

use Stillpress\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.

/**
 * Stores each page's terms and summary, and remembers which parts of the index files they changed (D20).
 *
 * - `search_terms`: (term, URL ID, score), the inverted index, read one prefix group at a time.
 * - `search_docs`: title and excerpt per URL ID.
 * - `search_dirty`: prefix groups ("group") and document chunks ("docs") whose files must be written again.
 */
final class Index {

	/** Documents per chunk file, by URL ID. */
	const CHUNK_SIZE = 500;

	/** Rows per multi-row statement. */
	const BATCH = 200;

	/**
	 * Replaces a page's terms and summary, marking what changed as dirty.
	 *
	 * @param int               $url_id  URL ID.
	 * @param string            $title   Title.
	 * @param string            $excerpt Excerpt.
	 * @param array<string,int> $scores  Term => score, from Tokenizer::scores().
	 * @return void
	 */
	public function replace( int $url_id, string $title, string $excerpt, array $scores ): void {
		global $wpdb;
		$table = Schema::table( 'search_terms' );

		$old = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT term, score FROM %i WHERE url_id = %d', $table, $url_id ), ARRAY_A ) as $row ) {
			$old[ (string) $row['term'] ] = (int) $row['score'];
		}

		$removed = array_map( 'strval', array_keys( array_diff_key( $old, $scores ) ) );
		$changed = array();
		foreach ( $scores as $term => $score ) {
			if ( ! isset( $old[ $term ] ) || $old[ $term ] !== (int) $score ) {
				$changed[ (string) $term ] = (int) $score;
			}
		}

		foreach ( array_chunk( $removed, self::BATCH ) as $batch ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
				$wpdb->prepare(
					'DELETE FROM %i WHERE url_id = %d AND term IN (' . implode( ',', array_fill( 0, count( $batch ), '%s' ) ) . ')',
					array_merge( array( $table, $url_id ), $batch )
				)
			);
		}
		foreach ( array_chunk( $changed, self::BATCH, true ) as $batch ) {
			$values = array( $table );
			foreach ( $batch as $term => $score ) {
				array_push( $values, (string) $term, $url_id, $score );
			}
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
				$wpdb->prepare(
					'INSERT INTO %i (term, url_id, score) VALUES ' . implode( ',', array_fill( 0, count( $batch ), '(%s, %d, %d)' ) ) . ' ON DUPLICATE KEY UPDATE score = VALUES(score)',
					$values
				)
			);
		}
		$this->mark_groups( array_merge( $removed, array_map( 'strval', array_keys( $changed ) ) ) );

		$doc = $wpdb->get_row( $wpdb->prepare( 'SELECT title, excerpt FROM %i WHERE url_id = %d', Schema::table( 'search_docs' ), $url_id ), ARRAY_A );
		if ( ! is_array( $doc ) || $doc['title'] !== $title || $doc['excerpt'] !== $excerpt ) {
			$wpdb->replace(
				Schema::table( 'search_docs' ),
				array(
					'url_id'  => $url_id,
					'title'   => $title,
					'excerpt' => $excerpt,
				),
				array( '%d', '%s', '%s' )
			);
			$this->mark( 'docs', array( (string) self::chunk_of( $url_id ) ) );
		}
	}

	/**
	 * Removes a page from the index.
	 *
	 * @param int $url_id URL ID.
	 * @return void
	 */
	public function forget( int $url_id ): void {
		global $wpdb;
		$terms = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT term FROM %i WHERE url_id = %d', Schema::table( 'search_terms' ), $url_id ) );
		if ( $terms ) {
			$wpdb->delete( Schema::table( 'search_terms' ), array( 'url_id' => $url_id ), array( '%d' ) );
			$this->mark_groups( array_map( 'strval', $terms ) );
		}
		if ( $wpdb->delete( Schema::table( 'search_docs' ), array( 'url_id' => $url_id ), array( '%d' ) ) ) {
			$this->mark( 'docs', array( (string) self::chunk_of( $url_id ) ) );
		}
	}

	/**
	 * Removes pages that are no longer published as HTML (gone, redirected, excluded, failed into another type).
	 *
	 * @return int Pages removed.
	 */
	public function forget_unpublished(): int {
		global $wpdb;
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT d.url_id FROM %i d LEFT JOIN %i u ON u.id = d.url_id
				WHERE u.id IS NULL OR u.kind <> 'page' OR u.status_code <> 200 OR u.content_hash IS NULL OR u.content_type NOT LIKE %s",
				Schema::table( 'search_docs' ),
				Schema::table( 'urls' ),
				'text/html%'
			)
		);
		foreach ( $ids as $id ) {
			$this->forget( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * Marks every group and chunk with data, plus those given, as dirty, so all index files are written again.
	 *
	 * @param string[] $groups Groups that have files but may have no terms left.
	 * @param string[] $chunks Chunks that have files but may have no documents left.
	 * @return void
	 */
	public function mark_all_dirty( array $groups = array(), array $chunks = array() ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (kind, dirty_key) SELECT DISTINCT 'group', LEFT(term, %d) FROM %i", Schema::table( 'search_dirty' ), ShardKeys::BASE_LENGTH, Schema::table( 'search_terms' ) ) );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (kind, dirty_key) SELECT DISTINCT 'docs', FLOOR(url_id / %d) FROM %i", Schema::table( 'search_dirty' ), self::CHUNK_SIZE, Schema::table( 'search_docs' ) ) );
		$this->mark( 'group', $groups );
		$this->mark( 'docs', $chunks );
	}

	/**
	 * Returns some dirty keys of a kind, sorted.
	 *
	 * @param string $kind  "group" or "docs".
	 * @param int    $limit Most keys.
	 * @return string[]
	 */
	public function dirty( string $kind, int $limit ): array {
		global $wpdb;
		return array_map(
			'strval',
			(array) $wpdb->get_col( $wpdb->prepare( 'SELECT dirty_key FROM %i WHERE kind = %s ORDER BY dirty_key LIMIT %d', Schema::table( 'search_dirty' ), $kind, $limit ) )
		);
	}

	/**
	 * Marks a key as written.
	 *
	 * @param string $kind "group" or "docs".
	 * @param string $key  Key.
	 * @return void
	 */
	public function clean( string $kind, string $key ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::table( 'search_dirty' ),
			array(
				'kind'      => $kind,
				'dirty_key' => $key,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Returns the postings of every term in a prefix group, terms in byte order and documents by ID.
	 *
	 * @param string $group Group key (ShardKeys::group()).
	 * @return array<string,array<int,int>> Term => (URL ID => score). Numeric terms come back as integer keys.
	 */
	public function postings( string $group ): array {
		global $wpdb;
		$rows     = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT term, url_id, score FROM %i WHERE term LIKE %s ORDER BY term, url_id', Schema::table( 'search_terms' ), $wpdb->esc_like( $group ) . '%' ),
			ARRAY_A
		);
		$postings = array();
		foreach ( $rows as $row ) {
			$term = (string) $row['term'];
			if ( ShardKeys::group( $term ) === $group ) {
				$postings[ $term ][ (int) $row['url_id'] ] = (int) $row['score'];
			}
		}
		return $postings;
	}

	/**
	 * Returns the documents of a chunk with their origin URLs, by ID.
	 *
	 * @param int $chunk Chunk number.
	 * @return array<int,array{url:string,title:string,excerpt:string}>
	 */
	public function docs( int $chunk ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.url_id, d.title, d.excerpt, u.url FROM %i d JOIN %i u ON u.id = d.url_id WHERE d.url_id >= %d AND d.url_id < %d ORDER BY d.url_id',
				Schema::table( 'search_docs' ),
				Schema::table( 'urls' ),
				$chunk * self::CHUNK_SIZE,
				( $chunk + 1 ) * self::CHUNK_SIZE
			),
			ARRAY_A
		);
		$docs = array();
		foreach ( $rows as $row ) {
			$docs[ (int) $row['url_id'] ] = array(
				'url'     => (string) $row['url'],
				'title'   => (string) $row['title'],
				'excerpt' => (string) $row['excerpt'],
			);
		}
		return $docs;
	}

	/**
	 * Number of indexed pages.
	 *
	 * @return int
	 */
	public function count_docs(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'search_docs' ) ) );
	}

	/**
	 * Empties the index.
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		foreach ( array( 'search_terms', 'search_docs', 'search_dirty' ) as $suffix ) {
			// DELETE rather than TRUNCATE, which commits any open transaction (the test suite runs in one).
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( $suffix ) ) );
		}
	}

	/**
	 * The chunk a document belongs to.
	 *
	 * @param int $url_id URL ID.
	 * @return int
	 */
	public static function chunk_of( int $url_id ): int {
		return intdiv( $url_id, self::CHUNK_SIZE );
	}

	/**
	 * Marks the groups of some terms as dirty.
	 *
	 * @param string[] $terms Terms.
	 * @return void
	 */
	private function mark_groups( array $terms ): void {
		$groups = array();
		foreach ( $terms as $term ) {
			$groups[ ShardKeys::group( $term ) ] = true;
		}
		$this->mark( 'group', array_map( 'strval', array_keys( $groups ) ) );
	}

	/**
	 * Adds dirty keys.
	 *
	 * @param string   $kind "group" or "docs".
	 * @param string[] $keys Keys.
	 * @return void
	 */
	private function mark( string $kind, array $keys ): void {
		global $wpdb;
		foreach ( array_chunk( array_values( array_unique( $keys ) ), self::BATCH ) as $batch ) {
			$values = array( Schema::table( 'search_dirty' ) );
			foreach ( $batch as $key ) {
				array_push( $values, $kind, (string) $key );
			}
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (kind, dirty_key) VALUES ' . implode( ',', array_fill( 0, count( $batch ), '(%s, %s)' ) ),
					$values
				)
			);
		}
	}
}
