<?php
/**
 * Help centre breadcrumbs: Help centre › Topic › Article.
 * Uses Yoast breadcrumbs instead when Yoast has them switched on.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'yoast_breadcrumb' ) && current_theme_supports( 'yoast-seo-breadcrumbs' ) ) {
	yoast_breadcrumb( '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'support-desk' ) . '">', '</nav>' );
	return;
}

$bsup_crumbs = array(
	array(
		'url'   => bsup_help_url(),
		'label' => __( 'Help centre', 'support-desk' ),
	),
);

if ( is_singular( 'bst_article' ) ) {
	$bsup_terms = get_the_terms( get_the_ID(), 'bst_article_topic' );
	if ( $bsup_terms && ! is_wp_error( $bsup_terms ) ) {
		$bsup_link = get_term_link( $bsup_terms[0] );
		if ( ! is_wp_error( $bsup_link ) ) {
			$bsup_crumbs[] = array(
				'url'   => $bsup_link,
				'label' => $bsup_terms[0]->name,
			);
		}
	}
	$bsup_crumbs[] = array(
		'url'   => '',
		'label' => get_the_title(),
	);
} elseif ( is_tax( 'bst_article_topic' ) ) {
	$bsup_crumbs[] = array(
		'url'   => '',
		'label' => single_term_title( '', false ),
	);
}
?>
<nav class="breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'support-desk' ); ?>">
	<ol class="breadcrumbs__list">
		<?php foreach ( $bsup_crumbs as $bsup_crumb ) : ?>
			<li class="breadcrumbs__item">
				<?php if ( $bsup_crumb['url'] ) : ?>
					<a href="<?php echo esc_url( $bsup_crumb['url'] ); ?>"><?php echo esc_html( $bsup_crumb['label'] ); ?></a>
				<?php else : ?>
					<span aria-current="page"><?php echo esc_html( $bsup_crumb['label'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
