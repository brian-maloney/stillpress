<?php
/**
 * Advances a build through its phases.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Crawl\Seeds;
use Stillpress\Deploy\Registry;
use Stillpress\Deps\DependencyStore;
use Stillpress\Deps\Invalidator;
use Stillpress\Render\RenderToken;
use Stillpress\Storage\BuildRepository;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Logger;

/**
 * Runs a build in resumable slices. Each call to run_slice() does as much work as fits in its time budget and
 * returns true when more remains. All state lives in the database, so any slice can be the last one in a PHP
 * process and the next slice (possibly in another process) picks up where it stopped.
 *
 * Phases:
 *
 *     planning   – full builds: seed URLs and every published URL; incremental: URLs affected by changes;
 *                  urls: the URLs the caller listed.
 *     rendering  – fetch or copy queued URLs, write files, queue newly discovered URLs.
 *     finalizing – write generated files (_redirects, redirect pages).
 *     deploying  – make the deploy target match the cache.
 */
final class Runner {

	/** The build finished, failed or was cancelled. */
	const DONE = 'done';

	/** The slice ran out of time; more work remains. */
	const MORE = 'more';

	/** Another slice holds the lock; try again shortly. */
	const BUSY = 'busy';

	/**
	 * Builds.
	 *
	 * @var BuildRepository
	 */
	private $builds;

	/**
	 * URLs.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builds = new BuildRepository();
		$this->urls   = new UrlRepository();
	}

	/**
	 * Runs one slice of a build.
	 *
	 * @param int   $build_id Build ID.
	 * @param float $budget   Seconds to work before yielding (0 = until done).
	 * @return string self::DONE, self::MORE or self::BUSY.
	 */
	public function run_slice( int $build_id, float $budget ): string {
		$lock = new Lock();
		if ( ! $lock->acquire( (int) max( 120, $budget * 3 ) ) ) {
			return self::BUSY;
		}
		try {
			return $this->work( $build_id, $budget ) ? self::MORE : self::DONE;
		} catch ( \Throwable $e ) {
			$logger = new Logger( $build_id );
			$logger->error( 'Build failed: ' . $e->getMessage() );
			$this->builds->update( $build_id, array( 'error' => substr( \Stillpress\Security\Redactor::scrub( $e->getMessage() ), 0, 1000 ) ) );
			$this->builds->transition( $build_id, 'running', 'failed' );
			$this->finished( $build_id );
			return self::DONE;
		} finally {
			$lock->release();
		}
	}

	/**
	 * The slice loop.
	 *
	 * @param int   $build_id Build ID.
	 * @param float $budget   Seconds.
	 * @return bool
	 */
	private function work( int $build_id, float $budget ): bool {
		$start = microtime( true );
		$build = $this->builds->get( $build_id );
		if ( null === $build ) {
			return false;
		}
		if ( 'queued' === $build['status'] ) {
			if ( ! $this->builds->transition( $build_id, 'queued', 'running' ) ) {
				return false;
			}
			$this->builds->update( $build_id, array( 'phase' => 'planning' ) );
			( new Logger( $build_id ) )->info( sprintf( 'Started a %s build.', $build['kind'] ) );
			$build = $this->builds->get( $build_id );
		}
		if ( 'running' !== $build['status'] ) {
			return false;
		}

		$pipeline = Pipeline::for_site();
		$logger   = new Logger( $build_id );
		$frontier = new Frontier( $build_id );
		$frontier->recover();

		$out_of_time = static function () use ( $start, $budget ) {
			return $budget > 0 && ( microtime( true ) - $start ) >= $budget;
		};

		while ( true ) {
			$build = $this->builds->get( $build_id );
			if ( null === $build || 'running' !== $build['status'] ) {
				$this->finished( $build_id );
				return false; // Cancelled or finished elsewhere.
			}
			if ( $out_of_time() ) {
				return true;
			}

			switch ( $build['phase'] ) {
				case 'planning':
					if ( $this->plan( $build, $pipeline, $frontier, $logger ) ) {
						$this->builds->update( $build_id, array( 'phase' => 'rendering' ) );
					}
					break;

				case 'rendering':
					if ( ! $this->render_batch( $build, $pipeline, $frontier, $logger ) ) {
						$this->builds->update( $build_id, array( 'phase' => 'finalizing' ) );
					}
					break;

				case 'finalizing':
					( new Generated( $pipeline, $this->urls, $build_id ) )->write();
					( new DependencyStore() )->prune_signatures();
					$this->builds->merge_stats( $build_id, array( 'published' => $this->urls->published_counts() ) );
					$this->builds->update( $build_id, array( 'phase' => $build['deploy'] ? 'deploying' : 'done' ) );
					break;

				case 'deploying':
					$this->deploy( $build, $pipeline, $logger );
					$this->builds->update( $build_id, array( 'phase' => 'done' ) );
					break;

				default:
					$counts = $frontier->counts();
					$this->builds->merge_stats( $build_id, array( 'urls' => $counts ) );
					$this->builds->transition( $build_id, 'running', 'done' );
					$logger->info( sprintf( 'Build finished: %d URLs processed, %d failed.', $counts['done'] + $counts['failed'], $counts['failed'] ) );
					$this->finished( $build_id );
					return false;
			}
		}
	}

	/**
	 * Planning step. Returns true when planning is complete.
	 *
	 * @param array<string,mixed> $build    Build.
	 * @param Pipeline            $pipeline Components.
	 * @param Frontier            $frontier Queue.
	 * @param Logger              $logger   Log.
	 * @return bool
	 */
	private function plan( array $build, Pipeline $pipeline, Frontier $frontier, Logger $logger ): bool {
		$stats = $build['stats'];

		if ( 'incremental' === $build['kind'] ) {
			// Changes made earlier in this same process (WP-CLI, tests) are normally written at shutdown.
			$listener = \Stillpress\Plugin::instance()->changes();
			if ( null !== $listener ) {
				$listener->flush();
			}
			$plan = ( new Invalidator() )->plan( (int) $build['id'] );
			if ( $plan['full'] ) {
				$logger->info( 'A change affects every page (theme, plugins or site settings); switching to a full build.' );
				$this->builds->update( (int) $build['id'], array( 'kind' => 'full' ) );
				return false; // Plan again, now as a full build.
			}
			$ids = array();
			foreach ( $plan['urls'] as $url ) {
				$classified = $pipeline->classifier->classify( $url );
				if ( null !== $classified ) {
					$ids[] = $this->urls->ensure( $classified['url'], 'change', $classified['kind'] );
				}
			}
			$frontier->add( array_merge( $plan['url_ids'], $ids ) );
			$logger->info( sprintf( 'Planned an incremental build: %d changes affect %d URLs.', $plan['changes'], $frontier->total() ) );
			$this->builds->merge_stats( (int) $build['id'], array( 'changes' => $plan['changes'] ) );
			return true;
		}

		if ( 'urls' === $build['kind'] ) {
			$ids = array();
			foreach ( (array) ( $stats['request_urls'] ?? array() ) as $url ) {
				$classified = $pipeline->classifier->classify( (string) $url );
				if ( null === $classified ) {
					$logger->warning( sprintf( 'Skipped %s (%s).', $url, $pipeline->classifier->reason() ) );
					continue;
				}
				$ids[] = $this->urls->ensure( $classified['url'], 'request', $classified['kind'] );
			}
			$frontier->add( $ids );
			return true;
		}

		// Full build: every published URL first (so pages that are no longer linked are re-checked), then seeds.
		if ( empty( $stats['seed_cursor'] ) ) {
			$frontier->add( $this->urls->published_ids() );
			$stats['seed_cursor'] = array( 'stage' => 'core' );
			( new Invalidator() )->consume_all( (int) $build['id'] );
		}
		$batch = ( new Seeds() )->next( (array) $stats['seed_cursor'] );
		$ids   = array();
		foreach ( $batch['seeds'] as $seed ) {
			$classified = $pipeline->classifier->classify( $seed['url'] );
			if ( null === $classified ) {
				if ( '404' === $seed['source'] ) {
					$classified = array(
						'url'  => (string) $pipeline->policy->normalize( $seed['url'] ),
						'kind' => '404',
					);
				} else {
					continue;
				}
			}
			$ids[] = $this->urls->ensure( $classified['url'], $seed['source'], $classified['kind'] );
		}
		$frontier->add( $ids );
		$this->builds->merge_stats( (int) $build['id'], array( 'seed_cursor' => $batch['cursor'] ) );
		return 'done' === $batch['cursor']['stage'];
	}

	/**
	 * Processes one batch of queued URLs. Returns false when the queue is empty.
	 *
	 * @param array<string,mixed> $build    Build.
	 * @param Pipeline            $pipeline Components.
	 * @param Frontier            $frontier Queue.
	 * @param Logger              $logger   Log.
	 * @return bool
	 */
	private function render_batch( array $build, Pipeline $pipeline, Frontier $frontier, Logger $logger ): bool {
		$build_id = (int) $build['id'];
		$claimed  = $frontier->claim( max( 1, (int) $pipeline->settings['concurrency'] ) * 2 );
		if ( ! $claimed ) {
			return false;
		}
		$rows      = $this->urls->get_many( array_keys( $claimed ) );
		$processor = new Processor( $pipeline->origins, $pipeline->rewriter, $pipeline->extractor, $pipeline->classifier, $pipeline->cache, $this->urls, $logger, $build_id );
		$token     = RenderToken::for_site();
		$requests  = array();
		$results   = array();

		foreach ( $rows as $id => $row ) {
			$source = 'asset' === $row['kind'] ? $pipeline->copier->source_for( (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ) : null;
			if ( null !== $source ) {
				$results[ $id ] = $processor->process_file( $row, $source );
				continue;
			}
			$headers = array();
			if ( 'asset' !== $row['kind'] ) {
				$headers[ RenderToken::HEADER ] = $token->issue( $build_id, $id );
			}
			$requests[ $id ] = array(
				'url'     => $row['url'],
				'headers' => $headers,
			);
		}

		foreach ( $pipeline->client->fetch( $requests ) as $id => $result ) {
			$recorded = $result->headers[ strtolower( RenderToken::RECORDED_HEADER ) ] ?? '';
			$result->recorded = ( $build_id . '.' . $id ) === $recorded;
			// The recorder may have changed the row (dependency state); reload before processing.
			$row            = $this->urls->get( (int) $id );
			$results[ $id ] = null === $row ? array(
				'outcome'    => 'failed',
				'discovered' => array(),
			) : $processor->process( $row, $result );
		}

		$max_urls = (int) $pipeline->settings['max_urls'];
		foreach ( $results as $id => $outcome ) {
			$frontier->mark( (int) $id, 'failed' === $outcome['outcome'] ? 'failed' : 'done' );
			if ( ! $outcome['discovered'] ) {
				continue;
			}
			$new_ids = array();
			foreach ( $outcome['discovered'] as $found ) {
				$existing = $this->urls->find( $found['url'] );
				// Incremental builds only follow links to URLs that aren't published right now: new URLs, and URLs
				// that were gone (404) or redirecting before, which the change may have brought back.
				if ( 'full' !== $build['kind'] && null !== $existing && null !== $existing['content_hash'] ) {
					continue;
				}
				$new_ids[] = null !== $existing ? $existing['id'] : $this->urls->ensure( $found['url'], 'crawl', $found['kind'] );
			}
			if ( $new_ids ) {
				if ( $frontier->total() + count( $new_ids ) > $max_urls ) {
					$logger->warning( sprintf( 'Reached the limit of %d URLs; some links were not followed.', $max_urls ) );
					$new_ids = array_slice( $new_ids, 0, max( 0, $max_urls - $frontier->total() ) );
				}
				$frontier->add( $new_ids, $claimed[ $id ] + 1 );
			}
		}
		$this->builds->merge_stats( $build_id, array( 'urls' => $frontier->counts() ) );

		// The HTTP library leaves reference cycles (transport <-> hooks) holding whole response bodies. PHP only
		// collects cycles when its root buffer fills, which a long build can outrun, so collect after each batch.
		gc_collect_cycles();
		return true;
	}

	/**
	 * Runs the configured deployer.
	 *
	 * @param array<string,mixed> $build    Build.
	 * @param Pipeline            $pipeline Components.
	 * @param Logger              $logger   Log.
	 * @return void
	 * @throws \RuntimeException When the target is missing or misconfigured.
	 */
	private function deploy( array $build, Pipeline $pipeline, Logger $logger ): void {
		$target   = (string) ( $build['stats']['target'] ?? $pipeline->settings['target'] );
		$deployer = Registry::instance()->get( $target );
		if ( null === $deployer ) {
			/* translators: %s: deployer ID. */
			throw new \RuntimeException( sprintf( __( 'Unknown deploy target "%s".', 'stillpress' ), $target ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$manifest = iterator_to_array( $this->urls->published_files() );

		/**
		 * Filters the manifest (relative path => SHA-256) before it is deployed.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,string> $manifest Files that make up the site.
		 * @param string               $target   Deployer ID.
		 */
		$manifest = (array) apply_filters( 'stillpress_manifest', $manifest, $target );
		$result   = $deployer->deploy( $manifest, $pipeline->cache->mapper()->root(), $logger );
		$this->builds->merge_stats( (int) $build['id'], array( 'deploy' => $result->to_array() + array( 'target' => $target ) ) );
		$logger->info( sprintf( 'Deployed to %s: %d added, %d changed, %d deleted, %d unchanged.', $deployer->label(), $result->added, $result->modified, $result->deleted, $result->unchanged ) );
	}

	/**
	 * Clean-up after a build stops for any reason.
	 *
	 * @param int $build_id Build ID.
	 * @return void
	 */
	private function finished( int $build_id ): void {
		( new Frontier( $build_id ) )->clear();
		$build = $this->builds->get( $build_id );

		/**
		 * Fires when a build stops: done, failed or cancelled.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $build Build row, including status and stats.
		 */
		do_action( 'stillpress_build_finished', $build );
	}
}
