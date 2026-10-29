<?php
/**
 * WPForms (Lite and paid versions).
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

use Stillpress\Forms\Result;
use Stillpress\Forms\Submission;

/**
 * Recognizes WPForms forms (class `wpforms-form`, `data-formid`) and processes them with WPForms' own
 * `WPForms_Process::process()`, so its validation, notifications, entries and confirmations apply.
 *
 * WPForms' anti-spam features expect a page rendered moments ago: a signed render-time token, a JavaScript token and
 * a check that the submission came through its own AJAX action. A published page is hours or days old and the
 * submission comes through Stillpress, so those checks are bypassed for this call through the filters WPForms
 * provides for that (and `wp_doing_ajax`, which its AJAX check reads); Stillpress's receiver has already applied
 * its honeypot, time trap, rate limit and optional captcha. WPForms' own hidden honeypot field still applies.
 *
 * Page and redirect confirmations end in `wp_redirect()` and `exit`; the redirect address is caught through
 * `wpforms_process_redirect_url` (which runs after notifications are sent) and returned to the visitor instead.
 *
 * The render-time tokens WPForms prints change on every render and are bypassed anyway, so they are removed from
 * published forms.
 */
final class WpForms implements AdapterInterface, VolatileMarkupInterface {

	/** WPForms filters that skip a check when they return true. */
	const BYPASS = array(
		'wpforms_process_anti_spam_honeypot_bypass',
		'wpforms_process_anti_spam_direct_post_bypass',
		'wpforms_process_time_limit_check_bypass',
		'wpforms_process_bypass_captcha',
		'wpforms_process_antispam',
		'wp_doing_ajax',
	);

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'wpforms';
	}

	/**
	 * Per-render attributes of the form tag.
	 *
	 * @return string[]
	 */
	public function volatile_attributes(): array {
		return array( 'data-token', 'data-token-time' );
	}

	/**
	 * Per-render fields.
	 *
	 * @return string[]
	 */
	public function volatile_fields(): array {
		return array( 'wpforms[time_token]', 'wpforms[token]' );
	}

	/**
	 * Recognizes a WPForms form.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form.
	 * @return string|null
	 */
	public function detect( array $form ): ?string {
		$id = $form['attrs']['data-formid'] ?? ( $form['hidden']['wpforms[id]'] ?? '' );
		if ( preg_match( '/(^|\s)wpforms-form(\s|$)/', $form['attrs']['class'] ?? '' ) && preg_match( '/^[1-9][0-9]{0,19}$/', $id ) ) {
			return 'wpforms:' . $id;
		}
		return null;
	}

	/**
	 * Processes the entry.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	public function handle( Submission $submission ): Result {
		$entry = $submission->fields['wpforms'] ?? null;
		if ( ! function_exists( 'wpforms' ) || ! is_array( $entry ) || (string) ( $entry['id'] ?? '' ) !== $submission->id ) {
			return Result::error();
		}
		$process = wpforms()->obj( 'process' );
		if ( ! is_object( $process ) || ! method_exists( $process, 'process' ) ) {
			return Result::error();
		}

		$redirect = '';
		$catch    = static function ( $url ) {
			throw new RedirectCaught( (string) $url ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught below, never printed.
		};
		foreach ( self::BYPASS as $filter ) {
			add_filter( $filter, '__return_true', PHP_INT_MAX );
		}
		add_filter( 'wpforms_process_redirect_url', $catch, PHP_INT_MAX );
		try {
			Request::run(
				$submission,
				$submission->fields + array( 'action' => 'wpforms_submit' ),
				static function () use ( $process, $entry ) {
					$process->process( $entry );
				}
			);
		} catch ( RedirectCaught $e ) {
			$redirect = $e->url;
		} finally {
			foreach ( self::BYPASS as $filter ) {
				remove_filter( $filter, '__return_true', PHP_INT_MAX );
			}
			remove_filter( 'wpforms_process_redirect_url', $catch, PHP_INT_MAX );
		}

		$id     = (int) $submission->id;
		$errors = is_array( $process->errors ) && ! empty( $process->errors[ $id ] ) ? (array) $process->errors[ $id ] : array();
		if ( $errors ) {
			$header = '';
			$fields = array();
			foreach ( $errors as $field => $error ) {
				if ( in_array( (string) $field, array( 'header', 'footer', 'recaptcha', 'header_styled', 'footer_styled' ), true ) ) {
					$header = '' === $header && is_string( $error ) ? $error : $header;
					continue;
				}
				foreach ( self::field_errors( 'wpforms[fields][' . $field . ']', $error ) as $name => $text ) {
					$fields[ $name ] = $text;
				}
			}
			return new Result( $fields ? Result::INVALID : Result::ERROR, $header, $fields );
		}

		$result = Result::sent(
			method_exists( $process, 'get_confirmation_message' )
				? (string) $process->get_confirmation_message( $process->form_data, $process->fields, $process->entry_id )
				: ''
		);
		if ( '' !== $redirect ) {
			$result->redirect = $redirect;
		}
		return $result;
	}

	/**
	 * Flattens an error for a field with sub-fields (a name's first and last parts) into input names.
	 *
	 * @param string $name  Input name.
	 * @param mixed  $error Message or array of messages.
	 * @return array<string,string>
	 */
	private static function field_errors( string $name, $error ): array {
		if ( is_string( $error ) ) {
			return array( $name => $error );
		}
		$out = array();
		foreach ( (array) $error as $part => $message ) {
			$out = array_merge( $out, self::field_errors( $name . '[' . $part . ']', $message ) );
		}
		return $out;
	}
}
