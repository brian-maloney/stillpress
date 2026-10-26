<?php
/**
 * Transport over the HTTP library bundled with WordPress.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Aws;

use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\Hooks;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

/**
 * Sends requests with `WpOrg\Requests\Requests::request_multiple`, a few at a time.
 *
 * Redirects are never followed (a signed request must not be replayed to another host), TLS certificates are
 * verified against the CA bundle that ships with WordPress, and nothing is sent to a host other than the one in
 * each request's URL.
 */
final class RequestsTransport implements Transport {

	/**
	 * Requests in flight at once.
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
	 * CA bundle used to verify TLS certificates.
	 *
	 * @var string
	 */
	private $ca_bundle;

	/**
	 * Constructor.
	 *
	 * @param int    $concurrency Requests in flight at once.
	 * @param float  $timeout     Per-request timeout in seconds.
	 * @param string $ca_bundle   CA bundle file; '' for the one that ships with WordPress. Certificates are always verified.
	 */
	public function __construct( int $concurrency = 4, float $timeout = 60.0, string $ca_bundle = '' ) {
		$this->concurrency = max( 1, $concurrency );
		$this->timeout     = $timeout;
		$this->ca_bundle   = '' === $ca_bundle ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : $ca_bundle;
	}

	/**
	 * Request options for one request_multiple() call.
	 *
	 * Built fresh for every call: Requests registers its response parser on the hooks object each time, so hooks
	 * reused across calls would parse every response once per earlier call and fail.
	 *
	 * @return array<string,mixed>
	 */
	private function options(): array {
		// The curl transport applies `verify` to single requests only, not to request_multiple() handles, which would
		// then fall back to the system's CA store. Set the bundle on every handle so the choice above always holds.
		$ca    = $this->ca_bundle;
		$hooks = new Hooks();
		$hooks->register(
			'curl.before_request',
			static function ( &$handle ) use ( $ca ) {
				curl_setopt( $handle, CURLOPT_CAINFO, $ca ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Requests hook hands us the raw handle.
				curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- See above.
				curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- See above.
			}
		);
		return array(
			'hooks'            => $hooks,
			'timeout'          => $this->timeout,
			'connect_timeout'  => min( 10.0, $this->timeout ),
			'follow_redirects' => false,
			'verify'           => $this->ca_bundle,
			'useragent'        => 'Stillpress/' . ( defined( 'STILLPRESS_VERSION' ) ? STILLPRESS_VERSION : 'dev' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int|string,array{method:string,url:string,headers:array<string,string>,body:string}> $requests Requests.
	 * @return array<int|string,array{status:int,headers:array<string,string>,body:string,error:string}>
	 */
	public function send( array $requests ): array {
		$out = array();
		foreach ( array_chunk( $requests, $this->concurrency, true ) as $chunk ) {
			$options = $this->options();
			$batch   = array();
			foreach ( $chunk as $key => $request ) {
				$batch[ $key ] = array(
					'url'     => $request['url'],
					'type'    => $request['method'],
					'headers' => $request['headers'],
					'data'    => $request['body'],
					'options' => $options,
				);
			}
			foreach ( Requests::request_multiple( $batch, $options ) as $key => $response ) {
				$result = array(
					'status'  => 0,
					'headers' => array(),
					'body'    => '',
					'error'   => '',
				);
				if ( $response instanceof Response ) {
					$result['status'] = (int) $response->status_code;
					foreach ( $response->headers as $name => $value ) {
						$result['headers'][ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
					}
					$result['body'] = (string) $response->body;
				} elseif ( $response instanceof RequestsException ) {
					$result['error'] = $response->getMessage();
				} else {
					$result['error'] = 'No response.';
				}
				$out[ $key ] = $result;
			}
		}//end foreach
		return $out;
	}
}
