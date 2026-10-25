<?php
/**
 * Prevents two queue slices from working on builds at the same time.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Atomic compare-and-set on the options table; the options API has no such primitive.

/**
 * A lease stored in the options table. Acquiring is atomic (INSERT IGNORE or conditional UPDATE), and the lease
 * expires on its own if a PHP process dies while holding it.
 */
final class Lock {

	/** Option name. */
	const OPTION = 'stillpress_build_lock';

	/**
	 * Lock token held by this process.
	 *
	 * @var string
	 */
	private $token = '';

	/**
	 * Tries to acquire the lease.
	 *
	 * @param int $ttl Seconds before the lease expires on its own.
	 * @return bool
	 */
	public function acquire( int $ttl ): bool {
		global $wpdb;
		$token = bin2hex( random_bytes( 8 ) );
		$value = ( time() + $ttl ) . ':' . $token;

		$inserted = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION, $value )
		);
		if ( 1 !== (int) $inserted ) {
			// Take over an expired lease. The lease value starts with its expiry time.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
					$value,
					self::OPTION,
					time()
				)
			);
			if ( 1 !== (int) $updated ) {
				return false;
			}
		}
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$this->token = $token;
		return true;
	}

	/**
	 * Releases the lease if this process holds it.
	 *
	 * @return void
	 */
	public function release(): void {
		global $wpdb;
		if ( '' === $this->token ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::OPTION, '%:' . $wpdb->esc_like( $this->token ) )
		);
		wp_cache_delete( self::OPTION, 'options' );
		$this->token = '';
	}
}
