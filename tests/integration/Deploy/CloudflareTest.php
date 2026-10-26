<?php
/**
 * Cloudflare Workers static-assets deploys against an in-memory Cloudflare API (threat T16).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\Cloudflare;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Secrets;
use Stillpress\Support\Logger;
use Stillpress\Tests\Support\FakeCloudflare;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixtures; Cloudflare's hash input.

final class CloudflareTest extends \WP_UnitTestCase {

	/** @var string */
	private $cache;

	/** @var FakeCloudflare */
	private $api;

	/** @var Cloudflare */
	private $deployer;

	public function set_up(): void {
		parent::set_up();
		$this->cache = sys_get_temp_dir() . '/sp-cf-' . wp_generate_password( 6, false );
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		file_put_contents( $this->cache . '/404.html', 'not here' );
		file_put_contents( $this->cache . '/_redirects', '/old /new 301' );
		file_put_contents( $this->cache . '/logo.bin', "\x00\x01binary" );

		$this->api      = new FakeCloudflare();
		$this->deployer = new Cloudflare( $this->api );
		TargetConfig::set( $this->deployer, 'account_id', FakeCloudflare::ACCOUNT );
		TargetConfig::set( $this->deployer, 'worker_name', 'my-site' );
		Secrets::set( 'cloudflare', 'api_token', FakeCloudflare::TOKEN );
	}

	public function tear_down(): void {
		\Stillpress\Tests\Support\Fs::rmrf( $this->cache );
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

	public function test_hash_matches_the_documented_example(): void {
		// First 32 hex characters of SHA-256 over base64(contents) followed by the extension without its dot.
		$this->assertSame( substr( hash( 'sha256', base64_encode( 'home v1' ) . 'html' ), 0, 32 ), Cloudflare::hash( 'home v1', 'index.html' ) );
		$this->assertSame( substr( hash( 'sha256', base64_encode( 'x' ) ), 0, 32 ), Cloudflare::hash( 'x', '_redirects' ) );
		$this->assertSame( substr( hash( 'sha256', base64_encode( '' ) ), 0, 32 ), Cloudflare::hash( '', '.nojekyll' ), 'A dotfile has no extension.' );
	}

	public function test_first_deploy_uploads_in_buckets_and_creates_an_assets_only_worker(): void {
		$this->assertNull( $this->deployer->check_connection() );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 5, $result->added );
		$this->assertCount( 3, $this->api->sent( 'POST', '/workers/assets/upload' ), 'Five files in buckets of two.' );
		$this->assertSame(
			array(
				'/404.html'         => 'not here',
				'/_redirects'       => '/old /new 301',
				'/about/index.html' => 'about v1',
				'/index.html'       => 'home v1',
				'/logo.bin'         => "\x00\x01binary",
			),
			$this->api->served( 'my-site' )
		);
		$this->assertSame(
			array(
				'html_handling'      => 'auto-trailing-slash',
				'not_found_handling' => '404-page',
			),
			$this->api->workers['my-site']['config']
		);
		$this->assertSame( 'text/html; charset=utf-8', $this->api->types[ Cloudflare::hash( 'home v1', 'index.html' ) ] );
		$this->assertSame( 'application/null', $this->api->types[ Cloudflare::hash( "\x00\x01binary", 'logo.bin' ) ], 'Unknown types send no Content-Type.' );
	}

	public function test_unchanged_site_makes_no_calls_and_changes_send_only_new_content(): void {
		$this->deploy();
		$this->api->requests = array();
		$this->assertSame( 5, $this->deploy()->unchanged );
		$this->assertSame( array(), $this->api->requests );

		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/logo.bin' );
		$result = $this->deploy();
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$uploads = $this->api->sent( 'POST', '/workers/assets/upload' );
		$this->assertCount( 1, $uploads );
		$this->assertStringContainsString( Cloudflare::hash( 'home v2', 'index.html' ), $uploads[0]['body'] );
		$this->assertArrayNotHasKey( '/logo.bin', $this->api->served( 'my-site' ) );
		$this->assertSame( 'home v2', $this->api->served( 'my-site' )['/index.html'] );
	}

	public function test_existing_worker_is_refused_until_replacing_is_allowed(): void {
		$this->api->foreign = array( 'my-site' );
		$this->assertStringContainsString( 'already exists', (string) $this->deployer->check_connection() );
		try {
			$this->deploy();
			$this->fail( 'Expected a refusal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Replace an existing Worker', $e->getMessage() );
		}
		$this->assertSame( array(), $this->api->sent( 'PUT' ), 'Nothing was uploaded.' );

		TargetConfig::set( $this->deployer, 'replace_existing', true );
		$this->assertTrue( $this->deploy()->done );
		TargetConfig::set( $this->deployer, 'replace_existing', false );
		$this->assertNull( $this->deployer->check_connection(), 'Once published, the Worker is ours.' );
	}

	public function test_deadline_between_buckets_resumes_the_session(): void {
		$deployer         = $this->deployer;
		$this->api->after = static function ( $request ) use ( $deployer ) {
			if ( false !== strpos( $request['url'], '/workers/assets/upload' ) ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
		$this->assertFalse( $this->deploy()->done );
		$this->assertCount( 1, $this->api->sent( 'POST', '/workers/assets/upload' ) );

		$this->api->after = null;
		$deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 5, $result->added );
		$this->assertCount( 1, $this->api->sent( 'POST', '/assets-upload-session' ), 'The saved session is resumed.' );
		$this->assertCount( 3, $this->api->sent( 'POST', '/workers/assets/upload' ), 'No bucket is sent twice.' );
		$this->assertCount( 5, $this->api->served( 'my-site' ) );
	}

	public function test_stale_session_starts_over(): void {
		$deployer         = $this->deployer;
		$this->api->after = static function ( $request ) use ( $deployer ) {
			if ( false !== strpos( $request['url'], '/workers/assets/upload' ) ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
		$this->deploy();
		$state                    = get_option( Cloudflare::STATE_OPTION );
		$key                      = array_keys( $state )[0];
		$state[ $key ]['started'] = time() - 7200;
		update_option( Cloudflare::STATE_OPTION, $state );

		$this->api->after = null;
		$deployer->set_deadline( 0 );
		$this->assertTrue( $this->deploy()->done );
		$this->assertCount( 2, $this->api->sent( 'POST', '/assets-upload-session' ) );
		$this->assertCount( 5, $this->api->served( 'my-site' ) );
	}

	public function test_wrong_token_and_missing_settings(): void {
		Secrets::set( 'cloudflare', 'api_token', 'wrong-token-9999' );
		$this->assertStringContainsString( 'refused the API token', (string) $this->deployer->check_connection() );
		TargetConfig::set( $this->deployer, 'worker_name', '' );
		$this->assertStringContainsString( 'Worker name', (string) $this->deployer->problem() );
	}

	public function test_oversized_file_is_skipped(): void {
		file_put_contents( $this->cache . '/huge.bin', str_repeat( 'x', Cloudflare::MAX_FILE + 1 ) );
		$result = $this->deploy();
		$this->assertSame( 1, $result->failed );
		$this->assertArrayNotHasKey( '/huge.bin', $this->api->served( 'my-site' ) );
	}
}
