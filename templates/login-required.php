<?php
/**
 * Shown in place of the portal or form to logged-out visitors.
 *
 * Override: copy to {theme}/bonsai-support/login-required.php
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bst bst-login">
	<p class="bst__tag"><?php esc_html_e( 'Client support', 'bonsai-support-tickets' ); ?></p>
	<h2 class="bst__title"><?php esc_html_e( 'Please log in', 'bonsai-support-tickets' ); ?></h2>
	<p class="bst__lead"><?php esc_html_e( 'Log in to raise a request and see the progress of your existing ones.', 'bonsai-support-tickets' ); ?></p>
	<?php
	wp_login_form(
		array(
			'form_id'        => 'bst-login-form',
			'label_username' => __( 'Email address', 'bonsai-support-tickets' ),
			'label_log_in'   => __( 'Log in', 'bonsai-support-tickets' ),
		)
	);
	?>
	<p class="bst__small">
		<a href="<?php echo esc_url( wp_lostpassword_url( (string) get_permalink() ) ); ?>"><?php esc_html_e( 'Forgotten your password?', 'bonsai-support-tickets' ); ?></a>
	</p>
</div>
