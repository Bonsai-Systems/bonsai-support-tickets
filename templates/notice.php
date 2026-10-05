<?php
/**
 * A simple message box.
 *
 * Override: copy to {theme}/support-desk/notice.php
 *
 * @var string $message Plain text message.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bst">
	<p class="bst-notice" role="status"><?php echo esc_html( $message ); ?></p>
	<p><a class="bst-btn bst-btn--secondary" href="<?php echo esc_url( bst_get_portal_url() ); ?>"><?php esc_html_e( 'Back to my requests', 'bonsai-support-tickets' ); ?></a></p>
</div>
