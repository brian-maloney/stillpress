<?php
/**
 * S3 deploys and CloudFront invalidations, against an in-memory S3 (threats T5, T10).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\S3;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Redactor;
use Stillpress\Security\Secrets;
use Stillpress\Support\Logger;
use Stillpress\Support\Settings;
use Stillpress\Tests\Support\FakeS3;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixtures; checks S3's Content-MD5.

final class S3Test extends \WP_UnitTestCase {

	const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

	/** @var string */
	private $cache;

	/** @var FakeS3 */
	private $s3;

	/** @var S3 */
	private $deployer;

	public function set_up(): void {
		parent::set_up();
		$this->cache = sys_get_temp_dir() . '/sp-s3-' . wp_generate_password( 6, false );
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		file_put_contents( $this->cache . '/style.css', 'body{}' );

		$this->s3       = new FakeS3();
		$this->deployer = new S3( $this->s3 );
		TargetConfig::set( $this->deployer, 'bucket', 'example-site' );
		TargetConfig::set( $this->deployer, 'region', 'eu-west-1' );
		TargetConfig::set( $this->deployer, 'prefix', 'www/' );
		TargetConfig::set( $this->deployer, 'access_key_id', 'AKIAIOSFODNN7EXAMPLE' );
		Secrets::set( 's3', 'secret_access_key', self::SECRET );
		Settings::update( array( 'public_url' => 'https://www.example.org' ) );
	}

	public function tear_down(): void {
		\Stillpress\Tests\Support\Fs::rmrf( $this->cache );
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

	public function test_missing_settings_are_named(): void {
		$fresh = new S3( new FakeS3() );
		Secrets::delete( 's3', 'secret_access_key' );
		TargetConfig::set( $fresh, 'bucket', '' );
		$this->assertStringContainsString( 'Bucket', (string) $fresh->problem() );
		$this->assertStringContainsString( 'Secret access key', (string) $fresh->problem() );
	}

	public function test_first_deploy_uploads_everything_signed_with_types(): void {
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );
		$this->assertSame( array( 'www/about/index.html', 'www/index.html', 'www/style.css' ), array_keys( $this->sorted_objects() ) );
		$this->assertSame( 'text/html; charset=utf-8', $this->s3->objects['www/index.html']['headers']['Content-Type'] );
		$this->assertSame( 'text/css; charset=utf-8', $this->s3->objects['www/style.css']['headers']['Content-Type'] );

		$put = $this->s3->sent( 'PUT' )[0];
		$this->assertStringStartsWith( 'https://example-site.s3.eu-west-1.amazonaws.com/www/', $put['url'] );
		$this->assertMatchesRegularExpression( '#^AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/\d{8}/eu-west-1/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $put['headers']['Authorization'] );
		$this->assertSame( hash( 'sha256', $put['body'] ), $put['headers']['x-amz-content-sha256'] );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $this->s3->requests ) );
	}

	public function test_unchanged_files_are_not_sent_again(): void {
		$this->deploy();
		$this->s3->requests = array();
		$result             = $this->deploy();
		$this->assertSame( 3, $result->unchanged );
		$this->assertSame( array(), $this->s3->requests );
	}

	public function test_changes_and_deletes_touch_only_our_objects(): void {
		$this->deploy();
		$this->s3->objects['www/not-ours.html'] = array(
			'body'    => 'uploaded by someone else',
			'headers' => array(),
		);
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/about/index.html' );
		$this->s3->requests = array();

		$result = $this->deploy();
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$this->assertSame( 'home v2', $this->s3->objects['www/index.html']['body'] );
		$this->assertArrayNotHasKey( 'www/about/index.html', $this->s3->objects );
		$this->assertArrayHasKey( 'www/not-ours.html', $this->s3->objects, 'Objects we did not upload are never deleted.' );

		$delete = $this->s3->sent( 'POST', '?delete=' )[0];
		$this->assertSame( base64_encode( md5( $delete['body'], true ) ), $delete['headers']['Content-MD5'] );
		$this->assertStringNotContainsString( 'not-ours', $delete['body'] );
	}

	public function test_rejected_credentials_stop_the_deploy_without_leaking_the_secret(): void {
		$this->s3->fail = static function () {
			return FakeS3::response( 403, '<Error><Code>SignatureDoesNotMatch</Code><Message>Bad signature for ' . self::SECRET . '</Message></Error>' );
		};
		try {
			$this->deploy();
			$this->fail( 'Expected the deploy to stop.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'SignatureDoesNotMatch', $e->getMessage() );
			$this->assertStringNotContainsString( self::SECRET, Redactor::scrub( $e->getMessage() ) );
		}
	}

	public function test_server_errors_are_retried_by_the_next_deploy(): void {
		$this->s3->fail = static function ( $request ) {
			return false !== strpos( $request['url'], 'style.css' ) ? FakeS3::response( 503, '<Error><Code>SlowDown</Code></Error>' ) : null;
		};
		$result         = $this->deploy();
		$this->assertSame( 2, $result->added );
		$this->assertSame( 1, $result->failed );

		$this->s3->fail     = null;
		$this->s3->requests = array();
		$result             = $this->deploy();
		$this->assertSame( 1, $result->added );
		$this->assertCount( 1, $this->s3->sent( 'PUT' ) );
	}

	public function test_deadline_stops_and_resumes(): void {
		$this->deployer->set_deadline( microtime( true ) - 1 );
		$result = $this->deploy();
		$this->assertFalse( $result->done );
		$this->assertSame( array(), $this->s3->objects );

		$this->deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );
	}

	public function test_large_file_uploads_in_parts_and_resumes_mid_upload(): void {
		$big = str_repeat( 'a', S3::PART_SIZE ) . str_repeat( 'b', 1000 );
		file_put_contents( $this->cache . '/video.mp4', $big );
		$deployer        = $this->deployer;
		$this->s3->after = static function ( $request ) use ( $deployer ) {
			if ( false !== strpos( $request['url'], 'partNumber=1&' ) ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
		$result          = $this->deploy();
		$this->assertFalse( $result->done, 'Stops after the first part.' );
		$this->assertCount( 1, $this->s3->sent( 'POST', '?uploads=' ) );

		$this->s3->after = null;
		$deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertCount( 1, $this->s3->sent( 'POST', '?uploads=' ), 'The upload resumes instead of starting over.' );
		$parts = array_map(
			static function ( $r ) {
				return (int) preg_replace( '/.*partNumber=(\d+).*/', '$1', $r['url'] );
			},
			$this->s3->sent( 'PUT', 'partNumber=' )
		);
		$this->assertSame( array( 1, 2 ), $parts );
		$this->assertSame( $big, $this->s3->objects['www/video.mp4']['body'] );
		$this->assertSame( array(), get_option( S3::MULTIPART_OPTION, array() ) );
	}

	public function test_cloudfront_invalidates_changed_paths(): void {
		TargetConfig::set( $this->deployer, 'cloudfront_distribution', 'E2EXAMPLE' );
		$this->deploy();
		$requests = $this->s3->sent( 'POST', 'cloudfront.amazonaws.com' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://cloudfront.amazonaws.com/2020-05-31/distribution/E2EXAMPLE/invalidation', $requests[0]['url'] );
		$this->assertStringContainsString( '/us-east-1/cloudfront/aws4_request', $requests[0]['headers']['Authorization'] );
		foreach ( array( '/index.html', '/', '/about/index.html', '/about/', '/style.css' ) as $path ) {
			$this->assertStringContainsString( '<Path>' . $path . '</Path>', $requests[0]['body'] );
		}
		$this->assertStringContainsString( '<Quantity>5</Quantity>', $requests[0]['body'] );

		$this->s3->requests = array();
		$this->deploy();
		$this->assertSame( array(), $this->s3->sent( 'POST', 'cloudfront.amazonaws.com' ), 'Nothing changed, nothing to invalidate.' );
	}

	public function test_many_changes_become_one_wildcard(): void {
		TargetConfig::set( $this->deployer, 'cloudfront_distribution', 'E2EXAMPLE' );
		for ( $i = 0; $i < 400; $i++ ) {
			file_put_contents( $this->cache . "/p{$i}.html", "page {$i}" );
		}
		$this->deploy();
		$body = $this->s3->sent( 'POST', 'cloudfront.amazonaws.com' )[0]['body'];
		$this->assertStringContainsString( '<Quantity>1</Quantity><Items><Path>/*</Path>', $body );
	}

	public function test_connection_check_reports_wrong_region(): void {
		$this->s3->fail = static function () {
			return FakeS3::response( 301, '', array( 'x-amz-bucket-region' => 'us-west-2' ) );
		};
		$this->assertStringContainsString( 'us-west-2', (string) $this->deployer->check_connection() );
		$this->s3->fail = null;
		$this->assertNull( $this->deployer->check_connection() );
	}

	public function test_dotted_bucket_uses_path_style(): void {
		TargetConfig::set( $this->deployer, 'bucket', 'www.example.org' );
		$this->deploy();
		$this->assertStringStartsWith( 'https://s3.eu-west-1.amazonaws.com/www.example.org/www/', $this->s3->sent( 'PUT' )[0]['url'] );
	}

	private function sorted_objects(): array {
		$objects = $this->s3->objects;
		ksort( $objects );
		return $objects;
	}
}
