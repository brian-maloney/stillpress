<?php
/**
 * Local directory deploys (threat T5).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\LocalDirectory;
use Stillpress\Security\TargetPolicy;
use Stillpress\Support\Logger;
use Stillpress\Support\Settings;

final class LocalDirectoryTest extends \WP_UnitTestCase {

	/** @var string */
	private $cache;

	/** @var string */
	private $target;

	public function set_up(): void {
		parent::set_up();
		$base         = sys_get_temp_dir() . '/sp-deploy-' . wp_generate_password( 6, false );
		$this->cache  = $base . '/cache';
		$this->target = $base . '/site';
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		Settings::update( array( 'local_path' => $this->target ) );
	}

	public function tear_down(): void {
		\Stillpress\Tests\Support\Fs::rmrf( dirname( $this->cache ) );
		parent::tear_down();
	}

	private function manifest(): array {
		$out = array();
		foreach ( array( 'index.html', 'about/index.html' ) as $p ) {
			if ( is_file( $this->cache . '/' . $p ) ) {
				$out[ $p ] = hash_file( 'sha256', $this->cache . '/' . $p );
			}
		}
		return $out;
	}

	public function test_first_deploy_creates_files_and_marker(): void {
		$result = ( new LocalDirectory() )->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 2, $result->added );
		$this->assertSame( 'home v1', file_get_contents( $this->target . '/index.html' ) );
		$this->assertFileExists( $this->target . '/' . TargetPolicy::MARKER );
	}

	public function test_only_changes_are_written_and_removed_files_deleted(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );

		file_put_contents( $this->target . '/keep-me.txt', 'not ours' );
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/about/index.html' );

		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 0, $result->added );
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$this->assertSame( 'home v2', file_get_contents( $this->target . '/index.html' ) );
		$this->assertFileDoesNotExist( $this->target . '/about/index.html' );
		$this->assertDirectoryDoesNotExist( $this->target . '/about' );
		$this->assertFileExists( $this->target . '/keep-me.txt', 'Files the deployer did not write must survive.' );
	}

	public function test_unchanged_files_are_skipped(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 2, $result->unchanged );
		$this->assertSame( 0, $result->added + $result->modified + $result->deleted );
	}

	public function test_missing_target_file_is_restored(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		unlink( $this->target . '/index.html' );
		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 1, $result->modified );
		$this->assertFileExists( $this->target . '/index.html' );
	}

	public function test_non_empty_folder_without_marker_is_refused(): void {
		mkdir( $this->target );
		file_put_contents( $this->target . '/existing.html', 'precious' );
		$deployer = new LocalDirectory();
		$this->assertNotNull( $deployer->problem() );
		$this->expectException( \RuntimeException::class );
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	public function test_dangerous_target_is_refused(): void {
		Settings::update( array( 'local_path' => WP_CONTENT_DIR ) );
		$this->expectException( \RuntimeException::class );
		( new LocalDirectory() )->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	public function test_hostile_manifest_paths_are_skipped(): void {
		$manifest                        = $this->manifest();
		$manifest['../escape.html']      = str_repeat( 'a', 64 );
		$manifest['a/../../escape.html'] = str_repeat( 'a', 64 );
		$result                          = ( new LocalDirectory() )->deploy( $manifest, $this->cache, new Logger() );
		$this->assertSame( 2, $result->added );
		$this->assertFileDoesNotExist( dirname( $this->target ) . '/escape.html' );
	}
}
