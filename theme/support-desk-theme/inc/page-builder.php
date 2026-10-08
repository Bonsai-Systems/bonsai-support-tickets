<?php
/**
 * Page builder: renders the page_builder flexible content field (ACF Pro).
 *
 * Module = layout in acf-json/group_bsup_page_builder.json +
 * template-parts/modules/{layout}.php (receives the row's values as $args) +
 * optional assets/css/modules/{layout}.css. Child themes can override the
 * template or the CSS.
 *
 * Module CSS is enqueued in <head> (by reading which layouts the page uses
 * before rendering), so there's no flash of unstyled modules and no CLS.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Layouts the theme knows about. Anything else is logged and skipped.
 *
 * @return string[]
 */
function bsup_module_layouts() {
	/**
	 * Filters the page builder layouts the theme renders.
	 *
	 * @param string[] $layouts Layout names (template-parts/modules/{layout}.php).
	 */
	return apply_filters(
		'bsup_module_layouts',
		array(
			// Support.
			'help_search_hero_module',
			'support_links_module',
			'help_topics_module',
			'ticket_portal_module',
			'submit_request_module',
			// General.
			'subpage_hero_module',
			'content_block_module',
			'faq_module',
			'feature_cards_module',
			'process_module',
			'cta_compact_module',
			'meet_the_team_module',
		)
	);
}

/**
 * Layout names used on a post, in order, without loading ACF values.
 * ACF stores a flexible content field's meta as an array of layout names.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function bsup_page_layouts( $post_id ) {
	$layouts = get_post_meta( $post_id, 'page_builder', true );
	return is_array( $layouts ) ? array_values( array_filter( $layouts, 'is_string' ) ) : array();
}

/**
 * Enqueue module CSS in <head> for the layouts on this page.
 */
function bsup_enqueue_module_styles() {
	if ( ! is_singular() || ! bsup_has_acf() ) {
		return;
	}

	$layouts = array_intersect( array_unique( bsup_page_layouts( get_queried_object_id() ) ), bsup_module_layouts() );

	foreach ( $layouts as $layout ) {
		bsup_enqueue_module_style( $layout );
	}

	// Help modules reuse the help centre partials (search form, topics grid).
	if ( array_intersect( $layouts, array( 'help_search_hero_module', 'help_topics_module' ) ) ) {
		wp_enqueue_style( 'bsup-help' );
	}

	// Ticket modules output the plugin's templates — load its stylesheet in <head> too.
	if ( array_intersect( $layouts, array( 'ticket_portal_module', 'submit_request_module' ) ) && wp_style_is( 'bst-frontend', 'registered' ) ) {
		wp_enqueue_style( 'bst-frontend' );
	}
}
add_action( 'wp_enqueue_scripts', 'bsup_enqueue_module_styles', 20 );

/**
 * Enqueue one module's stylesheet if it has one.
 *
 * @param string $layout Layout (template) name.
 */
function bsup_enqueue_module_style( $layout ) {
	$rel  = 'assets/css/modules/' . $layout . '.css';
	$path = get_theme_file_path( $rel );
	if ( file_exists( $path ) ) {
		wp_enqueue_style( 'bsup-' . $layout, get_theme_file_uri( $rel ), array( 'bsup-base' ), (string) filemtime( $path ) );
	}
}

/**
 * Render the page builder for the current post.
 *
 * Each row's formatted values (keyed by sub field name) are passed to the
 * module template as $args. A module that throws is logged and skipped,
 * so one broken section never takes the page down.
 *
 * @return bool Whether any module was rendered (false = caller shows fallback content).
 */
function bsup_render_page_builder() {
	if ( ! bsup_has_acf() ) {
		return false;
	}

	$rows = get_field( 'page_builder' );
	if ( ! is_array( $rows ) || ! $rows ) {
		return false;
	}

	$known    = bsup_module_layouts();
	$rendered = false;

	foreach ( $rows as $row ) {
		$layout = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : '';

		if ( ! in_array( $layout, $known, true ) ) {
			error_log( 'Support Desk theme: unknown page builder layout "' . $layout . '" on post ' . get_the_ID() );
			continue;
		}

		$template = locate_template( 'template-parts/modules/' . $layout . '.php' );
		if ( ! $template ) {
			error_log( 'Support Desk theme: no template for layout "' . $layout . '"' );
			continue;
		}

		ob_start();
		try {
			load_template( $template, false, $row );
			echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- module templates escape their own output.
			$rendered = true;
		} catch ( Throwable $e ) {
			ob_end_clean();
			error_log( 'Support Desk theme: layout "' . $layout . '" failed on post ' . get_the_ID() . ': ' . $e->getMessage() );
		}
	}

	return $rendered;
}
