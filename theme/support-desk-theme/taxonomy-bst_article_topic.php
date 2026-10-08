<?php
/**
 * Help topic: all articles in one topic.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_term = get_queried_object();

get_header();
?>
<main id="main" class="site-main help-page">
	<div class="help-header">
		<div class="container">
			<?php get_template_part( 'template-parts/help/breadcrumbs' ); ?>
		</div>
	</div>

	<div class="container">
		<div class="help-layout">
			<div class="help-main">
				<header class="help-article__header">
					<span class="section-tag"><?php esc_html_e( 'Help topic', 'support-desk' ); ?></span>
					<h1 class="help-article__title display-heading<?php echo esc_attr( bsup_stop_class( $bsup_term->name ) ); ?>"><?php echo esc_html( $bsup_term->name ); ?></h1>
					<?php if ( $bsup_term->description ) : ?>
						<p class="help-hero__lead"><?php echo esc_html( $bsup_term->description ); ?></p>
					<?php endif; ?>
				</header>
				<?php get_template_part( 'template-parts/help/article-list', null, array( 'empty_message' => __( 'There are no articles in this topic yet.', 'support-desk' ) ) ); ?>
			</div>

			<aside class="help-sidebar" aria-label="<?php esc_attr_e( 'Help centre', 'support-desk' ); ?>">
				<div class="help-sidebar__block">
					<?php get_template_part( 'template-parts/help/search-form', null, array( 'hide_label' => false ) ); ?>
				</div>
				<?php
				$bsup_topics = get_terms(
					array(
						'taxonomy'   => 'bst_article_topic',
						'hide_empty' => true,
						'exclude'    => array( $bsup_term->term_id ),
					)
				);
				?>
				<?php if ( $bsup_topics && ! is_wp_error( $bsup_topics ) ) : ?>
					<div class="help-sidebar__block">
						<h2 class="help-sidebar__title"><?php esc_html_e( 'Other topics', 'support-desk' ); ?></h2>
						<ul class="help-sidebar__list">
							<?php foreach ( $bsup_topics as $bsup_topic ) : ?>
								<li><a href="<?php echo esc_url( get_term_link( $bsup_topic ) ); ?>"><?php echo esc_html( $bsup_topic->name ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php get_template_part( 'template-parts/help/still-stuck' ); ?>
			</aside>
		</div>
	</div>
</main>
<?php
get_footer();
