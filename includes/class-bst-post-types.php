<?php
/**
 * Post types and taxonomies: tickets and client companies (private, admin
 * only) and help centre articles (public).
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
	const COMPANY       = 'bst_company';
	const PARTNER       = 'bst_partner';
	const PLAN          = 'bst_plan';

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
		self::register_company();
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
	 * Client companies ("Clients" in the UI): the business a client account
	 * belongs to. Same capabilities as tickets, so agents manage them and
	 * only admins delete them. Slug is bst_company because bst_client is
	 * already the Support Client role.
	 *
	 * Partner and plan are managed lists (taxonomies) so later features
	 * (SLAs, partner branding) can hang settings off each term. One of each
	 * per client, chosen from a select in the Details box.
	 */
	private static function register_company() {
		register_post_type(
			self::COMPANY,
			array(
				'labels'              => array(
					'name'               => __( 'Clients', 'bonsai-support-tickets' ),
					'singular_name'      => __( 'Client', 'bonsai-support-tickets' ),
					'all_items'          => __( 'Clients', 'bonsai-support-tickets' ),
					'add_new'            => __( 'Add client', 'bonsai-support-tickets' ),
					'add_new_item'       => __( 'Add client', 'bonsai-support-tickets' ),
					'edit_item'          => __( 'Edit client', 'bonsai-support-tickets' ),
					'search_items'       => __( 'Search clients', 'bonsai-support-tickets' ),
					'not_found'          => __( 'No clients found.', 'bonsai-support-tickets' ),
					'not_found_in_trash' => __( 'No clients in the bin.', 'bonsai-support-tickets' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . self::TICKET,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => array( 'bst_ticket', 'bst_tickets' ),
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);

		$lists = array(
			self::PARTNER => array(
				'name'          => __( 'Partners', 'bonsai-support-tickets' ),
				'singular_name' => __( 'Partner', 'bonsai-support-tickets' ),
				'add_new_item'  => __( 'Add partner', 'bonsai-support-tickets' ),
				'back_to_items' => __( '&larr; Back to partners', 'bonsai-support-tickets' ),
			),
			self::PLAN    => array(
				'name'          => __( 'Plans', 'bonsai-support-tickets' ),
				'singular_name' => __( 'Plan', 'bonsai-support-tickets' ),
				'add_new_item'  => __( 'Add plan', 'bonsai-support-tickets' ),
				'back_to_items' => __( '&larr; Back to plans', 'bonsai-support-tickets' ),
			),
		);

		foreach ( $lists as $taxonomy => $labels ) {
			register_taxonomy(
				$taxonomy,
				self::COMPANY,
				array(
					'labels'            => $labels,
					'public'            => false,
					'show_ui'           => true,
					'show_in_menu'      => false, // Linked from the Clients screen instead.
					'show_in_rest'      => false,
					'show_admin_column' => true,
					'hierarchical'      => false,
					'meta_box_cb'       => false, // Single select in the Details box.
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
	}

	/**
	 * Help centre articles. Public and indexable, block editor enabled.
	 * Uses normal post capabilities so editors can write them.
	 *
	 * The topic taxonomy is registered first on purpose: rewrite rules are
	 * added in registration order, and the post type's attachment rule
	 * (help/{article}/{attachment}/) would otherwise swallow
	 * help/topic/{term}/ and 404 every topic page.
	 */
	private static function register_article() {
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
				'taxonomies'   => array( self::ARTICLE_TOPIC ),
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
