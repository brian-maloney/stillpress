<?php
/**
 * Writes the static search index files.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

use Stillpress\Build\Generated;
use Stillpress\Build\Pipeline;
use Stillpress\Storage\Schema;
use Stillpress\Storage\UrlRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * Turns the index tables into files under stillpress-search/ during finalizing (D20):
 *
 * - `terms/<hex of key>.json`: `{ term: [id, score, id, score, ...] }` for the terms of one shard.
 * - `docs/<chunk>.json`: `{ id: [public path, title, excerpt] }` for URL IDs in that chunk.
 * - `meta.json`: format version, chunk size, document count and every shard key.
 *
 * Only dirty groups and chunks are written, a group at a time, so the work resumes in the next slice when time runs
 * out. A full build marks everything dirty once, which repairs any file that went missing. Files are generated rows
 * (`stillpress:search/...`), so the manifest and deployers treat them like every other generated file.
 */
final class IndexWriter {

	/** Index format the client reads. */
	const FORMAT = 1;

	/** Output folder. */
	const DIR = 'stillpress-search';

	/** Pseudo-URL prefix of the generated rows. */
	const PSEUDO = 'stillpress:search/';

	/** Option holding the ID of the last full build whose index was marked dirty. */
	const MARKED_OPTION = 'stillpress_search_marked_build';

	/** Dirty keys fetched at a time. */
	const BATCH = 50;

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

	/**
	 * Generated-file writer.
	 *
	 * @var Generated
	 */
	private $generated;

	/**
	 * Build row.
	 *
	 * @var array<string,mixed>
	 */
	private $build;

	/**
	 * Constructor.
	 *
	 * @param Pipeline            $pipeline Components.
	 * @param UrlRepository       $urls     URLs.
	 * @param Index               $index    Index tables.
	 * @param array<string,mixed> $build    Build row.
	 */
	public function __construct( Pipeline $pipeline, UrlRepository $urls, Index $index, array $build ) {
		$this->pipeline  = $pipeline;
		$this->urls      = $urls;
		$this->index     = $index;
		$this->build     = $build;
		$this->generated = new Generated( $pipeline, $urls, (int) $build['id'] );
	}

	/**
	 * Writes what changed. Returns false when the deadline passed first; call again to continue.
	 *
	 * @param float $deadline microtime() to stop at, or 0 for no limit.
	 * @return bool
	 */
	public function write( float $deadline ): bool {
		if ( 'builtin' !== ( $this->pipeline->settings['search'] ?? 'off' ) ) {
			$this->remove_all();
			return true;
		}

		$this->index->forget_unpublished();
		if ( 'full' === $this->build['kind'] && (int) get_option( self::MARKED_OPTION, 0 ) !== (int) $this->build['id'] ) {
			$this->index->mark_all_dirty( $this->file_groups( 'terms' ), $this->file_groups( 'docs' ) );
			update_option( self::MARKED_OPTION, (int) $this->build['id'], false );
		}

		foreach ( array( 'group', 'docs' ) as $kind ) {
			do {
				$keys = $this->index->dirty( $kind, self::BATCH );
				foreach ( $keys as $key ) {
					if ( 'group' === $kind ) {
						$this->write_group( $key );
					} else {
						$this->write_chunk( (int) $key );
					}
					$this->index->clean( $kind, $key );
					if ( $deadline > 0 && microtime( true ) >= $deadline ) {
						return false;
					}
				}
			} while ( $keys );
		}

		$this->write_meta();
		return true;
	}

	/**
	 * Writes the shards of one prefix group and removes the group's shards that no longer exist.
	 *
	 * @param string $group Group key.
	 * @return void
	 */
	private function write_group( string $group ): void {
		$postings = $this->index->postings( $group );
		$counts   = array();
		foreach ( $postings as $term => $docs ) {
			$counts[ (string) $term ] = count( $docs );
		}
		$keys = ShardKeys::plan( $counts );

		$shards = array();
		foreach ( $postings as $term => $docs ) {
			$flat = array();
			foreach ( $docs as $id => $score ) {
				$flat[] = $id;
				$flat[] = $score;
			}
			$shards[ (string) ShardKeys::key_for( (string) $term, $keys ) ][ (string) $term ] = $flat;
		}

		$prefix  = self::PSEUDO . 'terms/' . bin2hex( $group ) . '/';
		$written = array();
		foreach ( $shards as $key => $terms ) {
			$pseudo    = $prefix . bin2hex( (string) $key );
			$written[] = $pseudo;
			$this->generated->write_file( $pseudo, self::DIR . '/terms/' . ShardKeys::file_name( (string) $key ), self::json( (object) $terms ), 'application/json' );
		}
		foreach ( $this->published_rows( $prefix ) as $row ) {
			if ( ! in_array( $row['url'], $written, true ) ) {
				$this->unpublish( $row );
			}
		}
	}

	/**
	 * Writes one document chunk, or removes it when it has no documents left.
	 *
	 * @param int $chunk Chunk number.
	 * @return void
	 */
	private function write_chunk( int $chunk ): void {
		$docs = array();
		foreach ( $this->index->docs( $chunk ) as $id => $doc ) {
			$path        = (string) wp_parse_url( $doc['url'], PHP_URL_PATH );
			$docs[ $id ] = array( $this->pipeline->origins->public_path_for( '' === $path ? '/' : $path ), $doc['title'], $doc['excerpt'] );
		}

		$pseudo = self::PSEUDO . 'docs/' . $chunk;
		if ( $docs ) {
			$this->generated->write_file( $pseudo, self::DIR . '/docs/' . $chunk . '.json', self::json( (object) $docs ), 'application/json' );
			return;
		}
		foreach ( $this->published_rows( $pseudo ) as $row ) {
			if ( $row['url'] === $pseudo ) {
				$this->unpublish( $row );
			}
		}
	}

	/**
	 * Writes meta.json from the shards that exist now.
	 *
	 * @return void
	 */
	private function write_meta(): void {
		$keys = array();
		foreach ( $this->published_rows( self::PSEUDO . 'terms/' ) as $row ) {
			$hex = substr( (string) strrchr( $row['url'], '/' ), 1 );
			if ( preg_match( '/^(?:[0-9a-f]{2})+$/', $hex ) ) {
				$keys[] = (string) hex2bin( $hex );
			}
		}
		sort( $keys, SORT_STRING );

		$meta = array(
			'version' => self::FORMAT,
			'chunk'   => Index::CHUNK_SIZE,
			'docs'    => $this->index->count_docs(),
			'keys'    => $keys,
		);
		$this->generated->write_file( self::PSEUDO . 'meta', self::DIR . '/meta.json', self::json( $meta ), 'application/json' );
	}

	/**
	 * Removes every index file and empties the tables (search switched off).
	 *
	 * @return void
	 */
	private function remove_all(): void {
		$rows = $this->published_rows( self::PSEUDO );
		if ( ! $rows && 0 === $this->index->count_docs() ) {
			return;
		}
		foreach ( $rows as $row ) {
			$this->unpublish( $row );
		}
		$this->index->clear();
	}

	/**
	 * Groups or chunks that have files now, read back from their pseudo-URLs.
	 *
	 * @param string $kind "terms" or "docs".
	 * @return string[]
	 */
	private function file_groups( string $kind ): array {
		$found = array();
		foreach ( $this->published_rows( self::PSEUDO . $kind . '/' ) as $row ) {
			$parts = explode( '/', substr( $row['url'], strlen( self::PSEUDO . $kind . '/' ) ) );
			if ( 'docs' === $kind ) {
				$found[] = $parts[0];
			} elseif ( preg_match( '/^(?:[0-9a-f]{2})+$/', $parts[0] ) ) {
				$found[] = (string) hex2bin( $parts[0] );
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Published generated rows whose pseudo-URL starts with a prefix.
	 *
	 * @param string $prefix Pseudo-URL prefix.
	 * @return array<int,array{id:string,url:string,path:string}>
	 */
	private function published_rows( string $prefix ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, url, path FROM %i WHERE kind = 'generated' AND url LIKE %s AND content_hash IS NOT NULL ORDER BY id", Schema::table( 'urls' ), $wpdb->esc_like( $prefix ) . '%' ),
			ARRAY_A
		);
	}

	/**
	 * Deletes a generated file and its record.
	 *
	 * @param array<string,mixed> $row Row from published_rows().
	 * @return void
	 */
	private function unpublish( array $row ): void {
		$this->pipeline->cache->delete( (string) $row['path'] );
		$this->urls->unpublish( (int) $row['id'] );
	}

	/**
	 * Encodes index JSON compactly and the same way every time.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private static function json( $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
}
