<?php
/**
 * In-memory stand-in for the Netlify API's file-digest deploys, for deployer tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Support;

use Stillpress\Deploy\Aws\Transport;

/**
 * Behaves like Netlify for the calls the deployer makes: files are stored by SHA-1 across deploys (so later deploys
 * only ask for new content), a deploy is "preparing" for $prepare_polls polls, then lists what it still needs, and
 * goes live ("ready") once nothing is missing. Uploads must hash to the digest they were declared with.
 */
final class FakeNetlify implements Transport {

	const TOKEN = 'nfp_test_token_123456';

	/** @var array<int,array{method:string,url:string,headers:array<string,string>,body:string}> */
	public $requests = array();

	/** @var array<string,string> Stored file contents by SHA-1. */
	public $store = array();

	/** @var array<string,array<string,string>> Live files per site: path => SHA-1. */
	public $live = array();

	/** @var array<string,array<string,mixed>> Deploys by ID. */
	public $deploys = array();

	/** @var int How many GETs a new deploy stays "preparing". */
	public $prepare_polls = 0;

	/** @var callable|null fn( array $request ): ?array to inject a response. */
	public $fail;

	/** @var callable|null Runs after each request. */
	public $after;

	public function send( array $requests ): array {
		$out = array();
		foreach ( $requests as $key => $request ) {
			$this->requests[] = $request;
			$injected         = $this->fail ? call_user_func( $this->fail, $request ) : null;
			$out[ $key ]      = $injected ?? $this->answer( $request );
			if ( $this->after ) {
				call_user_func( $this->after, $request );
			}
		}
		return $out;
	}

	/**
	 * Requests with this method whose URL contains $needle.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sent( string $method, string $needle = '' ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $r ) use ( $method, $needle ) {
					return $r['method'] === $method && ( '' === $needle || false !== strpos( $r['url'], $needle ) );
				}
			)
		);
	}

	private function answer( array $request ): array {
		if ( 'Bearer ' . self::TOKEN !== ( $request['headers']['Authorization'] ?? '' ) ) {
			return self::json(
				401,
				array(
					'code'    => 401,
					'message' => 'Access Denied',
				)
			);
		}
		$path = (string) wp_parse_url( $request['url'], PHP_URL_PATH );
		if ( ! preg_match( '#^/api/v1(/.*)$#', $path, $m ) ) {
			return self::json( 404, array( 'message' => 'Not Found' ) );
		}
		$route = $m[1];

		if ( 'GET' === $request['method'] && preg_match( '#^/sites/([^/]+)$#', $route, $m ) ) {
			return 'my-site' === rawurldecode( $m[1] )
				? self::json(
					200,
					array(
						'name'    => 'my-site',
						'ssl_url' => 'https://my-site.netlify.app',
					)
				)
				: self::json( 404, array( 'message' => 'Not Found' ) );
		}
		if ( 'POST' === $request['method'] && preg_match( '#^/sites/([^/]+)/deploys$#', $route, $m ) ) {
			if ( 'my-site' !== rawurldecode( $m[1] ) ) {
				return self::json( 404, array( 'message' => 'Not Found' ) );
			}
			$body = json_decode( $request['body'], true );
			$id   = 'd' . ( count( $this->deploys ) + 1 );

			$this->deploys[ $id ] = array(
				'site'  => 'my-site',
				'files' => (array) $body['files'],
				'async' => ! empty( $body['async'] ),
				'polls' => $this->prepare_polls,
				'state' => 'new',
			);
			return self::json( 200, $this->view( $id, false ) );
		}
		if ( 'GET' === $request['method'] && preg_match( '#^/deploys/([^/]+)$#', $route, $m ) ) {
			return isset( $this->deploys[ $m[1] ] ) ? self::json( 200, $this->view( $m[1], true ) ) : self::json( 404, array( 'message' => 'Not Found' ) );
		}
		if ( 'PUT' === $request['method'] && preg_match( '#^/deploys/([^/]+)/files/(.+)$#', $route, $m ) ) {
			$deploy = $this->deploys[ $m[1] ] ?? null;
			$file   = '/' . implode( '/', array_map( 'rawurldecode', explode( '/', $m[2] ) ) );
			if ( null === $deploy || ! isset( $deploy['files'][ $file ] ) ) {
				return self::json( 422, array( 'message' => 'File not in deploy' ) );
			}
			if ( sha1( $request['body'] ) !== $deploy['files'][ $file ] ) {
				return self::json( 422, array( 'message' => 'Checksum mismatch' ) );
			}
			if ( 'application/octet-stream' !== ( $request['headers']['Content-Type'] ?? '' ) ) {
				return self::json( 415, array( 'message' => 'Wrong content type' ) );
			}
			$this->store[ sha1( $request['body'] ) ] = $request['body'];
			return self::json( 200, array( 'id' => sha1( $request['body'] ) ) );
		}
		return self::json( 404, array( 'message' => 'Not Found' ) );
	}

	/**
	 * The deploy as the API shows it; a poll counts down the "preparing" phase.
	 */
	private function view( string $id, bool $poll ): array {
		$deploy =& $this->deploys[ $id ];
		if ( 'new' === $deploy['state'] ) {
			$deploy['state'] = $deploy['async'] && $deploy['polls'] > 0 ? 'preparing' : 'prepared';
		} elseif ( 'preparing' === $deploy['state'] && $poll && --$deploy['polls'] <= 0 ) {
			$deploy['state'] = 'prepared';
		}
		if ( 'preparing' === $deploy['state'] ) {
			return array(
				'id'       => $id,
				'state'    => 'preparing',
				'required' => array(),
			);
		}
		$missing = array_values( array_unique( array_diff( array_values( $deploy['files'] ), array_keys( $this->store ) ) ) );
		if ( ! $missing && in_array( $deploy['state'], array( 'prepared', 'uploading' ), true ) && $poll ) {
			$deploy['state']               = 'ready';
			$this->live[ $deploy['site'] ] = $deploy['files'];
		} elseif ( $missing ) {
			$deploy['state'] = 'prepared';
		}
		return array(
			'id'       => $id,
			'state'    => $deploy['state'],
			'required' => $missing,
		);
	}

	public static function json( int $status, array $data, array $headers = array() ): array {
		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => (string) wp_json_encode( $data ),
			'error'   => '',
		);
	}
}
