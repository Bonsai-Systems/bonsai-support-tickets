<?php
/**
 * Content Block — a section of normal editor content (policies, guides, general copy).
 *
 * Layout: content_block_module in the page builder (row values arrive as $args).
 *
 * Change from the original theme: the heading level is chosen per module
 * (H1 default was causing multiple H1s when used under a page hero).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag     = bsup_arg( $args, 'section_tag' );
$bsup_heading = bsup_arg( $args, 'heading' );
$bsup_level   = 'h1' === bsup_arg( $args, 'heading_level' ) ? 'h1' : 'h2';
$bsup_body    = trim( (string) bsup_arg( $args, 'body_content' ) ); // WYSIWYG, formatted by ACF.
$bsup_width   = 'standard' === bsup_arg( $args, 'content_width' ) ? 'standard' : 'narrow';
$bsup_bg      = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( ! $bsup_body && ! $bsup_heading ) {
	return;
}
?>
<section class="content-block-module content-block-module--<?php echo esc_attr( $bsup_width . $bsup_bg ); ?>">
	<div class="container">
		<div class="content-block-module__inner reveal-on-scroll">
			<?php if ( $bsup_tag ) : ?>
				<span class="section-tag"><?php echo esc_html( $bsup_tag ); ?></span>
			<?php endif; ?>

			<?php if ( $bsup_heading ) : ?>
				<<?php echo esc_html( $bsup_level ); ?> class="content-block-module__heading"><?php echo esc_html( $bsup_heading ); ?></<?php echo esc_html( $bsup_level ); ?>>
			<?php endif; ?>

			<?php if ( $bsup_body ) : ?>
				<div class="content-block-module__body content-block">
					<?php echo wp_kses_post( $bsup_body ); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
