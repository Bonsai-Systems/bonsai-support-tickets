<?php
/**
 * Slack notifications. Needs the WordPress test suite.
 *
 * HTTP is intercepted with pre_http_request, so nothing reaches Slack.
 *
 * @package Support_Desk
 */

/**
 * New tickets post to the webhook; nothing else does.
 */
class SlackTest extends WP_UnitTestCase {

	/**
	 * Captured requests: each array( 'url' => '', 'args' => array() ).
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * HTTP status the fake Slack returns.
	 *
	 * @var int
	 */
	private $status = 200;

	public function set_up() {
		parent::set_up();
		$this->requests = array();
		$this->status   = 200;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_all_filters( 'bst_slack_webhook_url' );
		delete_option( BST_Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Record the request and answer like Slack.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request args.
	 * @param string      $url  URL.
	 * @return array
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(),
			'body'     => 200 === $this->status ? 'ok' : 'invalid_token',
			'response' => array(
				'code'    => $this->status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	private function set_webhook() {
		add_filter(
			'bst_slack_webhook_url',
			function () {
				return 'https://hooks.slack.com/services/T000/B000/XXXX';
			}
		);
	}

	private function create_ticket( $subject = 'Site is down', $body = 'Secret client details here' ) {
		return BST_Tickets::create(
			array(
				'contact_email' => 'stranger@example.com',
				'contact_name'  => 'Stranger',
				'subject'       => $subject,
				'body'          => $body,
				'source'        => 'email',
			)
		);
	}

	public function test_nothing_sent_without_a_webhook() {
		$this->create_ticket();
		$this->assertCount( 0, $this->requests );
		$this->assertFalse( BST_Slack::enabled() );
	}

	public function test_non_https_webhook_is_ignored() {
		add_filter(
			'bst_slack_webhook_url',
			function () {
				return 'http://hooks.slack.com/services/T000/B000/XXXX';
			}
		);
		$this->assertFalse( BST_Slack::has_webhook() );
	}

	public function test_new_ticket_posts_once_without_the_message_body() {
		$this->set_webhook();
		$result = $this->create_ticket();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://hooks.slack.com/services/T000/B000/XXXX', $this->requests[0]['url'] );
		$this->assertFalse( $this->requests[0]['args']['blocking'], 'Live sends must not hold up ticket creation.' );

		$body = $this->requests[0]['args']['body'];
		$this->assertStringContainsString( BST_Tickets::ref( $result['ticket_id'] ), $body );
		$this->assertStringContainsString( 'Site is down', $body );
		$this->assertStringContainsString( 'unknown sender', $body );
		$this->assertStringNotContainsString( 'Secret client details', $body );
	}

	public function test_replies_do_not_post() {
		$this->set_webhook();
		$result = $this->create_ticket();
		$this->requests = array();

		BST_Tickets::reply(
			$result['ticket_id'],
			array(
				'user_id' => 0,
				'body'    => 'Any update?',
			)
		);

		$this->assertCount( 0, $this->requests );
	}

	public function test_switched_off_in_settings() {
		$this->set_webhook();
		BST_Settings::save( array( 'slack_enabled' => '0' ) );

		$this->create_ticket();

		$this->assertCount( 0, $this->requests );
	}

	public function test_text_is_escaped_for_slack() {
		// Stops a client pinging @channel or faking links via the subject or name.
		$this->assertSame( 'Broken &lt;!channel&gt; &amp; links', BST_Slack::escape( 'Broken <!channel> & links' ) );
	}

	public function test_html_encoded_titles_are_not_double_escaped() {
		$this->set_webhook();
		$result  = $this->create_ticket( 'Fish & chips' );
		$payload = BST_Slack::ticket_payload( $result['ticket_id'], 'email' );

		$this->assertStringContainsString( 'Fish &amp; chips', $payload['blocks'][0]['text']['text'] );
		$this->assertStringNotContainsString( '&amp;#038;', $payload['blocks'][0]['text']['text'] );
	}

	public function test_send_test_reports_slack_errors() {
		$this->set_webhook();
		$this->status = 403;

		$result = BST_Slack::send_test();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'invalid_token', $result->get_error_message() );
		$this->assertTrue( $this->requests[0]['args']['blocking'] );
	}

	public function test_send_test_without_webhook_errors() {
		$this->assertWPError( BST_Slack::send_test() );
		$this->assertCount( 0, $this->requests );
	}
}
