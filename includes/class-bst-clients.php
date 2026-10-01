<?php
/**
 * Client accounts: client name and phone fields, the "awaiting approval"
 * state for self-registered accounts, and how clients are labelled.
 *
 * Client name is the business the person works for ("The Ley Arms").
 * It is a label on each person, not a shared company record: every
 * account still only sees its own requests.
 *
 * Pending accounts (registered, not yet approved):
 *  - cannot log in or reset their password;
 *  - lose bst_submit_ticket;
 *  - are treated as unknown senders by inbound email.
 * Approval is in admin/class-bst-admin-signups.php.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clients.
 */
class BST_Clients {

	const META_CLIENT_NAME = 'bst_client_name';
	const META_PHONE       = 'bst_phone';
	const META_PENDING     = 'bst_pending';
	const META_REGISTERED  = 'bst_registered_via';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'block_pending_login' ), 99 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_pending_reset' ), 10, 2 );
		add_filter( 'user_has_cap', array( __CLASS__, 'strip_pending_caps' ), 10, 4 );

		// Profile fields (admins editing a client on Users → Edit).
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );

		// Users list.
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column' ), 10, 3 );
	}

	/*
	|----------------------------------------------------------------------
	| Data
	|----------------------------------------------------------------------
	*/

	/**
	 * Client (business) name for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function client_name( $user_id ) {
		return $user_id ? (string) get_user_meta( (int) $user_id, self::META_CLIENT_NAME, true ) : '';
	}

	/**
	 * Phone number for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function phone( $user_id ) {
		return $user_id ? (string) get_user_meta( (int) $user_id, self::META_PHONE, true ) : '';
	}

	/**
	 * Whether a self-registered account is still awaiting approval.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_pending( $user_id ) {
		return $user_id && (bool) get_user_meta( (int) $user_id, self::META_PENDING, true );
	}

	/**
	 * Label used in admin lists: "The Ley Arms — Jane Smith".
	 * Falls back to the person's name when there is no client name.
	 *
	 * @param WP_User|int $user User or ID.
	 * @return string
	 */
	public static function label( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user ) {
			return '';
		}
		$client = self::client_name( $user->ID );
		return '' !== $client ? $client . ' — ' . $user->display_name : $user->display_name;
	}

	/**
	 * Accounts awaiting approval, oldest first.
	 *
	 * @return WP_User[]
	 */
	public static function pending() {
		return get_users(
			array(
				'meta_key'   => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'registered',
				'order'      => 'ASC',
			)
		);
	}

	/**
	 * Number of accounts awaiting approval.
	 *
	 * @return int
	 */
	public static function pending_count() {
		return count( self::pending() );
	}

	/*
	|----------------------------------------------------------------------
	| Pending accounts
	|----------------------------------------------------------------------
	*/

	/**
	 * Stop pending accounts logging in. Runs after the password check, so
	 * only someone with the right password sees this message.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_pending_login( $user ) {
		if ( $user instanceof WP_User && self::is_pending( $user->ID ) ) {
			return new WP_Error(
				'bst_pending',
				__( 'Your account is waiting for approval. We\'ll email you as soon as it\'s ready.', 'bonsai-support-tickets' )
			);
		}
		return $user;
	}

	/**
	 * No password reset until approved (approval sends the set-password link).
	 *
	 * @param bool $allow   Whether to allow.
	 * @param int  $user_id User ID.
	 * @return bool
	 */
	public static function block_pending_reset( $allow, $user_id ) {
		return self::is_pending( $user_id ) ? false : $allow;
	}

	/**
	 * Belt and braces: a pending account can't submit even if it somehow
	 * has a session.
	 *
	 * @param array   $allcaps All caps.
	 * @param array   $caps    Required caps.
	 * @param array   $args    Args.
	 * @param WP_User $user    User.
	 * @return array
	 */
	public static function strip_pending_caps( $allcaps, $caps, $args, $user ) {
		if ( ! empty( $allcaps['bst_submit_ticket'] ) && $user instanceof WP_User && self::is_pending( $user->ID ) ) {
			$allcaps['bst_submit_ticket'] = false;
		}
		return $allcaps;
	}

	/*
	|----------------------------------------------------------------------
	| Admin: profile and users list
	|----------------------------------------------------------------------
	*/

	/**
	 * Client fields on the user profile screen (client accounts only).
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function profile_fields( $user ) {
		if ( ! in_array( 'bst_client', (array) $user->roles, true ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		wp_nonce_field( 'bst_client_profile_' . $user->ID, 'bst_client_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Support client', 'bonsai-support-tickets' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="bst-client-name"><?php esc_html_e( 'Client name', 'bonsai-support-tickets' ); ?></label></th>
				<td>
					<input type="text" name="bst_client_name" id="bst-client-name" class="regular-text" value="<?php echo esc_attr( self::client_name( $user->ID ) ); ?>">
					<p class="description"><?php esc_html_e( 'The business this person works for, e.g. The Ley Arms.', 'bonsai-support-tickets' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="bst-phone"><?php esc_html_e( 'Phone', 'bonsai-support-tickets' ); ?></label></th>
				<td><input type="tel" name="bst_phone" id="bst-phone" class="regular-text" value="<?php echo esc_attr( self::phone( $user->ID ) ); ?>"></td>
			</tr>
			<?php if ( self::is_pending( $user->ID ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Status', 'bonsai-support-tickets' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %s: link to the sign-ups screen. */
							esc_html__( 'Awaiting approval. Approve it under %s.', 'bonsai-support-tickets' ),
							'<a href="' . esc_url( BST_Admin_Signups::url() ) . '">' . esc_html__( 'Support → Sign-ups', 'bonsai-support-tickets' ) . '</a>'
						);
						?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Save the profile fields.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_profile_fields( $user_id ) {
		if ( ! isset( $_POST['bst_client_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bst_client_profile_nonce'] ) ), 'bst_client_profile_' . $user_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_CLIENT_NAME, sanitize_text_field( wp_unslash( $_POST['bst_client_name'] ?? '' ) ) );
		update_user_meta( $user_id, self::META_PHONE, sanitize_text_field( wp_unslash( $_POST['bst_phone'] ?? '' ) ) );
	}

	/**
	 * Client name column on Users.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function users_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['bst_client_name'] = __( 'Client', 'bonsai-support-tickets' );
			}
		}
		return $out;
	}

	/**
	 * Client name column content.
	 *
	 * @param string $output  Output so far.
	 * @param string $column  Column.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function users_column( $output, $column, $user_id ) {
		if ( 'bst_client_name' !== $column ) {
			return $output;
		}
		$output = esc_html( self::client_name( $user_id ) );
		if ( self::is_pending( $user_id ) ) {
			$output .= ' <em>' . esc_html__( '(awaiting approval)', 'bonsai-support-tickets' ) . '</em>';
		}
		return $output;
	}
}
