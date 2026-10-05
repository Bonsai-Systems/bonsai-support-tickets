<?php
/**
 * Business-hours clock: the arithmetic SLAs and reminders depend on.
 *
 * @package Support_Desk
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-bst-business-hours.php';

/**
 * BST_Business_Hours.
 */
class BusinessHoursTest extends TestCase {

	/**
	 * UK office: Mon–Fri 09:00–17:30, Europe/London, with the 2026 Easter and
	 * Christmas bank holidays (England and Wales).
	 *
	 * @return BST_Business_Hours
	 */
	private function office() {
		return new BST_Business_Hours(
			array( 1, 2, 3, 4, 5 ),
			'09:00',
			'17:30',
			'Europe/London',
			array( '2026-04-03', '2026-04-06', '2026-12-25', '2026-12-28' )
		);
	}

	/**
	 * London time.
	 *
	 * @param string $when Y-m-d H:i.
	 * @return DateTimeImmutable
	 */
	private function london( $when ) {
		return new DateTimeImmutable( $when, new DateTimeZone( 'Europe/London' ) );
	}

	/**
	 * Assert a moment, in London time.
	 *
	 * @param string            $expected Y-m-d H:i.
	 * @param DateTimeInterface $actual   Moment.
	 */
	private function assertLondon( $expected, DateTimeInterface $actual ) {
		$this->assertSame( $expected, $actual->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'Y-m-d H:i' ) );
	}

	public function test_within_the_same_day() {
		// Wednesday 7 Oct 2026, 10:00 + 2h.
		$this->assertLondon( '2026-10-07 12:00', $this->office()->add( $this->london( '2026-10-07 10:00' ), 120 ) );
	}

	public function test_friday_5pm_rolls_to_monday() {
		// Friday 9 Oct 2026, 17:00 + 4h: 30m Friday, 3h30m Monday.
		$this->assertLondon( '2026-10-12 12:30', $this->office()->add( $this->london( '2026-10-09 17:00' ), 240 ) );
	}

	public function test_raised_out_of_hours_starts_at_opening() {
		// Saturday + 1h → Monday 10:00.
		$this->assertLondon( '2026-10-12 10:00', $this->office()->add( $this->london( '2026-10-10 14:00' ), 60 ) );
		// 06:00 Tuesday + 1h → 10:00 Tuesday.
		$this->assertLondon( '2026-10-13 10:00', $this->office()->add( $this->london( '2026-10-13 06:00' ), 60 ) );
		// 20:00 Tuesday + 1h → 10:00 Wednesday.
		$this->assertLondon( '2026-10-14 10:00', $this->office()->add( $this->london( '2026-10-13 20:00' ), 60 ) );
	}

	public function test_exactly_at_closing_lands_on_closing() {
		$this->assertLondon( '2026-10-07 17:30', $this->office()->add( $this->london( '2026-10-07 17:00' ), 30 ) );
	}

	public function test_bank_holiday_weekend_is_skipped() {
		// Thursday 2 Apr 2026 17:00 + 1h: Good Friday and Easter Monday closed → Tuesday 7th 09:30.
		$this->assertLondon( '2026-04-07 09:30', $this->office()->add( $this->london( '2026-04-02 17:00' ), 60 ) );
	}

	public function test_christmas() {
		// Thursday 24 Dec 2026 17:00 + 8h30m: 30m Thursday, then 25th (hol), weekend, 28th (hol) → Tuesday 29th 17:00.
		$this->assertLondon( '2026-12-29 17:00', $this->office()->add( $this->london( '2026-12-24 17:00' ), 510 ) );
	}

	public function test_multi_day_target() {
		// 5 working days (42.5h) from Monday 09:00 → Friday 17:30.
		$this->assertLondon( '2026-10-16 17:30', $this->office()->add( $this->london( '2026-10-12 09:00' ), 5 * 510 ) );
	}

	public function test_clocks_going_back() {
		// Clocks go back Sunday 25 Oct 2026. Friday 17:00 + 1h → Monday 09:30 local.
		$due = $this->office()->add( $this->london( '2026-10-23 17:00' ), 60 );
		$this->assertLondon( '2026-10-26 09:30', $due );
		$this->assertSame( 'GMT', $due->format( 'T' ) );
	}

	public function test_clocks_going_forward() {
		// Clocks go forward Sunday 29 Mar 2026. Friday 17:00 + 1h → Monday 09:30 BST.
		$due = $this->office()->add( $this->london( '2026-03-27 17:00' ), 60 );
		$this->assertLondon( '2026-03-30 09:30', $due );
		$this->assertSame( 'BST', $due->format( 'T' ) );
	}

	public function test_input_in_utc_is_converted() {
		// 16:00 UTC on a BST day is 17:00 London.
		$utc = new DateTimeImmutable( '2026-10-09 16:00', new DateTimeZone( 'UTC' ) );
		$this->assertLondon( '2026-10-12 12:30', $this->office()->add( $utc, 240 ) );
	}

	public function test_between() {
		$office = $this->office();

		$this->assertSame( 120, $office->between( $this->london( '2026-10-07 10:00' ), $this->london( '2026-10-07 12:00' ) ) );
		// Friday 17:00 → Monday 10:00 = 30m + 1h.
		$this->assertSame( 90, $office->between( $this->london( '2026-10-09 17:00' ), $this->london( '2026-10-12 10:00' ) ) );
		// Whole weekend: nothing.
		$this->assertSame( 0, $office->between( $this->london( '2026-10-10 00:00' ), $this->london( '2026-10-11 23:59' ) ) );
		// Backwards: nothing.
		$this->assertSame( 0, $office->between( $this->london( '2026-10-12 10:00' ), $this->london( '2026-10-09 17:00' ) ) );
		// Easter: Thursday 17:00 → Tuesday 10:00 = 30m + 1h.
		$this->assertSame( 90, $office->between( $this->london( '2026-04-02 17:00' ), $this->london( '2026-04-07 10:00' ) ) );
	}

	public function test_add_and_between_agree() {
		$office = $this->office();
		$from   = $this->london( '2026-12-23 15:12' );
		foreach ( array( 1, 59, 510, 777, 3000 ) as $minutes ) {
			$this->assertSame( $minutes, $office->between( $from, $office->add( $from, $minutes ) ), "Round trip for $minutes minutes." );
		}
	}

	public function test_is_open() {
		$office = $this->office();
		$this->assertTrue( $office->is_open( $this->london( '2026-10-07 09:00' ) ) );
		$this->assertFalse( $office->is_open( $this->london( '2026-10-07 17:30' ) ) );
		$this->assertFalse( $office->is_open( $this->london( '2026-10-10 12:00' ) ) );
		$this->assertFalse( $office->is_open( $this->london( '2026-12-25 12:00' ) ) );
	}

	public function test_always_open() {
		$clock = BST_Business_Hours::always( 'Europe/London' );
		$this->assertLondon( '2026-10-10 18:00', $clock->add( $this->london( '2026-10-09 17:00' ), 25 * 60 ) );
		$this->assertSame( 1500, $clock->between( $this->london( '2026-10-09 17:00' ), $this->london( '2026-10-10 18:00' ) ) );
		$this->assertTrue( $clock->is_open( $this->london( '2026-12-25 03:00' ) ) );
		$this->assertSame( 1440, $clock->minutes_per_day() );
	}

	public function test_broken_config_falls_back_to_always_open() {
		$no_days = new BST_Business_Hours( array(), '09:00', '17:30', 'Europe/London' );
		$this->assertTrue( $no_days->is_always_open() );

		$backwards = new BST_Business_Hours( array( 1 ), '18:00', '09:00', 'Europe/London' );
		$this->assertTrue( $backwards->is_always_open() );
	}

	public function test_weekend_working() {
		// Seven-day support 08:00–20:00.
		$clock = new BST_Business_Hours( array( 1, 2, 3, 4, 5, 6, 7 ), '08:00', '20:00', 'Europe/London' );
		$this->assertLondon( '2026-10-10 09:00', $clock->add( $this->london( '2026-10-09 19:00' ), 120 ) );
		$this->assertSame( 720, $clock->minutes_per_day() );
	}
}
