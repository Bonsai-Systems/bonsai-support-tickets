<?php
/**
 * New-ticket auto-reply. Needs the WordPress test suite.
 *
 * @package Bonsai_Support_Tickets
 */

/**
 * When the auto-reply is (and isn't) sent, and what it contains.
 */
class AutoReplyTest extends WP_UnitTestCase {

	/**
	 * Client user ID.
	 *
	 * @var int
	 */
	private $client;

	/**
	 * Emails captured from the bst_email filter.
	 *
	 * @var array[]
	 */
	private $sent = array();

	public function set_up() {
		parent::set_up();
		$this->client = self::factory()->user->create(
			array(
				'role'       => 'bst_client',
				'user_email' => 'jane@example.com',
			)
		);
		$this->sent   = array();
		add_filter( 'bst_email', array( $this, 'capture' ) );
	}

	public function tear_down() {
		remove_filter( 'bst_email', array( $this, 'capture' ) );
		delete_option( BST_Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Record an outgoing email.
	 *
	 * @param array $email to, subject, html, headers.
	 * @return array
	 */
	public function capture( $email ) {
		$this->sent[] = $email;
		return $email;
	}

	/**
	 * Emails sent to an address.
	 *
	 * @param string $to Address.
	 * @return array[]
	 */
	private function sent_to( $to ) {
		return array_values(
			array_filter(
				$this->sent,
				function ( $email ) use ( $to ) {
					return $to === $email['to'];
				}
			)
		);
	}

	/**
	 * Create a ticket as the client.
	 *
	 * @param string $subject Subject.
	 * @return int Ticket ID.
	 */
	private function create_ticket( $subject = 'Site down' ) {
		$created = BST_Tickets::create(
			array(
				'client_id' => $this->client,
				'subject'   => $subject,
				'body'      => '<p>Nothing loads.</p>',
				'source'    => 'web',
			)
		);
		return $created['ticket_id'];
	}

	public function test_new_ticket_sends_the_default_auto_reply() {
		$ticket = $this->create_ticket();
		$ref    = BST_Tickets::ref( $ticket );
		$emails = $this->sent_to( 'jane@example.com' );

		$this->assertCount( 1, $emails );
		$this->assertSame( '[' . $ref . '] Thank you for contacting The Bonsai Digital Collective Support – [Site down]', $emails[0]['subject'] );
		$this->assertStringContainsString( '<strong>[' . $ref . ']</strong>', $emails[0]['html'] );
		$this->assertStringContainsString( 'Nothing loads.', $emails[0]['html'] );
	}

	public function test_client_reply_does_not_send_the_auto_reply() {
		$ticket     = $this->create_ticket();
		$this->sent = array();

		BST_Tickets::reply(
			$ticket,
			array(
				'user_id' => $this->client,
				'body'    => '<p>Still broken.</p>',
			)
		);

		$this->assertCount( 0, $this->sent_to( 'jane@example.com' ) );
	}

	public function test_auto_reply_can_be_turned_off() {
		BST_Settings::save( array( 'autoreply_enabled' => 0 ) );
		$this->create_ticket();

		$this->assertCount( 0, $this->sent_to( 'jane@example.com' ) );
	}

	public function test_edited_template_is_used_and_values_are_escaped_in_the_body() {
		BST_Settings::save(
			array(
				'autoreply_enabled' => 1,
				'autoreply_subject' => 'Got it: {{ticket.title}}',
				'autoreply_body'    => '<p>Hi, we have {{ticket.title}} as {{ticket.id}}.</p>',
			)
		);
		$ticket = $this->create_ticket( 'Fish & <b>chips</b>' );
		$emails = $this->sent_to( 'jane@example.com' );

		$this->assertCount( 1, $emails );
		$this->assertStringEndsWith( 'Got it: Fish & chips', $emails[0]['subject'] );
		$this->assertStringContainsString( 'Hi, we have Fish &amp; chips as ' . BST_Tickets::ref( $ticket ) . '.', $emails[0]['html'] );
	}

	public function test_clearing_the_template_restores_the_default() {
		BST_Settings::save(
			array(
				'autoreply_enabled' => 1,
				'autoreply_subject' => '',
				'autoreply_body'    => '<p> </p>',
			)
		);

		$this->assertSame( BST_Settings::default_autoreply_subject(), BST_Settings::get( 'autoreply_subject' ) );
		$this->assertSame( BST_Settings::default_autoreply_body(), BST_Settings::get( 'autoreply_body' ) );
	}

	public function test_unknown_sender_gets_no_auto_reply() {
		BST_Inbound::process_raw(
			implode(
				"\r\n",
				array(
					'From: stranger@example.net',
					'To: bonsaisupport@gmail.com',
					'Subject: Hello',
					'Message-ID: <' . wp_generate_password( 12, false ) . '@example.com>',
					'Content-Type: text/plain; charset=UTF-8',
				)
			) . "\r\n\r\nCan you quote for a site?"
		);

		$this->assertCount( 0, $this->sent_to( 'stranger@example.net' ) );
	}
}
