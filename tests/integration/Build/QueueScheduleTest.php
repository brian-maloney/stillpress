<?php
/**
 * Publishing at set times and the full-rebuild schedule, on Action Scheduler.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Build;

use Stillpress\Build\Queue;
use Stillpress\Storage\BuildRepository;
use Stillpress\Support\Settings;

final class QueueScheduleTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'as_schedule_single_action' ) || ! \ActionScheduler::is_initialized() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		add_filter( 'stillpress_kick_builds', '__return_false' );
		update_option( 'timezone_string', 'Europe/Berlin' );
		Queue::unschedule_all();
	}

	public function tear_down(): void {
		Queue::unschedule_all();
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	private function next_scheduled( string $hook ): ?int {
		$next = as_next_scheduled_action( $hook, array(), Queue::GROUP );
		return is_int( $next ) ? $next : null;
	}

	public function test_next_runs_use_the_site_timezone(): void {
		Settings::update(
			array(
				'publish_mode'      => 'scheduled',
				'publish_times'     => array( '09:00', '17:00' ),
				'publish_days'      => array( 'mon' ),
				'full_rebuild'      => 'weekly',
				'full_rebuild_day'  => 'wed',
				'full_rebuild_time' => '02:30',
			)
		);
		// Monday 2026-03-30 10:00 in Berlin (summer time, UTC+2).
		$after = new \DateTimeImmutable( '2026-03-30 10:00', new \DateTimeZone( 'Europe/Berlin' ) );
		$next  = Queue::next_runs( $after );
		$this->assertSame( '2026-03-30 17:00 +02:00', wp_date( 'Y-m-d H:i P', $next[ Queue::WINDOW_HOOK ] ) );
		$this->assertSame( '2026-04-01 02:30 +02:00', wp_date( 'Y-m-d H:i P', $next[ Queue::SCHEDULED_HOOK ] ) );
	}

	public function test_only_schedules_in_use_are_armed(): void {
		Settings::update(
			array(
				'publish_mode' => 'immediate',
				'full_rebuild' => 'off',
			)
		);
		Queue::ensure_recurring();
		$this->assertNull( $this->next_scheduled( Queue::WINDOW_HOOK ) );
		$this->assertNull( $this->next_scheduled( Queue::SCHEDULED_HOOK ) );

		Settings::update(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => array( '09:00' ),
				'full_rebuild'  => 'daily',
			)
		);
		Queue::ensure_recurring();
		$this->assertSame( Queue::next_runs()[ Queue::WINDOW_HOOK ], $this->next_scheduled( Queue::WINDOW_HOOK ) );
		$this->assertSame( Queue::next_runs()[ Queue::SCHEDULED_HOOK ], $this->next_scheduled( Queue::SCHEDULED_HOOK ) );
	}

	public function test_changed_times_reschedule(): void {
		Settings::update(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => array( '09:00' ),
			)
		);
		Queue::ensure_recurring();
		$before = $this->next_scheduled( Queue::WINDOW_HOOK );

		Settings::update( array( 'publish_times' => array( '09:15' ) ) );
		Queue::ensure_recurring();
		$this->assertSame( $before + 15 * MINUTE_IN_SECONDS, $this->next_scheduled( Queue::WINDOW_HOOK ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Queue::WINDOW_HOOK, 'status' => 'pending' ), 'ids' ), 'The old run was replaced, not kept.' );
	}

	public function test_old_recurring_rebuild_is_replaced_by_a_single_run(): void {
		// Earlier versions scheduled a recurring action every 24 hours, which drifts across daylight-saving changes.
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, Queue::SCHEDULED_HOOK, array(), Queue::GROUP, true );
		update_option( 'stillpress_rebuild_interval', DAY_IN_SECONDS );
		delete_option( 'stillpress_schedule_signature' );
		Settings::update( array( 'full_rebuild' => 'daily' ) );

		Queue::ensure_recurring();
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Queue::SCHEDULED_HOOK,
				'status' => 'pending',
			)
		);
		$this->assertCount( 1, $actions );
		$this->assertFalse( reset( $actions )->get_schedule()->is_recurring() );
		$this->assertFalse( get_option( 'stillpress_rebuild_interval' ) );
	}

	public function test_scheduled_mode_holds_changes_until_a_publishing_time(): void {
		update_option( 'stillpress_published_once', 1 );
		Settings::update(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => array( '09:00' ),
			)
		);
		Queue::changes_recorded();
		$this->assertNull( $this->next_scheduled( Queue::AUTO_HOOK ), 'No publish a minute after the change.' );

		Settings::update( array( 'publish_mode' => 'immediate' ) );
		Queue::changes_recorded();
		$this->assertNotNull( $this->next_scheduled( Queue::AUTO_HOOK ) );
	}

	public function test_publishing_time_builds_only_when_something_changed(): void {
		update_option( 'stillpress_published_once', 1 );
		Settings::update(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => array( '09:00' ),
			)
		);
		$builds  = new BuildRepository();
		$changes = new \Stillpress\Storage\ChangeRepository();
		// Changing the timezone and settings above are real site changes: write them out, then clear them.
		$listener = \Stillpress\Plugin::instance()->changes();
		if ( null !== $listener ) {
			$listener->flush();
		}
		$changes->flush();

		Queue::publish_window();
		$this->assertNull( $builds->active(), 'Nothing pending, no build.' );
		$this->assertNotNull( $this->next_scheduled( Queue::WINDOW_HOOK ), 'The next time is armed anyway.' );

		$changes->record( 'post', '1' );
		Queue::publish_window();
		$active = $builds->active();
		$this->assertNotNull( $active );
		$this->assertSame( 'incremental', $active['kind'] );
		$this->assertSame( 'schedule', $active['trigger_source'] );
	}
}
