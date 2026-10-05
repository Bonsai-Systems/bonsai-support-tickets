<?php
/**
 * Help topics grid: one card per topic with its first few articles.
 * Shared by the Help Topics module and the /help/ archive.
 *
 * Args:
 *  - topics   (WP_Term[]) Topics to show. Empty = all top-level topics with articles.
 *  - per_topic (int)      Articles listed per card.
 *  - columns  (int)       2 or 3.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( ! taxonomy_exists( 'bst_article_topic' ) ) {
	bsup_tickets_missing_notice();
	return;
}

$bsup_args = wp_parse_args(
	$args ?? array(),
	array(
		'topics'    => array(),
		'per_topic' => 5,
		'columns'   => 3,
	)
);

$bsup_topics = $bsup_args['topics'];
if ( ! $bsup_topics ) {
	$bsup_topics = get_terms(
		array(
			'taxonomy'   => 'bst_article_topic',
			'hide_empty' => true,
			'parent'     => 0,
		)
	);
	$bsup_topics = is_wp_error( $bsup_topics ) ? array() : $bsup_topics;
}

if ( ! $bsup_topics ) {
	if ( current_user_can( 'edit_posts' ) ) {
		echo '<p class="bsup-editor-notice">' . esc_html__( 'No help topics with articles yet. Add articles under Support → Help articles and give them a topic.', 'support-desk' ) . '</p>';
	}
	return;
}

$bsup_columns = in_array( (int) $bsup_args['columns'], array( 2, 3 ), true ) ? (int) $bsup_args['columns'] : 3;
?>
<div class="help-topics help-topics--cols-<?php echo esc_attr( $bsup_columns ); ?>">
	<?php foreach ( $bsup_topics as $bsup_topic ) : ?>
		<?php
		if ( ! $bsup_topic instanceof WP_Term ) {
			continue;
		}
		$bsup_articles = get_posts(
			array(
				'post_type'      => 'bst_article',
				'posts_per_page' => (int) $bsup_args['per_topic'],
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
		);
		$bsup_link = get_term_link( $bsup_topic );
		?>
		<section class="help-topic">
			<h3 class="help-topic__title">
				<a href="<?php echo esc_url( is_wp_error( $bsup_link ) ? '' : $bsup_link ); ?>"><?php echo esc_html( $bsup_topic->name ); ?></a>
			</h3>
			<?php if ( $bsup_topic->description ) : ?>
				<p class="help-topic__desc"><?php echo esc_html( $bsup_topic->description ); ?></p>
			<?php endif; ?>
			<?php if ( $bsup_articles ) : ?>
				<ul class="help-topic__list">
					<?php foreach ( $bsup_articles as $bsup_article ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $bsup_article ) ); ?>"><?php echo esc_html( get_the_title( $bsup_article ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $bsup_topic->count > count( $bsup_articles ) && ! is_wp_error( $bsup_link ) ) : ?>
				<a class="help-topic__more" href="<?php echo esc_url( $bsup_link ); ?>">
					<?php
					/* translators: 1: number of articles, 2: topic name (screen readers). */
					printf( esc_html__( 'All %1$d articles %2$s', 'support-desk' ), (int) $bsup_topic->count, '<span class="visually-hidden">' . esc_html( sprintf( /* translators: %s: topic */ __( 'in %s', 'support-desk' ), $bsup_topic->name ) ) . '</span>' );
					?>
					<span aria-hidden="true">&#8594;</span>
				</a>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
