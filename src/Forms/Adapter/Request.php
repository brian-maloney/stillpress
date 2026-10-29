<?php
/**
 * Runs form plugin code as if the visitor had posted to this site.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

use Stillpress\Forms\Submission;

// phpcs:disable WordPress.Security.NonceVerification -- The submission has passed the receiver's checks; plugins read the superglobals themselves.

/**
 * Form plugins read `$_POST` and `$_SERVER` directly. This sets them from a submission for the length of a callback
 * and restores them afterwards: the submitted fields, the visitor's IP address and User-Agent (which matter when a
 * separate receiver forwarded the submission), and no logged-in user, so nothing is attributed to an account.
 */
final class Request {

	/**
	 * Calls a function with the request set up from a submission.
	 *
	 * @param Submission          $submission Submission.
	 * @param array<string,mixed> $post       Value for `$_POST`.
	 * @param callable            $callback   Function to run.
	 * @return mixed What the callback returned.
	 */
	public static function run( Submission $submission, array $post, callable $callback ) {
		$saved                      = array(
			'post'    => $_POST,
			'request' => $_REQUEST,
			'server'  => $_SERVER,
			'user'    => get_current_user_id(),
		);
		$_POST                      = $post; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Plugins sanitize their own fields.
		$_REQUEST                   = $post;
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['HTTP_USER_AGENT'] = $submission->user_agent;
		if ( '' !== $submission->ip ) {
			$_SERVER['REMOTE_ADDR'] = $submission->ip;
		}
		if ( '' !== $submission->page ) {
			$_SERVER['HTTP_REFERER'] = $submission->page;
		}
		wp_set_current_user( 0 );
		try {
			return $callback();
		} finally {
			$_POST    = $saved['post'];
			$_REQUEST = $saved['request'];
			$_SERVER  = $saved['server'];
			wp_set_current_user( $saved['user'] );
		}
	}
}
