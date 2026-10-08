<?php
/**
 * Time tracking: entries, client stamp, retainer usage, alerts, permissions, CSV.
 * Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * Time entries against tickets and monthly retainers.
 */
class TimeTest extends WP_UnitTestCase {

	/**
	 * Client company with a 10-hour retainer.
	 *
	 * @var int
	 */
	private $company;

	/**
	 * Ticket for that company.
	 *
	 * @var int
	 */
	private $ticket;

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private $agent;

	/**
	 * Captured emails.
	 *
	 * @var array[]
	 */
	private $mail = array();

	public function set_up() {
		parent::set_up();
		BST_Settings::save(
			array(
				'time_enabled' => 1,
				'time_alerts'  => 1,
			)
		);

		$this->company = self::factory()->post->create(
			array(
				'post_type'  => BST_Post_Types::COMPANY,
				'post_title' => 'Ley Arms',
			)
		);
		update_post_meta( $this->company, BST_Companies::META_RETAINER, '10' );

		$client = self::factory()->user->create( array( 'role' => 'bst_client' ) );
		BST_Companies::set_user_company( $client, $this->company );

		$this->ticket = BST_Tickets::create(
			array(
				'client_id' => $client,
				'subject'   => 'Update plugins',
				'body'      => 'Please',
			)
		)['ticket_id'];

		$this->agent = self::factory()->user->create( array( 'role' => 'bst_agent' ) );
		wp_set_current_user( $this->agent );

		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		delete_option( BST_Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Capture outgoing mail.
	 *
	 * @param null  $null Short-circuit.
	 * @param array $atts wp_mail args.
	 * @return bool
	 */
	public function capture_mail( $null, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	/**
	 * Log time on the test ticket.
	 *
	 * @param int  $minutes  Minutes.
	 * @param bool $billable Billable.
	 * @param string $date   Work date.
	 * @return int
	 */
	private function log( $minutes, $billable = true, $date = '' ) {
		$id = BST_Time::add(
			array(
				'ticket_id' => $this->ticket,
				'minutes'   => $minutes,
				'billable'  => $billable,
				'work_date' => $date,
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_entry_is_stamped_with_the_ticket_client_and_logged() {
		$entry = BST_Time::get( $this->log( 90 ) );

		$this->assertSame( $this->company, (int) $entry->company_id );
		$this->assertSame( $this->agent, (int) $entry->user_id );
		$this->assertSame( BST_Time::today(), $entry->work_date );

		$events = wp_list_pluck( BST_Activity::for_ticket( $this->ticket ), 'action' );
		$this->assertContains( 'time', $events );
	}

	public function test_stamp_survives_the_ticket_moving_client() {
		$entry_id = $this->log( 30 );
		$other    = self::factory()->post->create( array( 'post_type' => BST_Post_Types::COMPANY ) );

		BST_Tickets::set_company( $this->ticket, $other );

		$this->assertSame( $this->company, (int) BST_Time::get( $entry_id )->company_id );
	}

	public function test_unassigned_entries_follow_the_ticket_to_its_first_client() {
		BST_Tickets::set_company( $this->ticket, 0 );
		$entry_id = $this->log( 30 );
		$this->assertSame( 0, (int) BST_Time::get( $entry_id )->company_id );

		BST_Tickets::set_company( $this->ticket, $this->company );
		$this->assertSame( $this->company, (int) BST_Time::get( $entry_id )->company_id );
	}

	public function test_rejects_bad_minutes_and_missing_ticket() {
		$this->assertWPError( BST_Time::add( array( 'ticket_id' => $this->ticket, 'minutes' => 0 ) ) );
		$this->assertWPError( BST_Time::add( array( 'ticket_id' => $this->ticket, 'minutes' => 1441 ) ) );
		$this->assertWPError( BST_Time::add( array( 'ticket_id' => 999999, 'minutes' => 30 ) ) );
	}

	public function test_only_billable_time_uses_the_retainer() {
		$this->log( 360 );
		$this->log( 120, false );

		$usage = BST_Time::usage( $this->company );

		$this->assertSame( 600, $usage['allowance'] );
		$this->assertSame( 360, $usage['used'] );
		$this->assertSame( 120, $usage['nonbillable'] );
		$this->assertSame( 240, $usage['remaining'] );
		$this->assertSame( 0, $usage['over'] );
		$this->assertSame( 'ok', $usage['level'] );
	}

	public function test_over_the_allowance() {
		$this->log( 660 );
		$usage = BST_Time::usage( $this->company );

		$this->assertSame( 0, $usage['remaining'] );
		$this->assertSame( 60, $usage['over'] );
		$this->assertSame( 'over', $usage['level'] );
	}

	public function test_months_are_separate() {
		$last_month = wp_date( 'Y-m-15', strtotime( 'first day of last month' ) );
		$this->log( 300, true, $last_month );

		$this->assertSame( 0, BST_Time::usage( $this->company )['used'] );
		$this->assertSame( 300, BST_Time::usage( $this->company, substr( $last_month, 0, 7 ) )['used'] );
	}

	public function test_alerts_once_at_80_and_once_at_100() {
		$this->log( 420 ); // 70%.
		$this->assertCount( 0, $this->mail );

		$this->log( 60 ); // 80%.
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( '80%', $this->mail[0]['subject'] );

		$this->log( 30 ); // 85%: no repeat.
		$this->assertCount( 1, $this->mail );

		$this->log( 120 ); // 105%.
		$this->assertCount( 2, $this->mail );
		$this->assertStringContainsString( 'has used their monthly retainer', $this->mail[1]['subject'] );

		// Only admins (bst_manage_time) are emailed, never the client.
		$admins = wp_list_pluck( get_users( array( 'capability' => 'bst_manage_time' ) ), 'user_email' );
		foreach ( $this->mail as $mail ) {
			$this->assertContains( $mail['to'], $admins );
		}
	}

	public function test_jumping_straight_past_100_sends_one_alert() {
		$this->log( 700 );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'has used their monthly retainer', $this->mail[0]['subject'] );
	}

	public function test_backdated_time_never_alerts() {
		$this->log( 700, true, wp_date( 'Y-m-15', strtotime( 'first day of last month' ) ) );
		$this->assertCount( 0, $this->mail );
	}

	public function test_alerts_can_be_switched_off() {
		BST_Settings::save( array( 'time_alerts' => 0 ) );
		$this->log( 700 );
		$this->assertCount( 0, $this->mail );
	}

	public function test_agents_edit_only_their_own_entries() {
		$mine    = BST_Time::get( $this->log( 30 ) );
		$other  = self::factory()->user->create( array( 'role' => 'bst_agent' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$client  = self::factory()->user->create( array( 'role' => 'bst_client' ) );

		$this->assertTrue( BST_Time::can_edit( $mine, $this->agent ) );
		$this->assertFalse( BST_Time::can_edit( $mine, $other ) );
		$this->assertTrue( BST_Time::can_edit( $mine, $admin ) );
		$this->assertFalse( BST_Time::can_edit( $mine, $client ) );
	}

	public function test_update_and_delete() {
		$id = $this->log( 30 );

		$this->assertTrue( BST_Time::update( $id, array( 'minutes' => 45, 'billable' => false, 'note' => 'Fixed' ) ) );
		$entry = BST_Time::get( $id );
		$this->assertSame( 45, (int) $entry->minutes );
		$this->assertSame( 0, (int) $entry->billable );
		$this->assertSame( 'Fixed', $entry->note );
		$this->assertSame( $this->company, (int) $entry->company_id, 'Editing keeps the client stamp.' );

		$this->assertWPError( BST_Time::update( $id, array( 'minutes' => 0 ) ) );

		$this->assertTrue( BST_Time::delete( $id ) );
		$this->assertNull( BST_Time::get( $id ) );
	}

	public function test_csv_rows_and_formula_guard() {
		$id = $this->log( 90 );
		BST_Time::update( $id, array( 'note' => '=HYPERLINK("x")' ) );

		$rows = BST_Time::entries_csv_rows( BST_Time::current_month(), $this->company );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Ley Arms', $rows[1][1] );
		$this->assertSame( '1.50', $rows[1][5] );
		$this->assertSame( "'=HYPERLINK(\"x\")", BST_Time::csv_cell( $rows[1][8] ) );
		$this->assertSame( '-5', BST_Time::csv_cell( '-5' ), 'Negative numbers are left alone.' );

		$summary = BST_Time::summary_csv_rows( BST_Time::current_month() );
		$names   = wp_list_pluck( array_slice( $summary, 1 ), 0 );
		$this->assertContains( 'Ley Arms', $names );
	}

	public function test_portal_usage_only_when_switched_on() {
		$client = BST_Tickets::client_id( $this->ticket );
		$this->log( 120 );

		$this->assertNull( BST_Time::portal_usage( $client ) );

		BST_Settings::save( array( 'time_portal' => 1 ) );
		$usage = BST_Time::portal_usage( $client );
		$this->assertSame( 120, $usage['used'] );
		$this->assertArrayHasKey( 'month_label', $usage );

		// No retainer, nothing shown.
		delete_post_meta( $this->company, BST_Companies::META_RETAINER );
		$this->assertNull( BST_Time::portal_usage( $client ) );
	}
}
