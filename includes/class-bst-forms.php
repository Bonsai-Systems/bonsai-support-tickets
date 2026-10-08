<?php
/**
 * Front-end form handlers (admin-post.php): submit a request, client reply.
 *
 * On a validation error the input and messages are kept in a short-lived
 * per-user transient and the user is sent back to the form, which reads
 * them with BST_Forms::flash().
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form handlers.
 */
class BST_Forms {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bst_submit_ticket', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_bst_submit_ticket', array( __CLASS__, 'require_login' ) );
		add_action( 'admin_post_bst_client_reply', array( __CLASS__, 'handle_reply' ) );
		add_action( 'admin_post_nopriv_bst_client_reply', array( __CLASS__, 'require_login' ) );
	}

	/**
	 * Logged-out form posts go to the login screen.
	 */
	public static function require_login() {
		wp_safe_redirect( wp_login_url( self::back_url() ) );
		exit;
	}

	/**
	 * Whether the current user may submit tickets.
	 *
	 * @return bool
	 */
	public static function current_user_can_submit() {
		return is_user_logged_in() && ( current_user_can( 'bst_submit_ticket' ) || current_user_can( 'bst_view_all_tickets' ) );
	}

	/**
	 * New request.
	 */
	public static function handle_submit() {
		try {
			if ( ! isset( $_POST['bst_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bst_nonce'] ) ), 'bst_submit_ticket' ) ) {
				self::fail( array( __( 'Your session expired. Please try again.', 'bonsai-support-tickets' ) ), array() );
			}

			if ( ! self::current_user_can_submit() ) {
				wp_die( esc_html__( 'Your account cannot submit support requests.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
			}

			$input = array(
				'subject'     => sanitize_text_field( wp_unslash( $_POST['bst_subject'] ?? '' ) ),
				'type_id'     => absint( $_POST['bst_type'] ?? 0 ),
				'priority'    => sanitize_key( wp_unslash( $_POST['bst_priority'] ?? 'normal' ) ),
				'site_url'    => esc_url_raw( wp_unslash( $_POST['bst_site_url'] ?? '' ) ),
				'description' => sanitize_textarea_field( wp_unslash( $_POST['bst_description'] ?? '' ) ),
			);

			// Honeypot: real people never see or fill this field.
			if ( ! empty( $_POST['bst_website'] ) ) {
				wp_safe_redirect( self::back_url() );
				exit;
			}

			$errors = array();
			if ( '' === $input['subject'] ) {
				$errors['subject'] = __( 'Please add a subject.', 'bonsai-support-tickets' );
			} elseif ( mb_strlen( $input['subject'] ) > 200 ) {
				$errors['subject'] = __( 'Please keep the subject under 200 characters.', 'bonsai-support-tickets' );
			}
			if ( '' === trim( $input['description'] ) ) {
				$errors['description'] = __( 'Please describe the issue.', 'bonsai-support-tickets' );
			}
			if ( $input['type_id'] && ! term_exists( $input['type_id'], BST_Post_Types::TICKET_TYPE ) ) {
				$errors['type'] = __( 'Please choose a request type.', 'bonsai-support-tickets' );
			}
			if ( ! array_key_exists( $input['priority'], BST_Tickets::priorities() ) ) {
				$input['priority'] = 'normal';
			}

			$uploads = BST_Attachments::normalise_files( $_FILES['bst_attachments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in validate_uploads().
			$check   = BST_Attachments::validate_uploads( $uploads );
			if ( is_wp_error( $check ) ) {
				$errors['attachments'] = $check->get_error_message();
			}

			if ( $errors ) {
				self::fail( $errors, $input );
			}

			$created = BST_Tickets::create(
				array(
					'client_id' => get_current_user_id(),
					'subject'   => $input['subject'],
					'body'      => BST_Messages::text_to_html( $input['description'] ),
					'type_id'   => $input['type_id'],
					'priority'  => $input['priority'],
					'site_url'  => $input['site_url'],
					'source'    => 'web',
					'uploads'   => $uploads,
				)
			);

			if ( is_wp_error( $created ) ) {
				self::fail( array( __( 'Sorry, we could not save your request. Please try again, or email us directly.', 'bonsai-support-tickets' ) ), $input );
			}

			wp_safe_redirect( add_query_arg( 'bst_notice', 'created', BST_Tickets::client_url( $created['ticket_id'] ) ) );
			exit;
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': submit failed: ' . $e->getMessage() );
			self::fail( array( __( 'Sorry, something went wrong. Please try again, or email us directly.', 'bonsai-support-tickets' ) ), array() );
		}
	}

	/**
	 * Client reply (and optional "mark as solved").
	 */
	public static function handle_reply() {
		$ticket_id = absint( $_POST['bst_ticket'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below, nonce is per ticket.

		try {
			if ( ! isset( $_POST['bst_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bst_nonce'] ) ), 'bst_client_reply_' . $ticket_id ) ) {
				self::fail( array( __( 'Your session expired. Please try again.', 'bonsai-support-tickets' ) ), array() );
			}

			if ( ! BST_Tickets::user_can_view( $ticket_id ) ) {
				wp_die( esc_html__( 'You do not have permission to reply to this request.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
			}

			$body        = sanitize_textarea_field( wp_unslash( $_POST['bst_message'] ?? '' ) );
			$mark_solved = ! empty( $_POST['bst_mark_solved'] );
			$uploads     = BST_Attachments::normalise_files( $_FILES['bst_attachments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in validate_uploads().

			$check = BST_Attachments::validate_uploads( $uploads );
			if ( is_wp_error( $check ) ) {
				self::fail( array( 'attachments' => $check->get_error_message() ), array( 'message' => $body ) );
			}

			$has_message = '' !== trim( $body ) || $uploads;

			if ( ! $has_message && ! $mark_solved ) {
				self::fail( array( 'message' => __( 'Please write a message.', 'bonsai-support-tickets' ) ), array() );
			}

			if ( $has_message ) {
				$result = BST_Tickets::reply(
					$ticket_id,
					array(
						'user_id'    => get_current_user_id(),
						'visibility' => BST_Messages::EXTERNAL,
						'body'       => BST_Messages::text_to_html( $body ),
						'source'     => 'web',
						'status'     => $mark_solved ? 'solved' : '',
						'uploads'    => $uploads,
					)
				);
				if ( is_wp_error( $result ) ) {
					self::fail( array( 'message' => $result->get_error_message() ), array( 'message' => $body ) );
				}
			} elseif ( $mark_solved ) {
				BST_Tickets::set_status( $ticket_id, 'solved' );
			}

			$notice = $mark_solved ? 'solved' : 'replied';
			wp_safe_redirect( add_query_arg( 'bst_notice', $notice, BST_Tickets::client_url( $ticket_id ) ) . '#bst-latest' );
			exit;
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': client reply failed: ' . $e->getMessage() );
			self::fail( array( __( 'Sorry, something went wrong. Please try again.', 'bonsai-support-tickets' ) ), array() );
		}
	}

	/**
	 * Keep errors and input for the next page load, then go back to the form.
	 *
	 * @param array $errors Field => message (or numeric keys for general errors).
	 * @param array $input  Submitted values to refill the form.
	 */
	private static function fail( array $errors, array $input ) {
		if ( is_user_logged_in() ) {
			set_transient(
				'bst_flash_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'input'  => $input,
				),
				5 * MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( add_query_arg( 'bst_error', '1', self::back_url() ) );
		exit;
	}

	/**
	 * Read (and clear) errors and input from a failed submission.
	 *
	 * @return array array( 'errors' => array, 'input' => array ).
	 */
	public static function flash() {
		static $flash = null;
		if ( null !== $flash ) {
			return $flash;
		}

		$flash = array(
			'errors' => array(),
			'input'  => array(),
		);

		if ( ! is_user_logged_in() || empty( $_GET['bst_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return $flash;
		}

		$key    = 'bst_flash_' . get_current_user_id();
		$stored = get_transient( $key );
		if ( is_array( $stored ) ) {
			$flash = wp_parse_args( $stored, $flash );
			delete_transient( $key );
		}
		return $flash;
	}

	/**
	 * Where to send the user back to: the page the form was on.
	 *
	 * @return string
	 */
	private static function back_url() {
		$referer = wp_get_referer();
		$url     = $referer ? wp_validate_redirect( $referer, BST_Tickets::portal_url() ) : BST_Tickets::portal_url();
		return remove_query_arg( array( 'bst_error', 'bst_notice' ), $url );
	}
}
