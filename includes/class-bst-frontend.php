<?php
/**
 * Front end: shortcodes, stylesheet, and keeping client accounts out of
 * wp-admin.
 *
 * Shortcodes are the zero-config fallback. A bespoke theme can call the
 * template functions in functions.php (or override the templates) instead.
 *
 * - [bst_submit_form] Submit a request.
 * - [bst_my_tickets]  The client's tickets; shows one ticket when ?ticket=ID.
 * - [bst_help_centre] Help centre search and topics.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end.
 */
class BST_Frontend {

	const STYLE_HANDLE = 'bst-frontend';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'bst_submit_form', array( __CLASS__, 'shortcode_submit_form' ) );
		add_shortcode( 'bst_my_tickets', array( __CLASS__, 'shortcode_my_tickets' ) );
		add_shortcode( 'bst_help_centre', array( __CLASS__, 'shortcode_help_centre' ) );
		add_shortcode( 'bst_register', array( __CLASS__, 'shortcode_register' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'admin_init', array( __CLASS__, 'keep_clients_out_of_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_for_clients' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/**
	 * Register the stylesheet; enqueue it where support content shows.
	 * Themes that style everything themselves can turn it off:
	 * add_filter( 'bst_load_frontend_css', '__return_false' );
	 */
	public static function enqueue() {
		wp_register_style( self::STYLE_HANDLE, BST_URL . 'assets/frontend/bst-frontend.css', array(), BST_VERSION );

		if ( ! apply_filters( 'bst_load_frontend_css', true ) ) {
			return;
		}

		$post = get_post();
		$uses = $post && (
			has_shortcode( $post->post_content, 'bst_submit_form' )
			|| has_shortcode( $post->post_content, 'bst_my_tickets' )
			|| has_shortcode( $post->post_content, 'bst_help_centre' )
		);

		if ( $uses || is_singular( BST_Post_Types::ARTICLE ) || is_post_type_archive( BST_Post_Types::ARTICLE ) || is_tax( BST_Post_Types::ARTICLE_TOPIC ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * Late fallback for shortcodes rendered outside post content (e.g. a
	 * theme calling do_shortcode() in a template).
	 */
	private static function ensure_style() {
		if ( apply_filters( 'bst_load_frontend_css', true ) && ! wp_style_is( self::STYLE_HANDLE ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, BST_URL . 'assets/frontend/bst-frontend.css', array(), BST_VERSION );
		}
	}

	/**
	 * What logged-out visitors see in place of the portal or form: the
	 * login box, or the register form when the URL has ?bst_register.
	 *
	 * @return string
	 */
	private static function logged_out_view() {
		return BST_Registration::requested() ? BST_Registration::render() : BST_Template::capture( 'login-required.php' );
	}

	/**
	 * [bst_register] — the register form on its own page. Logged-in
	 * visitors get a link to their requests instead.
	 *
	 * @return string
	 */
	public static function shortcode_register() {
		self::ensure_style();

		if ( is_user_logged_in() ) {
			return BST_Template::capture( 'notice.php', array( 'message' => __( 'You are already logged in.', 'bonsai-support-tickets' ) ) );
		}

		return BST_Registration::enabled() ? BST_Registration::render() : BST_Template::capture( 'login-required.php' );
	}

	/**
	 * [bst_submit_form]
	 *
	 * @return string
	 */
	public static function shortcode_submit_form() {
		self::ensure_style();

		if ( ! is_user_logged_in() ) {
			return self::logged_out_view();
		}

		if ( ! BST_Forms::current_user_can_submit() ) {
			return BST_Template::capture( 'notice.php', array( 'message' => __( 'Your account cannot submit support requests. Please contact us directly.', 'bonsai-support-tickets' ) ) );
		}

		$flash = BST_Forms::flash();

		return BST_Template::capture(
			'submit-form.php',
			array(
				'types'      => bst_get_ticket_types(),
				'priorities' => BST_Tickets::priorities(),
				'errors'     => $flash['errors'],
				'input'      => $flash['input'],
				'accept'     => BST_Attachments::accept_attribute(),
				'max_mb'     => (int) BST_Settings::get( 'max_upload_mb' ),
				'max_files'  => (int) BST_Settings::get( 'max_upload_files' ),
			)
		);
	}

	/**
	 * [bst_my_tickets] — list, or one ticket when ?ticket=ID.
	 *
	 * @return string
	 */
	public static function shortcode_my_tickets() {
		self::ensure_style();

		if ( ! is_user_logged_in() ) {
			return self::logged_out_view();
		}

		$ticket_id = isset( $_GET['ticket'] ) ? absint( $_GET['ticket'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, permission checked.

		if ( $ticket_id ) {
			return self::render_single( $ticket_id );
		}

		$user_id = get_current_user_id();

		return BST_Template::capture(
			'my-tickets.php',
			array(
				'active'     => BST_Tickets::client_tickets( $user_id, 'active' ),
				'resolved'   => BST_Tickets::client_tickets( $user_id, 'resolved' ),
				'submit_url' => BST_Tickets::submit_url(),
			)
		);
	}

	/**
	 * One ticket, with its client-visible thread and a reply form.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	private static function render_single( $ticket_id ) {
		// Same message for "doesn't exist" and "not yours", so IDs can't be probed.
		if ( ! BST_Tickets::user_can_view( $ticket_id ) ) {
			return BST_Template::capture( 'notice.php', array( 'message' => __( 'We could not find that request.', 'bonsai-support-tickets' ) ) );
		}

		$messages = BST_Messages::external_for_ticket( $ticket_id );
		$flash    = BST_Forms::flash();

		return BST_Template::capture(
			'single-ticket.php',
			array(
				'ticket'      => get_post( $ticket_id ),
				'ref'         => BST_Tickets::ref( $ticket_id ),
				'status'      => BST_Tickets::status( $ticket_id ),
				'messages'    => $messages,
				'attachments' => BST_Attachments::for_messages( wp_list_pluck( $messages, 'id' ) ),
				'errors'      => $flash['errors'],
				'input'       => $flash['input'],
				'portal_url'  => BST_Tickets::portal_url(),
				'accept'      => BST_Attachments::accept_attribute(),
				'notice'      => isset( $_GET['bst_notice'] ) ? sanitize_key( wp_unslash( $_GET['bst_notice'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			)
		);
	}

	/**
	 * [bst_help_centre]
	 *
	 * @return string
	 */
	public static function shortcode_help_centre() {
		self::ensure_style();

		$topics = get_terms(
			array(
				'taxonomy'   => BST_Post_Types::ARTICLE_TOPIC,
				'hide_empty' => true,
				'parent'     => 0,
			)
		);

		return BST_Template::capture(
			'help-centre.php',
			array(
				'topics'     => is_wp_error( $topics ) ? array() : $topics,
				'submit_url' => BST_Tickets::submit_url(),
			)
		);
	}

	/**
	 * Client-only accounts never see wp-admin. admin-post.php and AJAX
	 * still work (the forms and downloads go through admin-post.php).
	 */
	public static function keep_clients_out_of_admin() {
		if ( wp_doing_ajax() || ! bst_is_client() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		wp_safe_redirect( BST_Tickets::portal_url() );
		exit;
	}

	/**
	 * No admin bar for client-only accounts.
	 *
	 * @param bool $show Show the bar.
	 * @return bool
	 */
	public static function hide_admin_bar_for_clients( $show ) {
		return bst_is_client() ? false : $show;
	}

	/**
	 * Send clients to the portal after logging in, unless they were heading
	 * somewhere specific (e.g. a ticket link in an email).
	 *
	 * @param string           $redirect_to Requested redirect.
	 * @param string           $requested   Raw requested redirect.
	 * @param WP_User|WP_Error $user        User.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && bst_is_client( $user->ID ) && ( '' === $requested || str_contains( $requested, '/wp-admin' ) ) ) {
			return BST_Tickets::portal_url();
		}
		return $redirect_to;
	}
}
