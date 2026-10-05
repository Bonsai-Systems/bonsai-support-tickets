<?php
/**
 * Help centre landing: search, topics with their articles, and a
 * "still need help?" link to the request form.
 *
 * Override: copy to {theme}/support-desk/help-centre.php
 *
 * @var WP_Term[] $topics     Top-level help topics with articles.
 * @var string    $submit_url Submit-a-request URL.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bst bst-help">
	<form class="bst-help__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="bst-form__label" for="bst-help-search"><?php esc_html_e( 'Search the help centre', 'bonsai-support-tickets' ); ?></label>
		<div class="bst-help__search-row">
			<input class="bst-form__input" type="search" id="bst-help-search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'e.g. update opening hours', 'bonsai-support-tickets' ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( BST_Post_Types::ARTICLE ); ?>">
			<button type="submit" class="bst-btn bst-btn--primary"><?php esc_html_e( 'Search', 'bonsai-support-tickets' ); ?></button>
		</div>
	</form>

	<?php if ( $topics ) : ?>
		<div class="bst-help__topics">
			<?php foreach ( $topics as $bst_topic ) : ?>
				<?php
				$bst_articles = get_posts(
					array(
						'post_type'      => BST_Post_Types::ARTICLE,
						'posts_per_page' => 5,
						'orderby'        => array(
							'menu_order' => 'ASC',
							'title'      => 'ASC',
						),
						'no_found_rows'  => true,
						'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
							array(
								'taxonomy' => BST_Post_Types::ARTICLE_TOPIC,
								'terms'    => $bst_topic->term_id,
							),
						),
					)
				);
				?>
				<section class="bst-help__topic">
					<h2 class="bst-help__topic-title">
						<a href="<?php echo esc_url( get_term_link( $bst_topic ) ); ?>"><?php echo esc_html( $bst_topic->name ); ?></a>
					</h2>
					<?php if ( $bst_topic->description ) : ?>
						<p class="bst-help__topic-intro"><?php echo esc_html( $bst_topic->description ); ?></p>
					<?php endif; ?>
					<ul class="bst-help__list">
						<?php foreach ( $bst_articles as $bst_article ) : ?>
							<li><a href="<?php echo esc_url( get_permalink( $bst_article ) ); ?>"><?php echo esc_html( get_the_title( $bst_article ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $bst_topic->count > count( $bst_articles ) ) : ?>
						<a class="bst-help__more" href="<?php echo esc_url( get_term_link( $bst_topic ) ); ?>">
							<?php
							/* translators: %d: number of articles. */
							echo esc_html( sprintf( __( 'See all %d articles', 'bonsai-support-tickets' ), $bst_topic->count ) );
							?>
						</a>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="bst-help__cta">
		<h2 class="bst__title"><?php esc_html_e( 'Still need help?', 'bonsai-support-tickets' ); ?></h2>
		<p class="bst__lead"><?php esc_html_e( 'Send us a request and the team will get back to you.', 'bonsai-support-tickets' ); ?></p>
		<a class="bst-btn bst-btn--light" href="<?php echo esc_url( $submit_url ); ?>"><?php esc_html_e( 'Submit a request', 'bonsai-support-tickets' ); ?></a>
	</div>
</div>
