<?php
/**
 * The WordPress form receiver: checks, relay signatures, rate limit, captcha, webhook, and the marked-form and comment
 * adapters.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Forms;

use Stillpress\Forms\Adapters;
use Stillpress\Forms\Config;
use Stillpress\Forms\Receiver;
use Stillpress\Forms\Result;
use Stillpress\Forms\Signature;
use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;
use WP_UnitTestCase;

final class ReceiverTest extends WP_UnitTestCase {

	const PUBLIC_URL = 'https://static.example.org';

	/** @var array<int,array<string,mixed>> */
	private $requests = array();

	/** @var array<string,mixed>|null */
	private $answer = null;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		// The test site is "localhost", which PHPMailer refuses as a sender domain.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);
		Settings::update(
			array(
				'public_url'  => self::PUBLIC_URL,
				'forms'       => 'site',
				'forms_email' => 'forms@example.org',
			)
		);
		$this->requests = array();
		$this->answer   = null;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		Secrets::delete( 'forms', 'secret' );
		Secrets::delete( 'forms', 'captcha_secret' );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->answer ?? array(
			'headers'  => array(),
			'body'     => '{"success":true}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function receiver(): Receiver {
		$config = new Config();
		return new Receiver( $config, Adapters::defaults( $config ) );
	}

	private static function contact( array $extra = array() ): array {
		return array_merge(
			array(
				array( 'stillpress_form', 'form:contact' ),
				array( 'stillpress_page', self::PUBLIC_URL . '/contact/' ),
				array( 'stillpress_hp', '' ),
				array( 'stillpress_t', '12.5' ),
				array( 'name', 'Ada' ),
				array( 'email', 'ada@example.com' ),
				array( 'message', "Hello\nthere" ),
			),
			$extra
		);
	}

	public function test_marked_form_is_emailed(): void {
		$result = $this->receiver()->direct( self::contact(), '203.0.113.5', 'Mozilla/5.0', self::PUBLIC_URL );
		$this->assertSame( Result::SENT, $result->status, $result->message );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'forms@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'contact form', $mail->subject );
		$this->assertStringContainsString( "message: Hello\r\nthere", $mail->body );
		$this->assertStringContainsString( 'name: Ada', $mail->body );
		$this->assertStringNotContainsString( 'stillpress_', $mail->body );
		$this->assertStringContainsString( 'Reply-To: ada@example.com', $mail->header );
	}

	public function test_refusals(): void {
		$cases = array(
			'other origin' => array( self::contact(), 'https://evil.example' ),
			'honeypot'     => array( self::contact( array( array( 'stillpress_hp', 'x' ) ) ), '' ),
			'too fast'     => array( array_merge( array( array( 'stillpress_t', '0.5' ) ), self::contact() ), '' ),
			'no form key'  => array( array( array( 'message', 'hi' ) ), '' ),
		);
		// The honeypot added at the end doesn't count (first value wins), so put it first.
		$cases['honeypot'][0] = array_merge( array( array( 'stillpress_hp', 'x' ) ), self::contact() );
		foreach ( $cases as $name => $case ) {
			$result = $this->receiver()->direct( $case[0], '203.0.113.6', 'UA', $case[1] );
			$this->assertSame( Result::SPAM, $result->status, $name );
		}
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'nothing was sent' );

		$unknown = $this->receiver()->direct( array( array( 'stillpress_form', 'nosuch:1' ) ), '203.0.113.6', 'UA', '' );
		$this->assertSame( Result::ERROR, $unknown->status );
	}

	public function test_direct_submissions_need_site_mode(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		$this->assertSame( Result::ERROR, $this->receiver()->direct( self::contact(), '203.0.113.5', 'UA', '' )->status );
	}

	public function test_rate_limit_per_address(): void {
		for ( $i = 0; $i < Receiver::RATE_LIMIT; $i++ ) {
			$this->assertTrue( $this->receiver()->direct( self::contact(), '198.51.100.7', 'UA', '' )->ok() );
		}
		$limited = $this->receiver()->direct( self::contact(), '198.51.100.7', 'UA', '' );
		$this->assertSame( Result::LIMITED, $limited->status );
		$this->assertSame( 429, $limited->http_status() );
		$this->assertTrue( $this->receiver()->direct( self::contact(), '198.51.100.8', 'UA', '' )->ok(), 'other address' );
	}

	public function test_captcha_is_verified_with_the_provider(): void {
		Settings::update(
			array(
				'forms_captcha'          => 'turnstile',
				'forms_captcha_site_key' => 'site-key-1',
			)
		);
		Secrets::set( 'forms', 'captcha_secret', 'captcha-secret' );

		$missing = $this->receiver()->direct( self::contact(), '203.0.113.9', 'UA', '' );
		$this->assertSame( Result::SPAM, $missing->status );
		$this->assertCount( 0, $this->requests, 'no token, no request' );

		$ok = $this->receiver()->direct( self::contact( array( array( 'cf-turnstile-response', 'tok' ) ) ), '203.0.113.9', 'UA', '' );
		$this->assertTrue( $ok->ok() );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $this->requests[0]['url'] );
		$this->assertSame(
			array(
				'secret'   => 'captcha-secret',
				'response' => 'tok',
				'remoteip' => '203.0.113.9',
			),
			$this->requests[0]['args']['body']
		);

		$this->answer = array(
			'headers'  => array(),
			'body'     => '{"success":false}',
			'response' => array( 'code' => 200 ),
		);
		$this->assertSame( Result::SPAM, $this->receiver()->direct( self::contact( array( array( 'cf-turnstile-response', 'tok' ) ) ), '203.0.113.9', 'UA', '' )->status );
	}

	public function test_relay_accepts_signed_submissions_once(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		$body = Receiver::payload( 'form:contact', self::PUBLIC_URL . '/contact/', self::contact(), '192.0.2.4', 'UA' );

		$receiver      = $this->receiver();
		$receiver->now = 2000000000;
		$this->assertSame( Result::SPAM, $receiver->relay( $body, Signature::sign( $body, 'wrong', 2000000000 ) )->status, 'wrong secret' );
		$this->assertSame( Result::SPAM, $receiver->relay( $body, Signature::sign( $body, 'shared-secret', 2000000000 - 301 ) )->status, 'expired' );

		$signature = Signature::sign( $body, 'shared-secret', 2000000000 );
		$result    = $receiver->relay( $body, $signature );
		$this->assertTrue( $result->ok(), $result->message );
		$this->assertSame( Result::SPAM, $receiver->relay( $body, $signature )->status, 'replayed' );

		$honeypot = Receiver::payload( 'form:contact', '', array_merge( array( array( 'stillpress_hp', 'x' ) ), self::contact() ), '192.0.2.4', 'UA' );
		$this->assertSame( Result::SPAM, $receiver->relay( $honeypot, Signature::sign( $honeypot, 'shared-secret', 2000000000 ) )->status, 'checked again' );
	}

	public function test_relay_without_a_secret_refuses_everything(): void {
		$body = Receiver::payload( 'form:contact', '', self::contact(), '', '' );
		$this->assertSame( Result::SPAM, $this->receiver()->relay( $body, Signature::sign( $body, '', time() ) )->status );
	}

	public function test_webhook_gets_a_signed_copy(): void {
		Settings::update( array( 'forms_webhook' => 'https://hooks.example.net/in' ) );
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		$fired = 0;
		add_action(
			'stillpress_form_submitted',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$this->assertTrue( $this->receiver()->direct( self::contact(), '203.0.113.10', 'UA', '' )->ok() );
		$this->assertSame( 1, $fired );
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://hooks.example.net/in', $request['url'] );
		$this->assertTrue( Signature::verify( $request['args']['headers'][ Signature::HEADER ], $request['args']['body'], 'shared-secret', time() ) );
		$payload = json_decode( $request['args']['body'], true );
		$this->assertSame( 'form:contact', $payload['form'] );
		$this->assertContains( array( 'name', 'Ada' ), $payload['fields'] );
		$this->assertNotContains( array( 'stillpress_hp', '' ), $payload['fields'] );
	}

	public function test_comments_are_added_anonymously(): void {
		$post  = self::factory()->post->create( array( 'comment_status' => 'open' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( 'comment_moderation', 1 );
		$pairs  = array(
			array( 'stillpress_form', 'comments' ),
			array( 'comment_post_ID', (string) $post ),
			array( 'comment_parent', '0' ),
			array( 'author', 'Grace' ),
			array( 'email', 'grace@example.com' ),
			array( 'comment', 'A static comment.' ),
		);
		$result = $this->receiver()->direct( $pairs, '203.0.113.11', 'Mozilla/5.0', '' );
		$this->assertTrue( $result->ok(), $result->message );
		$this->assertStringContainsString( 'moderation', $result->message );
		$comments = get_comments( array( 'post_id' => $post ) );
		$this->assertCount( 1, $comments );
		$this->assertSame( '0', $comments[0]->user_id );
		$this->assertSame( '203.0.113.11', $comments[0]->comment_author_IP );
		$this->assertSame( '0', $comments[0]->comment_approved );
		$this->assertSame( $admin, get_current_user_id(), 'the current user is restored' );

		$missing = $this->receiver()->direct( array( array( 'stillpress_form', 'comments' ), array( 'comment_post_ID', (string) $post ), array( 'comment', 'x' ) ), '203.0.113.12', 'UA', '' );
		$this->assertSame( Result::INVALID, $missing->status );
		$this->assertArrayHasKey( 'author', $missing->errors );

		$closed = self::factory()->post->create( array( 'comment_status' => 'closed' ) );
		$this->assertFalse( $this->receiver()->direct( array( array( 'stillpress_form', 'comments' ), array( 'comment_post_ID', (string) $closed ), array( 'author', 'a' ), array( 'email', 'a@example.com' ), array( 'comment', 'x' ) ), '203.0.113.13', 'UA', '' )->ok() );
	}

	public function test_result_messages_are_text(): void {
		$result = Result::sent( '<p>Thanks for <b>writing</b> &amp; waiting.</p>' );
		$this->assertSame( 'Thanks for writing & waiting.', $result->message );
		$this->assertSame( Result::default_message( Result::ERROR ), Result::error( '  ' )->message );
		$this->assertNull( Result::from_array( array( 'ok' => true ) ) );
		$this->assertSame(
			'',
			Result::from_array(
				array(
					'status'   => 'sent',
					'redirect' => 'javascript:alert(1)',
				)
			)->redirect
		);
	}
}
