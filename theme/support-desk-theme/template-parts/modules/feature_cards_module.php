<?php
/**
 * Feature Cards — header + grid of label/title/description cards.
 * On the support site: "What we cover", "Our service levels" etc.
 *
 * Block: support-desk/feature-cards (attributes arrive as $args).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag     = bsup_arg( $args, 'eyebrow' );
$bsup_heading = bsup_arg( $args, 'heading' );
$bsup_cards   = bsup_arg( $args, 'feature_cards' );
$bsup_columns = '2' === (string) bsup_arg( $args, 'columns' ) ? 2 : 3;
$bsup_bg      = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( ! $bsup_cards && ! $bsup_heading ) {
	return;
}
?>
<section class="feature-cards-module<?php echo esc_attr( $bsup_bg ); ?>">
	<div class="container">

		<?php if ( $bsup_tag || $bsup_heading ) : ?>
			<div class="section-header reveal-on-scroll">
				<?php if ( $bsup_tag ) : ?>
					<span class="section-tag"><?php echo esc_html( $bsup_tag ); ?></span>
				<?php endif; ?>
				<?php if ( $bsup_heading ) : ?>
					<h2 class="display-heading<?php echo esc_attr( bsup_stop_class( $bsup_heading ) ); ?>"><?php echo esc_html( $bsup_heading ); ?></h2>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $bsup_cards ) : ?>
			<ul class="feature-cards-module__grid feature-cards-module__grid--cols-<?php echo esc_attr( $bsup_columns ); ?>" data-reveal-cards="<?php echo esc_attr( $bsup_columns ); ?>">
				<?php foreach ( $bsup_cards as $bsup_card ) : ?>
					<?php
					if ( empty( $bsup_card['card_title'] ) ) {
						continue;
					}
					?>
					<li class="feature-cards-module__card">
						<?php if ( ! empty( $bsup_card['card_label'] ) ) : ?>
							<span class="feature-cards-module__card-label"><?php echo esc_html( $bsup_card['card_label'] ); ?></span>
						<?php endif; ?>
						<h3 class="feature-cards-module__card-title"><?php echo esc_html( $bsup_card['card_title'] ); ?></h3>
						<?php if ( ! empty( $bsup_card['card_description'] ) ) : ?>
							<p class="feature-cards-module__card-desc"><?php echo esc_html( $bsup_card['card_description'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

	</div>
</section>
