<?php
/**
 * Durations for time tracking: parse what agents type, format minutes.
 *
 * Pure PHP with no WordPress calls, so it's unit tested without WordPress.
 *
 * Accepted input (case and spacing don't matter):
 *   30m, 30 min, 30 mins, 45 minutes      → minutes
 *   2h, 2 hrs, 2 hours, 1.5h              → hours
 *   1h 30m, 1h30m, 1h30, 1 hr 30 mins     → hours and minutes
 *   1:30                                  → hours:minutes
 *   1.5, 2, 0.25, 1,5                     → a bare number is hours
 *
 * Anything else, zero, or more than MAX_MINUTES is rejected (null), so a
 * typo like "30" meaning 30 minutes is caught as 30 hours rather than
 * silently billed.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Duration parsing/formatting.
 */
class BST_Duration {

	/**
	 * Longest single entry: one day.
	 */
	const MAX_MINUTES = 1440;

	/**
	 * Parse a duration into whole minutes.
	 *
	 * @param string $input What the agent typed.
	 * @return int|null Minutes (1–MAX_MINUTES), or null if it can't be read.
	 */
	public static function parse( $input ) {
		$text = strtolower( trim( (string) $input ) );
		$text = str_replace( ',', '.', $text ); // 1,5 → 1.5.
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( '' === $text ) {
			return null;
		}

		$minutes = null;

		if ( preg_match( '/^(\d+):([0-5]\d)$/', $text, $m ) ) {
			// 1:30.
			$minutes = (int) $m[1] * 60 + (int) $m[2];
		} elseif ( preg_match( '/^(\d+(?:\.\d+)?)$/', $text, $m ) ) {
			// Bare number = hours.
			$minutes = (float) $m[1] * 60;
		} elseif ( preg_match( '/^(?:(\d+(?:\.\d+)?) ?(?:h|hr|hrs|hour|hours))? ?(?:(\d+) ?(?:m|min|mins|minute|minutes)?)?$/', $text, $m ) ) {
			// 1h, 1.5h, 1h 30m, 1h30, 30m. A trailing bare number needs hours before it.
			$hours = isset( $m[1] ) && '' !== $m[1] ? (float) $m[1] : null;
			$mins  = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : null;

			$has_min_unit = (bool) preg_match( '/\d ?(?:m|min|mins|minute|minutes)$/', $text );
			if ( null === $hours && ( null === $mins || ! $has_min_unit ) ) {
				return null; // Bare numbers are handled above; nothing matched.
			}
			$minutes = ( $hours ?? 0 ) * 60 + ( $mins ?? 0 );
		}

		if ( null === $minutes ) {
			return null;
		}

		$minutes = (int) round( $minutes );
		return $minutes >= 1 && $minutes <= self::MAX_MINUTES ? $minutes : null;
	}

	/**
	 * Minutes as "1h 30m", "45m", "2h" or "0m".
	 *
	 * @param int $minutes Minutes (negative shown with a minus sign).
	 * @return string
	 */
	public static function format( $minutes ) {
		$minutes = (int) $minutes;
		$sign    = $minutes < 0 ? '-' : '';
		$minutes = abs( $minutes );
		$hours   = intdiv( $minutes, 60 );
		$rest    = $minutes % 60;

		if ( ! $hours ) {
			return $sign . $rest . 'm';
		}
		return $sign . $hours . 'h' . ( $rest ? ' ' . $rest . 'm' : '' );
	}

	/**
	 * Minutes as decimal hours for spreadsheets and invoices: 90 → "1.50".
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public static function hours( $minutes ) {
		return number_format( (int) $minutes / 60, 2, '.', '' );
	}
}
