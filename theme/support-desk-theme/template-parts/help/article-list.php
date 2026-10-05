<?php
/**
 * List of help articles (or search results) from the main query.
 *
 * Args:
 *  - empty_message (string)
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_args = wp_parse_args(
	$args ?? array(),
	array(
		'empty_message' => __( 'Nothing here yet.', 'support-desk' ),
	)
);
?>
<?php if ( have_posts() ) : ?>
	<ol class="article-list">
		<?php
		while ( have_posts() ) :
			the_post();
			$bsup_topics = get_the_terms( get_the_ID(), 'bst_article_topic' );
			?>
			<li class="article-list__item">
				<a class="article-list__link" href="<?php the_permalink(); ?>">
					<span class="article-list__title"><?php the_title(); ?></span>
					<?php if ( has_excerpt() || get_the_content() ) : ?>
						<span class="article-list__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24 ) ); ?></span>
					<?php endif; ?>
					<?php if ( $bsup_topics && ! is_wp_error( $bsup_topics ) && ! is_tax( 'bst_article_topic' ) ) : ?>
						<span class="article-list__meta"><?php echo esc_html( $bsup_topics[0]->name ); ?></span>
					<?php elseif ( 'page' === get_post_type() ) : ?>
						<span class="article-list__meta"><?php esc_html_e( 'Page', 'support-desk' ); ?></span>
					<?php endif; ?>
				</a>
			</li>
		<?php endwhile; ?>
	</ol>

	<?php
	the_posts_pagination(
		array(
			'mid_size'           => 1,
			'prev_text'          => __( 'Previous', 'support-desk' ),
			'next_text'          => __( 'Next', 'support-desk' ),
			'screen_reader_text' => __( 'More results', 'support-desk' ),
			'class'              => 'bsup-pagination',
		)
	);
	?>
<?php else : ?>
	<p class="article-list__empty"><?php echo esc_html( $bsup_args['empty_message'] ); ?></p>
<?php endif; ?>
