<?php
/**
 * Time tracking screens: the settings tab, "Log time" in the reply box, the
 * ticket's Time box, the client's Retainer box, and Support → Time (report,
 * CSV export, editing an entry).
 *
 * Nothing here shows while time tracking is switched off, except the
 * settings tab that switches it on.
 *
 * Capabilities: bst_log_time (agents) logs time and edits their own
 * entries; bst_manage_time (admins) sees reports, exports and edits anyone's.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Time tracking admin.
 */
class BST_Admin_Time {

	const SLUG = 'bst-time';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes_' . BST_Post_Types::TICKET, array( __CLASS__, 'ticket_box' ) );
		add_action( 'add_meta_boxes_' . BST_Post_Types::COMPANY, array( __CLASS__, 'company_box' ) );
		add_action( 'admin_post_bst_time_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_bst_time_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_bst_time_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Support → Time URL.
	 *
	 * @param array $args Query args (month, company, entry).
	 * @return string
	 */
	public static function url( array $args = array() ) {
		// Keep 0 (the "No client" row); drop only missing values.
		$args = array_filter(
			$args,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET . '&page=' . self::SLUG ) );
	}

	/**
	 * Submenu, only while time tracking is on.
	 */
	public static function menu() {
		if ( ! BST_Time::enabled() ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . BST_Post_Types::TICKET,
			__( 'Time', 'bonsai-support-tickets' ),
			__( 'Time', 'bonsai-support-tickets' ),
			'bst_log_time',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/*
	|----------------------------------------------------------------------
	| Shared bits
	|----------------------------------------------------------------------
	*/

	/**
	 * Retainer usage bar: "6h 30m of 10h · 3h 30m left".
	 *
	 * @param array $usage BST_Time::usage().
	 * @return string HTML.
	 */
	public static function usage_bar( array $usage ) {
		if ( 'none' === $usage['level'] ) {
			return '<span class="bst-muted">' . esc_html(
				sprintf(
					/* translators: %s: billable time, e.g. 2h 30m. */
					__( 'No retainer · %s billable', 'bonsai-support-tickets' ),
					BST_Duration::format( $usage['used'] )
				)
			) . '</span>';
		}

		$label = sprintf(
			/* translators: 1: used, 2: allowance. */
			__( '%1$s of %2$s', 'bonsai-support-tickets' ),
			BST_Duration::format( $usage['used'] ),
			BST_Duration::format( $usage['allowance'] )
		);
		$extra = $usage['over']
			/* translators: %s: time over the allowance. */
			? sprintf( __( '%s over', 'bonsai-support-tickets' ), BST_Duration::format( $usage['over'] ) )
			/* translators: %s: time left. */
			: sprintf( __( '%s left', 'bonsai-support-tickets' ), BST_Duration::format( $usage['remaining'] ) );

		$percent = (int) round( $usage['percent'] );

		return sprintf(
			'<div class="bst-usage bst-usage--%1$s"><progress class="bst-usage__bar" max="100" value="%2$d" aria-label="%3$s">%4$d%%</progress><span class="bst-usage__text">%5$s · %6$s</span></div>',
			esc_attr( $usage['level'] ),
			min( 100, $percent ),
			/* translators: %d: percent. */
			esc_attr( sprintf( __( '%d%% of the retainer used', 'bonsai-support-tickets' ), $percent ) ),
			$percent,
			esc_html( $label ),
			esc_html( $extra )
		);
	}

	/**
	 * Billable / non-billable badge.
	 *
	 * @param object $entry Entry.
	 * @return string HTML.
	 */
	private static function billable_badge( $entry ) {
		return (int) $entry->billable
			? BST_Admin_UI::badge( __( 'Billable', 'bonsai-support-tickets' ), 'success' )
			: BST_Admin_UI::badge( __( 'Non-billable', 'bonsai-support-tickets' ), 'neutral' );
	}

	/**
	 * Delete link for an entry (admin-post, nonce, confirm).
	 *
	 * @param object $entry Entry.
	 * @return string HTML.
	 */
	private static function delete_link( $entry ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=bst_time_delete&entry=' . (int) $entry->id ),
			'bst_time_delete_' . (int) $entry->id
		);
		return sprintf(
			'<a href="%1$s" class="button-link button-link-delete" data-bst-confirm="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'Delete this time entry?', 'bonsai-support-tickets' ),
			esc_html__( 'Delete', 'bonsai-support-tickets' )
		);
	}

	/*
	|----------------------------------------------------------------------
	| Settings tab
	|----------------------------------------------------------------------
	*/

	/**
	 * Settings → Time tracking.
	 *
	 * @param array $s Settings.
	 */
	public static function render_settings( array $s ) {
		$rows = array(
			'time_enabled'          => array(
				'label' => __( 'Time tracking', 'bonsai-support-tickets' ),
				'text'  => __( 'Log time on tickets and track monthly retainers', 'bonsai-support-tickets' ),
				'help'  => __( 'Adds Log time to the reply box, a Time box on tickets and clients, and Support → Time. Turning it off hides all of these; logged time is kept.', 'bonsai-support-tickets' ),
			),
			'time_default_billable' => array(
				'label' => __( 'New entries', 'bonsai-support-tickets' ),
				'text'  => __( 'Billable by default', 'bonsai-support-tickets' ),
				'help'  => __( 'Only billable time counts towards a client\'s retainer. Untick "Billable" when logging goodwill or internal time.', 'bonsai-support-tickets' ),
			),
			'time_alerts'           => array(
				'label' => __( 'Retainer alerts', 'bonsai-support-tickets' ),
				'text'  => __( 'Tell the team at 80% and 100% of a retainer', 'bonsai-support-tickets' ),
				'help'  => __( 'Emailed to administrators (and posted to Slack if it\'s on), once per threshold per month. Clients are not told.', 'bonsai-support-tickets' ),
			),
			'time_portal'           => array(
				'label' => __( 'Client portal', 'bonsai-support-tickets' ),
				'text'  => __( 'Show clients their hours this month', 'bonsai-support-tickets' ),
				'help'  => __( 'Clients on a retainer see "Support hours this month: 6.5 of 10 used" on My requests. No entry details or notes are shown.', 'bonsai-support-tickets' ),
			),
		);
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Time tracking', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Record time against tickets as you reply, see each client\'s retainer used and remaining, and export a month\'s time for invoicing. Retainer hours are set on each client (Support → Clients) and reset on the 1st of each month.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $rows as $key => $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
						<td>
							<input type="hidden" name="bst[<?php echo esc_attr( $key ); ?>]" value="0">
							<label for="bst-<?php echo esc_attr( $key ); ?>"><input type="checkbox" class="bonsai-ui-toggle" id="bst-<?php echo esc_attr( $key ); ?>" name="bst[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $s[ $key ] ); ?>> <?php echo esc_html( $row['text'] ); ?></label>
							<p class="description"><?php echo esc_html( $row['help'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	/*
	|----------------------------------------------------------------------
	| Ticket screen
	|----------------------------------------------------------------------
	*/

	/**
	 * "Log time" fields inside the reply box.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function reply_fields( $post ) {
		unset( $post );
		if ( ! BST_Time::enabled() || ! current_user_can( 'bst_log_time' ) ) {
			return;
		}
		?>
		<fieldset class="bst-log-time">
			<legend><?php esc_html_e( 'Log time', 'bonsai-support-tickets' ); ?></legend>
			<div class="bst-log-time__fields">
				<p>
					<label for="bst-time-duration"><?php esc_html_e( 'Time spent', 'bonsai-support-tickets' ); ?></label>
					<input type="text" name="bst_time" id="bst-time-duration" class="small-text bst-log-time__duration" placeholder="<?php esc_attr_e( '30m', 'bonsai-support-tickets' ); ?>" aria-describedby="bst-time-help" autocomplete="off">
				</p>
				<p>
					<label for="bst-time-date"><?php esc_html_e( 'Date', 'bonsai-support-tickets' ); ?></label>
					<input type="date" name="bst_time_date" id="bst-time-date" value="<?php echo esc_attr( BST_Time::today() ); ?>" max="<?php echo esc_attr( BST_Time::today() ); ?>">
				</p>
				<p class="bst-log-time__note">
					<label for="bst-time-note"><?php esc_html_e( 'Note (team only)', 'bonsai-support-tickets' ); ?></label>
					<input type="text" name="bst_time_note" id="bst-time-note" class="widefat" maxlength="255">
				</p>
				<p class="bst-log-time__billable">
					<input type="hidden" name="bst_time_billable" value="0">
					<label><input type="checkbox" name="bst_time_billable" value="1" <?php checked( BST_Settings::get( 'time_default_billable' ) ); ?>> <?php esc_html_e( 'Billable', 'bonsai-support-tickets' ); ?></label>
				</p>
			</div>
			<p class="description" id="bst-time-help"><?php esc_html_e( 'e.g. 30m, 1h 15m, 1:30 or 1.5 (hours). Saved with your reply, or on its own if you leave the message empty.', 'bonsai-support-tickets' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Save "Log time" from the ticket form. Called by save_ticket() after the
	 * nonce and capability checks.
	 *
	 * @param int $post_id Ticket ID.
	 */
	public static function save_from_ticket( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in BST_Admin_Tickets::save_ticket().
		if ( ! BST_Time::enabled() || ! current_user_can( 'bst_log_time' ) ) {
			return;
		}

		$raw = trim( sanitize_text_field( wp_unslash( $_POST['bst_time'] ?? '' ) ) );
		if ( '' === $raw ) {
			return;
		}

		$minutes = BST_Duration::parse( $raw );
		if ( null === $minutes ) {
			BST_Admin_UI::flash(
				sprintf(
					/* translators: %s: what was typed. */
					__( 'Time not logged: "%s" isn\'t a time we can read. Try 30m, 1h 15m, 1:30 or 1.5 (hours), up to 24 hours.', 'bonsai-support-tickets' ),
					$raw
				),
				'error'
			);
			return;
		}

		$date = sanitize_text_field( wp_unslash( $_POST['bst_time_date'] ?? '' ) );

		$result = BST_Time::add(
			array(
				'ticket_id' => $post_id,
				'minutes'   => $minutes,
				'billable'  => ! empty( $_POST['bst_time_billable'] ),
				'note'      => sanitize_text_field( wp_unslash( $_POST['bst_time_note'] ?? '' ) ),
				// Future dates make no sense for time already spent.
				'work_date' => $date && $date <= BST_Time::today() ? $date : '',
			)
		);
		// phpcs:enable

		if ( is_wp_error( $result ) ) {
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
			return;
		}

		/* translators: %s: duration, e.g. 1h 30m. */
		BST_Admin_UI::flash( sprintf( __( '%s logged.', 'bonsai-support-tickets' ), BST_Duration::format( $minutes ) ) );
	}

	/**
	 * Register the ticket's Time box.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function ticket_box( $post ) {
		if ( ! BST_Time::enabled() || ! current_user_can( 'bst_log_time' ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		add_meta_box( 'bst_time', __( 'Time', 'bonsai-support-tickets' ), array( __CLASS__, 'box_ticket_time' ), BST_Post_Types::TICKET, 'side', 'default' );
	}

	/**
	 * Ticket's time entries, totals and its client's retainer this month.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function box_ticket_time( $post ) {
		$entries  = BST_Time::for_ticket( $post->ID );
		$billable = 0;
		$total    = 0;
		foreach ( $entries as $entry ) {
			$total    += (int) $entry->minutes;
			$billable += (int) $entry->billable ? (int) $entry->minutes : 0;
		}

		$company = BST_Companies::for_ticket( $post->ID );
		if ( $company ) {
			echo '<p class="bst-time-box__retainer"><strong>' . esc_html(
				sprintf(
					/* translators: %s: client name. */
					__( '%s this month', 'bonsai-support-tickets' ),
					BST_Companies::name( $company )
				)
			) . '</strong></p>';
			echo self::usage_bar( BST_Time::usage( $company ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in usage_bar().
		}

		if ( ! $entries ) {
			echo '<p class="bst-muted">' . esc_html__( 'No time logged on this ticket yet.', 'bonsai-support-tickets' ) . '</p>';
			return;
		}

		printf(
			'<p class="bst-time-box__total">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: total time, 2: billable time. */
					__( 'This ticket: %1$s (%2$s billable)', 'bonsai-support-tickets' ),
					BST_Duration::format( $total ),
					BST_Duration::format( $billable )
				)
			)
		);
		?>
		<ul class="bst-time-list">
			<?php foreach ( $entries as $entry ) : ?>
				<?php $agent = get_userdata( (int) $entry->user_id ); ?>
				<li>
					<strong><?php echo esc_html( BST_Duration::format( (int) $entry->minutes ) ); ?></strong>
					<?php echo self::billable_badge( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
					<br>
					<span class="bst-muted"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $entry->work_date . ' 12:00:00' ) ) . ' · ' . ( $agent ? $agent->display_name : __( 'Unknown', 'bonsai-support-tickets' ) ) ); ?></span>
					<?php if ( '' !== $entry->note ) : ?>
						<br><?php echo esc_html( $entry->note ); ?>
					<?php endif; ?>
					<?php if ( BST_Time::can_edit( $entry ) ) : ?>
						<br>
						<a href="<?php echo esc_url( self::url( array( 'entry' => (int) $entry->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'bonsai-support-tickets' ); ?></a>
						&middot; <?php echo self::delete_link( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delete_link(). ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/*
	|----------------------------------------------------------------------
	| Client screen
	|----------------------------------------------------------------------
	*/

	/**
	 * Register the client's Retainer box.
	 *
	 * @param WP_Post $post Client.
	 */
	public static function company_box( $post ) {
		if ( ! BST_Time::enabled() || ! current_user_can( 'bst_log_time' ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		add_meta_box( 'bst_company_time', __( 'Retainer this month', 'bonsai-support-tickets' ), array( __CLASS__, 'box_company_time' ), BST_Post_Types::COMPANY, 'side', 'high' );
	}

	/**
	 * Client's retainer use this month.
	 *
	 * @param WP_Post $post Client.
	 */
	public static function box_company_time( $post ) {
		$usage = BST_Time::usage( $post->ID );
		echo self::usage_bar( $usage ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in usage_bar().
		if ( $usage['nonbillable'] ) {
			/* translators: %s: non-billable time. */
			echo '<p class="bst-muted">' . esc_html( sprintf( __( 'Plus %s non-billable.', 'bonsai-support-tickets' ), BST_Duration::format( $usage['nonbillable'] ) ) ) . '</p>';
		}
		if ( current_user_can( 'bst_manage_time' ) ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url(
					self::url(
						array(
							'company' => $post->ID,
							'month'   => BST_Time::current_month(),
						)
					)
				),
				esc_html__( 'See this month\'s time', 'bonsai-support-tickets' )
			);
		}
	}

	/**
	 * Clients list "Retainer" column while time tracking is on.
	 *
	 * @param int $company_id Client.
	 * @return string HTML, or '' to fall back to the plain hours.
	 */
	public static function company_column( $company_id ) {
		if ( ! BST_Time::enabled() || ! current_user_can( 'bst_log_time' ) ) {
			return '';
		}
		$usage = BST_Time::usage( $company_id );
		return 'none' === $usage['level'] && ! $usage['used'] ? '' : self::usage_bar( $usage );
	}

	/*
	|----------------------------------------------------------------------
	| Support → Time
	|----------------------------------------------------------------------
	*/

	/**
	 * Page router: edit one entry, a client's month, all clients (managers),
	 * or the agent's own month.
	 */
	public static function render() {
		if ( ! current_user_can( 'bst_log_time' ) || ! BST_Time::enabled() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$entry_id = absint( $_GET['entry'] ?? 0 );
		$month    = BST_Time::sanitize_month( sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) );
		$company  = isset( $_GET['company'] ) ? absint( $_GET['company'] ) : null;
		// phpcs:enable

		$manager = current_user_can( 'bst_manage_time' );
		?>
		<div class="wrap bonsai-ui">
			<?php
			BST_Admin_UI::header(
				__( 'Time', 'bonsai-support-tickets' ),
				$manager
					? __( 'Time logged on tickets each month, against each client\'s retainer. Only billable time counts towards a retainer. Export a month as CSV for invoicing.', 'bonsai-support-tickets' )
					: __( 'Time you\'ve logged on tickets.', 'bonsai-support-tickets' ),
				array(
					array(
						'label' => __( 'Tickets', 'bonsai-support-tickets' ),
						'url'   => admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ),
					),
				)
			);

			if ( $entry_id ) {
				self::render_edit( $entry_id );
			} elseif ( $manager && null !== $company ) {
				self::render_company( $company, $month );
			} elseif ( $manager ) {
				self::render_summary( $month );
			} else {
				self::render_mine( $month );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Month picker with previous/next links.
	 *
	 * @param string $month Y-m.
	 * @param array  $keep  Other query args to keep.
	 */
	private static function month_nav( $month, array $keep = array() ) {
		$time = strtotime( $month . '-15 12:00:00' );
		$prev = gmdate( 'Y-m', strtotime( '-1 month', $time ) );
		$next = gmdate( 'Y-m', strtotime( '+1 month', $time ) );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="bst-month-nav">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( BST_Post_Types::TICKET ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php foreach ( $keep as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<a class="button" href="<?php echo esc_url( self::url( array_merge( $keep, array( 'month' => $prev ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'bonsai-support-tickets' ); ?>">&larr;</a>
			<label class="screen-reader-text" for="bst-month"><?php esc_html_e( 'Month', 'bonsai-support-tickets' ); ?></label>
			<input type="month" id="bst-month" name="month" value="<?php echo esc_attr( $month ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Show', 'bonsai-support-tickets' ); ?></button>
			<?php if ( $month < BST_Time::current_month() ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( array_merge( $keep, array( 'month' => $next ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'bonsai-support-tickets' ); ?>">&rarr;</a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Export button (admin-post, nonce).
	 *
	 * @param string   $type    entries|summary.
	 * @param string   $month   Y-m.
	 * @param int|null $company Client or null for all.
	 * @param string   $label   Button text.
	 */
	private static function export_button( $type, $month, $company, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bst_time_export">
			<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
			<?php if ( null !== $company ) : ?>
				<input type="hidden" name="company" value="<?php echo esc_attr( $company ); ?>">
			<?php endif; ?>
			<?php wp_nonce_field( 'bst_time_export' ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * All clients for a month.
	 *
	 * @param string $month Y-m.
	 */
	private static function render_summary( $month ) {
		$rows = BST_Time::summary( $month );
		?>
		<section class="bonsai-ui-card">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title"><?php echo esc_html( BST_Time::month_label( $month ) ); ?></h2>
				<?php self::month_nav( $month ); ?>
			</div>

			<?php if ( ! $rows ) : ?>
				<p class="bst-muted"><?php esc_html_e( 'No time logged this month, and no clients have a retainer. Set retainer hours on a client under Support → Clients.', 'bonsai-support-tickets' ); ?></p>
			<?php else : ?>
				<table class="widefat striped bst-time-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Client', 'bonsai-support-tickets' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Retainer', 'bonsai-support-tickets' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Billable', 'bonsai-support-tickets' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Non-billable', 'bonsai-support-tickets' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Used', 'bonsai-support-tickets' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php $usage = $row['usage']; ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( self::url( array( 'company' => (string) $row['company_id'], 'month' => $month ) ) ); ?>"><strong><?php echo esc_html( $row['name'] ); ?></strong></a>
								</td>
								<td><?php echo $usage['allowance'] ? esc_html( BST_Duration::format( $usage['allowance'] ) ) : '<span class="bst-muted">&mdash;</span>'; ?></td>
								<td><?php echo esc_html( BST_Duration::format( $usage['used'] ) ); ?></td>
								<td><?php echo esc_html( BST_Duration::format( $usage['nonbillable'] ) ); ?></td>
								<td><?php echo self::usage_bar( $usage ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in usage_bar(). ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="bonsai-ui-card__footer bonsai-ui-actions">
				<?php
				self::export_button( 'summary', $month, null, __( 'Export summary (CSV)', 'bonsai-support-tickets' ) );
				self::export_button( 'entries', $month, null, __( 'Export all entries (CSV)', 'bonsai-support-tickets' ) );
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * One client's month.
	 *
	 * @param int    $company Client (0 = no client).
	 * @param string $month   Y-m.
	 */
	private static function render_company( $company, $month ) {
		$name    = $company ? BST_Companies::name( $company ) : __( 'No client', 'bonsai-support-tickets' );
		$entries = BST_Time::for_month( $month, $company );
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'month' => $month ) ) ); ?>">&larr; <?php esc_html_e( 'All clients', 'bonsai-support-tickets' ); ?></a></p>
		<section class="bonsai-ui-card">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title">
					<?php
					/* translators: 1: client name, 2: month. */
					echo esc_html( sprintf( __( '%1$s · %2$s', 'bonsai-support-tickets' ), $name, BST_Time::month_label( $month ) ) );
					?>
				</h2>
				<?php self::month_nav( $month, array( 'company' => (string) $company ) ); ?>
			</div>
			<?php if ( $company ) : ?>
				<?php echo self::usage_bar( BST_Time::usage( $company, $month ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in usage_bar(). ?>
			<?php endif; ?>
			<?php self::entries_table( $entries, false ); ?>
			<div class="bonsai-ui-card__footer bonsai-ui-actions">
				<?php self::export_button( 'entries', $month, $company, __( 'Export CSV', 'bonsai-support-tickets' ) ); ?>
				<?php if ( $company ) : ?>
					<a class="button-link" href="<?php echo esc_url( get_edit_post_link( $company ) ); ?>"><?php esc_html_e( 'Edit client', 'bonsai-support-tickets' ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * An agent's own month.
	 *
	 * @param string $month Y-m.
	 */
	private static function render_mine( $month ) {
		$entries = BST_Time::for_month( $month, null, get_current_user_id() );
		$total   = array_sum( array_map( 'intval', wp_list_pluck( $entries, 'minutes' ) ) );
		?>
		<section class="bonsai-ui-card">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title">
					<?php
					/* translators: 1: month, 2: total time. */
					echo esc_html( sprintf( __( '%1$s · %2$s logged', 'bonsai-support-tickets' ), BST_Time::month_label( $month ), BST_Duration::format( $total ) ) );
					?>
				</h2>
				<?php self::month_nav( $month ); ?>
			</div>
			<?php self::entries_table( $entries, true ); ?>
		</section>
		<?php
	}

	/**
	 * Entries table.
	 *
	 * @param object[] $entries   Entries.
	 * @param bool     $show_client Show the client column (agent view) instead of agent.
	 */
	private static function entries_table( array $entries, $show_client ) {
		if ( ! $entries ) {
			echo '<p class="bst-muted">' . esc_html__( 'No time logged this month.', 'bonsai-support-tickets' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped bst-time-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ticket', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php echo $show_client ? esc_html__( 'Client', 'bonsai-support-tickets' ) : esc_html__( 'Agent', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Note', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'bonsai-support-tickets' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$agent = get_userdata( (int) $entry->user_id );
					$who   = $show_client
						? ( (int) $entry->company_id ? BST_Companies::name( (int) $entry->company_id ) : __( 'No client', 'bonsai-support-tickets' ) )
						: ( $agent ? $agent->display_name : __( 'Unknown', 'bonsai-support-tickets' ) );
					?>
					<tr>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $entry->work_date . ' 12:00:00' ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( BST_Tickets::admin_url( (int) $entry->ticket_id ) ); ?>"><?php echo esc_html( BST_Tickets::ref( (int) $entry->ticket_id ) ); ?></a>
							<?php echo esc_html( get_the_title( (int) $entry->ticket_id ) ); ?>
						</td>
						<td><?php echo esc_html( $who ); ?></td>
						<td>
							<?php echo esc_html( BST_Duration::format( (int) $entry->minutes ) ); ?>
							<?php echo self::billable_badge( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
						</td>
						<td><?php echo esc_html( $entry->note ); ?></td>
						<td>
							<?php if ( BST_Time::can_edit( $entry ) ) : ?>
								<a href="<?php echo esc_url( self::url( array( 'entry' => (int) $entry->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'bonsai-support-tickets' ); ?></a>
								&middot; <?php echo self::delete_link( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delete_link(). ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Edit one entry.
	 *
	 * @param int $entry_id Entry.
	 */
	private static function render_edit( $entry_id ) {
		$entry = BST_Time::get( $entry_id );
		if ( ! $entry || ! BST_Time::can_edit( $entry ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'That time entry doesn\'t exist, or it isn\'t yours to edit.', 'bonsai-support-tickets' ) . '</p></div>';
			return;
		}
		?>
		<p><a href="<?php echo esc_url( BST_Tickets::admin_url( (int) $entry->ticket_id ) ); ?>">&larr; <?php echo esc_html( BST_Tickets::ref( (int) $entry->ticket_id ) . ' ' . get_the_title( (int) $entry->ticket_id ) ); ?></a></p>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Edit time entry', 'bonsai-support-tickets' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bst_time_update">
				<input type="hidden" name="entry" value="<?php echo esc_attr( $entry->id ); ?>">
				<?php wp_nonce_field( 'bst_time_update_' . (int) $entry->id ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bst-edit-duration"><?php esc_html_e( 'Time spent', 'bonsai-support-tickets' ); ?></label></th>
						<td>
							<input type="text" name="bst_time" id="bst-edit-duration" class="small-text" value="<?php echo esc_attr( BST_Duration::format( (int) $entry->minutes ) ); ?>" required>
							<p class="description"><?php esc_html_e( 'e.g. 30m, 1h 15m, 1:30 or 1.5 (hours).', 'bonsai-support-tickets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bst-edit-date"><?php esc_html_e( 'Date', 'bonsai-support-tickets' ); ?></label></th>
						<td><input type="date" name="bst_time_date" id="bst-edit-date" value="<?php echo esc_attr( $entry->work_date ); ?>" max="<?php echo esc_attr( BST_Time::today() ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Billable', 'bonsai-support-tickets' ); ?></th>
						<td>
							<input type="hidden" name="bst_time_billable" value="0">
							<label><input type="checkbox" name="bst_time_billable" value="1" <?php checked( (int) $entry->billable ); ?>> <?php esc_html_e( 'Counts towards the client\'s retainer', 'bonsai-support-tickets' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bst-edit-note"><?php esc_html_e( 'Note (team only)', 'bonsai-support-tickets' ); ?></label></th>
						<td><input type="text" name="bst_time_note" id="bst-edit-note" class="regular-text" maxlength="255" value="<?php echo esc_attr( $entry->note ); ?>"></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'bonsai-support-tickets' ); ?></button>
					<?php echo self::delete_link( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delete_link(). ?>
				</p>
			</form>
		</section>
		<?php
	}

	/*
	|----------------------------------------------------------------------
	| Handlers
	|----------------------------------------------------------------------
	*/

	/**
	 * Entry from the request, if this user may change it; dies otherwise.
	 *
	 * @param string $nonce_action Nonce action prefix.
	 * @return object Entry.
	 */
	private static function guard_entry( $nonce_action ) {
		$entry_id = absint( $_REQUEST['entry'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next, nonce is per entry.
		check_admin_referer( $nonce_action . '_' . $entry_id );

		$entry = BST_Time::get( $entry_id );
		if ( ! BST_Time::enabled() || ! $entry || ! BST_Time::can_edit( $entry ) ) {
			wp_die( esc_html__( 'You can\'t change this time entry.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		return $entry;
	}

	/**
	 * Save an edited entry.
	 */
	public static function handle_update() {
		$entry = self::guard_entry( 'bst_time_update' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard_entry().
		$raw     = sanitize_text_field( wp_unslash( $_POST['bst_time'] ?? '' ) );
		$minutes = BST_Duration::parse( $raw );
		$back    = self::url( array( 'entry' => (int) $entry->id ) );

		if ( null === $minutes ) {
			/* translators: %s: what was typed. */
			BST_Admin_UI::flash( sprintf( __( '"%s" isn\'t a time we can read. Try 30m, 1h 15m, 1:30 or 1.5 (hours), up to 24 hours.', 'bonsai-support-tickets' ), $raw ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$date   = sanitize_text_field( wp_unslash( $_POST['bst_time_date'] ?? '' ) );
		$result = BST_Time::update(
			(int) $entry->id,
			array(
				'minutes'   => $minutes,
				'billable'  => ! empty( $_POST['bst_time_billable'] ),
				'note'      => sanitize_text_field( wp_unslash( $_POST['bst_time_note'] ?? '' ) ),
				'work_date' => $date && $date <= BST_Time::today() ? $date : $entry->work_date,
			)
		);
		// phpcs:enable

		if ( is_wp_error( $result ) ) {
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		BST_Admin_UI::flash( __( 'Time entry saved.', 'bonsai-support-tickets' ) );
		wp_safe_redirect( BST_Tickets::admin_url( (int) $entry->ticket_id ) );
		exit;
	}

	/**
	 * Delete an entry, then go back where we came from.
	 */
	public static function handle_delete() {
		$entry = self::guard_entry( 'bst_time_delete' );
		BST_Time::delete( (int) $entry->id );
		BST_Admin_UI::flash( __( 'Time entry deleted.', 'bonsai-support-tickets' ) );

		$back = wp_get_referer();
		// Coming from the entry's own edit page, which no longer exists.
		if ( ! $back || false !== strpos( $back, 'entry=' ) ) {
			$back = BST_Tickets::admin_url( (int) $entry->ticket_id );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * CSV download.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'bst_manage_time' ) || ! BST_Time::enabled() ) {
			wp_die( esc_html__( 'You do not have permission to export time.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_time_export' );

		$month   = BST_Time::sanitize_month( sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) ) );
		$type    = sanitize_key( wp_unslash( $_POST['type'] ?? 'entries' ) );
		$company = isset( $_POST['company'] ) ? absint( $_POST['company'] ) : null;

		try {
			if ( 'summary' === $type ) {
				BST_Time::send_csv( 'time-summary-' . $month . '.csv', BST_Time::summary_csv_rows( $month ) );
			}

			$slug = null === $company ? 'all-clients' : ( $company ? sanitize_title( BST_Companies::name( $company ) ) : 'no-client' );
			BST_Time::send_csv( 'time-' . $slug . '-' . $month . '.csv', BST_Time::entries_csv_rows( $month, $company ) );
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': time export failed: ' . $e->getMessage() );
			wp_die( esc_html__( 'The export failed. Please try again.', 'bonsai-support-tickets' ) );
		}
	}
}
