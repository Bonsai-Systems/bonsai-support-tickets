<?php
/**
 * Process — numbered steps. On the support site: "How support works"
 * (Submit → We triage → We fix → You confirm).
 *
 * Layout: process_module in the page builder (row values arrive as $args).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag     = bsup_arg( $args, 'section_tag' );
$bsup_heading = bsup_arg( $args, 'heading' );
$bsup_desc    = bsup_arg( $args, 'description' );
$bsup_steps   = bsup_arg( $args, 'steps' );
$bsup_bg      = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( ! $bsup_steps ) {
	return;
}

// Column count follows the number of steps (max 5), so 4 steps fill the row.
$bsup_count = min( 5, max( 1, count( $bsup_steps ) ) );
?>
<section class="process-module<?php echo esc_attr( $bsup_bg ); ?>">
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

		<ol class="process-module__steps process-module__steps--<?php echo esc_attr( $bsup_count ); ?>" data-reveal-cards="<?php echo esc_attr( $bsup_count ); ?>">
			<?php foreach ( $bsup_steps as $bsup_index => $bsup_step ) : ?>
				<?php
				if ( empty( $bsup_step['step_title'] ) ) {
					continue;
				}
				$bsup_num = ! empty( $bsup_step['step_number'] ) ? $bsup_step['step_number'] : str_pad( (string) ( $bsup_index + 1 ), 2, '0', STR_PAD_LEFT );
				?>
				<li class="process-module__step">
					<span class="process-module__num" aria-hidden="true"><?php echo esc_html( $bsup_num ); ?></span>
					<h3 class="process-module__step-title"><?php echo esc_html( $bsup_step['step_title'] ); ?></h3>
					<?php if ( ! empty( $bsup_step['step_description'] ) ) : ?>
						<p class="process-module__step-desc"><?php echo esc_html( $bsup_step['step_description'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

	</div>
</section>
