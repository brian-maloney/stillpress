<?php
/**
 * The set of URLs a build still has to process.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; work queue state must always be read fresh.

/**
 * A per-build work queue stored in the build_urls table. Because it lives in the database, a build can stop at
 * any point (timeout, crash, deploy) and resume exactly where it was.
 *
 * States: pending, running, done, failed, skipped.
 */
final class Frontier {

	/**
	 * Build ID.
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * Constructor.
	 *
	 * @param int $build_id Build ID.
	 */
	public function __construct( int $build_id ) {
		$this->build_id = $build_id;
	}

	/**
	 * Adds URLs to the queue. URLs already queued in this build are ignored.
	 *
	 * @param int[] $url_ids URL IDs.
	 * @param int   $depth   Link depth from the seeds.
	 * @return int Number of URLs newly queued.
	 */
	public function add( array $url_ids, int $depth = 0 ): int {
		global $wpdb;
		$url_ids = array_values( array_unique( array_filter( array_map( 'intval', $url_ids ) ) ) );
		$added   = 0;
		foreach ( array_chunk( $url_ids, 500 ) as $chunk ) {
			$placeholders = array();
			$values       = array( Schema::table( 'build_urls' ) );
			foreach ( $chunk as $id ) {
				$placeholders[] = '(%d, %d, %d)';
				$values[]       = $this->build_id;
				$values[]       = $id;
				$values[]       = $depth;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$added += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (build_id, url_id, depth) VALUES ' . implode( ', ', $placeholders ), $values ) );
		}
		return $added;
	}

	/**
	 * Claims the next pending URLs.
	 *
	 * @param int $limit Maximum number.
	 * @return array<int,int> URL ID => depth.
	 */
	public function claim( int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'build_urls' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT url_id, depth FROM %i WHERE build_id = %d AND state = 'pending' ORDER BY depth ASC, url_id ASC LIMIT %d", $table, $this->build_id, $limit ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['url_id'] ] = (int) $row['depth'];
		}
		if ( $out ) {
			$in = implode( ', ', array_fill( 0, count( $out ), '%d' ) );
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
				$wpdb->prepare( "UPDATE %i SET state = 'running', attempts = attempts + 1 WHERE build_id = %d AND url_id IN ($in)", array_merge( array( $table, $this->build_id ), array_keys( $out ) ) )
			);
		}
		return $out;
	}

	/**
	 * Sets the state of one URL.
	 *
	 * @param int    $url_id URL ID.
	 * @param string $state  done, failed or skipped.
	 * @return void
	 */
	public function mark( int $url_id, string $state ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'build_urls' ),
			array( 'state' => $state ),
			array(
				'build_id' => $this->build_id,
				'url_id'   => $url_id,
			)
		);
	}

	/**
	 * Puts URLs left "running" by an interrupted slice back to pending, unless they failed too often.
	 *
	 * @param int $max_attempts Attempts before giving up on a URL.
	 * @return void
	 */
	public function recover( int $max_attempts = 3 ): void {
		global $wpdb;
		$table = Schema::table( 'build_urls' );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed' WHERE build_id = %d AND state = 'running' AND attempts >= %d", $table, $this->build_id, $max_attempts ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'pending' WHERE build_id = %d AND state = 'running'", $table, $this->build_id ) );
	}

	/**
	 * True when the URL is already in this build's queue (in any state).
	 *
	 * @param int $url_id URL ID.
	 * @return bool
	 */
	public function has( int $url_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE build_id = %d AND url_id = %d', Schema::table( 'build_urls' ), $this->build_id, $url_id ) );
	}

	/**
	 * Number of URLs in each state.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT state, COUNT(*) AS n FROM %i WHERE build_id = %d GROUP BY state', Schema::table( 'build_urls' ), $this->build_id ),
			ARRAY_A
		);
		$out = array(
			'pending' => 0,
			'running' => 0,
			'done'    => 0,
			'failed'  => 0,
			'skipped' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['state'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Total URLs queued in this build.
	 *
	 * @return int
	 */
	public function total(): int {
		return array_sum( $this->counts() );
	}

	/**
	 * URL IDs processed in this build with the given state.
	 *
	 * @param string $state State.
	 * @return int[]
	 */
	public function ids_in_state( string $state ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( 'SELECT url_id FROM %i WHERE build_id = %d AND state = %s', Schema::table( 'build_urls' ), $this->build_id, $state ) )
		);
	}

	/**
	 * Deletes this build's queue (after the build finishes).
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE build_id = %d', Schema::table( 'build_urls' ), $this->build_id ) );
	}
}
