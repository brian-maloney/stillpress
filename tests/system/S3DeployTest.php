<?php
/**
 * A real build deployed to an in-memory S3 in many short background slices.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

use Stillpress\Build\BuildService;
use Stillpress\Build\Runner;
use Stillpress\Deploy\Registry;
use Stillpress\Deploy\S3;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Secrets;
use Stillpress\Tests\Support\FakeS3;

final class S3DeployTest extends SystemTestCase {

	protected function tearDown(): void {
		Registry::reset();
		parent::tearDown();
	}

	public function test_deploy_resumes_across_slices_and_matches_the_build(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			wp_insert_post(
				array(
					'post_title'  => "Post {$i}",
					'post_status' => 'publish',
				)
			);
		}
		$s3       = new FakeS3();
		$deployer = new S3( $s3 );
		Registry::instance()->register( $deployer );
		TargetConfig::set( $deployer, 'bucket', 'system-test' );
		TargetConfig::set( $deployer, 'access_key_id', 'AKIAIOSFODNN7EXAMPLE' );
		Secrets::set( 's3', 'secret_access_key', 'system-test-secret' );
		\Stillpress\Support\Settings::update( array( 'target' => 's3' ) );

		// Each upload takes 20 ms, so a 50 ms slice fits only a few of them.
		$s3->after = static function () {
			usleep( 20000 );
		};
		$id        = ( new BuildService() )->start( 'full', 'cli', 0, true, array( 'sync' => true ) ); // No background kick: only this process may deploy.
		$runner    = new Runner();
		$slices    = 0;
		while ( Runner::DONE !== $runner->run_slice( $id, 0.05 ) && $slices < 500 ) {
			++$slices;
		}
		$build = ( new \Stillpress\Storage\BuildRepository() )->get( $id );
		$this->assertSame( 'done', $build['status'], (string) $build['error'] . "\n" . $this->log( $id ) );
		$this->assertStringContainsString( 'continuing', $this->log( $id ), 'The deploy needed more than one slice.' );

		$manifest = iterator_to_array( ( new \Stillpress\Storage\UrlRepository() )->published_files() );
		$this->assertGreaterThan( 5, count( $manifest ) );
		$this->assertSame( count( $manifest ), $build['stats']['deploy']['added'] );
		$this->assertSame( 0, $build['stats']['deploy']['unchanged'] );
		$keys = array_keys( $s3->objects );
		sort( $keys );
		$paths = array_map( 'strval', array_keys( $manifest ) );
		sort( $paths );
		$this->assertSame( $paths, $keys );
		$this->assertCount( count( $manifest ), $s3->sent( 'PUT' ), 'No file is uploaded twice.' );
	}
}
