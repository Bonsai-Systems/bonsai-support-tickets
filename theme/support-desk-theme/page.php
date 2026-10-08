<?php
/**
 * Pages. Pages built with page modules (ACF page builder) print them full
 * width (each module is its own section); any other page, or every page
 * while ACF is inactive, gets the plain title + content layout.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main">
	<?php
	while ( have_posts() ) :
		the_post();
		if ( ! bsup_render_page_builder() ) {
			get_template_part( 'template-parts/content/content', 'page' );
		}
	endwhile;
	?>
</main>
<?php
get_footer();
