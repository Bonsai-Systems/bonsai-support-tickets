<?php
/**
 * Business-hours clock for SLAs and reminders.
 *
 * Pure PHP (no WordPress calls), so it's unit tested without WordPress.
 *
 * A clock is either:
 * - business hours: open on the given ISO weekdays (1 = Monday … 7 = Sunday)
 *   between start and end (local wall-clock time), closed on holidays; or
 * - always open (24/7), where it's plain elapsed time.
 *
 * Times are worked out in the clock's timezone, so "09:00" means 09:00 local
 * on both sides of a clock change. Real elapsed seconds are used inside each
 * day's window, which keeps DST nights (outside working hours) harmless.
 *
 * Example: Mon–Fri 09:00–17:30. A 4-hour target on a ticket raised at 16:00
 * Friday is due at 11:30 Monday (or Tuesday if Monday is a bank holiday).
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Working-time arithmetic.
 */
class BST_Business_Hours {

	/**
	 * Give up looking for an open day after this many days (a config with
	 * every day a holiday would otherwise loop forever).
	 */
	const MAX_DAYS = 3660;

	/**
	 * Open weekdays, ISO numbers.
	 *
	 * @var int[]
	 */
	private $days;

	/**
	 * Opening time, minutes after midnight.
	 *
	 * @var int
	 */
	private $start;

	/**
	 * Closing time, minutes after midnight.
	 *
	 * @var int
	 */
	private $end;

	/**
	 * Timezone.
	 *
	 * @var DateTimeZone
	 */
	private $tz;

	/**
	 * Closed dates, Y-m-d => true.
	 *
	 * @var array<string,bool>
	 */
	private $holidays;

	/**
	 * 24/7.
	 *
	 * @var bool
	 */
	private $always_open;

	/**
	 * Business-hours clock.
	 *
	 * @param int[]               $days        Open ISO weekdays (1–7).
	 * @param string              $start       Opening time, H:i.
	 * @param string              $end         Closing time, H:i (after start).
	 * @param DateTimeZone|string $tz          Timezone.
	 * @param string[]            $holidays    Closed dates, Y-m-d.
	 * @param bool                $always_open 24/7 (ignores the rest but tz).
	 */
	public function __construct( array $days, $start, $end, $tz, array $holidays = array(), $always_open = false ) {
		$this->tz       = $tz instanceof DateTimeZone ? $tz : new DateTimeZone( (string) $tz );
		$this->days     = array_values( array_unique( array_filter( array_map( 'intval', $days ), array( __CLASS__, 'is_weekday' ) ) ) );
		$this->start    = self::to_minutes( $start, 9 * 60 );
		$this->end      = self::to_minutes( $end, 17 * 60 + 30 );
		$this->holidays = array_fill_keys( array_filter( array_map( 'strval', $holidays ) ), true );

		// No open days or an empty/backwards window can't be business hours.
		$this->always_open = $always_open || ! $this->days || $this->end <= $this->start;
	}

	/**
	 * A 24/7 clock.
	 *
	 * @param DateTimeZone|string $tz Timezone.
	 * @return self
	 */
	public static function always( $tz ) {
		return new self( array(), '00:00', '00:00', $tz, array(), true );
	}

	/**
	 * Whether this is a 24/7 clock.
	 *
	 * @return bool
	 */
	public function is_always_open() {
		return $this->always_open;
	}

	/**
	 * Working minutes in a full open day (24 × 60 for 24/7).
	 *
	 * @return int
	 */
	public function minutes_per_day() {
		return $this->always_open ? 1440 : $this->end - $this->start;
	}

	/**
	 * Whether the clock is running at a moment.
	 *
	 * @param DateTimeInterface $at Moment.
	 * @return bool
	 */
	public function is_open( DateTimeInterface $at ) {
		if ( $this->always_open ) {
			return true;
		}
		$local  = $this->local( $at );
		$window = $this->window( $local );
		return $window && $local >= $window[0] && $local < $window[1];
	}

	/**
	 * The moment after $from when $minutes of working time have passed.
	 *
	 * @param DateTimeInterface $from    Start.
	 * @param int               $minutes Working minutes (0 returns the next open moment).
	 * @return DateTimeImmutable In the clock's timezone.
	 */
	public function add( DateTimeInterface $from, $minutes ) {
		$cursor    = $this->local( $from );
		$remaining = max( 0, (int) $minutes ) * 60; // Seconds.

		if ( $this->always_open ) {
			return $cursor->modify( '+' . $remaining . ' seconds' );
		}

		for ( $i = 0; $i < self::MAX_DAYS; $i++ ) {
			$window = $this->window( $cursor );

			if ( $window && $cursor < $window[1] ) {
				if ( $cursor < $window[0] ) {
					$cursor = $window[0];
				}
				$available = $window[1]->getTimestamp() - $cursor->getTimestamp();
				if ( $remaining <= $available ) {
					return $cursor->modify( '+' . $remaining . ' seconds' );
				}
				$remaining -= $available;
			}

			$cursor = $this->next_day( $cursor );
		}

		// Unreachable with a sane config; fall back to elapsed time.
		return $this->local( $from )->modify( '+' . ( max( 0, (int) $minutes ) * 60 ) . ' seconds' );
	}

	/**
	 * Working minutes between two moments (0 if $to isn't after $from).
	 *
	 * @param DateTimeInterface $from Start.
	 * @param DateTimeInterface $to   End.
	 * @return int Whole minutes, rounded down.
	 */
	public function between( DateTimeInterface $from, DateTimeInterface $to ) {
		if ( $to <= $from ) {
			return 0;
		}
		if ( $this->always_open ) {
			return intdiv( $to->getTimestamp() - $from->getTimestamp(), 60 );
		}

		$cursor  = $this->local( $from );
		$end     = $this->local( $to );
		$seconds = 0;

		for ( $i = 0; $i < self::MAX_DAYS && $cursor < $end; $i++ ) {
			$window = $this->window( $cursor );
			if ( $window ) {
				$open  = max( $cursor, $window[0] );
				$close = min( $end, $window[1] );
				if ( $close > $open ) {
					$seconds += $close->getTimestamp() - $open->getTimestamp();
				}
			}
			$cursor = $this->next_day( $cursor );
		}

		return intdiv( $seconds, 60 );
	}

	/**
	 * Today's open window, or null when closed all day.
	 *
	 * @param DateTimeImmutable $day Any moment on the day (local).
	 * @return DateTimeImmutable[]|null array( open, close ).
	 */
	private function window( DateTimeImmutable $day ) {
		if ( ! in_array( (int) $day->format( 'N' ), $this->days, true ) || isset( $this->holidays[ $day->format( 'Y-m-d' ) ] ) ) {
			return null;
		}
		return array(
			$day->setTime( intdiv( $this->start, 60 ), $this->start % 60 ),
			$day->setTime( intdiv( $this->end, 60 ), $this->end % 60 ),
		);
	}

	/**
	 * Midnight at the start of the next day (local).
	 *
	 * @param DateTimeImmutable $day Day.
	 * @return DateTimeImmutable
	 */
	private function next_day( DateTimeImmutable $day ) {
		return $day->setTime( 0, 0 )->modify( '+1 day' );
	}

	/**
	 * A moment in the clock's timezone.
	 *
	 * @param DateTimeInterface $at Moment.
	 * @return DateTimeImmutable
	 */
	private function local( DateTimeInterface $at ) {
		return ( new DateTimeImmutable( '@' . $at->getTimestamp() ) )->setTimezone( $this->tz );
	}

	/**
	 * "09:30" → 570.
	 *
	 * @param string $time     H:i.
	 * @param int    $fallback Minutes if invalid.
	 * @return int
	 */
	private static function to_minutes( $time, $fallback ) {
		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( (string) $time ), $m ) ) {
			return (int) $m[1] * 60 + (int) $m[2];
		}
		if ( '24:00' === trim( (string) $time ) ) {
			return 1440;
		}
		return $fallback;
	}

	/**
	 * 1–7.
	 *
	 * @param int $day Day number.
	 * @return bool
	 */
	private static function is_weekday( $day ) {
		return $day >= 1 && $day <= 7;
	}
}
