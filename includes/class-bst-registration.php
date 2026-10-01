<?php
/**
 * Client self-registration.
 *
 * Flow: register form → account created as a Support Client marked
 * pending (BST_Clients::META_PENDING) → applicant gets a "thanks" email,
 * the team gets a "new sign-up" email → an agent approves under
 * Support → Sign-ups → the client is emailed a set-password link.
 *
 * Pending accounts can't log in or reset their password, so the
 * set-password link is what proves they own the email address.
 *
 * The form shows wherever the login box does (portal and submit pages)
 * when the URL has ?bst_register=1, and via [bst_register].
 *
 * Spam: nonce, honeypot, minimum fill time (signed timestamp), and a
 * per-IP limit. No CAPTCHA, so nothing loads from third parties.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration.
 */
class BST_Registration {

	/**
	 * Query arg that switches the login box to the register form.
	 */
	const QUERY_ARG = 'bst_register';

	/**
	 * Fastest a human fills the form, in seconds.
	 */
	const MIN_SECONDS = 3;

	/**
	 * Registrations allowed per IP per hour.
	 */
	const RATE_LIMIT = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_nopriv_bst_register', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_bst_register', array( __CLASS__, 'already_logged_in' ) );
	}

	/**
	 * Whether registration is switched on in Support → Settings.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) BST_Settings::get( 'registration_enabled' );
	}

	/**
	 * URL of the register form: the My requests page with ?bst_register=1.
	 *
	 * @param string $base Page to show it on (defaults to the portal).
	 * @return string
	 */
	public static function url( $base = '' ) {
		$base = $base ? $base : BST_Tickets::portal_url();
		return add_query_arg( self::QUERY_ARG, '1', remove_query_arg( array( 'bst_error', 'bst_flash' ), $base ) );
	}

	/**
	 * Whether the current request asks for the register form.
	 *
	 * @return bool
	 */
	public static function requested() {
		return self::enabled() && ! empty( $_GET[ self::QUERY_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/**
	 * Render the form (or the "thanks" message after a successful sign-up).
	 *
	 * @return string
	 */
	public static function render() {
		if ( is_user_logged_in() ) {
			return '';
		}

		if ( ! self::enabled() ) {
			return BST_Template::capture( 'login-required.php' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$state = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		$flash = self::read_flash();

		return BST_Template::capture(
			'register.php',
			array(
				'done'        => 'done' === $state,
				'errors'      => $flash['errors'],
				'input'       => $flash['input'],
				'timestamp'   => self::timestamp_token(),
				'login_url'   => remove_query_arg( array( self::QUERY_ARG, 'bst_error', 'bst_flash' ) ),
				'privacy_url' => get_privacy_policy_url(),
			)
		);
	}

	/**
	 * Posting the form while logged in: just go to the portal.
	 */
	public static function already_logged_in() {
		wp_safe_redirect( BST_Tickets::portal_url() );
		exit;
	}

	/**
	 * Handle the form.
	 */
	public static function handle() {
		$input = array();

		try {
			if ( ! self::enabled() ) {
				wp_safe_redirect( BST_Tickets::portal_url() );
				exit;
			}

			if ( ! isset( $_POST['bst_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bst_nonce'] ) ), 'bst_register' ) ) {
				self::fail( array( __( 'The form expired. Please try again.', 'bonsai-support-tickets' ) ), array() );
			}

			$input = array(
				'client_name' => sanitize_text_field( wp_unslash( $_POST['bst_client_name'] ?? '' ) ),
				'first_name'  => sanitize_text_field( wp_unslash( $_POST['bst_first_name'] ?? '' ) ),
				'last_name'   => sanitize_text_field( wp_unslash( $_POST['bst_last_name'] ?? '' ) ),
				'email'       => sanitize_email( wp_unslash( $_POST['bst_email'] ?? '' ) ),
				'website'     => trim( sanitize_text_field( wp_unslash( $_POST['bst_site'] ?? '' ) ) ),
				'phone'       => sanitize_text_field( wp_unslash( $_POST['bst_phone'] ?? '' ) ),
			);

			// Bots: honeypot filled or form sent faster than a person could. Pretend it worked.
			$ts = sanitize_text_field( wp_unslash( $_POST['bst_ts'] ?? '' ) );
			if ( ! empty( $_POST['bst_hp'] ) || ! self::timestamp_ok( $ts ) ) {
				self::done();
			}

			if ( ! self::within_rate_limit() ) {
				self::fail( array( __( 'Too many sign-ups from your connection. Please try again in an hour, or email us.', 'bonsai-support-tickets' ) ), $input );
			}

			$errors = self::validate( $input );
			if ( $errors ) {
				self::fail( $errors, $input );
			}

			$user_id = self::create_account( $input );
			if ( is_wp_error( $user_id ) ) {
				error_log( 'Bonsai Support Tickets: registration failed: ' . $user_id->get_error_message() );
				self::fail( array( __( 'Sorry, we could not create your account. Please try again, or email us.', 'bonsai-support-tickets' ) ), $input );
			}

			self::count_attempt();

			/**
			 * A client registered and is awaiting approval.
			 *
			 * @param int $user_id New user ID.
			 */
			do_action( 'bst_client_registered', $user_id );

			self::email_applicant( $user_id );
			self::email_team( $user_id );

			self::done();
		} catch ( Throwable $e ) {
			error_log( 'Bonsai Support Tickets: registration exception: ' . $e->getMessage() );
			self::fail( array( __( 'Sorry, something went wrong. Please try again, or email us.', 'bonsai-support-tickets' ) ), $input );
		}
	}

	/**
	 * Validate the form.
	 *
	 * @param array $input Sanitised input (website is normalised in place).
	 * @return array Field => message.
	 */
	public static function validate( array &$input ) {
		$errors = array();

		if ( '' === $input['client_name'] ) {
			$errors['client_name'] = __( 'Please add your business or client name.', 'bonsai-support-tickets' );
		} elseif ( mb_strlen( $input['client_name'] ) > 100 ) {
			$errors['client_name'] = __( 'Please keep this under 100 characters.', 'bonsai-support-tickets' );
		}

		if ( '' === $input['first_name'] ) {
			$errors['first_name'] = __( 'Please add your first name.', 'bonsai-support-tickets' );
		} elseif ( mb_strlen( $input['first_name'] ) > 50 ) {
			$errors['first_name'] = __( 'Please keep this under 50 characters.', 'bonsai-support-tickets' );
		}

		if ( '' === $input['last_name'] ) {
			$errors['last_name'] = __( 'Please add your last name.', 'bonsai-support-tickets' );
		} elseif ( mb_strlen( $input['last_name'] ) > 50 ) {
			$errors['last_name'] = __( 'Please keep this under 50 characters.', 'bonsai-support-tickets' );
		}

		if ( ! is_email( $input['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'bonsai-support-tickets' );
		} elseif ( email_exists( $input['email'] ) ) {
			$errors['email'] = __( 'There\'s already an account with this email address. Log in, or use "Forgotten your password?" on the log-in form.', 'bonsai-support-tickets' );
		}

		if ( '' !== $input['website'] ) {
			// Accept "example.co.uk" as well as a full URL.
			if ( ! preg_match( '#^https?://#i', $input['website'] ) ) {
				$input['website'] = 'https://' . $input['website'];
			}
			$url = esc_url_raw( $input['website'], array( 'http', 'https' ) );
			if ( ! $url || ! wp_parse_url( $url, PHP_URL_HOST ) || ! str_contains( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) ) {
				$errors['website'] = __( 'Please enter a website address like example.co.uk.', 'bonsai-support-tickets' );
			} else {
				$input['website'] = $url;
			}
		}

		if ( '' !== $input['phone'] && ! preg_match( '/^[0-9 +()\-.]{6,30}$/', $input['phone'] ) ) {
			$errors['phone'] = __( 'Please enter a valid phone number.', 'bonsai-support-tickets' );
		}

		return $errors;
	}

	/**
	 * Create the pending client account.
	 *
	 * @param array $input Validated input.
	 * @return int|WP_Error User ID.
	 */
	public static function create_account( array $input ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login( $input['email'] ),
				'user_email'   => $input['email'],
				// Random and never sent: the client sets their own after approval.
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $input['first_name'],
				'last_name'    => $input['last_name'],
				'display_name' => trim( $input['first_name'] . ' ' . $input['last_name'] ),
				'user_url'     => $input['website'],
				'role'         => 'bst_client',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, BST_Clients::META_CLIENT_NAME, $input['client_name'] );
		update_user_meta( $user_id, BST_Clients::META_PHONE, $input['phone'] );
		update_user_meta( $user_id, BST_Clients::META_PENDING, '1' );
		update_user_meta( $user_id, BST_Clients::META_REGISTERED, 'form' );

		return (int) $user_id;
	}

	/**
	 * Login name that doesn't reveal the email address (it ends up in
	 * user_nicename). People log in with their email address anyway.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function unique_login( $email ) {
		$base = sanitize_user( strstr( $email, '@', true ), true );
		$base = $base ? substr( $base, 0, 40 ) : 'client';
		do {
			$login = 'client-' . $base . '-' . strtolower( wp_generate_password( 4, false, false ) );
		} while ( username_exists( $login ) );
		return $login;
	}

	/*
	|----------------------------------------------------------------------
	| Approval
	|----------------------------------------------------------------------
	*/

	/**
	 * Approve a pending account and send the set-password email.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public static function approve( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! BST_Clients::is_pending( $user_id ) ) {
			return new WP_Error( 'bst_not_pending', __( 'That account is not awaiting approval.', 'bonsai-support-tickets' ) );
		}

		delete_user_meta( $user_id, BST_Clients::META_PENDING );

		// Needs the pending flag gone first: password resets are blocked while pending.
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			update_user_meta( $user_id, BST_Clients::META_PENDING, '1' );
			return $key;
		}

		$url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );

		BST_Mailer::send_account_email(
			$user->user_email,
			__( 'Your support account is ready', 'bonsai-support-tickets' ),
			array(
				'heading'      => sprintf(
					/* translators: %s: first name. */
					__( 'Welcome, %s', 'bonsai-support-tickets' ),
					$user->first_name ? $user->first_name : $user->display_name
				),
				'intro'        => __( 'Your support account has been approved. Choose a password to log in, then you can raise requests and follow their progress. The link works for 24 hours; after that, use "Forgotten your password?" on the log-in form.', 'bonsai-support-tickets' ),
				'button_url'   => $url,
				'button_label' => __( 'Set your password', 'bonsai-support-tickets' ),
				'footer'       => __( 'You are receiving this because you registered for a support account with us.', 'bonsai-support-tickets' ),
			)
		);

		/**
		 * A pending client account was approved.
		 *
		 * @param int $user_id User ID.
		 * @param int $by      Approving user ID.
		 */
		do_action( 'bst_client_approved', $user_id, get_current_user_id() );

		return true;
	}

	/**
	 * Reject (delete) a pending account. Only ever deletes pending
	 * client-only accounts with no tickets, so it can't remove a real user.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public static function reject( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! BST_Clients::is_pending( $user_id ) || array( 'bst_client' ) !== array_values( (array) $user->roles ) ) {
			return new WP_Error( 'bst_not_pending', __( 'That account is not awaiting approval.', 'bonsai-support-tickets' ) );
		}

		if ( BST_Tickets::client_tickets( $user_id ) ) {
			return new WP_Error( 'bst_has_tickets', __( 'That account has requests linked to it, so it was not deleted. Edit it under Users instead.', 'bonsai-support-tickets' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( $user_id ) ) {
			return new WP_Error( 'bst_delete_failed', __( 'Could not delete that account.', 'bonsai-support-tickets' ) );
		}

		/**
		 * A pending client account was rejected and deleted.
		 *
		 * @param string $email Their email address.
		 * @param int    $by    Rejecting user ID.
		 */
		do_action( 'bst_client_rejected', $user->user_email, get_current_user_id() );

		return true;
	}

	/*
	|----------------------------------------------------------------------
	| Emails
	|----------------------------------------------------------------------
	*/

	/**
	 * "Thanks, we'll check your details" to the applicant.
	 *
	 * @param int $user_id User ID.
	 */
	private static function email_applicant( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		BST_Mailer::send_account_email(
			$user->user_email,
			__( 'We\'ve received your registration', 'bonsai-support-tickets' ),
			array(
				'heading' => sprintf(
					/* translators: %s: first name. */
					__( 'Thanks, %s', 'bonsai-support-tickets' ),
					$user->first_name
				),
				'intro'   => __( 'We\'ve received your request for a support account. We\'ll check your details and email you a link to set your password, usually within one working day.', 'bonsai-support-tickets' ),
				'footer'  => __( 'If you didn\'t register, you can ignore this email and the account will not be activated.', 'bonsai-support-tickets' ),
			)
		);
	}

	/**
	 * "New sign-up" to everyone who can approve.
	 *
	 * @param int $user_id User ID.
	 */
	private static function email_team( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$recipients = get_users(
			array(
				'capability' => 'bst_approve_clients',
				'fields'     => array( 'user_email' ),
			)
		);

		/**
		 * Filter who is emailed about new client sign-ups.
		 *
		 * @param string[] $emails  Email addresses.
		 * @param int      $user_id New user ID.
		 */
		$emails = apply_filters( 'bst_registration_notify_recipients', wp_list_pluck( $recipients, 'user_email' ), $user_id );

		$details = array(
			__( 'Client', 'bonsai-support-tickets' )  => BST_Clients::client_name( $user_id ),
			__( 'Name', 'bonsai-support-tickets' )    => $user->display_name,
			__( 'Email', 'bonsai-support-tickets' )   => $user->user_email,
			__( 'Website', 'bonsai-support-tickets' ) => $user->user_url,
			__( 'Phone', 'bonsai-support-tickets' )   => BST_Clients::phone( $user_id ),
		);

		foreach ( array_unique( array_filter( $emails, 'is_email' ) ) as $email ) {
			BST_Mailer::send_account_email(
				$email,
				/* translators: %s: client name. */
				sprintf( __( 'New support sign-up: %s', 'bonsai-support-tickets' ), BST_Clients::client_name( $user_id ) ),
				array(
					'heading'      => __( 'New sign-up awaiting approval', 'bonsai-support-tickets' ),
					'details'      => array_filter( $details ),
					'button_url'   => BST_Admin_Signups::url(),
					'button_label' => __( 'Review sign-ups', 'bonsai-support-tickets' ),
					'footer'       => __( 'They can\'t log in until someone approves the account.', 'bonsai-support-tickets' ),
				)
			);
		}
	}

	/*
	|----------------------------------------------------------------------
	| Spam protection
	|----------------------------------------------------------------------
	*/

	/**
	 * Signed "form shown at" timestamp.
	 *
	 * @return string
	 */
	public static function timestamp_token() {
		$time = (string) time();
		return $time . '.' . substr( wp_hash( 'bst_register_ts|' . $time ), 0, 16 );
	}

	/**
	 * Whether the timestamp is genuine, at least MIN_SECONDS old and less than a day old.
	 *
	 * @param string $token Token from the form.
	 * @return bool
	 */
	public static function timestamp_ok( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		if ( ! hash_equals( substr( wp_hash( 'bst_register_ts|' . $parts[0] ), 0, 16 ), $parts[1] ) ) {
			return false;
		}
		$age = time() - (int) $parts[0];
		return $age >= self::MIN_SECONDS && $age < DAY_IN_SECONDS;
	}

	/**
	 * Transient key for this visitor's IP.
	 *
	 * @return string
	 */
	private static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'bst_reg_' . substr( wp_hash( $ip ), 0, 20 );
	}

	/**
	 * Whether this IP is under the hourly limit.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		return (int) get_transient( self::rate_key() ) < self::RATE_LIMIT;
	}

	/**
	 * Count a successful registration against this IP.
	 */
	private static function count_attempt() {
		$key = self::rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/*
	|----------------------------------------------------------------------
	| Redirects and flash (logged-out visitors have no user ID, so the
	| errors are keyed by a random token in the URL instead)
	|----------------------------------------------------------------------
	*/

	/**
	 * Back to the form with errors.
	 *
	 * @param array $errors Errors.
	 * @param array $input  Input to refill.
	 */
	private static function fail( array $errors, array $input ) {
		$token = wp_generate_password( 20, false, false );
		set_transient(
			'bst_reg_flash_' . $token,
			array(
				'errors' => $errors,
				'input'  => $input,
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					self::QUERY_ARG => '1',
					'bst_flash'     => $token,
				),
				self::back_url()
			) . '#bst-register'
		);
		exit;
	}

	/**
	 * To the "thanks" message.
	 */
	private static function done() {
		wp_safe_redirect( add_query_arg( self::QUERY_ARG, 'done', self::back_url() ) . '#bst-register' );
		exit;
	}

	/**
	 * Read (and clear) errors for this visitor.
	 *
	 * @return array
	 */
	private static function read_flash() {
		static $flash = null;
		if ( null !== $flash ) {
			return $flash;
		}
		$flash = array(
			'errors' => array(),
			'input'  => array(),
		);
		$token = isset( $_GET['bst_flash'] ) ? sanitize_key( wp_unslash( $_GET['bst_flash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( $token ) {
			$stored = get_transient( 'bst_reg_flash_' . $token );
			if ( is_array( $stored ) ) {
				$flash = wp_parse_args( $stored, $flash );
				delete_transient( 'bst_reg_flash_' . $token );
			}
		}
		return $flash;
	}

	/**
	 * The page the form was on, without our query args.
	 *
	 * @return string
	 */
	private static function back_url() {
		$referer = wp_get_referer();
		$url     = $referer ? wp_validate_redirect( $referer, BST_Tickets::portal_url() ) : BST_Tickets::portal_url();
		return remove_query_arg( array( self::QUERY_ARG, 'bst_flash', 'bst_error', 'bst_notice' ), $url );
	}
}
