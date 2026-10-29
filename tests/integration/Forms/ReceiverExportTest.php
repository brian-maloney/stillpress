<?php
/**
 * Standalone receivers written for a site.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Forms;

use Stillpress\Forms\Config;
use Stillpress\Forms\ReceiverExport;
use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;
use WP_UnitTestCase;

final class ReceiverExportTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Settings::update(
			array(
				'public_url'             => 'https://static.example.org/',
				'forms'                  => 'receiver',
				'forms_endpoint'         => 'https://forms.example.net/stillpress-form.php',
				'forms_captcha'          => 'turnstile',
				'forms_captcha_site_key' => 'site-key',
			)
		);
		Secrets::set( 'forms', 'secret', 'never-in-the-file' );
		Secrets::set( 'forms', 'captcha_secret', 'never-either' );
	}

	public function tear_down(): void {
		Secrets::delete( 'forms', 'secret' );
		Secrets::delete( 'forms', 'captcha_secret' );
		parent::tear_down();
	}

	public function test_php_receiver_has_the_site_settings_and_no_secrets(): void {
		$php = ReceiverExport::build( 'php', new Config() );
		$this->assertStringContainsString( "'allowed_origin' => 'https://static.example.org'", $php );
		$this->assertStringContainsString( "'forward_url' => '" . rest_url( 'stillpress/v1/forms/relay' ) . "'", $php );
		$this->assertStringContainsString( "'captcha' => 'turnstile'", $php );
		$this->assertStringNotContainsString( 'never-', $php );

		// The file still parses and its settings are what was written.
		$file = get_temp_dir() . 'stillpress-receiver-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( $file, str_replace( 'namespace StillpressReceiver;', 'namespace StillpressReceiverExportTest;', $php ) );
		if ( ! defined( 'STILLPRESS_RECEIVER_LIBRARY' ) ) {
			define( 'STILLPRESS_RECEIVER_LIBRARY', true );
		}
		require $file;
		unlink( $file );
		$this->assertSame( 'https://static.example.org', \StillpressReceiverExportTest\CONFIG['allowed_origin'] );
		$this->assertSame( 5, \StillpressReceiverExportTest\CONFIG['rate_limit'] );
	}

	public function test_serverless_receivers_are_single_files(): void {
		foreach ( array( 'cloudflare', 'netlify' ) as $kind ) {
			$js = ReceiverExport::build( $kind, new Config() );
			$this->assertStringNotContainsString( 'import ', $js, $kind );
			$this->assertStringNotContainsString( 'never-', $js, $kind );
			$this->assertStringContainsString( '"allowedOrigin": "https://static.example.org"', $js, $kind );
			$this->assertStringContainsString( '"forwardUrl": "' . rest_url( 'stillpress/v1/forms/relay' ) . '"', $js, $kind );
			$this->assertStringContainsString( 'async function handle(', $js, $kind );
			$this->assertSame( 0, preg_match( '/^export (const (?!config =)|function|async function) /m', $js ), $kind . ': only the entry point exports' );
		}
		$this->assertStringContainsString( 'export default {', ReceiverExport::build( 'cloudflare', new Config() ) );
		$this->assertStringContainsString( "export const config = { path: '/stillpress-form' };", ReceiverExport::build( 'netlify', new Config() ) );
	}

	public function test_unknown_kind(): void {
		$this->expectException( \InvalidArgumentException::class );
		ReceiverExport::build( 'lambda', new Config() );
	}
}
