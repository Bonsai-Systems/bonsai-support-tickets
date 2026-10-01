<?php
/**
 * One request: the conversation (client-visible messages only) and a reply form.
 *
 * Override: copy to {theme}/bonsai-support/single-ticket.php
 * Keep the reply form's field names, nonce and action field.
 *
 * @var WP_Post  $ticket      The ticket.
 * @var string   $ref         Reference.
 * @var string   $status      Status slug.
 * @var object[] $messages    External messages, oldest first. Never internal notes.
 * @var array    $attachments Attachments grouped by message ID.
 * @var array    $errors      Errors from a failed reply.
 * @var array    $input       Previously submitted values.
 * @var string   $portal_url  Back link.
 * @var string   $accept      Accepted file extensions.
 * @var string   $notice      created|replied|solved after a redirect.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

$bst_notices = array(
	'created' => __( 'Thanks — your request has been sent. We will email you when we reply.', 'bonsai-support-tickets' ),
	'replied' => __( 'Your reply has been sent.', 'bonsai-support-tickets' ),
	'solved'  => __( 'Thanks — we have marked this request as solved. Reply any time to reopen it.', 'bonsai-support-tickets' ),
);

$bst_is_resolved = in_array( $status, array( 'solved', 'closed' ), true );
$bst_last_index  = count( $messages ) - 1;
?>
<div class="bst bst-ticket">
	<p><a class="bst-back" href="<?php echo esc_url( $portal_url ); ?>">&larr; <?php esc_html_e( 'All requests', 'bonsai-support-tickets' ); ?></a></p>

	<?php if ( isset( $bst_notices[ $notice ] ) ) : ?>
		<p class="bst-notice bst-notice--success" role="status"><?php echo esc_html( $bst_notices[ $notice ] ); ?></p>
	<?php endif; ?>

	<header class="bst-ticket__head">
		<p class="bst__tag"><?php echo esc_html( $ref ); ?></p>
		<h2 class="bst__title"><?php echo esc_html( get_the_title( $ticket ) ); ?></h2>
		<span class="bst-badge bst-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( bst_get_status_label( $status ) ); ?></span>
	</header>

	<ol class="bst-thread">
		<?php foreach ( $messages as $bst_index => $bst_message ) : ?>
			<?php $bst_from_team = BST_Tickets::is_agent( (int) $bst_message->user_id ); ?>
			<li class="bst-message<?php echo $bst_from_team ? ' bst-message--team' : ''; ?>" <?php echo $bst_index === $bst_last_index ? 'id="bst-latest"' : ''; ?>>
				<div class="bst-message__meta">
					<span class="bst-message__author">
						<?php echo esc_html( $bst_message->author_name ? $bst_message->author_name : $bst_message->author_email ); ?>
						<?php if ( $bst_from_team ) : ?>
							<span class="bst-message__team"><?php esc_html_e( 'Support team', 'bonsai-support-tickets' ); ?></span>
						<?php endif; ?>
					</span>
					<time class="bst-message__time" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $bst_message->created_at . ' UTC' ) ) ); ?>"><?php echo esc_html( bst_format_datetime( $bst_message->created_at ) ); ?></time>
				</div>
				<div class="bst-message__body">
					<?php echo wp_kses( $bst_message->body, BST_Messages::allowed_html() ); ?>
				</div>
				<?php if ( ! empty( $attachments[ (int) $bst_message->id ] ) ) : ?>
					<ul class="bst-files" aria-label="<?php esc_attr_e( 'Attachments', 'bonsai-support-tickets' ); ?>">
						<?php foreach ( $attachments[ (int) $bst_message->id ] as $bst_file ) : ?>
							<li><a href="<?php echo esc_url( bst_get_attachment_url( $bst_file->id ) ); ?>"><?php echo esc_html( $bst_file->file_name ); ?></a> <span class="bst-files__size">(<?php echo esc_html( bst_format_size( $bst_file->file_size ) ); ?>)</span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<section class="bst-reply" aria-labelledby="bst-reply-title">
		<h3 class="bst-reply__title" id="bst-reply-title"><?php echo $bst_is_resolved ? esc_html__( 'Need to reopen this?', 'bonsai-support-tickets' ) : esc_html__( 'Reply', 'bonsai-support-tickets' ); ?></h3>

		<?php if ( $errors ) : ?>
			<div class="bst-notice bst-notice--error" role="alert">
				<?php foreach ( $errors as $bst_error ) : ?>
					<p><?php echo esc_html( $bst_error ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<form class="bst-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bst_client_reply">
			<input type="hidden" name="bst_ticket" value="<?php echo esc_attr( $ticket->ID ); ?>">
			<?php wp_nonce_field( 'bst_client_reply_' . $ticket->ID, 'bst_nonce' ); ?>

			<div class="bst-form__group bst-form__group--full">
				<label class="bst-form__label" for="bst-message"><?php esc_html_e( 'Your message', 'bonsai-support-tickets' ); ?></label>
				<textarea class="bst-form__input bst-form__textarea" id="bst-message" name="bst_message" rows="6"><?php echo esc_textarea( $input['message'] ?? '' ); ?></textarea>
			</div>

			<div class="bst-form__group bst-form__group--full">
				<label class="bst-form__label" for="bst-reply-attachments"><?php esc_html_e( 'Attachments', 'bonsai-support-tickets' ); ?></label>
				<input class="bst-form__file" type="file" id="bst-reply-attachments" name="bst_attachments[]" multiple accept="<?php echo esc_attr( $accept ); ?>">
			</div>

			<div class="bst-form__actions">
				<button type="submit" class="bst-btn bst-btn--primary"><?php echo $bst_is_resolved ? esc_html__( 'Reopen with this reply', 'bonsai-support-tickets' ) : esc_html__( 'Send reply', 'bonsai-support-tickets' ); ?></button>
				<?php if ( ! $bst_is_resolved ) : ?>
					<button type="submit" class="bst-btn bst-btn--secondary" name="bst_mark_solved" value="1"><?php esc_html_e( 'Mark as solved', 'bonsai-support-tickets' ); ?></button>
				<?php endif; ?>
			</div>
		</form>
	</section>
</div>
