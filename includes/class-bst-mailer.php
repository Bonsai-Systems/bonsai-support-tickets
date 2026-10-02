<?php
/**
 * Outbound email notifications.
 *
 * Listens to the ticket actions fired by BST_Tickets and sends HTML email
 * through wp_mail(), so an SMTP plugin added later is picked up with no
 * changes here.
 *
 * Threading: every email carries [REF] in the subject, a Reply-To with the
 * ticket's secret token (inbound+t{ID}-{token}@domain), and a References
 * header so mail clients group a ticket's emails into one conversation.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mailer.
 */
class BST_Mailer {

	/**
	 * Marker placed at the top of every email. Inbound parsing cuts the
	 * reply off at this line, so quoted history never lands in the ticket.
	 */
	const REPLY_MARKER = '##- Please type your reply above this line -##';

	/**
	 * Tickets that got a client reply email in this request, so a
	 * "solved" email isn't sent straight after it.
	 *
	 * @var array<int,bool>
	 */
	private static $client_emailed = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'bst_ticket_created', array( __CLASS__, 'on_ticket_created' ), 10, 3 );
		add_action( 'bst_message_added', array( __CLASS__, 'on_message_added' ), 10, 3 );
		add_action( 'bst_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'bst_ticket_assigned', array( __CLASS__, 'on_assigned' ), 10, 4 );
	}

	/*
	|----------------------------------------------------------------------
	| Events
	|----------------------------------------------------------------------
	*/

	/**
	 * New ticket (web form or email in): auto-reply to the client, alert the agents.
	 *
	 * @param int $ticket_id  Ticket ID.
	 * @param int $message_id First message.
	 * @param int $actor_id   Creator.
	 */
	public static function on_ticket_created( $ticket_id, $message_id, $actor_id ) {
		$message = BST_Messages::get( $message_id );
		$subject = get_the_title( $ticket_id );

		// Auto-reply, editable under Support → Settings. Only fires here, so
		// replies never trigger it. Never to unverified senders — that would
		// let anyone use us to send mail to any address (backscatter).
		if ( BST_Settings::get( 'autoreply_enabled' ) && ! BST_Tickets::is_unverified( $ticket_id ) ) {
			self::send_to_client(
				$ticket_id,
				self::fill_placeholders( BST_Settings::get( 'autoreply_subject' ), $ticket_id, false ),
				array(
					'body_html' => self::fill_placeholders( BST_Settings::get( 'autoreply_body' ), $ticket_id, true ),
					'message'   => $message,
				)
			);
		}

		$heading = BST_Tickets::is_unverified( $ticket_id )
			? __( 'New ticket from an unknown sender', 'bonsai-support-tickets' )
			: __( 'New ticket', 'bonsai-support-tickets' );

		self::send_to_agents(
			$ticket_id,
			self::agent_recipients( $ticket_id, $actor_id ),
			/* translators: %s: ticket subject. */
			sprintf( __( 'New ticket: %s', 'bonsai-support-tickets' ), $subject ),
			array(
				'heading' => $heading,
				/* translators: 1: client name, 2: ticket type. */
				'intro'   => sprintf( __( 'From %1$s · %2$s', 'bonsai-support-tickets' ), BST_Tickets::contact_name( $ticket_id ), self::type_name( $ticket_id ) ),
				'message' => $message,
			)
		);
	}

	/**
	 * New message: agent reply → client; client reply → assignee/agents;
	 * internal note → assignee.
	 *
	 * @param int  $ticket_id  Ticket ID.
	 * @param int  $message_id Message ID.
	 * @param bool $is_agent   Author is an agent.
	 */
	public static function on_message_added( $ticket_id, $message_id, $is_agent ) {
		$message = BST_Messages::get( $message_id );
		if ( ! $message ) {
			return;
		}
		$subject = get_the_title( $ticket_id );

		if ( BST_Messages::INTERNAL === $message->visibility ) {
			$assignee = BST_Tickets::assignee( $ticket_id );
			if ( $assignee && $assignee !== (int) $message->user_id ) {
				self::send_to_agents(
					$ticket_id,
					array( $assignee ),
					/* translators: %s: ticket subject. */
					sprintf( __( 'Internal note: %s', 'bonsai-support-tickets' ), $subject ),
					array(
						'heading'  => __( 'New internal note', 'bonsai-support-tickets' ),
						/* translators: %s: author. */
						'intro'    => sprintf( __( '%s added a note. The client cannot see this.', 'bonsai-support-tickets' ), $message->author_name ),
						'message'  => $message,
						'internal' => true,
					)
				);
			}
			return;
		}

		if ( $is_agent ) {
			// An agent-created ticket with no client chosen is owned by the agent — don't email them their own reply.
			if ( BST_Tickets::client_id( $ticket_id ) === (int) $message->user_id ) {
				return;
			}
			self::$client_emailed[ $ticket_id ] = true;
			self::send_to_client(
				$ticket_id,
				/* translators: %s: ticket subject. */
				sprintf( __( 'New reply: %s', 'bonsai-support-tickets' ), $subject ),
				array(
					/* translators: %s: agent name. */
					'heading' => sprintf( __( '%s replied to your request', 'bonsai-support-tickets' ), $message->author_name ),
					'intro'   => __( 'Reply to this email, or use the button below, to respond.', 'bonsai-support-tickets' ),
					'message' => $message,
				),
				true // Agents' manual replies go to unverified senders too.
			);
			return;
		}

		self::send_to_agents(
			$ticket_id,
			self::agent_recipients( $ticket_id, (int) $message->user_id ),
			/* translators: %s: ticket subject. */
			sprintf( __( 'Client reply: %s', 'bonsai-support-tickets' ), $subject ),
			array(
				/* translators: %s: client name. */
				'heading' => sprintf( __( '%s replied', 'bonsai-support-tickets' ), $message->author_name ? $message->author_name : $message->author_email ),
				/* translators: %s: status label. */
				'intro'   => sprintf( __( 'Status: %s', 'bonsai-support-tickets' ), BST_Tickets::status_label( BST_Tickets::status( $ticket_id ), true ) ),
				'message' => $message,
			)
		);
	}

	/**
	 * Solved → tell the client (unless they just got the reply that solved it).
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $old       Old status.
	 * @param string $new       New status.
	 * @param int    $actor_id  Who changed it.
	 */
	public static function on_status_changed( $ticket_id, $old, $new, $actor_id ) {
		if ( 'solved' !== $new || ! empty( self::$client_emailed[ $ticket_id ] ) || BST_Tickets::is_unverified( $ticket_id ) ) {
			return;
		}

		// The client marked it solved themselves — no need to tell them.
		if ( (int) $actor_id && (int) $actor_id === BST_Tickets::client_id( $ticket_id ) ) {
			return;
		}

		self::send_to_client(
			$ticket_id,
			/* translators: %s: ticket subject. */
			sprintf( __( 'Solved: %s', 'bonsai-support-tickets' ), get_the_title( $ticket_id ) ),
			array(
				'heading' => __( 'Your request has been solved', 'bonsai-support-tickets' ),
				'intro'   => __( 'We have marked this request as solved. If anything is still not right, just reply to this email and it will reopen.', 'bonsai-support-tickets' ),
			)
		);
	}

	/**
	 * Assigned → tell the new assignee (unless they assigned it to themselves).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $agent_id  New assignee.
	 * @param int $old       Previous assignee.
	 * @param int $actor_id  Who assigned it.
	 */
	public static function on_assigned( $ticket_id, $agent_id, $old, $actor_id ) {
		if ( ! $agent_id || $agent_id === (int) $actor_id ) {
			return;
		}

		$actor = $actor_id ? get_the_author_meta( 'display_name', $actor_id ) : __( 'System', 'bonsai-support-tickets' );

		self::send_to_agents(
			$ticket_id,
			array( $agent_id ),
			/* translators: %s: ticket subject. */
			sprintf( __( 'Assigned to you: %s', 'bonsai-support-tickets' ), get_the_title( $ticket_id ) ),
			array(
				'heading' => __( 'A ticket has been assigned to you', 'bonsai-support-tickets' ),
				/* translators: 1: person, 2: client. */
				'intro'   => sprintf( __( '%1$s assigned you a ticket from %2$s.', 'bonsai-support-tickets' ), $actor, BST_Tickets::contact_name( $ticket_id ) ),
			)
		);
	}

	/*
	|----------------------------------------------------------------------
	| Placeholders
	|----------------------------------------------------------------------
	*/

	/**
	 * Placeholders available in editable email templates, with their values
	 * for a ticket. Pass 0 to get the list for the settings screen.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array<string,string> Placeholder => value.
	 */
	public static function placeholders( $ticket_id = 0 ) {
		$ticket_id = (int) $ticket_id;

		// Raw title, not get_the_title(): that adds curly-quote entities that
		// would show up literally in a subject line.
		$placeholders = array(
			'{{ticket.title}}' => $ticket_id ? (string) get_post_field( 'post_title', $ticket_id, 'raw' ) : '',
			'{{ticket.id}}'    => $ticket_id ? BST_Tickets::ref( $ticket_id ) : '',
			'{{client.name}}'  => $ticket_id ? BST_Tickets::contact_name( $ticket_id ) : '',
		);

		/**
		 * Filter the placeholders available in editable emails.
		 *
		 * @param array<string,string> $placeholders Placeholder => value.
		 * @param int                  $ticket_id    Ticket ID (0 when listing them).
		 */
		return apply_filters( 'bst_email_placeholders', $placeholders, $ticket_id );
	}

	/**
	 * Swap placeholders for a ticket's values.
	 *
	 * @param string $template  Text containing {{placeholders}}.
	 * @param int    $ticket_id Ticket ID.
	 * @param bool   $html      Template is HTML, so escape the values.
	 * @return string
	 */
	public static function fill_placeholders( $template, $ticket_id, $html ) {
		$values = self::placeholders( $ticket_id );
		if ( $html ) {
			$values = array_map( 'esc_html', $values );
		}
		return strtr( (string) $template, $values );
	}

	/*
	|----------------------------------------------------------------------
	| Recipients
	|----------------------------------------------------------------------
	*/

	/**
	 * Agents to notify: the assignee if there is one, else every agent.
	 * Never the person who caused the email.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $exclude   User ID to leave out.
	 * @return int[] User IDs.
	 */
	public static function agent_recipients( $ticket_id, $exclude = 0 ) {
		$assignee = BST_Tickets::assignee( $ticket_id );
		$ids      = $assignee ? array( $assignee ) : wp_list_pluck( BST_Tickets::agents(), 'ID' );
		$ids      = array_values( array_diff( array_map( 'intval', $ids ), array( (int) $exclude ) ) );

		/**
		 * Filter which agents are emailed about a ticket.
		 *
		 * @param int[] $ids       User IDs.
		 * @param int   $ticket_id Ticket ID.
		 */
		return apply_filters( 'bst_agent_notification_recipients', $ids, $ticket_id );
	}

	/*
	|----------------------------------------------------------------------
	| Sending
	|----------------------------------------------------------------------
	*/

	/**
	 * Email the client.
	 *
	 * @param int    $ticket_id         Ticket ID.
	 * @param string $subject           Subject (ref is prepended).
	 * @param array  $content           heading, intro, body_html, message.
	 * @param bool   $allow_unverified  Send even when the ticket is unverified.
	 */
	private static function send_to_client( $ticket_id, $subject, array $content, $allow_unverified = false ) {
		if ( BST_Tickets::is_unverified( $ticket_id ) && ! $allow_unverified ) {
			return;
		}

		$to = BST_Tickets::contact_email( $ticket_id );
		if ( ! is_email( $to ) ) {
			return;
		}

		// Clients without an account (unverified senders) can't log in, so no portal button.
		$has_account = (bool) BST_Tickets::client_id( $ticket_id ) && ! BST_Tickets::is_unverified( $ticket_id );

		$content['button_url']   = $has_account ? BST_Tickets::client_url( $ticket_id ) : '';
		$content['button_label'] = __( 'View your request', 'bonsai-support-tickets' );
		$content['footer']       = __( 'You are receiving this because you raised a support request with us.', 'bonsai-support-tickets' );

		self::send( $to, $subject, $content, $ticket_id );
	}

	/**
	 * Email a set of agents.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param int[]  $user_ids  Agent user IDs.
	 * @param string $subject   Subject (ref is prepended).
	 * @param array  $content   heading, intro, message, internal.
	 */
	private static function send_to_agents( $ticket_id, array $user_ids, $subject, array $content ) {
		$content['button_url']   = BST_Tickets::admin_url( $ticket_id );
		$content['button_label'] = __( 'Open ticket', 'bonsai-support-tickets' );
		$content['footer']       = __( 'Replying to this email sends your reply to the client. Start your reply with #note to add an internal note instead.', 'bonsai-support-tickets' );

		foreach ( array_unique( $user_ids ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				self::send( $user->user_email, $subject, $content, $ticket_id );
			}
		}
	}

	/**
	 * Account email (registration, approval): same layout and From as
	 * ticket emails, but no ticket reference, no Reply-To token and no
	 * threading headers.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param array  $content heading, intro, details, button_url, button_label, footer.
	 * @return bool
	 */
	public static function send_account_email( $to, $subject, array $content ) {
		if ( ! is_email( $to ) ) {
			return false;
		}

		$content = wp_parse_args(
			$content,
			array(
				'heading'      => '',
				'intro'        => '',
				'body_html'    => '',
				'details'      => array(),
				'message'      => null,
				'internal'     => false,
				'button_url'   => '',
				'button_label' => '',
				'footer'       => '',
			)
		);

		$content['ref']          = '';
		$content['subject']      = '';
		$content['reply_marker'] = '';
		$content['logo_url']     = BST_Settings::get( 'email_logo_url' ) ? BST_Settings::get( 'email_logo_url' ) : BST_URL . 'assets/bonsai-avatar.jpg';
		$content['site_name']    = BST_Settings::get( 'from_name' ) ? BST_Settings::get( 'from_name' ) : get_bloginfo( 'name' );

		$html = BST_Template::capture( 'emails/layout.php', $content );
		$text = self::html_to_text( $html );

		$headers    = array( 'Content-Type: text/html; charset=UTF-8', 'Auto-Submitted: auto-generated' );
		$from_email = BST_Settings::get( 'from_email' );
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', self::header_safe( BST_Settings::get( 'from_name' ) ), $from_email );
		}

		$set_mailer = function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $set_mailer );

		/** This filter is documented in includes/class-bst-mailer.php (send()). */
		$email = apply_filters(
			'bst_email',
			array(
				'to'      => $to,
				'subject' => wp_specialchars_decode( $subject, ENT_QUOTES ),
				'html'    => $html,
				'headers' => $headers,
			),
			0
		);

		$sent = wp_mail( $email['to'], $email['subject'], $email['html'], $email['headers'] );

		remove_action( 'phpmailer_init', $set_mailer );

		if ( ! $sent ) {
			error_log( 'Bonsai Support Tickets: wp_mail failed sending account email "' . $subject . '" to ' . $to );
		}
		return $sent;
	}

	/**
	 * Build and send one email.
	 *
	 * @param string $to        Recipient.
	 * @param string $subject   Subject without ref.
	 * @param array  $content   Template content.
	 * @param int    $ticket_id Ticket ID.
	 * @return bool
	 */
	private static function send( $to, $subject, array $content, $ticket_id ) {
		$ref      = BST_Tickets::ref( $ticket_id );
		$reply_to = self::reply_to_address( $ticket_id );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$host     = $host ? $host : 'localhost';

		$content = wp_parse_args(
			$content,
			array(
				'heading'      => '',
				'intro'        => '',
				'body_html'    => '',
				'message'      => null,
				'internal'     => false,
				'button_url'   => '',
				'button_label' => '',
				'footer'       => '',
			)
		);

		$content['ref']          = $ref;
		$content['subject']      = get_the_title( $ticket_id );
		$content['reply_marker'] = $reply_to ? self::REPLY_MARKER : '';
		$content['logo_url']     = BST_Settings::get( 'email_logo_url' ) ? BST_Settings::get( 'email_logo_url' ) : BST_URL . 'assets/bonsai-avatar.jpg';
		$content['site_name']    = BST_Settings::get( 'from_name' ) ? BST_Settings::get( 'from_name' ) : get_bloginfo( 'name' );

		$html = BST_Template::capture( 'emails/layout.php', $content );
		$text = self::html_to_text( $html );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$from_email = BST_Settings::get( 'from_email' );
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', self::header_safe( BST_Settings::get( 'from_name' ) ), $from_email );
		}
		if ( $reply_to ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', self::header_safe( BST_Settings::get( 'from_name' ) ), $reply_to );
		}

		// Thread every email for this ticket together in the recipient's mail client.
		$root      = sprintf( '<bst.ticket.%d@%s>', $ticket_id, $host );
		$headers[] = 'In-Reply-To: ' . $root;
		$headers[] = 'References: ' . $root;
		$headers[] = 'X-BST-Ticket: ' . $ref;
		// Tell auto-responders (out of office etc.) not to reply — RFC 3834.
		$headers[] = 'Auto-Submitted: auto-generated';

		$message_id = sprintf( '<bst.%d.%s@%s>', $ticket_id, bin2hex( random_bytes( 6 ) ), $host );

		$set_mailer = function ( $phpmailer ) use ( $message_id, $text ) {
			$phpmailer->MessageID = $message_id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->AltBody   = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $set_mailer );

		$full_subject = sprintf( '[%s] %s', $ref, wp_specialchars_decode( $subject, ENT_QUOTES ) );

		/**
		 * Filter an outgoing email before it is sent.
		 *
		 * @param array $email     to, subject, html, headers.
		 * @param int   $ticket_id Ticket ID.
		 */
		$email = apply_filters(
			'bst_email',
			array(
				'to'      => $to,
				'subject' => $full_subject,
				'html'    => $html,
				'headers' => $headers,
			),
			$ticket_id
		);

		$sent = wp_mail( $email['to'], $email['subject'], $email['html'], $email['headers'] );

		remove_action( 'phpmailer_init', $set_mailer );

		if ( ! $sent ) {
			error_log( 'Bonsai Support Tickets: wp_mail failed sending "' . $full_subject . '" to ' . $to );
		}
		return $sent;
	}

	/**
	 * Reply-To address for a ticket. With plus addressing on, Gmail delivers
	 * bonsaisupport+t123-abcdef1234@gmail.com to bonsaisupport@gmail.com, and
	 * the inbound processor reads the ticket ID and token back out of it.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string '' if no inbound address is set.
	 */
	public static function reply_to_address( $ticket_id ) {
		$inbound = BST_Settings::get( 'inbound_address' );
		if ( ! is_email( $inbound ) ) {
			return '';
		}
		if ( ! BST_Settings::get( 'plus_addressing' ) ) {
			return $inbound;
		}
		list( $local, $domain ) = explode( '@', $inbound, 2 );
		return sprintf( '%s+t%d-%s@%s', $local, $ticket_id, BST_Tickets::reply_token( $ticket_id ), $domain );
	}

	/**
	 * Plain-text alternative body.
	 *
	 * @param string $html Email HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = preg_replace( '#<(head|style|script)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html );
		$html = preg_replace( '#<li[^>]*>#i', '- ', $html );
		$html = preg_replace( '#<(br|/p|/div|/h[1-6]|/tr|/li)[^>]*>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text );
		return trim( implode( "\n", array_map( 'trim', explode( "\n", $text ) ) ) );
	}

	/**
	 * Strip characters that could inject extra headers.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	private static function header_safe( $value ) {
		return trim( str_replace( array( "\r", "\n", '<', '>', '"' ), '', (string) $value ) );
	}

	/**
	 * Ticket type name for email intros.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	private static function type_name( $ticket_id ) {
		$terms = get_the_terms( $ticket_id, BST_Post_Types::TICKET_TYPE );
		return $terms && ! is_wp_error( $terms ) ? $terms[0]->name : __( 'No type', 'bonsai-support-tickets' );
	}
}
