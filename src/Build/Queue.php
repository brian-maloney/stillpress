<?php
/**
 * Background execution of builds.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Storage\BuildRepository;
use Stillpress\Support\Settings;

/**
 * Runs builds on Action Scheduler: each action runs one time-boxed slice and enqueues the next one, so large
 * sites never hit a PHP timeout. Also owns the recurring full rebuild and the debounced auto-publish.
 */
final class Queue {

	const GROUP = 'stillpress';

	const SLICE_HOOK     = 'stillpress_build_slice';
	const AUTO_HOOK      = 'stillpress_auto_publish';
	const SCHEDULED_HOOK = 'stillpress_scheduled_rebuild';
	const WATCHDOG_HOOK  = 'stillpress_watchdog';

	/** Seconds after the last change before an automatic incremental build starts. */
	const DEBOUNCE = 60;

	/**
	 * Registers action handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::SLICE_HOOK, array( self::class, 'run_slice' ), 10, 1 );
		add_action( self::AUTO_HOOK, array( self::class, 'auto_publish' ), 10, 0 );
		add_action( self::SCHEDULED_HOOK, array( self::class, 'scheduled_rebuild' ), 10, 0 );
		add_action( self::WATCHDOG_HOOK, array( self::class, 'watchdog' ), 10, 0 );
		add_action( 'stillpress_changes_recorded', array( self::class, 'changes_recorded' ), 10, 0 );
		add_action( 'stillpress_build_finished', array( self::class, 'build_finished' ), 10, 1 );
		add_action( 'action_scheduler_init', array( self::class, 'ensure_recurring' ) );
	}

	/**
	 * True when Action Scheduler is ready to accept actions.
	 *
	 * @return bool
	 */
	private static function ready(): bool {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized();
	}

	/**
	 * Enqueues the next slice of a build.
	 *
	 * @param int $build_id Build ID.
	 * @return void
	 */
	public static function enqueue_slice( int $build_id ): void {
		if ( self::ready() ) {
			as_enqueue_async_action( self::SLICE_HOOK, array( $build_id ), self::GROUP, true );
		}
	}

	/**
	 * Action handler: one slice, then enqueue the next if needed.
	 *
	 * @param int $build_id Build ID.
	 * @return void
	 */
	public static function run_slice( $build_id ): void {
		/**
		 * Filters the time budget, in seconds, of one background build slice.
		 *
		 * @since 0.1.0
		 *
		 * @param float $budget Seconds. Defaults to 20, or a third of max_execution_time when that is lower.
		 */
		$budget = (float) apply_filters( 'stillpress_slice_budget', self::default_budget() );
		if ( ( new Runner() )->run_slice( (int) $build_id, $budget ) ) {
			// The action that is running right now still counts as pending, so bypass the uniqueness check.
			as_enqueue_async_action( self::SLICE_HOOK, array( (int) $build_id ), self::GROUP, false );
		}
	}

	/**
	 * Default slice budget.
	 *
	 * @return float
	 */
	private static function default_budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? (float) min( 20, max( 5, intdiv( $max, 3 ) ) ) : 20.0;
	}

	/**
	 * After changes are recorded: schedule an incremental build once things go quiet.
	 *
	 * @return void
	 */
	public static function changes_recorded(): void {
		if ( ! Settings::get( 'auto_publish' ) || ! get_option( 'stillpress_published_once' ) ) {
			return;
		}
		if ( ! self::ready() ) {
			return;
		}
		// Debounce: push the pending auto-publish back on every change.
		as_unschedule_all_actions( self::AUTO_HOOK, array(), self::GROUP );
		as_schedule_single_action( time() + self::DEBOUNCE, self::AUTO_HOOK, array(), self::GROUP );
	}

	/**
	 * Action handler: start an incremental build for pending changes.
	 *
	 * @return void
	 */
	public static function auto_publish(): void {
		( new BuildService() )->start( 'incremental', 'content', 0, true );
	}

	/**
	 * Action handler: recurring full rebuild.
	 *
	 * @return void
	 */
	public static function scheduled_rebuild(): void {
		( new BuildService() )->start( 'full', 'schedule', 0, true );
	}

	/**
	 * Restarts builds whose slice chain broke (for example a fatal error in a slice) and keeps the schedule.
	 *
	 * @return void
	 */
	public static function watchdog(): void {
		$active = ( new BuildRepository() )->active();
		if ( null === $active ) {
			return;
		}
		$heartbeat = strtotime( (string) ( $active['heartbeat_at'] ?? $active['created_at'] ) . ' UTC' );
		if ( $heartbeat < time() - 5 * MINUTE_IN_SECONDS && ! as_has_scheduled_action( self::SLICE_HOOK, array( $active['id'] ), self::GROUP ) ) {
			self::enqueue_slice( (int) $active['id'] );
		}
	}

	/**
	 * After a build: remember the first publish, and pick up changes that arrived during the build.
	 *
	 * @param array<string,mixed>|null $build Build row.
	 * @return void
	 */
	public static function build_finished( $build ): void {
		if ( is_array( $build ) && 'done' === $build['status'] && ! empty( $build['deploy'] ) ) {
			update_option( 'stillpress_published_once', 1, false );
		}
		if ( ( new \Stillpress\Storage\ChangeRepository() )->pending_count() > 0 ) {
			self::changes_recorded();
		}
	}

	/**
	 * Keeps the recurring full rebuild and the watchdog in line with the settings.
	 *
	 * @return void
	 */
	public static function ensure_recurring(): void {
		if ( ! self::ready() ) {
			return;
		}
		if ( false === as_has_scheduled_action( self::WATCHDOG_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::WATCHDOG_HOOK, array(), self::GROUP, true );
		}

		$schedule = (string) Settings::get( 'full_rebuild' );
		$interval = 'weekly' === $schedule ? WEEK_IN_SECONDS : ( 'daily' === $schedule ? DAY_IN_SECONDS : 0 );
		$stored   = (int) get_option( 'stillpress_rebuild_interval', -1 );
		if ( $stored === $interval && ( 0 === $interval || as_has_scheduled_action( self::SCHEDULED_HOOK, array(), self::GROUP ) ) ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array(), self::GROUP );
		if ( $interval > 0 ) {
			// First run at 03:00 site time tomorrow, when traffic is usually low.
			$first = strtotime( 'tomorrow 03:00', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local-time arithmetic.
			as_schedule_recurring_action( $first, $interval, self::SCHEDULED_HOOK, array(), self::GROUP, true );
		}
		update_option( 'stillpress_rebuild_interval', $interval, false );
	}

	/**
	 * Removes every scheduled action (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( self::SLICE_HOOK, self::AUTO_HOOK, self::SCHEDULED_HOOK, self::WATCHDOG_HOOK ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}
		delete_option( 'stillpress_rebuild_interval' );
	}
}
