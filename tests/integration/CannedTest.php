<?php
/**
 * Canned responses: starter set, placeholders, grouping, permissions, saving.
 * Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * Saved replies the team inserts into the reply box.
 */
class CannedTest extends WP_UnitTestCase {

	/**
	 * A canned response.
	 *
	 * @param string $title Title.
	 * @param string $text  Reply text.
	 * @param string $tag   Tag, or '' for none.
	 * @return int
	 */
	private function canned( $title, $text, $tag = '' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'    => BST_Post_Types::CANNED,
				'post_title'   => $title,
				'post_content' => $text,
			)
		);
		if ( '' !== $tag ) {
			wp_set_object_terms( $id, array( $tag ), BST_Post_Types::CANNED_TAG );
		}
		return $id;
	}

	/**
	 * Remove every canned response (the starter set is created on activation).
	 */
	private function clear() {
		foreach ( get_posts( array( 'post_type' => BST_Post_Types::CANNED, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
	}

	public function test_starter_set_is_created_once() {
		$this->clear();
		delete_option( BST_Canned::SEEDED_OPTION );

		BST_Canned::create_defaults();
		$ids = get_posts( array( 'post_type' => BST_Post_Types::CANNED, 'numberposts' => -1, 'fields' => 'ids' ) );
		$this->assertCount( count( BST_Canned::defaults() ), $ids );

		// Deleting one and running again doesn't bring it back or duplicate.
		wp_delete_post( $ids[0], true );
		BST_Canned::create_defaults();
		$this->assertCount( count( BST_Canned::defaults() ) - 1, get_posts( array( 'post_type' => BST_Post_Types::CANNED, 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}

	public function test_starter_set_is_neutral() {
		$haystack = strtolower( wp_json_encode( BST_Canned::defaults() ) );
		$this->assertStringNotContainsString( 'bonsai', $haystack );
	}

	public function test_fill_uses_ticket_and_agent() {
		$client = self::factory()->user->create(
			array(
				'role'         => 'bst_client',
				'display_name' => 'Jane Client',
			)
		);
		$agent  = self::factory()->user->create(
			array(
				'role'         => 'bst_agent',
				'display_name' => 'Sam Agent',
			)
		);
		$ticket = BST_Tickets::create(
			array(
				'client_id' => $client,
				'subject'   => 'Broken form',
				'body'      => 'Help',
			)
		)['ticket_id'];

		$text = BST_Canned::fill( "Hi {{client.name}}, re {{ticket.id}} ({{ticket.title}}).\n{{agent.name}}", $ticket, $agent );

		$this->assertSame( 'Hi Jane Client, re ' . BST_Tickets::ref( $ticket ) . " (Broken form).\nSam Agent", $text );
	}

	public function test_fill_leaves_unknown_values_as_typed() {
		$this->assertSame( 'Hi {{client.name}},', BST_Canned::fill( 'Hi {{client.name}},', 0, 0 ) );
	}

	public function test_fill_does_not_html_escape() {
		$client = self::factory()->user->create(
			array(
				'role'         => 'bst_client',
				'display_name' => 'Smith & Sons',
			)
		);
		$ticket = BST_Tickets::create(
			array(
				'client_id' => $client,
				'subject'   => 'x',
				'body'      => 'x',
			)
		)['ticket_id'];

		$this->assertSame( 'Hi Smith & Sons', BST_Canned::fill( 'Hi {{client.name}}', $ticket, 0 ) );
	}

	public function test_grouped_by_first_tag_with_untagged_last() {
		$this->clear();
		$this->canned( 'Zebra', 'z', 'Billing' );
		$this->canned( 'Apple', 'a', 'Billing' );
		$this->canned( 'Loose', 'l' );
		$this->canned( 'DNS', 'd', 'Hosting' );

		$groups = BST_Canned::grouped( 0, 0 );

		$this->assertSame( array( 'Billing', 'Hosting', '' ), array_keys( $groups ) );
		$this->assertSame( array( 'Apple', 'Zebra' ), wp_list_pluck( $groups['Billing'], 'title' ) );
	}

	public function test_every_agent_can_manage_any_reply_and_clients_cannot() {
		$agent_a = self::factory()->user->create( array( 'role' => 'bst_agent' ) );
		$agent_b = self::factory()->user->create( array( 'role' => 'bst_agent' ) );
		$client  = self::factory()->user->create( array( 'role' => 'bst_client' ) );

		wp_set_current_user( $agent_a );
		$id = $this->canned( 'Mine', 'text' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $agent_a,
			)
		);

		$this->assertTrue( user_can( $agent_b, 'edit_post', $id ), 'Shared library: others can edit.' );
		$this->assertTrue( user_can( $agent_b, 'delete_post', $id ), 'Agents can delete.' );
		$this->assertTrue( user_can( $agent_b, 'edit_bst_canned_responses' ) );
		$this->assertFalse( user_can( $client, 'edit_post', $id ) );
		$this->assertFalse( user_can( $client, 'edit_bst_canned_responses' ) );
	}

	public function test_edit_screen_saves_plain_text() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST[ BST_Admin_Canned::NONCE ] = wp_create_nonce( 'bst_save_canned' );
		$_POST['bst_canned_text']         = wp_slash( "Hi {{client.name}},\n\n<script>x</script>It's done." );

		$id = wp_insert_post(
			array(
				'post_type'   => BST_Post_Types::CANNED,
				'post_status' => 'publish',
				'post_title'  => 'Done',
			)
		);

		unset( $_POST[ BST_Admin_Canned::NONCE ], $_POST['bst_canned_text'] );

		$content = get_post_field( 'post_content', $id, 'raw' );
		$this->assertStringContainsString( "Hi {{client.name}},\n\n", $content );
		$this->assertStringContainsString( "It's done.", $content );
		$this->assertStringNotContainsString( '<script>', $content );
	}

	public function test_picker_renders_filled_replies() {
		$this->clear();
		$admin = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Alex',
			)
		);
		wp_set_current_user( $admin );
		$this->canned( 'Sign off', 'Thanks, {{agent.name}}', 'Closing' );

		$ticket = self::factory()->post->create_and_get( array( 'post_type' => BST_Post_Types::TICKET ) );

		ob_start();
		BST_Admin_Canned::picker( $ticket );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-text="Thanks, Alex"', $html );
		$this->assertStringContainsString( '<optgroup label="Closing">', $html );
	}
}
