<?php
/**
 * Contact Form 7.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

use Stillpress\Forms\Result;
use Stillpress\Forms\Submission;

/**
 * Recognizes Contact Form 7 forms (class `wpcf7-form`, hidden `_wpcf7` form ID) and submits them through
 * `WPCF7_ContactForm::submit()`, the call Contact Form 7's own REST route makes, so its validation, mail templates,
 * spam filters and add-ons (such as Flamingo storage) run as usual. File uploads aren't supported.
 */
final class ContactForm7 implements AdapterInterface {

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'cf7';
	}

	/**
	 * Recognizes a Contact Form 7 form.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form.
	 * @return string|null
	 */
	public function detect( array $form ): ?string {
		$id = $form['hidden']['_wpcf7'] ?? '';
		if ( preg_match( '/(^|\s)wpcf7-form(\s|$)/', $form['attrs']['class'] ?? '' ) && preg_match( '/^[1-9][0-9]{0,19}$/', $id ) ) {
			return 'cf7:' . $id;
		}
		return null;
	}

	/**
	 * Submits the form.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	public function handle( Submission $submission ): Result {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return Result::error();
		}
		$form = \WPCF7_ContactForm::get_instance( (int) $submission->id );
		if ( ! $form || (string) ( $submission->fields['_wpcf7'] ?? '' ) !== $submission->id ) {
			return Result::error();
		}
		$result  = Request::run(
			$submission,
			$submission->fields,
			static function () use ( $form ) {
				return $form->submit();
			}
		);
		$status  = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';
		$message = is_array( $result ) ? (string) ( $result['message'] ?? '' ) : '';
		switch ( $status ) {
			case 'mail_sent':
				return Result::sent( $message );
			case 'validation_failed':
			case 'acceptance_missing':
				$errors = array();
				foreach ( (array) ( $result['invalid_fields'] ?? array() ) as $name => $field ) {
					if ( is_array( $field ) && isset( $field['reason'] ) ) {
						$errors[ (string) $name ] = (string) $field['reason'];
					}
				}
				return new Result( Result::INVALID, $message, $errors );
			case 'spam':
				return new Result( Result::SPAM, $message );
			default:
				return Result::error( $message );
		}
	}
}
