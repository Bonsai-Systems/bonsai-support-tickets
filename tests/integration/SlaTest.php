<?php
/**
 * SLAs and response reminders: due times, first response, pausing,
 * plan targets, warnings and reminders. Needs the WordPress test suite.
 *
 * Time travel: "now" comes from the bst_sla_now filter, and tickets are
 * back-dated to it, so nothing here depends on the real clock.
 *
 * @package Support_Desk
 */

/**
 * BST_SLA.
 */
class SlaTest extends WP_UnitTestCase {

	/**
	 * Unix time the SLA code sees as "now".
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Client user.
	 *
	 * @var int
	 */
	private $client;

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
		update_option( 'timezone_string', 'Europe/London' );
		BST_Settings::save(
			array(
				'sla_enabled'        => 1,
				'reminders_enabled'  => 1,
				'sla_holiday_region' => 'none',
				'sla_warn_percent'   => 25,
			)
		);

		$this->client = self::factory()->user->create( array( 'role' => 'bst_client' ) );
		$this->agent  = self::factory()->user->create(
			array(
				'role'       => 'bst_agent',
				'user_email' => 'agent@example.test',
			)
		);

		$this->travel( '2026-10-07 10:00' ); // Wednesday.
		add_filter( 'bst_sla_now', array( $this, 'filter_now' ) );

		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'bst_sla_now', array( $this, 'filter_now' ) );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		delete_option( BST_Settings::OPTION );
		delete_option( 'timezone_string' );
		parent::tear_down();
	}

	/**
	 * Filter callback.
	 *
	 * @return int
	 */
	public function filter_now() {
		return $this->now;
	}

	/**
	 * Capture instead of sending.
	 *
	 * @param null|bool $short Short-circuit.
	 * @param array     $atts  wp_mail() args.
	 * @return bool
	 */
	public function capture_mail( $short, $atts ) {
		unset( $short );
		$this->mail[] = $atts;
		return true;
	}

	/**
	 * Set "now" (London time).
	 *
	 * @param string $when Y-m-d H:i.
	 */
	private function travel( $when ) {
		$this->now = ( new DateTimeImmutable( $when, new DateTimeZone( 'Europe/London' ) ) )->getTimestamp();
	}

	/**
	 * Move "now" on.
	 *
	 * @param int $minutes Minutes.
	 */
	private function later( $minutes ) {
		$this->now += $minutes * 60;
	}

	/**
	 * London time of a moment.
	 *
	 * @param DateTimeInterface $at Moment.
	 * @return string Y-m-d H:i.
	 */
	private function london( DateTimeInterface $at ) {
		return $at->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'Y-m-d H:i' );
	}

	/**
	 * Ticket raised "now".
	 *
	 * @param string $priority Priority.
	 * @param array  $args     Extra create() args.
	 * @return int
	 */
	private function ticket( $priority = 'urgent', array $args = array() ) {
		$id = BST_Tickets::create(
			$args + array(
				'client_id' => $this->client,
				'subject'   => 'Site down',
				'body'      => 'Help',
				'priority'  => $priority,
			)
		)['ticket_id'];

		// Back-date the ticket to the test's "now".
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $this->now ),
				'post_date'     => wp_date( 'Y-m-d H:i:s', $this->now ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		BST_SLA::refresh( $id );
		return $id;
	}

	/**
	 * Agent reply to the client.
	 *
	 * @param int    $ticket_id  Ticket.
	 * @param string $visibility external|internal.
	 */
	private function agent_reply( $ticket_id, $visibility = BST_Messages::EXTERNAL ) {
		BST_Tickets::reply(
			$ticket_id,
			array(
				'user_id'    => $this->agent,
				'body'       => 'On it',
				'visibility' => $visibility,
			)
		);
	}

	/**
	 * Solve a ticket "now" (solved_at is real time, so pin it to ours).
	 *
	 * @param int $ticket_id Ticket.
	 */
	private function solve( $ticket_id ) {
		BST_Tickets::set_status( $ticket_id, 'solved', $this->agent );
		update_post_meta( $ticket_id, BST_Tickets::META_SOLVED_AT, gmdate( 'Y-m-d H:i:s', $this->now ) );
		BST_SLA::refresh( $ticket_id );
	}

	public function test_off_by_default_nothing_is_measured() {
		BST_Settings::save( array( 'sla_enabled' => 0 ) );
		$this->assertFalse( BST_SLA::applies( $this->ticket() ) );
	}

	public function test_urgent_due_times_on_the_24_7_clock() {
		$state = BST_SLA::state( $this->ticket( 'urgent' ) );

		$this->assertSame( '2026-10-07 11:00', $this->london( $state['response_due'] ) );
		$this->assertSame( '2026-10-07 18:00', $this->london( $state['resolution_due'] ) );
		$this->assertSame( 'pending', $state['response'] );
		$this->assertSame( 'pending', $state['resolution'] );
	}

	public function test_normal_priority_uses_business_hours() {
		$this->travel( '2026-10-09 17:00' ); // Friday.
		$state = BST_SLA::state( $this->ticket( 'normal' ) );

		// 8h: 30m on Friday, 7h30m from 09:00 Monday.
		$this->assertSame( '2026-10-12 16:30', $this->london( $state['response_due'] ) );
	}

	public function test_first_response_met_and_breached() {
		$quick = $this->ticket();
		$this->later( 30 );
		$this->agent_reply( $quick );
		$this->assertSame( 'met', BST_SLA::state( $quick )['response'] );

		$slow = $this->ticket();
		$this->later( 120 );
		$this->agent_reply( $slow );
		$this->assertSame( 'breached', BST_SLA::state( $slow )['response'] );
	}

	public function test_internal_note_is_not_a_response() {
		$id = $this->ticket();
		$this->later( 10 );
		$this->agent_reply( $id, BST_Messages::INTERNAL );
		$this->assertSame( '', get_post_meta( $id, BST_SLA::META_FIRST_RESPONSE, true ) );
	}

	public function test_awaiting_client_pauses_resolution() {
		$id = $this->ticket( 'urgent' ); // Resolution 8h, 24/7.

		$this->later( 120 );
		BST_Tickets::set_status( $id, 'awaiting_client', $this->agent );
		$this->later( 600 ); // 10h paused: would have breached without the pause.
		$this->assertSame( 'paused', BST_SLA::state( $id )['resolution'] );

		BST_Tickets::set_status( $id, 'open', $this->agent );
		$state = BST_SLA::state( $id );
		$this->assertSame( 'pending', $state['resolution'] );
		$this->assertSame( 360, $state['resolution_left'] );
		// 10:00 + 8h + 10h paused.
		$this->assertSame( '2026-10-08 04:00', $this->london( $state['resolution_due'] ) );
	}

	public function test_agent_reply_pauses_until_the_client_answers() {
		$id = $this->ticket( 'urgent' );
		$this->later( 60 );
		$this->agent_reply( $id ); // New → Awaiting client.
		$this->assertSame( 'awaiting_client', BST_Tickets::status( $id ) );

		$this->later( 600 );
		BST_Tickets::reply(
			$id,
			array(
				'user_id' => $this->client,
				'body'    => 'Thanks, still broken',
			)
		);
		$this->assertSame( 420, BST_SLA::state( $id )['resolution_left'] );
	}

	public function test_solved_ticket_is_judged_when_solved() {
		$id = $this->ticket( 'urgent' );
		$this->agent_reply( $id );
		$this->later( 420 );
		$this->solve( $id );

		$this->later( 2000 ); // Long after the target.
		$this->assertSame( 'met', BST_SLA::state( $id )['resolution'] );
	}

	public function test_plan_targets_override_defaults() {
		$plan    = self::factory()->term->create(
			array(
				'taxonomy' => BST_Post_Types::PLAN,
				'name'     => 'Gold',
			)
		);
		$company = self::factory()->post->create( array( 'post_type' => BST_Post_Types::COMPANY ) );
		wp_set_object_terms( $company, array( $plan ), BST_Post_Types::PLAN );
		update_term_meta(
			$plan,
			BST_SLA::PLAN_META,
			array(
				'urgent' => array(
					'response'   => 30,
					'resolution' => 0, // Blank: keep the default.
				),
			)
		);

		$id      = $this->ticket( 'urgent', array( 'company_id' => $company ) );
		$targets = BST_SLA::targets_for( $id );

		$this->assertSame( 30, $targets['response'] );
		$this->assertSame( BST_SLA::targets()['urgent']['resolution'], $targets['resolution'] );
		$this->assertSame( $plan, $targets['plan'] );
	}

	public function test_warning_then_breach_each_sent_once() {
		BST_Settings::save( array( 'reminders_enabled' => 0 ) );
		$id = $this->ticket( 'urgent' ); // Response 60m; warn at 15m left.
		$this->mail = array(); // Drop the client's acknowledgement.

		$this->later( 30 );
		$this->assertSame( 0, BST_SLA::run()['warned'] );

		$this->later( 20 );
		$this->assertSame( 1, BST_SLA::run()['warned'] );
		$this->assertSame( 0, BST_SLA::run()['warned'] );
		$this->assertStringContainsString( 'SLA at risk', $this->mail[0]['subject'] );
		// Unassigned: every agent (the site admin included) gets it.
		$this->assertContains( 'agent@example.test', array_merge( ...array_map( fn( $mail ) => (array) $mail['to'], $this->mail ) ) );

		$this->later( 20 );
		$counts = BST_SLA::run();
		$this->assertSame( 1, $counts['breached'] );
		$this->assertSame( 0, BST_SLA::run()['breached'] );
		$this->assertStringContainsString( 'SLA breached', end( $this->mail )['subject'] );
		$this->assertSame( 'breached', get_post_meta( $id, BST_SLA::META_RESPONSE, true ) );
	}

	public function test_reminders_cadence_reset_and_pause() {
		BST_Settings::save( array( 'sla_enabled' => 0 ) ); // Reminders work without SLAs.
		$id = $this->ticket( 'urgent' ); // First after 1h, then every 1h.
		$this->mail = array(); // Drop the client's acknowledgement.

		$this->later( 59 );
		$this->assertSame( 0, BST_SLA::run()['reminded'] );

		$this->later( 2 );
		$this->assertSame( 1, BST_SLA::run()['reminded'] );
		$this->assertSame( 0, BST_SLA::run()['reminded'] );
		$this->assertStringContainsString( 'Reminder:', $this->mail[0]['subject'] );

		$this->later( 60 );
		$this->assertSame( 1, BST_SLA::run()['reminded'] );

		// Client replies: the wait restarts.
		BST_Tickets::reply(
			$id,
			array(
				'user_id' => $this->client,
				'body'    => 'Any news?',
			)
		);
		$this->later( 30 );
		$this->assertSame( 0, BST_SLA::run()['reminded'] );

		// Waiting on the client: no reminders however long.
		BST_Tickets::set_status( $id, 'awaiting_client', $this->agent );
		$this->later( 600 );
		$this->assertSame( 0, BST_SLA::run()['reminded'] );
	}

	public function test_reminders_due() {
		$this->assertSame( 0, BST_SLA::reminders_due( 59, 60, 60 ) );
		$this->assertSame( 1, BST_SLA::reminders_due( 60, 60, 60 ) );
		$this->assertSame( 1, BST_SLA::reminders_due( 119, 60, 60 ) );
		$this->assertSame( 3, BST_SLA::reminders_due( 180, 60, 60 ) );
		$this->assertSame( 1, BST_SLA::reminders_due( 5000, 60, 0 ) );
		$this->assertSame( 0, BST_SLA::reminders_due( 5000, 0, 60 ) );
	}

	public function test_monitor_tickets_are_not_measured() {
		$id = $this->ticket( 'urgent', array( 'source' => 'monitor' ) );
		$this->assertFalse( BST_SLA::applies( $id ) );
	}

	public function test_priority_change_moves_due_time() {
		$id = $this->ticket( 'urgent' );
		BST_Tickets::set_priority( $id, 'high' ); // 4h on business hours.
		$this->assertSame( '2026-10-07 14:00', $this->london( BST_SLA::state( $id )['response_due'] ) );
	}
}
