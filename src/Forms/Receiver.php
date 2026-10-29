<?php
/**
 * Receives form submissions in WordPress.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

use Stillpress\Build\Pipeline;
use Stillpress\Rewrite\OriginSet;
use Stillpress\Rewrite\UrlRewriter;
use Stillpress\Support\Logger;
use Stillpress\Support\Settings;

/**
 * Checks a submission and hands it to its adapter. Two ways in:
 *
 * - direct(): a visitor's browser posted a published form here (`forms = site`). The page origin must be the
 *   public site (when the browser says), then the shared spam rules (Protocol), the optional captcha and the rate
 *   limit apply.
 * - relay(): a separate receiver forwarded a submission it accepted, signed with the forms secret. The signature
 *   must verify and must not have been used before; the spam rules and rate limit apply again (the captcha answer
 *   was spent by the receiver that checked it).
 *
 * After an adapter accepts a submission, `stillpress_form_submitted` fires and, when set up, a signed copy goes to
 * the webhook. Nothing is stored here: what a form plugin stores is up to that plugin.
 */
final class Receiver {

	/** Submissions allowed per address in a window. */
	const RATE_LIMIT = 5;

	/** Window, in seconds. */
	const RATE_WINDOW = 600;

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Adapters.
	 *
	 * @var Adapters
	 */
	private $adapters;

	/**
	 * Current time, for tests.
	 *
	 * @var int|null
	 */
	public $now = null;

	/**
	 * Constructor.
	 *
	 * @param Config   $config   Configuration.
	 * @param Adapters $adapters Adapters.
	 */
	public function __construct( Config $config, Adapters $adapters ) {
		$this->config   = $config;
		$this->adapters = $adapters;
	}

	/**
	 * The receiver for this site.
	 *
	 * @return Receiver
	 */
	public static function for_site(): Receiver {
		$config = new Config();
		return new self( $config, Adapters::defaults( $config ) );
	}

	/**
	 * A submission posted by a visitor.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs      Posted fields.
	 * @param string                              $ip         Visitor IP address.
	 * @param string                              $user_agent Visitor User-Agent.
	 * @param string                              $origin     The request's Origin header, or empty.
	 * @return Result
	 */
	public function direct( array $pairs, string $ip, string $user_agent, string $origin ): Result {
		if ( 'site' !== $this->config->mode() ) {
			return Result::error();
		}
		if ( '' !== $origin && 'null' !== $origin && strtolower( rtrim( $origin, '/' ) ) !== $this->config->allowed_origin() ) {
			return Result::refused();
		}
		if ( null !== Protocol::refusal( $pairs ) ) {
			return Result::refused();
		}
		if ( 'none' !== $this->config->captcha() ) {
			$token = '';
			foreach ( Protocol::CAPTCHA_FIELDS as $field ) {
				$token = '' === $token ? Protocol::value( $pairs, $field ) : $token;
			}
			if ( ! Captcha::verify( $this->config->captcha(), (string) $this->config->captcha_secret(), $token, $ip, $this->config->captcha_site_key() ) ) {
				return new Result( Result::SPAM, __( 'Please complete the check that you\'re not a robot.', 'stillpress' ) );
			}
		}
		if ( ! $this->within_limit( $ip ) ) {
			return new Result( Result::LIMITED );
		}
		return $this->deliver( Submission::from_pairs( $pairs, $ip, $user_agent, false ) );
	}

	/**
	 * A submission forwarded by a separate receiver.
	 *
	 * @param string $body      Request body (JSON).
	 * @param string $signature Signature header.
	 * @return Result
	 */
	public function relay( string $body, string $signature ): Result {
		$secret = (string) $this->config->secret();
		$now    = $this->now ?? time();
		if ( 'off' === $this->config->mode() || '' === $secret || ! Signature::verify( $signature, $body, $secret, $now ) ) {
			return Result::refused();
		}
		$seen = 'stillpress_form_seen_' . substr( hash( 'sha256', $signature ), 0, 40 );
		if ( false !== get_transient( $seen ) ) {
			return Result::refused();
		}
		set_transient( $seen, 1, 2 * Signature::TOLERANCE );

		$payload = json_decode( $body, true );
		$pairs   = Protocol::pairs( is_array( $payload ) ? ( $payload['fields'] ?? null ) : null );
		if ( ! $pairs || null !== Protocol::refusal( $pairs ) ) {
			return Result::refused();
		}
		$ip = is_string( $payload['ip'] ?? null ) ? $payload['ip'] : '';
		if ( ! $this->within_limit( $ip ) ) {
			return new Result( Result::LIMITED );
		}
		return $this->deliver( Submission::from_pairs( $pairs, $ip, is_string( $payload['user_agent'] ?? null ) ? $payload['user_agent'] : '', true ) );
	}

	/**
	 * Hands a submission to its adapter.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	private function deliver( Submission $submission ): Result {
		$adapter = $this->adapters->for_key( $submission->key );
		if ( null === $adapter ) {
			return Result::error();
		}
		try {
			$result = $adapter->handle( $submission );
		} catch ( \Throwable $e ) {
			( new Logger() )->error( sprintf( 'The %s form failed: %s', $submission->key, $e->getMessage() ) );
			$result = Result::error();
		}
		if ( '' !== $result->redirect ) {
			$result->redirect = $this->public_address( $result->redirect );
		}
		if ( $result->ok() ) {
			/**
			 * Fires after a form submission from the static site was accepted.
			 *
			 * @since 0.4.0
			 *
			 * @param \Stillpress\Forms\Submission $submission The submission: form key, fields, page, visitor.
			 * @param \Stillpress\Forms\Result     $result     What the visitor is told.
			 */
			do_action( 'stillpress_form_submitted', $submission, $result );
			$this->webhook( $submission );
		}
		return $result;
	}

	/**
	 * Is this address under the rate limit? Counts the attempt.
	 *
	 * @param string $ip Visitor IP address.
	 * @return bool
	 */
	private function within_limit( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		/**
		 * Filters how many form submissions one IP address may send, and in how many seconds.
		 *
		 * @since 0.4.0
		 *
		 * @param array{0:int,1:int} $limit Submissions and window; 5 in 600 seconds by default. Zero submissions turns
		 *                                  the limit off.
		 * @param string             $ip    Visitor IP address.
		 */
		$limit = (array) apply_filters( 'stillpress_form_rate_limit', array( self::RATE_LIMIT, self::RATE_WINDOW ), $ip );
		$count = (int) ( $limit[0] ?? self::RATE_LIMIT );
		if ( $count <= 0 ) {
			return true;
		}
		// Hashed with the site's salt: the option name doesn't reveal the address.
		$key  = 'stillpress_form_rate_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$used = (int) get_transient( $key );
		if ( $used >= $count ) {
			return false;
		}
		set_transient( $key, $used + 1, max( 1, (int) ( $limit[1] ?? self::RATE_WINDOW ) ) );
		return true;
	}

	/**
	 * Sends a signed copy of an accepted submission to the webhook.
	 *
	 * @param Submission $submission Submission.
	 * @return void
	 */
	private function webhook( Submission $submission ): void {
		$url    = $this->config->webhook();
		$secret = (string) $this->config->secret();
		if ( '' === $url || '' === $secret ) {
			return;
		}
		$body     = self::payload( $submission->key, $submission->page, $submission->pairs, $submission->ip, $submission->user_agent );
		$response = wp_safe_remote_post(
			$url,
			array(
				'body'        => $body,
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'    => 'application/json',
					Signature::HEADER => Signature::sign( $body, $secret, $this->now ?? time() ),
				),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			( new Logger() )->warning( sprintf( 'The forms webhook didn\'t accept a %s submission: %s', $submission->key, $reason ) );
		}
	}

	/**
	 * JSON body of a forwarded submission or webhook.
	 *
	 * @param string                              $form       Form key.
	 * @param string                              $page       Page address.
	 * @param array<int,array{0:string,1:string}> $pairs      Fields.
	 * @param string                              $ip         Visitor IP address.
	 * @param string                              $user_agent Visitor User-Agent.
	 * @return string
	 */
	public static function payload( string $form, string $page, array $pairs, string $ip, string $user_agent ): string {
		return (string) wp_json_encode(
			array(
				'v'          => 1,
				'form'       => $form,
				'page'       => $page,
				'fields'     => $pairs,
				'ip'         => $ip,
				'user_agent' => $user_agent,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Turns an address on this site into the public one (form plugins redirect to permalinks).
	 *
	 * @param string $url Address.
	 * @return string Public address, or empty when it isn't http(s).
	 */
	private function public_address( string $url ): string {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$origins = new OriginSet( home_url( '/' ), Pipeline::public_url( Settings::all() ) );
		return ( new UrlRewriter( $origins ) )->rewrite_url( $url );
	}
}
