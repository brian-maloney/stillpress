<?php
/**
 * WP-CLI commands, run as real `wp` processes.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

final class CliTest extends SystemTestCase {

	/**
	 * Runs `wp stillpress ...` and returns [exit code, stdout, stderr].
	 *
	 * @param string $args Arguments after "wp stillpress".
	 * @return array{0:int,1:string,2:string}
	 */
	private function wp( string $args ): array {
		$cmd   = 'wp --path=' . escapeshellarg( ABSPATH ) . ' stillpress ' . $args;
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test harness for CLI commands.
		$proc = proc_open(
			$cmd,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$out  = (string) stream_get_contents( $pipes[1] );
		$err  = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		wp_cache_flush();
		return array( $code, $out, $err );
	}

	public function test_build_status_why_and_settings(): void {
		wp_insert_post(
			array(
				'post_title'  => 'From the CLI',
				'post_name'   => 'from-the-cli',
				'post_status' => 'publish',
			)
		);

		list( $code, $out ) = $this->wp( 'build --sync' );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'done', $out );
		$this->assertFileExists( $this->target . '/from-the-cli/index.html' );

		list( $code, $out ) = $this->wp( 'status --format=json' );
		$this->assertSame( 0, $code );
		$builds = json_decode( $out, true );
		$this->assertSame( 'done', $builds[0]['status'] );
		$this->assertSame( 'full', $builds[0]['kind'], 'The first build is always full.' );

		list( $code, $out ) = $this->wp( 'why /from-the-cli/ --format=json' );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '"type":"post"', $out );

		list( $code ) = $this->wp( 'why /not-a-page/' );
		$this->assertNotSame( 0, $code );

		list( $code, $out ) = $this->wp( 'settings set full_rebuild weekly' );
		$this->assertSame( 0, $code, $out );
		list( , $out ) = $this->wp( 'settings get full_rebuild' );
		$this->assertStringContainsString( 'weekly', $out );

		list( $code ) = $this->wp( 'settings set nonsense 1' );
		$this->assertNotSame( 0, $code );
	}

	public function test_incremental_build_after_edit_and_changes_commands(): void {
		$id = wp_insert_post(
			array(
				'post_title'  => 'Before',
				'post_name'   => 'edited',
				'post_status' => 'publish',
			)
		);
		$this->wp( 'build --sync' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'After the edit',
			)
		);
		\Stillpress\Plugin::instance()->changes()->flush();

		list( , $out ) = $this->wp( 'changes list --format=json' );
		$this->assertNotEmpty( json_decode( $out, true ) );

		list( $code, $out ) = $this->wp( 'build --sync' );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'After the edit', (string) $this->page( '/edited/' ) );

		list( , $out ) = $this->wp( 'status --format=json' );
		$this->assertSame( 'incremental', json_decode( $out, true )[0]['kind'] );
	}

	public function test_doctor_zip_urls_and_clean(): void {
		wp_insert_post(
			array(
				'post_title'  => 'Zip me',
				'post_status' => 'publish',
			)
		);
		list( $code, $out ) = $this->wp( 'doctor' );
		$this->assertSame( 0, $code, $out );

		list( $code, $out, $err ) = $this->wp( 'build --sync --no-deploy' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertDirectoryDoesNotExist( $this->target, '--no-deploy must not deploy.' );

		$zip                      = sys_get_temp_dir() . '/stillpress-cli-test.zip';
		list( $code, $out, $err ) = $this->wp( 'zip --out=' . escapeshellarg( $zip ) );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertFileExists( $zip );
		wp_delete_file( $zip );

		list( $code, $out ) = $this->wp( 'urls list --format=count' );
		$this->assertSame( 0, $code );
		$this->assertGreaterThan( 3, (int) trim( $out ) );

		list( $code ) = $this->wp( 'urls add /extra-page/' );
		$this->assertSame( 0, $code );
		wp_cache_delete( 'stillpress_settings', 'options' );
		$this->assertContains( '/extra-page/', \Stillpress\Support\Settings::get( 'additional_urls' ) );

		list( $code, $out, $err ) = $this->wp( 'deploy' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertFileExists( $this->target . '/index.html' );

		list( $code ) = $this->wp( 'clean --yes' );
		$this->assertSame( 0, $code );
		list( , $out ) = $this->wp( 'urls list --format=count' );
		$this->assertSame( '0', trim( $out ) );
		$this->assertFileExists( $this->target . '/index.html', 'clean must not touch the deployed site.' );
	}
}
