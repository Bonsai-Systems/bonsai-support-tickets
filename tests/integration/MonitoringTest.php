<?php
/**
 * Uptime monitoring: endpoints, auth, tickets, dedupe and notes.
 * Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * Site-down alerts become one ticket per site; recoveries become notes.
 */
class MonitoringTest extends WP_UnitTestCase {

	/**
	 * Shared secret for the status monitor.
	 *
	 * @var string
	 */
	private $secret = 'testsecret1234567890';

	public function set_up() {
		parent::set_up();
		BST_Settings::save(
			array(
				'monitor_enabled'     => 1,
				'monitor_secret'      => $this->secret,
				'uptimerobot_enabled' => 1,
				'uptimerobot_key'     => 'robotkey123',
			)
		);
		// Register the routes for this request.
		do_action( 'rest_api_init', rest_get_server() );
	}

	public function tear_down() {
		delete_option( BST_Settings::OPTION );
		delete_option( BST_Monitoring::LOG_OPTION );
		parent::tear_down();
	}

	/**
	 * Signed status monitor request.
	 *
	 * @param array    $payload Body.
	 * @param int|null $time    Timestamp (default now).
	 * @param string   $secret  Secret to sign with.
	 * @return WP_REST_Response
	 */
	private function status_request( array $payload, $time = null, $secret = null ) {
		$time    = null === $time ? time() : $time;
		$body    = wp_json_encode( $payload );
		$request = new WP_REST_Request( 'POST', '/bst/v1/monitor/status' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Monitor-Timestamp', (string) $time );
		$request->set_header( 'X-Monitor-Signature', 'sha256=' . hash_hmac( 'sha256', $time . '.' . $body, null === $secret ? $this->secret : $secret ) );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/**
	 * Down payload for a site.
	 *
	 * @param string $event Event.
	 * @param string $url   Site URL.
	 * @return array
	 */
	private function payload( $event, $url = 'https://www.leyarms.example/' ) {
		return array(
			'event'            => $event,
			'site'             => array(
				'name' => 'The Ley Arms',
				'url'  => $url,
			),
			'cause'            => 'HTTP 503',
			'duration_seconds' => 750,
			// Real alerts always differ; identical bodies in one second are a replay.
			'occurred_at'      => uniqid( '', true ),
		);
	}

	public function test_down_creates_one_urgent_ticket_for_the_matching_client() {
		$company = self::factory()->post->create(
			array(
				'post_type'  => BST_Post_Types::COMPANY,
				'post_title' => 'The Ley Arms',
			)
		);
		update_post_meta( $company, BST_Companies::META_WEBSITES, array( 'https://leyarms.example' ) );

		$response = $this->status_request( $this->payload( 'down' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'created', $data['result'] );

		$ticket = $data['ticket_id'];
		$this->assertSame( 'urgent', BST_Tickets::priority( $ticket ) );
		$this->assertSame( $company, BST_Companies::for_ticket( $ticket ) );
		$this->assertFalse( BST_Tickets::is_unverified( $ticket ), 'Monitor tickets are not unknown senders.' );
		$this->assertSame( 0, BST_Tickets::client_id( $ticket ) );
		$this->assertStringContainsString( 'Site down: The Ley Arms', get_the_title( $ticket ) );

		// A second down while open adds a note, not a ticket.
		$again = $this->status_request( $this->payload( 'down' ) )->get_data();
		$this->assertSame( 'noted', $again['result'] );
		$this->assertSame( $ticket, $again['ticket_id'] );
	}

	public function test_up_adds_an_internal_note_and_leaves_the_status() {
		$ticket = $this->status_request( $this->payload( 'down' ) )->get_data()['ticket_id'];
		$status = BST_Tickets::status( $ticket );

		$up = $this->status_request( $this->payload( 'up' ) )->get_data();
		$this->assertSame( 'noted', $up['result'] );
		$this->assertSame( $status, BST_Tickets::status( $ticket ) );

		$messages = BST_Messages::for_ticket( $ticket, true );
		$notes    = array_filter(
			$messages,
			function ( $m ) {
				return BST_Messages::INTERNAL === $m->visibility;
			}
		);
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( '12m 30s', reset( $notes )->body );

		// Clients never see the recovery note.
		$this->assertCount( 1, BST_Messages::external_for_ticket( $ticket ) );
	}

	public function test_up_without_an_open_ticket_is_ignored() {
		$data = $this->status_request( $this->payload( 'up' ) )->get_data();
		$this->assertSame( 'ignored', $data['result'] );
	}

	public function test_solved_ticket_means_a_new_down_opens_a_new_ticket() {
		$first = $this->status_request( $this->payload( 'down' ) )->get_data()['ticket_id'];
		BST_Tickets::set_status( $first, 'solved' );

		$second = $this->status_request( $this->payload( 'down' ) )->get_data();
		$this->assertSame( 'created', $second['result'] );
		$this->assertNotSame( $first, $second['ticket_id'] );
	}

	public function test_expiry_opens_a_normal_ticket() {
		$payload              = $this->payload( 'ssl_expiry' );
		$payload['days_left'] = 10;

		$data = $this->status_request( $payload )->get_data();
		$this->assertSame( 'created', $data['result'] );
		$this->assertSame( 'normal', BST_Tickets::priority( $data['ticket_id'] ) );
		$this->assertStringContainsString( 'SSL certificate expiring', get_the_title( $data['ticket_id'] ) );
	}

	public function test_test_event_creates_nothing_but_is_logged() {
		$data = $this->status_request( array( 'event' => 'test' ) )->get_data();
		$this->assertSame( 'test', $data['result'] );
		$this->assertSame( 'test', BST_Monitoring::last_received( 'status' )['event'] );
	}

	public function test_bad_signature_is_rejected() {
		$response = $this->status_request( $this->payload( 'down' ), null, 'wrong-secret' );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_stale_timestamp_is_rejected() {
		$response = $this->status_request( $this->payload( 'down' ), time() - 3600 );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_replayed_request_is_rejected() {
		$time    = time();
		$payload = $this->payload( 'down' );
		$this->assertSame( 200, $this->status_request( $payload, $time )->get_status() );
		$this->assertSame( 401, $this->status_request( $payload, $time )->get_status() );
	}

	public function test_switched_off_source_answers_404() {
		BST_Settings::save( array( 'monitor_enabled' => 0 ) );
		$response = $this->status_request( $this->payload( 'down' ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_uptimerobot_down_and_up() {
		$send = function ( array $params, $key = 'robotkey123' ) {
			$request = new WP_REST_Request( 'POST', '/bst/v1/monitor/uptimerobot' );
			$request->set_query_params( array( 'key' => $key ) );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
			return rest_do_request( $request );
		};

		$down = array(
			'monitorURL'          => 'https://acme.example',
			'monitorFriendlyName' => 'Acme',
			'alertType'           => '1',
			'alertDetails'        => 'Connection Timeout',
			'alertDuration'       => '*alertDuration*', // Unfilled variable.
		);

		$this->assertSame( 401, $send( $down, 'nope' )->get_status() );

		$created = $send( $down )->get_data();
		$this->assertSame( 'created', $created['result'] );
		$this->assertStringContainsString( 'Site down: Acme', get_the_title( $created['ticket_id'] ) );

		$up = $send(
			array(
				'monitorURL'    => 'https://acme.example',
				'alertType'     => '2',
				'alertDuration' => '3725',
			)
		)->get_data();
		$this->assertSame( 'noted', $up['result'] );
		$this->assertSame( $created['ticket_id'], $up['ticket_id'] );
	}

	public function test_sources_do_not_share_tickets() {
		$status = $this->status_request( $this->payload( 'down', 'https://acme.example' ) )->get_data();

		$alert = BST_Monitoring::normalise_uptimerobot(
			array(
				'monitorURL' => 'https://acme.example',
				'alertType'  => '1',
			)
		);
		$robot = BST_Monitoring::process( $alert );

		$this->assertSame( 'created', $robot['result'] );
		$this->assertNotSame( $status['ticket_id'], $robot['ticket_id'] );
	}

	public function test_ambiguous_website_matches_no_client() {
		foreach ( array( 'One', 'Two' ) as $name ) {
			$id = self::factory()->post->create(
				array(
					'post_type'  => BST_Post_Types::COMPANY,
					'post_title' => $name,
				)
			);
			update_post_meta( $id, BST_Companies::META_WEBSITES, array( 'https://shared.example' ) );
		}
		$this->assertSame( 0, BST_Companies::find_by_website( 'https://www.shared.example/page' ) );
	}

	public function test_format_duration() {
		$this->assertSame( '0s', BST_Monitoring::format_duration( 0 ) );
		$this->assertSame( '12m 30s', BST_Monitoring::format_duration( 750 ) );
		$this->assertSame( '1h 2m 5s', BST_Monitoring::format_duration( 3725 ) );
		$this->assertSame( '1d 1h', BST_Monitoring::format_duration( 90000 ) );
	}
}
