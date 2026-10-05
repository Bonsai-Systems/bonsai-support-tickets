<?php
/**
 * Help Search Hero — the support home page header: big heading, help
 * centre search, and optional "popular articles" links.
 *
 * Block: support-desk/help-search-hero (attributes arrive as $args).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_badge       = bsup_arg( $args, 'hero_badge' );
$bsup_title       = bsup_arg( $args, 'hero_title' );
$bsup_lead        = bsup_arg( $args, 'hero_lead' );
$bsup_placeholder = bsup_arg( $args, 'search_placeholder' );
$bsup_popular     = bsup_arg( $args, 'popular_articles' );
$bsup_bg          = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( ! $bsup_title ) {
	$bsup_title = __( 'How can we help', 'support-desk' );
}
?>
<section class="help-search-hero-module<?php echo esc_attr( $bsup_bg ); ?>">
	<div class="container">
		<div class="help-search-hero-module__inner reveal-on-scroll">
			<?php if ( $bsup_badge ) : ?>
				<span class="section-tag"><?php echo esc_html( $bsup_badge ); ?></span>
			<?php endif; ?>

			<h1 class="help-search-hero-module__title display-heading<?php echo esc_attr( bsup_stop_class( $bsup_title ) ); ?>"><?php echo esc_html( $bsup_title ); ?></h1>

			<?php if ( $bsup_lead ) : ?>
				<p class="help-search-hero-module__lead"><?php echo esc_html( $bsup_lead ); ?></p>
			<?php endif; ?>

			<?php
			$bsup_search_args = array( 'size' => 'large' );
			if ( $bsup_placeholder ) {
				$bsup_search_args['placeholder'] = $bsup_placeholder;
			}
			get_template_part( 'template-parts/help/search-form', null, $bsup_search_args );
			?>

			<?php if ( $bsup_popular ) : ?>
				<?php $bsup_popular_id = bsup_uid( 'popular' ); ?>
				<div class="help-search-hero-module__popular">
					<span class="help-search-hero-module__popular-label" id="<?php echo esc_attr( $bsup_popular_id ); ?>"><?php esc_html_e( 'Popular:', 'support-desk' ); ?></span>
					<ul class="help-search-hero-module__popular-list" aria-labelledby="<?php echo esc_attr( $bsup_popular_id ); ?>">
						<?php foreach ( $bsup_popular as $bsup_article ) : ?>
							<?php
							$bsup_article = get_post( $bsup_article );
							if ( ! $bsup_article || 'publish' !== $bsup_article->post_status ) {
								continue;
							}
							?>
							<li><a href="<?php echo esc_url( get_permalink( $bsup_article ) ); ?>"><?php echo esc_html( get_the_title( $bsup_article ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
