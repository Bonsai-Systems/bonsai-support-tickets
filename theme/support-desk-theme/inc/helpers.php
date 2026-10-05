<?php
/**
 * Helpers used across templates and modules.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * A theme setting (Appearance → Customise), stored as a theme mod with a
 * bsup_ prefix. Returns $fallback when it's empty, so templates always
 * have something sensible to show.
 *
 * @param string $name     Setting name, e.g. contact_email (see bsup_customizer_settings()).
 * @param mixed  $fallback Fallback value.
 * @return mixed
 */
function bsup_option( $name, $fallback = '' ) {
	$value = get_theme_mod( 'bsup_' . $name, '' );
	return ( null === $value || '' === $value || false === $value || array() === $value ) ? $fallback : $value;
}

/**
 * A block attribute or field value, or $fallback when missing or empty.
 * Module templates receive their block's attributes as $args.
 *
 * @param array  $args     Attributes.
 * @param string $name     Key.
 * @param mixed  $fallback Fallback.
 * @return mixed
 */
function bsup_arg( $args, $name, $fallback = '' ) {
	if ( ! is_array( $args ) || ! isset( $args[ $name ] ) || '' === $args[ $name ] || array() === $args[ $name ] ) {
		return $fallback;
	}
	return $args[ $name ];
}

/**
 * Background class for a module from its background_style sub field.
 *
 * @param string $style default|warm|black|blue.
 * @return string Leading-space class string, or ''.
 */
function bsup_bg_class( $style ) {
	$allowed = array( 'warm', 'black', 'blue' );
	return in_array( $style, $allowed, true ) ? ' bg--' . $style : '';
}

/**
 * Class for the accent-coloured full stop after display headings
 * ("Meet the team."). Skipped when the heading already ends in
 * punctuation, so "Need help?" doesn't become "Need help?.".
 *
 * @param string $text Heading text.
 * @return string ' has-stop' or ''.
 */
function bsup_stop_class( $text ) {
	return preg_match( '/[.?!:…]\s*$/u', (string) $text ) ? '' : ' has-stop';
}

/**
 * Unique ID for module elements, so two copies of a module on one page
 * never share IDs (aria-controls, aria-labelledby).
 *
 * @param string $prefix Prefix.
 * @return string
 */
function bsup_uid( $prefix ) {
	static $count = 0;
	++$count;
	return sanitize_html_class( $prefix ) . '-' . $count;
}

/**
 * Output a link (url, title, target) as a button.
 *
 * @param array|null $link  Link array (url, title, target).
 * @param string     $class Button classes.
 * @param bool       $arrow Show the arrow icon.
 */
function bsup_link_button( $link, $class = 'btn btn-primary', $arrow = false ) {
	if ( empty( $link['url'] ) ) {
		return;
	}
	$title  = ! empty( $link['title'] ) ? $link['title'] : $link['url'];
	$target = ! empty( $link['target'] ) ? $link['target'] : '';
	?>
	<a href="<?php echo esc_url( $link['url'] ); ?>" class="<?php echo esc_attr( $class ); ?>"<?php echo $target ? ' target="' . esc_attr( $target ) . '" rel="noopener noreferrer"' : ''; ?>>
		<?php echo esc_html( $title ); ?>
		<?php if ( $arrow ) : ?>
			<span class="btn-icon" aria-hidden="true">&#8594;</span>
		<?php endif; ?>
		<?php if ( '_blank' === $target ) : ?>
			<span class="visually-hidden"><?php esc_html_e( '(opens in a new tab)', 'support-desk' ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

/**
 * Sanitise iframe/embed output from a field.
 * Use instead of esc_html() / wp_kses_post() for embed fields.
 *
 * @param string $embed Raw HTML.
 * @return string
 */
function bsup_kses_iframe( $embed ) {
	if ( empty( $embed ) ) {
		return '';
	}
	return wp_kses(
		$embed,
		array(
			'iframe' => array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'title'           => true,
				'frameborder'     => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'class'           => true,
				'id'              => true,
				'name'            => true,
			),
			'div'    => array(
				'class' => true,
				'id'    => true,
			),
		)
	);
}

if ( ! function_exists( 'bonsai_kses_iframe' ) ) {
	/**
	 * Alias kept for templates and child themes that use this name.
	 *
	 * @param string $embed Raw HTML.
	 * @return string
	 */
	function bonsai_kses_iframe( $embed ) {
		return bsup_kses_iframe( $embed );
	}
}

/**
 * Inline SVG icons used by the support modules. Decorative only —
 * always paired with visible text.
 *
 * @param string $name Icon key.
 * @return string SVG markup (static, safe).
 */
function bsup_icon( $name ) {
	$paths = array(
		'ticket'   => '<path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/><path d="M13 5v2M13 11v2M13 17v2"/>',
		'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
		'book'     => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
		'mail'     => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/>',
		'phone'    => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
		'pulse'    => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="18" rx="1"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'arrow'    => '<path d="M5 12h14M13 5l7 7-7 7"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		$name = 'arrow';
	}
	return '<svg class="bsup-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Allowed SVG tags for wp_kses when echoing bsup_icon().
 *
 * @return array
 */
function bsup_svg_kses() {
	$shape = array(
		'd'      => true,
		'x'      => true,
		'y'      => true,
		'cx'     => true,
		'cy'     => true,
		'r'      => true,
		'rx'     => true,
		'width'  => true,
		'height' => true,
	);
	return array(
		'svg'    => array(
			'class'           => true,
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
		),
		'path'   => $shape,
		'circle' => $shape,
		'rect'   => $shape,
	);
}
