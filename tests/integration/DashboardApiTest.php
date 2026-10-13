<?php
/**
 * Dashboard summary endpoint: access rules, counts and the latest list.
 * Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * GET /bst/v1/dashboard/summary is read-only and agents only.
 */
class DashboardApiTest extends WP_UnitTestCase {

	private $client;
	private $agent;

	public function set_up() {
		parent::set_up();
		$this->client = self::factory()->user->create( array( 'role' => 'bst_client' ) );
		$this->agent  = self::factory()->user->create( array( 'role' => 'bst_agent' ) );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Create a ticket from the client.
	 *
	 * @param string $subject Subject.
	 * @return int
	 */
	private function ticket( $subject ) {
		$created = BST_Tickets::create(
			array(
				'client_id' => $this->client,
				'subject'   => $subject,
				'body'      => '<p>Private message body</p>',
			)
		);
		return $created['ticket_id'];
	}

	/**
	 * Request the summary as the current user.
	 *
	 * @return WP_REST_Response
	 */
	private function request() {
		return rest_do_request( new WP_REST_Request( 'GET', '/bst/v1/dashboard/summary' ) );
	}

	public function test_logged_out_is_rejected() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request()->get_status() );
	}

	public function test_clients_are_forbidden() {
		wp_set_current_user( $this->client );
		$this->assertSame( 403, $this->request()->get_status() );
	}

	public function test_agent_gets_counts_and_latest_without_message_bodies() {
		$open   = $this->ticket( 'Menu broken' );
		$solved = $this->ticket( 'Old issue' );
		BST_Tickets::set_status( $solved, 'solved', $this->agent );
		BST_Tickets::set_assignee( $open, $this->agent );

		wp_set_current_user( $this->agent );
		$response = $this->request();
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 1, $data['active_total'], 'Solved tickets are not counted.' );
		$this->assertSame( 1, $data['mine'] );
		$this->assertSame( 0, $data['unassigned'] );
		$this->assertCount( 1, $data['latest'] );
		$this->assertSame( $open, $data['latest'][0]['id'] );
		$this->assertSame( 'Menu broken', $data['latest'][0]['title'] );
		$this->assertStringNotContainsString( 'Private message body', wp_json_encode( $data ) );
	}

	public function test_post_is_not_allowed() {
		wp_set_current_user( $this->agent );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/bst/v1/dashboard/summary' ) );
		$this->assertSame( 404, $response->get_status() );
	}
}
