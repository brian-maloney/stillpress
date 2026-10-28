<?php
/**
 * Path traversal and mapping tests (threat T2).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;

final class PathMapperTest extends TestCase {

	/** @var string */
	private $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/stillpress-pm-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp . '/root', 0777, true );
		mkdir( $this->tmp . '/outside', 0777, true );
	}

	protected function tearDown(): void {
		\Stillpress\Tests\Support\Fs::rmrf( $this->tmp );
	}

	private function mapper(): PathMapper {
		return new PathMapper( $this->tmp . '/root' );
	}

	/**
	 * @dataProvider provide_valid_mappings
	 */
	public function test_maps_url_paths_to_relative_files( string $url_path, string $type, string $expected ): void {
		$this->assertSame( $expected, $this->mapper()->relative_path( $url_path ) );
	}

	public function provide_valid_mappings(): array {
		return array(
			'root'                     => array( '/', 'text/html; charset=UTF-8', 'index.html' ),
			'empty is root'            => array( '', 'text/html', 'index.html' ),
			'pretty permalink'         => array( '/2026/10/25/hello-world/', 'text/html', '2026/10/25/hello-world/index.html' ),
			'no trailing slash'        => array( '/about', 'text/html', 'about/index.html' ),
			'file with extension'      => array( '/robots.txt', 'text/plain', 'robots.txt' ),
			'asset'                    => array( '/wp-content/uploads/2026/10/a.jpg', 'image/jpeg', 'wp-content/uploads/2026/10/a.jpg' ),
			'feed directory'           => array( '/feed/', 'application/rss+xml; charset=UTF-8', 'feed/index.html' ),
			'atom feed'                => array( '/feed/atom/', 'application/atom+xml', 'feed/atom/index.html' ),
			'json directory'           => array( '/data/', 'application/ld+json', 'data/index.html' ),
			'text directory'           => array( '/notes/', 'text/plain', 'notes/index.html' ),
			'sitemap file'             => array( '/wp-sitemap.xml', 'application/xml', 'wp-sitemap.xml' ),
			'percent-encoded space'    => array( '/wp-content/uploads/my%20file.pdf', 'application/pdf', 'wp-content/uploads/my file.pdf' ),
			'unicode slug'             => array( '/%E6%97%A5%E6%9C%AC/', 'text/html', '日本/index.html' ),
			'dot inside name'          => array( '/v1.2/release/', 'text/html', 'v1.2/release/index.html' ),
			'extension then slash'     => array( '/file.html/', 'text/html', 'file.html/index.html' ),
			'unknown type is html'     => array( '/x/', '', 'x/index.html' ),
			'dotfile-like but has ext' => array( '/a/.well-known.txt', 'text/plain', 'a/.well-known.txt' ),
		);
	}

	/**
	 * @dataProvider provide_hostile_paths
	 */
	public function test_rejects_hostile_url_paths( string $url_path ): void {
		$this->expectException( UnsafePathException::class );
		$this->mapper()->relative_path( $url_path );
	}

	public function provide_hostile_paths(): array {
		return array(
			'parent segment'               => array( '/../wp-config.php' ),
			'nested parent'                => array( '/a/b/../../../etc/passwd' ),
			'parent at end'                => array( '/a/..' ),
			'dot segment'                  => array( '/a/./b' ),
			'encoded parent'               => array( '/%2e%2e/wp-config.php' ),
			'mixed-case encoded parent'    => array( '/%2E%2e/x' ),
			'half-encoded parent'          => array( '/.%2e/x' ),
			'encoded slash traversal'      => array( '/..%2f..%2fetc/passwd' ),
			'encoded slash inside segment' => array( '/a%2Fb/' ),
			'encoded backslash'            => array( '/..%5c..%5cwin.ini' ),
			'raw backslash'                => array( '/a\\..\\b' ),
			'NUL byte raw'                 => array( "/a\0.html" ),
			'NUL byte encoded'             => array( '/index.php%00.html' ),
			'control char encoded'         => array( '/a%0A/' ),
			'DEL encoded'                  => array( '/a%7F/' ),
			'double slash'                 => array( '//etc/passwd' ),
			'double slash only'            => array( '//' ),
			'empty middle segment'         => array( '/a//b/' ),
			'no leading slash'             => array( 'etc/passwd' ),
			'query string'                 => array( '/a?b=c' ),
			'fragment'                     => array( '/a#b' ),
			'drive letter'                 => array( '/C:/Windows/' ),
			'encoded colon (NTFS ADS)'     => array( '/file.txt%3A%3A%24DATA' ),
			'windows device name'          => array( '/CON/' ),
			'windows device with ext'      => array( '/aux.txt' ),
			'windows device COM1'          => array( '/com1/' ),
			'trailing dot'                 => array( '/a./' ),
			'trailing space'               => array( '/a%20/' ),
			'encoded double-dot only'      => array( '/%2e%2e' ),
			'reserved wildcard'            => array( '/a%2A/' ),
			'pipe'                         => array( '/a%7Cb/' ),
			'invalid utf-8'                => array( '/%C3%28/' ),
			'overlong segment'             => array( '/' . str_repeat( 'a', 256 ) . '/' ),
			'too deep'                     => array( str_repeat( '/a', 40 ) . '/' ),
		);
	}

	public function test_double_encoding_is_decoded_only_once(): void {
		// "%252e%252e" decodes once to the literal name "%2e%2e", which is a harmless file name.
		$this->assertSame( '%2e%2e/index.html', $this->mapper()->relative_path( '/%252e%252e/' ) );
	}

	public function test_absolute_path_stays_in_root(): void {
		$mapper = $this->mapper();
		$this->assertSame( $this->tmp . '/root/a/b/index.html', $mapper->absolute_path( 'a/b/index.html' ) );
	}

	/**
	 * @dataProvider provide_hostile_relative
	 */
	public function test_absolute_path_rejects_hostile_relative_paths( string $relative ): void {
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( $relative );
	}

	public function provide_hostile_relative(): array {
		return array(
			'empty'            => array( '' ),
			'absolute'         => array( '/etc/passwd' ),
			'parent'           => array( '../outside/x.html' ),
			'nested parent'    => array( 'a/../../outside/x.html' ),
			'backslash'        => array( 'a\\b' ),
			'nul'              => array( "a\0b" ),
			'dot'              => array( './a' ),
			'trailing slash'   => array( 'a/' ),
			'zip slip windows' => array( 'C:/x' ),
		);
	}

	public function test_refuses_symlinked_directory_inside_root(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		symlink( $this->tmp . '/outside', $this->tmp . '/root/link' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( 'link/index.html' );
	}

	public function test_refuses_symlinked_file_inside_root(): void {
		file_put_contents( $this->tmp . '/outside/secret.txt', 'x' );
		symlink( $this->tmp . '/outside/secret.txt', $this->tmp . '/root/secret.txt' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( 'secret.txt' );
	}

	public function test_refuses_path_through_a_file(): void {
		file_put_contents( $this->tmp . '/root/a', 'x' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( 'a/index.html' );
	}

	public function test_root_that_is_itself_a_symlink_is_allowed(): void {
		// Hosting setups often symlink the document root; only links *inside* the root are refused.
		symlink( $this->tmp . '/root', $this->tmp . '/root-link' );
		$mapper = new PathMapper( $this->tmp . '/root-link' );
		$this->assertSame( $this->tmp . '/root-link/a.html', $mapper->absolute_path( 'a.html' ) );
	}

	public function test_root_must_be_absolute(): void {
		$this->expectException( UnsafePathException::class );
		new PathMapper( 'relative/dir' );
	}

	public function test_root_must_not_contain_dot_segments(): void {
		$this->expectException( UnsafePathException::class );
		new PathMapper( '/var/www/../etc' );
	}

	public function test_fuzzed_paths_never_escape_root(): void {
		$alphabet = array( '/', '.', '..', '%2e', '%2f', '%5c', 'a', '%00', '%252e', ':', '~', ' ', '%20' );
		$mapper   = $this->mapper();
		mt_srand( 1234 );
		for ( $i = 0; $i < 5000; $i++ ) {
			$path = '/';
			$len  = mt_rand( 1, 8 );
			for ( $j = 0; $j < $len; $j++ ) {
				$path .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ];
			}
			try {
				$relative = $mapper->relative_path( $path );
				$absolute = $mapper->absolute_path( $relative );
			} catch ( UnsafePathException $e ) {
				continue;
			}
			$this->assertStringStartsWith( $this->tmp . '/root/', $absolute, "Escaped for input {$path}" );
			$this->assertStringNotContainsString( '/../', '/' . $relative . '/', "Dot-dot in output for {$path}" );
			$this->assertNotSame( '..', basename( $relative ) );
		}
	}
}
