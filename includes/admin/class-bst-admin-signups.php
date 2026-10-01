<?php
/**
 * Support → Sign-ups: approve or reject self-registered client accounts.
 *
 * Lives under Support (not Users) so Support Agents can approve without
 * having user-management capabilities. Gated by bst_approve_clients.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sign-ups screen.
 */
class BST_Admin_Signups {

	const SLUG = 'bst-signups';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bst_approve_client', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_bst_reject_client', array( __CLASS__, 'handle_reject' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET . '&page=' . self::SLUG );
	}

	/**
	 * Submenu, with a count bubble while anyone is waiting.
	 */
	public static function menu() {
		$count = current_user_can( 'bst_approve_clients' ) ? BST_Clients::pending_count() : 0;
		$title = __( 'Sign-ups', 'bonsai-support-tickets' );
		if ( $count ) {
			$title .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count );
		}

		add_submenu_page(
			'edit.php?post_type=' . BST_Post_Types::TICKET,
			__( 'Client sign-ups', 'bonsai-support-tickets' ),
			$title,
			'bst_approve_clients',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Common checks.
	 *
	 * @param string $action Nonce action prefix.
	 * @return int User ID from the request.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'bst_approve_clients' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage sign-ups.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		$user_id = absint( $_POST['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next, nonce is per user.
		check_admin_referer( $action . '_' . $user_id );
		return $user_id;
	}

	/**
	 * Approve.
	 */
	public static function handle_approve() {
		$user_id = self::guard( 'bst_approve_client' );
		$result  = BST_Registration::approve( $user_id );

		if ( is_wp_error( $result ) ) {
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
		} else {
			$user = get_userdata( $user_id );
			/* translators: %s: client label. */
			BST_Admin_UI::flash( sprintf( __( 'Approved %s. They have been emailed a link to set their password.', 'bonsai-support-tickets' ), BST_Clients::label( $user ) ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Reject (delete).
	 */
	public static function handle_reject() {
		$user_id = self::guard( 'bst_reject_client' );
		$label   = BST_Clients::label( $user_id );
		$result  = BST_Registration::reject( $user_id );

		if ( is_wp_error( $result ) ) {
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
		} else {
			/* translators: %s: client label. */
			BST_Admin_UI::flash( sprintf( __( 'Rejected and deleted the sign-up from %s.', 'bonsai-support-tickets' ), $label ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Page.
	 */
	public static function render() {
		if ( ! current_user_can( 'bst_approve_clients' ) ) {
			return;
		}

		$pending = BST_Clients::pending();
		?>
		<div class="wrap bonsai-ui">
			<?php
			BST_Admin_UI::header(
				__( 'Client sign-ups', 'bonsai-support-tickets' ),
				__( 'People who registered on the support site. Approving emails them a link to set their password; rejecting deletes the account.', 'bonsai-support-tickets' ),
				array(
					array(
						'label' => __( 'Tickets', 'bonsai-support-tickets' ),
						'url'   => admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ),
					),
				)
			);
			?>

			<?php if ( ! BST_Registration::enabled() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Registration is switched off in Support → Settings, so no new sign-ups will arrive.', 'bonsai-support-tickets' ); ?></p></div>
			<?php endif; ?>

			<section class="bonsai-ui-card">
				<h2 class="bonsai-ui-card__title">
					<?php
					/* translators: %d: number of sign-ups. */
					echo esc_html( sprintf( _n( '%d awaiting approval', '%d awaiting approval', count( $pending ), 'bonsai-support-tickets' ), count( $pending ) ) );
					?>
				</h2>

				<?php if ( ! $pending ) : ?>
					<p class="bonsai-ui-card__intro"><?php esc_html_e( 'No one is waiting. New sign-ups appear here, and everyone who can approve them is emailed.', 'bonsai-support-tickets' ); ?></p>
				<?php else : ?>
					<table class="widefat striped bst-signups">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Client', 'bonsai-support-tickets' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Name', 'bonsai-support-tickets' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'bonsai-support-tickets' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Website', 'bonsai-support-tickets' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Phone', 'bonsai-support-tickets' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Registered', 'bonsai-support-tickets' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'bonsai-support-tickets' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending as $user ) : ?>
								<tr>
									<td><strong><?php echo esc_html( BST_Clients::client_name( $user->ID ) ); ?></strong></td>
									<td><?php echo esc_html( $user->display_name ); ?></td>
									<td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
									<td>
										<?php if ( $user->user_url ) : ?>
											<a href="<?php echo esc_url( $user->user_url ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $user->user_url ) ) ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'bonsai-support-tickets' ); ?></span></a>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( BST_Clients::phone( $user->ID ) ); ?></td>
									<td><?php echo esc_html( bst_format_datetime( $user->user_registered ) ); ?></td>
									<td class="bst-signups__actions">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="bst_approve_client">
											<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
											<?php wp_nonce_field( 'bst_approve_client_' . $user->ID ); ?>
											<button type="submit" class="button button-primary">
												<?php esc_html_e( 'Approve', 'bonsai-support-tickets' ); ?>
												<span class="screen-reader-text"><?php echo esc_html( BST_Clients::label( $user ) ); ?></span>
											</button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bst-signups__reject">
											<input type="hidden" name="action" value="bst_reject_client">
											<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
											<?php wp_nonce_field( 'bst_reject_client_' . $user->ID ); ?>
											<button type="submit" class="button-link button-link-delete" data-bst-confirm="<?php esc_attr_e( 'Delete this sign-up? They will not be told.', 'bonsai-support-tickets' ); ?>">
												<?php esc_html_e( 'Reject', 'bonsai-support-tickets' ); ?>
												<span class="screen-reader-text"><?php echo esc_html( BST_Clients::label( $user ) ); ?></span>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}
}
