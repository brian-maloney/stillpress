<?php
/**
 * Build rows.
 *
 * @package Stillpress
 */

namespace Stillpress\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; results are build state that must not be cached.

/**
 * Reads and writes rows in the builds table.
 *
 * Statuses: queued, running, done, failed, cancelled. A build is "active" while queued or running.
 */
final class BuildRepository {

	const ACTIVE_STATUSES = array( 'queued', 'running' );

	/**
	 * Creates a build row.
	 *
	 * @param string $kind           full, incremental or urls.
	 * @param string $trigger_source manual, cli, schedule or content.
	 * @param int    $user_id        User who started it (0 for system).
	 * @param bool   $deploy         Whether to deploy when rendering finishes.
	 * @return int Build ID.
	 */
	public function create( string $kind, string $trigger_source, int $user_id, bool $deploy ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'builds' ),
			array(
				'kind'           => $kind,
				'status'         => 'queued',
				'phase'          => '',
				'trigger_source' => $trigger_source,
				'user_id'        => $user_id,
				'deploy'         => $deploy ? 1 : 0,
				'created_at'     => current_time( 'mysql', true ),
				'stats'          => '{}',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetches a build.
	 *
	 * @param int $id Build ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'builds' ), $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Returns the active build (queued or running), if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function active(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1", Schema::table( 'builds' ) ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Returns true when the given build is queued or running.
	 *
	 * @param int $id Build ID.
	 * @return bool
	 */
	public function is_active( int $id ): bool {
		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Schema::table( 'builds' ), $id ) );
		return in_array( $status, self::ACTIVE_STATUSES, true );
	}

	/**
	 * Most recent builds, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::table( 'builds' ), $limit ),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Most recent finished build with the given status.
	 *
	 * @param string $status Status.
	 * @return array<string,mixed>|null
	 */
	public function last_with_status( string $status ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1', Schema::table( 'builds' ), $status ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Updates columns of a build.
	 *
	 * @param int                 $id     Build ID.
	 * @param array<string,mixed> $fields Columns to set. "stats" may be an array.
	 * @return void
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['stats'] ) && is_array( $fields['stats'] ) ) {
			$fields['stats'] = wp_json_encode( $fields['stats'] );
		}
		$wpdb->update( Schema::table( 'builds' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Moves a build from one status to another only if it is still in the expected status.
	 *
	 * @param int    $id   Build ID.
	 * @param string $from Expected current status.
	 * @param string $to   New status.
	 * @return bool True when this call made the transition.
	 */
	public function transition( int $id, string $from, string $to ): bool {
		global $wpdb;
		$fields = array( 'status' => $to );
		if ( 'running' === $to ) {
			$fields['started_at'] = current_time( 'mysql', true );
		}
		if ( in_array( $to, array( 'done', 'failed', 'cancelled' ), true ) ) {
			$fields['finished_at'] = current_time( 'mysql', true );
		}
		$updated = $wpdb->update(
			Schema::table( 'builds' ),
			$fields,
			array(
				'id'     => $id,
				'status' => $from,
			)
		);
		return 1 === $updated;
	}

	/**
	 * Merges values into a build's stats.
	 *
	 * @param int                 $id    Build ID.
	 * @param array<string,mixed> $stats Values to merge (numbers are replaced, not added).
	 * @return void
	 */
	public function merge_stats( int $id, array $stats ): void {
		$build = $this->get( $id );
		if ( null === $build ) {
			return;
		}
		$this->update(
			$id,
			array(
				'stats'        => array_merge( $build['stats'], $stats ),
				'heartbeat_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Converts a raw row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		$row['deploy']  = (bool) $row['deploy'];
		$stats          = json_decode( (string) $row['stats'], true );
		$row['stats']   = is_array( $stats ) ? $stats : array();
		return $row;
	}
}
