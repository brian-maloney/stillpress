<?php
/**
 * Transport over the HTTP library bundled with WordPress.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Aws;

use WpOrg\Requests\Exception as RequestsException;
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
	 * Constructor.
	 *
	 * @param int   $concurrency Requests in flight at once.
	 * @param float $timeout     Per-request timeout in seconds.
	 */
	public function __construct( int $concurrency = 4, float $timeout = 60.0 ) {
		$this->concurrency = max( 1, $concurrency );
		$this->timeout     = $timeout;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int|string,array{method:string,url:string,headers:array<string,string>,body:string}> $requests Requests.
	 * @return array<int|string,array{status:int,headers:array<string,string>,body:string,error:string}>
	 */
	public function send( array $requests ): array {
		$options = array(
			'timeout'          => $this->timeout,
			'connect_timeout'  => min( 10.0, $this->timeout ),
			'follow_redirects' => false,
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'useragent'        => 'Stillpress/' . ( defined( 'STILLPRESS_VERSION' ) ? STILLPRESS_VERSION : 'dev' ),
		);
		$out     = array();
		foreach ( array_chunk( $requests, $this->concurrency, true ) as $chunk ) {
			$batch = array();
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
