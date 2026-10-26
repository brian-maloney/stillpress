<?php
/**
 * Wall-clock schedules.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

/**
 * Finds the next time a "these weekdays at these times" schedule fires, in a given timezone.
 *
 * Times are local wall-clock times, so 09:00 stays 09:00 across daylight-saving changes (a fixed 24-hour interval
 * would drift by an hour). On the night clocks go forward, a time that doesn't exist (02:30 in most of Europe and the
 * US) fires at the same offset after the gap, as PHP resolves it (03:30); on the night clocks go back, a repeated time
 * fires once, at its first occurrence.
 *
 * Pure logic: no WordPress calls.
 */
final class Schedule {

	/** Weekday codes in ISO order (Monday = 1). */
	const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Next firing strictly after a moment.
	 *
	 * @param string[]           $times HH:MM times, any order.
	 * @param string[]           $days  Weekday codes from DAYS.
	 * @param \DateTimeZone      $zone  Timezone the times are in.
	 * @param \DateTimeImmutable $after The moment to look after.
	 * @return \DateTimeImmutable|null Null when there are no valid times or days.
	 */
	public static function next( array $times, array $days, \DateTimeZone $zone, \DateTimeImmutable $after ): ?\DateTimeImmutable {
		$clock = array();
		foreach ( $times as $time ) {
			if ( self::valid_time( (string) $time ) ) {
				$clock[] = (string) $time;
			}
		}
		sort( $clock );
		$weekdays = array_values( array_intersect( self::DAYS, $days ) );
		if ( ! $clock || ! $weekdays ) {
			return null;
		}
		$local = $after->setTimezone( $zone );
		// Eight days covers "later this weekday next week" when today's times have passed.
		for ( $offset = 0; $offset <= 7; $offset++ ) {
			$day = $local->setTime( 0, 0 )->modify( '+' . $offset . ' days' );
			if ( ! in_array( self::DAYS[ (int) $day->format( 'N' ) - 1 ], $weekdays, true ) ) {
				continue;
			}
			foreach ( $clock as $time ) {
				list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
				$candidate             = $day->setTime( $hour, $minute );
				if ( $candidate->getTimestamp() > $after->getTimestamp() ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * True for a 24-hour "HH:MM" time.
	 *
	 * @param string $time Time.
	 * @return bool
	 */
	public static function valid_time( string $time ): bool {
		return 1 === preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time );
	}

	/**
	 * Parses times typed by a person ("9:00, 17:30" or one per line) into sorted, unique "HH:MM" strings.
	 *
	 * @param mixed $value Text or a list.
	 * @return string[] Valid times only.
	 */
	public static function parse_times( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value );
		}
		$out = array();
		foreach ( (array) $value as $time ) {
			if ( ! is_scalar( $time ) || ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $time ), $m ) ) {
				continue;
			}
			$time = sprintf( '%02d:%s', (int) $m[1], $m[2] );
			if ( self::valid_time( $time ) ) {
				$out[ $time ] = $time;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Keeps known weekday codes, in week order.
	 *
	 * @param mixed $value Codes as a list, or text separated by commas or spaces.
	 * @return string[]
	 */
	public static function parse_days( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', strtolower( $value ) );
		}
		$given = array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) );
		return array_values( array_intersect( self::DAYS, $given ) );
	}
}
