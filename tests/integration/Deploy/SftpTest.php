<?php
/**
 * SFTP deploys against the test-only OpenSSH server in tests/sftp (threats T5, T10, T18).
 *
 * Start it with `npm run sftp:start`; these tests skip themselves when sftp.test isn't reachable.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use phpseclib3\Net\SFTP as SftpConnection;
use Stillpress\Deploy\Sftp;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Redactor;
use Stillpress\Security\Secrets;
use Stillpress\Support\Logger;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures.

final class SftpTest extends \WP_UnitTestCase {

	const HOST     = 'sftp.test';
	const PASSWORD = 'deploy-test-password';

	/** @var string */
	private $cache;

	/** @var string */
	private $remote;

	/** @var Sftp */
	private $deployer;

	/** @var SftpConnection Inspection connection. */
	private $peek;

	public function set_up(): void {
		parent::set_up();
		$socket = @fsockopen( self::HOST, 22, $errno, $errstr, 2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe.
		if ( false === $socket ) {
			$this->markTestSkipped( 'The SFTP test server is not running (npm run sftp:start).' );
		}
		fclose( $socket );
		if ( ! is_readable( dirname( __DIR__, 2 ) . '/sftp/.keys/deploy_key' ) ) {
			$this->markTestSkipped( 'The SFTP test keys are missing (npm run sftp:start creates them).' );
		}

		$this->cache = sys_get_temp_dir() . '/sp-sftp-' . wp_generate_password( 6, false );
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		file_put_contents( $this->cache . '/style.css', 'body{}' );

		$this->remote = '/home/deploy/www/t-' . strtolower( wp_generate_password( 8, false ) );
		$this->peek   = new SftpConnection( self::HOST );
		$this->assertTrue( $this->peek->login( 'deploy', self::PASSWORD ) );

		// Host, port and folder are restricted fields: an administrator sets them.
		\Stillpress\Security\Capabilities::grant_defaults();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin ); // On multisite only a super admin may set them.
		}
		wp_set_current_user( $admin );

		$this->deployer = new Sftp();
		TargetConfig::set( $this->deployer, 'host', self::HOST );
		TargetConfig::set( $this->deployer, 'username', 'deploy' );
		TargetConfig::set( $this->deployer, 'remote_path', $this->remote );
		TargetConfig::set( $this->deployer, 'host_key', Sftp::fingerprint( $this->peek ) );
		Secrets::set( 'sftp', 'private_key', (string) file_get_contents( dirname( __DIR__, 2 ) . '/sftp/.keys/deploy_key' ) );
	}

	public function tear_down(): void {
		if ( $this->peek instanceof SftpConnection && $this->remote ) {
			$this->peek->delete( $this->remote, true ); // Test-only cleanup of this test's own folder.
		}
		if ( $this->cache ) {
			\Stillpress\Tests\Support\Fs::rmrf( $this->cache );
		}
		Redactor::reset();
		parent::tear_down();
	}

	private function manifest(): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->cache, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[ substr( $file->getPathname(), strlen( $this->cache ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	private function deploy(): \Stillpress\Deploy\DeployResult {
		return $this->deployer->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	public function test_fingerprint_matches_openssh_format(): void {
		$this->assertMatchesRegularExpression( '#^SHA256:[A-Za-z0-9+/]{43}$#', Sftp::fingerprint( $this->peek ) );
	}

	public function test_check_without_host_key_shows_the_presented_key(): void {
		$expected = Sftp::fingerprint( $this->peek );
		TargetConfig::set( $this->deployer, 'host_key', '' );
		$message = (string) $this->deployer->check_connection();
		$this->assertStringContainsString( $expected, $message );
		$this->expectException( \RuntimeException::class );
		$this->deploy();
	}

	public function test_wrong_host_key_stops_before_login(): void {
		TargetConfig::set( $this->deployer, 'host_key', 'SHA256:' . str_repeat( 'A', 43 ) );
		try {
			$this->deploy();
			$this->fail( 'Expected a host key mismatch.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'host key does not match', $e->getMessage() );
		}
		$this->assertFalse( $this->peek->file_exists( $this->remote ), 'Nothing was written.' );
	}

	public function test_first_deploy_uploads_and_marks_the_folder(): void {
		$this->assertNull( $this->deployer->check_connection() );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );
		$this->assertSame( 'home v1', $this->peek->get( $this->remote . '/index.html' ) );
		$this->assertSame( 'about v1', $this->peek->get( $this->remote . '/about/index.html' ) );
		$this->assertTrue( $this->peek->file_exists( $this->remote . '/.stillpress-target' ) );
		$this->assertSame( array(), preg_grep( '/^\.stillpress-tmp-/', (array) $this->peek->nlist( $this->remote ) ), 'No temporary files are left.' );
	}

	public function test_changes_and_deletes_touch_only_our_files(): void {
		$this->deploy();
		$this->peek->put( $this->remote . '/not-ours.txt', 'someone else' );
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/about/index.html' );
		rmdir( $this->cache . '/about' );

		$result = $this->deploy();
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$this->assertSame( 1, $result->unchanged );
		$this->assertSame( 'home v2', $this->peek->get( $this->remote . '/index.html' ) );
		$this->assertFalse( $this->peek->file_exists( $this->remote . '/about' ), 'The emptied folder is removed.' );
		$this->assertSame( 'someone else', $this->peek->get( $this->remote . '/not-ours.txt' ) );
	}

	public function test_nothing_is_sent_when_nothing_changed(): void {
		$this->deploy();
		$result = $this->deploy();
		$this->assertSame( 3, $result->unchanged );
		$this->assertSame( 0, $result->added + $result->modified + $result->deleted );
	}

	public function test_non_empty_folder_without_marker_is_refused(): void {
		$this->peek->mkdir( $this->remote, -1, true );
		$this->peek->put( $this->remote . '/important.txt', 'keep me' );
		$this->assertStringContainsString( 'not empty', (string) $this->deployer->check_connection() );
		try {
			$this->deploy();
			$this->fail( 'Expected a refusal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '.stillpress-target', $e->getMessage() );
		}
		$this->assertSame( array( 'important.txt' ), array_values( array_diff( (array) $this->peek->nlist( $this->remote ), array( '.', '..' ) ) ) );
	}

	public function test_password_login_and_refusal_without_leaking_it(): void {
		Secrets::delete( 'sftp', 'private_key' );
		Secrets::set( 'sftp', 'password', self::PASSWORD );
		$this->assertSame( 3, $this->deploy()->added );

		Secrets::set( 'sftp', 'password', 'wrong-password-123' );
		try {
			$this->deploy();
			$this->fail( 'Expected a refused login.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'refused the login', $e->getMessage() );
			$this->assertStringNotContainsString( 'wrong-password-123', Redactor::scrub( $e->getMessage() ) );
		}
	}

	public function test_encrypted_key_needs_its_passphrase(): void {
		Secrets::set( 'sftp', 'private_key', (string) file_get_contents( dirname( __DIR__, 2 ) . '/sftp/.keys/deploy_key_encrypted' ) );
		Secrets::set( 'sftp', 'private_key_passphrase', 'wrong-passphrase' );
		$this->assertStringContainsString( 'passphrase', (string) $this->deployer->check_connection() );
		Secrets::set( 'sftp', 'private_key_passphrase', 'test-passphrase' );
		$this->assertNull( $this->deployer->check_connection() );
	}

	public function test_unwritable_folder_is_reported(): void {
		TargetConfig::set( $this->deployer, 'remote_path', '/home/deploy/readonly' );
		$this->remote = '';
		$this->assertStringContainsString( 'not writable', (string) $this->deployer->check_connection() );
	}

	public function test_deadline_stops_and_resumes(): void {
		$this->deployer->set_deadline( microtime( true ) - 1 );
		$result = $this->deploy();
		$this->assertFalse( $result->done );
		$this->assertSame( 0, $result->added );

		$this->deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );
	}

	public function test_server_root_is_refused(): void {
		TargetConfig::set( $this->deployer, 'remote_path', '/' );
		$this->remote = '';
		$this->assertStringContainsString( 'root folder', (string) $this->deployer->problem() );
	}
}
