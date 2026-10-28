<?php
/**
 * Deploys to Cloudflare as a Worker that serves only static assets.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Deploy\Aws\RequestsTransport;
use Stillpress\Deploy\Aws\Transport;
use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;
use Stillpress\Support\Logger;
use Stillpress\Support\Settings;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads files from the build cache (see DECISIONS.md D9).

/**
 * Publishes the build with Cloudflare's direct upload for Workers static assets (DECISIONS.md D17):
 *
 * 1. `POST /accounts/{account}/workers/scripts/{worker}/assets-upload-session` with every file's hash and size;
 *    Cloudflare answers with an upload token and the buckets of hashes it doesn't have yet.
 * 2. `POST /accounts/{account}/workers/assets/upload?base64=true` once per bucket (multipart, one part per file
 *    named by its hash); the last upload returns a completion token.
 * 3. `PUT /accounts/{account}/workers/scripts/{worker}` with only a metadata part: an assets-only Worker that serves
 *    the uploaded files, with trailing-slash HTML handling and the site's 404.html for missing pages.
 *
 * A Worker with that name that this deployer didn't create is refused unless "Replace an existing Worker" is on,
 * because uploading replaces whatever code it runs (threat T16). Unchanged sites make no API calls. The upload
 * session (valid for an hour) is saved with the buckets still to send, so a deploy resumes across slices.
 *
 * @link https://developers.cloudflare.com/workers/static-assets/direct-upload/
 */
final class Cloudflare implements ConfigurableDeployerInterface, ResumableDeployerInterface {

	/** API base. */
	const API = 'https://api.cloudflare.com/client/v4';

	/** Largest file Workers static assets accept. */
	const MAX_FILE = 26214400;

	/** Compatibility date sent with the Worker. Assets-only Workers run no code, so this only needs to be valid. */
	const COMPATIBILITY_DATE = '2025-09-01';

	/** Upload sessions last an hour; don't resume one older than this. */
	const SESSION_TTL = 3300;

	/** Option holding per-Worker state: target key => {deployed, owned, session, fingerprint, buckets, completion, started}. */
	const STATE_OPTION = 'stillpress_cloudflare_state';

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
	 * Recorded types of directory indexes that aren't HTML (feeds), loaded when a deploy starts.
	 *
	 * @var array<string,array{url:string,type:string}>
	 */
	private $served = array();

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
		return 'cloudflare';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Cloudflare (Workers static assets)', 'stillpress' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			'account_id'       => array(
				'label'       => __( 'Account ID', 'stillpress' ),
				'required'    => true,
				'pattern'     => '[a-f0-9]{32}',
				'description' => __( 'Shown on the Workers & Pages overview in the Cloudflare dashboard.', 'stillpress' ),
			),
			'worker_name'      => array(
				'label'       => __( 'Worker name', 'stillpress' ),
				'required'    => true,
				'pattern'     => '[a-z0-9][a-z0-9\-]{0,62}',
				'description' => __( 'A new name is created on the first publish. Add a custom domain or route to it in the Cloudflare dashboard.', 'stillpress' ),
			),
			'api_token'        => array(
				'label'       => __( 'API token', 'stillpress' ),
				'type'        => 'secret',
				'required'    => true,
				'description' => __( 'A token with the "Workers Scripts: Edit" permission for this account.', 'stillpress' ),
			),
			'not_found'        => array(
				'label'   => __( 'Missing pages', 'stillpress' ),
				'type'    => 'select',
				'default' => '404-page',
				'options' => array(
					'404-page' => __( 'Show the site\'s 404 page', 'stillpress' ),
					'none'     => __( 'Plain 404 response', 'stillpress' ),
				),
			),
			'replace_existing' => array(
				'label'       => __( 'Replace an existing Worker', 'stillpress' ),
				'type'        => 'bool',
				'default'     => false,
				'description' => __( 'Allow publishing to a Worker that Stillpress didn\'t create. Any code it runs is replaced by the static site.', 'stillpress' ),
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
			return sprintf( __( 'Cloudflare needs these settings: %s.', 'stillpress' ), implode( ', ', $missing ) );
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
		try {
			$exists = $this->worker_exists();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		if ( $exists && ! $this->state()['owned'] && ! TargetConfig::value( $this, 'replace_existing' ) ) {
			return $this->foreign_worker_message();
		}
		return null;
	}

	/**
	 * Publishes the manifest.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the settings are incomplete, the Worker isn't ours, or Cloudflare refuses.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$this->served = ( new \Stillpress\Storage\UrlRepository() )->directory_types();
		$key          = $this->target_key();
		$files        = new TargetFiles( 'cff-' . $key );
		$hashes       = new TargetFiles( 'cfh-' . $key );
		$held         = $files->all();
		$known        = $hashes->all();
		$source       = new PathMapper( $source_root );
		$result       = new DeployResult();

		$result->location = 'cloudflare:' . TargetConfig::value( $this, 'worker_name' );

		// Hash every file the way Cloudflare's own example does, reusing hashes of unchanged files.
		$entries = array();
		$local   = array();
		$changed = array();
		foreach ( $manifest as $path => $sha256 ) {
			$path = (string) $path;
			try {
				$file = $source->absolute_path( $path );
			} catch ( UnsafePathException $e ) {
				$logger->error( sprintf( 'Could not publish %s: %s', $path, $e->getMessage() ) );
				++$result->failed;
				continue;
			}
			$size = filesize( $file );
			if ( false === $size || $size > self::MAX_FILE ) {
				$logger->error( sprintf( 'Could not publish %s: Cloudflare accepts files up to 25 MiB.', $path ) );
				++$result->failed;
				continue;
			}
			if ( isset( $held[ $path ], $known[ $path ] ) && $held[ $path ] === $sha256 ) {
				$hash = $known[ $path ];
			} else {
				$contents = file_get_contents( $file );
				if ( false === $contents ) {
					$logger->error( sprintf( 'Could not read %s from the build cache.', $path ) );
					++$result->failed;
					continue;
				}
				$hash             = self::hash( $contents, $path );
				$changed[ $path ] = (string) $sha256;
			}
			$entries[ '/' . $path ] = array(
				'hash' => $hash,
				'size' => (int) $size,
			);
			$local[ $hash ]         = array(
				'file' => $file,
				'path' => $path,
			);
		}//end foreach
		$deleted = array_diff_key( $held, $manifest );
		ksort( $entries );
		$fingerprint = sha1( (string) wp_json_encode( $entries ) );
		$state       = $this->state();

		if ( $state['deployed'] === $fingerprint && ! $changed && ! $deleted ) {
			$result->unchanged = count( $entries );
			return $result;
			// Cloudflare already serves exactly this site.
		}
		if ( ! $state['owned'] && ! TargetConfig::value( $this, 'replace_existing' ) && $this->worker_exists() ) {
			throw new \RuntimeException( $this->foreign_worker_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		// Start an upload session, or resume the saved one while it is still valid for this exact file list.
		$resume = '' !== $state['session'] && $state['fingerprint'] === $fingerprint && time() - $state['started'] < self::SESSION_TTL;
		if ( ! $resume ) {
			$session = $this->api(
				'POST',
				$this->account_path() . '/workers/scripts/' . rawurlencode( (string) TargetConfig::value( $this, 'worker_name' ) ) . '/assets-upload-session',
				(string) wp_json_encode( array( 'manifest' => (object) $entries ) ),
				'application/json'
			);
			$buckets = array_values( array_filter( (array) ( $session['buckets'] ?? array() ) ) );

			$state['session']     = (string) ( $session['jwt'] ?? '' );
			$state['fingerprint'] = $fingerprint;
			$state['buckets']     = $buckets;
			$state['completion']  = $buckets ? '' : $state['session'];
			// Nothing to upload: the token is already the completion token.
			$state['started'] = time();
			if ( '' === $state['session'] ) {
				throw new \RuntimeException( __( 'Cloudflare did not return an upload token.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			$this->save_state( $state );
		} else {
			$logger->info( 'Continuing the unfinished Cloudflare upload.' );
		}//end if

		$uploaded = 0;
		while ( $state['buckets'] ) {
			if ( $this->out_of_time() ) {
				$result->done      = false;
				$result->unchanged = 0;
				return $result;
			}
			$bucket = (array) $state['buckets'][0];
			$answer = $this->upload_bucket( $bucket, $local, $state['session'] );
			if ( '' !== (string) ( $answer['jwt'] ?? '' ) ) {
				$state['completion'] = (string) $answer['jwt'];
			}
			$uploaded += count( $bucket );
			array_shift( $state['buckets'] );
			$this->save_state( $state );
		}
		if ( '' === $state['completion'] ) {
			$this->save_state( array_merge( $state, array( 'session' => '' ) ) );
			throw new \RuntimeException( __( 'Cloudflare accepted the files but did not confirm the upload. The next publish starts a new upload.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		$this->publish_worker( $state['completion'] );

		foreach ( $changed as $path => $sha256 ) {
			$files->set( $path, $sha256 );
			$hashes->set( $path, $entries[ '/' . $path ]['hash'] );
			if ( isset( $held[ $path ] ) ) {
				++$result->modified;
			} else {
				++$result->added;
			}
		}
		foreach ( array_keys( $deleted ) as $path ) {
			$files->remove( (string) $path );
			$hashes->remove( (string) $path );
			++$result->deleted;
		}
		$result->unchanged = count( $entries ) - count( $changed );
		$this->save_state(
			array(
				'deployed' => $fingerprint,
				'owned'    => true,
			)
		);
		$logger->info( sprintf( 'Cloudflare Worker %s updated (%d files uploaded).', (string) TargetConfig::value( $this, 'worker_name' ), $uploaded ) );
		return $result;
	}

	/**
	 * Content hash in the form Cloudflare's direct-upload example uses: the first 32 hex characters of the SHA-256 of
	 * the base64-encoded contents followed by the file extension (without the dot).
	 *
	 * @param string $contents File contents.
	 * @param string $path     Relative path (for the extension).
	 * @return string
	 */
	public static function hash( string $contents, string $path ): string {
		$base = basename( $path );
		$dot  = strrpos( $base, '.' );
		$ext  = false === $dot || 0 === $dot ? '' : substr( $base, $dot + 1 );
		return substr( hash( 'sha256', base64_encode( $contents ) . $ext ), 0, 32 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cloudflare's hash input.
	}

	/**
	 * Uploads one bucket: a multipart body with one part per file, named by its hash, holding its base64 contents
	 * and the content type to serve it with.
	 *
	 * @param string[]                                     $bucket Hashes.
	 * @param array<string,array{file:string,path:string}> $local  Files by hash.
	 * @param string                                       $jwt    Upload token.
	 * @return array<string,mixed> The API result (with `jwt` after the last bucket).
	 * @throws \RuntimeException When a file is unknown or unreadable, or Cloudflare refuses the upload.
	 */
	private function upload_bucket( array $bucket, array $local, string $jwt ): array {
		$boundary = 'stillpress-' . bin2hex( random_bytes( 12 ) );
		$body     = '';
		foreach ( $bucket as $hash ) {
			$hash = (string) $hash;
			if ( ! isset( $local[ $hash ] ) ) {
				/* translators: %s: content hash. */
				throw new \RuntimeException( sprintf( __( 'Cloudflare asked for a file this build does not have (%s).', 'stillpress' ), $hash ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			$contents = file_get_contents( $local[ $hash ]['file'] );
			if ( false === $contents ) {
				/* translators: %s: file path. */
				throw new \RuntimeException( sprintf( __( 'Could not read %s from the build cache.', 'stillpress' ), $local[ $hash ]['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			$body .= '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . $hash . '"; filename="' . $hash . "\"\r\n"
				. 'Content-Type: ' . $this->content_type( $local[ $hash ]['path'] ) . "\r\n\r\n"
				. base64_encode( $contents ) . "\r\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes base64 parts.
		}
		$body .= '--' . $boundary . "--\r\n";
		return $this->api( 'POST', $this->account_path() . '/workers/assets/upload?base64=true', $body, 'multipart/form-data; boundary=' . $boundary, $jwt );
	}

	/**
	 * Uploads the Worker: metadata only, no code.
	 *
	 * @param string $completion Completion token from the asset upload.
	 * @return void
	 * @throws \RuntimeException When Cloudflare refuses it.
	 */
	private function publish_worker( string $completion ): void {
		$metadata = array(
			'compatibility_date' => self::COMPATIBILITY_DATE,
			'assets'             => array(
				'jwt'    => $completion,
				'config' => array(
					'html_handling'      => 'auto-trailing-slash',
					'not_found_handling' => '404-page' === TargetConfig::value( $this, 'not_found' ) ? '404-page' : 'none',
				),
			),
		);
		$boundary = 'stillpress-' . bin2hex( random_bytes( 12 ) );
		$body     = '--' . $boundary . "\r\n"
			. "Content-Disposition: form-data; name=\"metadata\"\r\n"
			. "Content-Type: application/json\r\n\r\n"
			. wp_json_encode( $metadata ) . "\r\n"
			. '--' . $boundary . "--\r\n";
		$this->api( 'PUT', $this->account_path() . '/workers/scripts/' . rawurlencode( (string) TargetConfig::value( $this, 'worker_name' ) ), $body, 'multipart/form-data; boundary=' . $boundary );
	}

	/**
	 * True when a Worker with the configured name exists in the account.
	 *
	 * @return bool
	 * @throws \RuntimeException When the account can't be read.
	 */
	private function worker_exists(): bool {
		$name = (string) TargetConfig::value( $this, 'worker_name' );
		foreach ( (array) $this->api( 'GET', $this->account_path() . '/workers/scripts', '', '' ) as $script ) {
			if ( is_array( $script ) && ( $script['id'] ?? '' ) === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Content type a file is served with. Text types carry a charset; unknown types send none ("application/null"
	 * tells Cloudflare not to set the header, per its upload API).
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private function content_type( string $path ): string {
		$type = S3::content_type( $path, $this->served );
		return 'application/octet-stream' === $type ? 'application/null' : $type;
	}

	/**
	 * Calls the API and returns `result` from its envelope.
	 *
	 * @param string $method       Method.
	 * @param string $path         Path under the API base.
	 * @param string $body         Body.
	 * @param string $content_type Content type, or ''.
	 * @param string $bearer       Token to use instead of the API token (upload JWTs).
	 * @return array<mixed>
	 * @throws \RuntimeException On a failed request or an error answer.
	 */
	private function api( string $method, string $path, string $body, string $content_type, string $bearer = '' ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . ( '' === $bearer ? TargetConfig::value( $this, 'api_token' ) : $bearer ),
			'User-Agent'    => 'Stillpress/' . ( defined( 'STILLPRESS_VERSION' ) ? STILLPRESS_VERSION : 'dev' ) . ' (WordPress plugin)',
		);
		if ( '' !== $content_type ) {
			$headers['Content-Type'] = $content_type;
		}
		$response = $this->transport()->send(
			array(
				array(
					'method'  => $method,
					'url'     => self::API . $path,
					'headers' => $headers,
					'body'    => $body,
				),
			)
		)[0];
		if ( 0 === $response['status'] ) {
			/* translators: %s: error message. */
			throw new \RuntimeException( sprintf( __( 'Could not reach Cloudflare: %s', 'stillpress' ), $response['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$data = json_decode( $response['body'], true );
		if ( $response['status'] >= 200 && $response['status'] < 300 && is_array( $data ) && ! empty( $data['success'] ) ) {
			return is_array( $data['result'] ?? null ) ? $data['result'] : array();
		}
		if ( 401 === $response['status'] || 403 === $response['status'] ) {
			throw new \RuntimeException( __( 'Cloudflare refused the API token, or it lacks the "Workers Scripts: Edit" permission for this account.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( 429 === $response['status'] ) {
			throw new \RuntimeException( __( 'Cloudflare\'s rate limit was reached. The changes stay pending and go out with the next publish.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$messages = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['errors'] ?? array() ) : array() ) as $error ) {
			if ( is_array( $error ) ) {
				$messages[] = trim( ( $error['code'] ?? '' ) . ' ' . ( $error['message'] ?? '' ) );
			}
		}
		/* translators: 1: HTTP status, 2: error messages. */
		throw new \RuntimeException( sprintf( __( 'Cloudflare answered with HTTP %1$d: %2$s', 'stillpress' ), $response['status'], substr( implode( '; ', $messages ), 0, 400 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
	}

	/**
	 * Message for a Worker this deployer didn't create.
	 *
	 * @return string
	 */
	private function foreign_worker_message(): string {
		/* translators: %s: Worker name. */
		return sprintf( __( 'A Worker named %s already exists and Stillpress didn\'t create it. Choose a new name, or turn on "Replace an existing Worker" if its code may be replaced by the static site.', 'stillpress' ), (string) TargetConfig::value( $this, 'worker_name' ) );
	}

	/**
	 * API path of the account.
	 *
	 * @return string
	 */
	private function account_path(): string {
		return '/accounts/' . rawurlencode( (string) TargetConfig::value( $this, 'account_id' ) );
	}

	/**
	 * Identifies this account and Worker in the records.
	 *
	 * @return string 32 hex characters.
	 */
	private function target_key(): string {
		return substr( sha1( TargetConfig::value( $this, 'account_id' ) . '|' . TargetConfig::value( $this, 'worker_name' ) ), 0, 32 );
	}

	/**
	 * State for this account and Worker.
	 *
	 * @return array{deployed:string,owned:bool,session:string,fingerprint:string,buckets:array<int,array<int,string>>,completion:string,started:int}
	 */
	private function state(): array {
		$all   = get_option( self::STATE_OPTION, array() );
		$state = is_array( $all ) && isset( $all[ $this->target_key() ] ) && is_array( $all[ $this->target_key() ] ) ? $all[ $this->target_key() ] : array();
		return array(
			'deployed'    => (string) ( $state['deployed'] ?? '' ),
			'owned'       => ! empty( $state['owned'] ),
			'session'     => (string) ( $state['session'] ?? '' ),
			'fingerprint' => (string) ( $state['fingerprint'] ?? '' ),
			'buckets'     => isset( $state['buckets'] ) && is_array( $state['buckets'] ) ? array_values( $state['buckets'] ) : array(),
			'completion'  => (string) ( $state['completion'] ?? '' ),
			'started'     => (int) ( $state['started'] ?? 0 ),
		);
	}

	/**
	 * Saves the state for this account and Worker.
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	private function save_state( array $state ): void {
		$all                        = get_option( self::STATE_OPTION, array() );
		$all                        = is_array( $all ) ? $all : array();
		$all[ $this->target_key() ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	/**
	 * Transport.
	 *
	 * @return Transport
	 */
	private function transport(): Transport {
		if ( null === $this->transport ) {
			$this->transport = new RequestsTransport( 1, 120.0 );
		}
		return $this->transport;
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
