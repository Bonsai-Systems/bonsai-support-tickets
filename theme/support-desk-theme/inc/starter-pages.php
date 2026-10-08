<?php
/**
 * One-click starter pages.
 *
 * Shows an admin notice after activation offering to create Home, Submit
 * a request, My requests and Meet the team — pre-filled with modules —
 * then sets the front page, the Support Desk plugin's page settings and
 * a primary menu. Existing pages with the same slug are never touched.
 * Modules are written to the ACF page builder, so ACF Pro must be active.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option that hides the notice once pages are created or dismissed.
 */
const BSUP_STARTER_OPTION = 'bsup_starter_pages_done';

/**
 * Admin notice offering to create the starter pages.
 */
function bsup_starter_pages_notice() {
	if ( ! current_user_can( 'manage_options' ) || get_option( BSUP_STARTER_OPTION ) || ! bsup_has_acf() ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'themes', 'edit-page', 'bst_ticket_page_bst-overview' ), true ) ) {
		return;
	}

	$create_url  = wp_nonce_url( admin_url( 'admin-post.php?action=bsup_create_starter_pages' ), 'bsup_starter_pages' );
	$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=bsup_dismiss_starter_pages' ), 'bsup_starter_pages' );
	?>
	<div class="notice notice-info">
		<p><strong><?php esc_html_e( 'Support Desk theme', 'support-desk' ); ?></strong></p>
		<p><?php esc_html_e( 'Create the starter pages (Home, Submit a request, My requests, Meet the team) with their modules filled in? This also sets the front page, the Support plugin\'s page settings and the main menu. Existing pages with the same address are left alone.', 'support-desk' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create starter pages', 'support-desk' ); ?></a>
			<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'No thanks', 'support-desk' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'bsup_starter_pages_notice' );

/**
 * Result notice after creating pages.
 */
function bsup_starter_pages_result_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	if ( empty( $_GET['bsup_starter'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$created = absint( $_GET['created'] ?? 0 );
	$skipped = absint( $_GET['skipped'] ?? 0 );
	// phpcs:enable

	$message = sprintf(
		/* translators: 1: pages created, 2: pages skipped. */
		__( 'Starter pages: %1$d created, %2$d skipped because a page with that address already exists.', 'support-desk' ),
		$created,
		$skipped
	);
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'bsup_starter_pages_result_notice' );

/**
 * Check nonce and capability for the starter page actions.
 */
function bsup_starter_pages_verify() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'support-desk' ), 403 );
	}
	check_admin_referer( 'bsup_starter_pages' );
}

/**
 * Dismiss the starter pages notice.
 */
function bsup_dismiss_starter_pages() {
	bsup_starter_pages_verify();
	update_option( BSUP_STARTER_OPTION, 'dismissed', false );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_bsup_dismiss_starter_pages', 'bsup_dismiss_starter_pages' );

/**
 * Create the starter pages, front page, plugin settings and menu.
 */
function bsup_create_starter_pages() {
	bsup_starter_pages_verify();

	if ( ! bsup_has_acf() || ! function_exists( 'update_field' ) ) {
		wp_die( esc_html__( 'The starter pages need Advanced Custom Fields Pro. Activate it and try again.', 'support-desk' ) );
	}

	$ids     = array();
	$created = 0;
	$skipped = 0;

	// Create the target pages first so later modules can link to them.
	foreach ( bsup_starter_page_definitions() as $slug => $page ) {
		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			$ids[ $slug ] = (int) $existing->ID;
			++$skipped;
			continue;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $page['title'],
				'post_name'   => $slug,
				'menu_order'  => $page['order'],
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			error_log( 'Support Desk theme: could not create starter page "' . $slug . '": ' . $id->get_error_message() );
			continue;
		}

		$ids[ $slug ] = (int) $id;
		++$created;
		// Mark so modules are only written to pages we created.
		update_post_meta( $id, '_bsup_starter_page', 1 );
	}

	// Point the support plugin at the new pages (only if not already set).
	if ( class_exists( 'BST_Settings' ) ) {
		$settings = BST_Settings::all();
		if ( empty( $settings['portal_page_id'] ) && ! empty( $ids['my-requests'] ) ) {
			$settings['portal_page_id'] = $ids['my-requests'];
		}
		if ( empty( $settings['submit_page_id'] ) && ! empty( $ids['submit-a-request'] ) ) {
			$settings['submit_page_id'] = $ids['submit-a-request'];
		}
		// save() rebuilds the whole option, so pass every current value back in.
		BST_Settings::save( $settings );
	}

	// Modules (after plugin settings, so links resolve to the new pages).
	foreach ( bsup_starter_page_definitions() as $slug => $page ) {
		if ( empty( $ids[ $slug ] ) || ! get_post_meta( $ids[ $slug ], '_bsup_starter_page', true ) ) {
			continue;
		}
		update_field( BSUP_PAGE_BUILDER_KEY, bsup_starter_page_modules( $slug ), $ids[ $slug ] );
		delete_post_meta( $ids[ $slug ], '_bsup_starter_page' );
	}

	// Front page — only if the site is still showing latest posts.
	if ( ! empty( $ids['home'] ) && 'page' !== get_option( 'show_on_front' ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['home'] );
	}

	bsup_starter_menu( $ids );

	update_option( BSUP_STARTER_OPTION, 'created', false );

	wp_safe_redirect(
		add_query_arg(
			array(
				'bsup_starter' => 1,
				'created'      => $created,
				'skipped'      => $skipped,
			),
			admin_url( 'edit.php?post_type=page' )
		)
	);
	exit;
}
add_action( 'admin_post_bsup_create_starter_pages', 'bsup_create_starter_pages' );

/**
 * Starter pages, keyed by slug.
 *
 * @return array<string, array{title:string, order:int}>
 */
function bsup_starter_page_definitions() {
	return array(
		'home'             => array(
			'title' => __( 'Home', 'support-desk' ),
			'order' => 0,
		),
		'submit-a-request' => array(
			'title' => __( 'Submit a request', 'support-desk' ),
			'order' => 1,
		),
		'my-requests'      => array(
			'title' => __( 'My requests', 'support-desk' ),
			'order' => 2,
		),
		'meet-the-team'    => array(
			'title' => __( 'Meet the team', 'support-desk' ),
			'order' => 3,
		),
	);
}

/**
 * Page builder rows for a starter page: layout + sub field values.
 *
 * @param string $slug Page slug.
 * @return array[]
 */
function bsup_starter_page_modules( $slug ) {
	$submit_cta = array(
		'acf_fc_layout'    => 'cta_compact_module',
		'heading'          => __( 'Still need a hand', 'support-desk' ),
		'description'      => __( 'Send us a request and the team will pick it up. You can follow every update from your account.', 'support-desk' ),
		'primary_button'   => array(
			'title'  => __( 'Submit a request', 'support-desk' ),
			'url'    => bsup_submit_url(),
			'target' => '',
		),
		'secondary_button' => array(
			'title'  => __( 'Browse the help centre', 'support-desk' ),
			'url'    => bsup_help_url(),
			'target' => '',
		),
	);

	switch ( $slug ) {
		case 'home':
			return array(
				array(
					'acf_fc_layout'      => 'help_search_hero_module',
					'hero_badge'         => __( 'Support', 'support-desk' ),
					'hero_title'         => __( 'How can we help', 'support-desk' ),
					'hero_lead'          => __( 'Search our guides, or send us a request and we\'ll take it from there.', 'support-desk' ),
					'search_placeholder' => __( 'e.g. update opening hours', 'support-desk' ),
					'background_style'   => 'blue',
				),
				array(
					'acf_fc_layout'    => 'support_links_module',
					'links'            => array(
						array(
							'link_type'   => 'submit',
							'icon'        => 'ticket',
							'title'       => __( 'Submit a request', 'support-desk' ),
							'description' => __( 'Report a problem or ask for a change to your website.', 'support-desk' ),
						),
						array(
							'link_type'   => 'portal',
							'icon'        => 'list',
							'title'       => __( 'My requests', 'support-desk' ),
							'description' => __( 'See the status of everything you\'ve sent us and reply to the team.', 'support-desk' ),
						),
						array(
							'link_type'   => 'help',
							'icon'        => 'book',
							'title'       => __( 'Help centre', 'support-desk' ),
							'description' => __( 'Step-by-step guides for the things clients ask us most.', 'support-desk' ),
						),
					),
					'background_style' => 'default',
				),
				array(
					'acf_fc_layout'      => 'help_topics_module',
					'section_tag'        => __( 'Help centre', 'support-desk' ),
					'heading'            => __( 'Browse by topic', 'support-desk' ),
					'topics_source'      => 'all',
					'articles_per_topic' => 5,
					'columns'            => '3',
					'background_style'   => 'default',
				),
				$submit_cta,
			);

		case 'submit-a-request':
			return array(
				array(
					'acf_fc_layout'    => 'subpage_hero_module',
					'hero_badge'       => __( 'Support', 'support-desk' ),
					'hero_title'       => __( 'Submit a request', 'support-desk' ),
					'hero_lead'        => __( 'Tell us what\'s happening and we\'ll get back to you by email.', 'support-desk' ),
					'hero_style'       => 'default',
					'background_style' => 'warm',
				),
				array(
					'acf_fc_layout'    => 'submit_request_module',
					'heading'          => __( 'Before you send', 'support-desk' ),
					'intro'            => '<p>' . esc_html__( 'The more detail you give us, the quicker we can sort it.', 'support-desk' ) . '</p>',
					'tips'             => array(
						array( 'tip' => __( 'The page address (URL) where you saw the problem', 'support-desk' ) ),
						array( 'tip' => __( 'What you expected to happen, and what happened instead', 'support-desk' ) ),
						array( 'tip' => __( 'A screenshot, if you can', 'support-desk' ) ),
						array( 'tip' => __( 'Which device and browser you were using', 'support-desk' ) ),
					),
					'show_help_link'   => true,
					'background_style' => 'default',
				),
			);

		case 'my-requests':
			return array(
				array(
					'acf_fc_layout'    => 'subpage_hero_module',
					'hero_badge'       => __( 'Support', 'support-desk' ),
					'hero_title'       => __( 'My requests', 'support-desk' ),
					'hero_lead'        => __( 'Everything you\'ve sent us, with the latest replies from the team.', 'support-desk' ),
					'hero_style'       => 'default',
					'background_style' => 'warm',
				),
				array(
					'acf_fc_layout'    => 'ticket_portal_module',
					'intro'            => '',
					'background_style' => 'default',
				),
			);

		case 'meet-the-team':
			return array(
				array(
					'acf_fc_layout'    => 'subpage_hero_module',
					'hero_badge'       => __( 'Who you\'ll hear from', 'support-desk' ),
					'hero_title'       => __( 'Meet the team', 'support-desk' ),
					'hero_lead'        => __( 'The people who build, host and look after your website.', 'support-desk' ),
					'hero_style'       => 'default',
					'background_style' => 'warm',
				),
				array(
					'acf_fc_layout'    => 'meet_the_team_module',
					'source'           => 'all',
					'background_style' => 'default',
				),
				$submit_cta,
			);
	}

	return array();
}

/**
 * Create and assign a primary menu, unless one is already assigned.
 *
 * @param array $ids Page IDs keyed by slug.
 */
function bsup_starter_menu( array $ids ) {
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	if ( ! empty( $locations['primary'] ) && wp_get_nav_menu_object( $locations['primary'] ) ) {
		return;
	}

	$name = __( 'Support menu', 'support-desk' );
	$menu = wp_get_nav_menu_object( $name );
	if ( $menu ) {
		$menu_id = (int) $menu->term_id;
	} else {
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			error_log( 'Support Desk theme: could not create menu: ' . $menu_id->get_error_message() );
			return;
		}

		$position = 1;

		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'    => __( 'Help centre', 'support-desk' ),
				'menu-item-url'      => bsup_help_url(),
				'menu-item-type'     => 'custom',
				'menu-item-status'   => 'publish',
				'menu-item-position' => $position++,
			)
		);

		foreach ( array( 'submit-a-request', 'my-requests', 'meet-the-team' ) as $slug ) {
			if ( empty( $ids[ $slug ] ) ) {
				continue;
			}
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-object-id' => $ids[ $slug ],
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $position++,
				)
			);
		}
	}

	$locations['primary'] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}
