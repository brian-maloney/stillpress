<?php
/**
 * The Gravity Forms adapter, against a stand-in that returns what GFAPI::submit_form() documents.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Forms;

use Stillpress\Forms\Adapter\GravityForms;
use Stillpress\Forms\Result;
use Stillpress\Forms\Submission;
use Stillpress\Support\Settings;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FakeGravityForms.php';

final class GravityFormsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		\GFAPI::$calls   = array();
		\GFAPI::$answers = array();
		Settings::update( array( 'public_url' => 'https://static.example.org' ) );
	}

	private static function submission( bool $relayed = true ): Submission {
		return Submission::from_pairs(
			array(
				array( 'stillpress_form', 'gravityforms:2' ),
				array( 'input_1', 'Ada' ),
				array( 'input_3.1', 'News' ),
				array( 'is_submit_2', '1' ),
				array( 'gform_submit', '2' ),
				array( 'state_2', 'abc' ),
			),
			'192.0.2.50',
			'UA',
			$relayed
		);
	}

	public function test_passes_inputs_with_php_names(): void {
		\GFAPI::$answers[] = array(
			'is_valid'             => true,
			'is_spam'              => false,
			'confirmation_type'    => 'message',
			'confirmation_message' => '<div class="gform_confirmation_message">Thanks!</div>',
			'entry_id'             => 9,
		);
		$result            = ( new GravityForms() )->handle( self::submission() );
		$this->assertSame( Result::SENT, $result->status );
		$this->assertSame( 'Thanks!', $result->message );
		$this->assertSame( 2, \GFAPI::$calls[0]['form_id'] );
		$this->assertSame(
			array(
				'input_1'   => 'Ada',
				'input_3_1' => 'News',
			),
			\GFAPI::$calls[0]['input']
		);
		$this->assertSame( '192.0.2.50', \GFAPI::$calls[0]['ip'], 'the visitor\'s address while Gravity Forms runs' );
		$this->assertSame( '2', \GFAPI::$calls[0]['post']['gform_submit'] );
	}

	public function test_validation_redirect_spam_and_errors(): void {
		\GFAPI::$answers = array(
			array(
				'is_valid'            => false,
				'is_spam'             => false,
				'validation_messages' => array( 1 => 'This field is required.' ),
			),
			array(
				'is_valid'              => true,
				'confirmation_type'     => 'redirect',
				'confirmation_message'  => '',
				'confirmation_redirect' => 'https://example.org/thanks/',
			),
			array(
				'is_valid'             => false,
				'is_spam'              => true,
				'confirmation_message' => 'Thanks!',
			),
			new \WP_Error( 'gf', 'broken' ),
		);
		$adapter         = new GravityForms();

		$invalid = $adapter->handle( self::submission() );
		$this->assertSame( Result::INVALID, $invalid->status );
		$this->assertSame( array( 'input_1' => 'This field is required.' ), $invalid->errors );

		$this->assertSame( 'https://example.org/thanks/', $adapter->handle( self::submission() )->redirect );

		$spam = $adapter->handle( self::submission() );
		$this->assertSame( Result::SENT, $spam->status, 'spam looks like success, as in Gravity Forms' );

		$this->assertSame( Result::ERROR, $adapter->handle( self::submission() )->status );
	}

	public function test_form_id_must_match(): void {
		$submission                         = self::submission();
		$submission->fields['gform_submit'] = '3';
		$this->assertSame( Result::ERROR, ( new GravityForms() )->handle( $submission )->status );
		$this->assertSame( array(), \GFAPI::$calls );
	}
}
