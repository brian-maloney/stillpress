<?php
/**
 * Files the plugin generates rather than renders.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Output\UnsafePathException;
use Stillpress\Storage\Schema;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Logger;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * Writes the `_redirects` file (understood by several static hosts) from origin redirects and user-defined
 * redirects, plus a meta-refresh page for each user-defined redirect whose path is free.
 *
 * Generated files are stored as URL rows with a "stillpress:" pseudo-URL, so they flow through the same
 * manifest and deploy logic as everything else.
 */
final class Generated {

	/** Most rules Cloudflare reads from `_headers`. */
	const MAX_HEADER_RULES = 100;

	/**
	 * Components.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	/**
	 * URLs.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Build ID.
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * Constructor.
	 *
	 * @param Pipeline      $pipeline Components.
	 * @param UrlRepository $urls     URLs.
	 * @param int           $build_id Build ID.
	 */
	public function __construct( Pipeline $pipeline, UrlRepository $urls, int $build_id ) {
		$this->pipeline = $pipeline;
		$this->urls     = $urls;
		$this->build_id = $build_id;
	}

	/**
	 * Writes all generated files.
	 *
	 * @return void
	 */
	public function write(): void {
		$rules = array();
		$pages = array();

		foreach ( (array) $this->pipeline->settings['redirects'] as $redirect ) {
			$from    = $this->pipeline->origins->public_path_for( (string) $redirect['from'] );
			$to      = '/' === $redirect['to'][0] ? $this->pipeline->origins->public_path_for( (string) $redirect['to'] ) : (string) $redirect['to'];
			$rules[] = array( $from, $to, (int) $redirect['code'] );
			$pages[] = $this->write_redirect_page( (string) $redirect['from'], $to );
		}
		$this->remove_stale_redirect_pages( array_filter( $pages ) );

		foreach ( $this->origin_redirects() as $row ) {
			$path    = (string) wp_parse_url( $row['url'], PHP_URL_PATH );
			$target  = (string) $row['redirect_to'];
			$rules[] = array(
				$this->pipeline->origins->public_path_for( '' === $path ? '/' : $path ),
				$this->relative_if_public( $target ),
				in_array( (int) $row['status_code'], array( 301, 302, 307, 308 ), true ) ? (int) $row['status_code'] : 301,
			);
		}

		/**
		 * Filters redirect rules written to the `_redirects` file.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int,array{0:string,1:string,2:int}> $rules From path, target, status code.
		 */
		$rules = (array) apply_filters( 'stillpress_redirect_rules', $rules );

		$lines = array();
		foreach ( $rules as $rule ) {
			if ( isset( $rule[0], $rule[1] ) && ! preg_match( '/\s/', $rule[0] . $rule[1] ) ) {
				$lines[ $rule[0] ] = $rule[0] . ' ' . $rule[1] . ' ' . (int) ( $rule[2] ?? 301 );
			}
		}
		$this->write_file( 'stillpress:generated/_redirects', '_redirects', $lines ? implode( "\n", $lines ) . "\n" : '' );
		$this->write_headers();
	}

	/**
	 * Writes `_headers` (read by Netlify and Cloudflare) so directory indexes that aren't HTML, such as feeds saved as
	 * feed/index.html, are served with their real Content-Type. Cloudflare reads at most 100 rules, so the file stops
	 * there, shortest paths (the main feeds) first; Cloudflare also gets the type with each upload.
	 *
	 * @return void
	 */
	private function write_headers(): void {
		$rules = array();
		foreach ( $this->urls->directory_types() as $file ) {
			$path    = (string) wp_parse_url( $file['url'], PHP_URL_PATH );
			$rules[] = array( $this->pipeline->origins->public_path_for( '' === $path ? '/' : $path ), array( 'Content-Type' => $file['type'] ) );
		}

		/**
		 * Filters the rules written to the `_headers` file, which Netlify and Cloudflare read.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int,array{0:string,1:array<string,string>}> $rules URL path and the headers to send for it.
		 */
		$rules = (array) apply_filters( 'stillpress_header_rules', $rules );

		$blocks = array();
		foreach ( array_slice( $rules, 0, self::MAX_HEADER_RULES ) as $rule ) {
			if ( ! isset( $rule[0], $rule[1] ) || ! is_array( $rule[1] ) || preg_match( '/[\s]/', (string) $rule[0] ) ) {
				continue;
			}
			$block = (string) $rule[0] . "\n";
			foreach ( $rule[1] as $name => $value ) {
				if ( preg_match( '/^[A-Za-z0-9\-]+$/', (string) $name ) && ! preg_match( '/[\r\n]/', (string) $value ) ) {
					$block .= '  ' . $name . ': ' . $value . "\n";
				}
			}
			$blocks[] = $block;
		}
		$this->write_file( 'stillpress:generated/_headers', '_headers', implode( "\n", $blocks ) );
	}

	/**
	 * Recorded origin redirects that have a public target and still redirect (a URL that has since started to answer
	 * 404 keeps its row, but not its rule).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function origin_redirects(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT url, redirect_to, status_code FROM %i WHERE kind = 'redirect' AND redirect_to IS NOT NULL AND status_code IN (301, 302, 307, 308) ORDER BY id", Schema::table( 'urls' ) ),
			ARRAY_A
		);
	}

	/**
	 * Turns a public absolute URL into a path when it points at the static site itself.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function relative_if_public( string $url ): string {
		$base = $this->pipeline->origins->public_authority();
		if ( 0 === strpos( $url, $base . '/' ) ) {
			return substr( $url, strlen( $base ) );
		}
		return $url === $base ? '/' : $url;
	}

	/**
	 * Writes a meta-refresh page for a user-defined redirect when no page owns its path.
	 *
	 * @param string $from Origin path.
	 * @param string $to   Target path or URL (public).
	 * @return string|null Pseudo-URL of the page written, or null.
	 */
	private function write_redirect_page( string $from, string $to ): ?string {
		$output = $this->pipeline->origins->output_path_for( $from );
		if ( null === $output ) {
			return null;
		}
		try {
			$relative = $this->pipeline->cache->mapper()->relative_path( $output );
		} catch ( UnsafePathException $e ) {
			( new Logger( $this->build_id ) )->warning( sprintf( 'Skipped the redirect from %s: %s', $from, $e->getMessage() ) );
			return null;
		}
		$target = '/' === $to[0] ? $this->pipeline->origins->public_authority() . $to : $to;
		$owner  = $this->urls->find_by_path( $relative );
		$pseudo = 'stillpress:redirect' . $from;
		if ( null !== $owner && $owner['url'] !== $pseudo ) {
			return null;
		}
		$this->write_file( $pseudo, $relative, Processor::redirect_page( $target ) );
		return $pseudo;
	}

	/**
	 * Removes redirect pages for redirects that are no longer configured.
	 *
	 * @param string[] $keep Pseudo-URLs to keep.
	 * @return void
	 */
	private function remove_stale_redirect_pages( array $keep ): void {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, url, path FROM %i WHERE kind = %s AND url LIKE %s AND content_hash IS NOT NULL', Schema::table( 'urls' ), 'generated', $wpdb->esc_like( 'stillpress:redirect' ) . '%' ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['url'], $keep, true ) ) {
				$this->pipeline->cache->delete( (string) $row['path'] );
				$this->urls->unpublish( (int) $row['id'] );
			}
		}
	}

	/**
	 * Writes (or removes, when empty) a generated file and records it.
	 *
	 * @param string $pseudo   Pseudo-URL identifying the file.
	 * @param string $relative Output path.
	 * @param string $contents Contents; empty removes the file.
	 * @return void
	 */
	private function write_file( string $pseudo, string $relative, string $contents ): void {
		$id = $this->urls->ensure( $pseudo, 'generated', 'generated' );
		if ( '' === $contents ) {
			$this->pipeline->cache->delete( $relative );
			$this->urls->unpublish( $id );
			return;
		}
		$hash = $this->pipeline->cache->write( $relative, $contents );
		$this->urls->update(
			$id,
			array(
				'kind'          => 'generated',
				'status_code'   => 200,
				'path'          => $relative,
				'content_hash'  => $hash,
				'size'          => strlen( $contents ),
				'content_type'  => 'text/plain',
				'deps_state'    => 'static',
				'last_build_id' => $this->build_id,
				'rendered_at'   => current_time( 'mysql', true ),
			)
		);
	}
}
