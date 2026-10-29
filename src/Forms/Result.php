<?php
/**
 * The answer to a form submission.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

/**
 * What the visitor is told. Messages are plain text: plugins' messages can contain HTML written for their own pages,
 * and the client shows answers with textContent, so markup is removed here.
 *
 * JSON shape (shared with the standalone receivers): {ok, status, message, errors: {field name: text}, redirect}.
 */
final class Result {

	const SENT    = 'sent';
	const INVALID = 'invalid';
	const SPAM    = 'spam';
	const LIMITED = 'limited';
	const ERROR   = 'error';

	/**
	 * One of the constants above.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Message for the visitor.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Field name => problem.
	 *
	 * @var array<string,string>
	 */
	public $errors = array();

	/**
	 * Address to send the visitor to, or an empty string.
	 *
	 * @var string
	 */
	public $redirect = '';

	/**
	 * Constructor.
	 *
	 * @param string               $status  Status.
	 * @param string               $message Message (HTML is removed); empty for the default message.
	 * @param array<string,string> $errors  Field problems.
	 */
	public function __construct( string $status, string $message = '', array $errors = array() ) {
		$this->status  = in_array( $status, array( self::SENT, self::INVALID, self::SPAM, self::LIMITED, self::ERROR ), true ) ? $status : self::ERROR;
		$this->message = '' === trim( self::text( $message ) ) ? self::default_message( $this->status ) : self::text( $message );
		foreach ( $errors as $name => $text ) {
			$this->errors[ (string) $name ] = self::text( (string) $text );
		}
	}

	/**
	 * A successful submission.
	 *
	 * @param string $message Message.
	 * @return Result
	 */
	public static function sent( string $message = '' ): Result {
		return new self( self::SENT, $message );
	}

	/**
	 * A submission refused without saying why (spam checks), so bots learn nothing.
	 *
	 * @return Result
	 */
	public static function refused(): Result {
		return new self( self::SPAM );
	}

	/**
	 * Something went wrong on our side, or the form doesn't exist.
	 *
	 * @param string $message Message.
	 * @return Result
	 */
	public static function error( string $message = '' ): Result {
		return new self( self::ERROR, $message );
	}

	/**
	 * Did the submission go through?
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return self::SENT === $this->status;
	}

	/**
	 * HTTP status for the answer.
	 *
	 * @return int
	 */
	public function http_status(): int {
		$codes = array(
			self::SENT    => 200,
			self::INVALID => 422,
			self::SPAM    => 403,
			self::LIMITED => 429,
		);
		return $codes[ $this->status ] ?? 500;
	}

	/**
	 * JSON-ready answer.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'ok'       => $this->ok(),
			'status'   => $this->status,
			'message'  => $this->message,
			'errors'   => (object) $this->errors,
			'redirect' => $this->redirect,
		);
	}

	/**
	 * Builds a result from an answer in the JSON shape above (from a relay or webhook).
	 *
	 * @param mixed $data Decoded JSON.
	 * @return Result|null Null when it isn't that shape.
	 */
	public static function from_array( $data ): ?Result {
		if ( ! is_array( $data ) || ! isset( $data['status'] ) || ! is_string( $data['status'] ) ) {
			return null;
		}
		$errors = array();
		foreach ( (array) ( $data['errors'] ?? array() ) as $name => $text ) {
			if ( is_scalar( $text ) ) {
				$errors[ (string) $name ] = (string) $text;
			}
		}
		$result = new self( $data['status'], is_string( $data['message'] ?? null ) ? $data['message'] : '', $errors );
		if ( isset( $data['redirect'] ) && is_string( $data['redirect'] ) && preg_match( '#^https?://#i', $data['redirect'] ) ) {
			$result->redirect = $data['redirect'];
		}
		return $result;
	}

	/**
	 * Plain text from a message that may hold HTML.
	 *
	 * @param string $html Message.
	 * @return string
	 */
	public static function text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Default message for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function default_message( string $status ): string {
		switch ( $status ) {
			case self::SENT:
				return __( 'Thank you. Your message was sent.', 'stillpress' );
			case self::INVALID:
				return __( 'Please check the fields marked below.', 'stillpress' );
			case self::LIMITED:
				return __( 'Too many messages were sent from your connection. Please try again later.', 'stillpress' );
			case self::SPAM:
				return __( 'Your message couldn’t be sent.', 'stillpress' );
			default:
				return __( 'Your message couldn’t be sent. Please try again later.', 'stillpress' );
		}
	}
}
