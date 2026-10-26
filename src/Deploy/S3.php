<?php
/**
 * Deploys to Amazon S3 (and S3-compatible storage), optionally invalidating CloudFront.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Deploy\Aws\CloudFrontClient;
use Stillpress\Deploy\Aws\RequestsTransport;
use Stillpress\Deploy\Aws\S3Client;
use Stillpress\Deploy\Aws\SigV4;
use Stillpress\Deploy\Aws\Transport;
use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;
use Stillpress\Support\Logger;
use Stillpress\Support\Settings;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads files from the build cache (see DECISIONS.md D9).

/**
 * Mirrors the build into an S3 bucket under an optional key prefix.
 *
 * Like the local folder deployer, it uploads only files whose hash changed since the last deploy to this bucket
 * and prefix, and deletes only objects it uploaded itself (threat T5): other objects in the bucket are never touched.
 * Files over 16 MB go up as multipart uploads, resumable part by part. When a CloudFront distribution is set, the
 * changed paths are invalidated after the deploy finishes.
 *
 * Minimal IAM policy: s3:ListBucket on the bucket (HeadBucket); s3:PutObject, s3:DeleteObject and
 * s3:AbortMultipartUpload on `bucket/prefix*`; and cloudfront:CreateInvalidation plus cloudfront:ListInvalidations on
 * the distribution, if one is used.
 */
final class S3 implements ConfigurableDeployerInterface, ResumableDeployerInterface {

	/** Files larger than this are uploaded in parts of this size. */
	const PART_SIZE = 16777216;

	/** Bytes of file contents held in memory for one batch of uploads. */
	const BATCH_BYTES = 33554432;

	/** Option holding unfinished multipart uploads: target key => path => {hash, upload_id, etags}. */
	const MULTIPART_OPTION = 'stillpress_s3_multipart';

	/** Option holding paths waiting for a CloudFront invalidation: target key => paths. */
	const INVALIDATE_OPTION = 'stillpress_s3_invalidate';

	/**
	 * Transport (injected by tests; RequestsTransport otherwise).
	 *
	 * @var Transport|null
	 */
	private $transport;

	/**
	 * Stop starting new work after this time (0 = never).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Constructor.
	 *
	 * @param Transport|null $transport Transport; null to use the WordPress HTTP library.
	 */
	public function __construct( ?Transport $transport = null ) {
		$this->transport = $transport;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 's3';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Amazon S3 (or S3-compatible storage)', 'stillpress' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			'bucket'                  => array(
				'label'    => __( 'Bucket', 'stillpress' ),
				'required' => true,
				'pattern'  => '[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]',
			),
			'region'                  => array(
				'label'       => __( 'Region', 'stillpress' ),
				'required'    => true,
				'default'     => 'us-east-1',
				'pattern'     => '[a-z0-9\-]{2,32}',
				'description' => __( 'For example eu-west-1. S3-compatible services document their own value (Cloudflare R2 uses "auto").', 'stillpress' ),
			),
			'prefix'                  => array(
				'label'       => __( 'Key prefix', 'stillpress' ),
				'pattern'     => '[A-Za-z0-9._\-/]{0,200}',
				'description' => __( 'Optional folder inside the bucket, for example "www/". Leave empty to publish at the bucket root.', 'stillpress' ),
			),
			'access_key_id'           => array(
				'label'    => __( 'Access key ID', 'stillpress' ),
				'required' => true,
				'pattern'  => '[A-Za-z0-9]{8,128}',
			),
			'secret_access_key'       => array(
				'label'    => __( 'Secret access key', 'stillpress' ),
				'type'     => 'secret',
				'required' => true,
			),
			'session_token'           => array(
				'label'       => __( 'Session token', 'stillpress' ),
				'type'        => 'secret',
				'description' => __( 'Only for temporary credentials.', 'stillpress' ),
			),
			'endpoint'                => array(
				'label'       => __( 'Custom endpoint', 'stillpress' ),
				'pattern'     => '[A-Za-z0-9.\-]+(:[0-9]{1,5})?',
				'restricted'  => true,
				'description' => __( 'Host name of an S3-compatible service, for example <account>.r2.cloudflarestorage.com. Leave empty for AWS. Always HTTPS.', 'stillpress' ),
			),
			'path_style'              => array(
				'label'       => __( 'Path-style addressing', 'stillpress' ),
				'type'        => 'bool',
				'default'     => false,
				'description' => __( 'Put the bucket in the path instead of the host name. Some S3-compatible services need this.', 'stillpress' ),
			),
			'cache_control'           => array(
				'label'       => __( 'Cache-Control header', 'stillpress' ),
				'pattern'     => '[A-Za-z0-9=,\- ]{0,200}',
				'description' => __( 'Optional, sent with every file. For example "public, max-age=300".', 'stillpress' ),
			),
			'cloudfront_distribution' => array(
				'label'       => __( 'CloudFront distribution ID', 'stillpress' ),
				'pattern'     => '[A-Z0-9]{1,32}',
				'description' => __( 'Optional. Changed pages are invalidated after each deploy.', 'stillpress' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function problem(): ?string {
		$missing = TargetConfig::missing( $this );
		if ( $missing ) {
			/* translators: %s: comma-separated field names. */
			return sprintf( __( 'Amazon S3 needs these settings: %s.', 'stillpress' ), implode( ', ', $missing ) );
		}
		foreach ( explode( '/', $this->prefix() ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return __( 'The S3 key prefix may not contain "." or ".." folders.', 'stillpress' );
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param float $deadline Unix time with microseconds; 0 for no deadline.
	 */
	public function set_deadline( float $deadline ): void {
		$this->deadline = $deadline;
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_connection(): ?string {
		$problem = $this->problem();
		if ( null !== $problem ) {
			return $problem;
		}
		$client   = $this->s3();
		$response = $client->send( array( $client->head_bucket_request() ) )[0];
		if ( 301 === $response['status'] && isset( $response['headers']['x-amz-bucket-region'] ) ) {
			/* translators: %s: AWS region. */
			return sprintf( __( 'The bucket is in region %s. Change the region setting to match.', 'stillpress' ), $response['headers']['x-amz-bucket-region'] );
		}
		if ( 403 === $response['status'] ) {
			return __( 'S3 refused access to the bucket. Check the access key and its permissions.', 'stillpress' );
		}
		if ( 404 === $response['status'] ) {
			return __( 'The bucket does not exist.', 'stillpress' );
		}
		if ( ! S3Client::ok( $response ) ) {
			/* translators: %s: error details. */
			return sprintf( __( 'Could not reach the bucket: %s', 'stillpress' ), S3Client::describe( $response ) );
		}
		$distribution = (string) TargetConfig::value( $this, 'cloudfront_distribution' );
		if ( '' !== $distribution ) {
			$response = $client->send( array( $this->cloudfront()->list_invalidations_request( $distribution ) ) )[0];
			if ( ! S3Client::ok( $response ) ) {
				/* translators: %s: error details. */
				return sprintf( __( 'The bucket works, but CloudFront refused the request: %s', 'stillpress' ), S3Client::describe( $response ) );
			}
		}
		return null;
	}

	/**
	 * Makes the bucket match the manifest.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the settings are incomplete or S3 rejects the credentials or bucket.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$client = $this->s3();
		$files  = new TargetFiles( $this->target_key() );
		$held   = $files->all();
		$source = new PathMapper( $source_root );
		$result = new DeployResult();

		$result->location = 's3://' . TargetConfig::value( $this, 'bucket' ) . '/' . $this->prefix();

		$pending = array();
		foreach ( $manifest as $path => $hash ) {
			$path = (string) $path;
			if ( isset( $held[ $path ] ) && $held[ $path ] === $hash ) {
				++$result->unchanged;
				continue;
			}
			try {
				$file = $source->absolute_path( $path );
			} catch ( UnsafePathException $e ) {
				$logger->error( sprintf( 'Could not deploy %s: %s', $path, $e->getMessage() ) );
				++$result->failed;
				continue;
			}
			if ( ! is_file( $file ) ) {
				$logger->error( sprintf( 'Could not deploy %s: the built file is missing.', $path ) );
				++$result->failed;
				continue;
			}
			$pending[] = array(
				'path' => $path,
				'hash' => (string) $hash,
				'file' => $file,
				'size' => (int) filesize( $file ),
				'new'  => ! isset( $held[ $path ] ),
			);
		}//end foreach

		$batch = array();
		$bytes = 0;
		$limit = max( 1, (int) Settings::get( 'concurrency' ) ) * 2;
		foreach ( $pending as $item ) {
			if ( $this->out_of_time() ) {
				$this->upload_batch( $client, $files, $batch, $result, $logger );
				$result->done = false;
				return $result;
			}
			if ( $item['size'] > self::PART_SIZE ) {
				$this->upload_batch( $client, $files, $batch, $result, $logger );
				$batch = array();
				$bytes = 0;
				$state = $this->upload_multipart( $client, $item, $logger );
				if ( 'more' === $state ) {
					$result->done = false;
					return $result;
				}
				$this->record( $files, $item, 'done' === $state, $result );
				continue;
			}
			$batch[] = $item;
			$bytes  += $item['size'];
			if ( count( $batch ) >= $limit || $bytes >= self::BATCH_BYTES ) {
				$this->upload_batch( $client, $files, $batch, $result, $logger );
				$batch = array();
				$bytes = 0;
			}
		}//end foreach
		$this->upload_batch( $client, $files, $batch, $result, $logger );

		$gone = array_keys( array_diff_key( $held, $manifest ) );
		foreach ( array_chunk( $gone, 1000 ) as $chunk ) {
			if ( $this->out_of_time() ) {
				$result->done = false;
				return $result;
			}
			$this->delete_chunk( $client, $files, array_map( 'strval', $chunk ), $result, $logger );
		}

		$this->invalidate( $client, $logger );
		return $result;
	}

	/**
	 * Uploads a batch of small files concurrently.
	 *
	 * @param S3Client                                                                $client Client.
	 * @param TargetFiles                                                             $files  Target records.
	 * @param array<int,array{path:string,hash:string,file:string,size:int,new:bool}> $batch  Files.
	 * @param DeployResult                                                            $result Result to update.
	 * @param Logger                                                                  $logger Log.
	 * @return void
	 * @throws \RuntimeException When S3 rejects the request itself (credentials, permissions, bucket, region).
	 */
	private function upload_batch( S3Client $client, TargetFiles $files, array $batch, DeployResult $result, Logger $logger ): void {
		if ( ! $batch ) {
			return;
		}
		$requests = array();
		foreach ( $batch as $i => $item ) {
			$body = file_get_contents( $item['file'] );
			if ( false === $body ) {
				$logger->error( sprintf( 'Could not read %s from the build cache.', $item['path'] ) );
				++$result->failed;
				continue;
			}
			$requests[ $i ] = $client->put_request( $this->key( $item['path'] ), $body, $this->object_headers( $item['path'] ) );
		}
		$responses = $client->send( $requests );
		unset( $requests );
		foreach ( $responses as $i => $response ) {
			$item = $batch[ $i ];
			if ( ! S3Client::ok( $response ) ) {
				self::fail_on_rejection( $response, $item['path'] );
				$logger->error( sprintf( 'Could not upload %s to S3: %s', $item['path'], S3Client::describe( $response ) ) );
			}
			$this->record( $files, $item, S3Client::ok( $response ), $result );
		}
	}

	/**
	 * Uploads one large file in parts. Progress is saved after every part, so an upload interrupted by the slice
	 * deadline continues in the next slice.
	 *
	 * @param S3Client                                                     $client Client.
	 * @param array{path:string,hash:string,file:string,size:int,new:bool} $item  File.
	 * @param Logger                                                       $logger Log.
	 * @return string "done", "more" (deadline reached) or "failed".
	 * @throws \RuntimeException When S3 rejects the request itself.
	 */
	private function upload_multipart( S3Client $client, array $item, Logger $logger ): string {
		$key   = $this->key( $item['path'] );
		$all   = get_option( self::MULTIPART_OPTION, array() );
		$all   = is_array( $all ) ? $all : array();
		$slot  = $this->target_key();
		$state = $all[ $slot ][ $item['path'] ] ?? null;
		if ( ! is_array( $state ) || ( $state['hash'] ?? '' ) !== $item['hash'] ) {
			if ( is_array( $state ) && ! empty( $state['upload_id'] ) ) {
				$client->send( array( $client->abort_multipart_request( $key, (string) $state['upload_id'] ) ) );
			}
			$response = $client->send( array( $client->create_multipart_request( $key, $this->object_headers( $item['path'] ) ) ) )[0];
			$upload   = S3Client::ok( $response ) ? S3Client::upload_id( $response['body'] ) : '';
			if ( '' === $upload ) {
				self::fail_on_rejection( $response, $item['path'] );
				$logger->error( sprintf( 'Could not start the upload of %s: %s', $item['path'], S3Client::describe( $response ) ) );
				return 'failed';
			}
			$state = array(
				'hash'      => $item['hash'],
				'upload_id' => $upload,
				'etags'     => array(),
			);
			$this->save_multipart( $item['path'], $state );
		}

		$parts  = (int) ceil( $item['size'] / self::PART_SIZE );
		$handle = fopen( $item['file'], 'rb' );
		if ( false === $handle ) {
			$logger->error( sprintf( 'Could not read %s from the build cache.', $item['path'] ) );
			return 'failed';
		}
		try {
			for ( $number = 1; $number <= $parts; $number++ ) {
				if ( isset( $state['etags'][ $number ] ) ) {
					continue;
				}
				if ( $this->out_of_time() ) {
					return 'more';
				}
				fseek( $handle, ( $number - 1 ) * self::PART_SIZE );
				$body     = (string) fread( $handle, self::PART_SIZE );
				$response = $client->send( array( $client->upload_part_request( $key, (string) $state['upload_id'], $number, $body ) ) )[0];
				unset( $body );
				$etag = $response['headers']['etag'] ?? '';
				if ( ! S3Client::ok( $response ) || '' === $etag ) {
					self::fail_on_rejection( $response, $item['path'] );
					$logger->error( sprintf( 'Could not upload part %d of %s: %s', $number, $item['path'], S3Client::describe( $response ) ) );
					return 'failed';
				}
				$state['etags'][ $number ] = $etag;
				$this->save_multipart( $item['path'], $state );
			}
		} finally {
			fclose( $handle );
		}//end try

		$response = $client->send( array( $client->complete_multipart_request( $key, (string) $state['upload_id'], $state['etags'] ) ) )[0];
		if ( ! S3Client::ok( $response ) ) {
			$logger->error( sprintf( 'Could not finish the upload of %s: %s', $item['path'], S3Client::describe( $response ) ) );
			if ( false !== strpos( $response['body'], 'NoSuchUpload' ) ) {
				$this->save_multipart( $item['path'], null );
			}
			return 'failed';
		}
		$this->save_multipart( $item['path'], null );
		return 'done';
	}

	/**
	 * Deletes up to 1,000 objects this deployer uploaded.
	 *
	 * @param S3Client     $client Client.
	 * @param TargetFiles  $files  Target records.
	 * @param string[]     $paths  Relative paths.
	 * @param DeployResult $result Result to update.
	 * @param Logger       $logger Log.
	 * @return void
	 * @throws \RuntimeException When S3 rejects the request itself.
	 */
	private function delete_chunk( S3Client $client, TargetFiles $files, array $paths, DeployResult $result, Logger $logger ): void {
		$by_key = array();
		foreach ( $paths as $path ) {
			$by_key[ $this->key( $path ) ] = $path;
		}
		$response = $client->send( array( $client->delete_request( array_keys( $by_key ) ) ) )[0];
		if ( ! S3Client::ok( $response ) ) {
			self::fail_on_rejection( $response, 'deleted files' );
			$logger->error( sprintf( 'Could not delete %d files from S3: %s', count( $paths ), S3Client::describe( $response ) ) );
			$result->failed += count( $paths );
			return;
		}
		$answer = S3Client::delete_result( $response['body'] );
		foreach ( $answer['deleted'] as $key ) {
			if ( isset( $by_key[ $key ] ) ) {
				$files->remove( $by_key[ $key ] );
				$this->queue_invalidation( $by_key[ $key ] );
				++$result->deleted;
			}
		}
		foreach ( $answer['errors'] as $key => $error ) {
			$logger->error( sprintf( 'Could not delete %s from S3: %s', $by_key[ $key ] ?? $key, $error ) );
			++$result->failed;
		}
	}

	/**
	 * Records the outcome of one upload.
	 *
	 * @param TargetFiles                                                  $files  Target records.
	 * @param array{path:string,hash:string,file:string,size:int,new:bool} $item   File.
	 * @param bool                                                         $ok     True when the upload succeeded.
	 * @param DeployResult                                                 $result Result to update.
	 * @return void
	 */
	private function record( TargetFiles $files, array $item, bool $ok, DeployResult $result ): void {
		if ( ! $ok ) {
			++$result->failed;
			return;
		}
		$files->set( $item['path'], $item['hash'] );
		$this->queue_invalidation( $item['path'] );
		if ( $item['new'] ) {
			++$result->added;
		} else {
			++$result->modified;
		}
	}

	/**
	 * Throws when S3 refused the request for a reason no retry will fix: a 3xx or 4xx answer to an upload or delete
	 * means wrong credentials, missing permissions, a missing bucket or the wrong region. Server errors (5xx) and
	 * network failures are left to the caller, which logs them; the file is retried by the next deploy.
	 *
	 * @param array{status:int,body:string,error:string} $response Response.
	 * @param string                                     $what     What was being sent, for the message.
	 * @return void
	 * @throws \RuntimeException On a 3xx or 4xx answer.
	 */
	private static function fail_on_rejection( array $response, string $what ): void {
		if ( $response['status'] >= 300 && $response['status'] < 500 ) {
			/* translators: 1: file path, 2: error details. */
			throw new \RuntimeException( sprintf( __( 'S3 rejected %1$s (%2$s). Run "wp stillpress target check s3" to diagnose.', 'stillpress' ), $what, S3Client::describe( $response ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
	}

	/**
	 * Adds a file's public URL paths to the pending CloudFront invalidation.
	 *
	 * @param string $path Relative path.
	 * @return void
	 */
	private function queue_invalidation( string $path ): void {
		if ( '' === (string) TargetConfig::value( $this, 'cloudfront_distribution' ) ) {
			return;
		}
		$all     = get_option( self::INVALIDATE_OPTION, array() );
		$all     = is_array( $all ) ? $all : array();
		$slot    = $this->target_key();
		$current = isset( $all[ $slot ] ) && is_array( $all[ $slot ] ) ? $all[ $slot ] : array();
		if ( array( '/*' ) !== $current ) {
			$current = array_values( array_unique( array_merge( $current, self::public_paths( $path ) ) ) );
			if ( count( $current ) > CloudFrontClient::MAX_PATHS ) {
				$current = array( '/*' );
			}
			$all[ $slot ] = $current;
			update_option( self::INVALIDATE_OPTION, $all, false );
		}
	}

	/**
	 * Sends the pending CloudFront invalidation, if any. A failure is logged but doesn't fail the deploy: the files
	 * are already live, and the cache expires on its own.
	 *
	 * @param S3Client $client Client (for its transport).
	 * @param Logger   $logger Log.
	 * @return void
	 */
	private function invalidate( S3Client $client, Logger $logger ): void {
		$distribution = (string) TargetConfig::value( $this, 'cloudfront_distribution' );
		$all          = get_option( self::INVALIDATE_OPTION, array() );
		$all          = is_array( $all ) ? $all : array();
		$paths        = $all[ $this->target_key() ] ?? array();
		if ( '' === $distribution || ! $paths ) {
			return;
		}
		$request  = $this->cloudfront()->invalidation_request( $distribution, $paths, 'stillpress-' . time() . '-' . bin2hex( random_bytes( 4 ) ) );
		$response = $client->send( array( $request ) )[0];
		unset( $all[ $this->target_key() ] );
		update_option( self::INVALIDATE_OPTION, $all, false );
		if ( S3Client::ok( $response ) ) {
			$logger->info( sprintf( 'Asked CloudFront to refresh %s.', array( '/*' ) === $paths ? 'every page' : count( $paths ) . ' paths' ) );
		} else {
			$logger->error( sprintf( 'CloudFront invalidation failed: %s. Cached copies will update when they expire.', S3Client::describe( $response ) ) );
		}
	}

	/**
	 * URL paths under which visitors fetch a file: the file itself and, for an index.html, its folder.
	 *
	 * @param string $path Relative path.
	 * @return string[]
	 */
	private static function public_paths( string $path ): array {
		$base    = untrailingslashit( (string) wp_parse_url( (string) ( Settings::get( 'public_url' ) ? Settings::get( 'public_url' ) : home_url() ), PHP_URL_PATH ) );
		$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		$paths   = array( $base . '/' . $encoded );
		if ( 'index.html' === basename( $path ) ) {
			$dir     = dirname( $encoded );
			$paths[] = $base . '/' . ( '.' === $dir ? '' : $dir . '/' );
		}
		return $paths;
	}

	/**
	 * Saves or clears the state of one multipart upload.
	 *
	 * @param string                   $path  Relative path.
	 * @param array<string,mixed>|null $state State, or null to forget it.
	 * @return void
	 */
	private function save_multipart( string $path, ?array $state ): void {
		$all  = get_option( self::MULTIPART_OPTION, array() );
		$all  = is_array( $all ) ? $all : array();
		$slot = $this->target_key();
		if ( null === $state ) {
			unset( $all[ $slot ][ $path ] );
			if ( empty( $all[ $slot ] ) ) {
				unset( $all[ $slot ] );
			}
		} else {
			$all[ $slot ][ $path ] = $state;
		}
		update_option( self::MULTIPART_OPTION, $all, false );
	}

	/**
	 * Headers stored with an object.
	 *
	 * @param string $path Relative path.
	 * @return array<string,string>
	 */
	private function object_headers( string $path ): array {
		$headers = array( 'Content-Type' => self::content_type( $path ) );
		$cache   = (string) TargetConfig::value( $this, 'cache_control' );
		if ( '' !== $cache ) {
			$headers['Cache-Control'] = $cache;
		}
		return $headers;
	}

	/**
	 * Content type from a file name. S3 serves objects with the type they were uploaded with.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public static function content_type( string $path ): string {
		$text = array(
			'html'        => 'text/html',
			'htm'         => 'text/html',
			'css'         => 'text/css',
			'js'          => 'text/javascript',
			'mjs'         => 'text/javascript',
			'json'        => 'application/json',
			'xml'         => 'application/xml',
			'xsl'         => 'text/xsl',
			'txt'         => 'text/plain',
			'svg'         => 'image/svg+xml',
			'webmanifest' => 'application/manifest+json',
			'map'         => 'application/json',
		);
		$base = basename( $path );
		$dot  = strrpos( $base, '.' );
		$ext  = false === $dot ? '' : strtolower( substr( $base, $dot + 1 ) );
		if ( '' === $ext ) {
			return 'text/plain; charset=utf-8';
			// _redirects and similar.
		}
		if ( isset( $text[ $ext ] ) ) {
			return $text[ $ext ] . ( 0 === strpos( $text[ $ext ], 'image/' ) ? '' : '; charset=utf-8' );
		}
		foreach ( wp_get_mime_types() as $exts => $type ) {
			if ( in_array( $ext, explode( '|', $exts ), true ) ) {
				return $type;
			}
		}
		return 'application/octet-stream';
	}

	/**
	 * Object key for a relative path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private function key( string $path ): string {
		return $this->prefix() . $path;
	}

	/**
	 * Key prefix, normalized to "" or "folder/".
	 *
	 * @return string
	 */
	private function prefix(): string {
		$prefix = trim( (string) TargetConfig::value( $this, 'prefix' ), '/' );
		return '' === $prefix ? '' : $prefix . '/';
	}

	/**
	 * Identifies this bucket and prefix in the target records, so switching buckets starts a fresh mirror.
	 *
	 * @return string
	 */
	private function target_key(): string {
		return 's3-' . substr( sha1( $this->endpoint() . '|' . TargetConfig::value( $this, 'bucket' ) . '|' . $this->prefix() ), 0, 32 );
	}

	/**
	 * Endpoint host.
	 *
	 * @return string
	 */
	private function endpoint(): string {
		$custom = (string) TargetConfig::value( $this, 'endpoint' );
		return '' === $custom ? 's3.' . TargetConfig::value( $this, 'region' ) . '.amazonaws.com' : $custom;
	}

	/**
	 * S3 client for the current settings.
	 *
	 * @return S3Client
	 */
	private function s3(): S3Client {
		return new S3Client(
			new SigV4( (string) TargetConfig::value( $this, 'access_key_id' ), (string) TargetConfig::value( $this, 'secret_access_key' ), (string) TargetConfig::value( $this, 'region' ), 's3', (string) TargetConfig::value( $this, 'session_token' ) ),
			$this->transport ?? new RequestsTransport( max( 1, (int) Settings::get( 'concurrency' ) ) ),
			$this->endpoint(),
			(string) TargetConfig::value( $this, 'bucket' ),
			(bool) TargetConfig::value( $this, 'path_style' )
		);
	}

	/**
	 * CloudFront client for the current credentials.
	 *
	 * @return CloudFrontClient
	 */
	private function cloudfront(): CloudFrontClient {
		return new CloudFrontClient( new SigV4( (string) TargetConfig::value( $this, 'access_key_id' ), (string) TargetConfig::value( $this, 'secret_access_key' ), 'us-east-1', 'cloudfront', (string) TargetConfig::value( $this, 'session_token' ) ) );
	}

	/**
	 * True once the deadline has passed.
	 *
	 * @return bool
	 */
	private function out_of_time(): bool {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}
}
