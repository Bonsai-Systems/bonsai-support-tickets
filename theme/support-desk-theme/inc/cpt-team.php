<?php
/**
 * Team post type, for the Meet the Team module.
 *
 * Fields (role, bio, LinkedIn) are plain post meta in a meta box below;
 * the keys (team_role, team_bio, team_linkedin) are the ones the old ACF
 * field group used, so existing team members keep their details.
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

/**
 * Team fields: key => label, type.
 *
 * @return array<string,array>
 */
function bsup_team_fields() {
	return array(
		'team_role'     => array(
			'label' => __( 'Role', 'support-desk' ),
			'type'  => 'text',
		),
		'team_bio'      => array(
			'label' => __( 'Short bio', 'support-desk' ),
			'type'  => 'textarea',
		),
		'team_linkedin' => array(
			'label' => __( 'LinkedIn profile URL', 'support-desk' ),
			'type'  => 'url',
		),
	);
}

/**
 * Register the fields as post meta (typed, sanitised, editors only).
 */
function bsup_register_team_meta() {
	foreach ( bsup_team_fields() as $key => $field ) {
		register_post_meta(
			'team',
			$key,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => bsup_team_sanitizer( $field['type'] ),
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
}
add_action( 'init', 'bsup_register_team_meta' );

/**
 * Sanitiser for a team field type.
 *
 * @param string $type text|textarea|url.
 * @return callable
 */
function bsup_team_sanitizer( $type ) {
	if ( 'url' === $type ) {
		return 'esc_url_raw';
	}
	return 'textarea' === $type ? 'sanitize_textarea_field' : 'sanitize_text_field';
}

/**
 * Details box on the team screen.
 */
function bsup_team_meta_box() {
	add_meta_box( 'bsup_team_details', __( 'Details', 'support-desk' ), 'bsup_team_meta_box_render', 'team', 'normal', 'high' );
}
add_action( 'add_meta_boxes_team', 'bsup_team_meta_box' );

/**
 * Details box.
 *
 * @param WP_Post $post Team member.
 */
function bsup_team_meta_box_render( $post ) {
	wp_nonce_field( 'bsup_save_team', 'bsup_team_nonce' );
	echo '<table class="form-table" role="presentation">';
	foreach ( bsup_team_fields() as $key => $field ) {
		$value = (string) get_post_meta( $post->ID, $key, true );
		$id    = 'bsup-' . str_replace( '_', '-', $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
		if ( 'textarea' === $field['type'] ) {
			echo '<textarea class="large-text" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			printf(
				'<input type="%1$s" class="regular-text" id="%2$s" name="%3$s" value="%4$s">',
				esc_attr( 'url' === $field['type'] ? 'url' : 'text' ),
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( $value )
			);
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

/**
 * Save the details box.
 *
 * @param int $post_id Team member.
 */
function bsup_team_save( $post_id ) {
	if ( ! isset( $_POST['bsup_team_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsup_team_nonce'] ) ), 'bsup_save_team' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( bsup_team_fields() as $key => $field ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$sanitize = bsup_team_sanitizer( $field['type'] );
		update_post_meta( $post_id, $key, $sanitize( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by $sanitize.
	}
}
add_action( 'save_post_team', 'bsup_team_save' );
