<?php
/**
 * Ticket activity log (status changes, assignments, replies).
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activity log table access.
 */
class BST_Activity {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bst_activity';
	}

	/**
	 * Record an event.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $action    Short action key, e.g. status, assignee, priority, created, reply, note.
	 * @param string $old_value Previous value (slug or ID).
	 * @param string $new_value New value (slug or ID).
	 * @param int    $user_id   Actor. 0 = system (cron, inbound email).
	 */
	public static function log( $ticket_id, $action, $old_value = '', $new_value = '', $user_id = null ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'ticket_id'  => absint( $ticket_id ),
				'user_id'    => null === $user_id ? get_current_user_id() : absint( $user_id ),
				'action'     => sanitize_key( $action ),
				'old_value'  => substr( (string) $old_value, 0, 255 ),
				'new_value'  => substr( (string) $new_value, 0, 255 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * All events for a ticket, newest first.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return object[]
	 */
	public static function for_ticket( $ticket_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id DESC", absint( $ticket_id ) ) );
	}

	/**
	 * Human-readable line for an event.
	 *
	 * @param object $event Row from for_ticket().
	 * @return string Plain text (escape on output).
	 */
	public static function describe( $event ) {
		$actor = $event->user_id ? get_the_author_meta( 'display_name', $event->user_id ) : __( 'System', 'bonsai-support-tickets' );

		// Actions with no user came from an email from someone without an account.
		if ( ! $event->user_id && 'created' === $event->action ) {
			return __( 'Ticket opened by email', 'bonsai-support-tickets' );
		}
		if ( ! $event->user_id && 'reply' === $event->action ) {
			return __( 'Reply received by email', 'bonsai-support-tickets' );
		}

		switch ( $event->action ) {
			case 'created':
				/* translators: %s: person or "System". */
				return sprintf( __( '%s opened the ticket', 'bonsai-support-tickets' ), $actor );

			case 'status':
				/* translators: 1: person, 2: old status, 3: new status. */
				return sprintf( __( '%1$s changed status from %2$s to %3$s', 'bonsai-support-tickets' ), $actor, BST_Tickets::status_label( $event->old_value ), BST_Tickets::status_label( $event->new_value ) );

			case 'priority':
				/* translators: 1: person, 2: old priority, 3: new priority. */
				return sprintf( __( '%1$s changed priority from %2$s to %3$s', 'bonsai-support-tickets' ), $actor, BST_Tickets::priority_label( $event->old_value ), BST_Tickets::priority_label( $event->new_value ) );

			case 'assignee':
				$to = $event->new_value ? get_the_author_meta( 'display_name', (int) $event->new_value ) : __( 'nobody', 'bonsai-support-tickets' );
				/* translators: 1: person, 2: assignee. */
				return sprintf( __( '%1$s assigned the ticket to %2$s', 'bonsai-support-tickets' ), $actor, $to );

			case 'client':
				$to = $event->new_value ? get_the_author_meta( 'display_name', (int) $event->new_value ) : __( 'nobody', 'bonsai-support-tickets' );
				/* translators: 1: person, 2: client. */
				return sprintf( __( '%1$s linked the ticket to %2$s', 'bonsai-support-tickets' ), $actor, $to );

			case 'company':
				$to = $event->new_value ? BST_Companies::name( (int) $event->new_value ) : '';
				$to = '' !== $to ? $to : __( 'no client', 'bonsai-support-tickets' );
				/* translators: 1: person, 2: client (business) name. */
				return sprintf( __( '%1$s moved the ticket to %2$s', 'bonsai-support-tickets' ), $actor, $to );

			case 'reply':
				/* translators: %s: person. */
				return sprintf( __( '%s replied', 'bonsai-support-tickets' ), $actor );

			case 'note':
				/* translators: %s: person. */
				return sprintf( __( '%s added an internal note', 'bonsai-support-tickets' ), $actor );

			case 'email_in':
				/* translators: %s: email address. */
				return sprintf( __( 'Email received from %s', 'bonsai-support-tickets' ), $event->new_value );

			case 'time':
				/* translators: 1: person, 2: duration, e.g. 1h 30m. */
				return sprintf( __( '%1$s logged %2$s', 'bonsai-support-tickets' ), $actor, BST_Duration::format( (int) $event->new_value ) );

			case 'time_edit':
				/* translators: 1: person, 2: old duration, 3: new duration. */
				return sprintf( __( '%1$s edited a time entry (%2$s → %3$s)', 'bonsai-support-tickets' ), $actor, BST_Duration::format( (int) $event->old_value ), BST_Duration::format( (int) $event->new_value ) );

			case 'time_delete':
				/* translators: 1: person, 2: duration. */
				return sprintf( __( '%1$s deleted a time entry (%2$s)', 'bonsai-support-tickets' ), $actor, BST_Duration::format( (int) $event->old_value ) );

			case 'auto_closed':
				return __( 'Closed automatically after being solved', 'bonsai-support-tickets' );

			default:
				return trim( $actor . ' ' . $event->action . ' ' . $event->new_value );
		}
	}
}
