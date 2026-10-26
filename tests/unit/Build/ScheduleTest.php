<?php
/**
 * Wall-clock schedules, including daylight-saving changes.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Stillpress\Build\Schedule;

final class ScheduleTest extends TestCase {

	const ALL = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	private function next( array $times, array $days, string $zone, string $after ): ?string {
		$tz   = new \DateTimeZone( $zone );
		$next = Schedule::next( $times, $days, $tz, new \DateTimeImmutable( $after, $tz ) );
		return null === $next ? null : $next->format( 'Y-m-d H:i T' );
	}

	public function test_later_today_then_tomorrow(): void {
		$this->assertSame( '2026-03-04 17:00 UTC', $this->next( array( '17:00', '09:00' ), self::ALL, 'UTC', '2026-03-04 10:00' ) );
		$this->assertSame( '2026-03-05 09:00 UTC', $this->next( array( '09:00', '17:00' ), self::ALL, 'UTC', '2026-03-04 18:00' ) );
	}

	public function test_exactly_at_a_time_means_the_next_one(): void {
		$this->assertSame( '2026-03-04 17:00 UTC', $this->next( array( '09:00', '17:00' ), self::ALL, 'UTC', '2026-03-04 09:00' ) );
	}

	public function test_weekdays_are_respected(): void {
		// 2026-03-06 is a Friday; Monday-only skips the weekend.
		$this->assertSame( '2026-03-09 08:00 UTC', $this->next( array( '08:00' ), array( 'mon' ), 'UTC', '2026-03-06 12:00' ) );
		// Same weekday, time already passed: a week later.
		$this->assertSame( '2026-03-16 08:00 UTC', $this->next( array( '08:00' ), array( 'mon' ), 'UTC', '2026-03-09 08:30' ) );
	}

	public function test_times_are_local_wall_clock(): void {
		$this->assertSame( '2026-03-04 09:00 CET', $this->next( array( '09:00' ), self::ALL, 'Europe/Berlin', '2026-03-04 07:00' ) );
	}

	public function test_daylight_saving_keeps_local_time(): void {
		// Europe switches to summer time on 2026-03-29: 09:00 stays 09:00 local, an hour earlier in UTC.
		$this->assertSame( '2026-03-28 09:00 CET', $this->next( array( '09:00' ), self::ALL, 'Europe/Berlin', '2026-03-27 10:00' ) );
		$this->assertSame( '2026-03-29 09:00 CEST', $this->next( array( '09:00' ), self::ALL, 'Europe/Berlin', '2026-03-28 10:00' ) );
		// And back on 2026-10-25.
		$this->assertSame( '2026-10-25 09:00 CET', $this->next( array( '09:00' ), self::ALL, 'Europe/Berlin', '2026-10-24 10:00' ) );
	}

	public function test_time_in_the_spring_gap_fires_after_it(): void {
		// 2026-03-08: New York clocks jump from 02:00 to 03:00, so 02:30 doesn't exist that night.
		$this->assertSame( '2026-03-08 03:30 EDT', $this->next( array( '02:30' ), self::ALL, 'America/New_York', '2026-03-07 12:00' ) );
	}

	public function test_repeated_autumn_hour_fires_once(): void {
		// 2026-11-01: New York repeats 01:00-02:00. 01:30 fires at its first occurrence, then the next day.
		$first = $this->next( array( '01:30' ), self::ALL, 'America/New_York', '2026-10-31 12:00' );
		$this->assertSame( '2026-11-01 01:30 EDT', $first );
		$tz    = new \DateTimeZone( 'America/New_York' );
		$after = Schedule::next( array( '01:30' ), self::ALL, $tz, new \DateTimeImmutable( '2026-11-01 01:30 EDT' ) );
		$this->assertSame( '2026-11-02 01:30 EST', $after->format( 'Y-m-d H:i T' ) );
	}

	public function test_nothing_to_schedule(): void {
		$this->assertNull( $this->next( array(), self::ALL, 'UTC', '2026-03-04 10:00' ) );
		$this->assertNull( $this->next( array( '09:00' ), array(), 'UTC', '2026-03-04 10:00' ) );
		$this->assertNull( $this->next( array( '25:00', 'noon' ), self::ALL, 'UTC', '2026-03-04 10:00' ) );
	}

	public function test_parse_times(): void {
		$this->assertSame( array( '09:00', '17:30' ), Schedule::parse_times( "17:30, 9:00\n09:00; 24:00 nonsense" ) );
		$this->assertSame( array( '00:05', '23:59' ), Schedule::parse_times( array( '23:59', '0:05', 7, null ) ) );
	}

	public function test_parse_days(): void {
		$this->assertSame( array( 'mon', 'fri', 'sun' ), Schedule::parse_days( 'Sun, fri mon, funday' ) );
		$this->assertSame( array( 'tue' ), Schedule::parse_days( array( 'tue', array( 'x' ) ) ) );
	}
}
