<?php
/**
 * FAQ — accordion Q&A with FAQPage schema.
 *
 * Block: support-desk/faq (attributes arrive as $args).
 * JS: assets/js/main.js (FAQ accordion).
 *
 * Fixes from the original theme: IDs are unique per module (two FAQ modules on
 * one page used to share faq-item-0…), and the list no longer has
 * role="list" on a <div> wrapping role="listitem" schema containers.
 * Answers are only collapsed when JS runs (html.js), so they stay
 * readable if the script fails — the old version hid them for good.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag     = bsup_arg( $args, 'section_tag' );
$bsup_heading = bsup_arg( $args, 'heading' );
$bsup_desc    = bsup_arg( $args, 'description' );
$bsup_items   = bsup_arg( $args, 'faq_items' );
$bsup_bg      = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( ! $bsup_items ) {
	return;
}

$bsup_uid = bsup_uid( 'faq' );
?>
<section class="faq-module<?php echo esc_attr( $bsup_bg ); ?>">
	<div class="container">
		<div class="faq-module__inner">

			<?php if ( $bsup_tag || $bsup_heading || $bsup_desc ) : ?>
				<div class="faq-module__header reveal-on-scroll">
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

			<div class="faq-module__list reveal-on-scroll" itemscope itemtype="https://schema.org/FAQPage">
				<?php foreach ( $bsup_items as $bsup_index => $bsup_item ) : ?>
					<?php
					$bsup_question = $bsup_item['question'] ?? '';
					$bsup_answer   = $bsup_item['answer'] ?? '';
					if ( ! $bsup_question ) {
						continue;
					}
					$bsup_answer_id = $bsup_uid . '-a' . $bsup_index;
					?>
					<div class="faq-module__item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
						<h3 class="faq-module__q-heading">
							<button class="faq-module__question" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $bsup_answer_id ); ?>">
								<span itemprop="name"><?php echo esc_html( $bsup_question ); ?></span>
								<span class="faq-module__icon" aria-hidden="true">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path class="faq-icon-v" d="M12 5v14"/><path d="M5 12h14"/></svg>
								</span>
							</button>
						</h3>
						<div class="faq-module__answer" id="<?php echo esc_attr( $bsup_answer_id ); ?>" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
							<div class="faq-module__answer-inner" itemprop="text">
								<?php echo wp_kses_post( $bsup_answer ); ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

		</div>
	</div>
</section>
