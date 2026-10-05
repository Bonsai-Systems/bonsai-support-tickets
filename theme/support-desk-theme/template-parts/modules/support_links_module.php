<?php
/**
 * Support Links — quick-link cards ("Submit a request", "My requests",
 * "Help centre", or anything custom).
 *
 * Block: support-desk/support-links (attributes arrive as $args).
 *
 * Card types submit / portal / help get their URL from the Support Desk
 * plugin's settings automatically, so links never go stale if a page slug
 * changes. "Custom" uses the card's own link field.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag     = bsup_arg( $args, 'section_tag' );
$bsup_heading = bsup_arg( $args, 'heading' );
$bsup_desc    = bsup_arg( $args, 'description' );
$bsup_cards   = bsup_arg( $args, 'links' );
$bsup_bg      = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( ! $bsup_cards ) {
	return;
}

$bsup_auto_urls = array(
	'submit' => bsup_submit_url(),
	'portal' => bsup_portal_url(),
	'help'   => bsup_help_url(),
);

$bsup_columns = min( 4, count( $bsup_cards ) );
?>
<section class="support-links-module<?php echo esc_attr( $bsup_bg ); ?>">
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

		<ul class="support-links-module__grid support-links-module__grid--<?php echo esc_attr( $bsup_columns ); ?>" data-reveal-cards="<?php echo esc_attr( $bsup_columns ); ?>">
			<?php foreach ( $bsup_cards as $bsup_card ) : ?>
				<?php
				$bsup_type   = $bsup_card['link_type'] ?? 'custom';
				$bsup_link   = $bsup_card['link'] ?? array();
				$bsup_url    = isset( $bsup_auto_urls[ $bsup_type ] ) ? $bsup_auto_urls[ $bsup_type ] : ( $bsup_link['url'] ?? '' );
				$bsup_target = 'custom' === $bsup_type ? ( $bsup_link['target'] ?? '' ) : '';
				$bsup_title  = $bsup_card['title'] ?? '';
				if ( ! $bsup_url || ! $bsup_title ) {
					continue;
				}
				?>
				<li class="support-link-card">
					<a class="support-link-card__link" href="<?php echo esc_url( $bsup_url ); ?>"<?php echo $bsup_target ? ' target="' . esc_attr( $bsup_target ) . '" rel="noopener noreferrer"' : ''; ?>>
						<span class="support-link-card__icon"><?php echo wp_kses( bsup_icon( $bsup_card['icon'] ?? 'arrow' ), bsup_svg_kses() ); ?></span>
						<span class="support-link-card__title"><?php echo esc_html( $bsup_title ); ?></span>
						<?php if ( ! empty( $bsup_card['description'] ) ) : ?>
							<span class="support-link-card__desc"><?php echo esc_html( $bsup_card['description'] ); ?></span>
						<?php endif; ?>
						<span class="support-link-card__arrow" aria-hidden="true">&#8594;</span>
						<?php if ( '_blank' === $bsup_target ) : ?>
							<span class="visually-hidden"><?php esc_html_e( '(opens in a new tab)', 'support-desk' ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

	</div>
</section>
