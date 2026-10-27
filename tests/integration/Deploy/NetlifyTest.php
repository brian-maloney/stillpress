<?php
/**
 * Netlify deploys against an in-memory Netlify API.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\Netlify;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Secrets;
use Stillpress\Support\Logger;
use Stillpress\Support\Settings;
use Stillpress\Tests\Support\FakeNetlify;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures.

final class NetlifyTest extends \WP_UnitTestCase {

	/** @var string */
	private $cache;

	/** @var FakeNetlify */
	private $api;

	/** @var Netlify */
	private $deployer;

	/** @var int */
	private $sleeps = 0;

	public function set_up(): void {
		parent::set_up();
		$this->cache = sys_get_temp_dir() . '/sp-netlify-' . wp_generate_password( 6, false );
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		file_put_contents( $this->cache . '/_redirects', '/old /new 301' );

		$this->api      = new FakeNetlify();
		$this->deployer = new Netlify(
			$this->api,
			function () {
				++$this->sleeps;
			}
		);
		TargetConfig::set( $this->deployer, 'site_id', 'my-site' );
		Secrets::set( 'netlify', 'token', FakeNetlify::TOKEN );
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

	private function live(): array {
		$live = array();
		foreach ( $this->api->live['my-site'] ?? array() as $path => $sha1 ) {
			$live[ $path ] = $this->api->store[ $sha1 ];
		}
		ksort( $live );
		return $live;
	}

	public function test_missing_settings_are_named(): void {
		$fresh = new Netlify( new FakeNetlify() );
		TargetConfig::set( $fresh, 'site_id', '' );
		Secrets::delete( 'netlify', 'token' );
		$this->assertStringContainsString( 'Site ID', (string) $fresh->problem() );
		$this->assertStringContainsString( 'Personal access token', (string) $fresh->problem() );
	}

	public function test_connection_check(): void {
		$this->assertNull( $this->deployer->check_connection() );
		TargetConfig::set( $this->deployer, 'site_id', 'someone-else' );
		$this->assertStringContainsString( 'no site with that ID', (string) $this->deployer->check_connection() );
		TargetConfig::set( $this->deployer, 'site_id', 'my-site' );
		Secrets::set( 'netlify', 'token', 'wrong-token-1234' );
		$this->assertStringContainsString( 'refused the access token', (string) $this->deployer->check_connection() );
	}

	public function test_first_deploy_sends_digests_then_the_files(): void {
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );

		$create = $this->api->sent( 'POST', '/deploys' )[0];
		$body   = json_decode( $create['body'], true );
		$this->assertTrue( $body['async'] );
		$this->assertSame( sha1( 'home v1' ), $body['files']['/index.html'] );
		$this->assertSame( array( '/_redirects', '/about/index.html', '/index.html' ), array_keys( $body['files'] ) );
		$this->assertCount( 3, $this->api->sent( 'PUT' ) );
		$this->assertSame(
			array(
				'/_redirects'       => '/old /new 301',
				'/about/index.html' => 'about v1',
				'/index.html'       => 'home v1',
			),
			$this->live()
		);
		$this->assertSame( array(), get_option( Netlify::STATE_OPTION ), 'No unfinished deploy is left.' );
	}

	public function test_unchanged_site_spends_no_deploy(): void {
		$this->deploy();
		$this->api->requests = array();
		$result              = $this->deploy();
		$this->assertSame( 3, $result->unchanged );
		$this->assertSame( array(), $this->api->requests, 'Netlify allows 100 API deploys a day; none is used for nothing.' );
	}

	public function test_changes_upload_only_new_content(): void {
		$this->deploy();
		$this->api->requests = array();
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/about/index.html' );
		rmdir( $this->cache . '/about' );

		$result = $this->deploy();
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$this->assertSame( 1, $result->unchanged );
		$puts = $this->api->sent( 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertStringEndsWith( '/files/index.html', $puts[0]['url'] );
		$this->assertSame(
			array(
				'/_redirects' => '/old /new 301',
				'/index.html' => 'home v2',
			),
			$this->live()
		);
	}

	public function test_waits_while_netlify_prepares(): void {
		$this->api->prepare_polls = 3;
		$this->assertTrue( $this->deploy()->done );
		$this->assertSame( 3, $this->sleeps );
		$this->assertCount( 3, $this->live() );
	}

	public function test_deadline_while_preparing_resumes_the_same_deploy(): void {
		$this->api->prepare_polls = 5;
		$this->deployer->set_deadline( microtime( true ) - 1 );
		$this->assertFalse( $this->deploy()->done );

		$this->deployer->set_deadline( 0 );
		$this->assertTrue( $this->deploy()->done );
		$this->assertCount( 1, $this->api->sent( 'POST', '/deploys' ), 'The saved deploy is resumed, not recreated.' );
		$this->assertCount( 3, $this->live() );
	}

	public function test_deadline_mid_upload_resumes_with_what_is_missing(): void {
		Settings::update( array( 'concurrency' => 1 ) );
		$deployer         = $this->deployer;
		$this->api->after = static function ( $request ) use ( $deployer ) {
			if ( 'PUT' === $request['method'] ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
		$first            = $this->deploy();
		$this->assertFalse( $first->done );
		$this->assertCount( 1, $this->api->sent( 'PUT' ) );

		$this->api->after = null;
		$deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );
		$this->assertCount( 3, $this->api->sent( 'PUT' ), 'Each file is uploaded once.' );
		$this->assertCount( 1, $this->api->sent( 'POST', '/deploys' ) );
	}

	public function test_expired_unfinished_deploy_is_replaced(): void {
		$this->api->prepare_polls = 5;
		$this->deployer->set_deadline( microtime( true ) - 1 );
		$this->deploy();
		$this->api->deploys = array(); // Netlify forgot it.

		$this->api->prepare_polls = 0;
		$this->deployer->set_deadline( 0 );
		$this->assertTrue( $this->deploy()->done );
		$this->assertCount( 2, $this->api->sent( 'POST', '/deploys' ) );
		$this->assertCount( 3, $this->live() );
	}

	public function test_deploy_stuck_after_every_upload_is_replaced(): void {
		// Netlify keeps saying "uploading" although every file arrived: wait a few slices, then start over.
		$this->api->fail = static function ( $request ) {
			if ( 'GET' === $request['method'] && preg_match( '#/api/v1/deploys/(d1)$#', (string) wp_parse_url( $request['url'], PHP_URL_PATH ), $m ) ) {
				return FakeNetlify::json(
					200,
					array(
						'id'       => 'd1',
						'state'    => 'uploading',
						'required' => array(),
					)
				);
			}
			return null;
		};
		for ( $i = 1; $i < Netlify::MAX_WAITS; $i++ ) {
			$this->assertFalse( $this->deploy()->done, "Waiting, slice {$i}." );
		}
		try {
			$this->deploy();
			$this->fail( 'Expected the stuck deploy to be given up.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'did not finish deploy d1', $e->getMessage() );
		}
		$this->assertCount( 3, $this->api->sent( 'PUT' ), 'Its files were sent once.' );
		$this->assertSame( array(), $this->live(), 'Nothing went live.' );

		$this->api->fail = null;
		$this->assertTrue( $this->deploy()->done );
		$this->assertCount( 2, $this->api->sent( 'POST', '/deploys' ), 'A new deploy replaced it.' );
		$this->assertCount( 3, $this->live() );
	}

	public function test_rate_limit_keeps_changes_pending(): void {
		$this->api->fail = static function ( $request ) {
			return 'POST' === $request['method'] ? FakeNetlify::json( 429, array( 'message' => 'Rate limit exceeded' ) ) : null;
		};
		try {
			$this->deploy();
			$this->fail( 'Expected the rate limit to stop the deploy.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'rate limit', $e->getMessage() );
		}
		$this->api->fail = null;
		$this->assertSame( 3, $this->deploy()->added, 'Nothing was recorded as published, so the next deploy sends it all.' );
	}

	public function test_failed_upload_is_retried_in_the_same_deploy(): void {
		$this->api->fail = static function ( $request ) {
			return 'PUT' === $request['method'] && false !== strpos( $request['url'], '_redirects' ) ? FakeNetlify::json( 500, array( 'message' => 'Internal error' ) ) : null;
		};
		try {
			$this->deploy();
			$this->fail( 'Expected the failed upload to stop the deploy.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '_redirects', $e->getMessage() );
		}
		$this->api->fail = null;
		$this->assertTrue( $this->deploy()->done );
		$this->assertCount( 1, $this->api->sent( 'POST', '/deploys' ) );
		$this->assertCount( 3, $this->live() );
	}

	public function test_names_netlify_refuses_are_skipped(): void {
		file_put_contents( $this->cache . '/odd#name.html', 'x' );
		$result = $this->deploy();
		$this->assertSame( 1, $result->failed );
		$this->assertArrayNotHasKey( '/odd#name.html', $this->live() );
		$this->assertCount( 3, $this->live() );
	}
}
