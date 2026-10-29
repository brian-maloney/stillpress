<?php
/**
 * REST routes that receive form submissions.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

use Stillpress\Rest\Controller;

/**
 * Two public routes under stillpress/v1:
 *
 *     POST /forms/submit  A visitor's browser posts a published form (forms = site).
 *     POST /forms/relay   A separate receiver forwards a submission, signed with the forms secret.
 *
 * Both are open to anonymous requests on purpose: visitors of the static site aren't logged in. What protects them
 * is in Receiver (origin check, spam rules, captcha, rate limit; the signature for the relay), and adapters run every
 * submission as an anonymous visitor.
 *
 * The client script asks for JSON (`Accept: application/json`). A browser without JavaScript posts the form
 * normally and gets a short HTML page with the message and a link back.
 */
final class Endpoint {

	/** Content type of HTML answers, which rest_pre_serve_request prints as they are. */
	const HTML = 'text/html; charset=UTF-8';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_html' ), 10, 4 );
	}

	/**
	 * Route definitions.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Controller::NAMESPACE,
			'/forms/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'submit' ),
				// Public: anyone may send a form. Receiver::direct() applies the checks.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/forms/relay',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'relay' ),
				// Authenticated by the signature over the body, checked in Receiver::relay().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles a visitor's submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$pairs  = Protocol::from_array( (array) $request->get_body_params() );
		$result = Receiver::for_site()->direct( $pairs, self::client_ip(), (string) $request->get_header( 'user_agent' ), (string) $request->get_header( 'origin' ) );
		return self::respond( $request, $result, Protocol::value( $pairs, Protocol::FIELD_PAGE ) );
	}

	/**
	 * Handles a forwarded submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function relay( \WP_REST_Request $request ): \WP_REST_Response {
		$result = Receiver::for_site()->relay( (string) $request->get_body(), (string) $request->get_header( 'x_stillpress_signature' ) );
		return new \WP_REST_Response( $result->to_array(), $result->http_status() );
	}

	/**
	 * Answers with JSON or, for a plain form post, an HTML page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param Result           $result  Result.
	 * @param string           $page    Page the form was on.
	 * @return \WP_REST_Response
	 */
	private static function respond( \WP_REST_Request $request, Result $result, string $page ): \WP_REST_Response {
		if ( false !== strpos( (string) $request->get_header( 'accept' ), 'application/json' ) ) {
			return new \WP_REST_Response( $result->to_array(), $result->http_status() );
		}
		if ( '' !== $result->redirect && $result->ok() ) {
			$response = new \WP_REST_Response( null, 303 );
			$response->header( 'Location', $result->redirect );
			return $response;
		}
		$response = new \WP_REST_Response( self::page( $result, self::back_link( $page ) ), $result->http_status() );
		$response->header( 'Content-Type', self::HTML );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Prints HTML answers as they are instead of as JSON.
	 *
	 * @param bool              $served  Whether the request has been served.
	 * @param \WP_HTTP_Response $result  Response.
	 * @param \WP_REST_Request  $request Request.
	 * @param \WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function serve_html( $served, $result, $request, $server ): bool {
		unset( $server );
		if ( $served || ! $result instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return (bool) $served;
		}
		$headers = $result->get_headers();
		if ( '/' . Controller::NAMESPACE . '/forms/submit' !== $request->get_route() || self::HTML !== ( $headers['Content-Type'] ?? '' ) ) {
			return false;
		}
		echo (string) $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in page().
		return true;
	}

	/**
	 * The HTML page for a visitor without JavaScript.
	 *
	 * @param Result $result Result.
	 * @param string $back   Address to go back to.
	 * @return string
	 */
	public static function page( Result $result, string $back ): string {
		$items = '';
		foreach ( $result->errors as $text ) {
			$items .= '<li>' . esc_html( $text ) . '</li>';
		}
		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html( $result->message ) . '</title>'
			. '<style>body{font:1.1rem/1.5 system-ui,sans-serif;max-width:36rem;margin:15vh auto;padding:0 1rem}</style></head><body>'
			. '<p>' . esc_html( $result->message ) . '</p>'
			. ( '' === $items ? '' : '<ul>' . $items . '</ul>' )
			. '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the page', 'stillpress' ) . '</a></p></body></html>';
	}

	/**
	 * The page to link back to: the form's page when it is on the public site, else the public home page.
	 *
	 * @param string $page Submitted page address.
	 * @return string
	 */
	private static function back_link( string $page ): string {
		$public = \Stillpress\Build\Pipeline::public_url( \Stillpress\Support\Settings::all() );
		return '' !== $page && Config::origin_of( $page ) === Config::origin_of( $public ) ? $page : $public;
	}

	/**
	 * The visitor's IP address.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the IP address of a visitor who sends a form, used for the rate limit, the captcha check and the
		 * form plugins. By default it is REMOTE_ADDR; behind a proxy or CDN, return the address the proxy reports,
		 * but only for requests that really came through it (forwarding headers are easy to forge).
		 *
		 * @since 0.4.0
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'stillpress_form_client_ip', $ip );
	}
}
