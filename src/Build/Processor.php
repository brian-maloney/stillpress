<?php
/**
 * Turns a fetched or copied URL into a file in the build cache.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Crawl\AssetCopier;
use Stillpress\Crawl\LinkExtractor;
use Stillpress\Crawl\Seeds;
use Stillpress\Crawl\UrlClassifier;
use Stillpress\Output\Cache;
use Stillpress\Output\UnsafePathException;
use Stillpress\Render\RenderResult;
use Stillpress\Rewrite\OriginSet;
use Stillpress\Rewrite\UrlRewriter;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Logger;

/**
 * Handles one URL's response: decides what file (if any) it becomes, rewrites it, writes it to the cache,
 * and reports the URLs it links to.
 *
 * Outcomes:
 * - published: a file was written (or confirmed unchanged).
 * - redirect:  recorded as a redirect (a meta-refresh file unless it would collide with a page).
 * - gone:      the origin says 404/410; any old file is removed.
 * - failed:    transport or server error; the previously published file (if any) is kept.
 * - refused:   the response is unsafe to publish (rendered logged-in, path conflict, unsafe path).
 */
final class Processor {

	/**
	 * Origin addresses.
	 *
	 * @var OriginSet
	 */
	private $origins;

	/**
	 * Rewriter.
	 *
	 * @var UrlRewriter
	 */
	private $rewriter;

	/**
	 * Link extractor.
	 *
	 * @var LinkExtractor
	 */
	private $extractor;

	/**
	 * Classifier.
	 *
	 * @var UrlClassifier
	 */
	private $classifier;

	/**
	 * Build cache.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * URL repository.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Build ID.
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * Constructor.
	 *
	 * @param OriginSet     $origins    Origin addresses.
	 * @param UrlRewriter   $rewriter   Rewriter.
	 * @param LinkExtractor $extractor  Link extractor.
	 * @param UrlClassifier $classifier Classifier.
	 * @param Cache         $cache      Build cache.
	 * @param UrlRepository $urls       URL repository.
	 * @param Logger        $logger     Logger.
	 * @param int           $build_id   Build ID.
	 */
	public function __construct( OriginSet $origins, UrlRewriter $rewriter, LinkExtractor $extractor, UrlClassifier $classifier, Cache $cache, UrlRepository $urls, Logger $logger, int $build_id ) {
		$this->origins    = $origins;
		$this->rewriter   = $rewriter;
		$this->extractor  = $extractor;
		$this->classifier = $classifier;
		$this->cache      = $cache;
		$this->urls       = $urls;
		$this->logger     = $logger;
		$this->build_id   = $build_id;
	}

	/**
	 * Processes a static file found on disk.
	 *
	 * @param array<string,mixed> $row    URL row.
	 * @param string              $source Absolute file path from AssetCopier.
	 * @return array{outcome:string,discovered:array<int,array{url:string,kind:string}>}
	 */
	public function process_file( array $row, string $source ): array {
		$path = (string) wp_parse_url( $row['url'], PHP_URL_PATH );
		$mime = self::mime_for_extension( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( self::is_text( $mime ) ) {
			$result                          = new RenderResult( $row['url'] );
			$result->status                  = 200;
			$result->headers['content-type'] = $mime;
			$result->body                    = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file validated by AssetCopier.
			return $this->process( $row, $result, 'static' );
		}
		try {
			$relative = $this->relative_path_for( $row['url'], $mime );
			if ( ! $this->claim_path( $row, $relative ) ) {
				return self::outcome( 'refused' );
			}
			$hash = $this->cache->copy( $source, $relative );
		} catch ( UnsafePathException $e ) {
			$this->fail( $row, 'Unsafe output path: ' . $e->getMessage() );
			return self::outcome( 'refused' );
		}
		$this->publish( $row, 'asset', 200, $mime, $relative, $hash, (int) filesize( $source ), 'static' );
		return self::outcome( 'published' );
	}

	/**
	 * Processes an HTTP response.
	 *
	 * @param array<string,mixed> $row        URL row.
	 * @param RenderResult        $result     Response.
	 * @param string|null         $deps_state Force a dependency state (for files read from disk).
	 * @return array{outcome:string,discovered:array<int,array{url:string,kind:string}>}
	 */
	public function process( array $row, RenderResult $result, ?string $deps_state = null ): array {
		$is_probe = '404' === $row['source'];

		if ( null !== $result->error ) {
			$this->fail( $row, 'Request failed: ' . $result->error );
			return self::outcome( 'failed' );
		}

		if ( null === $deps_state && ! $result->recorded ) {
			$deps_state = self::is_text( $result->mime_type() ) && 'text/css' !== $result->mime_type() && 'application/javascript' !== $result->mime_type() ? 'unknown' : 'static';
			if ( 'unknown' === $deps_state && 'page' === $row['kind'] ) {
				$this->logger->warning( sprintf( 'No dependency data for %s; a page cache may have answered. It will be rebuilt on every change.', $row['url'] ) );
			}
		}

		if ( $result->is_redirect() && ! $is_probe ) {
			return $this->handle_redirect( $row, $result, $deps_state );
		}

		if ( $is_probe ) {
			if ( 404 !== $result->status ) {
				$this->logger->warning( sprintf( 'The 404 probe returned HTTP %d instead of 404.', $result->status ) );
			}
			return $this->write_document( $row, $result, '404.html', '404', $deps_state );
		}

		if ( 404 === $result->status || 410 === $result->status ) {
			$this->remove( $row, $result->status );
			return self::outcome( 'gone' );
		}

		if ( 200 !== $result->status ) {
			$this->fail( $row, sprintf( 'HTTP %d', $result->status ) );
			return self::outcome( 'failed' );
		}

		try {
			$relative = $this->relative_path_for( $row['url'], $result->headers['content-type'] ?? 'text/html' );
		} catch ( UnsafePathException $e ) {
			$this->fail( $row, 'Unsafe output path: ' . $e->getMessage() );
			return self::outcome( 'refused' );
		}
		return $this->write_document( $row, $result, $relative, 'asset' === $row['kind'] ? 'asset' : 'page', $deps_state );
	}

	/**
	 * Rewrites a text or binary response and writes it.
	 *
	 * @param array<string,mixed> $row        URL row.
	 * @param RenderResult        $result     Response.
	 * @param string              $relative   Output path.
	 * @param string              $kind       Row kind to store.
	 * @param string|null         $deps_state Dependency state, or null to leave it as the recorder set it.
	 * @return array{outcome:string,discovered:array<int,array{url:string,kind:string}>}
	 */
	private function write_document( array $row, RenderResult $result, string $relative, string $kind, ?string $deps_state ): array {
		$mime = $result->mime_type();
		$body = $result->body;

		if ( 'text/html' === $mime && preg_match( '#<body[^>]*\sclass=["\'][^"\']*\blogged-in\b#i', $body ) ) {
			$this->fail( $row, 'The page rendered for a logged-in user; refusing to publish it.' );
			return self::outcome( 'refused' );
		}

		$discovered = array();
		if ( self::is_text( $mime ) ) {
			foreach ( $this->extractor->extract( $body, $mime, $result->url ) as $link ) {
				$classified = $this->classifier->classify( $link );
				if ( null !== $classified && $classified['url'] !== $row['url'] ) {
					$discovered[ $classified['url'] ] = $classified;
				}
			}
			$body = $this->rewriter->rewrite( $body );

			/**
			 * Filters a document after URL rewriting and before it is hashed and written.
			 *
			 * Use this to strip per-request values (nonces, timestamps) that would otherwise make every build look
			 * like a change.
			 *
			 * @since 0.1.0
			 *
			 * @param string $body Document.
			 * @param string $mime Content type.
			 * @param string $url  Origin URL.
			 */
			$body = (string) apply_filters( 'stillpress_output', $body, $mime, $row['url'] );
		}

		try {
			if ( ! $this->claim_path( $row, $relative ) ) {
				return self::outcome( 'refused' );
			}
			$hash = $this->cache->write( $relative, $body );
		} catch ( UnsafePathException $e ) {
			$this->fail( $row, 'Could not write the file: ' . $e->getMessage() );
			return self::outcome( 'refused' );
		}

		$this->publish( $row, $kind, $result->status, $result->headers['content-type'] ?? '', $relative, $hash, strlen( $body ), $deps_state );
		return array(
			'outcome'    => 'published',
			'discovered' => array_values( $discovered ),
		);
	}

	/**
	 * Records a redirect and writes a meta-refresh page when that doesn't collide with a real page.
	 *
	 * @param array<string,mixed> $row        URL row.
	 * @param RenderResult        $result     Response.
	 * @param string|null         $deps_state Dependency state.
	 * @return array{outcome:string,discovered:array<int,array{url:string,kind:string}>}
	 */
	private function handle_redirect( array $row, RenderResult $result, ?string $deps_state ): array {
		$location   = \Stillpress\Support\Url::resolve( $result->url, $result->headers['location'] );
		$discovered = array();
		if ( null === $location ) {
			$this->fail( $row, 'Redirect without a usable Location header.' );
			return self::outcome( 'failed' );
		}
		$target = $this->classifier->classify( $location );
		if ( null !== $target ) {
			$discovered[] = $target;
		}
		$public_target = $this->rewriter->rewrite_url( $location );

		$fields = array(
			'kind'          => 'redirect',
			'status_code'   => $result->status,
			'redirect_to'   => $public_target,
			'content_type'  => 'text/html',
			'last_build_id' => $this->build_id,
			'rendered_at'   => current_time( 'mysql', true ),
			'error'         => null,
		);
		if ( null !== $deps_state ) {
			$fields['deps_state'] = $deps_state;
		}

		try {
			$relative        = $this->relative_path_for( $row['url'], 'text/html' );
			$target_relative = null !== $target ? $this->relative_path_for( $target['url'], 'text/html' ) : null;
		} catch ( UnsafePathException $e ) {
			$relative        = null;
			$target_relative = null;
		}

		$owner = null === $relative ? null : $this->urls->find_by_path( $relative );
		if ( null !== $relative && $relative !== $target_relative && ( null === $owner || $owner['id'] === $row['id'] ) ) {
			$html = self::redirect_page( $public_target );
			try {
				$fields['content_hash'] = $this->cache->write( $relative, $html );
				$fields['path']         = $relative;
				$fields['size']         = strlen( $html );
			} catch ( UnsafePathException $e ) {
				$fields['path']         = null;
				$fields['content_hash'] = null;
			}
		} else {
			// Same file as the target (e.g. a trailing-slash redirect) or owned by a real page: record only.
			$this->delete_old_file( $row, null );
			$fields['path']         = null;
			$fields['content_hash'] = null;
			$fields['size']         = 0;
		}
		$this->urls->update( (int) $row['id'], $fields );
		return array(
			'outcome'    => 'redirect',
			'discovered' => $discovered,
		);
	}

	/**
	 * Ensures no other published URL owns the path. Redirect files give way to real pages.
	 *
	 * @param array<string,mixed> $row      URL row.
	 * @param string              $relative Output path.
	 * @return bool False on a conflict with another page.
	 */
	private function claim_path( array $row, string $relative ): bool {
		$owner = $this->urls->find_by_path( $relative );
		if ( null === $owner || $owner['id'] === $row['id'] ) {
			return true;
		}
		if ( 'redirect' === $owner['kind'] ) {
			$this->urls->unpublish( (int) $owner['id'] );
			return true;
		}
		$this->fail( $row, sprintf( 'Output file %s is already produced by %s.', $relative, $owner['url'] ) );
		return false;
	}

	/**
	 * Stores a published result.
	 *
	 * @param array<string,mixed> $row        URL row.
	 * @param string              $kind       Kind.
	 * @param int                 $status     HTTP status.
	 * @param string              $type       Content-Type.
	 * @param string              $relative   Output path.
	 * @param string              $hash       SHA-256.
	 * @param int                 $size       Bytes.
	 * @param string|null         $deps_state Dependency state, or null to keep the recorder's value.
	 * @return void
	 */
	private function publish( array $row, string $kind, int $status, string $type, string $relative, string $hash, int $size, ?string $deps_state ): void {
		$this->delete_old_file( $row, $relative );
		$fields = array(
			'kind'          => $kind,
			'status_code'   => $status,
			'content_type'  => substr( $type, 0, 100 ),
			'path'          => $relative,
			'content_hash'  => $hash,
			'size'          => $size,
			'redirect_to'   => null,
			'last_build_id' => $this->build_id,
			'rendered_at'   => current_time( 'mysql', true ),
			'error'         => null,
		);
		if ( null !== $deps_state ) {
			$fields['deps_state'] = $deps_state;
		}
		$this->urls->update( (int) $row['id'], $fields );
	}

	/**
	 * Removes a URL from the published set (404/410).
	 *
	 * @param array<string,mixed> $row    URL row.
	 * @param int                 $status HTTP status.
	 * @return void
	 */
	private function remove( array $row, int $status ): void {
		$this->delete_old_file( $row, null );
		$this->urls->update(
			(int) $row['id'],
			array(
				'status_code'   => $status,
				'path'          => null,
				'content_hash'  => null,
				'size'          => 0,
				'last_build_id' => $this->build_id,
				'rendered_at'   => current_time( 'mysql', true ),
				'error'         => null,
			)
		);
		( new \Stillpress\Deps\DependencyStore() )->forget( (int) $row['id'] );
	}

	/**
	 * Deletes the row's previous file when its path changes (and nobody else owns it).
	 *
	 * @param array<string,mixed> $row      URL row.
	 * @param string|null         $new_path New path, or null when the file goes away.
	 * @return void
	 */
	private function delete_old_file( array $row, ?string $new_path ): void {
		$old = $row['path'] ?? null;
		if ( empty( $old ) || $old === $new_path || empty( $row['content_hash'] ) ) {
			return;
		}
		$owner = $this->urls->find_by_path( (string) $old );
		if ( null === $owner || $owner['id'] === $row['id'] ) {
			$this->cache->delete( (string) $old );
		}
	}

	/**
	 * Records a failure without touching the published file.
	 *
	 * @param array<string,mixed> $row     URL row.
	 * @param string              $message Message.
	 * @return void
	 */
	private function fail( array $row, string $message ): void {
		$this->logger->error( $row['url'] . ': ' . $message );
		$this->urls->update(
			(int) $row['id'],
			array(
				'error'         => substr( $message, 0, 1000 ),
				'last_build_id' => $this->build_id,
			)
		);
	}

	/**
	 * Output path for an origin URL.
	 *
	 * @param string $url          Origin URL.
	 * @param string $content_type Content-Type.
	 * @return string
	 * @throws UnsafePathException When it can't be mapped.
	 */
	private function relative_path_for( string $url, string $content_type ): string {
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$output = $this->origins->output_path_for( '' === $path ? '/' : $path );
		if ( null === $output ) {
			throw new UnsafePathException( 'The URL is outside the public base path.' );
		}
		$relative = $this->cache->mapper()->relative_path( $output, $content_type );

		/**
		 * Filters the output path of a URL, relative to the output root.
		 *
		 * The result is validated again, so it can't escape the output directory.
		 *
		 * @since 0.1.0
		 *
		 * @param string $relative     Relative path such as "about/index.html".
		 * @param string $url          Origin URL.
		 * @param string $content_type Response Content-Type.
		 */
		$filtered = (string) apply_filters( 'stillpress_output_path', $relative, $url, $content_type );
		if ( $filtered !== $relative ) {
			\Stillpress\Output\PathMapper::assert_safe_relative( $filtered );
		}
		return $filtered;
	}

	/**
	 * HTML for a redirect file.
	 *
	 * @param string $target Public URL.
	 * @return string
	 */
	public static function redirect_page( string $target ): string {
		$url = esc_url( $target );
		return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Redirecting…', 'stillpress' ) . '</title>'
			. '<meta name="robots" content="noindex"><link rel="canonical" href="' . $url . '">'
			. '<meta http-equiv="refresh" content="0; url=' . $url . '"></head>'
			. '<body><p><a href="' . $url . '">' . esc_html( $target ) . '</a></p></body></html>' . "\n";
	}

	/**
	 * Is this content type text that may contain URLs?
	 *
	 * @param string $mime Content type.
	 * @return bool
	 */
	public static function is_text( string $mime ): bool {
		return 0 === strpos( $mime, 'text/' )
			|| in_array( $mime, array( 'application/javascript', 'application/x-javascript', 'application/json', 'application/xml', 'image/svg+xml', 'application/manifest+json' ), true )
			|| (bool) preg_match( '#^application/[a-z0-9.\-]+\+(json|xml)$#', $mime );
	}

	/**
	 * Content type for a file extension.
	 *
	 * @param string $ext Extension.
	 * @return string
	 */
	public static function mime_for_extension( string $ext ): string {
		$ext   = strtolower( $ext );
		$known = array(
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'mjs'  => 'application/javascript',
			'map'  => 'application/json',
			'svg'  => 'image/svg+xml',
			'json' => 'application/json',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
			'vtt'  => 'text/vtt',
		);
		if ( isset( $known[ $ext ] ) ) {
			return $known[ $ext ];
		}
		$type = wp_check_filetype( 'x.' . $ext );
		return $type['type'] ? (string) $type['type'] : 'application/octet-stream';
	}

	/**
	 * Outcome without discoveries.
	 *
	 * @param string $outcome Outcome.
	 * @return array{outcome:string,discovered:array<int,array{url:string,kind:string}>}
	 */
	private static function outcome( string $outcome ): array {
		return array(
			'outcome'    => $outcome,
			'discovered' => array(),
		);
	}
}
