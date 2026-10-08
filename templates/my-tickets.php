<?php
/**
 * The client's requests.
 *
 * Override: copy to {theme}/support-desk/my-tickets.php
 *
 * @var WP_Post[] $active     Open requests.
 * @var WP_Post[] $resolved   Solved/closed requests.
 * @var string    $submit_url Submit-a-request URL.
 * @var array|null $hours     This month's support hours (BST_Time::usage() plus
 *                            month_label), or null when not shown. Totals only.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

$bst_render_table = function ( array $tickets, $caption ) {
	?>
	<table class="bst-table">
		<caption class="bst-table__caption"><?php echo esc_html( $caption ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Ref', 'bonsai-support-tickets' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Subject', 'bonsai-support-tickets' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'bonsai-support-tickets' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last update', 'bonsai-support-tickets' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $tickets as $bst_ticket ) : ?>
				<?php $bst_status = bst_get_ticket_status( $bst_ticket->ID ); ?>
				<tr>
					<td class="bst-table__ref" data-label="<?php esc_attr_e( 'Ref', 'bonsai-support-tickets' ); ?>"><?php echo esc_html( bst_get_ticket_ref( $bst_ticket->ID ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Subject', 'bonsai-support-tickets' ); ?>">
						<a class="bst-table__link" href="<?php echo esc_url( bst_get_ticket_url( $bst_ticket->ID ) ); ?>"><?php echo esc_html( get_the_title( $bst_ticket ) ); ?></a>
					</td>
					<td data-label="<?php esc_attr_e( 'Status', 'bonsai-support-tickets' ); ?>">
						<span class="bst-badge bst-badge--<?php echo esc_attr( $bst_status ); ?>"><?php echo esc_html( bst_get_status_label( $bst_status ) ); ?></span>
					</td>
					<td data-label="<?php esc_attr_e( 'Last update', 'bonsai-support-tickets' ); ?>"><?php echo esc_html( bst_format_datetime( get_post_meta( $bst_ticket->ID, BST_Tickets::META_LAST_ACTIVITY, true ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<div class="bst bst-portal">
	<div class="bst-portal__head">
		<div>
			<p class="bst__tag"><?php esc_html_e( 'Client support', 'bonsai-support-tickets' ); ?></p>
			<h2 class="bst__title"><?php esc_html_e( 'My requests', 'bonsai-support-tickets' ); ?></h2>
		</div>
		<a class="bst-btn bst-btn--primary" href="<?php echo esc_url( $submit_url ); ?>"><?php esc_html_e( 'New request', 'bonsai-support-tickets' ); ?></a>
	</div>

	<?php if ( ! empty( $hours ) ) : ?>
		<section class="bst-hours bst-hours--<?php echo esc_attr( $hours['level'] ); ?>" aria-labelledby="bst-hours-title">
			<h3 class="bst-hours__title" id="bst-hours-title">
				<?php
				/* translators: %s: month, e.g. October 2026. */
				echo esc_html( sprintf( __( 'Support hours · %s', 'bonsai-support-tickets' ), $hours['month_label'] ) );
				?>
			</h3>
			<p class="bst-hours__figures">
				<?php
				/* translators: 1: time used, e.g. 9h 45m, 2: time included, e.g. 10h. */
				echo esc_html( sprintf( __( '%1$s of %2$s used', 'bonsai-support-tickets' ), BST_Duration::format( $hours['used'] ), BST_Duration::format( $hours['allowance'] ) ) );
				?>
			</p>
			<progress class="bst-hours__bar" max="100" value="<?php echo esc_attr( (string) min( 100, (int) round( $hours['percent'] ) ) ); ?>" aria-labelledby="bst-hours-title"><?php echo esc_html( (int) round( $hours['percent'] ) . '%' ); ?></progress>
			<p class="bst-hours__note">
				<?php
				if ( $hours['over'] ) {
					/* translators: %s: time over the monthly allowance, e.g. 1h 30m. */
					echo esc_html( sprintf( __( '%s over your monthly allowance.', 'bonsai-support-tickets' ), BST_Duration::format( $hours['over'] ) ) );
				} else {
					/* translators: %s: time remaining, e.g. 2h 15m. */
					echo esc_html( sprintf( __( '%s left this month. Your hours reset on the 1st.', 'bonsai-support-tickets' ), BST_Duration::format( $hours['remaining'] ) ) );
				}
				?>
			</p>
		</section>
	<?php endif; ?>

	<?php if ( ! $active && ! $resolved ) : ?>
		<p class="bst-empty"><?php esc_html_e( 'You have not raised any requests yet.', 'bonsai-support-tickets' ); ?></p>
	<?php endif; ?>

	<?php
	if ( $active ) {
		$bst_render_table( $active, __( 'Open requests', 'bonsai-support-tickets' ) );
	}
	if ( $resolved ) {
		$bst_render_table( $resolved, __( 'Solved and closed', 'bonsai-support-tickets' ) );
	}
	?>
</div>
