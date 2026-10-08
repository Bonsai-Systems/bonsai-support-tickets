<?php
/**
 * Team post type, for the Meet the Team module.
 *
 * Fields (role, bio, LinkedIn) are in acf-json/group_bsup_team.json and
 * saved as post meta team_role, team_bio, team_linkedin.
 * Headshot = featured image. No single pages or archive: team members
 * only appear through the module.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the team post type.
 */
function bsup_register_team() {
	register_post_type(
		'team',
		array(
			'labels'             => array(
				'name'               => __( 'Team members', 'support-desk' ),
				'singular_name'      => __( 'Team member', 'support-desk' ),
				'menu_name'          => __( 'Team', 'support-desk' ),
				'add_new_item'       => __( 'Add team member', 'support-desk' ),
				'edit_item'          => __( 'Edit team member', 'support-desk' ),
				'all_items'          => __( 'All team members', 'support-desk' ),
				'search_items'       => __( 'Search team members', 'support-desk' ),
				'not_found'          => __( 'No team members found.', 'support-desk' ),
				'not_found_in_trash' => __( 'No team members in the bin.', 'support-desk' ),
				'featured_image'     => __( 'Headshot', 'support-desk' ),
				'set_featured_image' => __( 'Set headshot (portrait, at least 600 × 800)', 'support-desk' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => false,
			'menu_position'      => 25,
			'menu_icon'          => 'dashicons-groups',
			'supports'           => array( 'title', 'thumbnail', 'page-attributes' ),
			'rewrite'            => false,
			'has_archive'        => false,
		)
	);
}
add_action( 'init', 'bsup_register_team' );

/**
 * "Name" rather than "Add title" on the team screen.
 *
 * @param string  $text Placeholder.
 * @param WP_Post $post Post.
 * @return string
 */
function bsup_team_title_placeholder( $text, $post ) {
	return 'team' === $post->post_type ? __( 'Full name', 'support-desk' ) : $text;
}
add_filter( 'enter_title_here', 'bsup_team_title_placeholder', 10, 2 );
