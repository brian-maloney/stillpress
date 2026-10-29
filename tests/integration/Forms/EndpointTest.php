<?php
/**
 * The public form routes.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Forms;

use Stillpress\Forms\Endpoint;
use Stillpress\Forms\Receiver;
use Stillpress\Forms\Signature;
use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

final class EndpointTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);
		Settings::update(
			array(
				'public_url' => 'https://static.example.org',
				'forms'      => 'site',
			)
		);
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function tear_down(): void {
		Secrets::delete( 'forms', 'secret' );
		parent::tear_down();
	}

	private static function submit( string $accept, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/stillpress/v1/forms/submit' );
		$request->set_header( 'accept', $accept );
		$request->set_header( 'origin', 'https://static.example.org' );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	}

	private static function contact(): array {
		return array(
			'stillpress_form' => 'form:contact',
			'stillpress_page' => 'https://static.example.org/contact/',
			'stillpress_hp'   => '',
			'stillpress_t'    => '7',
			'message'         => 'Hi',
		);
	}

	public function test_json_answer_for_anonymous_visitors(): void {
		wp_set_current_user( 0 );
		$response = self::submit( 'application/json', self::contact() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( 'sent', $response->get_data()['status'] );

		$spam = self::submit( 'application/json', array( 'stillpress_hp' => 'x' ) + self::contact() );
		$this->assertSame( 403, $spam->get_status() );
		$this->assertFalse( $spam->get_data()['ok'] );
	}

	public function test_html_answer_without_javascript(): void {
		$response = self::submit( 'text/html,application/xhtml+xml', self::contact() );
		$this->assertSame( Endpoint::HTML, $response->get_headers()['Content-Type'] );
		$html = (string) $response->get_data();
		$this->assertStringContainsString( 'Your message was sent', $html );
		$this->assertStringContainsString( 'href="https://static.example.org/contact/"', $html );

		$request = new WP_REST_Request( 'POST', '/stillpress/v1/forms/submit' );
		ob_start();
		$served = Endpoint::serve_html( false, $response, $request, rest_get_server() );
		$this->assertTrue( $served );
		$this->assertSame( $html, ob_get_clean() );

		// Answers of other routes are left to the REST server.
		$other = new WP_REST_Request( 'GET', '/stillpress/v1/status' );
		$this->assertFalse( Endpoint::serve_html( false, $response, $other, rest_get_server() ) );
	}

	public function test_back_link_stays_on_the_public_site(): void {
		$html = (string) self::submit( 'text/html', array( 'stillpress_page' => 'https://evil.example/' ) + self::contact() )->get_data();
		$this->assertStringContainsString( 'href="https://static.example.org"', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
	}

	public function test_relay_route(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		Secrets::set( 'forms', 'secret', 'shared' );
		$pairs = array();
		foreach ( self::contact() as $name => $value ) {
			$pairs[] = array( $name, $value );
		}
		$body    = Receiver::payload( 'form:contact', '', $pairs, '192.0.2.9', 'UA' );
		$request = new WP_REST_Request( 'POST', '/stillpress/v1/forms/relay' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-stillpress-signature', Signature::sign( $body, 'shared', time() ) );
		$request->set_body( $body );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );

		$request->set_header( 'x-stillpress-signature', Signature::sign( $body, 'other', time() ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}
}
