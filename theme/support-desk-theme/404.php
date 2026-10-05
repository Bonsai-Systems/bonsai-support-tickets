<?php
/**
 * 404.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main help-page">
	<section class="help-hero">
		<div class="container">
			<div class="help-hero__inner">
				<span class="section-tag"><?php esc_html_e( 'Error 404', 'support-desk' ); ?></span>
				<h1 class="help-hero__title display-heading has-stop"><?php esc_html_e( 'Page not found', 'support-desk' ); ?></h1>
				<p class="help-hero__lead"><?php esc_html_e( 'The page you were looking for has moved or no longer exists. Try a search, or use one of the links below.', 'support-desk' ); ?></p>
				<?php get_template_part( 'template-parts/help/search-form', null, array( 'size' => 'large' ) ); ?>
				<div class="help-hero__actions">
					<a class="btn btn-primary" href="<?php echo esc_url( bsup_help_url() ); ?>"><?php esc_html_e( 'Help centre', 'support-desk' ); ?></a>
					<a class="btn btn-secondary" href="<?php echo esc_url( bsup_submit_url() ); ?>"><?php esc_html_e( 'Submit a request', 'support-desk' ); ?></a>
				</div>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
