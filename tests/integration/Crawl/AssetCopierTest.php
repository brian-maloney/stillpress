<?php
/**
 * Copying files from disk safely (threat T3).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Crawl;

use Stillpress\Crawl\AssetCopier;

final class AssetCopierTest extends \WP_UnitTestCase {

	/** @var string */
	private $root;

	/** @var string */
	private $outside;

	public function set_up(): void {
		parent::set_up();
		$this->root    = sys_get_temp_dir() . '/sp-assets-' . wp_generate_password( 6, false );
		$this->outside = sys_get_temp_dir() . '/sp-outside-' . wp_generate_password( 6, false );
		mkdir( $this->root . '/2026/10', 0777, true );
		mkdir( $this->root . '/.git', 0777, true );
		mkdir( $this->outside );
		foreach ( array( '2026/10/photo.jpg', '2026/10/doc.pdf', 'shell.php', 'shell.php5', 'shell.phtml', '.env', '.git/config', 'wp-config.php.bak', 'dump.sql', 'debug.log', 'notes.txt', 'style.css', 'backup.css~', 'my file.png' ) as $f ) {
			file_put_contents( $this->root . '/' . $f, 'x' );
		}
		file_put_contents( $this->outside . '/secret.jpg', 'secret' );
		symlink( $this->outside . '/secret.jpg', $this->root . '/linked.jpg' );
		symlink( $this->outside, $this->root . '/linkdir' );
	}

	public function tear_down(): void {
		\Stillpress\Tests\Support\Fs::rmrf( $this->root );
		\Stillpress\Tests\Support\Fs::rmrf( $this->outside );
		parent::tear_down();
	}

	private function copier(): AssetCopier {
		return new AssetCopier(
			array(
				'/wp-content/uploads' => $this->root,
				'/wp-content'         => '/nonexistent',
			)
		);
	}

	public function test_allows_media_inside_root(): void {
		$this->assertSame( realpath( $this->root . '/2026/10/photo.jpg' ), $this->copier()->source_for( '/wp-content/uploads/2026/10/photo.jpg' ) );
		$this->assertSame( realpath( $this->root . '/my file.png' ), $this->copier()->source_for( '/wp-content/uploads/my%20file.png' ) );
		$this->assertNotNull( $this->copier()->source_for( '/wp-content/uploads/style.css' ) );
	}

	/**
	 * @dataProvider provide_refused
	 */
	public function test_refuses( string $path ): void {
		$this->assertNull( $this->copier()->source_for( $path ) );
	}

	public function provide_refused(): array {
		return array(
			'php'                 => array( '/wp-content/uploads/shell.php' ),
			'php5'                => array( '/wp-content/uploads/shell.php5' ),
			'phtml'               => array( '/wp-content/uploads/shell.phtml' ),
			'dotenv'              => array( '/wp-content/uploads/.env' ),
			'dot directory'       => array( '/wp-content/uploads/.git/config' ),
			'config backup'       => array( '/wp-content/uploads/wp-config.php.bak' ),
			'sql dump'            => array( '/wp-content/uploads/dump.sql' ),
			'log'                 => array( '/wp-content/uploads/debug.log' ),
			'txt not allowlisted' => array( '/wp-content/uploads/notes.txt' ),
			'editor backup'       => array( '/wp-content/uploads/backup.css~' ),
			'missing'             => array( '/wp-content/uploads/none.jpg' ),
			'traversal'           => array( '/wp-content/uploads/../../../etc/passwd' ),
			'encoded traversal'   => array( '/wp-content/uploads/%2e%2e/%2e%2e/etc/hosts' ),
			'symlinked file'      => array( '/wp-content/uploads/linked.jpg' ),
			'through symlink dir' => array( '/wp-content/uploads/linkdir/secret.jpg' ),
			'outside roots'       => array( '/etc/passwd.jpg' ),
			'prefix lookalike'    => array( '/wp-content/uploads-evil/photo.jpg' ),
		);
	}

	public function test_site_roots_cover_uploads_and_includes(): void {
		$copier = AssetCopier::for_wordpress();
		$this->assertNotNull( $copier->source_for( wp_parse_url( includes_url( 'js/wp-emoji.js' ), PHP_URL_PATH ) ) );
	}
}
