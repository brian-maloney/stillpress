<?php
/**
 * Known URLs and what was last produced for each.
 *
 * @package Stillpress
 */

namespace Stillpress\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; crawl state must always be read fresh.

/**
 * Reads and writes rows in the urls table.
 *
 * A URL row is "published" when it has an output path and content hash: the file exists in the build cache and
 * belongs in the deployed site.
 *
 * Kinds: page (rendered by WordPress), asset (static file), redirect, 404, generated (files we create, such
 * as _redirects).
 */
final class UrlRepository {

	/**
	 * Returns the ID for a URL, creating the row if needed.
	 *
	 * @param string $url    Canonical URL.
	 * @param string $source How it was found (seed type, crawl, additional ...).
	 * @param string $kind   Initial kind.
	 * @return int
	 */
	public function ensure( string $url, string $source = 'crawl', string $kind = 'page' ): int {
		global $wpdb;
		$table = Schema::table( 'urls' );
		$hash  = sha1( $url );
		$id    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url_hash = %s', $table, $hash ) );
		if ( $id ) {
			return (int) $id;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (url_hash, url, kind, source) VALUES (%s, %s, %s, %s)',
				$table,
				$hash,
				$url,
				$kind,
				$source
			)
		);
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url_hash = %s', $table, $hash ) );
	}

	/**
	 * Looks up a URL row by URL.
	 *
	 * @param string $url Canonical URL.
	 * @return array<string,mixed>|null
	 */
	public function find( string $url ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE url_hash = %s', Schema::table( 'urls' ), sha1( $url ) ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Fetches a row by ID.
	 *
	 * @param int $id URL ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'urls' ), $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Fetches several rows by ID.
	 *
	 * @param int[] $ids URL IDs.
	 * @return array<int,array<string,mixed>> Keyed by ID.
	 */
	public function get_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in   = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
				$wpdb->prepare( "SELECT * FROM %i WHERE id IN ($in)", array_merge( array( Schema::table( 'urls' ) ), $chunk ) ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$row               = self::hydrate( $row );
				$out[ $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Returns the published row (if any) that owns an output path.
	 *
	 * @param string $path Relative output path.
	 * @return array<string,mixed>|null
	 */
	public function find_by_path( string $path ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE path_hash = %s AND content_hash IS NOT NULL ORDER BY id ASC LIMIT 1', Schema::table( 'urls' ), sha1( $path ) ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Updates a row.
	 *
	 * @param int                 $id     URL ID.
	 * @param array<string,mixed> $fields Columns. Passing "path" also sets "path_hash".
	 * @return void
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		if ( array_key_exists( 'path', $fields ) ) {
			$fields['path_hash'] = null === $fields['path'] ? null : sha1( (string) $fields['path'] );
		}
		$wpdb->update( Schema::table( 'urls' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Marks a row as no longer published (its file leaves the cache and deployed site).
	 *
	 * @param int $id URL ID.
	 * @return void
	 */
	public function unpublish( int $id ): void {
		$this->update(
			$id,
			array(
				'path'         => null,
				'content_hash' => null,
				'size'         => 0,
			)
		);
	}

	/**
	 * Iterates over published rows (path => content hash), in ID order.
	 *
	 * @return \Generator<string,string>
	 */
	public function published_files(): \Generator {
		global $wpdb;
		$last = 0;
		do {
			$rows    = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, path, content_hash FROM %i WHERE id > %d AND content_hash IS NOT NULL AND path IS NOT NULL ORDER BY id ASC LIMIT 1000', Schema::table( 'urls' ), $last ),
				ARRAY_A
			);
			$fetched = count( (array) $rows );
			foreach ( (array) $rows as $row ) {
				$last = (int) $row['id'];
				yield (string) $row['path'] => (string) $row['content_hash'];
			}
		} while ( 1000 === $fetched );
	}

	/**
	 * Published directory indexes whose response isn't HTML, such as feeds: their file is index.html (so static hosts
	 * find it), and this is the type they should be served with.
	 *
	 * @return array<string,array{url:string,type:string}> Output path => URL and Content-Type.
	 */
	public function directory_types(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, path, content_type FROM %i WHERE content_hash IS NOT NULL AND (path = 'index.html' OR path LIKE %s) AND content_type NOT LIKE %s AND content_type <> '' ORDER BY LENGTH(path), path",
				Schema::table( 'urls' ),
				'%/index.html',
				'text/html%'
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['path'] ] = array(
				'url'  => (string) $row['url'],
				'type' => (string) $row['content_type'],
			);
		}
		return $out;
	}

	/**
	 * IDs of published rows of the given kinds.
	 *
	 * @param string[] $kinds Kinds.
	 * @return int[]
	 */
	public function published_ids( array $kinds = array( 'page', 'asset', 'redirect', '404' ) ): array {
		global $wpdb;
		$in = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
				$wpdb->prepare( "SELECT id FROM %i WHERE content_hash IS NOT NULL AND kind IN ($in) ORDER BY id", array_merge( array( Schema::table( 'urls' ) ), $kinds ) )
			)
		);
	}

	/**
	 * IDs of published rows whose dependencies couldn't be recorded.
	 *
	 * @return int[]
	 */
	public function unknown_dependency_ids(): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM %i WHERE deps_state = 'unknown' AND content_hash IS NOT NULL AND kind = 'page'", Schema::table( 'urls' ) )
			)
		);
	}

	/**
	 * Counts published rows by kind.
	 *
	 * @return array<string,int>
	 */
	public function published_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT kind, COUNT(*) AS n, SUM(size) AS bytes FROM %i WHERE content_hash IS NOT NULL GROUP BY kind', Schema::table( 'urls' ) ),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['kind'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Lists rows for display, newest render first.
	 *
	 * @param array<string,mixed> $args status (published|error|skipped|all), limit, offset, search.
	 * @return array<int,array<string,mixed>>
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		$limit  = max( 1, min( 1000, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where  = '1=1';
		$params = array( Schema::table( 'urls' ) );
		switch ( $args['status'] ?? 'all' ) {
			case 'published':
				$where = 'content_hash IS NOT NULL';
				break;
			case 'error':
				$where = 'error IS NOT NULL';
				break;
		}
		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		$params[] = $limit;
		$params[] = $offset;
		$rows     = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is built from fixed strings and placeholders.
			$wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Converts a raw row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['status_code']   = (int) $row['status_code'];
		$row['size']          = (int) $row['size'];
		$row['last_build_id'] = (int) $row['last_build_id'];
		return $row;
	}
}
