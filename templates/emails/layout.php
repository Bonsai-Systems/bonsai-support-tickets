<?php
/**
 * Email layout. Inline styles only — most mail clients ignore <style>.
 *
 * Override: copy to {theme}/bonsai-support/emails/layout.php
 *
 * Available variables:
 *
 * @var string      $heading      Main heading.
 * @var string      $intro        Line under the heading (plain text).
 * @var object|null $message      Message row (author_name, body, created_at) or null.
 * @var bool        $internal     Message is an internal note.
 * @var string      $button_url   Call to action URL ('' = no button).
 * @var string      $button_label Call to action label.
 * @var string      $footer       Small print.
 * @var string      $ref          Ticket reference ('' for account emails).
 * @var string      $subject      Ticket subject ('' for account emails).
 * @var array       $details      Optional label => value rows (account emails).
 * @var string      $reply_marker Reply-above-this-line marker ('' when inbound email is off).
 * @var string      $logo_url     Logo image URL.
 * @var string      $site_name    Sender name.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

$bst_font    = "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif";
$bst_details = isset( $details ) && is_array( $details ) ? $details : array();
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( trim( $ref . ' ' . ( $subject ? $subject : $heading ) ) ); ?></title>
</head>
<body style="margin:0;padding:0;background:#faf8f5;">
	<?php if ( $reply_marker ) : ?>
		<div style="font-family:<?php echo esc_attr( $bst_font ); ?>;font-size:11px;line-height:1.4;color:#767676;padding:12px 16px 0;text-align:center;"><?php echo esc_html( $reply_marker ); ?></div>
	<?php endif; ?>

	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf8f5;">
		<tr>
			<td align="center" style="padding:24px 16px 40px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e5e0;">

					<tr>
						<td style="background:#f9f8f4;border-bottom:4px solid #ee4367;padding:24px 32px;">
							<img src="<?php echo esc_url( $logo_url ); ?>" width="206" alt="<?php echo esc_attr( $site_name ); ?>" style="display:block;width:206px;max-width:100%;height:auto;border:0;">
						</td>
					</tr>

					<tr>
						<td style="padding:32px 32px 8px;font-family:<?php echo esc_attr( $bst_font ); ?>;">
							<?php if ( $ref ) : ?>
								<p style="margin:0 0 12px;font-size:12px;font-weight:600;letter-spacing:0.15em;text-transform:uppercase;color:#c21f48;">
									<?php echo esc_html( $ref ); ?>
								</p>
							<?php endif; ?>
							<h1 style="margin:0 0 12px;font-size:24px;line-height:1.25;font-weight:600;color:#000000;">
								<?php echo esc_html( $heading ); ?>
							</h1>
							<?php if ( $intro ) : ?>
								<p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#333333;"><?php echo esc_html( $intro ); ?></p>
							<?php endif; ?>
							<?php if ( $subject ) : ?>
								<p style="margin:0;font-size:13px;line-height:1.6;color:#767676;"><?php echo esc_html( $subject ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( $bst_details ) : ?>
						<tr>
							<td style="padding:16px 32px 8px;font-family:<?php echo esc_attr( $bst_font ); ?>;">
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e5e0;">
									<?php foreach ( $bst_details as $bst_label => $bst_value ) : ?>
										<tr>
											<td style="padding:10px 16px 10px 0;border-bottom:1px solid #e5e5e0;font-size:13px;font-weight:600;color:#000000;width:110px;vertical-align:top;"><?php echo esc_html( $bst_label ); ?></td>
											<td style="padding:10px 0;border-bottom:1px solid #e5e5e0;font-size:14px;line-height:1.5;color:#333333;"><?php echo esc_html( $bst_value ); ?></td>
										</tr>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( $message ) : ?>
						<tr>
							<td style="padding:16px 32px 8px;font-family:<?php echo esc_attr( $bst_font ); ?>;">
								<div style="padding:20px 24px;background:<?php echo $internal ? '#fff8e1' : '#faf8f5'; ?>;border-left:3px solid <?php echo $internal ? '#f5a623' : '#000000'; ?>;">
									<p style="margin:0 0 10px;font-size:13px;font-weight:600;color:#000000;">
										<?php echo esc_html( $message->author_name ? $message->author_name : $message->author_email ); ?>
										<?php if ( $internal ) : ?>
											<span style="font-weight:400;color:#8a4b00;"> · <?php esc_html_e( 'Internal note', 'bonsai-support-tickets' ); ?></span>
										<?php endif; ?>
									</p>
									<div style="font-size:15px;line-height:1.6;color:#111111;">
										<?php echo wp_kses( $message->body, BST_Messages::allowed_html() ); ?>
									</div>
								</div>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( $button_url ) : ?>
						<tr>
							<td style="padding:24px 32px 8px;">
								<table role="presentation" cellpadding="0" cellspacing="0" border="0">
									<tr>
										<td style="background:#000000;">
											<a href="<?php echo esc_url( $button_url ); ?>" style="display:inline-block;padding:14px 28px;font-family:<?php echo esc_attr( $bst_font ); ?>;font-size:13px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#faf8f5;text-decoration:none;"><?php echo esc_html( $button_label ); ?></a>
										</td>
									</tr>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<td style="padding:24px 32px 32px;font-family:<?php echo esc_attr( $bst_font ); ?>;">
							<p style="margin:0;padding-top:20px;border-top:1px solid #e5e5e0;font-size:12px;line-height:1.6;color:#767676;">
								<?php echo esc_html( $footer ); ?>
							</p>
						</td>
					</tr>

				</table>
			</td>
		</tr>
	</table>
</body>
</html>
