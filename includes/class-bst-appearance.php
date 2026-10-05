<?php
/**
 * Brand colours: the editable core set used by the front-end CSS and the
 * HTML emails, so the plugin can be rebranded per install.
 *
 * Saved as `color_*` keys in the `bst_settings` option. Blank means "use the
 * default", which on the front end lets the theme's own --bonsai-* custom
 * properties through. Saving a value equal to the default stores blank for
 * the same reason.
 *
 * Status colours (success/warning/error), borders and muted greys are not
 * editable, so they stay accessible whatever a client picks.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brand colour settings, CSS output and contrast checks.
 */
class BST_Appearance {

	/**
	 * WCAG AA contrast for normal-size text.
	 */
	const MIN_CONTRAST = 4.5;

	/**
	 * Default hex per setting key. Kept separate from fields() so it can be
	 * used before the text domain loads (BST_Settings::defaults()).
	 *
	 * @return array<string,string>
	 */
	public static function default_colors() {
		return array(
			'color_accent'       => '#ee4367',
			'color_accent_hover' => '#d23253',
			'color_accent_text'  => '#c21f48',
			'color_ink'          => '#000000',
			'color_text'         => '#333333',
			'color_background'   => '#faf8f5',
			'color_surface'      => '#ffffff',
		);
	}

	/**
	 * Editable colours, in screen order.
	 *
	 * Each: label, description, default (hex), var (front-end custom property).
	 *
	 * @return array<string,array>
	 */
	public static function fields() {
		$defaults = self::default_colors();

		$fields = array(
			'color_accent'       => array(
				'label'       => __( 'Accent', 'bonsai-support-tickets' ),
				'description' => __( 'Focus rings, link underlines, team reply edges and the bar under the email logo.', 'bonsai-support-tickets' ),
				'var'         => '--bst-accent',
			),
			'color_accent_hover' => array(
				'label'       => __( 'Button hover', 'bonsai-support-tickets' ),
				'description' => __( 'Buttons on hover. Card-coloured text sits on it, so keep it dark enough to read.', 'bonsai-support-tickets' ),
				'var'         => '--bst-accent-hover',
			),
			'color_accent_text'  => array(
				'label'       => __( 'Accent text', 'bonsai-support-tickets' ),
				'description' => __( 'Small coloured text: section tags, required markers and the ticket reference in emails.', 'bonsai-support-tickets' ),
				'var'         => '--bst-accent-text',
			),
			'color_ink'          => array(
				'label'       => __( 'Buttons and headings', 'bonsai-support-tickets' ),
				'description' => __( 'Button background, headings, labels and the email button.', 'bonsai-support-tickets' ),
				'var'         => '--bst-black',
			),
			'color_text'         => array(
				'label'       => __( 'Body text', 'bonsai-support-tickets' ),
				'description' => __( 'Main text on the front end and in emails.', 'bonsai-support-tickets' ),
				'var'         => '--bst-grey-dark',
			),
			'color_background'   => array(
				'label'       => __( 'Page background', 'bonsai-support-tickets' ),
				'description' => __( 'Team replies, table headers, button text and the email background.', 'bonsai-support-tickets' ),
				'var'         => '--bst-warm',
			),
			'color_surface'      => array(
				'label'       => __( 'Cards', 'bonsai-support-tickets' ),
				'description' => __( 'Forms, tables, messages and the email body. Front-end links use this colour too.', 'bonsai-support-tickets' ),
				'var'         => '--bst-white',
			),
		);

		foreach ( $fields as $key => $field ) {
			$fields[ $key ]['default'] = $defaults[ $key ];
		}

		return $fields;
	}

	/**
	 * Sanitise one posted colour. Invalid, blank or default values store ''.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Posted value.
	 * @return string Hex colour or ''.
	 */
	public static function sanitize( $key, $value ) {
		$defaults = self::default_colors();
		$hex      = sanitize_hex_color( self::normalize( (string) $value ) );

		if ( ! $hex || ( isset( $defaults[ $key ] ) && self::normalize( $defaults[ $key ] ) === self::normalize( $hex ) ) ) {
			return '';
		}

		return strtolower( $hex );
	}

	/**
	 * Effective colour: the saved value, or the default.
	 *
	 * @param string $key Setting key, e.g. 'color_accent'.
	 * @return string Hex colour.
	 */
	public static function color( $key ) {
		$saved    = BST_Settings::get( $key );
		$defaults = self::default_colors();

		if ( is_string( $saved ) && '' !== $saved ) {
			return $saved;
		}

		return $defaults[ $key ] ?? '';
	}

	/**
	 * Inline CSS overriding the front-end tokens for saved colours only.
	 * Printed after bst-frontend.css with the same selector, so it wins.
	 *
	 * @return string '' when nothing is customised.
	 */
	public static function inline_css() {
		$rules = array();

		foreach ( self::fields() as $key => $field ) {
			$saved = BST_Settings::get( $key );
			// Re-validate on output in case the option was edited directly.
			$hex = is_string( $saved ) ? sanitize_hex_color( $saved ) : '';
			if ( $hex ) {
				$rules[] = $field['var'] . ':' . $hex . ';';
			}
		}

		return $rules ? '.bst{' . implode( '', $rules ) . '}' : '';
	}

	/**
	 * Colours for the email layout template.
	 *
	 * @return array<string,string> accent, accent_text, ink, text, background, surface.
	 */
	public static function email_colors() {
		return array(
			'accent'      => self::color( 'color_accent' ),
			'accent_text' => self::color( 'color_accent_text' ),
			'ink'         => self::color( 'color_ink' ),
			'text'        => self::color( 'color_text' ),
			'background'  => self::color( 'color_background' ),
			'surface'     => self::color( 'color_surface' ),
		);
	}

	/**
	 * Colour pairs that fail WCAG AA with the effective colours.
	 *
	 * @return string[] Human-readable warnings.
	 */
	public static function contrast_warnings() {
		$fields = self::fields();
		$pairs  = array(
			// Foreground key, background key.
			array( 'color_background', 'color_ink' ),         // Button text on the button.
			array( 'color_surface', 'color_accent_hover' ),   // Button text on hover.
			array( 'color_accent_text', 'color_background' ), // Tags on the page.
			array( 'color_text', 'color_surface' ),           // Body text on cards.
			array( 'color_ink', 'color_surface' ),            // Headings on cards.
		);

		$warnings = array();
		foreach ( $pairs as $pair ) {
			$ratio = self::contrast_ratio( self::color( $pair[0] ), self::color( $pair[1] ) );
			if ( $ratio < self::MIN_CONTRAST ) {
				$warnings[] = sprintf(
					/* translators: 1: foreground colour name, 2: background colour name, 3: contrast ratio, 4: required ratio. */
					__( '%1$s on %2$s is %3$s:1. Text needs at least %4$s:1 to be readable (WCAG AA).', 'bonsai-support-tickets' ),
					$fields[ $pair[0] ]['label'],
					$fields[ $pair[1] ]['label'],
					number_format( $ratio, 1 ),
					number_format( self::MIN_CONTRAST, 1 )
				);
			}
		}

		return $warnings;
	}

	/**
	 * WCAG contrast ratio between two hex colours.
	 *
	 * @param string $hex_a Hex colour.
	 * @param string $hex_b Hex colour.
	 * @return float 1 to 21. 21 when either colour can't be parsed, so bad
	 *               input never produces a false warning.
	 */
	public static function contrast_ratio( $hex_a, $hex_b ) {
		$lum_a = self::luminance( $hex_a );
		$lum_b = self::luminance( $hex_b );

		if ( null === $lum_a || null === $lum_b ) {
			return 21.0;
		}

		return ( max( $lum_a, $lum_b ) + 0.05 ) / ( min( $lum_a, $lum_b ) + 0.05 );
	}

	/**
	 * WCAG relative luminance of a hex colour.
	 *
	 * @param string $hex #rgb or #rrggbb.
	 * @return float|null Null when not a hex colour.
	 */
	public static function luminance( $hex ) {
		$hex = ltrim( self::normalize( (string) $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/', $hex ) ) {
			return null;
		}

		$channels = array();
		foreach ( str_split( $hex, 2 ) as $part ) {
			$c          = hexdec( $part ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Lowercase, trimmed, with a leading # (people paste "EE4367").
	 *
	 * @param string $value Colour.
	 * @return string
	 */
	private static function normalize( $value ) {
		$value = strtolower( trim( $value ) );
		return ( '' !== $value && '#' !== $value[0] ) ? '#' . $value : $value;
	}
}
