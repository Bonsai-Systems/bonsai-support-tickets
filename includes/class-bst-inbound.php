<?php
/**
 * Inbound email: polls the support mailbox and turns each new email into
 * a ticket or a reply.
 *
 * Matching, most trusted first:
 * 1. Reply-to token in a recipient address (inbound+t123-abcdef1234@...) —
 *    the token is secret, so the reply is accepted from any sender.
 * 2. [REF] in the subject, or our Message-ID in References/In-Reply-To —
 *    accepted only if the sender is the ticket's client or an agent.
 *    Anyone else starts a new (unverified) ticket instead.
 * 3. No match: a new ticket. Known client → normal ticket. Unknown sender
 *    → "unverified" ticket that gets no automatic emails until an agent
 *    links it to a client.
 *
 * Auto-replies, bounces, bulk mail and our own outgoing mail are skipped.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inbound processor.
 */
class BST_Inbound {

	const STATUS_OPTION = 'bst_inbound_status';
	const LOCK          = 'bst_inbound_lock';
	const BATCH_SIZE    = 20;
	const FAILED_LABEL  = 'Bonsai Support/Failed';

	/**
	 * Poll the mailbox once. Called by cron and the "Check now" button.
	 *
	 * @return array Status: time, processed, results, error.
	 */
	public static function run() {
		$status = array(
			'time'      => time(),
			'processed' => 0,
			'results'   => array(),
			'error'     => '',
		);

		if ( ! BST_Settings::get( 'imap_enabled' ) ) {
			$status['error'] = __( 'Inbound email is switched off.', 'bonsai-support-tickets' );
			return $status;
		}

		if ( ! BST_Settings::has_imap_credentials() ) {
			$status['error'] = __( 'BST_IMAP_USER and BST_IMAP_PASSWORD are not set in wp-config.php.', 'bonsai-support-tickets' );
			self::save_status( $status );
			return $status;
		}

		// One poll at a time: a slow mailbox must not overlap the next cron run.
		if ( get_transient( self::LOCK ) ) {
			$status['error'] = __( 'A mailbox check is already running.', 'bonsai-support-tickets' );
			return $status;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$client = new BST_Imap_Client( BST_Settings::get( 'imap_host' ), (int) BST_Settings::get( 'imap_port' ) );

		try {
			$client->connect( BST_IMAP_USER, BST_IMAP_PASSWORD );
			$client->select( BST_Settings::get( 'imap_mailbox' ) );

			$uids = array_slice( $client->search_unseen(), 0, self::BATCH_SIZE );

			foreach ( $uids as $uid ) {
				$label = BST_Settings::get( 'imap_processed_tag' );

				try {
					$result = self::process_raw( $client->fetch_raw( $uid ) );
				} catch ( Throwable $e ) {
					// Mark it read anyway so one bad email can't block the queue forever;
					// the Failed label makes it easy to find in Gmail.
					error_log( 'Bonsai Support Tickets: failed processing email UID ' . $uid . ': ' . $e->getMessage() );
					$result = array(
						'result'    => 'failed',
						'ticket_id' => 0,
					);
					$label  = self::FAILED_LABEL;
				}

				$client->mark_seen( $uid );
				$client->add_gmail_label( $uid, $label );

				$status['results'][] = $result['result'];
				++$status['processed'];
			}

			$client->logout();
		} catch ( Throwable $e ) {
			$status['error'] = $e->getMessage();
			error_log( 'Bonsai Support Tickets: mailbox check failed: ' . $e->getMessage() );
		}

		delete_transient( self::LOCK );
		self::save_status( $status );

		return $status;
	}

	/**
	 * Just connect, log in and select the mailbox. For the settings screen.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		if ( ! BST_Settings::has_imap_credentials() ) {
			return new WP_Error( 'bst_no_credentials', __( 'BST_IMAP_USER and BST_IMAP_PASSWORD are not set in wp-config.php.', 'bonsai-support-tickets' ) );
		}

		try {
			$client = new BST_Imap_Client( BST_Settings::get( 'imap_host' ), (int) BST_Settings::get( 'imap_port' ) );
			$client->connect( BST_IMAP_USER, BST_IMAP_PASSWORD );
			$client->select( BST_Settings::get( 'imap_mailbox' ) );
			$client->logout();
		} catch ( Throwable $e ) {
			return new WP_Error( 'bst_imap_failed', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Turn one raw email into a ticket or reply.
	 *
	 * @param string $raw Raw RFC 822 message.
	 * @return array result (created|replied|skipped_auto|skipped_self|duplicate|empty|invalid), ticket_id.
	 */
	public static function process_raw( $raw ) {
		$email = BST_Mime_Parser::parse( $raw );
		$from  = $email['from_email'];

		$skip = function ( $why ) {
			return array(
				'result'    => $why,
				'ticket_id' => 0,
			);
		};

		if ( ! is_email( $from ) ) {
			return $skip( 'invalid' );
		}

		if ( $email['is_auto'] ) {
			return $skip( 'skipped_auto' );
		}

		// Our own outgoing mail coming back round (e.g. a client CC'd the support address).
		$own = array_filter( array( strtolower( (string) BST_Settings::get( 'from_email' ) ), strtolower( (string) BST_Settings::get( 'inbound_address' ) ) ) );
		if ( in_array( $from, $own, true ) ) {
			return $skip( 'skipped_self' );
		}

		// Some clients send no Message-ID; fingerprint the raw message instead.
		$message_id = $email['message_id'] ? $email['message_id'] : '<' . sha1( $raw ) . '@bst.local>';
		if ( BST_Messages::email_exists( $message_id ) ) {
			return $skip( 'duplicate' );
		}

		$sender = get_user_by( 'email', $from );
		// A sign-up awaiting approval hasn't proved it owns the address: treat it as an unknown sender.
		if ( $sender && BST_Clients::is_pending( $sender->ID ) ) {
			$sender = false;
		}
		$sender_id = $sender ? (int) $sender->ID : 0;

		list( $ticket_id, $trusted ) = self::match_ticket( $email );

		// Matched by subject/headers only: the sender must belong on the ticket.
		if ( $ticket_id && ! $trusted ) {
			$trusted = BST_Tickets::is_agent( $sender_id ) || strtolower( BST_Tickets::contact_email( $ticket_id ) ) === $from;
			if ( ! $trusted ) {
				$ticket_id = 0;
			}
		}

		$body_text   = BST_Reply_Parser::extract( $email['text'], $email['html'] );
		$attachments = array_map(
			function ( $a ) {
				return array(
					'filename' => $a['filename'],
					'content'  => $a['content'],
				);
			},
			$email['attachments']
		);

		if ( $ticket_id ) {
			return self::add_reply( $ticket_id, $email, $body_text, $attachments, $sender_id, $message_id );
		}

		return self::create_ticket( $email, $body_text, $attachments, $sender, $message_id );
	}

	/**
	 * Find the ticket an email belongs to.
	 *
	 * @param array $email Parsed email.
	 * @return array array( ticket_id, trusted ).
	 */
	public static function match_ticket( array $email ) {
		// 1. Token in a recipient address.
		foreach ( $email['recipients'] as $address ) {
			if ( preg_match( '/\+t(\d+)-([0-9a-f]{10})@/i', $address, $m ) ) {
				$ticket_id = (int) $m[1];
				if ( BST_Tickets::exists( $ticket_id ) && BST_Tickets::verify_reply_token( $ticket_id, $m[2] ) ) {
					return array( $ticket_id, true );
				}
			}
		}

		// 2a. [REF] in the subject.
		$prefix = preg_quote( BST_Settings::get( 'ref_prefix' ), '/' );
		if ( preg_match( '/\[(' . $prefix . '-\d+)\]/i', $email['subject'], $m ) ) {
			$ticket_id = BST_Tickets::find_by_ref( $m[1] );
			if ( $ticket_id ) {
				return array( $ticket_id, false );
			}
		}

		// 2b. Our Message-IDs in the threading headers.
		$ids = array_merge( array( $email['in_reply_to'] ), $email['references'] );
		foreach ( $ids as $id ) {
			if ( preg_match( '/^<bst\.(?:ticket\.)?(\d+)[.@]/', (string) $id, $m ) && BST_Tickets::exists( (int) $m[1] ) ) {
				return array( (int) $m[1], false );
			}
		}

		return array( 0, false );
	}

	/**
	 * Add an email as a reply.
	 *
	 * @param int    $ticket_id   Ticket ID.
	 * @param array  $email       Parsed email.
	 * @param string $body_text   Extracted reply text.
	 * @param array  $attachments Attachments.
	 * @param int    $sender_id   Sender user ID (0 if unknown).
	 * @param string $message_id  Message-ID for dedupe.
	 * @return array
	 */
	private static function add_reply( $ticket_id, array $email, $body_text, array $attachments, $sender_id, $message_id ) {
		$is_agent   = BST_Tickets::is_agent( $sender_id );
		$visibility = BST_Messages::EXTERNAL;

		// Agents can start an emailed reply with #note to keep it internal.
		if ( $is_agent && preg_match( '/^\s*#note\b\s*/i', $body_text ) ) {
			$visibility = BST_Messages::INTERNAL;
			$body_text  = preg_replace( '/^\s*#note\b\s*/i', '', $body_text );
		}

		// Only link the message to an account that belongs on this ticket.
		$user_id = ( $is_agent || BST_Tickets::client_id( $ticket_id ) === $sender_id ) ? $sender_id : 0;

		$result = BST_Tickets::reply(
			$ticket_id,
			array(
				'user_id'          => $user_id,
				'author_email'     => $email['from_email'],
				'author_name'      => $email['from_name'],
				'visibility'       => $visibility,
				'body'             => BST_Messages::text_to_html( $body_text ),
				'source'           => 'email',
				'email_message_id' => $message_id,
				'raw_attachments'  => $attachments,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'result'    => 'empty',
				'ticket_id' => $ticket_id,
			);
		}

		return array(
			'result'    => 'replied',
			'ticket_id' => $ticket_id,
		);
	}

	/**
	 * Create a ticket from an email.
	 *
	 * @param array        $email       Parsed email.
	 * @param string       $body_text   Extracted text.
	 * @param array        $attachments Attachments.
	 * @param WP_User|bool $sender      Sender account, if any.
	 * @param string       $message_id  Message-ID for dedupe.
	 * @return array
	 */
	private static function create_ticket( array $email, $body_text, array $attachments, $sender, $message_id ) {
		if ( '' === trim( $body_text ) && ! $attachments ) {
			return array(
				'result'    => 'empty',
				'ticket_id' => 0,
			);
		}

		// Only client accounts get a verified ticket. Agents emailing in (e.g.
		// forwarding something) get an unverified one to link to a client.
		$client_id = ( $sender && user_can( $sender, 'bst_submit_ticket' ) && ! BST_Tickets::is_agent( $sender->ID ) ) ? (int) $sender->ID : 0;

		$subject = preg_replace( '/^\s*((re|fw|fwd|aw|wg)\s*:\s*)+/i', '', $email['subject'] );

		$created = BST_Tickets::create(
			array(
				'client_id'        => $client_id,
				'contact_email'    => $email['from_email'],
				'contact_name'     => $email['from_name'],
				'subject'          => $subject,
				'body'             => BST_Messages::text_to_html( $body_text ),
				'source'           => 'email',
				'actor_id'         => $client_id,
				'email_message_id' => $message_id,
				'raw_attachments'  => $attachments,
			)
		);

		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( $created->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- logged, never output.
		}

		return array(
			'result'    => 'created',
			'ticket_id' => $created['ticket_id'],
		);
	}

	/**
	 * Remember the last run for the settings screen.
	 *
	 * @param array $status Run status.
	 */
	private static function save_status( array $status ) {
		$previous = get_option( self::STATUS_OPTION, array() );
		if ( '' === $status['error'] ) {
			$status['last_success'] = $status['time'];
		} else {
			$status['last_success'] = $previous['last_success'] ?? 0;
		}
		update_option( self::STATUS_OPTION, $status, false );
	}
}
