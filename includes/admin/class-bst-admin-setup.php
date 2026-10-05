<?php
/**
 * First-run setup: a checklist at the top of Support → Settings → General,
 * a one-click "create the portal pages", and a dismissible notice pointing
 * there until the essentials are done.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Setup checklist.
 */
class BST_Admin_Setup {

	const DISMISS_META = 'bst_setup_notice_dismissed';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bst_create_pages', array( __CLASS__, 'handle_create_pages' ) );
		add_action( 'admin_post_bst_dismiss_setup', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Setup steps, in order.
	 *
	 * Each: label, done (bool), required (bool), tab (settings tab to fix it).
	 *
	 * @return array<string,array>
	 */
	public static function steps() {
		$s        = BST_Settings::all();
		$has_page = function ( $id ) {
			return $id && 'publish' === get_post_status( (int) $id );
		};

		return array(
			'brand'   => array(
				'label'    => __( 'Add your support name and logo', 'bonsai-support-tickets' ),
				'done'     => '' !== trim( (string) $s['brand_name'] ) && '' !== (string) $s['email_logo_url'],
				'required' => true,
				'tab'      => 'appearance',
			),
			'pages'   => array(
				'label'    => __( 'Create the My requests and Submit a request pages', 'bonsai-support-tickets' ),
				'done'     => $has_page( $s['portal_page_id'] ) && $has_page( $s['submit_page_id'] ),
				'required' => true,
				'tab'      => 'general',
			),
			'from'    => array(
				'label'    => __( 'Set the address emails are sent from', 'bonsai-support-tickets' ),
				'done'     => (bool) is_email( $s['from_email'] ),
				'required' => true,
				'tab'      => 'outgoing',
			),
			'colours' => array(
				'label'    => __( 'Choose your brand colours', 'bonsai-support-tickets' ),
				'done'     => '' !== BST_Appearance::inline_css(),
				'required' => false,
				'tab'      => 'appearance',
			),
			'inbound' => array(
				'label'    => __( 'Connect a support mailbox so emails become tickets', 'bonsai-support-tickets' ),
				'done'     => $s['imap_enabled'] && BST_Settings::has_imap_credentials() && is_email( $s['inbound_address'] ),
				'required' => false,
				'tab'      => 'inbound',
			),
		);
	}

	/**
	 * Whether every required step is done.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		foreach ( self::steps() as $step ) {
			if ( $step['required'] && ! $step['done'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checklist card (General tab, above the settings form).
	 */
	public static function render_card() {
		$steps = self::steps();
		$done  = count( array_filter( wp_list_pluck( $steps, 'done' ) ) );

		if ( count( $steps ) === $done ) {
			return;
		}

		$need_pages = ! $steps['pages']['done'];
		?>
		<section class="bonsai-ui-card bst-setup">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Get set up', 'bonsai-support-tickets' ); ?></h2>
				<?php
				/* translators: 1: steps done, 2: total steps. */
				echo BST_Admin_UI::badge( sprintf( __( '%1$d of %2$d done', 'bonsai-support-tickets' ), $done, count( $steps ) ), self::is_complete() ? 'success' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				?>
			</div>
			<ol class="bst-setup__steps">
				<?php foreach ( $steps as $key => $step ) : ?>
					<li class="<?php echo esc_attr( $step['done'] ? 'is-done' : 'is-todo' ); ?>">
						<span class="bst-setup__state" aria-hidden="true"><?php echo $step['done'] ? '&#10003;' : ''; ?></span>
						<?php if ( $step['done'] ) : ?>
							<span><?php echo esc_html( $step['label'] ); ?></span>
							<span class="screen-reader-text"><?php esc_html_e( '(done)', 'bonsai-support-tickets' ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( BST_Admin_Settings::url( $step['tab'] ) ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
							<?php if ( ! $step['required'] ) : ?>
								<span class="bst-muted"><?php esc_html_e( '(optional)', 'bonsai-support-tickets' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ( $need_pages ) : ?>
				<div class="bonsai-ui-card__footer bonsai-ui-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bst_create_pages">
						<?php wp_nonce_field( 'bst_create_pages' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create the pages for me', 'bonsai-support-tickets' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Adds "My requests" and "Submit a request" pages with the right shortcodes, and selects them below. Existing pages are kept.', 'bonsai-support-tickets' ); ?></p>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Create the portal pages that aren't set yet.
	 */
	public static function handle_create_pages() {
		if ( ! current_user_can( 'bst_manage_settings' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to create pages.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_create_pages' );

		$pages = array(
			'portal_page_id' => array(
				'title'     => __( 'My requests', 'bonsai-support-tickets' ),
				'shortcode' => '[bst_my_tickets]',
			),
			'submit_page_id' => array(
				'title'     => __( 'Submit a request', 'bonsai-support-tickets' ),
				'shortcode' => '[bst_submit_form]',
			),
		);

		$input   = array();
		$created = 0;
		foreach ( $pages as $key => $page ) {
			$current = (int) BST_Settings::get( $key );
			if ( $current && 'publish' === get_post_status( $current ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_content' => '<!-- wp:shortcode -->' . $page['shortcode'] . '<!-- /wp:shortcode -->',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				error_log( BST_PRODUCT_NAME . ': could not create page ' . $page['title'] . ': ' . $id->get_error_message() );
				continue;
			}
			$input[ $key ] = (int) $id;
			++$created;
		}

		if ( $input ) {
			BST_Settings::save( $input );
		}

		BST_Admin_UI::flash(
			$created
				/* translators: %d: number of pages. */
				? sprintf( _n( 'Created %d page and selected it below.', 'Created %d pages and selected them below.', $created, 'bonsai-support-tickets' ), $created )
				: __( 'Your pages were already set up.', 'bonsai-support-tickets' )
		);
		wp_safe_redirect( BST_Admin_Settings::url( 'general' ) );
		exit;
	}

	/**
	 * "Finish setting up" notice on the dashboard and support screens.
	 */
	public static function notice() {
		if ( ! current_user_can( 'bst_manage_settings' ) || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( 'dashboard' !== $screen->id && ! BST_Admin_UI::is_plugin_screen() ) ) {
			return;
		}
		// The checklist itself is on the General tab.
		if ( str_contains( (string) $screen->id, 'bst-settings' ) && 'general' === BST_Admin_Settings::current_tab() ) {
			return;
		}
		if ( self::is_complete() ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=bst_dismiss_setup' ), 'bst_dismiss_setup' );
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p></div>',
			/* translators: %s: product name. */
			esc_html( sprintf( __( '%s needs a few details before clients can use it.', 'bonsai-support-tickets' ), BST_PRODUCT_NAME ) ),
			esc_url( BST_Admin_Settings::url( 'general' ) ),
			esc_html__( 'Finish setting up', 'bonsai-support-tickets' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'bonsai-support-tickets' )
		);
	}

	/**
	 * Hide the notice for this user.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'bst_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_dismiss_setup' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
