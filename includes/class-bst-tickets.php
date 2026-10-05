<?php
/**
 * Ticket domain logic: create, reply, status/priority/assignee changes,
 * references, permissions. Everything that changes a ticket goes through
 * here so activity logging and notifications happen in one place.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tickets service.
 */
class BST_Tickets {

	/**
	 * Meta keys.
	 */
	const META_REF           = '_bst_ref';
	const META_STATUS        = '_bst_status';
	const META_PRIORITY      = '_bst_priority';
	const META_ASSIGNEE      = '_bst_assignee';
	const META_SITE_URL      = '_bst_site_url';
	const META_LAST_ACTIVITY = '_bst_last_activity';
	const META_WAITING_ON    = '_bst_waiting_on';
	const META_SOLVED_AT     = '_bst_solved_at';
	const META_CONTACT_EMAIL = '_bst_contact_email';
	const META_CONTACT_NAME  = '_bst_contact_name';
	const META_UNVERIFIED    = '_bst_unverified';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/*
	|----------------------------------------------------------------------
	| Statuses and priorities
	|----------------------------------------------------------------------
	*/

	/**
	 * Statuses: slug => label + badge variant (success|warning|error|neutral).
	 *
	 * @return array
	 */
	public static function statuses() {
		return apply_filters(
			'bst_statuses',
			array(
				'new'             => array(
					'label'   => __( 'New', 'bonsai-support-tickets' ),
					'variant' => 'error',
				),
				'open'            => array(
					'label'   => __( 'Open', 'bonsai-support-tickets' ),
					'variant' => 'warning',
				),
				'awaiting_client' => array(
					'label'   => __( 'Awaiting your reply', 'bonsai-support-tickets' ),
					'variant' => 'neutral',
				),
				'on_hold'         => array(
					'label'   => __( 'On hold', 'bonsai-support-tickets' ),
					'variant' => 'neutral',
				),
				'solved'          => array(
					'label'   => __( 'Solved', 'bonsai-support-tickets' ),
					'variant' => 'success',
				),
				'closed'          => array(
					'label'   => __( 'Closed', 'bonsai-support-tickets' ),
					'variant' => 'success',
				),
			)
		);
	}

	/**
	 * Statuses that count as "still needs doing".
	 *
	 * @return string[]
	 */
	public static function active_statuses() {
		return apply_filters( 'bst_active_statuses', array( 'new', 'open', 'awaiting_client', 'on_hold' ) );
	}

	/**
	 * Priorities: slug => label + badge variant.
	 *
	 * @return array
	 */
	public static function priorities() {
		return apply_filters(
			'bst_priorities',
			array(
				'low'    => array(
					'label'   => __( 'Low', 'bonsai-support-tickets' ),
					'variant' => 'neutral',
				),
				'normal' => array(
					'label'   => __( 'Normal', 'bonsai-support-tickets' ),
					'variant' => 'neutral',
				),
				'high'   => array(
					'label'   => __( 'High', 'bonsai-support-tickets' ),
					'variant' => 'warning',
				),
				'urgent' => array(
					'label'   => __( 'Urgent', 'bonsai-support-tickets' ),
					'variant' => 'error',
				),
			)
		);
	}

	/**
	 * Status label. Agents see "Awaiting client" rather than the client-facing wording.
	 *
	 * @param string $status   Slug.
	 * @param bool   $for_agent Use agent wording.
	 * @return string
	 */
	public static function status_label( $status, $for_agent = false ) {
		if ( $for_agent && 'awaiting_client' === $status ) {
			return __( 'Awaiting client', 'bonsai-support-tickets' );
		}
		$all = self::statuses();
		return $all[ $status ]['label'] ?? (string) $status;
	}

	/**
	 * Priority label.
	 *
	 * @param string $priority Slug.
	 * @return string
	 */
	public static function priority_label( $priority ) {
		$all = self::priorities();
		return $all[ $priority ]['label'] ?? (string) $priority;
	}

	/*
	|----------------------------------------------------------------------
	| Getters
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether an ID is a ticket.
	 *
	 * @param int $ticket_id Post ID.
	 * @return bool
	 */
	public static function exists( $ticket_id ) {
		return BST_Post_Types::TICKET === get_post_type( $ticket_id );
	}

	/**
	 * Reference, e.g. SUP-1042.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function ref( $ticket_id ) {
		return (string) get_post_meta( $ticket_id, self::META_REF, true );
	}

	/**
	 * Status slug.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function status( $ticket_id ) {
		$status = get_post_meta( $ticket_id, self::META_STATUS, true );
		return array_key_exists( $status, self::statuses() ) ? $status : 'new';
	}

	/**
	 * Priority slug.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function priority( $ticket_id ) {
		$priority = get_post_meta( $ticket_id, self::META_PRIORITY, true );
		return array_key_exists( $priority, self::priorities() ) ? $priority : 'normal';
	}

	/**
	 * Assigned agent user ID (0 = unassigned).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return int
	 */
	public static function assignee( $ticket_id ) {
		return (int) get_post_meta( $ticket_id, self::META_ASSIGNEE, true );
	}

	/**
	 * Client user ID (the post author). 0 for unverified email tickets.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return int
	 */
	public static function client_id( $ticket_id ) {
		return (int) get_post_field( 'post_author', $ticket_id );
	}

	/**
	 * Where replies to the client go: the client's account email, or the
	 * sender address for an unverified email ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function contact_email( $ticket_id ) {
		$client = get_userdata( self::client_id( $ticket_id ) );
		return $client ? $client->user_email : (string) get_post_meta( $ticket_id, self::META_CONTACT_EMAIL, true );
	}

	/**
	 * Client display name.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function contact_name( $ticket_id ) {
		$client = get_userdata( self::client_id( $ticket_id ) );
		if ( $client ) {
			return $client->display_name;
		}
		$name = (string) get_post_meta( $ticket_id, self::META_CONTACT_NAME, true );
		return '' !== $name ? $name : self::contact_email( $ticket_id );
	}

	/**
	 * Whether the ticket came from an unknown email sender and hasn't been
	 * linked to a client yet. No email goes to the sender until it is.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function is_unverified( $ticket_id ) {
		return (bool) get_post_meta( $ticket_id, self::META_UNVERIFIED, true );
	}

	/**
	 * Find a ticket by reference.
	 *
	 * @param string $ref e.g. SUP-1042.
	 * @return int Ticket ID or 0.
	 */
	public static function find_by_ref( $ref ) {
		$ids = get_posts(
			array(
				'post_type'        => BST_Post_Types::TICKET,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => strtoupper( $ref ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Users who can work tickets.
	 *
	 * @return WP_User[]
	 */
	public static function agents() {
		return get_users(
			array(
				'capability' => 'bst_view_all_tickets',
				'orderby'    => 'display_name',
			)
		);
	}

	/**
	 * Whether a user is an agent.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_agent( $user_id ) {
		return $user_id && user_can( $user_id, 'bst_view_all_tickets' );
	}

	/**
	 * Client accounts.
	 *
	 * @return WP_User[]
	 */
	public static function clients() {
		$users = get_users(
			array(
				'role'    => 'bst_client',
				'orderby' => 'display_name',
			)
		);

		// Sort by "Client name — Person" so a business's people sit together.
		usort(
			$users,
			function ( $a, $b ) {
				return strcasecmp( BST_Clients::label( $a ), BST_Clients::label( $b ) );
			}
		);

		return $users;
	}

	/*
	|----------------------------------------------------------------------
	| Permissions
	|----------------------------------------------------------------------
	*/

	/**
	 * Single source of truth for "can this user see this ticket".
	 * Agents see everything; clients see only tickets they own.
	 *
	 * @param int      $ticket_id Ticket ID.
	 * @param int|null $user_id   Defaults to the current user.
	 * @return bool
	 */
	public static function user_can_view( $ticket_id, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		if ( ! $user_id || ! self::exists( $ticket_id ) || 'trash' === get_post_status( $ticket_id ) ) {
			return false;
		}

		if ( self::is_agent( $user_id ) ) {
			return true;
		}

		return self::client_id( $ticket_id ) === $user_id;
	}

	/**
	 * A client's tickets, most recently active first.
	 *
	 * @param int    $user_id Client user ID.
	 * @param string $state   all|active|resolved.
	 * @return WP_Post[]
	 */
	public static function client_tickets( $user_id, $state = 'all' ) {
		if ( ! $user_id ) {
			return array();
		}

		$args = array(
			'post_type'      => BST_Post_Types::TICKET,
			'post_status'    => 'publish',
			'author'         => (int) $user_id,
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'meta_key'       => self::META_LAST_ACTIVITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
		);

		if ( 'active' === $state || 'resolved' === $state ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_STATUS,
					'value'   => self::active_statuses(),
					'compare' => 'active' === $state ? 'IN' : 'NOT IN',
				),
			);
		}

		return get_posts( $args );
	}

	/*
	|----------------------------------------------------------------------
	| References and reply tokens
	|----------------------------------------------------------------------
	*/

	/**
	 * Next reference number.
	 *
	 * Compare-and-swap: the UPDATE only succeeds if the counter still holds
	 * the value we read, so two tickets created at the same moment can't
	 * share a number — the loser re-reads and tries again. Plain SQL, so it
	 * works on MySQL/MariaDB and on SQLite hosts.
	 *
	 * @return int
	 */
	public static function next_ref_number() {
		global $wpdb;

		add_option( 'bst_ref_counter', 1000, '', false );

		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bst_ref_counter' ) );
			$next    = $current + 1;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $next, 'bst_ref_counter', (string) $current ) );

			if ( 1 === (int) $updated ) {
				wp_cache_delete( 'bst_ref_counter', 'options' );
				return $next;
			}

			usleep( wp_rand( 1000, 20000 ) ); // Someone else won; back off briefly and retry.
		}

		// Should never happen. Fall back to something unique rather than fail the ticket.
		error_log( BST_PRODUCT_NAME . ': could not reserve a reference number, using a timestamp.' );
		return (int) gmdate( 'ymdHis' );
	}

	/**
	 * Give a ticket a reference if it doesn't have one yet.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string The reference.
	 */
	public static function ensure_ref( $ticket_id ) {
		$ref = self::ref( $ticket_id );
		if ( '' !== $ref ) {
			return $ref;
		}
		$ref = BST_Settings::get( 'ref_prefix' ) . '-' . self::next_ref_number();
		update_post_meta( $ticket_id, self::META_REF, $ref );
		return $ref;
	}

	/**
	 * Secret token for this ticket's reply-to address. Without it, a reply
	 * is only accepted from the client's or an agent's own address.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string 10 hex characters.
	 */
	public static function reply_token( $ticket_id ) {
		return substr( hash_hmac( 'sha256', 'bst-ticket-' . (int) $ticket_id, wp_salt( 'auth' ) ), 0, 10 );
	}

	/**
	 * Check a reply token.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $token     Token from the address.
	 * @return bool
	 */
	public static function verify_reply_token( $ticket_id, $token ) {
		return hash_equals( self::reply_token( $ticket_id ), strtolower( (string) $token ) );
	}

	/*
	|----------------------------------------------------------------------
	| Create and reply
	|----------------------------------------------------------------------
	*/

	/**
	 * Create a ticket with its first message.
	 *
	 * @param array $args {
	 *     @type int     $client_id        Client user ID (0 for unverified email senders).
	 *     @type string  $contact_email    Sender email when there's no client account.
	 *     @type string  $contact_name     Sender name when there's no client account.
	 *     @type string  $subject          Subject.
	 *     @type string  $body             First message HTML.
	 *     @type int     $type_id          bst_ticket_type term ID.
	 *     @type string  $priority         Priority slug.
	 *     @type string  $site_url         Site/page the issue is on.
	 *     @type string  $source           web|email|admin|monitor.
	 *     @type int     $company_id       Client company. Defaults to the client's company.
	 *     @type int     $actor_id         Who is creating it (client, or agent on their behalf). Defaults to client_id.
	 *     @type string  $email_message_id Inbound Message-ID (dedupe).
	 *     @type array[] $uploads          Normalised $_FILES entries (already validated).
	 *     @type array[] $raw_attachments  Email attachments: each array( 'filename' => '', 'content' => '' ).
	 * }
	 * @return array|WP_Error array( 'ticket_id' => int, 'message_id' => int ).
	 */
	public static function create( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'client_id'        => 0,
				'contact_email'    => '',
				'contact_name'     => '',
				'subject'          => '',
				'body'             => '',
				'type_id'          => 0,
				'priority'         => 'normal',
				'site_url'         => '',
				'source'           => 'web',
				'company_id'       => 0,
				'actor_id'         => null,
				'email_message_id' => '',
				'uploads'          => array(),
				'raw_attachments'  => array(),
			)
		);

		$subject = trim( sanitize_text_field( $args['subject'] ) );
		if ( '' === $subject ) {
			$subject = __( '(no subject)', 'bonsai-support-tickets' );
		}

		$client_id = (int) $args['client_id'];
		$actor_id  = null === $args['actor_id'] ? $client_id : (int) $args['actor_id'];

		$ticket_id = wp_insert_post(
			array(
				'post_type'   => BST_Post_Types::TICKET,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $subject ),
				'post_author' => $client_id,
			),
			true
		);

		if ( is_wp_error( $ticket_id ) ) {
			error_log( BST_PRODUCT_NAME . ': could not create ticket: ' . $ticket_id->get_error_message() );
			return $ticket_id;
		}

		$ref = self::ensure_ref( $ticket_id );

		update_post_meta( $ticket_id, self::META_STATUS, 'new' );
		update_post_meta( $ticket_id, self::META_PRIORITY, array_key_exists( $args['priority'], self::priorities() ) ? $args['priority'] : 'normal' );
		update_post_meta( $ticket_id, self::META_ASSIGNEE, 0 );
		update_post_meta( $ticket_id, self::META_WAITING_ON, 'agent' );
		update_post_meta( $ticket_id, self::META_LAST_ACTIVITY, current_time( 'mysql', true ) );

		if ( $args['site_url'] ) {
			update_post_meta( $ticket_id, self::META_SITE_URL, esc_url_raw( $args['site_url'] ) );
		}

		if ( ! $client_id ) {
			update_post_meta( $ticket_id, self::META_CONTACT_EMAIL, sanitize_email( $args['contact_email'] ) );
			update_post_meta( $ticket_id, self::META_CONTACT_NAME, sanitize_text_field( $args['contact_name'] ) );
			// Uptime alerts have no sender to distrust; they're team-only, not unknown mail.
			if ( 'monitor' !== $args['source'] ) {
				update_post_meta( $ticket_id, self::META_UNVERIFIED, 1 );
			}
		}

		// Record the company now, so it stays right if the person moves on later.
		BST_Companies::stamp_ticket( $ticket_id, $args['company_id'] ? (int) $args['company_id'] : BST_Companies::for_user( $client_id ) );

		if ( $args['type_id'] && term_exists( (int) $args['type_id'], BST_Post_Types::TICKET_TYPE ) ) {
			wp_set_object_terms( $ticket_id, array( (int) $args['type_id'] ), BST_Post_Types::TICKET_TYPE );
		}

		$message_id = BST_Messages::add(
			$ticket_id,
			array(
				'user_id'          => $actor_id,
				'author_email'     => $client_id ? '' : $args['contact_email'],
				'author_name'      => $client_id ? '' : $args['contact_name'],
				'visibility'       => BST_Messages::EXTERNAL,
				'source'           => $args['source'],
				'body'             => $args['body'],
				'email_message_id' => $args['email_message_id'],
			)
		);

		if ( $message_id ) {
			self::store_files( $ticket_id, $message_id, $args['uploads'], $args['raw_attachments'] );
		}

		BST_Activity::log( $ticket_id, 'created', '', $ref, $actor_id );

		/**
		 * A ticket was created.
		 *
		 * @param int $ticket_id  Ticket ID.
		 * @param int $message_id First message ID.
		 * @param int $actor_id   Who created it.
		 */
		do_action( 'bst_ticket_created', $ticket_id, (int) $message_id, $actor_id );

		return array(
			'ticket_id'  => (int) $ticket_id,
			'message_id' => (int) $message_id,
		);
	}

	/**
	 * Add a reply or internal note to a ticket.
	 *
	 * Status moves on automatically unless $args['status'] is given:
	 * - agent reply to the client  → Awaiting client
	 * - client reply               → Open (re-opens solved/closed tickets)
	 * - internal note              → unchanged
	 *
	 * @param int   $ticket_id Ticket ID.
	 * @param array $args {
	 *     @type int     $user_id          Author (0 for unknown email senders).
	 *     @type string  $author_email     For unknown senders.
	 *     @type string  $author_name      For unknown senders.
	 *     @type string  $visibility       external|internal.
	 *     @type string  $body             Message HTML.
	 *     @type string  $source           web|admin|email|monitor.
	 *     @type string  $status           Explicit status to set afterwards.
	 *     @type bool    $system           Written by the plugin (e.g. an uptime alert), not a person: may be an internal note with no user.
	 *     @type string  $email_message_id Inbound Message-ID (dedupe).
	 *     @type array[] $uploads          Normalised, validated $_FILES entries.
	 *     @type array[] $raw_attachments  Email attachments.
	 * }
	 * @return int|WP_Error Message ID.
	 */
	public static function reply( $ticket_id, array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'user_id'          => get_current_user_id(),
				'author_email'     => '',
				'author_name'      => '',
				'visibility'       => BST_Messages::EXTERNAL,
				'body'             => '',
				'source'           => 'web',
				'status'           => '',
				'system'           => false,
				'email_message_id' => '',
				'uploads'          => array(),
				'raw_attachments'  => array(),
			)
		);

		if ( ! self::exists( $ticket_id ) ) {
			return new WP_Error( 'bst_no_ticket', __( 'Ticket not found.', 'bonsai-support-tickets' ) );
		}

		if ( '' === trim( wp_strip_all_tags( $args['body'] ) ) && ! $args['uploads'] && ! $args['raw_attachments'] ) {
			return new WP_Error( 'bst_empty', __( 'Please write a message.', 'bonsai-support-tickets' ) );
		}

		$user_id     = (int) $args['user_id'];
		$is_agent    = self::is_agent( $user_id );
		$is_internal = BST_Messages::INTERNAL === $args['visibility'];

		// Only agents (or the plugin itself) can write internal notes.
		if ( $is_internal && ! $is_agent && ! $args['system'] ) {
			$is_internal = false;
		}

		$message_id = BST_Messages::add(
			$ticket_id,
			array(
				'user_id'          => $user_id,
				'author_email'     => $args['author_email'],
				'author_name'      => $args['author_name'],
				'visibility'       => $is_internal ? BST_Messages::INTERNAL : BST_Messages::EXTERNAL,
				'source'           => $args['source'],
				'body'             => $args['body'],
				'email_message_id' => $args['email_message_id'],
			)
		);

		if ( ! $message_id ) {
			return new WP_Error( 'bst_save_failed', __( 'Your message could not be saved. Please try again.', 'bonsai-support-tickets' ) );
		}

		self::store_files( $ticket_id, $message_id, $args['uploads'], $args['raw_attachments'] );

		BST_Activity::log( $ticket_id, $is_internal ? 'note' : 'reply', '', '', $user_id );

		if ( ! $is_internal ) {
			update_post_meta( $ticket_id, self::META_LAST_ACTIVITY, current_time( 'mysql', true ) );
			update_post_meta( $ticket_id, self::META_WAITING_ON, $is_agent ? 'client' : 'agent' );
		}

		/**
		 * A message was added. Fired before the status change so
		 * notifications can tell "reply + solved" apart from "solved".
		 *
		 * @param int  $ticket_id  Ticket ID.
		 * @param int  $message_id Message ID.
		 * @param bool $is_agent   Whether the author is an agent.
		 */
		do_action( 'bst_message_added', $ticket_id, $message_id, $is_agent );

		// Status.
		$current = self::status( $ticket_id );
		$new     = $args['status'];

		if ( '' === $new && ! $is_internal ) {
			if ( $is_agent ) {
				$new = in_array( $current, array( 'new', 'open' ), true ) ? 'awaiting_client' : '';
			} else {
				$new = 'open' !== $current && 'new' !== $current ? 'open' : '';
			}
		}

		if ( '' !== $new && $new !== $current ) {
			self::set_status( $ticket_id, $new, $user_id );
		}

		return $message_id;
	}

	/**
	 * Save uploads and email attachments for a message.
	 *
	 * @param int     $ticket_id  Ticket ID.
	 * @param int     $message_id Message ID.
	 * @param array[] $uploads    Normalised $_FILES entries.
	 * @param array[] $raw        Email attachments.
	 */
	private static function store_files( $ticket_id, $message_id, array $uploads, array $raw ) {
		if ( $uploads ) {
			BST_Attachments::store_uploads( $uploads, $ticket_id, $message_id );
		}
		foreach ( $raw as $attachment ) {
			BST_Attachments::store_raw( $attachment['filename'], $attachment['content'], $ticket_id, $message_id );
		}
	}

	/*
	|----------------------------------------------------------------------
	| Field changes
	|----------------------------------------------------------------------
	*/

	/**
	 * Change status.
	 *
	 * @param int      $ticket_id Ticket ID.
	 * @param string   $status    New status slug.
	 * @param int|null $actor_id  Who did it (null = current user, 0 = system).
	 * @return bool Whether it changed.
	 */
	public static function set_status( $ticket_id, $status, $actor_id = null ) {
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return false;
		}
		$old = self::status( $ticket_id );
		if ( $old === $status && metadata_exists( 'post', $ticket_id, self::META_STATUS ) ) {
			return false;
		}

		update_post_meta( $ticket_id, self::META_STATUS, $status );

		if ( 'solved' === $status ) {
			update_post_meta( $ticket_id, self::META_SOLVED_AT, current_time( 'mysql', true ) );
		} elseif ( 'closed' !== $status ) {
			delete_post_meta( $ticket_id, self::META_SOLVED_AT );
		}

		$actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
		BST_Activity::log( $ticket_id, 'status', $old, $status, $actor_id );

		/**
		 * Ticket status changed.
		 *
		 * @param int    $ticket_id Ticket ID.
		 * @param string $old       Old status.
		 * @param string $status    New status.
		 * @param int    $actor_id  Who changed it.
		 */
		do_action( 'bst_status_changed', $ticket_id, $old, $status, $actor_id );

		return true;
	}

	/**
	 * Change priority.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $priority  Priority slug.
	 * @return bool
	 */
	public static function set_priority( $ticket_id, $priority ) {
		if ( ! array_key_exists( $priority, self::priorities() ) ) {
			return false;
		}
		$old = self::priority( $ticket_id );
		if ( $old === $priority ) {
			return false;
		}
		update_post_meta( $ticket_id, self::META_PRIORITY, $priority );
		BST_Activity::log( $ticket_id, 'priority', $old, $priority );
		return true;
	}

	/**
	 * Assign to an agent (0 = unassign).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $agent_id  Agent user ID.
	 * @return bool
	 */
	public static function set_assignee( $ticket_id, $agent_id ) {
		$agent_id = (int) $agent_id;
		if ( $agent_id && ! self::is_agent( $agent_id ) ) {
			return false;
		}
		$old = self::assignee( $ticket_id );
		if ( $old === $agent_id ) {
			return false;
		}
		update_post_meta( $ticket_id, self::META_ASSIGNEE, $agent_id );
		BST_Activity::log( $ticket_id, 'assignee', (string) $old, (string) $agent_id );

		/**
		 * Ticket assigned.
		 *
		 * @param int $ticket_id Ticket ID.
		 * @param int $agent_id  New assignee (0 = nobody).
		 * @param int $old       Previous assignee.
		 * @param int $actor_id  Who assigned it.
		 */
		do_action( 'bst_ticket_assigned', $ticket_id, $agent_id, $old, get_current_user_id() );
		return true;
	}

	/**
	 * Link a ticket to a client account. Clears the unverified flag, so the
	 * client starts receiving emails about it, and moves the ticket to the
	 * client's company (if they have one).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $client_id Client user ID.
	 * @return bool
	 */
	public static function set_client( $ticket_id, $client_id ) {
		$client_id = (int) $client_id;
		$old       = self::client_id( $ticket_id );
		if ( ! $client_id || $old === $client_id || ! get_userdata( $client_id ) ) {
			return false;
		}

		// Direct update: wp_update_post() would re-run save_post and our admin save handler.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_author' => $client_id ), array( 'ID' => (int) $ticket_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		clean_post_cache( $ticket_id );

		delete_post_meta( $ticket_id, self::META_UNVERIFIED );
		BST_Activity::log( $ticket_id, 'client', (string) $old, (string) $client_id );

		$company_id = BST_Companies::for_user( $client_id );
		if ( $company_id ) {
			self::set_company( $ticket_id, $company_id );
		}
		return true;
	}

	/**
	 * Set the company a ticket is for (0 clears it). Logged.
	 *
	 * @param int $ticket_id  Ticket ID.
	 * @param int $company_id Company ID or 0.
	 * @return bool Whether it changed.
	 */
	public static function set_company( $ticket_id, $company_id ) {
		$company_id = (int) $company_id;
		$old        = BST_Companies::for_ticket( $ticket_id );
		if ( $old === $company_id || ( $company_id && ! BST_Companies::exists( $company_id ) ) ) {
			return false;
		}

		BST_Companies::stamp_ticket( $ticket_id, $company_id );
		BST_Activity::log( $ticket_id, 'company', (string) $old, (string) $company_id );
		return true;
	}

	/**
	 * Set the site/page URL.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $url       URL.
	 */
	public static function set_site_url( $ticket_id, $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			delete_post_meta( $ticket_id, self::META_SITE_URL );
			return;
		}
		update_post_meta( $ticket_id, self::META_SITE_URL, $url );
	}

	/**
	 * Clean up custom table rows and files when a ticket is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete( $post_id ) {
		if ( ! self::exists( $post_id ) ) {
			return;
		}
		global $wpdb;
		BST_Attachments::delete_for_ticket( $post_id );
		BST_Messages::delete_for_ticket( $post_id );
		$wpdb->delete( BST_Activity::table(), array( 'ticket_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/*
	|----------------------------------------------------------------------
	| URLs
	|----------------------------------------------------------------------
	*/

	/**
	 * Client-facing URL for a ticket (portal page + ?ticket=ID).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function client_url( $ticket_id ) {
		return add_query_arg( 'ticket', (int) $ticket_id, self::portal_url() );
	}

	/**
	 * Portal ("My tickets") URL.
	 *
	 * @return string
	 */
	public static function portal_url() {
		$page = (int) BST_Settings::get( 'portal_page_id' );
		$url  = $page ? get_permalink( $page ) : '';
		return $url ? $url : home_url( '/' );
	}

	/**
	 * Submit-a-request URL.
	 *
	 * @return string
	 */
	public static function submit_url() {
		$page = (int) BST_Settings::get( 'submit_page_id' );
		$url  = $page ? get_permalink( $page ) : '';
		return $url ? $url : self::portal_url();
	}

	/**
	 * Admin URL for a ticket. Built by hand because get_edit_post_link()
	 * returns nothing when there's no logged-in user (cron, inbound email).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	public static function admin_url( $ticket_id ) {
		return admin_url( 'post.php?post=' . (int) $ticket_id . '&action=edit' );
	}
}
