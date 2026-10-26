<?php
/**
 * Per-target settings (threats T10, T13).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\S3;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Capabilities;
use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Tests the environment-variable source.

final class TargetConfigTest extends \WP_UnitTestCase {

	/** @var S3 */
	private $s3;

	public function set_up(): void {
		parent::set_up();
		$this->s3 = new S3( new \Stillpress\Tests\Support\FakeS3() );
	}

	public function tear_down(): void {
		putenv( 'STILLPRESS_S3_REGION' );
		parent::tear_down();
	}

	public function test_defaults_stored_values_and_sources(): void {
		$this->assertSame( 'us-east-1', TargetConfig::value( $this->s3, 'region' ) );
		$this->assertSame( TargetConfig::SOURCE_DEFAULT, TargetConfig::source( $this->s3, 'region' ) );
		TargetConfig::set( $this->s3, 'region', 'eu-west-1' );
		$this->assertSame( 'eu-west-1', TargetConfig::value( $this->s3, 'region' ) );
		$this->assertSame( Secrets::SOURCE_STORED, TargetConfig::source( $this->s3, 'region' ) );
		TargetConfig::set( $this->s3, 'region', '' );
		$this->assertSame( 'us-east-1', TargetConfig::value( $this->s3, 'region' ), 'An empty value restores the default.' );
	}

	public function test_environment_overrides_stored_value(): void {
		TargetConfig::set( $this->s3, 'region', 'eu-west-1' );
		putenv( 'STILLPRESS_S3_REGION=ap-south-1' );
		$this->assertSame( 'ap-south-1', TargetConfig::value( $this->s3, 'region' ) );
		$this->assertSame( Secrets::SOURCE_ENVIRONMENT, TargetConfig::source( $this->s3, 'region' ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function invalid_provider(): array {
		return array(
			'bucket with capitals' => array( 'bucket', 'My_Bucket' ),
			'bucket too short'     => array( 'bucket', 'ab' ),
			'endpoint with scheme' => array( 'endpoint', 'http://169.254.169.254' ),
			'endpoint with path'   => array( 'endpoint', 'minio.test/evil' ),
			'header injection'     => array( 'cache_control', "max-age=1\r\nX-Evil: 1" ),
			'distribution'         => array( 'cloudfront_distribution', 'e2/../x' ),
		);
	}

	/**
	 * @dataProvider invalid_provider
	 */
	public function test_invalid_values_are_refused( string $field, string $value ): void {
		$this->expectException( \InvalidArgumentException::class );
		TargetConfig::set( $this->s3, $field, $value );
	}

	public function test_unknown_field_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		TargetConfig::set( $this->s3, 'nonsense', 'x' );
	}

	public function test_secret_fields_go_to_secrets_never_to_settings(): void {
		TargetConfig::set( $this->s3, 'secret_access_key', 'never-in-settings-123' );
		$this->assertSame( 'never-in-settings-123', TargetConfig::value( $this->s3, 'secret_access_key' ) );
		$this->assertStringNotContainsString( 'never-in-settings-123', (string) wp_json_encode( get_option( Settings::OPTION ) ) );

		// Even a direct settings write can't park a secret in the plain settings array.
		Settings::update( array( 'targets' => array( 's3' => array( 'secret_access_key' => 'smuggled-value' ) ) ) );
		$this->assertStringNotContainsString( 'smuggled-value', (string) wp_json_encode( get_option( Settings::OPTION ) ) );
	}

	public function test_settings_write_validates_target_fields(): void {
		Settings::update(
			array(
				'targets' => array(
					's3'          => array(
						'bucket'   => 'good-bucket',
						'region'   => 'BAD REGION',
						'invented' => 'x',
					),
					'other-thing' => array(
						'kept'   => 'value',
						'nested' => array( 'dropped' ),
					),
				),
			)
		);
		$targets = Settings::get( 'targets' );
		$this->assertSame( array( 'bucket' => 'good-bucket' ), $targets['s3'] );
		$this->assertSame( array( 'kept' => 'value' ), $targets['other-thing'], 'Settings of an inactive deployer survive.' );
	}

	public function test_restricted_field_needs_path_privileges(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$this->assertFalse( current_user_can( Capabilities::MANAGE ) );
		try {
			TargetConfig::set( $this->s3, 'endpoint', 'minio.internal.test' );
			$this->fail( 'Expected a refusal.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( '', TargetConfig::value( $this->s3, 'endpoint' ) );
		}
		TargetConfig::set( $this->s3, 'bucket', 'editor-may-set-this' );
		$this->assertSame( 'editor-may-set-this', TargetConfig::value( $this->s3, 'bucket' ) );
	}

	public function test_missing_lists_required_labels(): void {
		$this->assertContains( 'Bucket', TargetConfig::missing( $this->s3 ) );
		$this->assertNotContains( 'Region', TargetConfig::missing( $this->s3 ), 'Region has a default.' );
	}
}
