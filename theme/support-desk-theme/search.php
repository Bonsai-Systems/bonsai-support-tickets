<?php
/**
 * Search results (help articles, or help articles + pages).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

global $wp_query;

get_header();
?>
<main id="main" class="site-main help-page">
	<section class="help-hero help-hero--compact">
		<div class="container">
			<div class="help-hero__inner">
				<span class="section-tag"><?php esc_html_e( 'Search', 'support-desk' ); ?></span>
				<h1 class="help-hero__title display-heading">
					<?php
					if ( get_search_query() ) {
						/* translators: 1: number of results, 2: search term. */
						printf( esc_html( _n( '%1$d result for “%2$s”', '%1$d results for “%2$s”', (int) $wp_query->found_posts, 'support-desk' ) ), (int) $wp_query->found_posts, esc_html( get_search_query() ) );
					} else {
						esc_html_e( 'Search the help centre', 'support-desk' );
					}
					?>
				</h1>
				<?php get_template_part( 'template-parts/help/search-form', null, array( 'size' => 'large' ) ); ?>
			</div>
		</div>
	</section>

	<div class="container">
		<div class="help-layout">
			<div class="help-main">
				<?php get_template_part( 'template-parts/help/article-list', null, array( 'empty_message' => __( 'No articles matched that search. Try different words, or browse the help centre.', 'support-desk' ) ) ); ?>
				<p><a class="text-link" href="<?php echo esc_url( bsup_help_url() ); ?>"><?php esc_html_e( 'Browse all help topics', 'support-desk' ); ?> <span aria-hidden="true">&#8594;</span></a></p>
			</div>
			<aside class="help-sidebar" aria-label="<?php esc_attr_e( 'Get help', 'support-desk' ); ?>">
				<?php get_template_part( 'template-parts/help/still-stuck' ); ?>
			</aside>
		</div>
	</div>
</main>
<?php
get_footer();
