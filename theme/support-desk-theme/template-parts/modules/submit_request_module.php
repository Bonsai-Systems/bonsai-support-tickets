<?php
/**
 * Submit Request — two columns: guidance on the left (sticky on desktop),
 * the Support Desk request form on the right.
 *
 * Layout: submit_request_module in the page builder (row values arrive as $args).
 *
 * Set this page as "Submit a request page" in Support → Settings.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag       = bsup_arg( $args, 'section_tag' );
$bsup_heading   = bsup_arg( $args, 'heading' );
$bsup_intro     = bsup_arg( $args, 'intro' );
$bsup_tips      = bsup_arg( $args, 'tips' );
$bsup_show_help = bsup_arg( $args, 'show_help_link' );
$bsup_hours     = bsup_option( 'support_hours' );
$bsup_bg        = bsup_bg_class( bsup_arg( $args, 'background_style' ) );
?>
<section class="submit-request-module<?php echo esc_attr( $bsup_bg ); ?>">
	<div class="container">
		<div class="submit-request-module__grid">

			<div class="submit-request-module__aside">
				<div class="submit-request-module__sticky">
					<?php if ( $bsup_tag ) : ?>
						<span class="section-tag"><?php echo esc_html( $bsup_tag ); ?></span>
					<?php endif; ?>
					<?php if ( $bsup_heading ) : ?>
						<h2 class="display-heading<?php echo esc_attr( bsup_stop_class( $bsup_heading ) ); ?>"><?php echo esc_html( $bsup_heading ); ?></h2>
					<?php endif; ?>
					<?php if ( $bsup_intro ) : ?>
						<div class="submit-request-module__intro content-block"><?php echo wp_kses_post( $bsup_intro ); ?></div>
					<?php endif; ?>

					<?php if ( $bsup_tips ) : ?>
						<h3 class="submit-request-module__tips-title"><?php esc_html_e( 'To help us fix it faster', 'support-desk' ); ?></h3>
						<ul class="submit-request-module__tips">
							<?php foreach ( $bsup_tips as $bsup_tip ) : ?>
								<?php if ( ! empty( $bsup_tip['tip'] ) ) : ?>
									<li><?php echo esc_html( $bsup_tip['tip'] ); ?></li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $bsup_hours ) : ?>
						<p class="submit-request-module__hours">
							<strong><?php esc_html_e( 'Support hours:', 'support-desk' ); ?></strong>
							<?php echo esc_html( $bsup_hours ); ?>
						</p>
					<?php endif; ?>

					<?php if ( $bsup_show_help ) : ?>
						<p class="submit-request-module__help">
							<?php esc_html_e( 'Quick question?', 'support-desk' ); ?>
							<a class="text-link" href="<?php echo esc_url( bsup_help_url() ); ?>"><?php esc_html_e( 'Try the help centre first', 'support-desk' ); ?> <span aria-hidden="true">&#8594;</span></a>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="submit-request-module__form">
				<?php
				if ( bsup_has_tickets() ) {
					echo do_shortcode( '[bst_submit_form]' );
				} else {
					bsup_tickets_missing_notice();
				}
				?>
			</div>

		</div>
	</div>
</section>
