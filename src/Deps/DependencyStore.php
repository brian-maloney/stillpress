<?php
/**
 * Stores what each URL depended on when it was last rendered.
 *
 * @package Stillpress
 */

namespace Stillpress\Deps;

use Stillpress\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; dependency data must always be read fresh.

/**
 * The inverted dependency map: (type, key) → URL IDs.
 *
 * Types:
 * - post   key = post ID
 * - term   key = term ID
 * - user   key = user ID
 * - option key = option name
 * - query  key = query signature hash (see query_sigs table)
 * - tq     key = taxonomy name (a term query ran on this taxonomy)
 * - uq     key = "*" (a user query ran)
 * - cq     key = "*" (a comment query ran)
 */
final class DependencyStore {

	/** Hard cap on dependency rows per URL; beyond it the URL is treated as depending on everything. */
	const MAX_PER_URL = 20000;

	const TYPES = array( 'post', 'term', 'user', 'option', 'query', 'tq', 'uq', 'cq' );

	/**
	 * Replaces the dependencies recorded for a URL.
	 *
	 * @param int                                 $url_id     URL ID.
	 * @param array<string,array<int,string|int>> $deps   Keys by type.
	 * @param array<string,array<string,mixed>>   $sigs   Query signatures by hash.
	 * @return bool False when the cap was hit (caller should mark the URL's dependencies unknown).
	 */
	public function replace( int $url_id, array $deps, array $sigs = array() ): bool {
		global $wpdb;
		$table = Schema::table( 'deps' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE url_id = %d', $table, $url_id ) );

		$rows = array();
		foreach ( $deps as $type => $keys ) {
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			foreach ( array_unique( array_map( 'strval', $keys ) ) as $key ) {
				if ( '' === $key || strlen( $key ) > 191 ) {
					continue;
				}
				$rows[] = array( $type, $key );
			}
		}
		if ( count( $rows ) > self::MAX_PER_URL ) {
			return false;
		}

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = array();
			$values       = array( $table );
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%d, %s, %s)';
				$values[]       = $url_id;
				$values[]       = $row[0];
				$values[]       = $row[1];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above.
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (url_id, dep_type, dep_key) VALUES ' . implode( ', ', $placeholders ), $values ) );
		}

		$sig_table = Schema::table( 'query_sigs' );
		foreach ( $sigs as $hash => $sig ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (sig_hash, sig) VALUES (%s, %s)', $sig_table, (string) $hash, (string) wp_json_encode( $sig ) ) );
		}
		return true;
	}

	/**
	 * Deletes a URL's dependencies.
	 *
	 * @param int $url_id URL ID.
	 * @return void
	 */
	public function forget( int $url_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE url_id = %d', Schema::table( 'deps' ), $url_id ) );
	}

	/**
	 * Returns the dependencies of one URL, grouped by type.
	 *
	 * @param int $url_id URL ID.
	 * @return array<string,string[]>
	 */
	public function for_url( int $url_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT dep_type, dep_key FROM %i WHERE url_id = %d ORDER BY dep_type, dep_key', Schema::table( 'deps' ), $url_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['dep_type'] ][] = $row['dep_key'];
		}
		return $out;
	}

	/**
	 * URL IDs that depend on any of the given keys of a type.
	 *
	 * @param string                $type Dependency type.
	 * @param array<int,string|int> $keys Keys.
	 * @return int[]
	 */
	public function urls_for( string $type, array $keys ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		if ( ! $keys ) {
			return array();
		}
		$ids = array();
		foreach ( array_chunk( $keys, 500 ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
			$sql = $wpdb->prepare( "SELECT DISTINCT url_id FROM %i WHERE dep_type = %s AND dep_key IN ($in)", array_merge( array( Schema::table( 'deps' ), $type ), $chunk ) );
			$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $sql ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Iterates over every stored query signature that is still referenced by a URL.
	 *
	 * @return \Generator<string,array<string,mixed>> Hash => signature.
	 */
	public function signatures(): \Generator {
		global $wpdb;
		$last = '';
		do {
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT s.sig_hash, s.sig FROM %i s WHERE s.sig_hash > %s AND EXISTS (SELECT 1 FROM %i d WHERE d.dep_type = %s AND d.dep_key = s.sig_hash) ORDER BY s.sig_hash LIMIT 500',
					Schema::table( 'query_sigs' ),
					$last,
					Schema::table( 'deps' ),
					'query'
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );
			foreach ( (array) $rows as $row ) {
				$last = $row['sig_hash'];
				$sig  = json_decode( (string) $row['sig'], true );
				if ( is_array( $sig ) ) {
					yield $row['sig_hash'] => $sig;
				}
			}
		} while ( 500 === $fetched );
	}

	/**
	 * Removes signatures no URL references any more.
	 *
	 * @return void
	 */
	public function prune_signatures(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE s FROM %i s LEFT JOIN %i d ON d.dep_type = %s AND d.dep_key = s.sig_hash WHERE d.url_id IS NULL',
				Schema::table( 'query_sigs' ),
				Schema::table( 'deps' ),
				'query'
			)
		);
	}
}
