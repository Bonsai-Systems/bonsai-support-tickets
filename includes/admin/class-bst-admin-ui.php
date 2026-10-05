<?php
/**
 * Bonsai admin UI: shared header and stylesheet for this plugin's screens.
 *
 * Markup and CSS follow the Bonsai admin design system
 * (assets/admin/bonsai-admin-ui.css, canonical copy in bonsai-seo-geo-checker).
 * Self-contained: nothing here depends on another Bonsai plugin.
 *
 * Ticket and help-article screens are core post screens, so they can't be
 * wrapped in .bonsai-ui. They get a compact Bonsai banner above the page
 * instead (in_admin_header), and bst-admin.css styles the rest.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Bonsai header and loads the stylesheets.
 */
class BST_Admin_UI {

	const REPO    = 'https://github.com/Bonsai-Systems/bonsai-support-tickets';
	const WEBSITE = 'https://bonsaidigitalcollective.co.uk/';
	const HANDLE  = 'bst-bonsai-admin-ui';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'banner' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @return bool
	 */
	public static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		if ( in_array( $screen->post_type, array( BST_Post_Types::TICKET, BST_Post_Types::ARTICLE, BST_Post_Types::COMPANY ), true ) ) {
			return true;
		}
		if ( in_array( $screen->taxonomy, array( BST_Post_Types::TICKET_TYPE, BST_Post_Types::ARTICLE_TOPIC, BST_Post_Types::PARTNER, BST_Post_Types::PLAN ), true ) ) {
			return true;
		}
		return str_contains( (string) $screen->id, 'bst-settings' ) || str_contains( (string) $screen->id, 'bst-signups' );
	}

	/**
	 * Whether the screen is one of our full pages (settings, sign-ups),
	 * which print their own header instead of the banner.
	 *
	 * @param WP_Screen|null $screen Screen.
	 * @return bool
	 */
	private static function is_full_page( $screen ) {
		return $screen && ( str_contains( (string) $screen->id, 'bst-settings' ) || str_contains( (string) $screen->id, 'bst-signups' ) );
	}

	/**
	 * Load the design-system stylesheet and our admin CSS/JS on our screens only.
	 */
	public static function enqueue() {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, BST_URL . 'assets/admin/bonsai-admin-ui.css', array(), BST_VERSION );
		wp_enqueue_style( 'bst-admin', BST_URL . 'assets/admin/bst-admin.css', array( self::HANDLE ), BST_VERSION );

		$screen = get_current_screen();
		if ( self::is_full_page( $screen ) ) {
			$deps = array( 'jquery' );
			// Core colour picker for Settings → Appearance.
			if ( str_contains( (string) $screen->id, 'bst-settings' ) ) {
				wp_enqueue_style( 'wp-color-picker' );
				$deps[] = 'wp-color-picker';
			}
			wp_enqueue_script( 'bst-admin', BST_URL . 'assets/admin/bst-admin.js', $deps, BST_VERSION, true );
		}
		if ( $screen && BST_Post_Types::TICKET === $screen->post_type && 'post' === $screen->base ) {
			wp_enqueue_script( 'bst-admin', BST_URL . 'assets/admin/bst-admin.js', array( 'jquery' ), BST_VERSION, true );
			wp_localize_script(
				'bst-admin',
				'bstAdmin',
				array(
					'sendReply' => __( 'Send reply', 'bonsai-support-tickets' ),
					'addNote'   => __( 'Add internal note', 'bonsai-support-tickets' ),
				)
			);
		}
	}

	/**
	 * Body class for scoping bst-admin.css.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::is_plugin_screen() ? $classes . ' bst-admin-screen' : $classes;
	}

	/**
	 * Compact Bonsai banner above core post/taxonomy screens.
	 */
	public static function banner() {
		$screen = get_current_screen();
		if ( ! self::is_plugin_screen() || ! $screen || self::is_full_page( $screen ) ) {
			return; // Settings and sign-ups print the full header themselves.
		}
		?>
		<div class="bonsai-ui bst-banner">
			<div class="bonsai-ui-header bst-banner__inner">
				<div class="bst-banner__brand">
					<img class="bst-banner__logo" src="<?php echo esc_url( BST_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="<?php esc_attr_e( 'The Bonsai Digital Collective', 'bonsai-support-tickets' ); ?>">
					<span class="bst-banner__title"><?php esc_html_e( 'Support', 'bonsai-support-tickets' ); ?></span>
				</div>
				<nav class="bst-banner__nav" aria-label="<?php esc_attr_e( 'Support sections', 'bonsai-support-tickets' ); ?>">
					<?php foreach ( self::nav_links() as $link ) : ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>"<?php echo $link['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $link['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<span class="bonsai-ui-version">v<?php echo esc_html( BST_VERSION ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Banner navigation.
	 *
	 * @return array[]
	 */
	private static function nav_links() {
		$screen = get_current_screen();
		$links  = array(
			array(
				'label'   => __( 'Tickets', 'bonsai-support-tickets' ),
				'url'     => admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ),
				'current' => $screen && BST_Post_Types::TICKET === $screen->post_type && ! $screen->taxonomy,
			),
			array(
				'label'   => __( 'Clients', 'bonsai-support-tickets' ),
				'url'     => admin_url( 'edit.php?post_type=' . BST_Post_Types::COMPANY ),
				'current' => $screen && BST_Post_Types::COMPANY === $screen->post_type,
			),
			array(
				'label'   => __( 'Help articles', 'bonsai-support-tickets' ),
				'url'     => admin_url( 'edit.php?post_type=' . BST_Post_Types::ARTICLE ),
				'current' => $screen && BST_Post_Types::ARTICLE === $screen->post_type && ! $screen->taxonomy,
			),
		);

		if ( current_user_can( 'bst_approve_clients' ) ) {
			$pending = BST_Clients::pending_count();
			$links[] = array(
				/* translators: %d: sign-ups awaiting approval. */
				'label'   => $pending ? sprintf( __( 'Sign-ups (%d)', 'bonsai-support-tickets' ), $pending ) : __( 'Sign-ups', 'bonsai-support-tickets' ),
				'url'     => BST_Admin_Signups::url(),
				'current' => false,
			);
		}

		if ( current_user_can( 'bst_manage_settings' ) ) {
			$links[] = array(
				'label'   => __( 'Settings', 'bonsai-support-tickets' ),
				'url'     => admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET . '&page=bst-settings' ),
				'current' => false,
			);
		}

		return $links;
	}

	/**
	 * Full page header (settings screen), followed by the marker core uses
	 * to place admin notices, so notices sit below the header.
	 *
	 * @param string $title Page title (plain text).
	 * @param string $lead  Short description. May contain links, code and emphasis.
	 * @param array  $links Extra links shown before the standard ones: each array( 'label' => '', 'url' => '' ).
	 */
	public static function header( $title, $lead = '', $links = array() ) {
		$links = array_merge(
			$links,
			array(
				array(
					'label'    => __( 'GitHub', 'bonsai-support-tickets' ),
					'url'      => self::REPO,
					'external' => true,
				),
				array(
					'label'    => __( 'Changelog', 'bonsai-support-tickets' ),
					'url'      => self::REPO . '/releases',
					'external' => true,
				),
				array(
					'label'    => __( 'The Bonsai Digital Collective', 'bonsai-support-tickets' ),
					'url'      => self::WEBSITE,
					'external' => true,
				),
			)
		);
		?>
		<header class="bonsai-ui-header">
			<div class="bonsai-ui-header__main">
				<img class="bonsai-ui-header__logo" src="<?php echo esc_url( BST_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="<?php esc_attr_e( 'The Bonsai Digital Collective', 'bonsai-support-tickets' ); ?>">
				<h1 class="bonsai-ui-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $lead ) : ?>
					<p class="bonsai-ui-header__lead"><?php echo wp_kses( $lead, self::lead_tags() ); ?></p>
				<?php endif; ?>
				<ul class="bonsai-ui-header__links">
					<?php foreach ( $links as $link ) : ?>
						<li>
							<?php if ( ! empty( $link['external'] ) ) : ?>
								<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'bonsai-support-tickets' ); ?></span></a>
							<?php else : ?>
								<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="bonsai-ui-header__meta">
				<span class="bonsai-ui-version">v<?php echo esc_html( BST_VERSION ); ?></span>
			</div>
		</header>
		<hr class="wp-header-end">
		<?php
	}

	/**
	 * Tags allowed in the header lead.
	 *
	 * @return array
	 */
	private static function lead_tags() {
		return array(
			'a'      => array( 'href' => true ),
			'code'   => array(),
			'strong' => array(),
			'em'     => array(),
		);
	}

	/**
	 * Badge markup using the design system's variants.
	 *
	 * @param string $label   Text.
	 * @param string $variant success|warning|error|neutral.
	 * @return string
	 */
	public static function badge( $label, $variant = 'neutral' ) {
		$class = 'bonsai-ui-badge bst-badge';
		if ( in_array( $variant, array( 'success', 'warning', 'error' ), true ) ) {
			$class .= ' bonsai-ui-badge--' . $variant;
		}
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Queue a notice for the next admin page load (after a redirect).
	 *
	 * @param string $message Plain text.
	 * @param string $type    success|error|warning|info.
	 */
	public static function flash( $message, $type = 'success' ) {
		$key           = 'bst_admin_notices_' . get_current_user_id();
		$notices       = get_transient( $key );
		$notices       = is_array( $notices ) ? $notices : array();
		$notices[]     = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear queued notices.
	 */
	public static function print_flashes() {
		$key     = 'bst_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}
}
