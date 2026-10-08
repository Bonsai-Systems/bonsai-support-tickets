<?php
/**
 * Help centre search form. Searches help articles only.
 *
 * Args (get_template_part 3rd param):
 *  - placeholder (string)
 *  - label       (string) Visible label; visually hidden when 'hide_label' is true.
 *  - hide_label  (bool)
 *  - size        (string) 'large' for the hero, '' for inline.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_args = wp_parse_args(
	$args ?? array(),
	array(
		'placeholder' => __( 'e.g. update opening hours', 'support-desk' ),
		'label'       => __( 'Search the help centre', 'support-desk' ),
		'hide_label'  => true,
		'size'        => '',
	)
);

$bsup_id = bsup_uid( 'help-search' );
?>
<form class="help-search<?php echo 'large' === $bsup_args['size'] ? ' help-search--large' : ''; ?>" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label for="<?php echo esc_attr( $bsup_id ); ?>" class="help-search__label<?php echo $bsup_args['hide_label'] ? ' visually-hidden' : ''; ?>"><?php echo esc_html( $bsup_args['label'] ); ?></label>
	<div class="help-search__row">
		<span class="help-search__icon"><?php echo wp_kses( bsup_icon( 'search' ), bsup_svg_kses() ); ?></span>
		<input class="help-search__input" type="search" id="<?php echo esc_attr( $bsup_id ); ?>" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php echo esc_attr( $bsup_args['placeholder'] ); ?>" autocomplete="off">
		<input type="hidden" name="post_type" value="bst_article">
		<button type="submit" class="btn btn-primary help-search__submit"><?php esc_html_e( 'Search', 'support-desk' ); ?></button>
	</div>
</form>
