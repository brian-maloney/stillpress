<?php
/**
 * Starting, running and stopping builds.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Storage\BuildRepository;

/**
 * The one entry point for starting builds, shared by the admin screen, REST API, WP-CLI and the scheduler.
 *
 * Only one build runs at a time. Asking for a build while one is active returns the active build, except that a
 * request for a full build while an incremental build is queued (not yet running) upgrades it.
 */
final class BuildService {

	const KINDS = array( 'full', 'incremental', 'urls' );

	/**
	 * Builds.
	 *
	 * @var BuildRepository
	 */
	private $builds;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builds = new BuildRepository();
	}

	/**
	 * Starts (or joins) a build and hands it to the background queue.
	 *
	 * @param string              $kind    full, incremental or urls.
	 * @param string              $trigger manual, cli, schedule or content.
	 * @param int                 $user_id User starting it.
	 * @param bool                $deploy  Deploy when done.
	 * @param array<string,mixed> $options urls (for kind "urls"), target (deployer ID override).
	 * @return int Build ID.
	 * @throws \InvalidArgumentException For an unknown kind.
	 */
	public function start( string $kind, string $trigger, int $user_id, bool $deploy, array $options = array() ): int {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown build kind.' );
		}
		$active = $this->builds->active();
		if ( null !== $active ) {
			if ( 'full' === $kind && 'queued' === $active['status'] && 'full' !== $active['kind'] ) {
				$this->builds->update( $active['id'], array( 'kind' => 'full' ) );
			}
			return $active['id'];
		}

		$id    = $this->builds->create( $kind, $trigger, $user_id, $deploy );
		$stats = array();
		if ( ! empty( $options['urls'] ) ) {
			$stats['request_urls'] = array_values( array_map( 'strval', (array) $options['urls'] ) );
		}
		if ( ! empty( $options['target'] ) ) {
			$stats['target'] = sanitize_key( (string) $options['target'] );
		}
		if ( $stats ) {
			$this->builds->update( $id, array( 'stats' => $stats ) );
		}

		/**
		 * Fires when a build has been created, before any work runs.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $id      Build ID.
		 * @param string $kind    full, incremental or urls.
		 * @param string $trigger manual, cli, schedule or content.
		 */
		do_action( 'stillpress_build_started', $id, $kind, $trigger );

		if ( empty( $options['sync'] ) ) {
			Queue::enqueue_slice( $id );
			Queue::kick( $id );
		}
		return $id;
	}

	/**
	 * Runs a build to completion in this process (WP-CLI, tests).
	 *
	 * @param int           $build_id Build ID.
	 * @param callable|null $progress Called after each slice with the build row.
	 * @return array<string,mixed>|null Final build row.
	 */
	public function run_sync( int $build_id, ?callable $progress = null ): ?array {
		$runner = new Runner();
		while ( true ) {
			$result = $runner->run_slice( $build_id, 5.0 );
			if ( Runner::DONE === $result ) {
				break;
			}
			if ( Runner::BUSY === $result ) {
				usleep( 500000 ); // A background slice is working on it; wait for it.
			}
			if ( null !== $progress ) {
				$progress( $this->builds->get( $build_id ) );
			}
		}
		return $this->builds->get( $build_id );
	}

	/**
	 * Cancels the active build.
	 *
	 * @return int|null The cancelled build ID.
	 */
	public function cancel(): ?int {
		$active = $this->builds->active();
		if ( null === $active ) {
			return null;
		}
		$from = $active['status'];
		if ( $this->builds->transition( $active['id'], $from, 'cancelled' ) ) {
			( new Frontier( $active['id'] ) )->clear();

			/** This action is documented in src/Build/Runner.php */
			do_action( 'stillpress_build_finished', $this->builds->get( $active['id'] ) );
			return $active['id'];
		}
		return null;
	}
}
