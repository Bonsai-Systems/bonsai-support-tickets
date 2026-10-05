<?php
/**
 * Glass header: logo (or support name) + "Support" label, primary nav, account link and
 * a "Submit a request" button. Mobile: full-screen overlay menu.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_label   = bsup_option( 'header_label', __( 'Support', 'support-desk' ) );
$bsup_cta     = bsup_option( 'nav_cta_text', __( 'Submit a request', 'support-desk' ) );
$bsup_cta_url = bsup_option( 'nav_cta_url', bsup_submit_url() );
$bsup_account = bsup_account_link();

$bsup_menu_args = array(
	'theme_location' => 'primary',
	'container'      => false,
	'depth'          => 1,
	'fallback_cb'    => 'bsup_primary_menu_fallback',
);
?>
<header class="bonsai-header" id="bonsai-header">
	<div class="bonsai-nav-container">

		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bonsai-brand-logo" rel="home">
			<?php echo bsup_logo_html( 'bonsai-logo-img', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in bsup_logo_html(). ?>
			<?php if ( $bsup_label ) : ?>
				<span class="bonsai-brand-label"><?php echo esc_html( $bsup_label ); ?></span>
			<?php endif; ?>
		</a>

		<nav class="bonsai-nav-menu-wrap" aria-label="<?php esc_attr_e( 'Main', 'support-desk' ); ?>">
			<?php
			wp_nav_menu(
				array_merge(
					$bsup_menu_args,
					array(
						'menu_class'  => 'bonsai-nav-menu',
						'link_before' => '<span class="bonsai-nav-link">',
						'link_after'  => '</span>',
					)
				)
			);
			?>
		</nav>

		<div class="bonsai-nav-actions">
			<a href="<?php echo esc_url( $bsup_account['url'] ); ?>" class="bonsai-account-link"><?php echo esc_html( $bsup_account['label'] ); ?></a>
			<a href="<?php echo esc_url( $bsup_cta_url ); ?>" class="btn-nav"><?php echo esc_html( $bsup_cta ); ?></a>
		</div>

		<button class="bonsai-menu-toggle" id="bonsai-menu-toggle" type="button" aria-expanded="false" aria-controls="bonsai-mobile-overlay">
			<span class="visually-hidden"><?php esc_html_e( 'Menu', 'support-desk' ); ?></span>
			<span class="bonsai-hamburger-line" aria-hidden="true"></span>
			<span class="bonsai-hamburger-line" aria-hidden="true"></span>
		</button>

	</div>
</header>

<div class="bonsai-mobile-overlay" id="bonsai-mobile-overlay" hidden>
	<nav class="bonsai-mobile-nav-wrap" aria-label="<?php esc_attr_e( 'Mobile', 'support-desk' ); ?>">
		<?php
		wp_nav_menu(
			array_merge(
				$bsup_menu_args,
				array(
					'menu_class'  => 'bonsai-mobile-nav',
					'link_before' => '',
					'link_after'  => '',
				)
			)
		);
		?>
		<div class="bonsai-mobile-actions">
			<a href="<?php echo esc_url( $bsup_cta_url ); ?>" class="btn btn-primary"><?php echo esc_html( $bsup_cta ); ?></a>
			<a href="<?php echo esc_url( $bsup_account['url'] ); ?>" class="bonsai-account-link"><?php echo esc_html( $bsup_account['label'] ); ?></a>
		</div>
	</nav>
</div>

<div class="bonsai-header-spacer" aria-hidden="true"></div>
