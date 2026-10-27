<?php
/**
 * Deploys to a Netlify site.
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
 * Publishes the build to a Netlify site with the API's file-digest method:
 *
 * 1. `POST /sites/{site}/deploys` with every file's SHA-1 (`async` so large sites don't hit the 30-second limit);
 * 2. Netlify answers with the hashes it doesn't have yet (`required`);
 * 3. `PUT /deploys/{id}/files/{path}` for each of those; the deploy goes live once nothing is missing.
 *
 * A Netlify deploy is always the whole site, and Netlify keeps every earlier deploy for rollback. Netlify allows
 * three API deploys a minute and 100 a day, so a site whose files haven't changed since the last deploy creates no
 * deploy at all. The deploy ID is saved, so an interrupted upload resumes in the next slice instead of starting over.
 *
 * @link https://docs.netlify.com/api/get-started/#file-digest-method
 */
final class Netlify implements ConfigurableDeployerInterface, ResumableDeployerInterface {

	/** API base. */
	const API = 'https://api.netlify.com/api/v1';

	/** Option holding unfinished deploys: target key => {deploy, digest}. */
	const STATE_OPTION = 'stillpress_netlify_state';

	/** Longest time to wait in one call for Netlify to prepare an async deploy. */
	const PREPARE_WAIT = 20;

	/** Slices to wait for a fully uploaded deploy to go live before starting a fresh one. */
	const MAX_WAITS = 3;

	/** Deploy states that mean Netlify has every file. */
	const RECEIVED = array( 'uploaded', 'processing', 'ready' );

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
	 * Called instead of sleep() while polling (tests).
	 *
	 * @var callable|null
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Transport|null $transport Transport; null to use the WordPress HTTP library.
	 * @param callable|null  $sleeper   Replacement for sleep( 1 ) between polls.
	 */
	public function __construct( ?Transport $transport = null, ?callable $sleeper = null ) {
		$this->transport = $transport;
		$this->sleeper   = $sleeper;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'netlify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Netlify', 'stillpress' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			'site_id' => array(
				'label'       => __( 'Site ID', 'stillpress' ),
				'required'    => true,
				'pattern'     => '[A-Za-z0-9.\-]{1,100}',
				'description' => __( 'The site\'s API ID (Site configuration → Site details) or its netlify.app address. Every publish replaces this site\'s files; Netlify keeps earlier deploys for rollback.', 'stillpress' ),
			),
			'token'   => array(
				'label'       => __( 'Personal access token', 'stillpress' ),
				'type'        => 'secret',
				'required'    => true,
				'description' => __( 'Create one under User settings → Applications → Personal access tokens.', 'stillpress' ),
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
			return sprintf( __( 'Netlify needs these settings: %s.', 'stillpress' ), implode( ', ', $missing ) );
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
			$site = $this->api( 'GET', '/sites/' . rawurlencode( $this->site() ) );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		if ( '' === (string) ( $site['name'] ?? '' ) ) {
			return __( 'Netlify answered, but not with a site.', 'stillpress' );
		}
		return null;
	}

	/**
	 * Publishes the manifest as a new deploy.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the settings are incomplete or Netlify refuses or fails the deploy.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$key    = $this->target_key();
		$files  = new TargetFiles( 'nlf-' . $key );
		$sha1s  = new TargetFiles( 'nls-' . $key );
		$held   = $files->all();
		$known  = $sha1s->all();
		$source = new PathMapper( $source_root );
		$result = new DeployResult();

		$result->location = 'netlify:' . $this->site();

		// Every file's SHA-1 (Netlify's digest), reusing the last one when the content hasn't changed.
		$digest  = array();
		$local   = array();
		$changed = array();
		foreach ( $manifest as $path => $hash ) {
			$path = (string) $path;
			if ( false !== strpbrk( $path, '#?' ) ) {
				$logger->error( sprintf( 'Could not publish %s: Netlify does not accept "#" or "?" in file names.', $path ) );
				++$result->failed;
				continue;
			}
			try {
				$file = $source->absolute_path( $path );
			} catch ( UnsafePathException $e ) {
				$logger->error( sprintf( 'Could not publish %s: %s', $path, $e->getMessage() ) );
				++$result->failed;
				continue;
			}
			if ( isset( $held[ $path ], $known[ $path ] ) && $held[ $path ] === $hash ) {
				$sha1 = $known[ $path ];
			} else {
				$sha1 = sha1_file( $file );
				if ( false === $sha1 ) {
					$logger->error( sprintf( 'Could not read %s from the build cache.', $path ) );
					++$result->failed;
					continue;
				}
				$changed[ $path ] = (string) $hash;
			}
			$digest[ '/' . $path ] = $sha1;
			$local[ $sha1 ]        = $file;
		}//end foreach
		$deleted = array_diff_key( $held, $manifest );
		$state   = $this->state();
		ksort( $digest );
		$fingerprint = sha1( (string) wp_json_encode( $digest ) );

		if ( ! $changed && ! $deleted && '' === $state['deploy'] && $held ) {
			$result->unchanged = count( $digest );
			return $result;
			// Netlify already serves exactly this site; don't spend a deploy on it.
		}

		$deploy = null;
		if ( '' !== $state['deploy'] && $state['digest'] === $fingerprint ) {
			try {
				$deploy = $this->api( 'GET', '/deploys/' . rawurlencode( $state['deploy'] ) );
			} catch ( \RuntimeException $e ) {
				$deploy = array( 'state' => 'error' );
				// Expired or deleted on Netlify's side: start over.
			}
			if ( in_array( $deploy['state'] ?? '', array( 'error', 'rejected' ), true ) ) {
				$deploy = null;
				// Start a fresh deploy.
			} else {
				$logger->info( 'Continuing the unfinished Netlify deploy.' );
			}
		}
		if ( null === $deploy ) {
			$deploy = $this->api(
				'POST',
				'/sites/' . rawurlencode( $this->site() ) . '/deploys',
				array(
					'files' => (object) $digest,
					'async' => true,
				)
			);
			// A new deploy starts with nothing known about what it needs.
			$state = array(
				'deploy'    => (string) ( $deploy['id'] ?? '' ),
				'digest'    => $fingerprint,
				'remaining' => null,
				'waits'     => 0,
			);
			$this->save_state( $state );
		}
		$id = (string) ( $deploy['id'] ?? '' );
		if ( '' === $id ) {
			throw new \RuntimeException( __( 'Netlify did not return a deploy.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		// An async deploy is "preparing" until Netlify has compared the hashes with what it already stores.
		$waited = 0;
		while ( 'preparing' === ( $deploy['state'] ?? '' ) || 'new' === ( $deploy['state'] ?? '' ) ) {
			if ( $this->out_of_time() || $waited >= self::PREPARE_WAIT ) {
				$result->done = false;
				return $this->partial( $result );
			}
			$this->pause();
			++$waited;
			$deploy = $this->api( 'GET', '/deploys/' . rawurlencode( $id ) );
		}
		if ( 'error' === ( $deploy['state'] ?? '' ) ) {
			$this->save_state( array() );
			/* translators: %s: error message from Netlify. */
			throw new \RuntimeException( sprintf( __( 'Netlify could not prepare the deploy: %s', 'stillpress' ), (string) ( $deploy['error_message'] ?? __( 'no reason given', 'stillpress' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		// What is left to upload comes from our own records: once uploading has started, Netlify's deploy no longer
		// lists the files it still needs (testing against the real API showed `required` empty while it waited).
		if ( null === $state['remaining'] ) {
			$state = array(
				'deploy'    => $id,
				'digest'    => $fingerprint,
				'remaining' => array_values( array_unique( array_map( 'strval', (array) ( $deploy['required'] ?? array() ) ) ) ),
				'waits'     => 0,
			);
			$this->save_state( $state );
		}
		$by_hash  = array_flip( $digest );
		$client   = $this->transport();
		$limit    = max( 1, (int) Settings::get( 'concurrency' ) );
		$uploaded = 0;
		foreach ( array_chunk( $state['remaining'], $limit ) as $chunk ) {
			if ( $this->out_of_time() ) {
				$result->done = false;
				return $this->partial( $result );
			}
			$requests = array();
			foreach ( $chunk as $sha1 ) {
				if ( ! isset( $by_hash[ $sha1 ], $local[ $sha1 ] ) ) {
					/* translators: %s: SHA-1 hash. */
					throw new \RuntimeException( sprintf( __( 'Netlify asked for a file this build does not have (%s).', 'stillpress' ), $sha1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
				}
				$body = file_get_contents( $local[ $sha1 ] );
				if ( false === $body ) {
					/* translators: %s: file path. */
					throw new \RuntimeException( sprintf( __( 'Could not read %s from the build cache.', 'stillpress' ), $by_hash[ $sha1 ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
				}
				$path              = implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $by_hash[ $sha1 ], '/' ) ) ) );
				$requests[ $sha1 ] = $this->request( 'PUT', '/deploys/' . rawurlencode( $id ) . '/files/' . $path, $body, 'application/octet-stream' );
			}
			foreach ( $client->send( $requests ) as $sha1 => $response ) {
				if ( $response['status'] < 200 || $response['status'] >= 300 ) {
					// The deploy stays open with this file still listed: the next attempt sends it again.
					/* translators: 1: file path, 2: error. */
					throw new \RuntimeException( sprintf( __( 'Netlify refused the upload of %1$s: %2$s', 'stillpress' ), ltrim( (string) $by_hash[ $sha1 ], '/' ), self::error_text( $response ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
				}
				$state['remaining'] = array_values( array_diff( $state['remaining'], array( (string) $sha1 ) ) );
				++$uploaded;
			}
			$this->save_state( $state );
		}//end foreach

		// Every file is up: the deploy counts once Netlify says it has them all, not before.
		$final  = $this->api( 'GET', '/deploys/' . rawurlencode( $id ) );
		$waited = 0;
		while ( ! in_array( $final['state'] ?? '', self::RECEIVED, true ) ) {
			if ( 'error' === ( $final['state'] ?? '' ) ) {
				$this->save_state( array() );
				/* translators: %s: error message from Netlify. */
				throw new \RuntimeException( sprintf( __( 'Netlify failed the deploy: %s', 'stillpress' ), (string) ( $final['error_message'] ?? __( 'no reason given', 'stillpress' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			if ( $this->out_of_time() || $waited >= self::PREPARE_WAIT ) {
				++$state['waits'];
				if ( $state['waits'] >= self::MAX_WAITS ) {
					$this->save_state( array() );
					/* translators: 1: deploy ID, 2: deploy state. */
					throw new \RuntimeException( sprintf( __( 'Netlify did not finish deploy %1$s (still "%2$s") after every file was uploaded. The next publish starts a new deploy.', 'stillpress' ), $id, (string) ( $final['state'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
				}
				$this->save_state( $state );
				$result->done = false;
				return $this->partial( $result );
			}
			$this->pause();
			++$waited;
			$final = $this->api( 'GET', '/deploys/' . rawurlencode( $id ) );
		}//end while

		foreach ( $changed as $path => $hash ) {
			$files->set( $path, $hash );
			$sha1s->set( $path, $digest[ '/' . $path ] );
			if ( isset( $held[ $path ] ) ) {
				++$result->modified;
			} else {
				++$result->added;
			}
		}
		foreach ( array_keys( $deleted ) as $path ) {
			$files->remove( (string) $path );
			$sha1s->remove( (string) $path );
			++$result->deleted;
		}
		$result->unchanged = count( $digest ) - count( $changed );
		$this->save_state( array() );
		$logger->info( sprintf( 'Netlify deploy %s is %s (%d files uploaded in this run).', $id, (string) ( $final['state'] ?? 'uploaded' ), $uploaded ) );
		return $result;
	}

	/**
	 * A result for a deploy that continues in the next slice: counts are reported once, when it finishes.
	 *
	 * @param DeployResult $result Result.
	 * @return DeployResult
	 */
	private function partial( DeployResult $result ): DeployResult {
		$result->unchanged = 0;
		return $result;
	}

	/**
	 * Calls the API and decodes its JSON answer.
	 *
	 * @param string              $method Method.
	 * @param string              $path   Path under the API base.
	 * @param array<string,mixed> $json   JSON body, if any.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On a failed request or an error answer.
	 */
	private function api( string $method, string $path, array $json = array() ): array {
		$body     = $json ? (string) wp_json_encode( $json ) : '';
		$response = $this->transport()->send( array( $this->request( $method, $path, $body, $json ? 'application/json' : '' ) ) )[0];
		$status   = $response['status'];
		if ( 0 === $status ) {
			/* translators: %s: error message. */
			throw new \RuntimeException( sprintf( __( 'Could not reach Netlify: %s', 'stillpress' ), $response['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( 401 === $status || 403 === $status ) {
			throw new \RuntimeException( __( 'Netlify refused the access token, or it may not deploy this site.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( 404 === $status ) {
			throw new \RuntimeException( __( 'Netlify has no site with that ID that this token can see.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( 429 === $status ) {
			throw new \RuntimeException( __( 'Netlify\'s rate limit was reached (3 deploys a minute, 100 a day). The changes stay pending and go out with the next publish.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$data = json_decode( $response['body'], true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			/* translators: %s: error details. */
			throw new \RuntimeException( sprintf( __( 'Netlify answered with an error: %s', 'stillpress' ), self::error_text( $response ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		return $data;
	}

	/**
	 * Builds a request.
	 *
	 * @param string $method       Method.
	 * @param string $path         Path under the API base.
	 * @param string $body         Body.
	 * @param string $content_type Content type, or ''.
	 * @return array{method:string,url:string,headers:array<string,string>,body:string}
	 */
	private function request( string $method, string $path, string $body, string $content_type ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . TargetConfig::value( $this, 'token' ),
			'User-Agent'    => 'Stillpress/' . ( defined( 'STILLPRESS_VERSION' ) ? STILLPRESS_VERSION : 'dev' ) . ' (WordPress plugin)',
		);
		if ( '' !== $content_type ) {
			$headers['Content-Type'] = $content_type;
		}
		return array(
			'method'  => $method,
			'url'     => self::API . $path,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Short description of a failed response.
	 *
	 * @param array{status:int,body:string,error:string} $response Response.
	 * @return string
	 */
	private static function error_text( array $response ): string {
		if ( 0 === $response['status'] ) {
			return $response['error'];
		}
		$data    = json_decode( $response['body'], true );
		$message = is_array( $data ) ? (string) ( $data['message'] ?? $data['error'] ?? '' ) : '';
		return 'HTTP ' . $response['status'] . ( '' === $message ? '' : ' ' . substr( $message, 0, 300 ) );
	}

	/**
	 * The configured site ID or address.
	 *
	 * @return string
	 */
	private function site(): string {
		return (string) TargetConfig::value( $this, 'site_id' );
	}

	/**
	 * Identifies this site in the target records.
	 *
	 * @return string 32 hex characters.
	 */
	private function target_key(): string {
		return substr( sha1( strtolower( $this->site() ) ), 0, 32 );
	}

	/**
	 * Unfinished deploy for this site.
	 *
	 * @return array{deploy:string,digest:string,remaining:string[]|null,waits:int}
	 */
	private function state(): array {
		$all   = get_option( self::STATE_OPTION, array() );
		$state = is_array( $all ) && isset( $all[ $this->target_key() ] ) && is_array( $all[ $this->target_key() ] ) ? $all[ $this->target_key() ] : array();
		return array(
			'deploy'    => (string) ( $state['deploy'] ?? '' ),
			'digest'    => (string) ( $state['digest'] ?? '' ),
			// Hashes still to upload; null until the deploy is prepared and Netlify has said what it needs.
			'remaining' => isset( $state['remaining'] ) && is_array( $state['remaining'] ) ? array_values( array_map( 'strval', $state['remaining'] ) ) : null,
			'waits'     => (int) ( $state['waits'] ?? 0 ),
		);
	}

	/**
	 * Saves or clears the unfinished deploy.
	 *
	 * @param array<string,mixed> $state State, or an empty array to clear it.
	 * @return void
	 */
	private function save_state( array $state ): void {
		$all = get_option( self::STATE_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( $state ) {
			$all[ $this->target_key() ] = $state;
		} else {
			unset( $all[ $this->target_key() ] );
		}
		update_option( self::STATE_OPTION, $all, false );
	}

	/**
	 * Transport.
	 *
	 * @return Transport
	 */
	private function transport(): Transport {
		if ( null === $this->transport ) {
			$this->transport = new RequestsTransport( max( 1, (int) Settings::get( 'concurrency' ) ), 60.0 );
		}
		return $this->transport;
	}

	/**
	 * Waits a second between polls.
	 *
	 * @return void
	 */
	private function pause(): void {
		if ( null !== $this->sleeper ) {
			call_user_func( $this->sleeper );
			return;
		}
		sleep( 1 );
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
