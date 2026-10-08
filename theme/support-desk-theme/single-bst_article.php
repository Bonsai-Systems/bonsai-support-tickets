<?php
/**
 * Single help article: breadcrumbs, article, related articles in the
 * same topic, and a "still stuck" call to action.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main help-page">
	<?php
	while ( have_posts() ) :
		the_post();
		$bsup_terms   = get_the_terms( get_the_ID(), 'bst_article_topic' );
		$bsup_topic   = ( $bsup_terms && ! is_wp_error( $bsup_terms ) ) ? $bsup_terms[0] : null;
		$bsup_related = $bsup_topic ? get_posts(
			array(
				'post_type'      => 'bst_article',
				'posts_per_page' => 8,
				'post__not_in'   => array( get_the_ID() ),
				'no_found_rows'  => true,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'bst_article_topic',
						'terms'    => $bsup_topic->term_id,
					),
				),
			)
		) : array();
		?>
		<div class="help-header">
			<div class="container">
				<?php get_template_part( 'template-parts/help/breadcrumbs' ); ?>
			</div>
		</div>

		<div class="container">
			<div class="help-layout">
				<article class="help-article">
					<header class="help-article__header">
						<?php if ( $bsup_topic ) : ?>
							<span class="section-tag"><?php echo esc_html( $bsup_topic->name ); ?></span>
						<?php endif; ?>
						<h1 class="help-article__title display-heading<?php echo esc_attr( bsup_stop_class( get_the_title() ) ); ?>"><?php the_title(); ?></h1>
						<p class="help-article__meta">
							<?php
							/* translators: %s: date. */
							printf( esc_html__( 'Updated %s', 'support-desk' ), '<time datetime="' . esc_attr( get_the_modified_date( 'c' ) ) . '">' . esc_html( get_the_modified_date() ) . '</time>' );
							?>
						</p>
					</header>
					<div class="help-article__body content-block">
						<?php the_content(); ?>
					</div>
				</article>

				<aside class="help-sidebar" aria-label="<?php esc_attr_e( 'Related help', 'support-desk' ); ?>">
					<?php if ( $bsup_related ) : ?>
						<div class="help-sidebar__block">
							<h2 class="help-sidebar__title">
								<?php
								/* translators: %s: topic name. */
								echo esc_html( sprintf( __( 'More in %s', 'support-desk' ), $bsup_topic->name ) );
								?>
							</h2>
							<ul class="help-sidebar__list">
								<?php foreach ( $bsup_related as $bsup_post ) : ?>
									<li><a href="<?php echo esc_url( get_permalink( $bsup_post ) ); ?>"><?php echo esc_html( get_the_title( $bsup_post ) ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php get_template_part( 'template-parts/help/still-stuck' ); ?>
				</aside>
			</div>
		</div>
	<?php endwhile; ?>
</main>
<?php
get_footer();
