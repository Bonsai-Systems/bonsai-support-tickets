<?php
/**
 * Theme supports, menus, image sizes.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Theme setup.
 */
function bsup_setup() {
	load_theme_textdomain( 'support-desk', BSUP_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );

	// Logo: Appearance → Customise → Site Identity. Falls back to the Support Desk logo, then the support name.
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 120,
			'width'       => 480,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	// Block editor: show pages as they'll look (fonts, tokens, module CSS).
	add_theme_support( 'editor-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'wp-block-styles' );

	// Team headshots: 3:4 portrait.
	add_image_size( 'bsup-portrait', 600, 800, true );

	register_nav_menus(
		array(
			'primary'        => __( 'Primary navigation', 'support-desk' ),
			'footer_support' => __( 'Footer – Support column', 'support-desk' ),
			'footer_legal'   => __( 'Footer – Legal column', 'support-desk' ),
		)
	);
}
add_action( 'after_setup_theme', 'bsup_setup' );

/**
 * Body classes: post type and page slug, for targeted styling.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function bsup_body_classes( $classes ) {
	if ( is_singular() ) {
		$classes[] = 'post-type-' . sanitize_html_class( get_post_type() );
	}
	if ( is_page() ) {
		$classes[] = 'page-' . sanitize_html_class( get_post_field( 'post_name' ) );
	}
	return $classes;
}
add_filter( 'body_class', 'bsup_body_classes' );

/**
 * Red bar on the admin bar when the site is set to discourage search
 * engines — a reminder not to launch like that.
 */
function bsup_noindex_warning() {
	if ( '0' === get_option( 'blog_public' ) && is_admin_bar_showing() ) {
		echo '<style>#wpadminbar{border-top:5px solid #cf0000}</style>';
	}
}
add_action( 'admin_head', 'bsup_noindex_warning' );
add_action( 'wp_head', 'bsup_noindex_warning' );
