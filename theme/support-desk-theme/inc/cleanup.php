<?php
/**
 * WordPress cleanup: head links, emojis, XML-RPC, comments, editor choice.
 *
 * Deliberately NOT ported from the original theme: stripping ?ver= query
 * strings (breaks cache busting), whole-page output buffers (role="list",
 * empty alt), forcing every external link into a new tab, and email
 * obfuscation. See CHANGELOG for why.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

// Head cleanup.
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );
add_filter( 'the_generator', '__return_empty_string' );

// XML-RPC off (re-enable if Jetpack or the mobile app is ever needed).
add_filter( 'xmlrpc_enabled', '__return_false' );

/**
 * Block editor for pages (built with Support Desk blocks); classic editor
 * for everything else (help articles are plain long-form content, team
 * members are a few fields).
 *
 * @param bool   $use       Whether to use the block editor.
 * @param string $post_type Post type.
 * @return bool
 */
function bsup_block_editor_for( $use, $post_type ) {
	return 'page' === $post_type ? $use : false;
}
add_filter( 'use_block_editor_for_post_type', 'bsup_block_editor_for', 10, 2 );

/**
 * Comments off: the support site has no blog.
 */
function bsup_disable_comments() {
	foreach ( get_post_types() as $post_type ) {
		if ( post_type_supports( $post_type, 'comments' ) ) {
			remove_post_type_support( $post_type, 'comments' );
			remove_post_type_support( $post_type, 'trackbacks' );
		}
	}
}
add_action( 'init', 'bsup_disable_comments', 100 );
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'comments_array', '__return_empty_array', 10 );

add_action(
	'admin_menu',
	function () {
		remove_menu_page( 'edit-comments.php' );
	}
);

add_action(
	'wp_before_admin_bar_render',
	function () {
		global $wp_admin_bar;
		$wp_admin_bar->remove_menu( 'comments' );
	}
);

/**
 * Posts menu: hidden. The support site doesn't publish news; help
 * content lives in Help articles (Support Desk plugin).
 * Filterable in case a site wants a blog after all.
 */
function bsup_hide_posts_menu() {
	if ( apply_filters( 'bsup_hide_posts', true ) ) {
		remove_menu_page( 'edit.php' );
	}
}
add_action( 'admin_menu', 'bsup_hide_posts_menu' );

/**
 * Trim the dashboard.
 */
function bsup_dashboard_widgets() {
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
}
add_action( 'wp_dashboard_setup', 'bsup_dashboard_widgets' );
