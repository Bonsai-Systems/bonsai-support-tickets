<?php
/**
 * Ticket Portal — the client's requests (list, or one request when the
 * URL has ?ticket=ID). Output and permissions come from the Support Desk
 * plugin; this block only places it on the page.
 *
 * Block: support-desk/ticket-portal (attributes arrive as $args).
 *
 * Set this page as "My requests page" in Support → Settings so email
 * links land here.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_intro = bsup_arg( $args, 'intro' );
$bsup_bg    = bsup_bg_class( bsup_arg( $args, 'background_style' ) );
?>
<section class="ticket-portal-module<?php echo esc_attr( $bsup_bg ); ?>">
	<div class="container">
		<div class="ticket-portal-module__inner">
			<?php if ( $bsup_intro && empty( $_GET['ticket'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only. ?>
				<p class="ticket-portal-module__intro"><?php echo esc_html( $bsup_intro ); ?></p>
			<?php endif; ?>

			<?php
			if ( bsup_has_tickets() ) {
				echo do_shortcode( '[bst_my_tickets]' );
			} else {
				bsup_tickets_missing_notice();
			}
			?>
		</div>
	</div>
</section>
