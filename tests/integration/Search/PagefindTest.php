<?php
/**
 * Running a Pagefind binary.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Search\Pagefind;
use Stillpress\Support\Paths;

final class PagefindTest extends \WP_UnitTestCase {

	/**
	 * Scratch folder.
	 *
	 * @var string
	 */
	private $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = get_temp_dir() . 'stillpress-pagefind-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir . '/site/about' );
		file_put_contents( $this->dir . '/site/index.html', '<html><body>Home</body></html>' );
		file_put_contents( $this->dir . '/site/about/index.html', '<html><body>About</body></html>' );
	}

	public function tear_down(): void {
		Paths::delete_tree( $this->dir );
		parent::tear_down();
	}

	private function fixture( string $name ): string {
		return dirname( __DIR__, 2 ) . '/fixtures/pagefind/' . $name;
	}

	public function test_no_constant_means_no_binary(): void {
		$this->assertNull( Pagefind::configured() );
	}

	public function test_problems_are_reported_before_anything_runs(): void {
		$this->assertStringContainsString( 'absolute path', (string) ( new Pagefind( 'pagefind' ) )->problem() );
		$this->assertStringContainsString( 'not an executable file', (string) ( new Pagefind( $this->dir . '/missing' ) )->problem() );
		$this->assertStringContainsString( 'not an executable file', (string) ( new Pagefind( $this->dir . '/site/index.html' ) )->problem() );
		$this->assertNull( ( new Pagefind( $this->fixture( 'fake-pagefind' ) ) )->problem() );
	}

	public function test_version(): void {
		$this->assertSame( 'pagefind 0.0.0-fake', ( new Pagefind( $this->fixture( 'fake-pagefind' ) ) )->version() );
		$this->assertNull( ( new Pagefind( $this->dir . '/missing' ) )->version() );
	}

	public function test_index_passes_arguments_without_a_shell_and_without_the_environment(): void {
		putenv( 'STILLPRESS_TEST_SECRET=do-not-pass' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The variable the child must not inherit.
		$site   = $this->dir . '/site';
		$output = $this->dir . '/out; touch ' . $this->dir . '/injected';
		$result = ( new Pagefind( $this->fixture( 'fake-pagefind' ) ) )->index( $site, $output );
		putenv( 'STILLPRESS_TEST_SECRET' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Clean up.

		$this->assertSame( 0, $result['code'], $result['output'] );
		$this->assertStringContainsString( 'Indexed 2 pages', $result['output'] );
		$this->assertFileExists( $output . '/pagefind.js', 'The odd folder name arrived as one argument.' );
		$this->assertFileDoesNotExist( $this->dir . '/injected' );
		$this->assertSame( implode( ' ', Pagefind::arguments( $site, $output ) ), trim( (string) file_get_contents( $output . '/args.txt' ) ) );
		$this->assertStringNotContainsString( 'STILLPRESS_TEST_SECRET', (string) file_get_contents( $output . '/env.txt' ) );
	}

	public function test_a_run_over_the_time_limit_is_stopped(): void {
		$started = microtime( true );
		$result  = ( new Pagefind( $this->fixture( 'slow-pagefind' ) ) )->index( $this->dir . '/site', $this->dir . '/out', 1 );
		$this->assertLessThan( 5, microtime( true ) - $started );
		$this->assertSame( -1, $result['code'] );
		$this->assertStringContainsString( 'stopped after 1 seconds', $result['output'] );
	}

	public function test_a_failing_run_reports_its_code_and_message(): void {
		$result = ( new Pagefind( $this->fixture( 'failing-pagefind' ) ) )->index( $this->dir . '/site', $this->dir . '/out' );
		$this->assertSame( 3, $result['code'] );
		$this->assertStringContainsString( 'no HTML files found', $result['output'] );
	}
}
