<?php
/**
 * SLA and reminder screens: the settings tab, plan targets, the ticket
 * list's Due column, the ticket's SLA box and Support → SLA report.
 *
 * Nothing but the settings tab shows while SLAs are off. Reminders have no
 * screens of their own beyond their settings.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * SLA admin.
 */
class BST_Admin_SLA {

	const SLUG       = 'bst-sla';
	const PLAN_NONCE = 'bst_plan_sla_nonce';

	/**
	 * Hooks.
	 */
	public static function init() {
		$plan = BST_Post_Types::PLAN;

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bst_refresh_holidays', array( __CLASS__, 'handle_refresh_holidays' ) );
		add_action( 'admin_post_bst_sla_export', array( __CLASS__, 'handle_export' ) );

		add_action( "{$plan}_add_form_fields", array( __CLASS__, 'plan_add_fields' ) );
		add_action( "{$plan}_edit_form_fields", array( __CLASS__, 'plan_edit_fields' ) );
		add_action( "created_{$plan}", array( __CLASS__, 'save_plan' ) );
		add_action( "edited_{$plan}", array( __CLASS__, 'save_plan' ), 5 );

		$type = BST_Post_Types::TICKET;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ), 20 );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_by_due' ), 20 );
		add_filter( 'posts_orderby', array( __CLASS__, 'due_nulls_last' ), 10, 2 );
		add_action( "add_meta_boxes_{$type}", array( __CLASS__, 'ticket_box' ) );
	}

	/**
	 * Support → SLA report URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET . '&page=' . self::SLUG ) );
	}

	/**
	 * Report submenu, only while SLAs are on.
	 */
	public static function menu() {
		if ( ! BST_SLA::enabled() ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . BST_Post_Types::TICKET,
			__( 'SLA report', 'bonsai-support-tickets' ),
			__( 'SLA report', 'bonsai-support-tickets' ),
			'bst_view_all_tickets',
			self::SLUG,
			array( __CLASS__, 'render_report' )
		);
	}

	/**
	 * Hours for a form field: 90 → "1.5", 0 → "".
	 *
	 * @param int  $minutes    Minutes.
	 * @param bool $zero_blank Show 0 as blank.
	 * @return string
	 */
	private static function hours_value( $minutes, $zero_blank = true ) {
		if ( ! $minutes && $zero_blank ) {
			return '';
		}
		return rtrim( rtrim( number_format( $minutes / 60, 2, '.', '' ), '0' ), '.' );
	}

	/*
	|----------------------------------------------------------------------
	| Settings tab
	|----------------------------------------------------------------------
	*/

	/**
	 * Settings → SLAs & reminders (inside the tab's form).
	 *
	 * @param array $s Settings.
	 */
	public static function render_settings( array $s ) {
		$days       = array_map( 'intval', explode( ',', (string) $s['sla_days'] ) );
		$day_names  = array(
			1 => __( 'Mon', 'bonsai-support-tickets' ),
			2 => __( 'Tue', 'bonsai-support-tickets' ),
			3 => __( 'Wed', 'bonsai-support-tickets' ),
			4 => __( 'Thu', 'bonsai-support-tickets' ),
			5 => __( 'Fri', 'bonsai-support-tickets' ),
			6 => __( 'Sat', 'bonsai-support-tickets' ),
			7 => __( 'Sun', 'bonsai-support-tickets' ),
		);
		$targets    = BST_SLA::sanitize_targets( $s['sla_targets'] );
		$reminders  = BST_SLA::sanitize_reminders( $s['reminder_rules'] );
		$priorities = BST_Tickets::priorities();
		$day_hours  = self::hours_value( BST_SLA::clock()->minutes_per_day(), false );
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Business hours', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Used by SLAs and reminders, so a ticket raised at 5pm on Friday isn\'t late at 9am on Monday. Times are in the site\'s timezone (Settings → General).', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Working days', 'bonsai-support-tickets' ); ?></th>
					<td>
						<fieldset class="bst-days">
							<legend class="screen-reader-text"><?php esc_html_e( 'Working days', 'bonsai-support-tickets' ); ?></legend>
							<input type="hidden" name="bst[sla_days][]" value="">
							<?php foreach ( $day_names as $num => $name ) : ?>
								<label><input type="checkbox" name="bst[sla_days][]" value="<?php echo esc_attr( $num ); ?>" <?php checked( in_array( $num, $days, true ) ); ?>> <?php echo esc_html( $name ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-sla-start"><?php esc_html_e( 'Hours', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="time" id="bst-sla-start" name="bst[sla_start]" value="<?php echo esc_attr( $s['sla_start'] ); ?>" aria-label="<?php esc_attr_e( 'Opening time', 'bonsai-support-tickets' ); ?>">
						<?php esc_html_e( 'to', 'bonsai-support-tickets' ); ?>
						<input type="time" id="bst-sla-end" name="bst[sla_end]" value="<?php echo esc_attr( $s['sla_end'] ); ?>" aria-label="<?php esc_attr_e( 'Closing time', 'bonsai-support-tickets' ); ?>">
						<p class="description">
							<?php
							/* translators: %s: hours in a working day, e.g. 8.5. */
							echo esc_html( sprintf( __( 'A working day is %s hours. Use that when setting targets in days.', 'bonsai-support-tickets' ), $day_hours ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-sla-region"><?php esc_html_e( 'Bank holidays', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<select id="bst-sla-region" name="bst[sla_holiday_region]">
							<?php foreach ( BST_SLA::holiday_regions() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['sla_holiday_region'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'UK bank holidays come from gov.uk and update weekly.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-sla-closed"><?php esc_html_e( 'Other closed days', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<textarea id="bst-sla-closed" name="bst[sla_closed_days]" rows="3" class="regular-text" placeholder="2026-12-29&#10;2026-12-30"><?php echo esc_textarea( $s['sla_closed_days'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One date per line (YYYY-MM-DD), e.g. a Christmas shutdown.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'SLAs', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Response and resolution targets per priority. First response is met by the first reply to the client (not an internal note); resolution by Solved. The resolution clock pauses while a ticket is Awaiting client or On hold. Plans (Support → Clients → Plans) can set their own targets, e.g. Bronze, Silver and Gold support.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'SLAs', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[sla_enabled]" value="0">
						<label for="bst-sla-enabled"><input type="checkbox" class="bonsai-ui-toggle" id="bst-sla-enabled" name="bst[sla_enabled]" value="1" <?php checked( $s['sla_enabled'] ); ?>> <?php esc_html_e( 'Measure response and resolution times', 'bonsai-support-tickets' ); ?></label>
						<p class="description"><?php esc_html_e( 'Applies to tickets created from now on. Adds a Due column, an SLA box on tickets, warnings and Support → SLA report.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-sla-warn"><?php esc_html_e( 'Warn when', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="number" id="bst-sla-warn" name="bst[sla_warn_percent]" class="small-text" min="5" max="90" value="<?php echo esc_attr( $s['sla_warn_percent'] ); ?>">
						<?php esc_html_e( '% of the time is left', 'bonsai-support-tickets' ); ?>
						<p class="description"><?php esc_html_e( 'One warning and one breach email per ticket and target, to the assignee (or all agents if unassigned), plus Slack if it\'s on.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
			</table>

			<table class="widefat striped bst-sla-targets">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Priority', 'bonsai-support-tickets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First response (hours)', 'bonsai-support-tickets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Resolution (hours)', 'bonsai-support-tickets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Clock', 'bonsai-support-tickets' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $priorities as $slug => $priority ) : ?>
						<?php $t = $targets[ $slug ]; ?>
						<tr>
							<th scope="row"><?php echo esc_html( $priority['label'] ); ?></th>
							<td><input type="number" min="0.25" max="2160" step="0.25" class="small-text" name="bst[sla_targets][<?php echo esc_attr( $slug ); ?>][response_hours]" value="<?php echo esc_attr( self::hours_value( $t['response'], false ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: priority. */ __( '%s first response, hours', 'bonsai-support-tickets' ), $priority['label'] ) ); ?>"></td>
							<td><input type="number" min="0.25" max="2160" step="0.25" class="small-text" name="bst[sla_targets][<?php echo esc_attr( $slug ); ?>][resolution_hours]" value="<?php echo esc_attr( self::hours_value( $t['resolution'], false ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: priority. */ __( '%s resolution, hours', 'bonsai-support-tickets' ), $priority['label'] ) ); ?>"></td>
							<td>
								<select name="bst[sla_targets][<?php echo esc_attr( $slug ); ?>][clock]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: priority. */ __( '%s clock', 'bonsai-support-tickets' ), $priority['label'] ) ); ?>">
									<option value="business" <?php selected( $t['clock'], 'business' ); ?>><?php esc_html_e( 'Business hours', 'bonsai-support-tickets' ); ?></option>
									<option value="always" <?php selected( $t['clock'], 'always' ); ?>><?php esc_html_e( '24/7', 'bonsai-support-tickets' ); ?></option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Response reminders', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Email the assignee (or all agents if unassigned) while a ticket is waiting on us: New or Open. Awaiting client, On hold, Solved and Closed tickets never get reminders. Counted on each priority\'s clock above, and restarted when the client replies. Works with or without SLAs.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Reminders', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[reminders_enabled]" value="0">
						<label for="bst-reminders-enabled"><input type="checkbox" class="bonsai-ui-toggle" id="bst-reminders-enabled" name="bst[reminders_enabled]" value="1" <?php checked( $s['reminders_enabled'] ); ?>> <?php esc_html_e( 'Send reminders for tickets waiting on us', 'bonsai-support-tickets' ); ?></label>
					</td>
				</tr>
			</table>
			<table class="widefat striped bst-sla-targets">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Priority', 'bonsai-support-tickets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First reminder after (hours)', 'bonsai-support-tickets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Then every (hours)', 'bonsai-support-tickets' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $priorities as $slug => $priority ) : ?>
						<?php $r = $reminders[ $slug ]; ?>
						<tr>
							<th scope="row"><?php echo esc_html( $priority['label'] ); ?></th>
							<td><input type="number" min="0" max="2160" step="0.25" class="small-text" name="bst[reminder_rules][<?php echo esc_attr( $slug ); ?>][first_hours]" value="<?php echo esc_attr( self::hours_value( $r['first'] ) ); ?>" placeholder="<?php esc_attr_e( 'Off', 'bonsai-support-tickets' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: priority. */ __( '%s first reminder, hours', 'bonsai-support-tickets' ), $priority['label'] ) ); ?>"></td>
							<td><input type="number" min="0" max="2160" step="0.25" class="small-text" name="bst[reminder_rules][<?php echo esc_attr( $slug ); ?>][repeat_hours]" value="<?php echo esc_attr( self::hours_value( $r['repeat'] ) ); ?>" placeholder="<?php esc_attr_e( 'Once', 'bonsai-support-tickets' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: priority. */ __( '%s repeat reminder, hours', 'bonsai-support-tickets' ), $priority['label'] ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Leave "First reminder" blank to turn reminders off for that priority, and "Then every" blank for a single reminder.', 'bonsai-support-tickets' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Settings tab, below the form: bank holiday status and refresh.
	 *
	 * @param array $s Settings.
	 */
	public static function render_settings_status( array $s ) {
		$region  = (string) $s['sla_holiday_region'];
		$fetched = BST_SLA::holidays_fetched_at();
		$today   = wp_date( 'Y-m-d' );
		$next    = array_values(
			array_filter(
				BST_SLA::closed_days(),
				function ( $day ) use ( $today ) {
					return $day >= $today;
				}
			)
		);
		sort( $next );
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Closed days', 'bonsai-support-tickets' ); ?></h2>
			<dl class="bonsai-ui-status">
				<dt><?php esc_html_e( 'Bank holidays', 'bonsai-support-tickets' ); ?></dt>
				<dd>
					<?php
					if ( 'none' === $region ) {
						esc_html_e( 'None', 'bonsai-support-tickets' );
					} elseif ( $fetched ) {
						/* translators: 1: region, 2: date and time. */
						echo esc_html( sprintf( __( '%1$s, from gov.uk (updated %2$s)', 'bonsai-support-tickets' ), BST_SLA::holiday_regions()[ $region ] ?? $region, wp_date( get_option( 'date_format' ), $fetched ) ) );
					} else {
						/* translators: %s: region. */
						echo esc_html( sprintf( __( '%s, built-in list (not fetched from gov.uk yet)', 'bonsai-support-tickets' ), BST_SLA::holiday_regions()[ $region ] ?? $region ) );
					}
					?>
				</dd>
				<dt><?php esc_html_e( 'Next closed days', 'bonsai-support-tickets' ); ?></dt>
				<dd>
					<?php
					echo $next
						? esc_html( implode( ', ', array_map( fn( $day ) => wp_date( 'D j M Y', strtotime( $day . ' 12:00:00' ) ), array_slice( $next, 0, 6 ) ) ) )
						: esc_html__( 'None listed', 'bonsai-support-tickets' );
					?>
				</dd>
			</dl>
			<div class="bonsai-ui-card__footer bonsai-ui-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bst_refresh_holidays">
					<?php wp_nonce_field( 'bst_refresh_holidays' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Update bank holidays now', 'bonsai-support-tickets' ); ?></button>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Fetch bank holidays now.
	 */
	public static function handle_refresh_holidays() {
		if ( ! current_user_can( 'bst_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to change support settings.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_refresh_holidays' );

		$result = BST_SLA::refresh_holidays();
		if ( is_wp_error( $result ) ) {
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
		} else {
			BST_Admin_UI::flash( __( 'Bank holidays updated from gov.uk.', 'bonsai-support-tickets' ) );
		}
		wp_safe_redirect( BST_Admin_Settings::url( 'sla' ) );
		exit;
	}

	/*
	|----------------------------------------------------------------------
	| Plans
	|----------------------------------------------------------------------
	*/

	/**
	 * Target fields for a plan.
	 *
	 * @param array $values priority => array( response, resolution ) minutes.
	 */
	private static function plan_fields( array $values ) {
		$defaults = BST_SLA::targets();
		wp_nonce_field( 'bst_save_plan_sla', self::PLAN_NONCE );
		?>
		<table class="widefat striped bst-sla-targets">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Priority', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'First response (hours)', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Resolution (hours)', 'bonsai-support-tickets' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( BST_Tickets::priorities() as $slug => $priority ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $priority['label'] ); ?></th>
						<?php foreach ( array( 'response', 'resolution' ) as $metric ) : ?>
							<td>
								<input type="number" min="0" max="2160" step="0.25" class="small-text"
									name="bst_plan_sla[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $metric ); ?>]"
									value="<?php echo esc_attr( self::hours_value( $values[ $slug ][ $metric ] ?? 0 ) ); ?>"
									placeholder="<?php echo esc_attr( self::hours_value( $defaults[ $slug ][ $metric ], false ) ); ?>"
									aria-label="<?php echo esc_attr( $priority['label'] . ' ' . ( 'response' === $metric ? __( 'first response, hours', 'bonsai-support-tickets' ) : __( 'resolution, hours', 'bonsai-support-tickets' ) ) ); ?>">
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Blank uses the default from Settings → SLAs & reminders (shown in grey). Clocks (business hours or 24/7) follow the priority.', 'bonsai-support-tickets' ); ?></p>
		<?php
	}

	/**
	 * Add-plan form.
	 */
	public static function plan_add_fields() {
		if ( ! BST_SLA::enabled() ) {
			return;
		}
		echo '<div class="form-field"><label>' . esc_html__( 'SLA targets', 'bonsai-support-tickets' ) . '</label>';
		self::plan_fields( array() );
		echo '</div>';
	}

	/**
	 * Edit-plan form.
	 *
	 * @param WP_Term $term Plan.
	 */
	public static function plan_edit_fields( $term ) {
		if ( ! BST_SLA::enabled() ) {
			return;
		}
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'SLA targets', 'bonsai-support-tickets' ) . '</th><td>';
		self::plan_fields( BST_SLA::plan_targets( $term->term_id ) );
		echo '</td></tr>';
	}

	/**
	 * Save a plan's targets.
	 *
	 * @param int $term_id Plan.
	 */
	public static function save_plan( $term_id ) {
		if ( ! isset( $_POST[ self::PLAN_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::PLAN_NONCE ] ) ), 'bst_save_plan_sla' ) ) {
			return;
		}
		if ( ! current_user_can( 'bst_manage_settings' ) ) {
			return;
		}

		$input = isset( $_POST['bst_plan_sla'] ) && is_array( $_POST['bst_plan_sla'] ) ? wp_unslash( $_POST['bst_plan_sla'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- numbers, cast below.
		$clean = array();
		foreach ( array_keys( BST_Tickets::priorities() ) as $slug ) {
			foreach ( array( 'response', 'resolution' ) as $metric ) {
				$hours = isset( $input[ $slug ][ $metric ] ) ? (float) str_replace( ',', '.', (string) $input[ $slug ][ $metric ] ) : 0;
				$clean[ $slug ][ $metric ] = max( 0, min( 60 * 24 * 90, (int) round( $hours * 60 ) ) );
			}
		}
		update_term_meta( (int) $term_id, BST_SLA::PLAN_META, $clean );
	}

	/*
	|----------------------------------------------------------------------
	| Ticket list and ticket screen
	|----------------------------------------------------------------------
	*/

	/**
	 * Add Due before Last activity.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		if ( ! BST_SLA::enabled() ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'bst_activity' === $key ) {
				$out['bst_due'] = __( 'Due', 'bonsai-support-tickets' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['bst_due'] ) ) {
			$out['bst_due'] = __( 'Due', 'bonsai-support-tickets' );
		}
		return $out;
	}

	/**
	 * Due column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Ticket.
	 */
	public static function column( $column, $post_id ) {
		if ( 'bst_due' !== $column ) {
			return;
		}
		echo self::due_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in due_html().
	}

	/**
	 * Due time: date shown is the deadline; colour says how close it is.
	 * Hover shows the working time left.
	 *
	 * @param int $ticket_id Ticket.
	 * @return string HTML.
	 */
	public static function due_html( $ticket_id ) {
		if ( ! BST_SLA::applies( $ticket_id ) ) {
			return '<span class="bst-muted">&mdash;</span>';
		}

		$state = BST_SLA::state( $ticket_id );
		$warn  = max( 1, (int) BST_Settings::get( 'sla_warn_percent' ) ) / 100;

		// What's pending: first response first, then resolution.
		if ( ! get_post_meta( $ticket_id, BST_SLA::META_FIRST_RESPONSE, true ) ) {
			$label  = __( 'Reply', 'bonsai-support-tickets' );
			$due    = $state['response_due'];
			$left   = $state['response_left'];
			$target = $state['targets']['response'];
		} elseif ( in_array( $state['resolution'], array( 'pending', 'breached' ), true ) ) {
			$label  = __( 'Resolve', 'bonsai-support-tickets' );
			$due    = $state['resolution_due'];
			$left   = $state['resolution_left'];
			$target = $state['targets']['resolution'];
		} elseif ( 'paused' === $state['resolution'] ) {
			return BST_Admin_UI::badge( __( 'Paused', 'bonsai-support-tickets' ), 'neutral' );
		} else {
			return 'met' === $state['resolution']
				? BST_Admin_UI::badge( __( 'Met', 'bonsai-support-tickets' ), 'success' )
				: BST_Admin_UI::badge( __( 'Missed', 'bonsai-support-tickets' ), 'error' );
		}

		$level = $left < 0 ? 'over' : ( $left <= $target * $warn ? 'warning' : 'ok' );
		$when  = wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $due->getTimestamp() )
			? wp_date( get_option( 'time_format' ), $due->getTimestamp() )
			: wp_date( 'D j M, ' . get_option( 'time_format' ), $due->getTimestamp() );
		$title = $left < 0
			/* translators: %s: working time overdue. */
			? sprintf( __( 'Overdue by %s of working time', 'bonsai-support-tickets' ), BST_Duration::format( -$left ) )
			/* translators: %s: working time left. */
			: sprintf( __( '%s of working time left', 'bonsai-support-tickets' ), BST_Duration::format( $left ) );

		return sprintf(
			'<span class="bst-due bst-due--%1$s" title="%2$s"><span class="bst-due__label">%3$s</span> %4$s<span class="screen-reader-text"> (%2$s)</span></span>',
			esc_attr( $level ),
			esc_attr( $title ),
			esc_html( $left < 0 ? sprintf( /* translators: Reply or Resolve. */ __( '%s overdue', 'bonsai-support-tickets' ), $label ) : $label ),
			esc_html( $when )
		);
	}

	/**
	 * Due is sortable.
	 *
	 * @param array $columns Sortable.
	 * @return array
	 */
	public static function sortable( $columns ) {
		if ( BST_SLA::enabled() ) {
			$columns['bst_due'] = 'bst_due';
		}
		return $columns;
	}

	/**
	 * Sort by next due time. Tickets without an SLA (no due key) stay in the
	 * list (see due_nulls_last()) rather than being dropped by the join.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function sort_by_due( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || BST_Post_Types::TICKET !== $query->get( 'post_type' ) || 'bst_due' !== $query->get( 'orderby' ) ) {
			return;
		}
		$meta_query            = (array) $query->get( 'meta_query' );
		$meta_query['bst_due'] = array(
			'relation'    => 'OR',
			'bst_due_set' => array(
				'key'     => BST_SLA::META_NEXT_DUE,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => BST_SLA::META_NEXT_DUE,
				'compare' => 'NOT EXISTS',
			),
		);
		$query->set( 'meta_query', $meta_query );
		$query->set( 'meta_key', '' );
		$query->set( 'orderby', array( 'bst_due_set' => $query->get( 'order' ) ? $query->get( 'order' ) : 'ASC' ) );
		$query->set( 'bst_due_sort', 1 );
	}

	/**
	 * Tickets without an SLA go last in either direction.
	 *
	 * @param string   $orderby ORDER BY clause.
	 * @param WP_Query $query   Query.
	 * @return string
	 */
	public static function due_nulls_last( $orderby, $query ) {
		if ( ! $query->get( 'bst_due_sort' ) || ! $query->meta_query ) {
			return $orderby;
		}
		$clauses = $query->meta_query->get_clauses();
		if ( empty( $clauses['bst_due_set']['alias'] ) ) {
			return $orderby;
		}
		return $clauses['bst_due_set']['alias'] . '.meta_value IS NULL, ' . $orderby;
	}

	/**
	 * Ticket's SLA box.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function ticket_box( $post ) {
		if ( ! BST_SLA::enabled() || 'auto-draft' === $post->post_status || ! BST_SLA::applies( $post->ID ) ) {
			return;
		}
		add_meta_box( 'bst_sla', __( 'SLA', 'bonsai-support-tickets' ), array( __CLASS__, 'box_sla' ), BST_Post_Types::TICKET, 'side', 'high' );
	}

	/**
	 * SLA box: targets and how each is going.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function box_sla( $post ) {
		$state   = BST_SLA::state( $post->ID );
		$targets = $state['targets'];
		$plan    = $targets['plan'] ? get_term( $targets['plan'] ) : null;
		$states  = array(
			'pending'  => array( __( 'Due', 'bonsai-support-tickets' ), 'neutral' ),
			'paused'   => array( __( 'Paused', 'bonsai-support-tickets' ), 'neutral' ),
			'met'      => array( __( 'Met', 'bonsai-support-tickets' ), 'success' ),
			'breached' => array( __( 'Missed', 'bonsai-support-tickets' ), 'error' ),
		);
		$rows = array(
			'response'   => array( __( 'First response', 'bonsai-support-tickets' ), $state['response'], $state['response_due'], $targets['response'], $state['response_left'] ),
			'resolution' => array( __( 'Resolution', 'bonsai-support-tickets' ), $state['resolution'], $state['resolution_due'], $targets['resolution'], $state['resolution_left'] ),
		);
		?>
		<dl class="bst-sla-box">
			<?php foreach ( $rows as $row ) : ?>
				<?php list( $label, $status, $due, $target, $left ) = $row; ?>
				<dt>
					<?php echo esc_html( $label ); ?>
					<?php echo BST_Admin_UI::badge( $states[ $status ][0], $states[ $status ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
				</dt>
				<dd>
					<?php
					/* translators: %s: target, e.g. 4h. */
					echo esc_html( sprintf( __( 'Target %s', 'bonsai-support-tickets' ), BST_Duration::format( $target ) ) );
					if ( in_array( $status, array( 'pending', 'breached' ), true ) ) {
						echo '<br>';
						if ( 'pending' === $status ) {
							/* translators: 1: date and time, 2: working time left. */
							echo esc_html( sprintf( __( 'Due %1$s (%2$s left)', 'bonsai-support-tickets' ), wp_date( 'D j M, ' . get_option( 'time_format' ), $due->getTimestamp() ), BST_Duration::format( max( 0, $left ) ) ) );
						} else {
							/* translators: %s: working time overdue. */
							echo esc_html( sprintf( __( 'Overdue by %s', 'bonsai-support-tickets' ), BST_Duration::format( abs( $left ) ) ) );
						}
					}
					?>
				</dd>
			<?php endforeach; ?>
		</dl>
		<p class="bst-muted">
			<?php
			$clock = 'always' === $targets['clock'] ? __( '24/7 clock', 'bonsai-support-tickets' ) : __( 'business hours', 'bonsai-support-tickets' );
			echo esc_html(
				$plan && ! is_wp_error( $plan )
					/* translators: 1: priority, 2: plan name, 3: clock. */
					? sprintf( __( '%1$s targets for the %2$s plan, %3$s.', 'bonsai-support-tickets' ), BST_Tickets::priority_label( BST_Tickets::priority( $post->ID ) ), $plan->name, $clock )
					/* translators: 1: priority, 2: clock. */
					: sprintf( __( '%1$s targets, %2$s.', 'bonsai-support-tickets' ), BST_Tickets::priority_label( BST_Tickets::priority( $post->ID ) ), $clock )
			);
			?>
		</p>
		<?php
	}

	/*
	|----------------------------------------------------------------------
	| Report
	|----------------------------------------------------------------------
	*/

	/**
	 * Percentage cell.
	 *
	 * @param array  $group  BST_SLA::group() row.
	 * @param string $metric response|resolution.
	 * @return string HTML.
	 */
	private static function pct_cell( array $group, $metric ) {
		$pct = $group[ $metric . '_pct' ];
		if ( null === $pct ) {
			return '<span class="bst-muted">&mdash;</span>';
		}
		$variant = $pct >= 95 ? 'success' : ( $pct >= 80 ? 'warning' : 'error' );
		return BST_Admin_UI::badge( number_format_i18n( $pct, floor( $pct ) === $pct ? 0 : 1 ) . '%', $variant )
			. ' <span class="bst-muted">' . esc_html(
				sprintf(
					/* translators: 1: met count, 2: decided count. */
					__( '%1$d of %2$d', 'bonsai-support-tickets' ),
					$group[ $metric . '_met' ],
					$group[ $metric . '_met' ] + $group[ $metric . '_breached' ]
				)
			) . '</span>';
	}

	/**
	 * One grouped table.
	 *
	 * @param string  $title  Heading.
	 * @param array   $groups BST_SLA::group().
	 * @param callable $name  key => label.
	 */
	private static function report_table( $title, array $groups, callable $name ) {
		?>
		<h3><?php echo esc_html( $title ); ?></h3>
		<table class="widefat striped bst-time-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $title ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tickets', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'First response met', 'bonsai-support-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Resolution met', 'bonsai-support-tickets' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $key => $group ) : ?>
					<tr>
						<td><?php echo esc_html( $name( $key ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $group['tickets'] ) ); ?></td>
						<td><?php echo self::pct_cell( $group, 'response' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pct_cell(). ?></td>
						<td><?php echo self::pct_cell( $group, 'resolution' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pct_cell(). ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Name for a report group key.
	 *
	 * @param string $by  priority|company_id|plan_id.
	 * @param string $key Key.
	 * @return string
	 */
	public static function group_name( $by, $key ) {
		switch ( $by ) {
			case 'priority':
				return BST_Tickets::priority_label( $key );
			case 'company_id':
				return (int) $key ? BST_Companies::name( (int) $key ) : __( 'No client', 'bonsai-support-tickets' );
			default:
				$term = (int) $key ? get_term( (int) $key ) : null;
				return $term && ! is_wp_error( $term ) ? $term->name : __( 'No plan', 'bonsai-support-tickets' );
		}
	}

	/**
	 * Support → SLA report.
	 */
	public static function render_report() {
		if ( ! current_user_can( 'bst_view_all_tickets' ) || ! BST_SLA::enabled() ) {
			return;
		}

		$month = BST_Time::sanitize_month( sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$rows  = BST_SLA::month_rows( $month );
		$all   = BST_SLA::group( $rows, 'priority' );
		$total = BST_SLA::group(
			array_map(
				function ( $row ) {
					$row['priority'] = 'all';
					return $row;
				},
				$rows
			),
			'priority'
		);

		$time = strtotime( $month . '-15 12:00:00' );
		$prev = gmdate( 'Y-m', strtotime( '-1 month', $time ) );
		$next = gmdate( 'Y-m', strtotime( '+1 month', $time ) );
		?>
		<div class="wrap bonsai-ui">
			<?php
			BST_Admin_UI::header(
				__( 'SLA report', 'bonsai-support-tickets' ),
				__( 'How many tickets raised each month met their first-response and resolution targets. Tickets still open count once they meet or miss a target.', 'bonsai-support-tickets' ),
				array(
					array(
						'label' => __( 'SLA settings', 'bonsai-support-tickets' ),
						'url'   => BST_Admin_Settings::url( 'sla' ),
					),
				)
			);
			?>
			<section class="bonsai-ui-card">
				<div class="bonsai-ui-card__head">
					<h2 class="bonsai-ui-card__title"><?php echo esc_html( BST_Time::month_label( $month ) ); ?></h2>
					<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="bst-month-nav">
						<input type="hidden" name="post_type" value="<?php echo esc_attr( BST_Post_Types::TICKET ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<a class="button" href="<?php echo esc_url( self::url( array( 'month' => $prev ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'bonsai-support-tickets' ); ?>">&larr;</a>
						<label class="screen-reader-text" for="bst-sla-month"><?php esc_html_e( 'Month', 'bonsai-support-tickets' ); ?></label>
						<input type="month" id="bst-sla-month" name="month" value="<?php echo esc_attr( $month ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Show', 'bonsai-support-tickets' ); ?></button>
						<?php if ( $month < BST_Time::current_month() ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'month' => $next ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'bonsai-support-tickets' ); ?>">&rarr;</a>
						<?php endif; ?>
					</form>
				</div>

				<?php if ( ! $rows ) : ?>
					<p class="bst-muted"><?php esc_html_e( 'No tickets with an SLA were raised this month.', 'bonsai-support-tickets' ); ?></p>
				<?php else : ?>
					<?php $overall = $total['all']; ?>
					<dl class="bonsai-ui-status">
						<dt><?php esc_html_e( 'Tickets', 'bonsai-support-tickets' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( $overall['tickets'] ) ); ?></dd>
						<dt><?php esc_html_e( 'First response met', 'bonsai-support-tickets' ); ?></dt>
						<dd><?php echo self::pct_cell( $overall, 'response' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pct_cell(). ?></dd>
						<dt><?php esc_html_e( 'Resolution met', 'bonsai-support-tickets' ); ?></dt>
						<dd><?php echo self::pct_cell( $overall, 'resolution' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pct_cell(). ?></dd>
					</dl>
					<?php
					self::report_table( __( 'Priority', 'bonsai-support-tickets' ), $all, fn( $key ) => self::group_name( 'priority', $key ) );
					self::report_table( __( 'Plan', 'bonsai-support-tickets' ), BST_SLA::group( $rows, 'plan_id' ), fn( $key ) => self::group_name( 'plan_id', $key ) );
					self::report_table( __( 'Client', 'bonsai-support-tickets' ), BST_SLA::group( $rows, 'company_id' ), fn( $key ) => self::group_name( 'company_id', $key ) );
					?>
				<?php endif; ?>

				<div class="bonsai-ui-card__footer bonsai-ui-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bst_sla_export">
						<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
						<?php wp_nonce_field( 'bst_sla_export' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Export tickets (CSV)', 'bonsai-support-tickets' ); ?></button>
					</form>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * CSV: one row per measured ticket raised in the month.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'bst_view_all_tickets' ) || ! BST_SLA::enabled() ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_sla_export' );

		$month  = BST_Time::sanitize_month( sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) ) );
		$labels = array(
			'pending'  => __( 'Due', 'bonsai-support-tickets' ),
			'paused'   => __( 'Paused', 'bonsai-support-tickets' ),
			'met'      => __( 'Met', 'bonsai-support-tickets' ),
			'breached' => __( 'Missed', 'bonsai-support-tickets' ),
		);

		$csv = array(
			array(
				__( 'Ticket', 'bonsai-support-tickets' ),
				__( 'Subject', 'bonsai-support-tickets' ),
				__( 'Raised', 'bonsai-support-tickets' ),
				__( 'Client', 'bonsai-support-tickets' ),
				__( 'Plan', 'bonsai-support-tickets' ),
				__( 'Priority', 'bonsai-support-tickets' ),
				__( 'First response', 'bonsai-support-tickets' ),
				__( 'Resolution', 'bonsai-support-tickets' ),
			),
		);

		try {
			foreach ( BST_SLA::month_rows( $month ) as $row ) {
				$csv[] = array(
					BST_Tickets::ref( $row['ticket_id'] ),
					wp_specialchars_decode( (string) get_post_field( 'post_title', $row['ticket_id'], 'raw' ), ENT_QUOTES ),
					get_date_from_gmt( (string) get_post_field( 'post_date_gmt', $row['ticket_id'] ), 'Y-m-d H:i' ),
					self::group_name( 'company_id', (string) $row['company_id'] ),
					$row['plan_id'] ? self::group_name( 'plan_id', (string) $row['plan_id'] ) : '',
					BST_Tickets::priority_label( $row['priority'] ),
					$labels[ $row['response'] ] ?? $row['response'],
					$labels[ $row['resolution'] ] ?? $row['resolution'],
				);
			}
			BST_Time::send_csv( 'sla-' . $month . '.csv', $csv );
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': SLA export failed: ' . $e->getMessage() );
			wp_die( esc_html__( 'The export failed. Please try again.', 'bonsai-support-tickets' ) );
		}
	}
}
