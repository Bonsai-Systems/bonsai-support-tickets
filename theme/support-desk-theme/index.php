<?php
/**
 * Fallback template (any view without a more specific template).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main help-page">
	<section class="help-hero help-hero--compact">
		<div class="container">
			<div class="help-hero__inner">
				<h1 class="help-hero__title display-heading has-stop">
					<?php
					if ( is_archive() ) {
						echo esc_html( wp_strip_all_tags( get_the_archive_title() ) );
					} elseif ( is_singular() ) {
						single_post_title();
					} else {
						bloginfo( 'name' );
					}
					?>
				</h1>
			</div>
		</div>
	</section>

	<div class="container">
		<div class="help-main help-main--solo">
			<?php if ( is_singular() ) : ?>
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<div class="content-block"><?php the_content(); ?></div>
				<?php endwhile; ?>
			<?php else : ?>
				<?php get_template_part( 'template-parts/help/article-list' ); ?>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
