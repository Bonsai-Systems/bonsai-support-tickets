<?php
/**
 * Theme settings in Appearance → Customise (replaces the old ACF options
 * page). Stored as theme mods named bsup_{name}; read with bsup_option().
 *
 * The logo lives in Site Identity (custom-logo). Brand name and colours come
 * from the Support Desk plugin (Support → Settings → Appearance) so they're
 * set once for the portal, emails and this theme.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings, by section. Each: label, type (text|textarea|email|url|image|color),
 * optional description and default.
 *
 * @return array<string,array>
 */
function bsup_customizer_settings() {
	return array(
		'bsup_header' => array(
			'title'    => __( 'Header', 'support-desk' ),
			'settings' => array(
				'header_label' => array(
					'label'       => __( 'Label beside the logo', 'support-desk' ),
					'type'        => 'text',
					'description' => __( 'E.g. "Support". Leave blank for none.', 'support-desk' ),
				),
				'nav_cta_text' => array(
					'label' => __( 'Button text', 'support-desk' ),
					'type'  => 'text',
				),
				'nav_cta_url'  => array(
					'label'       => __( 'Button link', 'support-desk' ),
					'type'        => 'url',
					'description' => __( 'Blank goes to your Submit a request page.', 'support-desk' ),
				),
			),
		),
		'bsup_footer' => array(
			'title'    => __( 'Footer and contact details', 'support-desk' ),
			'settings' => array(
				'site_footer_logo'  => array(
					'label'       => __( 'Footer logo', 'support-desk' ),
					'type'        => 'image',
					'description' => __( 'A version that reads on a dark background. Blank shows your support name.', 'support-desk' ),
				),
				'footer_tagline'    => array(
					'label' => __( 'Tagline', 'support-desk' ),
					'type'  => 'textarea',
				),
				'support_hours'     => array(
					'label'       => __( 'Support hours', 'support-desk' ),
					'type'        => 'text',
					'description' => __( 'E.g. "Monday to Friday, 9am to 5.30pm". Also shown beside the request form.', 'support-desk' ),
				),
				'contact_email'     => array(
					'label' => __( 'Contact email', 'support-desk' ),
					'type'  => 'email',
				),
				'contact_telephone' => array(
					'label' => __( 'Contact phone', 'support-desk' ),
					'type'  => 'text',
				),
				'main_site_url'     => array(
					'label'       => __( 'Main website link', 'support-desk' ),
					'type'        => 'url',
					'description' => __( 'Your company website, linked in the footer. Blank hides it.', 'support-desk' ),
				),
				'main_site_label'   => array(
					'label' => __( 'Main website link text', 'support-desk' ),
					'type'  => 'text',
				),
				'copyright_text'    => array(
					'label'       => __( 'Copyright name', 'support-desk' ),
					'type'        => 'text',
					'description' => __( 'Shown after "© year". Blank uses your support name.', 'support-desk' ),
				),
				'credit_text'       => array(
					'label'       => __( 'Credit text', 'support-desk' ),
					'type'        => 'text',
					'description' => __( 'Optional, e.g. "Website by Example Ltd". Blank hides it.', 'support-desk' ),
				),
				'credit_url'        => array(
					'label' => __( 'Credit link', 'support-desk' ),
					'type'  => 'url',
				),
			),
		),
		'bsup_help'   => array(
			'title'    => __( 'Help centre', 'support-desk' ),
			'settings' => array(
				'help_title' => array(
					'label' => __( 'Help centre heading', 'support-desk' ),
					'type'  => 'text',
				),
				'help_lead'  => array(
					'label' => __( 'Help centre intro', 'support-desk' ),
					'type'  => 'textarea',
				),
			),
		),
		'colors'      => array(
			'title'    => '', // Core's Colours section.
			'settings' => array(
				'color_tint' => array(
					'label'       => __( 'Tint background', 'support-desk' ),
					'type'        => 'color',
					'description' => __( 'The light "tint" section background. Your other colours are set in Support → Settings → Appearance.', 'support-desk' ),
					'default'     => '#eef2ff',
				),
			),
		),
	);
}

/**
 * Register the panel, sections and controls.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function bsup_customize_register( $wp_customize ) {
	$wp_customize->add_panel(
		'bsup_theme',
		array(
			'title'    => __( 'Support Desk theme', 'support-desk' ),
			'priority' => 30,
		)
	);

	foreach ( bsup_customizer_settings() as $section_id => $section ) {
		if ( '' !== $section['title'] ) {
			$wp_customize->add_section(
				$section_id,
				array(
					'title' => $section['title'],
					'panel' => 'bsup_theme',
				)
			);
		}

		foreach ( $section['settings'] as $name => $field ) {
			$id = 'bsup_' . $name;
			$wp_customize->add_setting(
				$id,
				array(
					'default'           => $field['default'] ?? '',
					'sanitize_callback' => bsup_customizer_sanitizer( $field['type'] ),
					'transport'         => 'refresh',
				)
			);

			$args = array(
				'label'       => $field['label'],
				'description' => $field['description'] ?? '',
				'section'     => $section_id,
			);

			if ( 'image' === $field['type'] ) {
				$args['mime_type'] = 'image';
				$wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, $id, $args ) );
			} elseif ( 'color' === $field['type'] ) {
				$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $id, $args ) );
			} else {
				$args['type'] = $field['type'];
				$wp_customize->add_control( $id, $args );
			}
		}
	}
}
add_action( 'customize_register', 'bsup_customize_register' );

/**
 * Sanitiser for a field type.
 *
 * @param string $type Field type.
 * @return callable
 */
function bsup_customizer_sanitizer( $type ) {
	switch ( $type ) {
		case 'textarea':
			return 'sanitize_textarea_field';
		case 'email':
			return 'sanitize_email';
		case 'url':
			return 'esc_url_raw';
		case 'image':
			return 'absint';
		case 'color':
			return 'sanitize_hex_color';
		default:
			return 'sanitize_text_field';
	}
}
