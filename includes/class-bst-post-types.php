<?php
/**
 * Post types and taxonomies: tickets (private, admin only) and help
 * centre articles (public).
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post type registration.
 */
class BST_Post_Types {

	const TICKET        = 'bst_ticket';
	const TICKET_TYPE   = 'bst_ticket_type';
	const ARTICLE       = 'bst_article';
	const ARTICLE_TOPIC = 'bst_article_topic';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register everything. Also called directly on activation before the
	 * rewrite flush.
	 */
	public static function register() {
		self::register_ticket();
		self::register_article();
	}

	/**
	 * Tickets. Not public, not in REST — clients only ever see tickets
	 * through the permission-checked front-end templates.
	 */
	private static function register_ticket() {
		register_post_type(
			self::TICKET,
			array(
				'labels'              => array(
					'name'               => __( 'Tickets', 'bonsai-support-tickets' ),
					'singular_name'      => __( 'Ticket', 'bonsai-support-tickets' ),
					'menu_name'          => __( 'Support', 'bonsai-support-tickets' ),
					'all_items'          => __( 'Tickets', 'bonsai-support-tickets' ),
					'add_new'            => __( 'New ticket', 'bonsai-support-tickets' ),
					'add_new_item'       => __( 'New ticket', 'bonsai-support-tickets' ),
					'edit_item'          => __( 'Ticket', 'bonsai-support-tickets' ),
					'search_items'       => __( 'Search tickets', 'bonsai-support-tickets' ),
					'not_found'          => __( 'No tickets found.', 'bonsai-support-tickets' ),
					'not_found_in_trash' => __( 'No tickets in the bin.', 'bonsai-support-tickets' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_position'       => 3,
				'menu_icon'           => 'dashicons-sos',
				'supports'            => array( 'title' ),
				'capability_type'     => array( 'bst_ticket', 'bst_tickets' ),
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);

		register_taxonomy(
			self::TICKET_TYPE,
			self::TICKET,
			array(
				'labels'            => array(
					'name'          => __( 'Ticket types', 'bonsai-support-tickets' ),
					'singular_name' => __( 'Ticket type', 'bonsai-support-tickets' ),
					'menu_name'     => __( 'Ticket types', 'bonsai-support-tickets' ),
					'add_new_item'  => __( 'Add ticket type', 'bonsai-support-tickets' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'meta_box_cb'       => false, // Set from the Details box instead.
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'bst_manage_settings',
					'edit_terms'   => 'bst_manage_settings',
					'delete_terms' => 'bst_manage_settings',
					'assign_terms' => 'edit_bst_tickets',
				),
			)
		);
	}

	/**
	 * Help centre articles. Public and indexable, block editor enabled.
	 * Uses normal post capabilities so editors can write them.
	 */
	private static function register_article() {
		register_post_type(
			self::ARTICLE,
			array(
				'labels'       => array(
					'name'          => __( 'Help articles', 'bonsai-support-tickets' ),
					'singular_name' => __( 'Help article', 'bonsai-support-tickets' ),
					'all_items'     => __( 'Help articles', 'bonsai-support-tickets' ),
					'add_new_item'  => __( 'Add help article', 'bonsai-support-tickets' ),
					'edit_item'     => __( 'Edit help article', 'bonsai-support-tickets' ),
					'search_items'  => __( 'Search help articles', 'bonsai-support-tickets' ),
					'not_found'     => __( 'No help articles found.', 'bonsai-support-tickets' ),
				),
				'public'       => true,
				'show_in_menu' => 'edit.php?post_type=' . self::TICKET,
				'show_in_rest' => true,
				'has_archive'  => 'help',
				'rewrite'      => array(
					'slug'       => 'help',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'page-attributes' ),
			)
		);

		register_taxonomy(
			self::ARTICLE_TOPIC,
			self::ARTICLE,
			array(
				'labels'            => array(
					'name'          => __( 'Help topics', 'bonsai-support-tickets' ),
					'singular_name' => __( 'Help topic', 'bonsai-support-tickets' ),
					'menu_name'     => __( 'Help topics', 'bonsai-support-tickets' ),
					'add_new_item'  => __( 'Add help topic', 'bonsai-support-tickets' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => 'help/topic',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Default ticket types, created once on activation. Editable afterwards
	 * under Support → Ticket types.
	 */
	public static function create_default_terms() {
		if ( get_option( 'bst_default_terms_created' ) ) {
			return;
		}

		$types = apply_filters(
			'bst_default_ticket_types',
			array(
				'bug'            => __( 'Something is broken', 'bonsai-support-tickets' ),
				'content-change' => __( 'Content change', 'bonsai-support-tickets' ),
				'new-feature'    => __( 'New feature or page', 'bonsai-support-tickets' ),
				'hosting'        => __( 'Hosting, domains and email', 'bonsai-support-tickets' ),
				'billing'        => __( 'Billing', 'bonsai-support-tickets' ),
				'other'          => __( 'Something else', 'bonsai-support-tickets' ),
			)
		);

		foreach ( $types as $slug => $name ) {
			if ( ! term_exists( $slug, self::TICKET_TYPE ) ) {
				wp_insert_term( $name, self::TICKET_TYPE, array( 'slug' => $slug ) );
			}
		}

		update_option( 'bst_default_terms_created', 1, false );
	}
}
