<?php
/**
 * Renders pages by requesting them from the site itself.
 *
 * @package Stillpress
 */

namespace Stillpress\Render;

use Stillpress\Security\UrlPolicy;
use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\Hooks;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;
use WpOrg\Requests\Transport\Curl;

/**
 * Fetches URLs from the origin as an anonymous visitor would, several at a time.
 *
 * - Every URL is checked by the UrlPolicy before it is requested (threat T1).
 * - No cookies are sent, so the page renders logged out (threat T4).
 * - Redirects are returned, not followed, so each Location can be checked and recorded.
 * - An optional connect-to pin ("host:port") sends the request to another address while keeping the URL,
 *   Host header and TLS SNI. This is needed when the site can't reach its own public address (DECISIONS.md D7).
 */
final class LoopbackClient {

	/**
	 * URL policy.
	 *
	 * @var UrlPolicy
	 */
	private $policy;

	/**
	 * Connect-to target "host:port", or "".
	 *
	 * @var string
	 */
	private $connect_to;

	/**
	 * Maximum parallel requests.
	 *
	 * @var int
	 */
	private $concurrency;

	/**
	 * Per-request timeout in seconds.
	 *
	 * @var float
	 */
	private $timeout;

	/**
	 * Maximum body size in bytes.
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Constructor.
	 *
	 * @param UrlPolicy $policy      URL policy.
	 * @param string    $connect_to  Connect-to pin ("host" or "host:port"), or "" for none.
	 * @param int       $concurrency Parallel requests (1–16).
	 * @param float     $timeout     Timeout per request in seconds.
	 * @param int       $max_bytes   Maximum body size.
	 */
	public function __construct( UrlPolicy $policy, string $connect_to = '', int $concurrency = 4, float $timeout = 30.0, int $max_bytes = 20971520 ) {
		$this->policy      = $policy;
		$this->connect_to  = $connect_to;
		$this->concurrency = max( 1, min( 16, $concurrency ) );
		$this->timeout     = $timeout;
		$this->max_bytes   = $max_bytes;
	}

	/**
	 * Fetches URLs.
	 *
	 * @param array<int|string,array{url:string,headers?:array<string,string>}> $requests Requests keyed by caller ID.
	 * @return array<int|string,RenderResult> Results with the same keys.
	 */
	public function fetch( array $requests ): array {
		$results = array();
		$pending = array();
		foreach ( $requests as $key => $request ) {
			$url = $this->policy->normalize( $request['url'] );
			if ( null === $url ) {
				$result          = new RenderResult( (string) $request['url'] );
				$result->error   = 'Refused by URL policy.';
				$results[ $key ] = $result;
				continue;
			}
			$pending[ $key ] = array(
				'url'     => $url,
				'headers' => $request['headers'] ?? array(),
			);
		}

		foreach ( array_chunk( $pending, $this->concurrency, true ) as $chunk ) {
			foreach ( $this->fetch_chunk( $chunk ) as $key => $result ) {
				$results[ $key ] = $result;
			}
		}
		return $results;
	}

	/**
	 * Sends a fire-and-forget POST to the site itself (used to start background work right away).
	 *
	 * @param string               $url  Origin URL.
	 * @param array<string,string> $body Form fields.
	 * @return bool False when the URL is refused or the request couldn't be sent.
	 */
	public function dispatch( string $url, array $body ): bool {
		$url = $this->policy->normalize( $url );
		if ( null === $url ) {
			return false;
		}
		$hooks   = new Hooks();
		$pin     = $this->pin_option(
			array(
				array(
					'url'     => $url,
					'headers' => array(),
				),
			)
		);
		$options = array(
			'blocking'        => false,
			'timeout'         => 1,
			'connect_timeout' => 1,
			'hooks'           => $hooks,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'verify'          => apply_filters( 'https_local_ssl_verify', false, '' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback TLS verification.
		);
		if ( null !== $pin ) {
			$hooks->register(
				'curl.before_request',
				static function ( &$handle ) use ( $pin ) {
					curl_setopt( $handle, CURLOPT_CONNECT_TO, $pin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- See fetch_chunk().
				}
			);
			$options['transport'] = Curl::class;
		}
		try {
			Requests::request( $url, array(), $body, Requests::POST, $options );
		} catch ( RequestsException $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Fetches one batch in parallel.
	 *
	 * @param array<int|string,array{url:string,headers:array<string,string>}> $chunk Requests.
	 * @return array<int|string,RenderResult>
	 */
	private function fetch_chunk( array $chunk ): array {
		$hooks = new Hooks();
		$pin   = $this->pin_option( $chunk );
		if ( null !== $pin ) {
			$hooks->register(
				'curl.before_request',
				static function ( &$handle ) use ( $pin ) {
					curl_setopt( $handle, CURLOPT_CONNECT_TO, $pin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Requests hook hands us the raw handle; no WP API exists for CONNECT_TO.
				}
			);
		}

		$options = array(
			'timeout'          => $this->timeout,
			'connect_timeout'  => min( 10.0, $this->timeout ),
			'follow_redirects' => false,
			'max_bytes'        => $this->max_bytes,
			'hooks'            => $hooks,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'verify'           => apply_filters( 'https_local_ssl_verify', false, '' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback TLS verification.
			'useragent'        => 'Stillpress/' . ( defined( 'STILLPRESS_VERSION' ) ? STILLPRESS_VERSION : 'dev' ) . ' (static render)',
		);
		if ( null !== $pin ) {
			$options['transport'] = Curl::class;
		}

		$requests = array();
		foreach ( $chunk as $key => $request ) {
			$requests[ $key ] = array(
				'url'     => $request['url'],
				'type'    => Requests::GET,
				'headers' => array_merge( array( 'Accept' => '*/*' ), $request['headers'] ),
				'options' => $options,
			);
		}

		/**
		 * Fires before a batch of loopback render requests is sent.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int|string,array<string,mixed>> $requests Requests keyed by caller ID.
		 */
		do_action( 'stillpress_before_loopback_batch', $requests );

		$responses = Requests::request_multiple( $requests, $options );

		$results = array();
		foreach ( $chunk as $key => $request ) {
			$result   = new RenderResult( $request['url'] );
			$response = $responses[ $key ] ?? null;
			if ( $response instanceof Response ) {
				$result->status = (int) $response->status_code;
				foreach ( $response->headers as $name => $value ) {
					$result->headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				}
				$result->body = (string) $response->body;
			} elseif ( $response instanceof RequestsException ) {
				$result->error = $response->getMessage();
			} else {
				$result->error = 'No response.';
			}
			$results[ $key ] = $result;
		}
		return $results;
	}

	/**
	 * Builds the CURLOPT_CONNECT_TO entries for the hosts in a batch.
	 *
	 * @param array<int|string,array{url:string,headers:array<string,string>}> $chunk Requests.
	 * @return string[]|null
	 */
	private function pin_option( array $chunk ): ?array {
		if ( '' === $this->connect_to ) {
			return null;
		}
		// Accepted forms: a host name or bracketed IPv6 address, optionally followed by a port.
		if ( ! preg_match( '/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)(?::([0-9]{1,5}))?$/', $this->connect_to, $m ) ) {
			return null;
		}
		$pin_host = $m[1];
		$pin_port = isset( $m[2] ) ? (int) $m[2] : 0;

		$entries = array();
		foreach ( $chunk as $request ) {
			$parts = wp_parse_url( $request['url'] );
			$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			$from  = $parts['host'] . ':' . $port;

			$entries[ $from ] = $from . ':' . $pin_host . ':' . ( $pin_port > 0 ? $pin_port : $port );
		}
		return array_values( $entries );
	}
}
