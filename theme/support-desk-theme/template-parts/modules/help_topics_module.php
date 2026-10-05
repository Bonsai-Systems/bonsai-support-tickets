<?php
/**
 * Help Topics — grid of help centre topics with their top articles.
 *
 * Block: support-desk/help-topics (attributes arrive as $args).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag       = bsup_arg( $args, 'section_tag' );
$bsup_heading   = bsup_arg( $args, 'heading' );
$bsup_desc      = bsup_arg( $args, 'description' );
$bsup_source    = bsup_arg( $args, 'topics_source' );
$bsup_topics    = 'selected' === $bsup_source ? bsup_arg( $args, 'topics' ) : array();
$bsup_per_topic = (int) bsup_arg( $args, 'articles_per_topic' );
$bsup_columns   = (int) bsup_arg( $args, 'columns' );
$bsup_bg        = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

// Field returns term IDs; keep only terms that still exist (empty = all topics).
$bsup_topics = is_array( $bsup_topics ) ? $bsup_topics : array();
$bsup_topics = array_values(
	array_filter(
		array_map( 'get_term', $bsup_topics ),
		function ( $term ) {
			return $term instanceof WP_Term;
		}
	)
);
?>
<section class="help-topics-module<?php echo esc_attr( $bsup_bg ); ?>">
	<div class="container">

		<?php if ( $bsup_tag || $bsup_heading || $bsup_desc ) : ?>
			<div class="section-header reveal-on-scroll">
				<?php if ( $bsup_tag ) : ?>
					<span class="section-tag"><?php echo esc_html( $bsup_tag ); ?></span>
				<?php endif; ?>
				<?php if ( $bsup_heading ) : ?>
					<h2 class="display-heading<?php echo esc_attr( bsup_stop_class( $bsup_heading ) ); ?>"><?php echo esc_html( $bsup_heading ); ?></h2>
				<?php endif; ?>
				<?php if ( $bsup_desc ) : ?>
					<p class="section-desc"><?php echo esc_html( $bsup_desc ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php
		get_template_part(
			'template-parts/help/topics-grid',
			null,
			array(
				'topics'    => $bsup_topics,
				'per_topic' => $bsup_per_topic > 0 ? $bsup_per_topic : 5,
				'columns'   => $bsup_columns ? $bsup_columns : 3,
			)
		);
		?>

		<p class="help-topics-module__all">
			<a class="text-link" href="<?php echo esc_url( bsup_help_url() ); ?>"><?php esc_html_e( 'Browse the whole help centre', 'support-desk' ); ?> <span aria-hidden="true">&#8594;</span></a>
		</p>

	</div>
</section>
