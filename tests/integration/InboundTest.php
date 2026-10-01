<?php
/**
 * Inbound email processing. Needs the WordPress test suite.
 *
 * @package Bonsai_Support_Tickets
 */

/**
 * Email → ticket matching and safety rules.
 */
class InboundTest extends WP_UnitTestCase {

	/**
	 * Client user ID.
	 *
	 * @var int
	 */
	private $client;

	public function set_up() {
		parent::set_up();
		$this->client = self::factory()->user->create(
			array(
				'role'       => 'bst_client',
				'user_email' => 'jane@example.com',
			)
		);
	}

	/**
	 * Build a raw email.
	 *
	 * @param string $from    From address.
	 * @param string $to      To address.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $extra   Extra header lines.
	 * @return string
	 */
	private function email( $from, $to, $subject, $body, array $extra = array() ) {
		$headers = array_merge(
			array(
				'From: ' . $from,
				'To: ' . $to,
				'Subject: ' . $subject,
				'Message-ID: <' . wp_generate_password( 12, false ) . '@example.com>',
				'Content-Type: text/plain; charset=UTF-8',
			),
			$extra
		);
		return implode( "\r\n", $headers ) . "\r\n\r\n" . $body;
	}

	public function test_known_client_email_creates_a_verified_ticket() {
		$result = BST_Inbound::process_raw( $this->email( 'Jane <jane@example.com>', 'bonsaisupport@gmail.com', 'Site down', 'Nothing loads.' ) );

		$this->assertSame( 'created', $result['result'] );
		$this->assertSame( $this->client, BST_Tickets::client_id( $result['ticket_id'] ) );
		$this->assertFalse( BST_Tickets::is_unverified( $result['ticket_id'] ) );
	}

	public function test_unknown_sender_creates_an_unverified_ticket() {
		$result = BST_Inbound::process_raw( $this->email( 'stranger@example.net', 'bonsaisupport@gmail.com', 'Hello', 'Can you quote for a site?' ) );

		$this->assertSame( 'created', $result['result'] );
		$this->assertTrue( BST_Tickets::is_unverified( $result['ticket_id'] ) );
		$this->assertSame( 'stranger@example.net', BST_Tickets::contact_email( $result['ticket_id'] ) );
	}

	public function test_token_reply_is_added_to_the_ticket() {
		$created = BST_Tickets::create( array( 'client_id' => $this->client, 'subject' => 'Menu', 'body' => 'x' ) );
		$ticket  = $created['ticket_id'];
		$to      = BST_Mailer::reply_to_address( $ticket );

		$result = BST_Inbound::process_raw( $this->email( 'jane@example.com', $to, 'Re: anything', "Fixed, thanks\n\nOn Wed wrote:\n> old" ) );

		$this->assertSame( 'replied', $result['result'] );
		$this->assertSame( $ticket, $result['ticket_id'] );
		$messages = BST_Messages::for_ticket( $ticket, true );
		$this->assertStringContainsString( 'Fixed, thanks', end( $messages )->body );
		$this->assertStringNotContainsString( 'old', end( $messages )->body );
	}

	public function test_subject_ref_from_a_stranger_does_not_join_the_ticket() {
		$created = BST_Tickets::create( array( 'client_id' => $this->client, 'subject' => 'Menu', 'body' => 'x' ) );
		$ref     = BST_Tickets::ref( $created['ticket_id'] );

		$result = BST_Inbound::process_raw( $this->email( 'attacker@example.net', 'bonsaisupport@gmail.com', "Re: [{$ref}] Menu", 'Let me in' ) );

		$this->assertSame( 'created', $result['result'] );
		$this->assertNotSame( $created['ticket_id'], $result['ticket_id'] );
		$this->assertCount( 1, BST_Messages::for_ticket( $created['ticket_id'], true ) );
	}

	public function test_wrong_token_is_not_trusted() {
		$created = BST_Tickets::create( array( 'client_id' => $this->client, 'subject' => 'Menu', 'body' => 'x' ) );
		$to      = 'bonsaisupport+t' . $created['ticket_id'] . '-0000000000@gmail.com';

		$result = BST_Inbound::process_raw( $this->email( 'attacker@example.net', $to, 'Hi', 'Let me in' ) );

		$this->assertNotSame( $created['ticket_id'], $result['ticket_id'] );
	}

	public function test_auto_replies_and_duplicates_are_skipped() {
		$auto = $this->email( 'jane@example.com', 'bonsaisupport@gmail.com', 'Out of office', 'Away', array( 'Auto-Submitted: auto-replied' ) );
		$this->assertSame( 'skipped_auto', BST_Inbound::process_raw( $auto )['result'] );

		$raw = $this->email( 'jane@example.com', 'bonsaisupport@gmail.com', 'Once', 'Only once' );
		$this->assertSame( 'created', BST_Inbound::process_raw( $raw )['result'] );
		$this->assertSame( 'duplicate', BST_Inbound::process_raw( $raw )['result'] );
	}

	public function test_agent_note_by_email_stays_internal() {
		$agent   = self::factory()->user->create( array( 'role' => 'bst_agent', 'user_email' => 'ben@example.com' ) );
		$created = BST_Tickets::create( array( 'client_id' => $this->client, 'subject' => 'Menu', 'body' => 'x' ) );

		BST_Inbound::process_raw( $this->email( 'ben@example.com', BST_Mailer::reply_to_address( $created['ticket_id'] ), 'Re', "#note client is on the old plan" ) );

		wp_set_current_user( $this->client );
		$this->assertCount( 1, bst_get_ticket_thread( $created['ticket_id'] ) );
		$this->assertCount( 2, BST_Messages::for_ticket( $created['ticket_id'], true ) );
		unset( $agent );
	}
}
