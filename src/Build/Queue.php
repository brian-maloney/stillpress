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
	const WINDOW_HOOK    = 'stillpress_publish_window';
	const WATCHDOG_HOOK  = 'stillpress_watchdog';

	/** admin-ajax action that runs a slice right away. */
	const KICK_ACTION = 'stillpress_run';

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
		add_action( self::WINDOW_HOOK, array( self::class, 'publish_window' ), 10, 0 );
		add_action( self::WATCHDOG_HOOK, array( self::class, 'watchdog' ), 10, 0 );
		add_action( 'stillpress_changes_recorded', array( self::class, 'changes_recorded' ), 10, 0 );
		add_action( 'stillpress_build_finished', array( self::class, 'build_finished' ), 10, 1 );
		add_action( 'action_scheduler_init', array( self::class, 'ensure_recurring' ) );
		add_action( 'wp_ajax_' . self::KICK_ACTION, array( self::class, 'handle_kick' ) );
		add_action( 'wp_ajax_nopriv_' . self::KICK_ACTION, array( self::class, 'handle_kick' ) );
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
		self::after_slice( (int) $build_id, ( new Runner() )->run_slice( (int) $build_id, $budget ) );
	}

	/**
	 * Schedules what comes after a slice.
	 *
	 * @param int    $build_id Build ID.
	 * @param string $result   Runner::DONE, MORE or BUSY.
	 * @return void
	 */
	private static function after_slice( int $build_id, string $result ): void {
		if ( ! self::ready() ) {
			return;
		}
		if ( Runner::MORE === $result ) {
			// The action running right now still counts as pending, so bypass the uniqueness check.
			as_enqueue_async_action( self::SLICE_HOOK, array( $build_id ), self::GROUP, false );
			self::kick( $build_id );
		} elseif ( Runner::BUSY === $result && ! as_has_scheduled_action( self::SLICE_HOOK, array( $build_id ), self::GROUP ) ) {
			// Another process holds the build; check back later instead of spinning.
			as_schedule_single_action( time() + 30, self::SLICE_HOOK, array( $build_id ), self::GROUP, true );
		}
	}

	/**
	 * Starts a slice immediately with a non-blocking request to the site itself, so a build begins within a second
	 * of clicking Publish instead of waiting for the next cron tick. Action Scheduler remains the fallback.
	 *
	 * @param int $build_id Build ID.
	 * @return void
	 */
	public static function kick( int $build_id ): void {
		/**
		 * Filters whether builds are started with an immediate loopback request.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $kick Default true. Return false to rely on Action Scheduler and WP-Cron only.
		 */
		if ( ! apply_filters( 'stillpress_kick_builds', true ) ) {
			return;
		}
		$pipeline = Pipeline::for_site();
		$pipeline->client->dispatch(
			admin_url( 'admin-ajax.php' ),
			array(
				'action' => self::KICK_ACTION,
				'build'  => (string) $build_id,
				'token'  => self::kick_token( $build_id ),
			)
		);
	}

	/**
	 * Signature authorizing one kick for a build. It only allows running a slice of an active build.
	 *
	 * @param int $build_id Build ID.
	 * @return string
	 */
	private static function kick_token( int $build_id ): string {
		$key = (string) get_option( \Stillpress\Render\RenderToken::KEY_OPTION );
		if ( strlen( $key ) < 64 ) {
			\Stillpress\Render\RenderToken::for_site();
			$key = (string) get_option( \Stillpress\Render\RenderToken::KEY_OPTION );
		}
		return hash_hmac( 'sha256', 'kick:' . $build_id, $key );
	}

	/**
	 * admin-ajax handler for kicks.
	 *
	 * @return void
	 */
	public static function handle_kick(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authenticated by an HMAC instead of a nonce: the request comes from the server itself, without cookies.
		$build_id = isset( $_POST['build'] ) ? absint( $_POST['build'] ) : 0;
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable
		if ( $build_id <= 0 || ! hash_equals( self::kick_token( $build_id ), $token ) || ! ( new BuildRepository() )->is_active( $build_id ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running background slice.
		}

		/** This filter is documented in src/Build/Queue.php */
		$budget = (float) apply_filters( 'stillpress_slice_budget', self::default_budget() );
		self::after_slice( $build_id, ( new Runner() )->run_slice( $build_id, $budget ) );
		wp_die( '', '', array( 'response' => 204 ) );
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
		// Only "publish right after changes" reacts here; scheduled changes wait for publish_window().
		if ( 'immediate' !== Settings::get( 'publish_mode' ) || ! get_option( 'stillpress_published_once' ) ) {
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
		self::schedule_next( self::SCHEDULED_HOOK );
	}

	/**
	 * Action handler: a scheduled publishing time. Publishes pending changes, if there are any.
	 *
	 * @return void
	 */
	public static function publish_window(): void {
		if ( 'scheduled' === Settings::get( 'publish_mode' ) && get_option( 'stillpress_published_once' ) && ( new \Stillpress\Storage\ChangeRepository() )->pending_count() > 0 ) {
			( new BuildService() )->start( 'incremental', 'schedule', 0, true );
		}
		self::schedule_next( self::WINDOW_HOOK );
	}

	/**
	 * When each schedule fires next, from the settings and the site's timezone.
	 *
	 * @param \DateTimeImmutable|null $after Moment to look after; now by default.
	 * @return array<string,int|null> Hook => Unix time of its next run, or null when it is off.
	 */
	public static function next_runs( ?\DateTimeImmutable $after = null ): array {
		$settings = Settings::all();
		$zone     = wp_timezone();
		$after    = $after ?? new \DateTimeImmutable( 'now', $zone );
		$rebuild  = null;
		if ( 'off' !== $settings['full_rebuild'] ) {
			$days    = 'weekly' === $settings['full_rebuild'] ? array( $settings['full_rebuild_day'] ) : Schedule::DAYS;
			$next    = Schedule::next( array( $settings['full_rebuild_time'] ), $days, $zone, $after );
			$rebuild = null === $next ? null : $next->getTimestamp();
		}
		$window = null;
		if ( 'scheduled' === $settings['publish_mode'] ) {
			$next   = Schedule::next( $settings['publish_times'], $settings['publish_days'], $zone, $after );
			$window = null === $next ? null : $next->getTimestamp();
		}
		return array(
			self::SCHEDULED_HOOK => $rebuild,
			self::WINDOW_HOOK    => $window,
		);
	}

	/**
	 * Schedules the next run of a wall-clock hook as a single action. Each run schedules the next one, so times stay
	 * on the local clock across daylight-saving changes (a recurring action repeats every 86,400 seconds and drifts).
	 *
	 * @param string $hook SCHEDULED_HOOK or WINDOW_HOOK.
	 * @return void
	 */
	private static function schedule_next( string $hook ): void {
		if ( ! self::ready() ) {
			return;
		}
		as_unschedule_all_actions( $hook, array(), self::GROUP );
		$when = self::next_runs()[ $hook ] ?? null;
		if ( null !== $when ) {
			as_schedule_single_action( $when, $hook, array(), self::GROUP, true );
		}
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

		// Reschedule when the settings or the site's timezone change, or when a run went missing.
		$settings  = Settings::all();
		$signature = md5(
			(string) wp_json_encode(
				array(
					$settings['publish_mode'],
					$settings['publish_times'],
					$settings['publish_days'],
					$settings['full_rebuild'],
					$settings['full_rebuild_time'],
					$settings['full_rebuild_day'],
					wp_timezone_string(),
				)
			)
		);
		$changed   = get_option( 'stillpress_schedule_signature' ) !== $signature;
		foreach ( self::next_runs() as $hook => $when ) {
			$missing = null !== $when && ! as_has_scheduled_action( $hook, array(), self::GROUP );
			if ( $changed || $missing ) {
				self::schedule_next( $hook );
			}
		}
		if ( $changed ) {
			// Earlier versions used a recurring action, which drifts across daylight-saving changes.
			delete_option( 'stillpress_rebuild_interval' );
			update_option( 'stillpress_schedule_signature', $signature, false );
		}
	}

	/**
	 * Removes every scheduled action (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( self::SLICE_HOOK, self::AUTO_HOOK, self::SCHEDULED_HOOK, self::WINDOW_HOOK, self::WATCHDOG_HOOK ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}
		delete_option( 'stillpress_rebuild_interval' );
		delete_option( 'stillpress_schedule_signature' );
	}
}
