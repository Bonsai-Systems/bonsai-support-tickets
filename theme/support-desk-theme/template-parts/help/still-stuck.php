<?php
/**
 * "Still stuck?" call to action, used on help templates.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_title_id = bsup_uid( 'still-stuck-title' );
?>
<aside class="still-stuck" aria-labelledby="<?php echo esc_attr( $bsup_title_id ); ?>">
	<h2 class="still-stuck__title" id="<?php echo esc_attr( $bsup_title_id ); ?>"><?php esc_html_e( 'Still stuck?', 'support-desk' ); ?></h2>
	<p class="still-stuck__text"><?php esc_html_e( 'Send us a request and the team will pick it up.', 'support-desk' ); ?></p>
	<a class="btn btn-light" href="<?php echo esc_url( bsup_submit_url() ); ?>"><?php esc_html_e( 'Submit a request', 'support-desk' ); ?></a>
</aside>
