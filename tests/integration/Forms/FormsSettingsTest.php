<?php
/**
 * Forms settings: sanitizing, the settings screen's secret boxes, Site Health, and the client's settings.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Forms;

use Stillpress\Admin\SettingsPage;
use Stillpress\Forms\Config;
use Stillpress\Forms\Frontend;
use Stillpress\Security\Capabilities;
use Stillpress\Security\Secrets;
use Stillpress\Service\HealthChecks;
use Stillpress\Support\Settings;
use WP_UnitTestCase;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Simulates a verified options.php submission.

final class FormsSettingsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_POST['option_page'] );
		Secrets::delete( 'forms', 'secret' );
		Secrets::delete( 'forms', 'captcha_secret' );
		parent::tear_down();
	}

	public function test_sanitizing(): void {
		$saved = Settings::update(
			array(
				'forms'                  => 'nonsense',
				'forms_endpoint'         => 'http://forms.example.net/x',
				'forms_webhook'          => 'http://localhost:9000/hook',
				'forms_email'            => 'not an address',
				'forms_captcha'          => 'recaptcha',
				'forms_captcha_site_key' => 'key with spaces',
			)
		);
		$this->assertSame( 'off', $saved['forms'] );
		$this->assertSame( '', $saved['forms_endpoint'], 'http only for this computer' );
		$this->assertSame( '', $saved['forms_webhook'], 'webhooks are https only' );
		$this->assertSame( '', $saved['forms_email'] );
		$this->assertSame( 'none', $saved['forms_captcha'] );
		$this->assertSame( '', $saved['forms_captcha_site_key'] );

		$saved = Settings::update(
			array(
				'forms'                  => 'receiver',
				'forms_endpoint'         => 'http://localhost:8787/',
				'forms_webhook'          => 'https://hooks.example.net/in?x=1',
				'forms_email'            => 'forms@example.org',
				'forms_captcha'          => 'hcaptcha',
				'forms_captcha_site_key' => '10000000-ffff-ffff-ffff-000000000001',
			)
		);
		$this->assertSame( 'receiver', $saved['forms'] );
		$this->assertSame( 'http://localhost:8787/', $saved['forms_endpoint'] );
		$this->assertSame( 'https://hooks.example.net/in?x=1', $saved['forms_webhook'] );
		$this->assertSame( 'forms@example.org', $saved['forms_email'] );
		$this->assertSame( 'hcaptcha', $saved['forms_captcha'] );
		$this->assertSame( '', Settings::update( array( 'forms_endpoint' => 'https://user:pass@forms.example.net/' ) )['forms_endpoint'] );
	}

	public function test_settings_screen_stores_forms_secrets_write_only(): void {
		Capabilities::grant_defaults();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['option_page'] = SettingsPage::GROUP;
		update_option( Settings::OPTION, SettingsPage::sanitize( array( 'secrets' => array( 'forms' => array( 'secret' => 'shared-value-1' ) ) ) ) );
		$this->assertSame( 'shared-value-1', Secrets::get( 'forms', 'secret' ) );
		$this->assertStringNotContainsString( 'shared-value-1', (string) wp_json_encode( get_option( Settings::OPTION ) ) );

		ob_start();
		SettingsPage::field_forms_secret();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'shared-value-1', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'secrets_clear][forms][secret]', $html );

		update_option( Settings::OPTION, SettingsPage::sanitize( array( 'secrets_clear' => array( 'forms' => array( 'secret' => '1' ) ) ) ) );
		$this->assertNull( Secrets::get( 'forms', 'secret' ) );
	}

	public function test_health_check(): void {
		$checks = new HealthChecks();
		$this->assertSame( 'good', $checks->forms()['status'] );

		Settings::update(
			array(
				'forms'      => 'receiver',
				'public_url' => 'https://static.example.org',
			)
		);
		$this->assertSame( 'critical', $checks->forms()['status'], 'no receiver address' );

		Settings::update( array( 'forms_endpoint' => 'https://forms.example.net/' ) );
		$this->assertSame( 'recommended', $checks->forms()['status'], 'no shared secret' );
		Secrets::set( 'forms', 'secret', 's' );
		$this->assertSame( 'good', $checks->forms()['status'] );

		Settings::update( array( 'forms' => 'site' ) );
		$this->assertSame( 'good', $checks->forms()['status'] );
		Settings::update( array( 'public_url' => '' ) );
		$this->assertSame( 'recommended', $checks->forms()['status'], 'static site at this site\'s own address' );

		Settings::update(
			array(
				'public_url'             => 'https://static.example.org',
				'forms_captcha'          => 'turnstile',
				'forms_captcha_site_key' => 'key',
			)
		);
		$this->assertSame( 'critical', $checks->forms()['status'], 'captcha without its secret refuses everything' );
	}

	public function test_client_settings_and_endpoint(): void {
		Settings::update(
			array(
				'forms'                  => 'site',
				'forms_captcha'          => 'turnstile',
				'forms_captcha_site_key' => 'key-1',
			)
		);
		$config = new Config();
		$this->assertTrue( $config->enabled() );
		$this->assertSame( rest_url( 'stillpress/v1/forms/submit' ), $config->endpoint() );
		$this->assertSame(
			array(
				'provider' => 'turnstile',
				'siteKey'  => 'key-1',
			),
			Frontend::settings( $config )['captcha']
		);

		Settings::update( array( 'forms_captcha_site_key' => '' ) );
		$this->assertSame( 'none', ( new Config() )->captcha(), 'no site key, no captcha' );
		Settings::update( array( 'forms' => 'receiver' ) );
		$this->assertFalse( ( new Config() )->enabled(), 'receiver mode without an address' );
	}
}
