<?php
/**
 * What each deploy target currently holds.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * Remembers which files (and which versions) a target received, so deploys only send changes and only delete
 * files they put there.
 */
final class TargetFiles {

	/**
	 * Target key.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Constructor.
	 *
	 * @param string $target Target key (deployer ID plus a hash of its destination), at most 40 characters.
	 */
	public function __construct( string $target ) {
		$this->target = substr( $target, 0, 40 );
	}

	/**
	 * Everything the target holds.
	 *
	 * @return array<string,string> Path => SHA-256.
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT path, content_hash FROM %i WHERE target = %s', Schema::table( 'target_files' ), $this->target ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['path'] ] = (string) $row['content_hash'];
		}
		return $out;
	}

	/**
	 * Records a file.
	 *
	 * @param string $path Relative path.
	 * @param string $hash SHA-256.
	 * @return void
	 */
	public function set( string $path, string $hash ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'REPLACE INTO %i (target, path_hash, path, content_hash) VALUES (%s, %s, %s, %s)',
				Schema::table( 'target_files' ),
				$this->target,
				sha1( $path ),
				$path,
				$hash
			)
		);
	}

	/**
	 * Forgets a file.
	 *
	 * @param string $path Relative path.
	 * @return void
	 */
	public function remove( string $path ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::table( 'target_files' ),
			array(
				'target'    => $this->target,
				'path_hash' => sha1( $path ),
			)
		);
	}

	/**
	 * Forgets everything for this target.
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'target_files' ), array( 'target' => $this->target ) );
	}
}
