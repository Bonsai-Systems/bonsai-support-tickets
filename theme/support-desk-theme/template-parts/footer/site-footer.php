<?php
/**
 * Dark footer: brand + contact + support hours, Support links, Legal links.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_logo      = bsup_option( 'site_footer_logo' );
$bsup_tagline   = bsup_option( 'footer_tagline', __( 'Help, guides and support requests in one place.', 'support-desk' ) );
$bsup_email     = bsup_option( 'contact_email' );
$bsup_phone     = bsup_option( 'contact_telephone' );
$bsup_hours     = bsup_option( 'support_hours' );
$bsup_main_site = bsup_option( 'main_site_url' );
$bsup_main_text = bsup_option( 'main_site_label', __( 'Main website', 'support-desk' ) );
$bsup_copyright = bsup_option( 'copyright_text', bsup_brand_name() );
$bsup_credit    = bsup_option( 'credit_text' );
$bsup_credit_to = bsup_option( 'credit_url' );
?>
<footer class="bonsai-footer" id="site-footer">
	<div class="container">
		<div class="bonsai-footer-grid">

			<div class="bonsai-footer-brand-col">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bonsai-footer-logo">
					<?php if ( $bsup_logo ) : ?>
						<?php
						echo wp_get_attachment_image(
							(int) $bsup_logo,
							'medium',
							false,
							array(
								'class'   => 'bonsai-footer-logo-img',
								'alt'     => bsup_brand_name(),
								'loading' => 'lazy',
							)
						);
						?>
					<?php else : ?>
						<?php echo esc_html( bsup_brand_name() ); ?>
					<?php endif; ?>
				</a>

				<?php if ( $bsup_tagline ) : ?>
					<p class="bonsai-footer-tagline"><?php echo esc_html( $bsup_tagline ); ?></p>
				<?php endif; ?>

				<?php if ( $bsup_hours ) : ?>
					<p class="bonsai-footer-hours">
						<span class="bonsai-footer-hours__label"><?php esc_html_e( 'Support hours', 'support-desk' ); ?></span>
						<?php echo esc_html( $bsup_hours ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $bsup_email || $bsup_phone ) : ?>
					<ul class="bonsai-footer-contacts">
						<?php if ( $bsup_email ) : ?>
							<li><a class="bonsai-footer-link-subtle" href="mailto:<?php echo esc_attr( antispambot( $bsup_email ) ); ?>"><?php echo esc_html( antispambot( $bsup_email ) ); ?></a></li>
						<?php endif; ?>
						<?php if ( $bsup_phone ) : ?>
							<li><a class="bonsai-footer-link-subtle" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $bsup_phone ) ); ?>"><?php echo esc_html( $bsup_phone ); ?></a></li>
						<?php endif; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="bonsai-footer-nav-col">
				<h2 class="bonsai-footer-heading"><?php esc_html_e( 'Support', 'support-desk' ); ?></h2>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer_support',
						'menu_class'     => 'bonsai-footer-links',
						'container'      => false,
						'depth'          => 1,
						'fallback_cb'    => 'bsup_primary_menu_fallback',
					)
				);
				?>
			</div>

			<div class="bonsai-footer-nav-col">
				<h2 class="bonsai-footer-heading"><?php esc_html_e( 'Legal', 'support-desk' ); ?></h2>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer_legal',
						'menu_class'     => 'bonsai-footer-links',
						'container'      => false,
						'depth'          => 1,
						'fallback_cb'    => false,
					)
				);
				?>
				<?php if ( $bsup_main_site ) : ?>
					<a class="bonsai-footer-book-call" href="<?php echo esc_url( $bsup_main_site ); ?>">
						<?php echo esc_html( $bsup_main_text ); ?>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 17L17 7M17 7H7M17 7v10"/></svg>
					</a>
				<?php endif; ?>
			</div>

		</div>
	</div>
</footer>

<div class="bonsai-sub-footer">
	<div class="container">
		<div class="bonsai-sub-footer-inner">
			<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $bsup_copyright ); ?></p>
			<?php if ( $bsup_credit ) : ?>
				<p>
					<?php if ( $bsup_credit_to ) : ?>
						<a href="<?php echo esc_url( $bsup_credit_to ); ?>"><?php echo esc_html( $bsup_credit ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $bsup_credit ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
</div>
