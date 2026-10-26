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
	 * @param string      $args  Arguments after "wp stillpress".
	 * @param string|null $stdin Text to pipe to the command; null leaves standard input inherited.
	 * @return array{0:int,1:string,2:string}
	 */
	private function wp( string $args, ?string $stdin = null ): array {
		$cmd   = 'wp --path=' . escapeshellarg( ABSPATH ) . ' stillpress ' . $args;
		$pipes = array();
		$spec  = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		if ( null !== $stdin ) {
			$spec[0] = array( 'pipe', 'r' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test harness for CLI commands.
		$proc = proc_open( $cmd, $spec, $pipes );
		if ( null !== $stdin ) {
			fwrite( $pipes[0], $stdin );
			fclose( $pipes[0] );
		}
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
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

	public function test_secret_set_list_delete_never_prints_the_value(): void {
		list( $code, $out, $err ) = $this->wp( 'secret set testdep token', "tok-cli-0a1b2c3d\n" );
		$this->assertSame( 0, $code, $out . $err );
		wp_cache_delete( \Stillpress\Security\Secrets::OPTION, 'options' );
		$this->assertSame( 'tok-cli-0a1b2c3d', \Stillpress\Security\Secrets::get( 'testdep', 'token' ), 'The trailing newline is dropped.' );

		list( $code, $out, $err ) = $this->wp( 'secret list --format=json' );
		$this->assertSame( 0, $code );
		$this->assertStringNotContainsString( 'tok-cli-', $out . $err );
		$rows = json_decode( $out, true );
		$this->assertSame( 'stored', $rows[0]['source'] );
		$this->assertSame( 'STILLPRESS_TESTDEP_TOKEN', $rows[0]['name'] );

		list( $code ) = $this->wp( 'secret set testdep token', '' );
		$this->assertNotSame( 0, $code, 'An empty value is refused.' );
		list( $code ) = $this->wp( 'secret set Bad-Name token', 'x' );
		$this->assertNotSame( 0, $code );

		list( $code ) = $this->wp( 'secret delete testdep token' );
		$this->assertSame( 0, $code );
		list( , $out ) = $this->wp( 'secret list --format=count' );
		$this->assertSame( '0', trim( $out ) );
	}

	public function test_target_list_show_set_hide_secrets(): void {
		list( $code, $out ) = $this->wp( 'target list --format=json' );
		$this->assertSame( 0, $code );
		$this->assertContains( 's3', array_column( json_decode( $out, true ), 'target' ) );

		list( $code, $out, $err ) = $this->wp( 'target set s3 bucket cli-bucket' );
		$this->assertSame( 0, $code, $out . $err );
		list( $code ) = $this->wp( 'target set s3 bucket NOT_VALID' );
		$this->assertNotSame( 0, $code );
		list( $code, $out ) = $this->wp( 'target set s3 secret_access_key typed-on-the-command-line' );
		$this->assertNotSame( 0, $code, 'Secrets are refused as arguments.' );
		$this->wp( 'secret set s3 secret_access_key', "cli-secret-value-42\n" );

		list( $code, $out ) = $this->wp( 'target show s3 --format=json' );
		$this->assertSame( 0, $code );
		$this->assertStringNotContainsString( 'cli-secret-value-42', $out );
		$rows = array_column( json_decode( $out, true ), null, 'field' );
		$this->assertSame( 'cli-bucket', $rows['bucket']['value'] );
		$this->assertSame( '(hidden)', $rows['secret_access_key']['value'] );
		$this->assertSame( 'stored', $rows['secret_access_key']['source'] );
		$this->assertSame( 'STILLPRESS_S3_BUCKET', $rows['bucket']['constant'] );
	}

	public function test_schedule_shows_the_next_runs(): void {
		list( $code ) = $this->wp( 'settings set publish_mode scheduled' );
		$this->assertSame( 0, $code );
		list( $code ) = $this->wp( 'settings set publish_times "9:00, 17:30"' );
		$this->assertSame( 0, $code );
		list( $code, $out ) = $this->wp( 'schedule --format=json' );
		$this->assertSame( 0, $code, $out );
		$rows = array_column( json_decode( $out, true ), null, 'job' );
		$this->assertSame( 'at 09:00, 17:30 on mon, tue, wed, thu, fri, sat, sun', $rows['publish changes']['schedule'] );
		$this->assertNotSame( '', $rows['publish changes']['next'] );
		$this->assertSame( 'daily at 03:00', $rows['full rebuild']['schedule'] );

		list( $code ) = $this->wp( 'settings set publish_mode sometimes' );
		$this->assertSame( 0, $code );
		list( , $out ) = $this->wp( 'settings get publish_mode' );
		$this->assertStringContainsString( 'immediate', $out, 'An unknown mode falls back to the default.' );
	}
}
