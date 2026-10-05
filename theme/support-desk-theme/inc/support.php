<?php
/**
 * Support Desk plugin integration.
 *
 * The theme never assumes the plugin is active: every call goes through
 * these wrappers, which fall back to sensible URLs.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the Support Desk plugin is active.
 *
 * @return bool
 */
function bsup_has_tickets() {
	return function_exists( 'bst_get_portal_url' ) && class_exists( 'BST_Tickets' );
}

/**
 * Submit-a-request URL.
 *
 * @return string
 */
function bsup_submit_url() {
	return bsup_has_tickets() ? bst_get_submit_url() : home_url( '/submit-a-request/' );
}

/**
 * My requests URL.
 *
 * @return string
 */
function bsup_portal_url() {
	return bsup_has_tickets() ? bst_get_portal_url() : home_url( '/my-requests/' );
}

/**
 * Help centre URL (help article archive).
 *
 * @return string
 */
function bsup_help_url() {
	$url = post_type_exists( 'bst_article' ) ? get_post_type_archive_link( 'bst_article' ) : '';
	return $url ? $url : home_url( '/help/' );
}

/**
 * Shown in place of a ticket block when the plugin is off. Visitors see
 * nothing; editors see why.
 */
function bsup_tickets_missing_notice() {
	if ( current_user_can( 'edit_pages' ) ) {
		echo '<p class="bsup-editor-notice">' . esc_html__( 'This block needs the Support Desk plugin to be active.', 'support-desk' ) . '</p>';
	}
}

/**
 * Primary menu fallback when no menu is assigned: the three support
 * destinations, so a fresh install has working navigation.
 *
 * @param array $args wp_nav_menu() args.
 */
function bsup_primary_menu_fallback( $args ) {
	$links = array(
		bsup_help_url()   => __( 'Help centre', 'support-desk' ),
		bsup_submit_url() => __( 'Submit a request', 'support-desk' ),
		bsup_portal_url() => __( 'My requests', 'support-desk' ),
	);

	$class = ! empty( $args['menu_class'] ) ? $args['menu_class'] : 'menu';
	echo '<ul class="' . esc_attr( $class ) . '">';
	foreach ( $links as $url => $label ) {
		printf(
			'<li class="menu-item"><a href="%1$s">%2$s%3$s%4$s</a></li>',
			esc_url( $url ),
			wp_kses_post( $args['link_before'] ?? '' ),
			esc_html( $label ),
			wp_kses_post( $args['link_after'] ?? '' )
		);
	}
	echo '</ul>';
}

/**
 * Account link for the header: "Log in" when logged out, "Log out" when
 * logged in.
 *
 * With the tickets plugin active, "Log in" goes to the My requests page,
 * which shows the branded log-in form and the Register link. Without it,
 * core's login screen, returning to the current page.
 *
 * @return array{url:string,label:string}
 */
function bsup_account_link() {
	if ( is_user_logged_in() ) {
		return array(
			'url'   => wp_logout_url( home_url( '/' ) ),
			'label' => __( 'Log out', 'support-desk' ),
		);
	}

	if ( bsup_has_tickets() ) {
		return array(
			'url'   => bsup_portal_url(),
			'label' => __( 'Log in', 'support-desk' ),
		);
	}

	$current = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
	return array(
		'url'   => wp_login_url( $current ? $current : bsup_portal_url() ),
		'label' => __( 'Log in', 'support-desk' ),
	);
}

/**
 * Support name: the plugin's brand name (Support → Settings → Appearance),
 * else the site title.
 *
 * @return string Plain text.
 */
function bsup_brand_name() {
	if ( class_exists( 'BST_Settings' ) && method_exists( 'BST_Settings', 'brand_name' ) ) {
		return BST_Settings::brand_name();
	}
	return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
}

/**
 * Logo markup: the Site Identity logo, else the plugin's email logo, else
 * the support name as text.
 *
 * @param string $class   Image class.
 * @param bool   $eager   Load eagerly (header) rather than lazily.
 * @return string HTML (escaped).
 */
function bsup_logo_html( $class, $eager = false ) {
	$name    = bsup_brand_name();
	$logo_id = (int) get_theme_mod( 'custom_logo' );

	if ( $logo_id ) {
		$img = wp_get_attachment_image(
			$logo_id,
			'medium',
			false,
			array(
				'class'   => $class,
				'alt'     => $name,
				'loading' => $eager ? 'eager' : 'lazy',
			)
		);
		if ( $img ) {
			return $img;
		}
	}

	$url = class_exists( 'BST_Settings' ) ? (string) BST_Settings::get( 'email_logo_url' ) : '';
	if ( '' !== $url ) {
		return sprintf(
			'<img class="%1$s" src="%2$s" alt="%3$s" loading="%4$s">',
			esc_attr( $class ),
			esc_url( $url ),
			esc_attr( $name ),
			$eager ? 'eager' : 'lazy'
		);
	}

	return '<span class="bonsai-brand-text">' . esc_html( $name ) . '</span>';
}

/**
 * Theme colour tokens from the plugin's brand colours, so the site,
 * portal and emails match. Without the plugin, base.css defaults apply.
 *
 * @return string CSS declarations for :root (no selector), or ''.
 */
function bsup_color_tokens() {
	$map = array(
		'--bonsai-accent'       => 'color_accent',
		'--bonsai-accent-hover' => 'color_accent_hover',
		'--bonsai-accent-text'  => 'color_accent_text',
		'--bonsai-black'        => 'color_ink',
		'--bonsai-grey-dark'    => 'color_text',
		'--bonsai-warm'         => 'color_background',
		'--bonsai-white'        => 'color_surface',
	);

	$rules = array();
	if ( class_exists( 'BST_Appearance' ) ) {
		foreach ( $map as $var => $key ) {
			$hex = sanitize_hex_color( (string) BST_Appearance::color( $key ) );
			if ( $hex ) {
				$rules[] = $var . ':' . $hex . ';';
			}
		}
	}

	$tint = sanitize_hex_color( (string) bsup_option( 'color_tint' ) );
	if ( $tint ) {
		$rules[] = '--bonsai-blue:' . $tint . ';';
	}

	return implode( '', $rules );
}

/**
 * Print the colour tokens after base.css.
 */
function bsup_print_color_tokens() {
	$tokens = bsup_color_tokens();
	if ( '' !== $tokens ) {
		wp_add_inline_style( 'bsup-base', ':root{' . $tokens . '}' );
	}
}
add_action( 'wp_enqueue_scripts', 'bsup_print_color_tokens', 30 );

/**
 * Branded login screen: logo, brand colours, square fields.
 * Skipped when another plugin defines BWL_VERSION (a white-label login
 * plugin) so the two don't fight.
 */
function bsup_login_styles() {
	if ( defined( 'BWL_VERSION' ) ) {
		return;
	}

	$logo_id  = (int) get_theme_mod( 'custom_logo' );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
	if ( ! $logo_url && class_exists( 'BST_Settings' ) ) {
		$logo_url = (string) BST_Settings::get( 'email_logo_url' );
	}

	$css  = ':root{--bonsai-accent:#4f46e5;--bonsai-accent-hover:#4338ca;--bonsai-black:#111827;--bonsai-warm:#f9fafb;' . bsup_color_tokens() . '}';
	$css .= 'body.login{background:var(--bonsai-warm);font-family:var(--bonsai-sans,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif);}';
	if ( $logo_url ) {
		$css .= '.login h1 a{background-image:url(' . esc_url( $logo_url ) . ');background-size:contain;width:240px;height:64px;}';
	}
	$css .= '.login form{border:1px solid rgba(0,0,0,.08);border-radius:0;box-shadow:none;}';
	$css .= '.login input[type="text"],.login input[type="password"]{border-radius:0;}';
	$css .= '.login input:focus{border-color:var(--bonsai-black);box-shadow:0 0 0 1px var(--bonsai-black);}';
	$css .= '.wp-core-ui .button-primary{background:var(--bonsai-black);border-color:var(--bonsai-black);border-radius:0;}';
	$css .= '.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:var(--bonsai-accent-hover);border-color:var(--bonsai-accent-hover);}';
	$css .= '.login #nav a,.login #backtoblog a{color:var(--bonsai-black);}';

	wp_register_style( 'bsup-login', false, array(), BSUP_VERSION );
	wp_enqueue_style( 'bsup-login' );
	wp_add_inline_style( 'bsup-login', $css );
}
add_action( 'login_enqueue_scripts', 'bsup_login_styles' );

/**
 * Login logo links to the site and is named after it.
 *
 * @return string
 */
function bsup_login_logo_url() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'bsup_login_logo_url' );

/**
 * Login logo text.
 *
 * @return string
 */
function bsup_login_logo_text() {
	return bsup_brand_name();
}
add_filter( 'login_headertext', 'bsup_login_logo_text' );
