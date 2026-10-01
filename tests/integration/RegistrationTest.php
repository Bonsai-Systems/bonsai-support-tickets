<?php
/**
 * Client registration and approval. Needs the WordPress test suite:
 * WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit --testsuite integration
 *
 * @package Bonsai_Support_Tickets
 */

/**
 * Registration.
 */
class RegistrationTest extends WP_UnitTestCase {

	/**
	 * Valid form input.
	 *
	 * @return array
	 */
	private function input() {
		return array(
			'client_name' => 'The Ley Arms',
			'first_name'  => 'Jane',
			'last_name'   => 'Smith',
			'email'       => 'jane@leyarms.example',
			'website'     => 'theleyarms.co.uk',
			'phone'       => '01392 123456',
		);
	}

	public function set_up() {
		parent::set_up();
		BST_Install::create_roles();
		reset_phpmailer_instance();
	}

	public function test_valid_input_passes_and_website_is_normalised() {
		$input = $this->input();
		$this->assertSame( array(), BST_Registration::validate( $input ) );
		$this->assertSame( 'https://theleyarms.co.uk', $input['website'] );
	}

	public function test_missing_and_invalid_fields_are_reported() {
		$input = array(
			'client_name' => '',
			'first_name'  => '',
			'last_name'   => '',
			'email'       => 'not-an-email',
			'website'     => 'nope',
			'phone'       => 'call me',
		);
		$errors = BST_Registration::validate( $input );
		foreach ( array( 'client_name', 'first_name', 'last_name', 'email', 'website', 'phone' ) as $key ) {
			$this->assertArrayHasKey( $key, $errors, $key );
		}
	}

	public function test_existing_email_is_rejected() {
		self::factory()->user->create( array( 'user_email' => 'jane@leyarms.example' ) );
		$input = $this->input();
		$this->assertArrayHasKey( 'email', BST_Registration::validate( $input ) );
	}

	public function test_new_account_is_a_pending_client_with_fields() {
		$input = $this->input();
		BST_Registration::validate( $input );
		$user_id = BST_Registration::create_account( $input );

		$user = get_userdata( $user_id );
		$this->assertSame( array( 'bst_client' ), array_values( $user->roles ) );
		$this->assertSame( 'The Ley Arms', BST_Clients::client_name( $user_id ) );
		$this->assertSame( '01392 123456', BST_Clients::phone( $user_id ) );
		$this->assertSame( 'https://theleyarms.co.uk', $user->user_url );
		$this->assertSame( 'Jane Smith', $user->display_name );
		$this->assertTrue( BST_Clients::is_pending( $user_id ) );
		$this->assertStringNotContainsString( '@', $user->user_login, 'Login must not expose the email address.' );
		$this->assertSame( 'The Ley Arms — Jane Smith', BST_Clients::label( $user ) );
	}

	public function test_pending_account_cannot_log_in_reset_or_submit() {
		$input = $this->input();
		BST_Registration::validate( $input );
		$user_id = BST_Registration::create_account( $input );
		wp_set_password( 'known-password', $user_id );

		$result = wp_authenticate( 'jane@leyarms.example', 'known-password' );
		$this->assertWPError( $result );
		$this->assertSame( 'bst_pending', $result->get_error_code() );

		$this->assertWPError( get_password_reset_key( get_userdata( $user_id ) ) );
		$this->assertFalse( user_can( $user_id, 'bst_submit_ticket' ) );
	}

	public function test_approval_unlocks_the_account_and_emails_a_set_password_link() {
		$input = $this->input();
		BST_Registration::validate( $input );
		$user_id = BST_Registration::create_account( $input );
		wp_set_password( 'known-password', $user_id );

		$this->assertTrue( BST_Registration::approve( $user_id ) );
		$this->assertFalse( BST_Clients::is_pending( $user_id ) );
		$this->assertTrue( user_can( $user_id, 'bst_submit_ticket' ) );
		$this->assertInstanceOf( 'WP_User', wp_authenticate( 'jane@leyarms.example', 'known-password' ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'jane@leyarms.example', $mail->to[0][0] );
		$this->assertStringContainsString( 'action=rp', $mail->body );

		$this->assertWPError( BST_Registration::approve( $user_id ), 'Approving twice fails.' );
	}

	public function test_reject_only_deletes_pending_clients() {
		$input = $this->input();
		BST_Registration::validate( $input );
		$pending = BST_Registration::create_account( $input );
		$normal  = self::factory()->user->create( array( 'role' => 'bst_client' ) );

		$this->assertWPError( BST_Registration::reject( $normal ) );
		$this->assertInstanceOf( 'WP_User', get_userdata( $normal ) );

		$this->assertTrue( BST_Registration::reject( $pending ) );
		$this->assertFalse( get_userdata( $pending ) );
	}

	public function test_timestamp_token_rejects_tampering_and_instant_submits() {
		$this->assertFalse( BST_Registration::timestamp_ok( BST_Registration::timestamp_token() ), 'Too fast.' );

		$old  = (string) ( time() - 10 );
		$good = $old . '.' . substr( wp_hash( 'bst_register_ts|' . $old ), 0, 16 );
		$this->assertTrue( BST_Registration::timestamp_ok( $good ) );
		$this->assertFalse( BST_Registration::timestamp_ok( $old . '.0000000000000000' ) );
		$this->assertFalse( BST_Registration::timestamp_ok( 'garbage' ) );
	}

	public function test_inbound_email_from_a_pending_account_is_unverified() {
		$input = $this->input();
		BST_Registration::validate( $input );
		BST_Registration::create_account( $input );

		$raw    = "From: Jane <jane@leyarms.example>\r\nTo: bonsaisupport@gmail.com\r\nSubject: Hello\r\nMessage-ID: <pending-test@x>\r\n\r\nPlease help.";
		$result = BST_Inbound::process_raw( $raw );
		$this->assertSame( 'created', $result['result'] );

		$ticket = get_posts(
			array(
				'post_type'   => BST_Post_Types::TICKET,
				'post_status' => 'any',
				'numberposts' => 1,
			)
		)[0];
		$this->assertTrue( BST_Tickets::is_unverified( $ticket->ID ) );
	}
}
