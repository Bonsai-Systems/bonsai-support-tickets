<?php
/**
 * Permission and visibility tests. Needs the WordPress test suite:
 * WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit --testsuite integration
 *
 * @package Support_Desk
 */

/**
 * Who can see what.
 */
class TicketPermissionsTest extends WP_UnitTestCase {

	/**
	 * Users.
	 *
	 * @var int
	 */
	private $client_a;
	private $client_b;
	private $agent;

	/**
	 * Ticket owned by client A.
	 *
	 * @var int
	 */
	private $ticket;

	public function set_up() {
		parent::set_up();

		$this->client_a = self::factory()->user->create( array( 'role' => 'bst_client' ) );
		$this->client_b = self::factory()->user->create( array( 'role' => 'bst_client' ) );
		$this->agent    = self::factory()->user->create( array( 'role' => 'bst_agent' ) );

		$created = BST_Tickets::create(
			array(
				'client_id' => $this->client_a,
				'subject'   => 'Menu broken',
				'body'      => '<p>Help</p>',
			)
		);

		$this->ticket = $created['ticket_id'];
	}

	public function test_owner_and_agents_can_view_but_other_clients_cannot() {
		$this->assertTrue( BST_Tickets::user_can_view( $this->ticket, $this->client_a ) );
		$this->assertTrue( BST_Tickets::user_can_view( $this->ticket, $this->agent ) );
		$this->assertFalse( BST_Tickets::user_can_view( $this->ticket, $this->client_b ) );
		$this->assertFalse( BST_Tickets::user_can_view( $this->ticket, 0 ) );
	}

	public function test_internal_notes_never_reach_the_client_thread() {
		BST_Tickets::reply(
			$this->ticket,
			array(
				'user_id'    => $this->agent,
				'visibility' => BST_Messages::INTERNAL,
				'body'       => '<p>Secret: client owes us money</p>',
			)
		);

		wp_set_current_user( $this->client_a );
		$thread = bst_get_ticket_thread( $this->ticket );

		$this->assertCount( 1, $thread );
		$this->assertStringNotContainsString( 'Secret', wp_list_pluck( $thread, 'body' )[0] );
		$this->assertCount( 2, BST_Messages::for_ticket( $this->ticket, true ) );
	}

	public function test_clients_cannot_write_internal_notes() {
		$message_id = BST_Tickets::reply(
			$this->ticket,
			array(
				'user_id'    => $this->client_a,
				'visibility' => BST_Messages::INTERNAL,
				'body'       => '<p>Trying to hide this</p>',
			)
		);

		$this->assertSame( BST_Messages::EXTERNAL, BST_Messages::get( $message_id )->visibility );
	}

	public function test_thread_is_empty_for_another_client() {
		wp_set_current_user( $this->client_b );
		$this->assertSame( array(), bst_get_ticket_thread( $this->ticket ) );
	}

	public function test_status_follows_replies() {
		BST_Tickets::reply( $this->ticket, array( 'user_id' => $this->agent, 'body' => '<p>On it</p>' ) );
		$this->assertSame( 'awaiting_client', BST_Tickets::status( $this->ticket ) );

		BST_Tickets::reply( $this->ticket, array( 'user_id' => $this->client_a, 'body' => '<p>Thanks</p>' ) );
		$this->assertSame( 'open', BST_Tickets::status( $this->ticket ) );

		BST_Tickets::set_status( $this->ticket, 'solved', $this->agent );
		BST_Tickets::reply( $this->ticket, array( 'user_id' => $this->client_a, 'body' => '<p>Broken again</p>' ) );
		$this->assertSame( 'open', BST_Tickets::status( $this->ticket ) );
	}

	public function test_references_are_unique_and_sequential() {
		$second = BST_Tickets::create( array( 'client_id' => $this->client_a, 'subject' => 'Two', 'body' => 'x' ) );
		$first  = (int) substr( BST_Tickets::ref( $this->ticket ), strrpos( BST_Tickets::ref( $this->ticket ), '-' ) + 1 );
		$next   = (int) substr( BST_Tickets::ref( $second['ticket_id'] ), strrpos( BST_Tickets::ref( $second['ticket_id'] ), '-' ) + 1 );

		$this->assertSame( $first + 1, $next );
	}

	public function test_reply_token_is_ticket_specific() {
		$token = BST_Tickets::reply_token( $this->ticket );
		$this->assertTrue( BST_Tickets::verify_reply_token( $this->ticket, $token ) );
		$this->assertFalse( BST_Tickets::verify_reply_token( $this->ticket + 1, $token ) );
	}
}
