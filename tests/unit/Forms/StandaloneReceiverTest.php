<?php
/**
 * The standalone PHP receiver (receivers/stillpress-form.php): the shared rules, forwarding, email and the rate limit,
 * and one real request through PHP's built-in web server.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;
use Stillpress\Forms\Signature;
use StillpressReceiver as R;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.CodeAnalysis.ForLoopWithTestFunctionCall -- Fakes take the real signatures; the last test runs a web server.

if ( ! defined( 'STILLPRESS_RECEIVER_LIBRARY' ) ) {
	define( 'STILLPRESS_RECEIVER_LIBRARY', true );
}
require_once dirname( __DIR__, 3 ) . '/receivers/stillpress-form.php';

final class StandaloneReceiverTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private $sent = array();

	/** @var array<int,array<string,mixed>> */
	private $mails = array();

	/** @var array<int,array{status:int,body:string}|null> */
	private $answers = array();

	private function io( bool $allow = true ): array {
		return array(
			'http'  => function ( $url, $headers, $body ) {
				$this->sent[] = compact( 'url', 'headers', 'body' );
				return array_shift( $this->answers );
			},
			'mail'  => function ( $to, $subject, $body, $headers ) {
				$this->mails[] = compact( 'to', 'subject', 'body', 'headers' );
				return true;
			},
			'time'  => static function () {
				return 1900000000;
			},
			'limit' => static function () use ( $allow ) {
				return $allow;
			},
		);
	}

	private static function config( array $overrides = array() ): array {
		return array_merge( R\CONFIG, array( 'allowed_origin' => 'https://static.example.org' ), $overrides );
	}

	private static function contact( array $extra = array() ): array {
		return array_merge(
			array(
				array( 'stillpress_form', 'form:contact' ),
				array( 'stillpress_page', 'https://static.example.org/contact/' ),
				array( 'stillpress_hp', '' ),
				array( 'stillpress_t', '8' ),
				array( 'email', 'ada@example.com' ),
				array( 'message', 'Hi' ),
			),
			$extra
		);
	}

	public function test_shared_rules(): void {
		foreach ( ProtocolTest::cases()['refusals'] as $c ) {
			$this->assertSame( $c['expect'], R\refusal( ProtocolTest::expand( $c ) ), $c['name'] );
		}
		foreach ( ProtocolTest::cases()['signatures'] as $c ) {
			$this->assertSame( $c['header'], R\sign( $c['body'], $c['secret'], $c['time'] ) );
		}
	}

	public function test_forwards_signed_and_passes_the_answer_back(): void {
		$this->answers[] = array(
			'status' => 422,
			'body'   => '{"ok":false,"status":"invalid","message":"<b>Check</b> it","errors":{"email":"Bad"}}',
		);
		$config          = self::config(
			array(
				'forward_url' => 'https://wp.example.org/wp-json/stillpress/v1/forms/relay',
				'secret'      => 's',
			)
		);
		$result          = R\handle( $config, self::contact(), '192.0.2.1', 'UA', 'https://static.example.org', $this->io() );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertSame( 'Check it', $result['message'] );
		$this->assertSame( array( 'email' => 'Bad' ), $result['errors'] );

		$request = $this->sent[0];
		$this->assertSame( 'https://wp.example.org/wp-json/stillpress/v1/forms/relay', $request['url'] );
		$header = substr( (string) end( $request['headers'] ), strlen( 'X-Stillpress-Signature: ' ) );
		$this->assertTrue( Signature::verify( $header, $request['body'], 's', 1900000000 ), 'Stillpress accepts the signature' );
		$payload = json_decode( $request['body'], true );
		$this->assertSame( 'form:contact', $payload['form'] );
		$this->assertSame( '192.0.2.1', $payload['ip'] );
		$this->assertSame( self::contact(), $payload['fields'] );

		$this->answers[] = array(
			'status' => 204,
			'body'   => '',
		);
		$this->assertTrue( R\handle( $config, self::contact(), '192.0.2.1', 'UA', '', $this->io() )['ok'], 'a plain webhook that answers 2xx' );
		$this->answers[] = null;
		$this->assertSame( 'error', R\handle( $config, self::contact(), '192.0.2.1', 'UA', '', $this->io() )['status'], 'unreachable' );
	}

	public function test_email(): void {
		$config = self::config( array( 'email_to' => 'owner@example.org' ) );
		$result = R\handle( $config, self::contact( array( array( 'notes', "two\nlines" ) ) ), '192.0.2.1', 'UA', '', $this->io() );
		$this->assertTrue( $result['ok'] );
		$mail = $this->mails[0];
		$this->assertSame( 'owner@example.org', $mail['to'] );
		$this->assertSame( 'New message from the form:contact form', $mail['subject'] );
		$this->assertStringContainsString( "message: Hi\nnotes: two\nlines", $mail['body'] );
		$this->assertStringNotContainsString( 'stillpress_', $mail['body'] );
		$this->assertStringContainsString( 'Reply-To: ada@example.com', $mail['headers'] );

		$this->mails = array();
		R\handle( $config, self::contact( array( array( 'email2', "x@example.com\r\nBcc: victim@example.org" ) ) ), '192.0.2.1', 'UA', '', $this->io() );
		$this->assertStringNotContainsString( 'Bcc', $this->mails[0]['headers'] );
	}

	public function test_refusals_and_limits(): void {
		$config = self::config( array( 'email_to' => 'owner@example.org' ) );
		$this->assertSame( 'spam', R\handle( $config, self::contact(), '192.0.2.1', 'UA', 'https://evil.example', $this->io() )['status'] );
		$this->assertSame( 'spam', R\handle( $config, array_merge( array( array( 'stillpress_hp', 'x' ) ), self::contact() ), '192.0.2.1', 'UA', '', $this->io() )['status'] );
		$this->assertSame( 'limited', R\handle( $config, self::contact(), '192.0.2.1', 'UA', '', $this->io( false ) )['status'] );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 'error', R\handle( self::config(), self::contact(), '192.0.2.1', 'UA', '', $this->io() )['status'], 'nowhere to send it' );
	}

	public function test_captcha(): void {
		$config = self::config(
			array(
				'email_to'         => 'owner@example.org',
				'captcha'          => 'hcaptcha',
				'captcha_site_key' => 'site',
				'captcha_secret'   => 'csecret',
			)
		);
		$this->assertSame( 'spam', R\handle( $config, self::contact(), '192.0.2.1', 'UA', '', $this->io() )['status'] );
		$this->assertSame( array(), $this->sent, 'no token, no request' );

		$this->answers[] = array(
			'status' => 200,
			'body'   => '{"success":true}',
		);
		$this->assertTrue( R\handle( $config, self::contact( array( array( 'h-captcha-response', 'tok' ) ) ), '192.0.2.1', 'UA', '', $this->io() )['ok'] );
		$this->assertSame( 'https://api.hcaptcha.com/siteverify', $this->sent[0]['url'] );
		parse_str( $this->sent[0]['body'], $body );
		$this->assertSame(
			array(
				'secret'   => 'csecret',
				'response' => 'tok',
				'remoteip' => '192.0.2.1',
				'sitekey'  => 'site',
			),
			$body
		);
	}

	public function test_rate_limit_files(): void {
		$dir    = sys_get_temp_dir() . '/stillpress-receiver-test-' . bin2hex( random_bytes( 4 ) );
		$config = self::config(
			array(
				'data_dir'   => $dir,
				'rate_limit' => 2,
			)
		);
		$this->assertTrue( R\within_limit( $config, '192.0.2.1', 1000 ) );
		$this->assertTrue( R\within_limit( $config, '192.0.2.1', 1001 ) );
		$this->assertFalse( R\within_limit( $config, '192.0.2.1', 1002 ) );
		$this->assertTrue( R\within_limit( $config, '192.0.2.2', 1002 ), 'other address' );
		$this->assertTrue( R\within_limit( $config, '192.0.2.1', 1601 ), 'window passed' );
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			$this->assertStringNotContainsString( '192.0.2', (string) $file );
			unlink( (string) $file );
		}
		rmdir( $dir );
	}

	public function test_parses_bodies_like_a_browser_sends_them(): void {
		$this->assertSame(
			array( array( 'a[b]', 'x y' ), array( 'tags[]', '1' ), array( 'tags[]', '2' ), array( 'e', '' ), array( 'input_2.3', '&=' ) ),
			R\parse_urlencoded( 'a%5Bb%5D=x+y&tags[]=1&tags[]=2&e&input_2.3=%26%3D' )
		);
		$this->assertSame(
			array( array( 'a', '1' ), array( 'w[f][1]', 'x' ) ),
			R\flatten(
				array(
					'a' => '1',
					'w' => array( 'f' => array( 1 => 'x' ) ),
				)
			)
		);
	}

	/**
	 * Runs the file under `php -S`, as a host would, with mail going to a file.
	 */
	public function test_real_request(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is disabled.' );
		}
		$root = sys_get_temp_dir() . '/stillpress-receiver-root-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root );
		copy( dirname( __DIR__, 3 ) . '/receivers/stillpress-form.php', $root . '/form.php' );
		$port    = 20000 + random_int( 0, 20000 );
		$mailbox = $root . '/mail.txt';
		$server  = proc_open(
			array( PHP_BINARY, '-d', 'sendmail_path=cat >> ' . $mailbox, '-S', '127.0.0.1:' . $port, '-t', $root ),
			array(
				1 => array( 'file', '/dev/null', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			),
			$pipes,
			null,
			array(
				'STILLPRESS_EMAIL_TO'       => 'owner@example.org',
				'STILLPRESS_ALLOWED_ORIGIN' => 'https://static.example.org',
				'STILLPRESS_FORMS_SECRET'   => 'from-env',
			)
		);
		try {
			$url = 'http://127.0.0.1:' . $port . '/form.php';
			for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $port ); $i++ ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				usleep( 100000 );
			}
			$post = static function ( string $accept, string $origin ) use ( $url ) {
				$context = stream_context_create(
					array(
						'http' => array(
							'method'          => 'POST',
							'header'          => "Content-Type: application/x-www-form-urlencoded\r\nAccept: {$accept}\r\nOrigin: {$origin}",
							'content'         => 'stillpress_form=form%3Acontact&stillpress_page=https%3A%2F%2Fstatic.example.org%2Fcontact%2F&stillpress_hp=&stillpress_t=&email=ada%40example.com&message=Hi%3Cb%3E',
							'ignore_errors'   => true,
							'follow_location' => 0,
						),
					)
				);
				$stream  = fopen( $url, 'rb', false, $context );
				$body    = (string) stream_get_contents( $stream );
				$meta    = stream_get_meta_data( $stream );
				fclose( $stream );
				return array( $meta['wrapper_data'], $body );
			};

			list( $headers, $body ) = $post( 'application/json', 'https://static.example.org' );
			$this->assertStringContainsString( '200', $headers[0] );
			$this->assertContains( 'Access-Control-Allow-Origin: https://static.example.org', $headers );
			$this->assertTrue( json_decode( $body, true )['ok'] );
			$this->assertStringContainsString( 'message: Hi<b>', (string) file_get_contents( $mailbox ) );

			list( $headers, $body ) = $post( 'text/html', 'https://static.example.org' );
			$this->assertStringContainsString( 'Your message was sent', $body );
			$this->assertStringContainsString( 'href="https://static.example.org/contact/"', $body );

			list( $headers, $body ) = $post( 'application/json', 'https://evil.example' );
			$this->assertStringContainsString( '403', $headers[0] );
			$this->assertNotContains( 'Access-Control-Allow-Origin: https://evil.example', $headers );
		} finally {
			proc_terminate( $server );
			proc_close( $server );
			array_map( 'unlink', (array) glob( $root . '/*' ) );
			rmdir( $root );
		}
	}
}
