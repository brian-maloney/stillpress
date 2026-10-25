<?php
/**
 * Schema installation.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Storage;

use Stillpress\Storage\Schema;

final class SchemaTest extends \WP_UnitTestCase {

	public function test_all_tables_exist(): void {
		global $wpdb;
		foreach ( Schema::TABLES as $suffix ) {
			$table = Schema::table( $suffix );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing {$table}" );
		}
	}

	public function test_version_option_is_stored_without_autoload(): void {
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::OPTION ) );
	}

	public function test_install_is_idempotent(): void {
		global $wpdb;
		Schema::install();
		Schema::install();
		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::table( 'urls' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'deps_state', $columns );
		$this->assertSame( count( array_unique( $columns ) ), count( $columns ) );
	}
}
