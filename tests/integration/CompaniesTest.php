<?php
/**
 * Client companies. Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * Company records, ticket stamping, permissions and the migration from
 * free-text client names.
 */
class CompaniesTest extends WP_UnitTestCase {

	/**
	 * A Support Client user, optionally with a legacy client name.
	 *
	 * @param string $legacy_name bst_client_name value.
	 * @return int User ID.
	 */
	private function client( $legacy_name = '' ) {
		$user_id = self::factory()->user->create( array( 'role' => 'bst_client' ) );
		if ( '' !== $legacy_name ) {
			update_user_meta( $user_id, BST_Clients::META_CLIENT_NAME, $legacy_name );
		}
		return $user_id;
	}

	/**
	 * A ticket from a client.
	 *
	 * @param int $user_id Client.
	 * @return int Ticket ID.
	 */
	private function ticket( $user_id ) {
		$result = BST_Tickets::create(
			array(
				'client_id' => $user_id,
				'subject'   => 'Help',
				'body'      => 'Please',
			)
		);
		return $result['ticket_id'];
	}

	private function company_count() {
		return count( BST_Companies::all() );
	}

	/*
	| Migration
	*/

	public function test_migration_merges_case_and_spacing_variants() {
		$a = $this->client( 'The Ley Arms' );
		$b = $this->client( '  the  ley arms ' );
		$c = $this->client( 'Acme Ltd' );

		$result = BST_Companies::migrate_legacy_names();

		$this->assertSame( 2, $result['created'] );
		$this->assertSame( 3, $result['linked'] );
		$this->assertSame( BST_Companies::for_user( $a ), BST_Companies::for_user( $b ) );
		$this->assertNotSame( BST_Companies::for_user( $a ), BST_Companies::for_user( $c ) );
		$this->assertSame( 'The Ley Arms', BST_Companies::name( BST_Companies::for_user( $a ) ), 'First spelling seen becomes the name.' );
	}

	public function test_migration_backfills_existing_tickets() {
		$user   = $this->client( 'Acme Ltd' );
		$ticket = $this->ticket( $user ); // Created before the migration: no company yet.
		$this->assertSame( 0, BST_Companies::for_ticket( $ticket ) );

		$result = BST_Companies::migrate_legacy_names();

		$this->assertSame( 1, $result['tickets'] );
		$this->assertSame( BST_Companies::for_user( $user ), BST_Companies::for_ticket( $ticket ) );
	}

	public function test_migration_is_safe_to_run_twice() {
		$this->client( 'Acme Ltd' );
		BST_Companies::migrate_legacy_names();
		$second = BST_Companies::migrate_legacy_names();

		$this->assertSame( 1, $this->company_count() );
		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 0, $second['linked'] );
	}

	public function test_migration_reuses_an_existing_company() {
		$existing = BST_Companies::create( 'Acme Ltd' );
		$user     = $this->client( 'ACME LTD' );

		BST_Companies::migrate_legacy_names();

		$this->assertSame( $existing, BST_Companies::for_user( $user ) );
		$this->assertSame( 1, $this->company_count() );
	}

	public function test_migration_skips_pending_sign_ups() {
		$user = $this->client( 'Newco' );
		update_user_meta( $user, BST_Clients::META_PENDING, '1' );

		BST_Companies::migrate_legacy_names();

		$this->assertSame( 0, BST_Companies::for_user( $user ) );
		$this->assertSame( 0, $this->company_count() );
	}

	/*
	| Names
	*/

	public function test_client_name_prefers_company_then_typed_name() {
		$user = $this->client( 'Typed Name' );
		$this->assertSame( 'Typed Name', BST_Clients::client_name( $user ) );

		BST_Companies::set_user_company( $user, BST_Companies::create( 'Real Company' ) );

		$this->assertSame( 'Real Company', BST_Clients::client_name( $user ) );
		$this->assertSame( 'Real Company', bst_get_client_name( $user ) );
	}

	public function test_trashed_company_is_ignored() {
		$user    = $this->client();
		$company = BST_Companies::create( 'Gone Ltd' );
		BST_Companies::set_user_company( $user, $company );
		wp_trash_post( $company );

		$this->assertSame( 0, BST_Companies::for_user( $user ) );
	}

	public function test_suggest_finds_near_matches() {
		$ley = BST_Companies::create( 'The Ley Arms' );
		BST_Companies::create( 'Acme Ltd' );

		$suggestions = wp_list_pluck( BST_Companies::suggest( 'Ley Arms' ), 'ID' );

		$this->assertSame( array( $ley ), $suggestions );
	}

	/*
	| Tickets
	*/

	public function test_new_ticket_is_stamped_with_the_clients_company() {
		$user    = $this->client();
		$company = BST_Companies::create( 'Acme Ltd' );
		BST_Companies::set_user_company( $user, $company );

		$ticket = $this->ticket( $user );

		$this->assertSame( $company, BST_Companies::for_ticket( $ticket ) );
	}

	public function test_ticket_keeps_its_company_when_the_person_moves() {
		$user = $this->client();
		$old  = BST_Companies::create( 'Old Co' );
		BST_Companies::set_user_company( $user, $old );
		$ticket = $this->ticket( $user );

		BST_Companies::set_user_company( $user, BST_Companies::create( 'New Co' ) );

		$this->assertSame( $old, BST_Companies::for_ticket( $ticket ) );
	}

	public function test_linking_a_contact_moves_the_ticket_to_their_company() {
		$result  = BST_Tickets::create(
			array(
				'contact_email' => 'stranger@example.com',
				'subject'       => 'Help',
				'body'          => 'Please',
			)
		);
		$ticket  = $result['ticket_id'];
		$user    = $this->client();
		$company = BST_Companies::create( 'Acme Ltd' );
		BST_Companies::set_user_company( $user, $company );

		BST_Tickets::set_client( $ticket, $user );

		$this->assertSame( $company, BST_Companies::for_ticket( $ticket ) );
		$this->assertFalse( BST_Tickets::is_unverified( $ticket ) );
	}

	public function test_set_company_rejects_non_companies() {
		$ticket = $this->ticket( $this->client() );
		$post   = self::factory()->post->create();

		$this->assertFalse( BST_Tickets::set_company( $ticket, $post ) );
		$this->assertSame( 0, BST_Companies::for_ticket( $ticket ) );
	}

	/*
	| Permissions: unchanged by companies
	*/

	public function test_colleagues_cannot_see_each_others_tickets() {
		$company = BST_Companies::create( 'Acme Ltd' );
		$alice   = $this->client();
		$bob     = $this->client();
		BST_Companies::set_user_company( $alice, $company );
		BST_Companies::set_user_company( $bob, $company );

		$ticket = $this->ticket( $alice );

		$this->assertTrue( BST_Tickets::user_can_view( $ticket, $alice ) );
		$this->assertFalse( BST_Tickets::user_can_view( $ticket, $bob ) );
	}

	/*
	| Websites
	*/

	public function test_websites_are_cleaned_and_deduplicated() {
		$sites = BST_Companies::parse_websites( "leyarms.co.uk\nhttps://LeyArms.co.uk/\n, javascript:alert(1)\n\nhttps://shop.leyarms.co.uk" );

		$this->assertSame( array( 'https://leyarms.co.uk', 'https://shop.leyarms.co.uk' ), $sites );
	}
}
