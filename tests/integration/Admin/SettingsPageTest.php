<?php
/**
 * The settings form's handling of deploy target fields (threats T6, T10, T17).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Admin;

use Stillpress\Admin\SettingsPage;
use Stillpress\Deploy\Registry;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Capabilities;
use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Simulates a verified options.php submission; tests the environment source.

final class SettingsPageTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Capabilities::grant_defaults();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['option_page'] = SettingsPage::GROUP;
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset between tests.
	}

	public function tear_down(): void {
		unset( $_POST['option_page'] );
		putenv( 'STILLPRESS_S3_REGION' );
		parent::tear_down();
	}

	/**
	 * Submits the form the way options.php does: the sanitize callback runs inside update_option().
	 *
	 * @param array<string,mixed> $input Submitted stillpress_settings.
	 */
	private function submit( array $input ): void {
		update_option( Settings::OPTION, SettingsPage::sanitize( $input ) );
	}

	private function panel( string $id ): string {
		ob_start();
		SettingsPage::field_target_panel( array( 'deployer' => Registry::instance()->get( $id ) ) );
		return (string) ob_get_clean();
	}

	public function test_form_saves_with_the_plugin_capability(): void {
		SettingsPage::register();
		$this->assertSame( Capabilities::MANAGE, apply_filters( 'option_page_capability_' . SettingsPage::GROUP, 'manage_options' ) );
	}

	public function test_secrets_go_to_secrets_and_empty_boxes_keep_them(): void {
		$this->submit( array( 'secrets' => array( 's3' => array( 'secret_access_key' => 'form-secret-1234' ) ) ) );
		$this->assertSame( 'form-secret-1234', Secrets::get( 's3', 'secret_access_key' ) );
		$this->assertStringNotContainsString( 'form-secret-1234', (string) wp_json_encode( get_option( Settings::OPTION ) ) );

		$this->submit( array( 'secrets' => array( 's3' => array( 'secret_access_key' => '' ) ) ) );
		$this->assertSame( 'form-secret-1234', Secrets::get( 's3', 'secret_access_key' ), 'An empty box keeps the saved secret.' );

		$this->submit( array( 'secrets_clear' => array( 's3' => array( 'secret_access_key' => '1' ) ) ) );
		$this->assertNull( Secrets::get( 's3', 'secret_access_key' ) );
	}

	public function test_private_key_line_endings_are_normalized(): void {
		$this->submit( array( 'secrets' => array( 'sftp' => array( 'private_key' => "-----BEGIN KEY-----\r\nabc\r\n-----END KEY-----\r\n\r\n" ) ) ) );
		$this->assertSame( "-----BEGIN KEY-----\nabc\n-----END KEY-----\n", Secrets::get( 'sftp', 'private_key' ) );
	}

	public function test_passwords_are_kept_exactly_as_typed(): void {
		$this->submit( array( 'secrets' => array( 'sftp' => array( 'password' => ' <p@ss "word"> ' ) ) ) );
		$this->assertSame( ' <p@ss "word"> ', Secrets::get( 'sftp', 'password' ) );
	}

	public function test_fields_merge_one_by_one(): void {
		$sftp = Registry::instance()->get( 'sftp' );
		$s3   = Registry::instance()->get( 's3' );
		TargetConfig::set( $sftp, 'username', 'deploy' );
		TargetConfig::set( $s3, 'path_style', true );

		$this->submit(
			array(
				'targets' => array(
					's3' => array(
						'bucket'     => 'from-the-form',
						'region'     => '',
						'path_style' => '0',
					),
				),
			)
		);
		$this->assertSame( 'from-the-form', TargetConfig::value( $s3, 'bucket' ) );
		$this->assertSame( 'us-east-1', TargetConfig::value( $s3, 'region' ), 'An empty box restores the default.' );
		$this->assertFalse( TargetConfig::value( $s3, 'path_style' ), 'The hidden zero turns a checkbox off.' );
		$this->assertSame( 'deploy', TargetConfig::value( $sftp, 'username' ), 'Targets not on the submission keep their settings.' );
	}

	public function test_invalid_value_keeps_the_old_one_and_says_so(): void {
		$s3 = Registry::instance()->get( 's3' );
		TargetConfig::set( $s3, 'bucket', 'good-bucket' );
		$this->submit( array( 'targets' => array( 's3' => array( 'bucket' => 'Not A Bucket!' ) ) ) );
		$this->assertSame( 'good-bucket', TargetConfig::value( $s3, 'bucket' ) );
		$codes = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' );
		$this->assertContains( 'stillpress_s3_bucket', $codes );
	}

	public function test_environment_pinned_field_is_locked(): void {
		putenv( 'STILLPRESS_S3_REGION=ap-south-1' );
		$this->submit( array( 'targets' => array( 's3' => array( 'region' => 'eu-west-1' ) ) ) );
		$this->assertSame( 'ap-south-1', TargetConfig::value( Registry::instance()->get( 's3' ), 'region' ) );
		$html = $this->panel( 's3' );
		$this->assertMatchesRegularExpression( '#id="stillpress-target-s3-region"[^>]*disabled#', $html );
		$this->assertStringContainsString( 'STILLPRESS_S3_REGION environment variable', $html );
	}

	public function test_panel_never_contains_a_saved_secret(): void {
		Secrets::set( 's3', 'secret_access_key', 'must-not-render-9876' );
		Secrets::set( 'sftp', 'private_key', "-----BEGIN KEY-----\nmust-not-render-key\n-----END KEY-----\n" );
		$html = $this->panel( 's3' ) . $this->panel( 'sftp' );
		$this->assertStringNotContainsString( 'must-not-render', $html );
		$this->assertMatchesRegularExpression( '#<input type="password"[^>]*name="stillpress_settings\[secrets\]\[s3\]\[secret_access_key\]"#', $html );
		$this->assertMatchesRegularExpression( '#<textarea[^>]*name="stillpress_settings\[secrets\]\[sftp\]\[private_key\]"[^>]*></textarea>#', $html );
		$this->assertStringContainsString( 'Saved. Leave empty to keep it.', $html );
		$this->assertStringContainsString( 'Remove the saved value', $html );
	}

	public function test_panel_escapes_stored_values(): void {
		// Stored directly, as a hostile or buggy extension might: escaping must not depend on validation.
		$settings                            = get_option( Settings::OPTION, array() );
		$settings['targets']['s3']['bucket'] = 'x"><script>alert(1)</script>';
		update_option( Settings::OPTION, $settings );
		$html = $this->panel( 's3' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_restricted_fields_are_editable_for_managers_on_a_single_site(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On multisite only super admins may edit them (MultisiteTest).' );
		}
		$html = $this->panel( 'sftp' );
		$this->assertDoesNotMatchRegularExpression( '#id="stillpress-target-sftp-host"[^>]*disabled#', $html );
	}
}
