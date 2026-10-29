<?php
/**
 * Publishes a Pagefind index built from the build cache.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

use Stillpress\Build\Generated;
use Stillpress\Build\Pipeline;
use Stillpress\Build\Processor;
use Stillpress\Output\UnsafePathException;
use Stillpress\Storage\Schema;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Logger;
use Stillpress\Support\Paths;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * In the "pagefind" search mode, runs the configured binary over the build cache at finalizing and adds its bundle
 * under pagefind/ as generated files (D21). Pagefind writes to a private temporary folder first, so a failed run leaves
 * the published bundle as it was; a successful one replaces it, and files the new bundle no longer has are removed.
 *
 * Pagefind indexes the whole site on every run, which is fast (it's compiled) but runs in one go rather than in
 * slices; its fragment files are named by content, so unchanged pages keep their files and aren't deployed again.
 */
final class PagefindWriter {

	/** Output folder. */
	const DIR = 'pagefind';

	/** Pseudo-URL prefix of the generated rows. */
	const PSEUDO = 'stillpress:pagefind/';

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
	 * Log.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Generated-file writer.
	 *
	 * @var Generated
	 */
	private $generated;

	/**
	 * Binary, or null when none is configured.
	 *
	 * @var Pagefind|null
	 */
	private $pagefind;

	/**
	 * Constructor.
	 *
	 * @param Pipeline      $pipeline Components.
	 * @param UrlRepository $urls     URLs.
	 * @param Logger        $logger   Log.
	 * @param int           $build_id Build ID.
	 * @param Pagefind|null $pagefind Binary, or null when none is configured.
	 */
	public function __construct( Pipeline $pipeline, UrlRepository $urls, Logger $logger, int $build_id, ?Pagefind $pagefind ) {
		$this->pipeline  = $pipeline;
		$this->urls      = $urls;
		$this->logger    = $logger;
		$this->pagefind  = $pagefind;
		$this->generated = new Generated( $pipeline, $urls, $build_id );
	}

	/**
	 * Runs Pagefind and publishes its bundle, or removes a bundle left from an earlier mode.
	 *
	 * @return void
	 */
	public function write(): void {
		if ( 'pagefind' !== ( $this->pipeline->settings['search'] ?? 'off' ) ) {
			foreach ( $this->published_rows() as $row ) {
				$this->unpublish( $row );
			}
			return;
		}
		if ( null === $this->pagefind ) {
			/* translators: %s: constant name. */
			$this->logger->error( sprintf( __( 'Search is set to run Pagefind, but %s isn\'t set in wp-config.php. The search index was not updated.', 'stillpress' ), Pagefind::CONSTANT ) );
			return;
		}

		$output = Paths::data_dir() . '/tmp/pagefind-' . get_current_blog_id();
		Paths::delete_tree( $output );
		if ( ! Paths::ensure_private_dir( dirname( $output ) ) ) {
			$this->logger->error( __( 'Pagefind needs a writable temporary folder in the Stillpress data folder.', 'stillpress' ) );
			return;
		}

		$started = microtime( true );
		$result  = $this->pagefind->index( Paths::cache_dir(), $output );
		if ( 0 !== $result['code'] || ! is_file( $output . '/pagefind.js' ) ) {
			/* translators: %s: Pagefind's output. */
			$this->logger->error( sprintf( __( 'Pagefind failed, so the search index was not updated: %s', 'stillpress' ), trim( $result['output'] ) ) );
			Paths::delete_tree( $output );
			return;
		}

		$written = array();
		try {
			foreach ( $this->files( $output ) as $relative ) {
				$pseudo             = self::PSEUDO . $relative;
				$written[ $pseudo ] = true;
				$contents           = (string) file_get_contents( $output . '/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file Pagefind just wrote.
				$this->generated->write_file( $pseudo, self::DIR . '/' . $relative, $contents, Processor::mime_for_extension( pathinfo( $relative, PATHINFO_EXTENSION ) ) );
			}
		} catch ( UnsafePathException $e ) {
			$this->logger->error( sprintf( 'Pagefind wrote a file that can\'t be published: %s', $e->getMessage() ) );
		}
		foreach ( $this->published_rows() as $row ) {
			if ( ! isset( $written[ $row['url'] ] ) ) {
				$this->unpublish( $row );
			}
		}
		Paths::delete_tree( $output );

		/* translators: 1: number of files, 2: seconds. */
		$this->logger->info( sprintf( __( 'Pagefind indexed the site: %1$d files in %2$.1f s.', 'stillpress' ), count( $written ), microtime( true ) - $started ) );
	}

	/**
	 * Regular files under a folder, as relative paths, sorted. Links are skipped.
	 *
	 * @param string $root Folder.
	 * @return string[]
	 */
	private function files( string $root ): array {
		$found = array();
		$queue = array( '' );
		while ( $queue ) {
			$relative = array_shift( $queue );
			$dir      = '' === $relative ? $root : $root . '/' . $relative;
			foreach ( (array) scandir( $dir ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				$name = '' === $relative ? $entry : $relative . '/' . $entry;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$queue[] = $name;
				} elseif ( is_file( $path ) ) {
					$found[] = $name;
				}
			}
		}
		sort( $found, SORT_STRING );
		return $found;
	}

	/**
	 * Published Pagefind rows.
	 *
	 * @return array<int,array{id:string,url:string,path:string}>
	 */
	private function published_rows(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, url, path FROM %i WHERE kind = 'generated' AND url LIKE %s AND content_hash IS NOT NULL ORDER BY id", Schema::table( 'urls' ), $wpdb->esc_like( self::PSEUDO ) . '%' ),
			ARRAY_A
		);
	}

	/**
	 * Deletes a generated file and its record.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function unpublish( array $row ): void {
		$this->pipeline->cache->delete( (string) $row['path'] );
		$this->urls->unpublish( (int) $row['id'] );
	}
}
