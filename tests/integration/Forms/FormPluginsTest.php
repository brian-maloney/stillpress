<?php
/**
 * Submissions handed to Contact Form 7 and WPForms Lite, the real plugins.
 *
 * Run with `npm run forms:plugins` (once) and `npm run test:integration:forms`, which loads both plugins.
 *
 * @package Stillpress
 * @group form-plugins
 */

namespace Stillpress\Tests\Integration\Forms;

use Stillpress\Forms\Adapters;
use Stillpress\Forms\Config;
use Stillpress\Forms\Receiver;
use Stillpress\Forms\Result;
use Stillpress\Support\Settings;
use WP_UnitTestCase;

/**
 * @group form-plugins
 */
final class FormPluginsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
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
	}

	private static function send( array $pairs ): Result {
		$config = new Config();
		return ( new Receiver( $config, Adapters::defaults( $config ) ) )->direct( $pairs, '203.0.113.' . wp_rand( 1, 250 ), 'Mozilla/5.0 (test)', '' );
	}

	public function test_contact_form_7(): void {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			$this->markTestSkipped( 'Contact Form 7 is not loaded (npm run test:integration:forms).' );
		}
		$form = \WPCF7_ContactForm::get_template( array( 'title' => 'Static contact' ) );
		$mail = $form->prop( 'mail' );
		// The template's sender uses the test site's "localhost" domain, which PHPMailer refuses.
		$mail['sender']    = 'Site <wordpress@example.org>';
		$mail['recipient'] = 'owner@example.org';
		$form->set_properties( array( 'mail' => $mail ) );
		$form->save();
		$id = (string) $form->id();

		$pairs = static function ( string $email ) use ( $id ) {
			return array(
				array( 'stillpress_form', 'cf7:' . $id ),
				array( 'stillpress_t', '9' ),
				array( '_wpcf7', $id ),
				array( '_wpcf7_version', WPCF7_VERSION ),
				array( '_wpcf7_locale', 'en_US' ),
				array( '_wpcf7_unit_tag', 'wpcf7-f' . $id . '-o1' ),
				array( '_wpcf7_container_post', '0' ),
				array( 'your-name', 'Ada' ),
				array( 'your-email', $email ),
				array( 'your-subject', 'Hello' ),
				array( 'your-message', 'From the static site.' ),
			);
		};

		$invalid = self::send( $pairs( 'not an address' ) );
		$this->assertSame( Result::INVALID, $invalid->status, $invalid->message );
		$this->assertArrayHasKey( 'your-email', $invalid->errors );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );

		self::reset_cf7();
		$sent = self::send( $pairs( 'ada@example.com' ) );
		$this->assertSame( Result::SENT, $sent->status, $sent->message );
		$this->assertSame( Result::text( $form->message( 'mail_sent_ok' ) ), $sent->message );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'owner@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'From the static site.', $mail->body );
	}

	public function test_wpforms(): void {
		if ( ! function_exists( 'wpforms' ) ) {
			$this->markTestSkipped( 'WPForms is not loaded (npm run test:integration:forms).' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = (int) wpforms()->obj( 'form' )->add( 'Static WPForms' );
		$this->assertGreaterThan( 0, $id );
		wpforms()->obj( 'form' )->update(
			$id,
			array(
				'id'       => $id,
				'field_id' => 4,
				'fields'   => array(
					'1' => array(
						'id'       => '1',
						'type'     => 'name',
						'label'    => 'Name',
						'format'   => 'simple',
						'required' => '1',
					),
					'2' => array(
						'id'       => '2',
						'type'     => 'email',
						'label'    => 'Email',
						'required' => '1',
					),
					'3' => array(
						'id'    => '3',
						'type'  => 'textarea',
						'label' => 'Message',
					),
				),
				'settings' => array(
					'form_title'          => 'Static WPForms',
					'ajax_submit'         => '1',
					'antispam'            => '1',
					'antispam_v3'         => '1',
					'notification_enable' => '1',
					'notifications'       => array(
						'1' => array(
							'email'          => 'owner@example.org',
							'subject'        => 'New entry',
							'sender_name'    => 'Site',
							'sender_address' => 'wordpress@example.org',
							'message'        => '{all_fields}',
						),
					),
					'confirmations'       => array(
						'1' => array(
							'type'    => 'message',
							'message' => '<p>Thanks for <b>writing</b>!</p>',
						),
					),
				),
			)
		);
		wp_set_current_user( 0 );

		$pairs = static function ( string $email ) use ( $id ) {
			return array(
				array( 'stillpress_form', 'wpforms:' . $id ),
				array( 'stillpress_t', '9' ),
				array( 'wpforms[fields][1]', 'Ada' ),
				array( 'wpforms[fields][2]', $email ),
				array( 'wpforms[fields][3]', 'From the static site.' ),
				array( 'wpforms[id]', (string) $id ),
				array( 'wpforms[time_token]', '' ),
				array( 'wpforms[post_id]', '0' ),
				array( 'wpforms[submit]', 'wpforms-submit' ),
			);
		};

		$invalid = self::send( $pairs( '' ) );
		$this->assertSame( Result::INVALID, $invalid->status, $invalid->message );
		$this->assertArrayHasKey( 'wpforms[fields][2]', $invalid->errors );

		$sent = self::send( $pairs( 'ada@example.com' ) );
		$this->assertSame( Result::SENT, $sent->status, $sent->message );
		$this->assertSame( 'Thanks for writing!', $sent->message );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'owner@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'ada@example.com', $mail->body );
		$this->assertFalse( has_filter( 'wp_doing_ajax', '__return_true' ), 'bypass filters are removed' );
	}

	/**
	 * Contact Form 7 keeps one submission per request; tests make several.
	 */
	private static function reset_cf7(): void {
		$property = new \ReflectionProperty( \WPCF7_Submission::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function tear_down(): void {
		if ( class_exists( '\WPCF7_Submission' ) ) {
			self::reset_cf7();
		}
		parent::tear_down();
	}
}
