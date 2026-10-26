<?php
/**
 * AWS Signature Version 4 against AWS's published test vectors.
 *
 * The generic cases come from the AWS SigV4 test suite (tests/fixtures/aws4_testsuite, Apache-2.0, see its NOTICE).
 * The S3 cases are the four worked examples from AWS's "Signature Calculations for the Authorization Header"
 * page for Amazon S3 (sig-v4-header-based-auth.html).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Stillpress\Deploy\Aws\SigV4;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads test fixtures.

final class SigV4Test extends TestCase {

	const SUITE = __DIR__ . '/../../fixtures/aws4_testsuite';

	/** Example credentials used throughout the AWS test suite. */
	const SUITE_KEY    = 'AKIDEXAMPLE';
	const SUITE_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	/** Example credentials used in the S3 documentation. */
	const S3_KEY    = 'AKIAIOSFODNN7EXAMPLE';
	const S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

	/**
	 * Every suite case directory (one containing a .req file).
	 *
	 * @return array<string,array{0:string}>
	 */
	public function suite_provider(): array {
		$cases = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::SUITE, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'req' === $file->getExtension() ) {
				$base = substr( $file->getPathname(), 0, -4 );
				// The token is added after signing in this case, so it isn't part of the signature (S3 and CloudFront sign it).
				if ( 'post-sts-header-after' !== basename( $base ) ) {
					$cases[ basename( $base ) ] = array( $base );
				}
			}
		}
		ksort( $cases );
		return $cases;
	}

	public function test_suite_is_present(): void {
		$this->assertGreaterThanOrEqual( 30, count( $this->suite_provider() ) );
	}

	/**
	 * @dataProvider suite_provider
	 */
	public function test_suite_case( string $base ): void {
		$req = self::parse_request( (string) file_get_contents( $base . '.req' ) );

		$time  = 0;
		$token = '';
		$other = array();
		foreach ( $req['headers'] as $h ) {
			$name = strtolower( $h[0] );
			if ( 'x-amz-date' === $name ) {
				$time = (int) gmmktime( (int) substr( $h[1], 9, 2 ), (int) substr( $h[1], 11, 2 ), (int) substr( $h[1], 13, 2 ), (int) substr( $h[1], 4, 2 ), (int) substr( $h[1], 6, 2 ), (int) substr( $h[1], 0, 4 ) );
			} elseif ( 'x-amz-security-token' === $name ) {
				$token = $h[1];
			} elseif ( 'host' !== $name ) {
				$other[] = $h;
			}
		}
		// The session-token case's .req leaves the token out; the suite's harness adds it. Take it from the .creq.
		if ( '' === $token && preg_match( '/^x-amz-security-token:(.*)$/m', self::read( $base . '.creq' ), $m ) ) {
			$token            = $m[1];
			$req['headers'][] = array( 'X-Amz-Security-Token', $token );
		}
		$host   = self::header( $req['headers'], 'host' );
		$hash   = hash( 'sha256', $req['body'] );
		$signer = new SigV4( self::SUITE_KEY, self::SUITE_SECRET, 'us-east-1', 'service', $token );

		$canonical = $signer->canonical_request( $req['method'], $req['path'], $req['query'], $req['headers'], $hash );
		$this->assertSame( self::read( $base . '.creq' ), $canonical, 'Canonical request' );
		$this->assertSame( self::read( $base . '.sts' ), $signer->string_to_sign( $canonical, $time ), 'String to sign' );

		$signed = $signer->sign( $req['method'], $host, $req['path'], $req['query'], $other, $hash, $time );
		$this->assertSame( self::read( $base . '.authz' ), $signed['Authorization'] );
	}

	/**
	 * The four S3 examples: method, path, query, extra headers, body, expected signed headers and signature.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:array<int,array{0:string,1:string}>,4:string,5:string,6:string}>
	 */
	public function s3_provider(): array {
		return array(
			'GET Object'           => array( 'GET', '/test.txt', '', array( array( 'Range', 'bytes=0-9' ) ), '', 'host;range;x-amz-content-sha256;x-amz-date', 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41' ),
			'PUT Object'           => array( 'PUT', '/test$file.text', '', array( array( 'Date', 'Fri, 24 May 2013 00:00:00 GMT' ), array( 'x-amz-storage-class', 'REDUCED_REDUNDANCY' ) ), 'Welcome to Amazon S3.', 'date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class', '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd' ),
			'GET Bucket Lifecycle' => array( 'GET', '/', 'lifecycle', array(), '', 'host;x-amz-content-sha256;x-amz-date', 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543' ),
			'List Objects'         => array( 'GET', '/', 'max-keys=2&prefix=J', array(), '', 'host;x-amz-content-sha256;x-amz-date', '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7' ),
		);
	}

	/**
	 * @dataProvider s3_provider
	 *
	 * @param array<int,array{0:string,1:string}> $headers Extra headers.
	 */
	public function test_s3_example( string $method, string $path, string $query, array $headers, string $body, string $signed_headers, string $signature ): void {
		$signer = new SigV4( self::S3_KEY, self::S3_SECRET, 'us-east-1', 's3' );
		$out    = $signer->sign( $method, 'examplebucket.s3.amazonaws.com', $path, $query, $headers, hash( 'sha256', $body ), (int) gmmktime( 0, 0, 0, 5, 24, 2013 ) );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
			$out['Authorization']
		);
		$this->assertSame( '20130524T000000Z', $out['X-Amz-Date'] );
		$this->assertSame( hash( 'sha256', $body ), $out['x-amz-content-sha256'] );
	}

	public function test_s3_paths_are_not_normalized(): void {
		$s3      = new SigV4( 'k', 's', 'us-east-1', 's3' );
		$generic = new SigV4( 'k', 's', 'us-east-1', 'service' );
		$headers = array( array( 'host', 'h' ) );
		$this->assertStringStartsWith( "GET\n/my-object//example//photo.user\n", $s3->canonical_request( 'GET', '/my-object//example//photo.user', '', $headers, SigV4::EMPTY_HASH ) );
		$this->assertStringStartsWith( "GET\n/my-object/example/photo.user\n", $generic->canonical_request( 'GET', '/my-object//example//photo.user', '', $headers, SigV4::EMPTY_HASH ) );
	}

	public function test_already_encoded_paths(): void {
		$s3      = new SigV4( 'k', 's', 'us-east-1', 's3' );
		$generic = new SigV4( 'k', 's', 'us-east-1', 'service' );
		$headers = array( array( 'host', 'h' ) );
		// S3 signs the key as sent; other services encode the sent path again.
		$this->assertStringStartsWith( "PUT\n/a%20b/%E2%9C%93.html\n", $s3->canonical_request( 'PUT', '/a%20b/%E2%9C%93.html', '', $headers, SigV4::EMPTY_HASH ) );
		$this->assertStringStartsWith( "GET\n/a%2520b\n", $generic->canonical_request( 'GET', '/a%20b', '', $headers, SigV4::EMPTY_HASH ) );
	}

	public function test_session_token_is_signed(): void {
		$out = ( new SigV4( 'k', 's', 'us-east-1', 'cloudfront', 'tok' ) )->sign( 'POST', 'cloudfront.amazonaws.com', '/2020-05-31/distribution/E1/invalidation', '', array(), SigV4::EMPTY_HASH, 0 );
		$this->assertSame( 'tok', $out['X-Amz-Security-Token'] );
		$this->assertStringContainsString( 'SignedHeaders=host;x-amz-date;x-amz-security-token,', $out['Authorization'] );
		$this->assertArrayNotHasKey( 'x-amz-content-sha256', $out, 'Only S3 needs the payload hash header.' );
	}

	/**
	 * Parses a suite .req file: request line, headers (continuation lines start with whitespace), blank line, body.
	 *
	 * @param string $raw File contents.
	 * @return array{method:string,path:string,query:string,headers:array<int,array{0:string,1:string}>,body:string}
	 */
	private static function parse_request( string $raw ): array {
		$parts   = explode( "\n\n", str_replace( "\r\n", "\n", $raw ), 2 );
		$lines   = explode( "\n", $parts[0] );
		$request = (string) array_shift( $lines );
		$space   = strpos( $request, ' ' );
		$method  = substr( $request, 0, $space );
		$target  = (string) preg_replace( '# HTTP/1\.1$#', '', substr( $request, $space + 1 ) );
		$q       = strpos( $target, '?' );
		$headers = array();
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue; // A trailing newline with no body.
			}
			if ( ( ' ' === $line[0] || "\t" === $line[0] ) && $headers ) {
				$headers[ count( $headers ) - 1 ][1] .= ' ' . trim( $line );
				continue;
			}
			$colon     = strpos( $line, ':' );
			$headers[] = array( substr( $line, 0, $colon ), substr( $line, $colon + 1 ) );
		}
		return array(
			'method'  => $method,
			'path'    => false === $q ? $target : substr( $target, 0, $q ),
			'query'   => false === $q ? '' : substr( $target, $q + 1 ),
			'headers' => $headers,
			'body'    => $parts[1] ?? '',
		);
	}

	/**
	 * First header value by name.
	 *
	 * @param array<int,array{0:string,1:string}> $headers Headers.
	 * @param string                              $name    Lowercase name.
	 * @return string
	 */
	private static function header( array $headers, string $name ): string {
		foreach ( $headers as $h ) {
			if ( strtolower( $h[0] ) === $name ) {
				return trim( $h[1] );
			}
		}
		return '';
	}

	/**
	 * Reads a fixture with normalized line endings.
	 *
	 * @param string $path File.
	 * @return string
	 */
	private static function read( string $path ): string {
		return str_replace( "\r\n", "\n", (string) file_get_contents( $path ) );
	}
}
