<?php
/**
 * /help/ — the help centre: search, then every topic with its articles.
 *
 * Intro text comes from Site settings → Help centre (falls back to defaults).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_title = bsup_option( 'help_title', __( 'How can we help', 'support-desk' ) );
$bsup_lead  = bsup_option( 'help_lead', __( 'Answers to common questions, step by step.', 'support-desk' ) );

get_header();
?>
<main id="main" class="site-main help-page">
	<section class="help-hero">
		<div class="container">
			<div class="help-hero__inner reveal-on-scroll">
				<span class="section-tag"><?php esc_html_e( 'Help centre', 'support-desk' ); ?></span>
				<h1 class="help-hero__title display-heading<?php echo esc_attr( bsup_stop_class( $bsup_title ) ); ?>"><?php echo esc_html( $bsup_title ); ?></h1>
				<?php if ( $bsup_lead ) : ?>
					<p class="help-hero__lead"><?php echo esc_html( $bsup_lead ); ?></p>
				<?php endif; ?>
				<?php get_template_part( 'template-parts/help/search-form', null, array( 'size' => 'large' ) ); ?>
			</div>
		</div>
	</section>

	<section class="help-section">
		<div class="container">
			<?php get_template_part( 'template-parts/help/topics-grid', null, array( 'per_topic' => 6 ) ); ?>
		</div>
	</section>

	<?php
	// Articles with no topic would otherwise be unreachable from here.
	$bsup_untopiced = get_posts(
		array(
			'post_type'      => 'bst_article',
			'posts_per_page' => 20,
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'bst_article_topic',
					'operator' => 'NOT EXISTS',
				),
			),
		)
	);
	?>
	<?php if ( $bsup_untopiced ) : ?>
		<section class="help-section help-section--tight">
			<div class="container">
				<h2 class="help-section__title"><?php esc_html_e( 'Other articles', 'support-desk' ); ?></h2>
				<ul class="help-topic__list help-topic__list--columns">
					<?php foreach ( $bsup_untopiced as $bsup_post ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $bsup_post ) ); ?>"><?php echo esc_html( get_the_title( $bsup_post ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
	<?php endif; ?>

	<section class="help-section help-section--cta">
		<div class="container">
			<?php get_template_part( 'template-parts/help/still-stuck' ); ?>
		</div>
	</section>
</main>
<?php
get_footer();
