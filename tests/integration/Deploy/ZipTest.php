<?php
/**
 * ZIP archives (threat T11 and zip-slip).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\Zip;
use Stillpress\Support\Logger;
use Stillpress\Support\Paths;

final class ZipTest extends \WP_UnitTestCase {

	/** @var string */
	private $cache;

	public function set_up(): void {
		parent::set_up();
		$this->cache = sys_get_temp_dir() . '/sp-zip-' . wp_generate_password( 6, false );
		mkdir( $this->cache . '/a', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home' );
		file_put_contents( $this->cache . '/a/b.css', 'css' );
	}

	public function tear_down(): void {
		\Stillpress\Tests\Support\Fs::rmrf( $this->cache );
		parent::tear_down();
	}

	public function test_archive_contains_manifest_files_only(): void {
		$manifest = array(
			'index.html'       => 'x',
			'a/b.css'          => 'y',
			'../outside.txt'   => 'z',
			'a/../../evil.txt' => 'z',
		);
		$result   = ( new Zip() )->deploy( $manifest, $this->cache, new Logger() );
		$this->assertSame( 2, $result->added );
		$this->assertSame( Zip::current_file(), $result->location );

		$zip = new \ZipArchive();
		$zip->open( $result->location );
		$names = array();
		$count = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		sort( $names );
		$this->assertSame( array( 'a/b.css', 'index.html' ), $names );
	}

	public function test_archive_name_is_unguessable_and_directory_is_protected(): void {
		$result = ( new Zip() )->deploy( array( 'index.html' => 'x' ), $this->cache, new Logger() );
		$this->assertMatchesRegularExpression( '/site-\d+-[a-f0-9]{32}\.zip$/', $result->location );
		$this->assertFileExists( Paths::zip_dir() . '/.htaccess' );
		$this->assertFileExists( Paths::zip_dir() . '/index.php' );
	}

	public function test_previous_archive_is_replaced(): void {
		$first  = ( new Zip() )->deploy( array( 'index.html' => 'x' ), $this->cache, new Logger() )->location;
		$second = ( new Zip() )->deploy( array( 'index.html' => 'x' ), $this->cache, new Logger() )->location;
		$this->assertNotSame( $first, $second );
		$this->assertFileDoesNotExist( $first );
		$this->assertFileExists( $second );
	}
}
