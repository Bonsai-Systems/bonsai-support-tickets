<?php
/**
 * Slack: posts new tickets to one channel through an incoming webhook.
 *
 * The webhook URL lives only in wp-config.php (BST_SLACK_WEBHOOK_URL),
 * like the IMAP credentials. Anyone holding it can post to the channel, so
 * it never goes in the database or a DB export.
 *
 * Only ticket metadata is sent (reference, subject, client, priority, type,
 * source, site). The client's message stays in WordPress; Slack is a third
 * party and the message may hold personal data.
 *
 * Live sends are non-blocking, so a slow or down Slack never holds up
 * ticket creation (web form or the inbound mail cron).
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slack notifications.
 */
class BST_Slack {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'bst_ticket_created', array( __CLASS__, 'on_ticket_created' ), 20, 2 );
	}

	/**
	 * Webhook URL from wp-config.php, filterable for sites that keep secrets
	 * elsewhere. '' when not set or not an https URL.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		$url = defined( 'BST_SLACK_WEBHOOK_URL' ) ? (string) BST_SLACK_WEBHOOK_URL : '';

		/**
		 * Filters the Slack incoming webhook URL.
		 *
		 * @param string $url Webhook URL.
		 */
		$url = (string) apply_filters( 'bst_slack_webhook_url', $url );

		return 0 === strpos( $url, 'https://' ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Whether the webhook is configured.
	 *
	 * @return bool
	 */
	public static function has_webhook() {
		return '' !== self::webhook_url();
	}

	/**
	 * Whether new tickets should be posted.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return self::has_webhook() && (bool) BST_Settings::get( 'slack_enabled' );
	}

	/**
	 * New ticket: post it to the channel.
	 *
	 * @param int $ticket_id  Ticket ID.
	 * @param int $message_id First message ID.
	 */
	public static function on_ticket_created( $ticket_id, $message_id ) {
		if ( ! self::enabled() ) {
			return;
		}

		$message = BST_Messages::get( $message_id );
		$source  = $message && ! empty( $message->source ) ? (string) $message->source : '';

		self::send( self::ticket_payload( $ticket_id, $source ), false );
	}

	/**
	 * Slack message for a new ticket.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $source    web|email|admin.
	 * @return array Webhook payload.
	 */
	public static function ticket_payload( $ticket_id, $source = '' ) {
		$ref        = BST_Tickets::ref( $ticket_id );
		$subject    = get_the_title( $ticket_id );
		$link       = '<' . BST_Tickets::admin_url( $ticket_id ) . '|' . self::escape( $ref ) . '>';
		$unverified = BST_Tickets::is_unverified( $ticket_id );

		$client_id = BST_Tickets::client_id( $ticket_id );
		$client    = $client_id ? BST_Clients::label( $client_id ) : '';
		if ( '' === $client ) {
			$client = BST_Tickets::contact_name( $ticket_id );
			$email  = BST_Tickets::contact_email( $ticket_id );
			$client = $email && $email !== $client ? $client . ' (' . $email . ')' : $client;
		}

		$sources = array(
			'web'   => __( 'Web form', 'bonsai-support-tickets' ),
			'email' => __( 'Email', 'bonsai-support-tickets' ),
			'admin' => __( 'Added by the team', 'bonsai-support-tickets' ),
		);

		$types = get_the_terms( $ticket_id, BST_Post_Types::TICKET_TYPE );

		$fields = array(
			__( 'Client', 'bonsai-support-tickets' )   => $client,
			__( 'Priority', 'bonsai-support-tickets' ) => BST_Tickets::priority_label( BST_Tickets::priority( $ticket_id ) ),
			__( 'Type', 'bonsai-support-tickets' )     => $types && ! is_wp_error( $types ) ? $types[0]->name : '',
			__( 'Source', 'bonsai-support-tickets' )   => $sources[ $source ] ?? '',
			__( 'Site', 'bonsai-support-tickets' )     => (string) get_post_meta( $ticket_id, BST_Tickets::META_SITE_URL, true ),
		);

		$field_blocks = array();
		foreach ( $fields as $label => $value ) {
			if ( '' !== (string) $value ) {
				$field_blocks[] = array(
					'type' => 'mrkdwn',
					'text' => '*' . self::escape( $label ) . "*\n" . self::escape( $value ),
				);
			}
		}

		$heading = $unverified
			? __( 'New ticket from an unknown sender', 'bonsai-support-tickets' )
			: __( 'New ticket', 'bonsai-support-tickets' );

		$blocks = array(
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => '*' . self::escape( $heading ) . '* ' . $link . "\n" . self::escape( $subject ),
				),
			),
		);

		if ( $field_blocks ) {
			$blocks[] = array(
				'type'   => 'section',
				'fields' => $field_blocks,
			);
		}

		if ( $unverified ) {
			$blocks[] = array(
				'type'     => 'context',
				'elements' => array(
					array(
						'type' => 'mrkdwn',
						'text' => self::escape( __( 'Not linked to a client account yet, and no email has gone to the sender. Could be spam.', 'bonsai-support-tickets' ) ),
					),
				),
			);
		}

		$payload = array(
			// Plain-text fallback for notifications and old clients.
			'text'   => $heading . ': ' . $ref . ' ' . $subject,
			'blocks' => $blocks,
		);

		/**
		 * Filters the Slack payload for a new ticket.
		 *
		 * @param array $payload   Webhook payload (text + blocks).
		 * @param int   $ticket_id Ticket ID.
		 */
		return apply_filters( 'bst_slack_ticket_payload', $payload, $ticket_id );
	}

	/**
	 * Post a test message. Blocking, so the settings screen can report the result.
	 *
	 * @return true|WP_Error
	 */
	public static function send_test() {
		return self::send(
			array(
				'text' => sprintf(
					/* translators: %s: site name. */
					__( 'Test from %s support: new tickets will be posted to this channel.', 'bonsai-support-tickets' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
			),
			true
		);
	}

	/**
	 * Post a payload to the webhook.
	 *
	 * @param array $payload  Webhook payload.
	 * @param bool  $blocking Wait for Slack's answer (tests) or fire and forget (live).
	 * @return true|WP_Error Non-blocking sends only report connection errors.
	 */
	public static function send( array $payload, $blocking = false ) {
		$url = self::webhook_url();
		if ( '' === $url ) {
			return new WP_Error( 'bst_slack_no_webhook', __( 'BST_SLACK_WEBHOOK_URL is not set in wp-config.php.', 'bonsai-support-tickets' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => $blocking ? 10 : 3,
				'blocking' => (bool) $blocking,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'Bonsai Support Tickets: Slack post failed: ' . $response->get_error_message() );
			return $response;
		}

		if ( $blocking ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$body = trim( (string) wp_remote_retrieve_body( $response ) );
				error_log( 'Bonsai Support Tickets: Slack returned ' . $code . ': ' . $body );
				return new WP_Error(
					'bst_slack_http',
					/* translators: 1: HTTP status code, 2: Slack's error text, e.g. invalid_token. */
					sprintf( __( 'Slack returned %1$d (%2$s).', 'bonsai-support-tickets' ), $code, '' !== $body ? $body : __( 'no details', 'bonsai-support-tickets' ) )
				);
			}
		}

		return true;
	}

	/**
	 * Escape text for Slack mrkdwn: only &, < and > are special.
	 * See https://api.slack.com/reference/surfaces/formatting#escaping
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function escape( $text ) {
		$text = wp_specialchars_decode( (string) $text, ENT_QUOTES ); // Titles come back HTML-encoded.
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}
