<?php
/**
 * CloudFront invalidations.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Aws;

/**
 * Builds signed CreateInvalidation requests. CloudFront is a global service: the API lives at
 * cloudfront.amazonaws.com and is signed for region us-east-1.
 */
final class CloudFrontClient {

	/** API version in the request path. */
	const API = '2020-05-31';

	/** API host. */
	const HOST = 'cloudfront.amazonaws.com';

	/**
	 * More paths than this become one wildcard invalidation. CloudFront bills per path after the first 1,000 a
	 * month, and `/*` counts as one.
	 */
	const MAX_PATHS = 300;

	/**
	 * Signer (service cloudfront, region us-east-1).
	 *
	 * @var SigV4
	 */
	private $signer;

	/**
	 * Constructor.
	 *
	 * @param SigV4 $signer Signer.
	 */
	public function __construct( SigV4 $signer ) {
		$this->signer = $signer;
	}

	/**
	 * CreateInvalidation.
	 *
	 * @param string   $distribution Distribution ID.
	 * @param string[] $paths        URL paths (percent-encoded, starting with "/"). Too many become "/*".
	 * @param string   $reference    Unique caller reference, so a retried request isn't applied twice.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function invalidation_request( string $distribution, array $paths, string $reference ): array {
		$paths = array_values( array_unique( $paths ) );
		if ( count( $paths ) > self::MAX_PATHS || in_array( '/*', $paths, true ) ) {
			$paths = array( '/*' );
		}
		$xml = '<?xml version="1.0" encoding="UTF-8"?><InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/' . self::API . '/"><Paths><Quantity>' . count( $paths ) . '</Quantity><Items>';
		foreach ( $paths as $path ) {
			$xml .= '<Path>' . htmlspecialchars( $path, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</Path>';
		}
		$xml .= '</Items></Paths><CallerReference>' . htmlspecialchars( $reference, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</CallerReference></InvalidationBatch>';

		$path    = '/' . self::API . '/distribution/' . rawurlencode( $distribution ) . '/invalidation';
		$headers = array( 'Content-Type' => 'application/xml' );
		$signed  = $this->signer->sign( 'POST', self::HOST, $path, '', array( array( 'Content-Type', 'application/xml' ) ), hash( 'sha256', $xml ), time() );
		return array(
			'method'  => 'POST',
			'url'     => 'https://' . self::HOST . $path,
			'headers' => array_merge( $headers, $signed ),
			'body'    => $xml,
		);
	}

	/**
	 * ListInvalidations, used by the connection check: it proves the distribution exists and the credentials can
	 * reach it, without changing anything. Needs cloudfront:ListInvalidations (listed in the documented policy).
	 *
	 * @param string $distribution Distribution ID.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	public function list_invalidations_request( string $distribution ): array {
		$path   = '/' . self::API . '/distribution/' . rawurlencode( $distribution ) . '/invalidation';
		$query  = 'MaxItems=1';
		$signed = $this->signer->sign( 'GET', self::HOST, $path, $query, array(), SigV4::EMPTY_HASH, time() );
		return array(
			'method'  => 'GET',
			'url'     => 'https://' . self::HOST . $path . '?' . $query,
			'headers' => $signed,
			'body'    => '',
		);
	}
}
