<?php
/**
 * Pages. Pages built with Support Desk blocks print them full width (each
 * block is its own section); any other page gets the plain title + content
 * layout, so shortcode-only and simple text pages still look right.
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
		if ( bsup_has_modules() ) {
			the_content();
		} else {
			get_template_part( 'template-parts/content/content', 'page' );
		}
	endwhile;
	?>
</main>
<?php
get_footer();
