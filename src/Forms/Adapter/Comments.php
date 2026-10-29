<?php
/**
 * The comment form.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

use Stillpress\Forms\Result;
use Stillpress\Forms\Submission;

/**
 * Recognizes WordPress's comment form (it posts to wp-comments-post.php with a `comment_post_ID`) and submits comments
 * through `wp_handle_comment_submission()`, so core's checks apply: comments open, required name and email, duplicates,
 * flooding, moderation and the disallowed-keys list. Comments are always anonymous. An approved comment changes its
 * post, so it reaches the static copy with the next publish.
 */
final class Comments implements AdapterInterface {

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'comments';
	}

	/**
	 * Recognizes the comment form.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form.
	 * @return string|null
	 */
	public function detect( array $form ): ?string {
		$path = (string) wp_parse_url( $form['attrs']['action'] ?? '', PHP_URL_PATH );
		if ( isset( $form['hidden']['comment_post_ID'] ) && '/wp-comments-post.php' === substr( $path, -21 ) ) {
			return 'comments';
		}
		return null;
	}

	/**
	 * Adds the comment.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	public function handle( Submission $submission ): Result {
		$fields = $submission->fields;
		$data   = array();
		foreach ( array( 'comment_post_ID', 'author', 'email', 'url', 'comment', 'comment_parent' ) as $name ) {
			if ( isset( $fields[ $name ] ) && is_string( $fields[ $name ] ) ) {
				$data[ $name ] = $fields[ $name ];
			}
		}
		$comment = Request::run(
			$submission,
			$fields,
			static function () use ( $data ) {
				return wp_handle_comment_submission( $data );
			}
		);

		if ( is_wp_error( $comment ) ) {
			$code = $comment->get_error_code();
			if ( in_array( $code, array( 'comment_flood' ), true ) ) {
				return new Result( Result::LIMITED, $comment->get_error_message() );
			}
			$fields_for = array(
				'require_name_email'                 => 'author',
				'require_valid_email'                => 'email',
				'require_valid_comment'              => 'comment',
				'comment_author_column_length'       => 'author',
				'comment_author_email_column_length' => 'email',
				'comment_author_url_column_length'   => 'url',
				'comment_content_column_length'      => 'comment',
			);
			$errors     = isset( $fields_for[ $code ] ) ? array( $fields_for[ $code ] => $comment->get_error_message() ) : array();
			return new Result( Result::INVALID, $comment->get_error_message(), $errors );
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return Result::error();
		}
		if ( 'spam' === $comment->comment_approved || 'trash' === $comment->comment_approved ) {
			return Result::refused();
		}
		if ( '1' === (string) $comment->comment_approved ) {
			return Result::sent( __( 'Thank you. Your comment will appear on the site after the next update.', 'stillpress' ) );
		}
		return Result::sent( __( 'Thank you. Your comment is awaiting moderation.', 'stillpress' ) );
	}
}
