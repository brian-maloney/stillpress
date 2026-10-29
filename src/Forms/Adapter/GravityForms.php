<?php
/**
 * Gravity Forms.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

use Stillpress\Forms\Result;
use Stillpress\Forms\Submission;

/**
 * Recognizes Gravity Forms forms (hidden `gform_submit` with the form ID) and submits them through the documented
 * `GFAPI::submit_form()`, so Gravity Forms validates them, stores the entry and sends its notifications. Input names
 * keep Gravity Forms' convention (`input_2_3` for the posted `input_2.3`, which PHP converts the same way).
 *
 * Only single-page forms are supported: a multi-page form's later pages are rendered by Gravity Forms on the server.
 * File uploads aren't supported. Gravity Forms isn't on WordPress.org, so this adapter is tested against a stand-in
 * that follows the documented return value, not the plugin itself (docs/SUPPORT.md).
 */
final class GravityForms implements AdapterInterface {

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'gravityforms';
	}

	/**
	 * Recognizes a Gravity Forms form.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form.
	 * @return string|null
	 */
	public function detect( array $form ): ?string {
		$id = $form['hidden']['gform_submit'] ?? '';
		return preg_match( '/^[1-9][0-9]{0,19}$/', $id ) ? 'gravityforms:' . $id : null;
	}

	/**
	 * Submits the form.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	public function handle( Submission $submission ): Result {
		if ( ! class_exists( '\GFAPI' ) || (string) ( $submission->fields['gform_submit'] ?? '' ) !== $submission->id ) {
			return Result::error();
		}
		$input = array();
		foreach ( $submission->fields as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'input_' ) ) {
				$input[ $name ] = $value;
			}
		}
		$id     = (int) $submission->id;
		$result = Request::run(
			$submission,
			$submission->fields,
			static function () use ( $id, $input ) {
				return \GFAPI::submit_form( $id, $input );
			}
		);
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return Result::error();
		}
		if ( ! empty( $result['is_spam'] ) ) {
			// Gravity Forms shows spam its normal confirmation, so bots can't tell; do the same.
			return Result::sent( (string) ( $result['confirmation_message'] ?? '' ) );
		}
		if ( empty( $result['is_valid'] ) ) {
			$errors = array();
			foreach ( (array) ( $result['validation_messages'] ?? array() ) as $field => $message ) {
				$errors[ 'input_' . $field ] = (string) $message;
			}
			return new Result( Result::INVALID, '', $errors );
		}
		$sent = Result::sent( (string) ( $result['confirmation_message'] ?? '' ) );
		if ( 'redirect' === ( $result['confirmation_type'] ?? '' ) && is_string( $result['confirmation_redirect'] ?? null ) ) {
			$sent->redirect = $result['confirmation_redirect'];
		}
		return $sent;
	}
}
