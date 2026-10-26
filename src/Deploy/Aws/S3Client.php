<?php
/**
 * Minimal Amazon S3 API client.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Aws;

/**
 * Builds signed S3 requests and reads S3's XML answers. Only the calls a static-site deploy needs: HeadBucket,
 * PutObject, DeleteObjects and the multipart upload calls. Works with AWS and S3-compatible services (custom
 * endpoint, path-style addressing).
 *
 * Requests are sent through a Transport, so tests can replace the network.
 */
final class S3Client {

	/**
	 * Signer (service s3).
	 *
	 * @var SigV4
	 */
	private $signer;

	/**
	 * Transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Endpoint host, with a port when it isn't 443.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Bucket.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * True to address the bucket in the path (https://endpoint/bucket/key) rather than the host name.
	 *
	 * @var bool
	 */
	private $path_style;

	/**
	 * Constructor.
	 *
	 * @param SigV4     $signer     Signer for service "s3".
	 * @param Transport $transport  Transport.
	 * @param string    $endpoint   Host[:port], for example s3.eu-west-1.amazonaws.com.
	 * @param string    $bucket     Bucket name.
	 * @param bool      $path_style Path-style addressing. Buckets with dots always use it, because their virtual-host
	 *                              name doesn't match S3's wildcard TLS certificate.
	 */
	public function __construct( SigV4 $signer, Transport $transport, string $endpoint, string $bucket, bool $path_style ) {
		$this->signer     = $signer;
		$this->transport  = $transport;
		$this->endpoint   = $endpoint;
		$this->bucket     = $bucket;
		$this->path_style = $path_style || false !== strpos( $bucket, '.' );
	}

	/**
	 * Sends requests built by the *_request() methods.
	 *
	 * @param array<int|string,array{method:string,url:string,headers:array<string,string>,body:string}> $requests Requests.
	 * @return array<int|string,array{status:int,headers:array<string,string>,body:string,error:string}>
	 */
	public function send( array $requests ): array {
		return $requests ? $this->transport->send( $requests ) : array();
	}

	/**
	 * HeadBucket: checks the bucket exists and the credentials may use it.
	 *
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function head_bucket_request(): array {
		return $this->request( 'HEAD', '', '', array(), '', SigV4::EMPTY_HASH );
	}

	/**
	 * PutObject.
	 *
	 * @param string               $key     Object key.
	 * @param string               $body    File contents.
	 * @param array<string,string> $headers Content-Type, Cache-Control and similar.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function put_request( string $key, string $body, array $headers ): array {
		return $this->request( 'PUT', $key, '', $headers, $body, hash( 'sha256', $body ) );
	}

	/**
	 * DeleteObjects (at most 1,000 keys).
	 *
	 * @param string[] $keys Object keys.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function delete_request( array $keys ): array {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>false</Quiet>';
		foreach ( $keys as $key ) {
			$xml .= '<Object><Key>' . self::xml( $key ) . '</Key></Object>';
		}
		$xml .= '</Delete>';
		return $this->request(
			'POST',
			'',
			'delete=',
			array(
				'Content-Type' => 'application/xml',
				'Content-MD5'  => base64_encode( md5( $xml, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- S3 requires Content-MD5 on DeleteObjects.
			),
			$xml,
			hash( 'sha256', $xml )
		);
	}

	/**
	 * CreateMultipartUpload.
	 *
	 * @param string               $key     Object key.
	 * @param array<string,string> $headers Content-Type, Cache-Control and similar.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function create_multipart_request( string $key, array $headers ): array {
		return $this->request( 'POST', $key, 'uploads=', $headers, '', SigV4::EMPTY_HASH );
	}

	/**
	 * UploadPart.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload ID.
	 * @param int    $number    Part number, from 1.
	 * @param string $body      Part contents.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function upload_part_request( string $key, string $upload_id, int $number, string $body ): array {
		return $this->request( 'PUT', $key, 'partNumber=' . $number . '&uploadId=' . rawurlencode( $upload_id ), array(), $body, hash( 'sha256', $body ) );
	}

	/**
	 * CompleteMultipartUpload.
	 *
	 * @param string            $key       Object key.
	 * @param string            $upload_id Upload ID.
	 * @param array<int,string> $etags     Part number => ETag.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function complete_multipart_request( string $key, string $upload_id, array $etags ): array {
		ksort( $etags );
		$xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';
		foreach ( $etags as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . self::xml( $etag ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		return $this->request( 'POST', $key, 'uploadId=' . rawurlencode( $upload_id ), array( 'Content-Type' => 'application/xml' ), $xml, hash( 'sha256', $xml ) );
	}

	/**
	 * AbortMultipartUpload.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload ID.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function abort_multipart_request( string $key, string $upload_id ): array {
		return $this->request( 'DELETE', $key, 'uploadId=' . rawurlencode( $upload_id ), array(), '', SigV4::EMPTY_HASH );
	}

	/**
	 * Reads an UploadId from a CreateMultipartUpload answer.
	 *
	 * @param string $body Response body.
	 * @return string '' when missing.
	 */
	public static function upload_id( string $body ): string {
		return preg_match( '#<UploadId>([^<]+)</UploadId>#', $body, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
	}

	/**
	 * Reads a DeleteObjects answer.
	 *
	 * @param string $body Response body.
	 * @return array{deleted:string[],errors:array<string,string>} Deleted keys, and key => "Code: Message" for failures.
	 */
	public static function delete_result( string $body ): array {
		$out = array(
			'deleted' => array(),
			'errors'  => array(),
		);
		if ( preg_match_all( '#<Deleted>.*?<Key>([^<]*)</Key>.*?</Deleted>#s', $body, $m ) ) {
			foreach ( $m[1] as $key ) {
				$out['deleted'][] = html_entity_decode( $key, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}
		}
		if ( preg_match_all( '#<Error>(.*?)</Error>#s', $body, $m ) ) {
			foreach ( $m[1] as $error ) {
				$key = preg_match( '#<Key>([^<]*)</Key>#', $error, $k ) ? html_entity_decode( $k[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
				if ( '' !== $key ) {
					$out['errors'][ $key ] = self::error_text( '<Error>' . $error . '</Error>' );
				}
			}
		}
		return $out;
	}

	/**
	 * True when a response is a success. S3 can answer CompleteMultipartUpload with 200 and an error document.
	 *
	 * @param array{status:int,body:string} $response Response.
	 * @return bool
	 */
	public static function ok( array $response ): bool {
		return $response['status'] >= 200 && $response['status'] < 300 && false === strpos( substr( $response['body'], 0, 200 ), '<Error>' );
	}

	/**
	 * Describes a failed response for the log.
	 *
	 * @param array{status:int,body:string,error:string} $response Response.
	 * @return string
	 */
	public static function describe( array $response ): string {
		if ( 0 === $response['status'] ) {
			return '' === $response['error'] ? 'No response.' : $response['error'];
		}
		$text = self::error_text( $response['body'] );
		return 'HTTP ' . $response['status'] . ( '' === $text ? '' : ' ' . $text );
	}

	/**
	 * "Code: Message" from an S3 error document.
	 *
	 * @param string $xml Error XML.
	 * @return string
	 */
	public static function error_text( string $xml ): string {
		$code    = preg_match( '#<Code>([^<]*)</Code>#', $xml, $m ) ? $m[1] : '';
		$message = preg_match( '#<Message>([^<]*)</Message>#', $xml, $m ) ? $m[1] : '';
		$text    = trim( $code . ( '' !== $code && '' !== $message ? ': ' : '' ) . $message );
		return html_entity_decode( substr( $text, 0, 300 ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Builds and signs a request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $key     Object key ('' for the bucket itself).
	 * @param string               $query   Query string as sent.
	 * @param array<string,string> $headers Extra headers (signed).
	 * @param string               $body    Body.
	 * @param string               $hash    Hex SHA-256 of the body.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	private function request( string $method, string $key, string $query, array $headers, string $body, string $hash ): array {
		$encoded = implode( '/', array_map( array( SigV4::class, 'encode' ), explode( '/', $key ) ) );
		if ( $this->path_style ) {
			$host = $this->endpoint;
			$path = '/' . $this->bucket . ( '' === $key ? '' : '/' . $encoded );
		} else {
			$host = $this->bucket . '.' . $this->endpoint;
			$path = '/' . $encoded;
		}
		$pairs = array();
		foreach ( $headers as $name => $value ) {
			$pairs[] = array( $name, $value );
		}
		$signed = $this->signer->sign( $method, $host, $path, $query, $pairs, $hash, time() );
		return array(
			'method'  => $method,
			'url'     => 'https://' . $host . $path . ( '' === $query ? '' : '?' . $query ),
			'headers' => array_merge( $headers, $signed ),
			'body'    => $body,
		);
	}

	/**
	 * Escapes text for an XML element.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function xml( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
