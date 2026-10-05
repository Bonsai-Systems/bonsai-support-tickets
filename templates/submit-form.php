<?php
/**
 * Submit a request.
 *
 * Override: copy to {theme}/support-desk/submit-form.php
 * Keep the field names, the nonce and the action field — the handler relies on them.
 *
 * @var WP_Term[] $types      Ticket types.
 * @var array     $priorities Priorities (slug => label, variant).
 * @var array     $errors     Field => error message from a failed submit.
 * @var array     $input      Previously submitted values.
 * @var string    $accept     Accepted file extensions.
 * @var int       $max_mb     Max file size.
 * @var int       $max_files  Max number of files.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

$bst_value = function ( $key, $fallback = '' ) use ( $input ) {
	return $input[ $key ] ?? $fallback;
};

// Errors without a field key are shown at the top.
$bst_general = array_filter(
	$errors,
	function ( $key ) {
		return is_int( $key );
	},
	ARRAY_FILTER_USE_KEY
);
?>
<div class="bst bst-submit">
	<p class="bst__tag"><?php esc_html_e( 'Submit a request', 'bonsai-support-tickets' ); ?></p>

	<?php if ( $errors ) : ?>
		<div class="bst-notice bst-notice--error" role="alert">
			<p><?php esc_html_e( 'Please check the form and try again.', 'bonsai-support-tickets' ); ?></p>
			<?php foreach ( $bst_general as $bst_error ) : ?>
				<p><?php echo esc_html( $bst_error ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<form class="bst-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" novalidate>
		<input type="hidden" name="action" value="bst_submit_ticket">
		<?php wp_nonce_field( 'bst_submit_ticket', 'bst_nonce' ); ?>

		<div class="bst-form__hp" aria-hidden="true">
			<label for="bst-website"><?php esc_html_e( 'Leave this empty', 'bonsai-support-tickets' ); ?></label>
			<input type="text" id="bst-website" name="bst_website" tabindex="-1" autocomplete="off">
		</div>

		<div class="bst-form__group bst-form__group--full">
			<label class="bst-form__label" for="bst-subject"><?php esc_html_e( 'Subject', 'bonsai-support-tickets' ); ?> <span class="bst-form__required" aria-hidden="true">*</span></label>
			<input class="bst-form__input" type="text" id="bst-subject" name="bst_subject" maxlength="200" required aria-required="true"
				value="<?php echo esc_attr( $bst_value( 'subject' ) ); ?>"
				<?php echo isset( $errors['subject'] ) ? 'aria-invalid="true" aria-describedby="bst-subject-error"' : ''; ?>>
			<?php if ( isset( $errors['subject'] ) ) : ?>
				<p class="bst-form__error" id="bst-subject-error"><?php echo esc_html( $errors['subject'] ); ?></p>
			<?php endif; ?>
		</div>

		<div class="bst-form__grid">
			<?php if ( $types ) : ?>
				<div class="bst-form__group">
					<label class="bst-form__label" for="bst-type"><?php esc_html_e( 'What do you need help with?', 'bonsai-support-tickets' ); ?></label>
					<select class="bst-form__input" id="bst-type" name="bst_type" <?php echo isset( $errors['type'] ) ? 'aria-invalid="true" aria-describedby="bst-type-error"' : ''; ?>>
						<?php foreach ( $types as $bst_type ) : ?>
							<option value="<?php echo esc_attr( $bst_type->term_id ); ?>" <?php selected( (int) $bst_value( 'type_id' ), $bst_type->term_id ); ?>><?php echo esc_html( $bst_type->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( isset( $errors['type'] ) ) : ?>
						<p class="bst-form__error" id="bst-type-error"><?php echo esc_html( $errors['type'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="bst-form__group">
				<label class="bst-form__label" for="bst-priority"><?php esc_html_e( 'How urgent is it?', 'bonsai-support-tickets' ); ?></label>
				<select class="bst-form__input" id="bst-priority" name="bst_priority">
					<?php foreach ( $priorities as $bst_slug => $bst_priority ) : ?>
						<option value="<?php echo esc_attr( $bst_slug ); ?>" <?php selected( $bst_value( 'priority', 'normal' ), $bst_slug ); ?>><?php echo esc_html( $bst_priority['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="bst-form__group bst-form__group--full">
			<label class="bst-form__label" for="bst-site-url"><?php esc_html_e( 'Which website or page?', 'bonsai-support-tickets' ); ?></label>
			<input class="bst-form__input" type="url" id="bst-site-url" name="bst_site_url" placeholder="https://" aria-describedby="bst-site-url-hint"
				value="<?php echo esc_attr( $bst_value( 'site_url' ) ); ?>">
			<p class="bst-form__hint" id="bst-site-url-hint"><?php esc_html_e( 'Paste the address of the page the issue is on, if there is one.', 'bonsai-support-tickets' ); ?></p>
		</div>

		<div class="bst-form__group bst-form__group--full">
			<label class="bst-form__label" for="bst-description"><?php esc_html_e( 'Description', 'bonsai-support-tickets' ); ?> <span class="bst-form__required" aria-hidden="true">*</span></label>
			<textarea class="bst-form__input bst-form__textarea" id="bst-description" name="bst_description" rows="8" required aria-required="true"
				aria-describedby="bst-description-hint<?php echo isset( $errors['description'] ) ? ' bst-description-error' : ''; ?>"
				<?php echo isset( $errors['description'] ) ? 'aria-invalid="true"' : ''; ?>><?php echo esc_textarea( $bst_value( 'description' ) ); ?></textarea>
			<p class="bst-form__hint" id="bst-description-hint"><?php esc_html_e( 'What happened, what you expected, and the steps to see it. Screenshots help.', 'bonsai-support-tickets' ); ?></p>
			<?php if ( isset( $errors['description'] ) ) : ?>
				<p class="bst-form__error" id="bst-description-error"><?php echo esc_html( $errors['description'] ); ?></p>
			<?php endif; ?>
		</div>

		<div class="bst-form__group bst-form__group--full">
			<label class="bst-form__label" for="bst-attachments"><?php esc_html_e( 'Attachments', 'bonsai-support-tickets' ); ?></label>
			<input class="bst-form__file" type="file" id="bst-attachments" name="bst_attachments[]" multiple accept="<?php echo esc_attr( $accept ); ?>"
				aria-describedby="bst-attachments-hint<?php echo isset( $errors['attachments'] ) ? ' bst-attachments-error' : ''; ?>">
			<p class="bst-form__hint" id="bst-attachments-hint">
				<?php
				/* translators: 1: number of files, 2: size in MB. */
				echo esc_html( sprintf( __( 'Up to %1$d files, %2$d MB each. Images, PDFs, Office documents, ZIP and video.', 'bonsai-support-tickets' ), $max_files, $max_mb ) );
				?>
			</p>
			<?php if ( isset( $errors['attachments'] ) ) : ?>
				<p class="bst-form__error" id="bst-attachments-error"><?php echo esc_html( $errors['attachments'] ); ?></p>
			<?php endif; ?>
		</div>

		<div class="bst-form__actions">
			<button type="submit" class="bst-btn bst-btn--primary"><?php esc_html_e( 'Submit request', 'bonsai-support-tickets' ); ?></button>
		</div>
	</form>
</div>
