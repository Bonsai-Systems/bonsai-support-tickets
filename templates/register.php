<?php
/**
 * Register for a client account.
 *
 * Override: copy to {theme}/bonsai-support/register.php
 * Keep the field names, the nonce, the honeypot and bst_ts — the handler relies on them.
 *
 * @var bool   $done        Registration received (show the thanks message).
 * @var array  $errors      Field => message from a failed submit.
 * @var array  $input       Previously submitted values.
 * @var string $timestamp   Signed "form shown at" token.
 * @var string $login_url   Back to the log-in form.
 * @var string $privacy_url Privacy policy URL ('' if none set).
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

$bst_value = function ( $key ) use ( $input ) {
	return $input[ $key ] ?? '';
};

$bst_general = array_filter(
	$errors,
	function ( $key ) {
		return is_int( $key );
	},
	ARRAY_FILTER_USE_KEY
);

/**
 * One text field. Keeps label, error and aria wiring consistent.
 *
 * @param string $key          Input key (bst_{key} is the field name).
 * @param string $label        Label.
 * @param string $type         Input type.
 * @param bool   $required     Required.
 * @param string $autocomplete Autocomplete token.
 * @param string $hint         Hint text.
 * @param bool   $full         Full width.
 */
$bst_field = function ( $key, $label, $type, $required, $autocomplete, $hint = '', $full = false ) use ( $errors, $bst_value ) {
	$name     = 'website' === $key ? 'bst_site' : 'bst_' . $key; // bst_website is the submit form's honeypot name.
	$id       = 'bst-reg-' . str_replace( '_', '-', $key );
	$describe = array();
	if ( $hint ) {
		$describe[] = $id . '-hint';
	}
	if ( isset( $errors[ $key ] ) ) {
		$describe[] = $id . '-error';
	}
	?>
	<div class="bst-form__group<?php echo $full ? ' bst-form__group--full' : ''; ?>">
		<label class="bst-form__label" for="<?php echo esc_attr( $id ); ?>">
			<?php echo esc_html( $label ); ?>
			<?php if ( $required ) : ?>
				<span class="bst-form__required" aria-hidden="true">*</span>
			<?php endif; ?>
		</label>
		<input class="bst-form__input" type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $bst_value( $key ) ); ?>" autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
			<?php echo $required ? 'required aria-required="true"' : ''; ?>
			<?php echo isset( $errors[ $key ] ) ? 'aria-invalid="true"' : ''; ?>
			<?php echo $describe ? 'aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' : ''; ?>>
		<?php if ( $hint ) : ?>
			<p class="bst-form__hint" id="<?php echo esc_attr( $id ); ?>-hint"><?php echo esc_html( $hint ); ?></p>
		<?php endif; ?>
		<?php if ( isset( $errors[ $key ] ) ) : ?>
			<p class="bst-form__error" id="<?php echo esc_attr( $id ); ?>-error"><?php echo esc_html( $errors[ $key ] ); ?></p>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="bst bst-register" id="bst-register">
	<p class="bst__tag"><?php esc_html_e( 'Client support', 'bonsai-support-tickets' ); ?></p>

	<?php if ( $done ) : ?>
		<h2 class="bst__title"><?php esc_html_e( 'Thanks for registering', 'bonsai-support-tickets' ); ?></h2>
		<div class="bst-notice bst-notice--success" role="status">
			<p><?php esc_html_e( 'We\'ve received your details. Once we\'ve checked them, we\'ll email you a link to set your password, usually within one working day.', 'bonsai-support-tickets' ); ?></p>
		</div>
		<p><a class="bst-btn bst-btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to log in', 'bonsai-support-tickets' ); ?></a></p>
	<?php else : ?>
		<h2 class="bst__title"><?php esc_html_e( 'Register for an account', 'bonsai-support-tickets' ); ?></h2>
		<p class="bst__lead"><?php esc_html_e( 'For clients of The Bonsai Digital Collective. We check every registration, then email you a link to set your password.', 'bonsai-support-tickets' ); ?></p>

		<?php if ( $errors ) : ?>
			<div class="bst-notice bst-notice--error" role="alert">
				<p><?php esc_html_e( 'Please check the form and try again.', 'bonsai-support-tickets' ); ?></p>
				<?php foreach ( $bst_general as $bst_error ) : ?>
					<p><?php echo esc_html( $bst_error ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<form class="bst-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<input type="hidden" name="action" value="bst_register">
			<input type="hidden" name="bst_ts" value="<?php echo esc_attr( $timestamp ); ?>">
			<?php wp_nonce_field( 'bst_register', 'bst_nonce' ); ?>

			<div class="bst-form__hp" aria-hidden="true">
				<label for="bst-reg-hp"><?php esc_html_e( 'Leave this empty', 'bonsai-support-tickets' ); ?></label>
				<input type="text" id="bst-reg-hp" name="bst_hp" tabindex="-1" autocomplete="off">
			</div>

			<?php $bst_field( 'client_name', __( 'Client name', 'bonsai-support-tickets' ), 'text', true, 'organization', __( 'Your business or organisation, e.g. The Ley Arms.', 'bonsai-support-tickets' ), true ); ?>

			<div class="bst-form__grid">
				<?php $bst_field( 'first_name', __( 'First name', 'bonsai-support-tickets' ), 'text', true, 'given-name' ); ?>
				<?php $bst_field( 'last_name', __( 'Last name', 'bonsai-support-tickets' ), 'text', true, 'family-name' ); ?>
			</div>

			<?php $bst_field( 'email', __( 'Email address', 'bonsai-support-tickets' ), 'email', true, 'email', __( 'You\'ll log in with this, and replies to your requests go here.', 'bonsai-support-tickets' ), true ); ?>

			<div class="bst-form__grid">
				<?php $bst_field( 'website', __( 'Website', 'bonsai-support-tickets' ), 'text', false, 'url', __( 'e.g. https://theleyarms.co.uk', 'bonsai-support-tickets' ) ); ?>
				<?php $bst_field( 'phone', __( 'Phone', 'bonsai-support-tickets' ), 'tel', false, 'tel' ); ?>
			</div>

			<p class="bst__small">
				<?php esc_html_e( 'We only use these details to manage your support account and requests.', 'bonsai-support-tickets' ); ?>
				<?php if ( $privacy_url ) : ?>
					<a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Privacy policy', 'bonsai-support-tickets' ); ?></a>
				<?php endif; ?>
			</p>

			<div class="bst-form__actions">
				<button type="submit" class="bst-btn bst-btn--primary"><?php esc_html_e( 'Register', 'bonsai-support-tickets' ); ?></button>
			</div>
		</form>

		<p class="bst__small">
			<?php esc_html_e( 'Already have an account?', 'bonsai-support-tickets' ); ?>
			<a href="<?php echo esc_url( $login_url ); ?>" class="black-link"><?php esc_html_e( 'Log in', 'bonsai-support-tickets' ); ?></a>
		</p>
	<?php endif; ?>
</div>
