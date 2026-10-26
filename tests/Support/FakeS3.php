<?php
/**
 * In-memory stand-in for S3 and CloudFront, for deployer tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Support;

use Stillpress\Deploy\Aws\Transport;

/**
 * Answers the S3 and CloudFront calls the S3 deployer makes and keeps the bucket's objects in memory.
 *
 * Set $fail to a callable( array $request ): ?array to inject a response for chosen requests, and $after to a
 * callable( array $request ) that runs after each request (for example to move a deployer's deadline).
 */
final class FakeS3 implements Transport {

	/** @var array<int,array{method:string,url:string,headers:array<string,string>,body:string}> Every request, in order. */
	public $requests = array();

	/** @var array<string,array{body:string,headers:array<string,string>}> Objects by key. */
	public $objects = array();

	/** @var callable|null */
	public $fail;

	/** @var callable|null */
	public $after;

	/** @var array<string,array<int,string>> Multipart parts by upload ID. */
	private $uploads = array();

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
	 * Requests whose method is $method and URL contains $needle.
	 *
	 * @return array<int,array{method:string,url:string,headers:array<string,string>,body:string}>
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
		$parts = wp_parse_url( $request['url'] );
		$query = $parts['query'] ?? '';
		if ( 'cloudfront.amazonaws.com' === $parts['host'] ) {
			return self::response( 'POST' === $request['method'] ? 201 : 200, '<Invalidation><Id>I1</Id></Invalidation>' );
		}
		$key = rawurldecode( ltrim( $parts['path'] ?? '', '/' ) );
		if ( 'HEAD' === $request['method'] ) {
			return self::response( 200 );
		}
		if ( 'POST' === $request['method'] && 'delete=' === $query ) {
			preg_match_all( '#<Key>([^<]*)</Key>#', $request['body'], $m );
			$xml = '<DeleteResult>';
			foreach ( $m[1] as $k ) {
				$k = html_entity_decode( $k, ENT_QUOTES | ENT_XML1 );
				unset( $this->objects[ $k ] );
				$xml .= '<Deleted><Key>' . htmlspecialchars( $k, ENT_XML1 ) . '</Key></Deleted>';
			}
			return self::response( 200, $xml . '</DeleteResult>' );
		}
		if ( 'POST' === $request['method'] && 'uploads=' === $query ) {
			$id                   = 'up-' . count( $this->uploads );
			$this->uploads[ $id ] = array();
			return self::response( 200, '<InitiateMultipartUploadResult><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>' );
		}
		parse_str( $query, $q );
		if ( 'PUT' === $request['method'] && isset( $q['partNumber'] ) ) {
			$this->uploads[ $q['uploadId'] ][ (int) $q['partNumber'] ] = $request['body'];
			return self::response( 200, '', array( 'etag' => '"' . md5( $request['body'] ) . '"' ) );
		}
		if ( 'POST' === $request['method'] && isset( $q['uploadId'] ) ) {
			$parts = $this->uploads[ $q['uploadId'] ] ?? null;
			if ( null === $parts ) {
				return self::response( 404, '<Error><Code>NoSuchUpload</Code></Error>' );
			}
			ksort( $parts );
			$this->objects[ $key ] = array(
				'body'    => implode( '', $parts ),
				'headers' => array(),
			);
			return self::response( 200, '<CompleteMultipartUploadResult/>' );
		}
		if ( 'DELETE' === $request['method'] && isset( $q['uploadId'] ) ) {
			unset( $this->uploads[ $q['uploadId'] ] );
			return self::response( 204 );
		}
		if ( 'PUT' === $request['method'] ) {
			$this->objects[ $key ] = array(
				'body'    => $request['body'],
				'headers' => $request['headers'],
			);
			return self::response( 200, '', array( 'etag' => '"' . md5( $request['body'] ) . '"' ) );
		}
		return self::response( 400, '<Error><Code>Unexpected</Code></Error>' );
	}

	public static function response( int $status, string $body = '', array $headers = array() ): array {
		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
			'error'   => '',
		);
	}
}
