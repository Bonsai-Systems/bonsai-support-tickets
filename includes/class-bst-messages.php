<?php
/**
 * Ticket messages: external replies (visible to the client, emailed) and
 * internal notes (agents only, never emailed to the client).
 *
 * Front-end code must only ever call external_for_ticket(). The one place
 * that can return internal notes is for_ticket( $id, true ), and that is
 * only used by the admin screen behind a capability check.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Messages table access.
 */
class BST_Messages {

	const EXTERNAL = 'external';
	const INTERNAL = 'internal';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bst_messages';
	}

	/**
	 * Tags allowed in a message body. Deliberately small: messages arrive
	 * from email and the front end, so no images, iframes, styles or scripts.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		return apply_filters(
			'bst_message_allowed_html',
			array(
				'p'          => array(),
				'br'         => array(),
				'strong'     => array(),
				'b'          => array(),
				'em'         => array(),
				'i'          => array(),
				'u'          => array(),
				'a'          => array( 'href' => true ),
				'ul'         => array(),
				'ol'         => array(),
				'li'         => array(),
				'blockquote' => array(),
				'code'       => array(),
				'pre'        => array(),
			)
		);
	}

	/**
	 * Turn plain text (form textarea or email) into safe message HTML.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function text_to_html( $text ) {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		return wp_kses( make_clickable( wpautop( esc_html( $text ) ) ), self::allowed_html() );
	}

	/**
	 * Insert a message.
	 *
	 * @param int   $ticket_id Ticket ID.
	 * @param array $args {
	 *     @type int    $user_id          Author user ID, 0 for unknown email senders.
	 *     @type string $author_name      Display name (falls back to the user's).
	 *     @type string $author_email     Email (falls back to the user's).
	 *     @type string $visibility       external|internal.
	 *     @type string $source           web|admin|email|system.
	 *     @type string $body             Message HTML (filtered through allowed_html()).
	 *     @type string $email_message_id Message-ID header for inbound emails (dedupe).
	 * }
	 * @return int|false Message ID, or false on failure.
	 */
	public static function add( $ticket_id, array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'user_id'          => 0,
				'author_name'      => '',
				'author_email'     => '',
				'visibility'       => self::EXTERNAL,
				'source'           => 'web',
				'body'             => '',
				'email_message_id' => '',
			)
		);

		$user = $args['user_id'] ? get_userdata( $args['user_id'] ) : false;
		if ( $user ) {
			$args['author_name']  = $args['author_name'] ? $args['author_name'] : $user->display_name;
			$args['author_email'] = $args['author_email'] ? $args['author_email'] : $user->user_email;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'ticket_id'        => absint( $ticket_id ),
				'user_id'          => absint( $args['user_id'] ),
				'author_name'      => substr( sanitize_text_field( $args['author_name'] ), 0, 190 ),
				'author_email'     => substr( sanitize_email( $args['author_email'] ), 0, 190 ),
				'visibility'       => self::INTERNAL === $args['visibility'] ? self::INTERNAL : self::EXTERNAL,
				'source'           => sanitize_key( $args['source'] ),
				'body'             => wp_kses( $args['body'], self::allowed_html() ),
				'email_message_id' => substr( (string) $args['email_message_id'], 0, 255 ),
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			error_log( BST_PRODUCT_NAME . ': failed to insert message for ticket ' . $ticket_id . ': ' . $wpdb->last_error );
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One message.
	 *
	 * @param int $message_id Message ID.
	 * @return object|null
	 */
	public static function get( $message_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $message_id ) ) );
	}

	/**
	 * Messages for a ticket, oldest first.
	 *
	 * @param int  $ticket_id        Ticket ID.
	 * @param bool $include_internal Include internal notes. Admin screen only.
	 * @return object[]
	 */
	public static function for_ticket( $ticket_id, $include_internal = false ) {
		global $wpdb;
		$table = self::table();

		if ( $include_internal ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id ASC", absint( $ticket_id ) ) );
		}

		return self::external_for_ticket( $ticket_id );
	}

	/**
	 * Client-visible messages only. The visibility filter is in the SQL so
	 * no template can accidentally show an internal note.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return object[]
	 */
	public static function external_for_ticket( $ticket_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d AND visibility = %s ORDER BY id ASC", absint( $ticket_id ), self::EXTERNAL ) );
	}

	/**
	 * Whether an inbound email has already been imported.
	 *
	 * @param string $email_message_id Message-ID header.
	 * @return bool
	 */
	public static function email_exists( $email_message_id ) {
		global $wpdb;
		if ( '' === $email_message_id ) {
			return false;
		}
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_message_id = %s LIMIT 1", substr( $email_message_id, 0, 255 ) ) );
	}

	/**
	 * Delete all messages for a ticket (called when a ticket is permanently deleted).
	 *
	 * @param int $ticket_id Ticket ID.
	 */
	public static function delete_for_ticket( $ticket_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'ticket_id' => absint( $ticket_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
