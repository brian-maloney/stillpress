<?php
/**
 * Build log.
 *
 * @package Stillpress
 */

namespace Stillpress\Support;

use Stillpress\Security\Redactor;
use Stillpress\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * Writes build log lines to the log table (scrubbed by the Redactor) and, under WP-CLI, to the console.
 *
 * Messages are plain text. They may contain data from crawled pages, so every screen that shows them escapes them.
 */
final class Logger {

	/** Lines kept per build. */
	const MAX_LINES_PER_BUILD = 2000;

	/**
	 * Build ID (0 for messages outside a build).
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * Lines written in this request, to enforce the cap without a count query per line.
	 *
	 * @var int
	 */
	private $written = 0;

	/**
	 * Whether to echo to WP-CLI.
	 *
	 * @var bool
	 */
	private static $cli_echo = false;

	/**
	 * Constructor.
	 *
	 * @param int $build_id Build ID.
	 */
	public function __construct( int $build_id = 0 ) {
		$this->build_id = $build_id;
	}

	/**
	 * Turns WP-CLI echoing on or off.
	 *
	 * @param bool $on Echo to the console.
	 * @return void
	 */
	public static function echo_to_cli( bool $on ): void {
		self::$cli_echo = $on;
	}

	/**
	 * Logs an informational message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function info( string $message ): void {
		$this->log( 'info', $message );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( string $message ): void {
		$this->log( 'warning', $message );
	}

	/**
	 * Logs an error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( string $message ): void {
		$this->log( 'error', $message );
	}

	/**
	 * Writes a line.
	 *
	 * @param string $level   info, warning or error.
	 * @param string $message Message.
	 * @return void
	 */
	public function log( string $level, string $message ): void {
		global $wpdb;
		$message = Redactor::scrub( mb_substr( $message, 0, 2000 ) );

		if ( self::$cli_echo && defined( 'WP_CLI' ) && WP_CLI ) {
			$line = sprintf( '[%s] %s', $level, $message );
			if ( 'error' === $level || 'warning' === $level ) {
				\WP_CLI::warning( $message );
			} else {
				\WP_CLI::log( $line );
			}
		}

		if ( $this->written >= self::MAX_LINES_PER_BUILD ) {
			return;
		}
		++$this->written;
		$wpdb->insert(
			Schema::table( 'log' ),
			array(
				'build_id'   => $this->build_id,
				'level'      => $level,
				'message'    => $message,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Reads log lines for a build.
	 *
	 * @param int $build_id Build ID.
	 * @param int $limit    Maximum lines.
	 * @return array<int,array{level:string,message:string,created_at:string}>
	 */
	public static function lines( int $build_id, int $limit = 500 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT level, message, created_at FROM %i WHERE build_id = %d ORDER BY id ASC LIMIT %d', Schema::table( 'log' ), $build_id, $limit ),
			ARRAY_A
		);
	}

	/**
	 * Deletes log lines of builds older than the newest $keep builds.
	 *
	 * @param int $keep Builds to keep.
	 * @return void
	 */
	public static function prune( int $keep = 50 ): void {
		global $wpdb;
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', Schema::table( 'builds' ), $keep ) );
		if ( $cutoff > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE build_id <= %d', Schema::table( 'log' ), $cutoff ) );
		}
	}
}
