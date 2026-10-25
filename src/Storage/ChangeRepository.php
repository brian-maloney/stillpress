<?php
/**
 * Content changes waiting for an incremental build.
 *
 * @package Stillpress
 */

namespace Stillpress\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; change queue must always be read fresh.

/**
 * Rows in the changes table. A change with build_id 0 is pending; a build claims changes by setting build_id.
 */
final class ChangeRepository {

	/**
	 * Records a change.
	 *
	 * @param string              $type    post, term, user, option, comment or full.
	 * @param string              $key     Object ID or option name.
	 * @param array<string,mixed> $payload Snapshot data.
	 * @return void
	 */
	public function record( string $type, string $key, array $payload = array() ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'changes' ),
			array(
				'object_type' => $type,
				'object_key'  => substr( $key, 0, 191 ),
				'payload'     => wp_json_encode( $payload ),
				'created_at'  => current_time( 'mysql', true ),
				'build_id'    => 0,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Number of pending changes.
	 *
	 * @return int
	 */
	public function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE build_id = 0', Schema::table( 'changes' ) ) );
	}

	/**
	 * Claims every pending change for a build and returns them.
	 *
	 * @param int $build_id Build ID.
	 * @return array<int,array{object_type:string,object_key:string,payload:array<string,mixed>}>
	 */
	public function claim( int $build_id ): array {
		global $wpdb;
		$table = Schema::table( 'changes' );
		$max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE build_id = 0', $table ) );
		if ( 0 === $max ) {
			return array();
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET build_id = %d WHERE build_id = 0 AND id <= %d', $table, $build_id, $max ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, object_key, payload FROM %i WHERE build_id = %d ORDER BY id', $table, $build_id ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$payload        = json_decode( (string) $row['payload'], true );
			$row['payload'] = is_array( $payload ) ? $payload : array();
		}
		return $rows;
	}

	/**
	 * Lists pending changes (newest first) for display.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function pending( int $limit = 100 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, object_type, object_key, created_at FROM %i WHERE build_id = 0 ORDER BY id DESC LIMIT %d', Schema::table( 'changes' ), $limit ),
			ARRAY_A
		);
	}

	/**
	 * Deletes pending changes without building.
	 *
	 * @return int Rows deleted.
	 */
	public function flush(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE build_id = 0', Schema::table( 'changes' ) ) );
	}

	/**
	 * Deletes claimed changes of finished builds older than a week.
	 *
	 * @return void
	 */
	public function prune(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE build_id > 0 AND created_at < %s', Schema::table( 'changes' ), gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) )
		);
	}
}
