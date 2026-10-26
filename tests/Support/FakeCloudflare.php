<?php
/**
 * In-memory stand-in for Cloudflare's Workers static-assets direct upload, for deployer tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Support;

use Stillpress\Deploy\Aws\Transport;

/**
 * Answers the calls the Cloudflare deployer makes. Stored assets persist across sessions, so a new session only asks
 * for content it hasn't seen; each bucket upload must be multipart with base64 parts named by hash whose decoded
 * contents hash (with the extension from the manifest) to that name; the completion token is issued only once every
 * requested hash has arrived; and the Worker upload must carry that token and no script.
 */
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the API's base64 parts.

final class FakeCloudflare implements Transport {

	const TOKEN   = 'cf-api-token-0123456789';
	const ACCOUNT = '0123456789abcdef0123456789abcdef';

	/** @var array<int,array<string,mixed>> */
	public $requests = array();

	/** @var array<string,string> Stored contents by hash. */
	public $store = array();

	/** @var array<string,string> Served types by hash. */
	public $types = array();

	/** @var array<string,array<string,mixed>> Workers by name: manifest (path => hash) and config. */
	public $workers = array();

	/** @var string[] Worker names that exist but were made elsewhere. */
	public $foreign = array();

	/** @var array<string,array<string,mixed>> Upload sessions by JWT. */
	private $sessions = array();

	/** @var int Files per bucket the fake asks for. */
	public $bucket_size = 2;

	/** @var callable|null */
	public $fail;

	/** @var callable|null */
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
		$path   = (string) wp_parse_url( $request['url'], PHP_URL_PATH );
		$auth   = (string) ( $request['headers']['Authorization'] ?? '' );
		$prefix = '/client/v4/accounts/' . self::ACCOUNT;

		if ( 'POST' === $request['method'] && $prefix . '/workers/assets/upload' === $path ) {
			$jwt = substr( $auth, 7 );
			if ( ! isset( $this->sessions[ $jwt ] ) || 'base64=true' !== wp_parse_url( $request['url'], PHP_URL_QUERY ) ) {
				return self::envelope( 401, null, 'Bad upload token' );
			}
			$session =& $this->sessions[ $jwt ];
			foreach ( self::parts( $request ) as $part ) {
				if ( $part['name'] !== $part['filename'] || ! in_array( $part['name'], $session['pending'], true ) ) {
					return self::envelope( 400, null, 'Unexpected part ' . $part['name'] );
				}
				$contents = base64_decode( $part['body'], true );
				if ( false === $contents || substr( hash( 'sha256', $part['body'] . $session['ext'][ $part['name'] ] ), 0, 32 ) !== $part['name'] ) {
					return self::envelope( 400, null, 'Hash mismatch for ' . $part['name'] );
				}
				$this->store[ $part['name'] ] = $contents;
				$this->types[ $part['name'] ] = $part['type'];
				$session['pending']           = array_values( array_diff( $session['pending'], array( $part['name'] ) ) );
			}
			if ( ! $session['pending'] ) {
				$session['complete'] = 'complete-' . substr( $jwt, 4 );
				return self::envelope( 201, array( 'jwt' => $session['complete'] ) );
			}
			return self::envelope( 200, array() );
		}

		if ( 'Bearer ' . self::TOKEN !== $auth ) {
			return self::envelope( 403, null, 'Authentication error' );
		}
		if ( 'GET' === $request['method'] && $prefix . '/workers/scripts' === $path ) {
			$names = array_merge( array_keys( $this->workers ), $this->foreign );
			return self::envelope(
				200,
				array_map(
					static function ( $name ) {
						return array( 'id' => $name );
					},
					$names
				)
			);
		}
		if ( 'POST' === $request['method'] && preg_match( '#^' . preg_quote( $prefix, '#' ) . '/workers/scripts/([a-z0-9\-]+)/assets-upload-session$#', $path, $m ) ) {
			$manifest = (array) json_decode( $request['body'], true )['manifest'];
			$missing  = array();
			$ext      = array();
			foreach ( $manifest as $file => $entry ) {
				$base                  = basename( (string) $file );
				$dot                   = strrpos( $base, '.' );
				$ext[ $entry['hash'] ] = false === $dot || 0 === $dot ? '' : substr( $base, $dot + 1 );
				if ( ! isset( $this->store[ $entry['hash'] ] ) ) {
					$missing[] = $entry['hash'];
				}
			}
			$missing = array_values( array_unique( $missing ) );
			$jwt     = 'jwt-' . bin2hex( random_bytes( 6 ) );

			$this->sessions[ $jwt ] = array(
				'worker'   => $m[1],
				'manifest' => $manifest,
				'pending'  => $missing,
				'ext'      => $ext,
				'complete' => $missing ? '' : $jwt,
			);
			return self::envelope(
				200,
				array(
					'jwt'     => $jwt,
					'buckets' => array_chunk( $missing, $this->bucket_size ),
				)
			);
		}
		if ( 'PUT' === $request['method'] && preg_match( '#^' . preg_quote( $prefix, '#' ) . '/workers/scripts/([a-z0-9\-]+)$#', $path, $m ) ) {
			$parts = self::parts( $request );
			if ( 1 !== count( $parts ) || 'metadata' !== $parts[0]['name'] ) {
				return self::envelope( 400, null, 'Expected only a metadata part' );
			}
			$metadata = json_decode( $parts[0]['body'], true );
			if ( isset( $metadata['main_module'] ) || empty( $metadata['compatibility_date'] ) ) {
				return self::envelope( 400, null, 'Bad metadata' );
			}
			foreach ( $this->sessions as $session ) {
				if ( '' !== $session['complete'] && ( $metadata['assets']['jwt'] ?? '' ) === $session['complete'] && $m[1] === $session['worker'] ) {
					$served = array();
					foreach ( $session['manifest'] as $file => $entry ) {
						$served[ $file ] = $entry['hash'];
					}
					$this->workers[ $m[1] ] = array(
						'manifest' => $served,
						'config'   => $metadata['assets']['config'],
					);
					$this->foreign          = array_values( array_diff( $this->foreign, array( $m[1] ) ) );
					return self::envelope( 200, array( 'id' => $m[1] ) );
				}
			}
			return self::envelope( 400, null, 'Unknown or incomplete assets token' );
		}
		return self::envelope( 404, null, 'Not found' );
	}

	/**
	 * Served files of a Worker: path => contents.
	 */
	public function served( string $worker ): array {
		$out = array();
		foreach ( $this->workers[ $worker ]['manifest'] ?? array() as $file => $hash ) {
			$out[ $file ] = $this->store[ $hash ];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Parses a multipart/form-data body.
	 *
	 * @return array<int,array{name:string,filename:string,type:string,body:string}>
	 */
	private static function parts( array $request ): array {
		if ( ! preg_match( '/boundary=(\S+)/', (string) ( $request['headers']['Content-Type'] ?? '' ), $m ) ) {
			return array();
		}
		$out = array();
		foreach ( explode( '--' . $m[1], $request['body'] ) as $chunk ) {
			if ( false === strpos( $chunk, "\r\n\r\n" ) ) {
				continue;
			}
			list( $head, $body ) = explode( "\r\n\r\n", $chunk, 2 );
			preg_match( '/name="([^"]*)"/', $head, $name );
			preg_match( '/filename="([^"]*)"/', $head, $filename );
			preg_match( '/Content-Type: ([^\r\n]+)/i', $head, $type );
			$out[] = array(
				'name'     => $name[1] ?? '',
				'filename' => $filename[1] ?? '',
				'type'     => $type[1] ?? '',
				'body'     => substr( $body, 0, -2 ),
			);
		}
		return $out;
	}

	public static function envelope( int $status, $result, string $error = '' ): array {
		return array(
			'status'  => $status,
			'headers' => array(),
			'body'    => (string) wp_json_encode(
				array(
					'success' => '' === $error,
					'errors'  => '' === $error ? array() : array(
						array(
							'code'    => 10000,
							'message' => $error,
						),
					),
					'result'  => $result,
				)
			),
			'error'   => '',
		);
	}
}
