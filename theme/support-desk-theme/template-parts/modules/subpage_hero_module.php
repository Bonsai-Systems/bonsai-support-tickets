<?php
/**
 * Subpage Hero — page header for interior pages.
 *
 * Layout: subpage_hero_module in the page builder (row values arrive as $args).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_badge = bsup_arg( $args, 'hero_badge' );
$bsup_title = bsup_arg( $args, 'hero_title' );
$bsup_lead  = bsup_arg( $args, 'hero_lead' );
$bsup_style = 'centered' === bsup_arg( $args, 'hero_style' ) ? 'centered' : 'default';
$bsup_bg    = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

// Every page needs an H1 — fall back to the page title.
if ( ! $bsup_title ) {
	$bsup_title = get_the_title();
}
?>
<section class="subpage-hero-module subpage-hero-module--<?php echo esc_attr( $bsup_style . $bsup_bg ); ?>">
	<div class="container">
		<div class="subpage-hero-module__content reveal-on-scroll">
			<?php if ( $bsup_badge ) : ?>
				<span class="section-tag"><?php echo esc_html( $bsup_badge ); ?></span>
			<?php endif; ?>

			<h1 class="subpage-hero-module__title display-heading<?php echo esc_attr( bsup_stop_class( $bsup_title ) ); ?>"><?php echo esc_html( $bsup_title ); ?></h1>

			<?php if ( $bsup_lead ) : ?>
				<p class="subpage-hero-module__lead"><?php echo esc_html( $bsup_lead ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
