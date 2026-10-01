<?php
/**
 * Public template functions for themes.
 *
 * A bespoke theme should use these rather than querying tickets or the
 * custom tables directly — they carry the permission checks.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a plugin template (theme overrides in {theme}/bonsai-support/).
 *
 * @param string $name Template name, e.g. 'my-tickets.php'.
 * @param array  $args Variables for the template.
 */
function bst_get_template( $name, array $args = array() ) {
	BST_Template::render( $name, $args );
}

/**
 * Whether a user is a client-only account (Support Client, no agent or
 * editing rights).
 *
 * @param int|null $user_id Defaults to the current user.
 * @return bool
 */
function bst_is_client( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	return $user_id && user_can( $user_id, 'bst_submit_ticket' ) && ! user_can( $user_id, 'bst_view_all_tickets' ) && ! user_can( $user_id, 'edit_posts' );
}

/**
 * Whether the current (or given) user can see a ticket.
 *
 * @param int      $ticket_id Ticket ID.
 * @param int|null $user_id   Defaults to the current user.
 * @return bool
 */
function bst_user_can_view_ticket( $ticket_id, $user_id = null ) {
	return BST_Tickets::user_can_view( $ticket_id, $user_id );
}

/**
 * A client's tickets.
 *
 * @param int|null $user_id Defaults to the current user.
 * @param string   $state   all|active|resolved.
 * @return WP_Post[]
 */
function bst_get_client_tickets( $user_id = null, $state = 'all' ) {
	return BST_Tickets::client_tickets( null === $user_id ? get_current_user_id() : (int) $user_id, $state );
}

/**
 * Client-visible messages on a ticket. Never includes internal notes.
 * Returns an empty array if the current user can't see the ticket.
 *
 * @param int $ticket_id Ticket ID.
 * @return object[]
 */
function bst_get_ticket_thread( $ticket_id ) {
	if ( ! BST_Tickets::user_can_view( $ticket_id ) ) {
		return array();
	}
	return BST_Messages::external_for_ticket( $ticket_id );
}

/**
 * Attachments for messages, grouped by message ID.
 *
 * @param int[] $message_ids Message IDs.
 * @return array<int,object[]>
 */
function bst_get_message_attachments( array $message_ids ) {
	return BST_Attachments::for_messages( $message_ids );
}

/**
 * Permission-checked download URL for an attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function bst_get_attachment_url( $attachment_id ) {
	return BST_Attachments::url( $attachment_id );
}

/**
 * Ticket reference, e.g. BDC-1042.
 *
 * @param int $ticket_id Ticket ID.
 * @return string
 */
function bst_get_ticket_ref( $ticket_id ) {
	return BST_Tickets::ref( $ticket_id );
}

/**
 * Ticket status slug.
 *
 * @param int $ticket_id Ticket ID.
 * @return string
 */
function bst_get_ticket_status( $ticket_id ) {
	return BST_Tickets::status( $ticket_id );
}

/**
 * Client-facing label for a status slug.
 *
 * @param string $status Status slug.
 * @return string
 */
function bst_get_status_label( $status ) {
	return BST_Tickets::status_label( $status );
}

/**
 * All statuses.
 *
 * @return array
 */
function bst_get_statuses() {
	return BST_Tickets::statuses();
}

/**
 * All priorities.
 *
 * @return array
 */
function bst_get_priorities() {
	return BST_Tickets::priorities();
}

/**
 * Ticket types for the submit form.
 *
 * @return WP_Term[]
 */
function bst_get_ticket_types() {
	$terms = get_terms(
		array(
			'taxonomy'   => BST_Post_Types::TICKET_TYPE,
			'hide_empty' => false,
			'orderby'    => 'term_id',
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Client URL for a ticket.
 *
 * @param int $ticket_id Ticket ID.
 * @return string
 */
function bst_get_ticket_url( $ticket_id ) {
	return BST_Tickets::client_url( $ticket_id );
}

/**
 * Portal ("My requests") URL.
 *
 * @return string
 */
function bst_get_portal_url() {
	return BST_Tickets::portal_url();
}

/**
 * Submit-a-request URL.
 *
 * @return string
 */
function bst_get_submit_url() {
	return BST_Tickets::submit_url();
}

/**
 * Format a stored GMT datetime in the site's timezone and date format.
 *
 * @param string $gmt_datetime Y-m-d H:i:s in GMT.
 * @return string
 */
function bst_format_datetime( $gmt_datetime ) {
	if ( ! $gmt_datetime ) {
		return '';
	}
	$timestamp = strtotime( $gmt_datetime . ' UTC' );
	return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '';
}

/**
 * Human-readable file size.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function bst_format_size( $bytes ) {
	return size_format( (int) $bytes, 0 );
}
