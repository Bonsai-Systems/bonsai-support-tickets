<?php
/**
 * ACF: Site settings options page, local JSON, default page modules,
 * missing-ACF notice, and a one-time move of Customiser settings.
 *
 * Field groups live in acf-json/ and are committed. After editing a group
 * in wp-admin, ACF writes the updated JSON back to acf-json/ — commit it.
 * The field keys are the original theme's, so its saved content matches.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Flexible content field key for the page builder (starter pages write to it).
 */
const BSUP_PAGE_BUILDER_KEY = 'field_bpb_flex';

/**
 * Site settings options page.
 */
function bsup_acf_options_page() {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}
	acf_add_options_page(
		array(
			'page_title' => __( 'Site settings', 'support-desk' ),
			'menu_title' => __( 'Site settings', 'support-desk' ),
			'menu_slug'  => 'bsup-site-settings',
			'capability' => 'edit_pages',
			'icon_url'   => 'dashicons-admin-settings',
			'position'   => 59,
			'redirect'   => false,
		)
	);
}
add_action( 'acf/init', 'bsup_acf_options_page' );

/**
 * Save ACF JSON to the theme.
 *
 * @return string
 */
function bsup_acf_json_save() {
	return BSUP_DIR . '/acf-json';
}
add_filter( 'acf/settings/save_json', 'bsup_acf_json_save' );

/**
 * Load ACF JSON from the theme only.
 *
 * @param array $paths Paths.
 * @return array
 */
function bsup_acf_json_load( $paths ) {
	unset( $paths[0] );
	$paths[] = BSUP_DIR . '/acf-json';
	return $paths;
}
add_filter( 'acf/settings/load_json', 'bsup_acf_json_load' );

// Our modules escape their own output; hide ACF's escaped-HTML notice.
add_filter( 'acf/admin/prevent_escaped_html_notice', '__return_true' );

/**
 * Default modules for brand-new pages, so editors start with a page
 * header and a content block rather than an empty builder.
 *
 * @param array $field ACF field.
 * @return array
 */
function bsup_default_page_layouts( $field ) {
	if ( ! is_admin() || ! empty( $field['value'] ) ) {
		return $field;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'page' !== $screen->post_type || 'add' !== $screen->action ) {
		return $field;
	}

	/**
	 * Filters the layouts a new page starts with.
	 *
	 * @param string[] $layouts Layout names.
	 */
	$defaults = apply_filters( 'bsup_default_page_layouts', array( 'subpage_hero_module', 'content_block_module' ) );

	$field['value'] = array_map(
		function ( $layout ) {
			return array( 'acf_fc_layout' => $layout );
		},
		$defaults
	);
	return $field;
}
add_filter( 'acf/prepare_field/name=page_builder', 'bsup_default_page_layouts' );

/**
 * Warn admins if ACF Pro isn't active. The theme still renders (pages
 * fall back to their normal content) but modules and settings need it.
 */
function bsup_acf_missing_notice() {
	if ( bsup_has_acf() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'The Support Desk theme needs Advanced Custom Fields Pro for its page modules and site settings. Pages show their plain content until it is activated.', 'support-desk' ) . '</p></div>';
}
add_action( 'admin_notices', 'bsup_acf_missing_notice' );

/**
 * Site settings field keys, by name. Used to move values saved in the
 * Customiser (theme versions 0.2.x) into the options page.
 *
 * @return array<string,string> Field name => field key.
 */
function bsup_option_field_keys() {
	return array(
		'site_footer_logo'  => 'field_bso_footer_logo',
		'header_label'      => 'field_bsup_header_label',
		'footer_tagline'    => 'field_bso_footer_tagline',
		'color_tint'        => 'field_bsup_color_tint',
		'nav_cta_text'      => 'field_bso_nav_cta_text',
		'nav_cta_url'       => 'field_bso_nav_cta_url',
		'contact_email'     => 'field_bso_email',
		'contact_telephone' => 'field_bso_phone',
		'support_hours'     => 'field_bsup_hours',
		'main_site_url'     => 'field_bsup_main_site',
		'main_site_label'   => 'field_bsup_main_site_label',
		'help_title'        => 'field_bsup_help_title',
		'help_lead'         => 'field_bsup_help_lead',
		'copyright_text'    => 'field_bso_copyright',
		'credit_text'       => 'field_bsup_credit_text',
		'credit_url'        => 'field_bsup_credit_url',
	);
}

/**
 * One-time move of Customiser settings (theme mods bsup_{name}) into the
 * Site settings page. Only fills options that are still empty; the theme
 * mods are left in place. Runs once, when ACF is active.
 */
function bsup_move_customizer_settings() {
	if ( ! bsup_has_acf() || ! function_exists( 'update_field' ) || get_option( 'bsup_customizer_moved' ) ) {
		return;
	}

	try {
		foreach ( bsup_option_field_keys() as $name => $key ) {
			$value = get_theme_mod( 'bsup_' . $name, '' );
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			$current = get_field( $name, 'option', false );
			if ( '' !== (string) $current && null !== $current ) {
				continue;
			}
			update_field( $key, $value, 'option' );
		}
	} catch ( Throwable $e ) {
		error_log( 'Support Desk theme: moving Customiser settings failed: ' . $e->getMessage() );
	}

	update_option( 'bsup_customizer_moved', time(), false );
}
add_action( 'admin_init', 'bsup_move_customizer_settings' );
