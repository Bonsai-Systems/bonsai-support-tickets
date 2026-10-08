<?php
/**
 * Time tracking: time entries against tickets, monthly retainer usage,
 * 80%/100% alerts and CSV exports.
 *
 * Switched on under Support → Settings → Time tracking (time_enabled).
 * Switching it off hides every screen and field; entries are kept.
 *
 * - Every entry is stamped with the ticket's client when it's logged, so
 *   reports stay right if the ticket later moves. The exception: entries
 *   logged while a ticket had no client follow it when it gets one.
 * - Retainers reset each calendar month (site timezone). Only billable time
 *   uses up the allowance; non-billable time is logged and reported.
 * - Alerts go to the team (users who can manage time) the first time a
 *   client crosses 80% and 100% in a month, by email and Slack if on.
 * - Agents edit and delete their own entries; managers (admins) any.
 *
 * All writes go through this class.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Time entries and retainer usage.
 */
class BST_Time {

	const META_ALERTS = '_bst_time_alerts'; // Company meta: array( 'Y-m' => int[] thresholds sent ).

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'bst_ticket_company_changed', array( __CLASS__, 'adopt_unassigned' ), 10, 3 );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bst_time_entries';
	}

	/*
	|----------------------------------------------------------------------
	| Settings and permissions
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether time tracking is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) BST_Settings::get( 'time_enabled' );
	}

	/**
	 * Whether clients see their monthly hours in the portal.
	 *
	 * @return bool
	 */
	public static function portal_enabled() {
		return self::enabled() && (bool) BST_Settings::get( 'time_portal' );
	}

	/**
	 * Whether a user can edit or delete an entry: their own, or anyone's
	 * with bst_manage_time.
	 *
	 * @param object   $entry   Entry row.
	 * @param int|null $user_id User (default current).
	 * @return bool
	 */
	public static function can_edit( $entry, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $entry || ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'bst_manage_time' ) ) {
			return true;
		}
		return user_can( $user_id, 'bst_log_time' ) && (int) $entry->user_id === $user_id;
	}

	/*
	|----------------------------------------------------------------------
	| Months
	|----------------------------------------------------------------------
	*/

	/**
	 * Current month in the site's timezone, as Y-m.
	 *
	 * @return string
	 */
	public static function current_month() {
		return wp_date( 'Y-m' );
	}

	/**
	 * Today in the site's timezone, as Y-m-d.
	 *
	 * @return string
	 */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Valid Y-m, or the current month.
	 *
	 * @param string $month Requested month.
	 * @return string
	 */
	public static function sanitize_month( $month ) {
		$month = (string) $month;
		if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m ) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100 ) {
			return $month;
		}
		return self::current_month();
	}

	/**
	 * First and last day of a month.
	 *
	 * @param string $month Y-m.
	 * @return string[] array( 'Y-m-01', 'Y-m-t' ).
	 */
	public static function month_bounds( $month ) {
		$month = self::sanitize_month( $month );
		$first = $month . '-01';
		return array( $first, gmdate( 'Y-m-t', strtotime( $first . ' 00:00:00 UTC' ) ) );
	}

	/**
	 * Month label, e.g. "October 2026".
	 *
	 * @param string $month Y-m.
	 * @return string
	 */
	public static function month_label( $month ) {
		return date_i18n( 'F Y', strtotime( self::sanitize_month( $month ) . '-15 12:00:00' ) );
	}

	/**
	 * Valid Y-m-d, or today.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	public static function sanitize_date( $date ) {
		$date = (string) $date;
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $date;
		}
		return self::today();
	}

	/*
	|----------------------------------------------------------------------
	| Entries
	|----------------------------------------------------------------------
	*/

	/**
	 * Log time against a ticket.
	 *
	 * @param array $args {
	 *     @type int    $ticket_id Ticket.
	 *     @type int    $minutes   1–1440.
	 *     @type bool   $billable  Counts against the retainer. Default true.
	 *     @type string $note      Short note (team only).
	 *     @type string $work_date Y-m-d the work was done. Default today.
	 *     @type int    $user_id   Who did it. Default current user.
	 * }
	 * @return int|WP_Error Entry ID.
	 */
	public static function add( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'ticket_id' => 0,
				'minutes'   => 0,
				'billable'  => true,
				'note'      => '',
				'work_date' => '',
				'user_id'   => get_current_user_id(),
			)
		);

		$ticket_id = (int) $args['ticket_id'];
		$minutes   = (int) $args['minutes'];

		if ( ! BST_Tickets::exists( $ticket_id ) ) {
			return new WP_Error( 'bst_time_ticket', __( 'Ticket not found.', 'bonsai-support-tickets' ) );
		}
		if ( $minutes < 1 || $minutes > BST_Duration::MAX_MINUTES ) {
			return new WP_Error( 'bst_time_minutes', __( 'Time must be between 1 minute and 24 hours.', 'bonsai-support-tickets' ) );
		}

		$now  = current_time( 'mysql', true );
		$date = '' !== (string) $args['work_date'] ? self::sanitize_date( $args['work_date'] ) : self::today();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'ticket_id'  => $ticket_id,
				'company_id' => BST_Companies::for_ticket( $ticket_id ),
				'user_id'    => absint( $args['user_id'] ),
				'minutes'    => $minutes,
				'billable'   => $args['billable'] ? 1 : 0,
				'note'       => substr( sanitize_text_field( (string) $args['note'] ), 0, 255 ),
				'work_date'  => $date,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			error_log( BST_PRODUCT_NAME . ': could not log time on ticket ' . $ticket_id . ': ' . $wpdb->last_error );
			return new WP_Error( 'bst_time_save', __( 'The time could not be saved. Please try again.', 'bonsai-support-tickets' ) );
		}

		$entry_id = (int) $wpdb->insert_id;
		BST_Activity::log( $ticket_id, 'time', '', (string) $minutes, absint( $args['user_id'] ) );

		$entry = self::get( $entry_id );

		/**
		 * Time was logged.
		 *
		 * @param int    $entry_id Entry ID.
		 * @param object $entry    Entry row.
		 */
		do_action( 'bst_time_logged', $entry_id, $entry );

		self::check_alerts( (int) $entry->company_id, substr( $entry->work_date, 0, 7 ) );

		return $entry_id;
	}

	/**
	 * Change an entry's minutes, billable flag, note or date. The client
	 * stamp doesn't change.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $fields   minutes, billable, note, work_date (any subset).
	 * @return true|WP_Error
	 */
	public static function update( $entry_id, array $fields ) {
		global $wpdb;

		$entry = self::get( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'bst_time_missing', __( 'Time entry not found.', 'bonsai-support-tickets' ) );
		}

		$data = array();
		if ( array_key_exists( 'minutes', $fields ) ) {
			$minutes = (int) $fields['minutes'];
			if ( $minutes < 1 || $minutes > BST_Duration::MAX_MINUTES ) {
				return new WP_Error( 'bst_time_minutes', __( 'Time must be between 1 minute and 24 hours.', 'bonsai-support-tickets' ) );
			}
			$data['minutes'] = $minutes;
		}
		if ( array_key_exists( 'billable', $fields ) ) {
			$data['billable'] = $fields['billable'] ? 1 : 0;
		}
		if ( array_key_exists( 'note', $fields ) ) {
			$data['note'] = substr( sanitize_text_field( (string) $fields['note'] ), 0, 255 );
		}
		if ( array_key_exists( 'work_date', $fields ) ) {
			$data['work_date'] = self::sanitize_date( $fields['work_date'] );
		}
		if ( ! $data ) {
			return true;
		}
		$data['updated_at'] = current_time( 'mysql', true );

		$updated = $wpdb->update( self::table(), $data, array( 'id' => (int) $entry_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $updated ) {
			error_log( BST_PRODUCT_NAME . ': could not update time entry ' . $entry_id . ': ' . $wpdb->last_error );
			return new WP_Error( 'bst_time_save', __( 'The time could not be saved. Please try again.', 'bonsai-support-tickets' ) );
		}

		BST_Activity::log( (int) $entry->ticket_id, 'time_edit', (string) $entry->minutes, (string) ( $data['minutes'] ?? $entry->minutes ) );

		$fresh = self::get( $entry_id );
		self::check_alerts( (int) $fresh->company_id, substr( $fresh->work_date, 0, 7 ) );

		return true;
	}

	/**
	 * Delete an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool
	 */
	public static function delete( $entry_id ) {
		global $wpdb;

		$entry = self::get( $entry_id );
		if ( ! $entry ) {
			return false;
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $entry_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		BST_Activity::log( (int) $entry->ticket_id, 'time_delete', (string) $entry->minutes, '' );
		return true;
	}

	/**
	 * One entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null
	 */
	public static function get( $entry_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $entry_id ) );
	}

	/**
	 * A ticket's entries, newest work first.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return object[]
	 */
	public static function for_ticket( $ticket_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY work_date DESC, id DESC", (int) $ticket_id ) );
	}

	/**
	 * Entries in a month, optionally for one client and/or one agent.
	 *
	 * @param string   $month      Y-m.
	 * @param int|null $company_id Client (0 = no client), null = all.
	 * @param int|null $user_id    Agent, null = all.
	 * @return object[] Oldest first.
	 */
	public static function for_month( $month, $company_id = null, $user_id = null ) {
		global $wpdb;
		$table           = self::table();
		list( $from, $to ) = self::month_bounds( $month );

		$where  = 'work_date BETWEEN %s AND %s';
		$params = array( $from, $to );
		if ( null !== $company_id ) {
			$where   .= ' AND company_id = %d';
			$params[] = (int) $company_id;
		}
		if ( null !== $user_id ) {
			$where   .= ' AND user_id = %d';
			$params[] = (int) $user_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY work_date ASC, id ASC", $params ) );
	}

	/**
	 * Billable and non-billable minutes per client for a month.
	 *
	 * @param string $month Y-m.
	 * @return array<int,array{billable:int,nonbillable:int}> Keyed by company ID (0 = no client).
	 */
	public static function totals_by_company( $month ) {
		global $wpdb;
		$table           = self::table();
		list( $from, $to ) = self::month_bounds( $month );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT company_id, billable, SUM(minutes) AS minutes FROM {$table} WHERE work_date BETWEEN %s AND %s GROUP BY company_id, billable", $from, $to ) );

		$totals = array();
		foreach ( $rows as $row ) {
			$id = (int) $row->company_id;
			if ( ! isset( $totals[ $id ] ) ) {
				$totals[ $id ] = array(
					'billable'    => 0,
					'nonbillable' => 0,
				);
			}
			$totals[ $id ][ (int) $row->billable ? 'billable' : 'nonbillable' ] += (int) $row->minutes;
		}
		return $totals;
	}

	/**
	 * Entries logged while a ticket had no client follow it to its new one.
	 * Entries already stamped with a client stay put.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $old       Previous company.
	 * @param int $new       New company.
	 */
	public static function adopt_unassigned( $ticket_id, $old, $new ) {
		global $wpdb;
		if ( (int) $old || ! (int) $new ) {
			return;
		}
		$wpdb->update( self::table(), array( 'company_id' => (int) $new ), array( 'ticket_id' => (int) $ticket_id, 'company_id' => 0 ), array( '%d' ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/*
	|----------------------------------------------------------------------
	| Retainer usage
	|----------------------------------------------------------------------
	*/

	/**
	 * A client's retainer use for a month. Only billable time counts.
	 *
	 * @param int    $company_id Client.
	 * @param string $month      Y-m (default this month).
	 * @param array  $totals     Pre-fetched totals_by_company() row (optional).
	 * @return array {
	 *     @type int        $allowance   Minutes allowed (0 = no retainer).
	 *     @type int        $used        Billable minutes.
	 *     @type int        $nonbillable Non-billable minutes.
	 *     @type int        $remaining   Allowance left (never below 0).
	 *     @type int        $over        Billable minutes over the allowance.
	 *     @type float|null $percent     Used ÷ allowance × 100, null with no retainer.
	 *     @type string     $level       ok | warning (≥80%) | over (≥100%) | none.
	 * }
	 */
	public static function usage( $company_id, $month = '', ?array $totals = null ) {
		$month = '' !== $month ? self::sanitize_month( $month ) : self::current_month();
		if ( null === $totals ) {
			$all    = self::totals_by_company( $month );
			$totals = $all[ (int) $company_id ] ?? array(
				'billable'    => 0,
				'nonbillable' => 0,
			);
		}

		$allowance = (int) round( BST_Companies::retainer_hours( $company_id ) * 60 );
		$used      = (int) $totals['billable'];
		$percent   = $allowance ? $used / $allowance * 100 : null;

		if ( null === $percent ) {
			$level = 'none';
		} elseif ( $percent >= 100 ) {
			$level = 'over';
		} elseif ( $percent >= 80 ) {
			$level = 'warning';
		} else {
			$level = 'ok';
		}

		return array(
			'allowance'   => $allowance,
			'used'        => $used,
			'nonbillable' => (int) $totals['nonbillable'],
			'remaining'   => max( 0, $allowance - $used ),
			'over'        => $allowance ? max( 0, $used - $allowance ) : 0,
			'percent'     => $percent,
			'level'       => $level,
		);
	}

	/**
	 * This month's retainer for a client's portal, or null when there's
	 * nothing to show (portal display off, no client, or no retainer).
	 * Totals only: entries and notes are never shown to clients.
	 *
	 * @param int $user_id Client user.
	 * @return array|null usage() plus 'month_label'.
	 */
	public static function portal_usage( $user_id ) {
		if ( ! self::portal_enabled() ) {
			return null;
		}
		$company_id = BST_Companies::for_user( (int) $user_id );
		if ( ! $company_id ) {
			return null;
		}
		$usage = self::usage( $company_id );
		if ( 'none' === $usage['level'] ) {
			return null;
		}
		$usage['month_label'] = self::month_label( self::current_month() );
		return $usage;
	}

	/**
	 * Alert the team the first time a client crosses 80% and 100% of their
	 * allowance in the current month. Back-dated entries for earlier months
	 * never alert.
	 *
	 * @param int    $company_id Client.
	 * @param string $month      Month the entry belongs to.
	 */
	public static function check_alerts( $company_id, $month ) {
		if ( ! $company_id || ! self::enabled() || ! BST_Settings::get( 'time_alerts' ) || self::current_month() !== $month ) {
			return;
		}

		$usage = self::usage( $company_id, $month );
		if ( null === $usage['percent'] ) {
			return;
		}

		$sent = get_post_meta( $company_id, self::META_ALERTS, true );
		$sent = is_array( $sent ) ? $sent : array();
		// Only keep this month's record; older months don't matter any more.
		$done = isset( $sent[ $month ] ) && is_array( $sent[ $month ] ) ? array_map( 'intval', $sent[ $month ] ) : array();

		$crossed = array();
		foreach ( array( 80, 100 ) as $threshold ) {
			if ( $usage['percent'] >= $threshold && ! in_array( $threshold, $done, true ) ) {
				$crossed[] = $threshold;
			}
		}
		if ( ! $crossed ) {
			return;
		}

		// Record first so a slow mail server can't cause a double alert.
		update_post_meta( $company_id, self::META_ALERTS, array( $month => array_merge( $done, $crossed ) ) );

		// One message for the highest threshold crossed.
		self::send_alert( $company_id, max( $crossed ), $usage, $month );
	}

	/**
	 * Email (and Slack) the team about a retainer threshold.
	 *
	 * @param int    $company_id Client.
	 * @param int    $threshold  80 or 100.
	 * @param array  $usage      usage().
	 * @param string $month      Y-m.
	 */
	private static function send_alert( $company_id, $threshold, array $usage, $month ) {
		$name    = BST_Companies::name( $company_id );
		$subject = 100 === $threshold
			/* translators: %s: client name. */
			? sprintf( __( '%s has used their monthly retainer', 'bonsai-support-tickets' ), $name )
			/* translators: %s: client name. */
			: sprintf( __( '%s has used 80%% of their monthly retainer', 'bonsai-support-tickets' ), $name );

		$details = array(
			__( 'Month', 'bonsai-support-tickets' )     => self::month_label( $month ),
			__( 'Allowance', 'bonsai-support-tickets' ) => BST_Duration::format( $usage['allowance'] ),
			__( 'Used', 'bonsai-support-tickets' )      => BST_Duration::format( $usage['used'] ) . ' (' . round( $usage['percent'] ) . '%)',
		);
		if ( $usage['over'] ) {
			$details[ __( 'Over by', 'bonsai-support-tickets' ) ] = BST_Duration::format( $usage['over'] );
		} else {
			$details[ __( 'Remaining', 'bonsai-support-tickets' ) ] = BST_Duration::format( $usage['remaining'] );
		}

		$url = BST_Admin_Time::url(
			array(
				'month'   => $month,
				'company' => $company_id,
			)
		);

		$users = get_users(
			array(
				'capability' => 'bst_manage_time',
				'fields'     => array( 'ID', 'user_email' ),
			)
		);

		/**
		 * Filters who gets retainer alerts.
		 *
		 * @param string[] $emails     Email addresses.
		 * @param int      $company_id Client.
		 * @param int      $threshold  80 or 100.
		 */
		$emails = apply_filters( 'bst_time_alert_recipients', wp_list_pluck( $users, 'user_email' ), $company_id, $threshold );

		foreach ( array_unique( (array) $emails ) as $email ) {
			BST_Mailer::send_account_email(
				$email,
				$subject,
				array(
					'heading'      => $subject,
					'intro'        => __( 'Only billable time counts towards the retainer. The client has not been told.', 'bonsai-support-tickets' ),
					'details'      => $details,
					'button_url'   => $url,
					'button_label' => __( 'See their time', 'bonsai-support-tickets' ),
					'footer'       => __( 'Sent to the support team because time tracking alerts are on (Support → Settings → Time tracking).', 'bonsai-support-tickets' ),
				)
			);
		}

		if ( BST_Slack::enabled() ) {
			$lines = array( ( 100 === $threshold ? ':red_circle: ' : ':large_orange_circle: ' ) . '*' . BST_Slack::escape( $subject ) . '*' );
			foreach ( $details as $label => $value ) {
				$lines[] = BST_Slack::escape( $label . ': ' . $value );
			}
			$lines[] = '<' . $url . '|' . BST_Slack::escape( __( 'See their time', 'bonsai-support-tickets' ) ) . '>';
			BST_Slack::send( array( 'text' => implode( "\n", $lines ) ), false );
		}
	}

	/*
	|----------------------------------------------------------------------
	| CSV
	|----------------------------------------------------------------------
	*/

	/**
	 * Entry rows for a CSV (header first).
	 *
	 * @param string   $month      Y-m.
	 * @param int|null $company_id Client, or null for all.
	 * @return array[]
	 */
	public static function entries_csv_rows( $month, $company_id = null ) {
		$rows = array(
			array(
				__( 'Date', 'bonsai-support-tickets' ),
				__( 'Client', 'bonsai-support-tickets' ),
				__( 'Ticket', 'bonsai-support-tickets' ),
				__( 'Subject', 'bonsai-support-tickets' ),
				__( 'Agent', 'bonsai-support-tickets' ),
				__( 'Hours', 'bonsai-support-tickets' ),
				__( 'Minutes', 'bonsai-support-tickets' ),
				__( 'Billable', 'bonsai-support-tickets' ),
				__( 'Note', 'bonsai-support-tickets' ),
			),
		);

		foreach ( self::for_month( $month, $company_id ) as $entry ) {
			$agent  = get_userdata( (int) $entry->user_id );
			$rows[] = array(
				$entry->work_date,
				BST_Companies::name( (int) $entry->company_id ),
				BST_Tickets::ref( (int) $entry->ticket_id ),
				wp_specialchars_decode( (string) get_post_field( 'post_title', (int) $entry->ticket_id, 'raw' ), ENT_QUOTES ),
				$agent ? wp_specialchars_decode( $agent->display_name, ENT_QUOTES ) : '',
				BST_Duration::hours( (int) $entry->minutes ),
				(int) $entry->minutes,
				(int) $entry->billable ? __( 'Yes', 'bonsai-support-tickets' ) : __( 'No', 'bonsai-support-tickets' ),
				$entry->note,
			);
		}

		return $rows;
	}

	/**
	 * Summary rows for a CSV: one per client with a retainer or time logged.
	 *
	 * @param string $month Y-m.
	 * @return array[]
	 */
	public static function summary_csv_rows( $month ) {
		$rows = array(
			array(
				__( 'Client', 'bonsai-support-tickets' ),
				__( 'Retainer hours', 'bonsai-support-tickets' ),
				__( 'Billable hours', 'bonsai-support-tickets' ),
				__( 'Non-billable hours', 'bonsai-support-tickets' ),
				__( 'Remaining hours', 'bonsai-support-tickets' ),
				__( 'Over by (hours)', 'bonsai-support-tickets' ),
			),
		);

		foreach ( self::summary( $month ) as $row ) {
			$usage  = $row['usage'];
			$rows[] = array(
				$row['name'],
				BST_Duration::hours( $usage['allowance'] ),
				BST_Duration::hours( $usage['used'] ),
				BST_Duration::hours( $usage['nonbillable'] ),
				BST_Duration::hours( $usage['remaining'] ),
				BST_Duration::hours( $usage['over'] ),
			);
		}

		return $rows;
	}

	/**
	 * Every client with a retainer or time this month, A–Z; time with no
	 * client last.
	 *
	 * @param string $month Y-m.
	 * @return array[] Each: company_id, name, usage.
	 */
	public static function summary( $month ) {
		$totals = self::totals_by_company( $month );
		$ids    = array_keys( $totals );

		foreach ( BST_Companies::all() as $company ) {
			if ( BST_Companies::retainer_hours( $company->ID ) > 0 ) {
				$ids[] = (int) $company->ID;
			}
		}

		$rows = array();
		foreach ( array_unique( $ids ) as $id ) {
			$rows[] = array(
				'company_id' => (int) $id,
				'name'       => $id ? BST_Companies::name( $id ) : __( 'No client', 'bonsai-support-tickets' ),
				'usage'      => self::usage(
					$id,
					$month,
					$totals[ $id ] ?? array(
						'billable'    => 0,
						'nonbillable' => 0,
					)
				),
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( ! $a['company_id'] || ! $b['company_id'] ) {
					return $a['company_id'] ? -1 : ( $b['company_id'] ? 1 : 0 );
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Make a cell safe for spreadsheets: values starting with = + - @ (or a
	 * tab/CR) would otherwise run as formulas in Excel/Sheets.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && ! is_numeric( $value ) && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Send rows as a CSV download and stop.
	 *
	 * @param string  $filename File name.
	 * @param array[] $rows     Rows.
	 */
	public static function send_csv( $filename, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8 (£, accents).
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
