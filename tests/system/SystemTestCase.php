<?php
/**
 * Base class for system tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

use PHPUnit\Framework\TestCase;
use Stillpress\Build\BuildService;
use Stillpress\Plugin;
use Stillpress\Storage\Schema;
use Stillpress\Support\Paths;
use Stillpress\Support\Settings;
use Stillpress\Tests\Support\Fs;

/**
 * Resets the site to a known state and runs real builds.
 */
abstract class SystemTestCase extends TestCase {

	const PUBLIC_URL = 'https://static.example.org';

	/** Theme the site runs during the test. */
	const THEME = 'twentytwentyfive';

	/**
	 * Deploy target for this test.
	 *
	 * @var string
	 */
	protected $target;

	protected function setUp(): void {
		parent::setUp();
		$this->target = sys_get_temp_dir() . '/stillpress-system-' . getmypid();
		$this->reset_site();
	}

	protected function tearDown(): void {
		Fs::rmrf( $this->target );
		parent::tearDown();
	}

	/**
	 * Erases content and plugin state.
	 */
	protected function reset_site(): void {
		global $wpdb;
		$listener = Plugin::instance()->changes();

		// Start from a clean baseline on the web side too: other suites (the visual diffs) activate page builders.
		update_option( 'active_plugins', array( 'stillpress/stillpress.php' ) );

		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( get_taxonomies() as $taxonomy ) {
			foreach ( get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			) as $term_id ) {
				if ( 'category' === $taxonomy && 1 === (int) $term_id ) {
					continue;
				}
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			if ( 1 !== (int) $user_id ) {
				wp_delete_user( (int) $user_id );
			}
		}
		$wpdb->query( "DELETE FROM {$wpdb->comments}" );

		switch_theme( static::THEME );
		update_option( 'blogname', 'System Test Site' );
		update_option( 'blogdescription', 'Just another static site' );
		update_option( 'show_on_front', 'posts' );
		update_option( 'posts_per_page', 3 );
		update_option( 'wp_attachment_pages_enabled', 0 );
		update_option( 'sticky_posts', array() );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'category_base', '' );
		update_option( 'tag_base', '' );
		flush_rewrite_rules();

		foreach ( Schema::TABLES as $table ) {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::table( $table ) ) );
		}
		delete_option( 'stillpress_published_once' );
		Fs::rmrf( Paths::cache_dir() );
		Fs::rmrf( $this->target );

		delete_option( Settings::OPTION );
		delete_option( \Stillpress\Security\Secrets::OPTION );
		Settings::update(
			array(
				'public_url'   => self::PUBLIC_URL,
				'target'       => 'local',
				'local_path'   => $this->target,
				'publish_mode' => 'manual',
				'concurrency'  => 4,
			)
		);
		if ( null !== $listener ) {
			$listener->flush();
		}
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::table( 'changes' ) ) );
		wp_cache_flush();
	}

	/**
	 * Runs a build to completion and returns the final row.
	 *
	 * @param string $kind full or incremental.
	 * @return array<string,mixed>
	 */
	protected function build( string $kind = 'full' ): array {
		$service = new BuildService();
		$id      = $service->start( $kind, 'cli', 0, true, array( 'sync' => true ) );
		$build   = $service->run_sync( $id );
		$this->assertNotNull( $build );
		$this->assertSame( 'done', $build['status'], 'Build failed: ' . ( $build['error'] ?? '' ) . "\n" . $this->log( (int) $build['id'] ) );
		wp_cache_flush();
		return $build;
	}

	/**
	 * Build log as text.
	 *
	 * @param int $build_id Build ID.
	 * @return string
	 */
	protected function log( int $build_id ): string {
		$lines = array();
		foreach ( \Stillpress\Support\Logger::lines( $build_id ) as $line ) {
			$lines[] = $line['level'] . ': ' . $line['message'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Every deployed file: path => contents.
	 *
	 * @return array<string,string>
	 */
	protected function snapshot(): array {
		$out = array();
		if ( ! is_dir( $this->target ) ) {
			return $out;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->target, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$relative = substr( $file->getPathname(), strlen( $this->target ) + 1 );
			if ( '.stillpress-target' !== $relative ) {
				$out[ $relative ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Deployed HTML for a public path such as "/about/".
	 *
	 * @param string $path Path.
	 * @return string|null
	 */
	protected function page( string $path ): ?string {
		$file = $this->target . rtrim( $path, '/' ) . '/index.html';
		return is_file( $file ) ? (string) file_get_contents( $file ) : null;
	}
}
