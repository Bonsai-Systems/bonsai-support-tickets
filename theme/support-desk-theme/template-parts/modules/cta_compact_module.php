<?php
/**
 * CTA Compact — centred dark call to action, one or two buttons.
 *
 * Layout: cta_compact_module in the page builder (row values arrive as $args).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_heading   = bsup_arg( $args, 'heading' );
$bsup_desc      = bsup_arg( $args, 'description' );
$bsup_primary   = bsup_arg( $args, 'primary_button' );
$bsup_secondary = bsup_arg( $args, 'secondary_button' );

if ( ! $bsup_heading ) {
	return;
}
?>
<section class="cta-compact-module">
	<div class="container">
		<div class="cta-compact-module__content reveal-on-scroll">
			<h2 class="display-heading<?php echo esc_attr( bsup_stop_class( $bsup_heading ) ); ?>"><?php echo esc_html( $bsup_heading ); ?></h2>

			<?php if ( $bsup_desc ) : ?>
				<p class="cta-compact-module__desc"><?php echo esc_html( $bsup_desc ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $bsup_primary['url'] ) || ! empty( $bsup_secondary['url'] ) ) : ?>
				<div class="cta-compact-module__actions">
					<?php bsup_link_button( $bsup_primary, 'btn btn-light', true ); ?>
					<?php bsup_link_button( $bsup_secondary, 'btn btn-outline-light' ); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
