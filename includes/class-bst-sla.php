<?php
/**
 * SLAs and response reminders.
 *
 * Two features, each with its own switch under Support → Settings → SLAs &
 * reminders (both off by default):
 *
 * SLAs (sla_enabled)
 * - First-response and resolution targets per priority, optionally
 *   overridden per client Plan (term meta), on a business-hours or 24/7
 *   clock per priority (BST_Business_Hours).
 * - Applies to tickets created while SLAs are on (_bst_sla_on), except
 *   uptime-monitor tickets, which are internal.
 * - First response = the first agent reply to the client (not a note).
 *   Resolution = Solved. The resolution clock pauses while Awaiting client,
 *   On hold, Solved or Closed; first response never pauses.
 * - Due times are stored on the ticket and refreshed on every relevant event
 *   so the list can sort by them and the cron can find them cheaply.
 * - The cron warns once when a target is close (sla_warn_percent of its time
 *   left) and once when it's breached: email to the assignee (else all
 *   agents) and Slack if it's on.
 *
 * Reminders (reminders_enabled)
 * - While a ticket is waiting on us (New or Open), email the assignee (else
 *   all agents) after the priority's "first" time, then every "repeat".
 *   Counted on the priority's clock. Reset when the client replies or the
 *   ticket comes back to New/Open.
 *
 * Bank holidays come from gov.uk (region setting), cached for a week, with
 * a bundled fallback; extra closed days can be added in settings.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * SLA and reminder engine.
 */
class BST_SLA {

	const CRON_HOOK     = 'bst_sla_check';
	const CRON_INTERVAL = 'bst_every_five_minutes';

	const HOLIDAY_OPTION = 'bst_bank_holidays';
	const HOLIDAY_URL    = 'https://www.gov.uk/bank-holidays.json';

	const PLAN_META = 'bst_sla_targets'; // Term meta on plans: priority => array( response, resolution ) minutes.

	// Ticket meta.
	const META_ON             = '_bst_sla_on';
	const META_FIRST_RESPONSE = '_bst_first_response_at';
	const META_PAUSED_MINUTES = '_bst_sla_paused_minutes';
	const META_PAUSED_SINCE   = '_bst_sla_paused_since';
	const META_RESPONSE_DUE   = '_bst_sla_response_due';
	const META_RESOLUTION_DUE = '_bst_sla_resolution_due';
	const META_RESPONSE       = '_bst_sla_response';   // pending|met|breached.
	const META_RESOLUTION     = '_bst_sla_resolution'; // pending|paused|met|breached.
	const META_NEXT_DUE       = '_bst_sla_due';        // Earliest pending due (GMT), or FAR_FUTURE.
	const META_NOTIFIED       = '_bst_sla_notified';   // string[] of warnings sent.
	const META_WAIT_SINCE     = '_bst_wait_since';
	const META_REMINDERS      = '_bst_reminders_sent';

	const FAR_FUTURE = '9999-12-31 23:59:59';

	/**
	 * Statuses that pause the resolution clock.
	 */
	const PAUSED_STATUSES = array( 'awaiting_client', 'on_hold', 'solved', 'closed' );

	/**
	 * Statuses that mean "waiting on us" for reminders.
	 */
	const WAITING_STATUSES = array( 'new', 'open' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 5 minutes is intentional.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( BST_Cron::DAILY_HOOK, array( __CLASS__, 'maybe_refresh_holidays' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );

		add_action( 'bst_ticket_created', array( __CLASS__, 'on_created' ), 5, 3 );
		add_action( 'bst_message_added', array( __CLASS__, 'on_message' ), 5, 3 );
		add_action( 'bst_status_changed', array( __CLASS__, 'on_status' ), 5, 4 );
		add_action( 'bst_priority_changed', array( __CLASS__, 'refresh' ), 10, 1 );
		add_action( 'bst_ticket_company_changed', array( __CLASS__, 'refresh' ), 10, 1 );

		// New hours, holidays or targets move every open ticket's due time.
		add_action( 'update_option_' . BST_Settings::OPTION, array( __CLASS__, 'settings_saved' ) );
		add_action( 'add_option_' . BST_Settings::OPTION, array( __CLASS__, 'settings_saved' ) );
		add_action( 'edited_' . BST_Post_Types::PLAN, array( __CLASS__, 'refresh_open_tickets' ) );
	}

	/**
	 * Settings saved: (un)schedule the check and refresh open tickets.
	 */
	public static function settings_saved() {
		self::schedule();
		if ( self::enabled() ) {
			self::refresh_open_tickets();
		}
	}

	/*
	|----------------------------------------------------------------------
	| Settings
	|----------------------------------------------------------------------
	*/

	/**
	 * SLAs on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) BST_Settings::get( 'sla_enabled' );
	}

	/**
	 * Reminders on.
	 *
	 * @return bool
	 */
	public static function reminders_enabled() {
		return (bool) BST_Settings::get( 'reminders_enabled' );
	}

	/**
	 * Default targets, minutes. A working day is 8.5 hours (09:00–17:30).
	 *
	 * @return array<string,array{response:int,resolution:int,clock:string}>
	 */
	public static function default_targets() {
		return array(
			'urgent' => array(
				'response'   => 60,
				'resolution' => 8 * 60,
				'clock'      => 'always',
			),
			'high'   => array(
				'response'   => 4 * 60,
				'resolution' => 2 * 510,
				'clock'      => 'business',
			),
			'normal' => array(
				'response'   => 8 * 60,
				'resolution' => 5 * 510,
				'clock'      => 'business',
			),
			'low'    => array(
				'response'   => 2 * 510,
				'resolution' => 10 * 510,
				'clock'      => 'business',
			),
		);
	}

	/**
	 * Default reminder rules, minutes (0 = off / no repeat).
	 *
	 * @return array<string,array{first:int,repeat:int}>
	 */
	public static function default_reminders() {
		return array(
			'urgent' => array(
				'first'  => 60,
				'repeat' => 60,
			),
			'high'   => array(
				'first'  => 4 * 60,
				'repeat' => 4 * 60,
			),
			'normal' => array(
				'first'  => 510,
				'repeat' => 510,
			),
			'low'    => array(
				'first'  => 0,
				'repeat' => 0,
			),
		);
	}

	/**
	 * Sanitise targets. Accepts minutes, or hours under *_hours keys (the
	 * settings form), so callers can pass either.
	 *
	 * @param mixed $input Raw.
	 * @return array
	 */
	public static function sanitize_targets( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::default_targets();
		$clean    = array();

		foreach ( array_keys( BST_Tickets::priorities() ) as $priority ) {
			$row     = isset( $input[ $priority ] ) && is_array( $input[ $priority ] ) ? $input[ $priority ] : array();
			$default = $defaults[ $priority ] ?? $defaults['normal'];

			$clean[ $priority ] = array(
				'response'   => self::minutes_from( $row, 'response', $default['response'] ),
				'resolution' => self::minutes_from( $row, 'resolution', $default['resolution'] ),
				'clock'      => isset( $row['clock'] ) && 'always' === $row['clock'] ? 'always' : ( isset( $row['clock'] ) ? 'business' : $default['clock'] ),
			);
		}
		return $clean;
	}

	/**
	 * Sanitise reminder rules (minutes, or hours under *_hours keys).
	 *
	 * @param mixed $input Raw.
	 * @return array
	 */
	public static function sanitize_reminders( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::default_reminders();
		$clean    = array();

		foreach ( array_keys( BST_Tickets::priorities() ) as $priority ) {
			$row     = isset( $input[ $priority ] ) && is_array( $input[ $priority ] ) ? $input[ $priority ] : array();
			$default = $defaults[ $priority ] ?? array(
				'first'  => 0,
				'repeat' => 0,
			);

			$clean[ $priority ] = array(
				'first'  => self::minutes_from( $row, 'first', $default['first'], true ),
				'repeat' => self::minutes_from( $row, 'repeat', $default['repeat'], true ),
			);
		}
		return $clean;
	}

	/**
	 * Minutes from a row: "{key}_hours" (decimal hours) wins over "{key}".
	 *
	 * @param array $row        Row.
	 * @param string $key       Key.
	 * @param int   $default    Default minutes.
	 * @param bool  $allow_zero 0 allowed (reminders: off).
	 * @return int
	 */
	private static function minutes_from( array $row, $key, $default, $allow_zero = false ) {
		if ( isset( $row[ $key . '_hours' ] ) && '' !== trim( (string) $row[ $key . '_hours' ] ) ) {
			$minutes = (int) round( (float) str_replace( ',', '.', (string) $row[ $key . '_hours' ] ) * 60 );
		} elseif ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
			$minutes = (int) $row[ $key ];
		} else {
			return $allow_zero && isset( $row[ $key . '_hours' ] ) ? 0 : (int) $default;
		}
		$minutes = max( 0, min( 60 * 24 * 90, $minutes ) ); // Up to 90 days.
		return $minutes || $allow_zero ? $minutes : (int) $default;
	}

	/**
	 * Saved targets.
	 *
	 * @return array
	 */
	public static function targets() {
		return self::sanitize_targets( BST_Settings::get( 'sla_targets' ) );
	}

	/**
	 * Saved reminder rules.
	 *
	 * @return array
	 */
	public static function reminder_rules() {
		return self::sanitize_reminders( BST_Settings::get( 'reminder_rules' ) );
	}

	/**
	 * A plan's overrides: priority => array( response, resolution ), minutes; 0 = default.
	 *
	 * @param int $term_id Plan term.
	 * @return array
	 */
	public static function plan_targets( $term_id ) {
		$saved = get_term_meta( (int) $term_id, self::PLAN_META, true );
		$saved = is_array( $saved ) ? $saved : array();
		$clean = array();
		foreach ( array_keys( BST_Tickets::priorities() ) as $priority ) {
			$clean[ $priority ] = array(
				'response'   => absint( $saved[ $priority ]['response'] ?? 0 ),
				'resolution' => absint( $saved[ $priority ]['resolution'] ?? 0 ),
			);
		}
		return $clean;
	}

	/**
	 * Targets that apply to a ticket: its priority, with its client's plan
	 * overrides.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array{response:int,resolution:int,clock:string,plan:int}
	 */
	public static function targets_for( $ticket_id ) {
		$targets  = self::targets();
		$priority = BST_Tickets::priority( $ticket_id );
		$target   = $targets[ $priority ] ?? $targets['normal'];
		$target['plan'] = 0;

		$company = BST_Companies::for_ticket( $ticket_id );
		$plan    = $company ? BST_Companies::term( $company, BST_Post_Types::PLAN ) : null;
		if ( $plan ) {
			$override = self::plan_targets( $plan->term_id )[ $priority ] ?? array();
			if ( ! empty( $override['response'] ) ) {
				$target['response'] = (int) $override['response'];
			}
			if ( ! empty( $override['resolution'] ) ) {
				$target['resolution'] = (int) $override['resolution'];
			}
			$target['plan'] = (int) $plan->term_id;
		}

		return $target;
	}

	/*
	|----------------------------------------------------------------------
	| Business hours and holidays
	|----------------------------------------------------------------------
	*/

	/**
	 * Open weekdays from settings.
	 *
	 * @return int[]
	 */
	public static function working_days() {
		$days = array_map( 'intval', explode( ',', (string) BST_Settings::get( 'sla_days' ) ) );
		return array_values(
			array_filter(
				$days,
				function ( $day ) {
					return $day >= 1 && $day <= 7;
				}
			)
		);
	}

	/**
	 * The clock for a priority.
	 *
	 * @param string $clock business|always.
	 * @return BST_Business_Hours
	 */
	public static function clock( $clock = 'business' ) {
		$tz = wp_timezone();
		if ( 'always' === $clock ) {
			return BST_Business_Hours::always( $tz );
		}
		return new BST_Business_Hours(
			self::working_days(),
			(string) BST_Settings::get( 'sla_start' ),
			(string) BST_Settings::get( 'sla_end' ),
			$tz,
			self::closed_days()
		);
	}

	/**
	 * Clock for a ticket's priority.
	 *
	 * @param int $ticket_id Ticket.
	 * @return BST_Business_Hours
	 */
	public static function ticket_clock( $ticket_id ) {
		$targets  = self::targets();
		$priority = BST_Tickets::priority( $ticket_id );
		return self::clock( $targets[ $priority ]['clock'] ?? 'business' );
	}

	/**
	 * Bank holidays (chosen region) plus extra closed days, Y-m-d.
	 *
	 * @return string[]
	 */
	public static function closed_days() {
		$days   = self::bank_holidays( (string) BST_Settings::get( 'sla_holiday_region' ) );
		$extra  = preg_split( '/[\s,]+/', (string) BST_Settings::get( 'sla_closed_days' ) );
		foreach ( $extra as $day ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				$days[] = $day;
			}
		}
		return array_values( array_unique( $days ) );
	}

	/**
	 * gov.uk regions.
	 *
	 * @return array<string,string>
	 */
	public static function holiday_regions() {
		return array(
			'england-and-wales' => __( 'England and Wales', 'bonsai-support-tickets' ),
			'scotland'          => __( 'Scotland', 'bonsai-support-tickets' ),
			'northern-ireland'  => __( 'Northern Ireland', 'bonsai-support-tickets' ),
			'none'              => __( 'None (I\'ll add closed days myself)', 'bonsai-support-tickets' ),
		);
	}

	/**
	 * A region's bank holidays: the gov.uk copy if we have one, else the
	 * bundled list.
	 *
	 * @param string $region Region key.
	 * @return string[]
	 */
	public static function bank_holidays( $region ) {
		if ( 'none' === $region || ! array_key_exists( $region, self::holiday_regions() ) ) {
			return array();
		}
		$cache = get_option( self::HOLIDAY_OPTION );
		if ( is_array( $cache ) && ! empty( $cache['regions'][ $region ] ) && is_array( $cache['regions'][ $region ] ) ) {
			return $cache['regions'][ $region ];
		}
		$fallback = self::fallback_holidays();
		return $fallback[ $region ] ?? array();
	}

	/**
	 * When the gov.uk list was last fetched (0 = never, using the bundled list).
	 *
	 * @return int Unix time.
	 */
	public static function holidays_fetched_at() {
		$cache = get_option( self::HOLIDAY_OPTION );
		return is_array( $cache ) ? (int) ( $cache['fetched'] ?? 0 ) : 0;
	}

	/**
	 * Refresh from gov.uk if SLAs or reminders are on and the copy is over a
	 * week old. Runs daily.
	 */
	public static function maybe_refresh_holidays() {
		if ( ( self::enabled() || self::reminders_enabled() ) && self::holidays_fetched_at() < time() - WEEK_IN_SECONDS ) {
			self::refresh_holidays();
		}
	}

	/**
	 * Fetch the gov.uk list. Keeps the old copy on failure.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh_holidays() {
		try {
			$response = wp_remote_get( self::HOLIDAY_URL, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) ) {
				error_log( BST_PRODUCT_NAME . ': bank holiday fetch failed: ' . $response->get_error_message() );
				return $response;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				error_log( BST_PRODUCT_NAME . ': bank holiday fetch returned HTTP ' . wp_remote_retrieve_response_code( $response ) );
				return new WP_Error( 'bst_holidays_http', __( 'gov.uk returned an error. The previous list is still in use.', 'bonsai-support-tickets' ) );
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) ) {
				error_log( BST_PRODUCT_NAME . ': bank holiday feed was not valid JSON.' );
				return new WP_Error( 'bst_holidays_json', __( 'The gov.uk list could not be read. The previous list is still in use.', 'bonsai-support-tickets' ) );
			}

			$regions = array();
			foreach ( array_keys( self::holiday_regions() ) as $region ) {
				$events = $data[ $region ]['events'] ?? array();
				$dates  = array();
				foreach ( (array) $events as $event ) {
					$date = (string) ( $event['date'] ?? '' );
					if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
						$dates[] = $date;
					}
				}
				if ( $dates ) {
					$regions[ $region ] = $dates;
				}
			}

			if ( ! $regions ) {
				return new WP_Error( 'bst_holidays_empty', __( 'The gov.uk list was empty. The previous list is still in use.', 'bonsai-support-tickets' ) );
			}

			update_option(
				self::HOLIDAY_OPTION,
				array(
					'fetched' => time(),
					'regions' => $regions,
				),
				false
			);
			self::refresh_open_tickets();
			return true;
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': bank holiday fetch exception: ' . $e->getMessage() );
			return new WP_Error( 'bst_holidays', __( 'Could not update bank holidays.', 'bonsai-support-tickets' ) );
		}
	}

	/**
	 * Bundled bank holidays (gov.uk, fetched October 2026), used until the
	 * first successful fetch.
	 *
	 * @return array<string,string[]>
	 */
	public static function fallback_holidays() {
		return array(
			'england-and-wales' => array( '2026-01-01', '2026-04-03', '2026-04-06', '2026-05-04', '2026-05-25', '2026-08-31', '2026-12-25', '2026-12-28', '2027-01-01', '2027-03-26', '2027-03-29', '2027-05-03', '2027-05-31', '2027-08-30', '2027-12-27', '2027-12-28', '2028-01-03', '2028-04-14', '2028-04-17', '2028-05-01', '2028-05-29', '2028-08-28', '2028-12-25', '2028-12-26' ),
			'scotland'          => array( '2026-01-01', '2026-01-02', '2026-04-03', '2026-05-04', '2026-05-25', '2026-06-15', '2026-08-03', '2026-11-30', '2026-12-25', '2026-12-28', '2027-01-01', '2027-01-04', '2027-03-26', '2027-05-03', '2027-05-31', '2027-08-02', '2027-11-30', '2027-12-27', '2027-12-28', '2028-01-03', '2028-01-04', '2028-04-14', '2028-05-01', '2028-05-29', '2028-08-07', '2028-11-30', '2028-12-25', '2028-12-26' ),
			'northern-ireland'  => array( '2026-01-01', '2026-03-17', '2026-04-03', '2026-04-06', '2026-05-04', '2026-05-25', '2026-07-13', '2026-08-31', '2026-12-25', '2026-12-28', '2027-01-01', '2027-03-17', '2027-03-26', '2027-03-29', '2027-05-03', '2027-05-31', '2027-07-12', '2027-08-30', '2027-12-27', '2027-12-28', '2028-01-03', '2028-03-17', '2028-04-14', '2028-04-17', '2028-05-01', '2028-05-29', '2028-07-12', '2028-08-28', '2028-12-25', '2028-12-26' ),
		);
	}

	/*
	|----------------------------------------------------------------------
	| Time helpers
	|----------------------------------------------------------------------
	*/

	/**
	 * GMT MySQL datetime → DateTimeImmutable (UTC).
	 *
	 * @param string $gmt Y-m-d H:i:s.
	 * @return DateTimeImmutable|null
	 */
	public static function from_gmt( $gmt ) {
		if ( ! $gmt || self::FAR_FUTURE === $gmt ) {
			return null;
		}
		try {
			return new DateTimeImmutable( $gmt, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * DateTime → GMT MySQL datetime.
	 *
	 * @param DateTimeInterface $at Moment.
	 * @return string
	 */
	public static function to_gmt( DateTimeInterface $at ) {
		return gmdate( 'Y-m-d H:i:s', $at->getTimestamp() );
	}

	/**
	 * Now (UTC). Filterable so tests can travel in time.
	 *
	 * @return DateTimeImmutable
	 */
	public static function now() {
		/**
		 * Filters "now" for SLA and reminder sums (tests).
		 *
		 * @param int $timestamp Unix time.
		 */
		$timestamp = (int) apply_filters( 'bst_sla_now', time() );
		return new DateTimeImmutable( '@' . $timestamp );
	}

	/*
	|----------------------------------------------------------------------
	| Ticket clock
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether a ticket is measured.
	 *
	 * @param int $ticket_id Ticket.
	 * @return bool
	 */
	public static function applies( $ticket_id ) {
		return (bool) get_post_meta( $ticket_id, self::META_ON, true );
	}

	/**
	 * New ticket: start its SLA (if on) and its waiting time.
	 *
	 * @param int $ticket_id  Ticket.
	 * @param int $message_id First message.
	 * @param int $actor_id   Creator.
	 */
	public static function on_created( $ticket_id, $message_id = 0, $actor_id = 0 ) {
		unset( $message_id, $actor_id );
		$now = self::to_gmt( self::now() );
		update_post_meta( $ticket_id, self::META_WAIT_SINCE, $now );
		update_post_meta( $ticket_id, self::META_REMINDERS, 0 );

		// Uptime alerts are internal; they'd skew the client figures.
		if ( self::enabled() && ! self::is_monitor_source( $ticket_id ) ) {
			update_post_meta( $ticket_id, self::META_ON, 1 );
			self::refresh( $ticket_id );
		}
	}

	/**
	 * Whether a ticket was opened by uptime monitoring (its dedupe key is
	 * stored after this runs, so check the first message's source).
	 *
	 * @param int $ticket_id Ticket.
	 * @return bool
	 */
	private static function is_monitor_source( $ticket_id ) {
		$messages = BST_Messages::for_ticket( $ticket_id, true );
		return $messages && 'monitor' === ( $messages[0]->source ?? '' );
	}

	/**
	 * Message added: an agent's first reply to the client meets first
	 * response; a client message restarts the waiting time.
	 *
	 * @param int  $ticket_id  Ticket.
	 * @param int  $message_id Message.
	 * @param bool $is_agent   Author is an agent.
	 */
	public static function on_message( $ticket_id, $message_id, $is_agent ) {
		$message = BST_Messages::get( $message_id );
		if ( ! $message || BST_Messages::EXTERNAL !== $message->visibility ) {
			return;
		}

		$now = self::to_gmt( self::now() );

		if ( $is_agent ) {
			if ( self::applies( $ticket_id ) && ! get_post_meta( $ticket_id, self::META_FIRST_RESPONSE, true ) ) {
				update_post_meta( $ticket_id, self::META_FIRST_RESPONSE, $now );
				self::refresh( $ticket_id );
			}
			return;
		}

		update_post_meta( $ticket_id, self::META_WAIT_SINCE, $now );
		update_post_meta( $ticket_id, self::META_REMINDERS, 0 );
	}

	/**
	 * Status changed: pause or resume the resolution clock; restart the
	 * waiting time when the ticket comes back to us.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $old       Old status.
	 * @param string $new       New status.
	 * @param int    $actor_id  Actor.
	 */
	public static function on_status( $ticket_id, $old, $new, $actor_id = 0 ) {
		unset( $actor_id );
		$was_paused = in_array( $old, self::PAUSED_STATUSES, true );
		$is_paused  = in_array( $new, self::PAUSED_STATUSES, true );
		$now        = self::now();

		if ( self::applies( $ticket_id ) ) {
			if ( $is_paused && ! $was_paused ) {
				update_post_meta( $ticket_id, self::META_PAUSED_SINCE, self::to_gmt( $now ) );
			} elseif ( $was_paused && ! $is_paused ) {
				$since = self::from_gmt( (string) get_post_meta( $ticket_id, self::META_PAUSED_SINCE, true ) );
				if ( $since ) {
					$paused = self::ticket_clock( $ticket_id )->between( $since, $now );
					update_post_meta( $ticket_id, self::META_PAUSED_MINUTES, (int) get_post_meta( $ticket_id, self::META_PAUSED_MINUTES, true ) + $paused );
				}
				delete_post_meta( $ticket_id, self::META_PAUSED_SINCE );
			}
			self::refresh( $ticket_id );
		}

		if ( in_array( $new, self::WAITING_STATUSES, true ) && ! in_array( $old, self::WAITING_STATUSES, true ) ) {
			update_post_meta( $ticket_id, self::META_WAIT_SINCE, self::to_gmt( $now ) );
			update_post_meta( $ticket_id, self::META_REMINDERS, 0 );
		}
	}

	/**
	 * Recalculate a ticket's due times and states.
	 *
	 * @param int $ticket_id Ticket.
	 */
	public static function refresh( $ticket_id ) {
		$ticket_id = (int) $ticket_id;
		if ( ! self::applies( $ticket_id ) ) {
			return;
		}

		$state = self::state( $ticket_id );

		update_post_meta( $ticket_id, self::META_RESPONSE_DUE, self::to_gmt( $state['response_due'] ) );
		update_post_meta( $ticket_id, self::META_RESOLUTION_DUE, self::to_gmt( $state['resolution_due'] ) );
		update_post_meta( $ticket_id, self::META_RESPONSE, $state['response'] );
		update_post_meta( $ticket_id, self::META_RESOLUTION, $state['resolution'] );

		$next = self::FAR_FUTURE;
		// The first-response clock matters until someone replies (even once breached).
		if ( ! get_post_meta( $ticket_id, self::META_FIRST_RESPONSE, true ) ) {
			$next = self::to_gmt( $state['response_due'] );
		} elseif ( in_array( $state['resolution'], array( 'pending', 'breached' ), true ) && ! in_array( BST_Tickets::status( $ticket_id ), self::PAUSED_STATUSES, true ) ) {
			$next = self::to_gmt( $state['resolution_due'] );
		}
		update_post_meta( $ticket_id, self::META_NEXT_DUE, $next );
	}

	/**
	 * Work out a ticket's SLA state (no writes).
	 *
	 * @param int $ticket_id Ticket.
	 * @return array {
	 *     @type DateTimeImmutable $response_due
	 *     @type DateTimeImmutable $resolution_due   With pauses so far (and the current one).
	 *     @type string            $response         pending|met|breached.
	 *     @type string            $resolution       pending|paused|met|breached.
	 *     @type array             $targets          targets_for().
	 *     @type int               $response_left    Clock minutes left (negative = overdue).
	 *     @type int               $resolution_left  Clock minutes left (negative = overdue).
	 * }
	 */
	public static function state( $ticket_id ) {
		$targets = self::targets_for( $ticket_id );
		$clock   = self::clock( $targets['clock'] );
		$now     = self::now();
		$created = self::from_gmt( (string) get_post_field( 'post_date_gmt', $ticket_id ) );
		$created = $created ? $created : $now;
		$status  = BST_Tickets::status( $ticket_id );

		// First response.
		$response_due = $clock->add( $created, $targets['response'] );
		$first        = self::from_gmt( (string) get_post_meta( $ticket_id, self::META_FIRST_RESPONSE, true ) );
		if ( $first ) {
			$response = $first <= $response_due ? 'met' : 'breached';
		} else {
			$response = $now > $response_due ? 'breached' : 'pending';
		}

		// Resolution: "active" clock minutes used = time since creation minus
		// every paused stretch (finished ones, plus the current one).
		$paused       = (int) get_post_meta( $ticket_id, self::META_PAUSED_MINUTES, true );
		$paused_since = self::from_gmt( (string) get_post_meta( $ticket_id, self::META_PAUSED_SINCE, true ) );
		$done         = in_array( $status, array( 'solved', 'closed' ), true );

		// A solved ticket is judged when it was solved, not now.
		$end = $now;
		if ( $done ) {
			$solved_at = self::from_gmt( (string) get_post_meta( $ticket_id, BST_Tickets::META_SOLVED_AT, true ) );
			$end       = $solved_at ? $solved_at : ( $paused_since ? $paused_since : $now );
		}

		$current_pause = $paused_since && $paused_since < $end ? $clock->between( $paused_since, $end ) : 0;
		$used          = max( 0, $clock->between( $created, $end ) - $paused - $current_pause );
		$over          = $used > $targets['resolution'];

		if ( $done ) {
			$resolution = $over ? 'breached' : 'met';
		} elseif ( $paused_since ) {
			$resolution = $over ? 'breached' : 'paused'; // Breached before the pause stays breached.
		} else {
			$resolution = $over ? 'breached' : 'pending';
		}

		// When it's (or was) due, if the clock keeps running from now.
		$resolution_due = $clock->add( $created, $targets['resolution'] + $paused + ( $paused_since ? $clock->between( $paused_since, $now ) : 0 ) );

		$response_used = $first ? $clock->between( $created, $first ) : $clock->between( $created, $now );

		return array(
			'response_due'    => $response_due,
			'resolution_due'  => $resolution_due,
			'response'        => $response,
			'resolution'      => $resolution,
			'targets'         => $targets,
			'response_left'   => $targets['response'] - $response_used,
			'resolution_left' => $targets['resolution'] - $used,
		);
	}

	/**
	 * Refresh every open measured ticket (after settings or holidays change).
	 */
	public static function refresh_open_tickets() {
		foreach ( self::open_ticket_ids( true ) as $ticket_id ) {
			self::refresh( $ticket_id );
		}
	}

	/**
	 * Active tickets, optionally only measured ones.
	 *
	 * @param bool     $measured Only tickets with an SLA.
	 * @param string[] $statuses Statuses (default all active).
	 * @return int[]
	 */
	private static function open_ticket_ids( $measured, array $statuses = array() ) {
		$meta_query = array(
			array(
				'key'     => BST_Tickets::META_STATUS,
				'value'   => $statuses ? $statuses : BST_Tickets::active_statuses(),
				'compare' => 'IN',
			),
		);
		if ( $measured ) {
			$meta_query[] = array(
				'key'   => self::META_ON,
				'value' => '1',
			);
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => BST_Post_Types::TICKET,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'numberposts'      => 1000,
					'suppress_filters' => true,
					'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			)
		);
	}

	/*
	|----------------------------------------------------------------------
	| Cron: warnings, breaches and reminders
	|----------------------------------------------------------------------
	*/

	/**
	 * Five-minute interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'bonsai-support-tickets' ),
		);
		return $schedules;
	}

	/**
	 * Keep the check scheduled while either feature is on.
	 */
	public static function schedule() {
		$on = self::enabled() || self::reminders_enabled();
		if ( $on && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		} elseif ( ! $on && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * The five-minute check.
	 *
	 * @return array{warned:int,breached:int,reminded:int}
	 */
	public static function run() {
		$counts = array(
			'warned'   => 0,
			'breached' => 0,
			'reminded' => 0,
		);

		try {
			if ( self::enabled() ) {
				foreach ( self::open_ticket_ids( true ) as $ticket_id ) {
					self::refresh( $ticket_id );
					$sent = self::check_ticket( $ticket_id );
					$counts['warned']   += $sent['warned'];
					$counts['breached'] += $sent['breached'];
				}
			}

			if ( self::reminders_enabled() ) {
				foreach ( self::open_ticket_ids( false, self::WAITING_STATUSES ) as $ticket_id ) {
					$counts['reminded'] += self::maybe_remind( $ticket_id ) ? 1 : 0;
				}
			}
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': SLA check failed: ' . $e->getMessage() );
		}

		return $counts;
	}

	/**
	 * Warn about one ticket if it's close to or past a target. Each warning
	 * is sent once per ticket.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array{warned:int,breached:int}
	 */
	public static function check_ticket( $ticket_id ) {
		$sent  = array(
			'warned'   => 0,
			'breached' => 0,
		);
		$state = self::state( $ticket_id );
		$done  = get_post_meta( $ticket_id, self::META_NOTIFIED, true );
		$done  = is_array( $done ) ? $done : array();
		$warn  = max( 1, min( 90, (int) BST_Settings::get( 'sla_warn_percent' ) ) ) / 100;

		$checks = array(
			'response'   => array(
				'pending' => 'pending' === $state['response'],
				'left'    => $state['response_left'],
				'target'  => $state['targets']['response'],
				'state'   => $state['response'],
				'live'    => ! get_post_meta( $ticket_id, self::META_FIRST_RESPONSE, true ),
			),
			'resolution' => array(
				'pending' => 'pending' === $state['resolution'],
				'left'    => $state['resolution_left'],
				'target'  => $state['targets']['resolution'],
				'state'   => $state['resolution'],
				'live'    => ! in_array( BST_Tickets::status( $ticket_id ), self::PAUSED_STATUSES, true ),
			),
		);

		foreach ( $checks as $metric => $check ) {
			if ( ! $check['live'] ) {
				continue;
			}
			if ( 'breached' === $check['state'] && ! in_array( $metric . '_breach', $done, true ) ) {
				$done[] = $metric . '_breach';
				$done[] = $metric . '_warn'; // No point warning after the fact.
				update_post_meta( $ticket_id, self::META_NOTIFIED, array_values( array_unique( $done ) ) );
				self::notify( $ticket_id, $metric, 'breach', $state );
				++$sent['breached'];
			} elseif ( $check['pending'] && $check['left'] <= $check['target'] * $warn && ! in_array( $metric . '_warn', $done, true ) ) {
				$done[] = $metric . '_warn';
				update_post_meta( $ticket_id, self::META_NOTIFIED, array_values( array_unique( $done ) ) );
				self::notify( $ticket_id, $metric, 'warn', $state );
				++$sent['warned'];
			}
		}

		return $sent;
	}

	/**
	 * Send a reminder if one is due.
	 *
	 * @param int $ticket_id Ticket.
	 * @return bool Sent.
	 */
	public static function maybe_remind( $ticket_id ) {
		$rules    = self::reminder_rules();
		$priority = BST_Tickets::priority( $ticket_id );
		$rule     = $rules[ $priority ] ?? array(
			'first'  => 0,
			'repeat' => 0,
		);
		if ( ! $rule['first'] ) {
			return false;
		}

		$since = self::from_gmt( (string) get_post_meta( $ticket_id, self::META_WAIT_SINCE, true ) );
		if ( ! $since ) {
			// Ticket from before reminders were on: start counting now rather than flooding inboxes.
			update_post_meta( $ticket_id, self::META_WAIT_SINCE, self::to_gmt( self::now() ) );
			update_post_meta( $ticket_id, self::META_REMINDERS, 0 );
			return false;
		}

		$waited = self::ticket_clock( $ticket_id )->between( $since, self::now() );
		$due    = self::reminders_due( $waited, $rule['first'], $rule['repeat'] );
		$sent   = (int) get_post_meta( $ticket_id, self::META_REMINDERS, true );

		if ( $due <= $sent ) {
			return false;
		}

		// Record first: one email per run even if several were missed.
		update_post_meta( $ticket_id, self::META_REMINDERS, $due );
		self::send_reminder( $ticket_id, $waited );
		return true;
	}

	/**
	 * How many reminders should have gone after waiting this long.
	 *
	 * @param int $waited Clock minutes waited.
	 * @param int $first  First reminder after (minutes).
	 * @param int $repeat Then every (minutes, 0 = once).
	 * @return int
	 */
	public static function reminders_due( $waited, $first, $repeat ) {
		if ( ! $first || $waited < $first ) {
			return 0;
		}
		return 1 + ( $repeat ? intdiv( $waited - $first, $repeat ) : 0 );
	}

	/*
	|----------------------------------------------------------------------
	| Notifications
	|----------------------------------------------------------------------
	*/

	/**
	 * Email addresses for a ticket's warnings and reminders: the assignee,
	 * else every agent.
	 *
	 * @param int $ticket_id Ticket.
	 * @return string[]
	 */
	private static function recipients( $ticket_id ) {
		$emails = array();
		foreach ( BST_Mailer::agent_recipients( $ticket_id ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		return array_unique( $emails );
	}

	/**
	 * Detail rows shared by warnings and reminders.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array<string,string>
	 */
	private static function ticket_details( $ticket_id ) {
		$company = BST_Companies::name( BST_Companies::for_ticket( $ticket_id ) );
		$person  = wp_specialchars_decode( BST_Tickets::contact_name( $ticket_id ), ENT_QUOTES );
		return array(
			__( 'Ticket', 'bonsai-support-tickets' )   => BST_Tickets::ref( $ticket_id ) . ' ' . wp_specialchars_decode( (string) get_post_field( 'post_title', $ticket_id, 'raw' ), ENT_QUOTES ),
			__( 'Client', 'bonsai-support-tickets' )   => '' !== $company ? $company . ' — ' . $person : $person,
			__( 'Priority', 'bonsai-support-tickets' ) => BST_Tickets::priority_label( BST_Tickets::priority( $ticket_id ) ),
			__( 'Status', 'bonsai-support-tickets' )   => BST_Tickets::status_label( BST_Tickets::status( $ticket_id ), true ),
		);
	}

	/**
	 * Warn or report a breach.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $metric    response|resolution.
	 * @param string $kind      warn|breach.
	 * @param array  $state     state().
	 */
	private static function notify( $ticket_id, $metric, $kind, array $state ) {
		$ref   = BST_Tickets::ref( $ticket_id );
		$what  = 'response' === $metric ? __( 'first response', 'bonsai-support-tickets' ) : __( 'resolution', 'bonsai-support-tickets' );
		$due   = 'response' === $metric ? $state['response_due'] : $state['resolution_due'];
		$left  = 'response' === $metric ? $state['response_left'] : $state['resolution_left'];

		$subject = 'breach' === $kind
			/* translators: 1: ticket reference, 2: "first response" or "resolution". */
			? sprintf( __( 'SLA breached: %1$s %2$s is overdue', 'bonsai-support-tickets' ), $ref, $what )
			/* translators: 1: ticket reference, 2: "first response" or "resolution", 3: time left, e.g. 25m. */
			: sprintf( __( 'SLA at risk: %1$s %2$s due in %3$s', 'bonsai-support-tickets' ), $ref, $what, BST_Duration::format( max( 0, $left ) ) );

		$details = self::ticket_details( $ticket_id ) + array(
			__( 'Due', 'bonsai-support-tickets' ) => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $due->getTimestamp() ),
		);

		foreach ( self::recipients( $ticket_id ) as $email ) {
			BST_Mailer::send_account_email(
				$email,
				$subject,
				array(
					'heading'      => $subject,
					'intro'        => 'breach' === $kind
						? __( 'This ticket has missed its target. Reply or update it as soon as you can.', 'bonsai-support-tickets' )
						: __( 'This ticket is close to missing its target.', 'bonsai-support-tickets' ),
					'details'      => $details,
					'button_url'   => BST_Tickets::admin_url( $ticket_id ),
					'button_label' => __( 'Open ticket', 'bonsai-support-tickets' ),
					'footer'       => __( 'Sent because SLAs are on (Support → Settings → SLAs & reminders).', 'bonsai-support-tickets' ),
				)
			);
		}

		if ( BST_Slack::enabled() ) {
			$lines = array( ( 'breach' === $kind ? ':red_circle: ' : ':large_orange_circle: ' ) . '*' . BST_Slack::escape( $subject ) . '*' );
			foreach ( $details as $label => $value ) {
				$lines[] = BST_Slack::escape( $label . ': ' . $value );
			}
			$lines[] = '<' . BST_Tickets::admin_url( $ticket_id ) . '|' . BST_Slack::escape( __( 'Open ticket', 'bonsai-support-tickets' ) ) . '>';
			BST_Slack::send( array( 'text' => implode( "\n", $lines ) ), false );
		}
	}

	/**
	 * Reminder email.
	 *
	 * @param int $ticket_id Ticket.
	 * @param int $waited    Clock minutes waiting on us.
	 */
	private static function send_reminder( $ticket_id, $waited ) {
		$subject = sprintf(
			/* translators: 1: ticket reference, 2: priority, 3: time waiting, e.g. 2h 15m. */
			__( 'Reminder: %1$s (%2$s) has been waiting %3$s', 'bonsai-support-tickets' ),
			BST_Tickets::ref( $ticket_id ),
			BST_Tickets::priority_label( BST_Tickets::priority( $ticket_id ) ),
			BST_Duration::format( $waited )
		);

		foreach ( self::recipients( $ticket_id ) as $email ) {
			BST_Mailer::send_account_email(
				$email,
				$subject,
				array(
					'heading'      => $subject,
					'intro'        => __( 'This ticket is still waiting on us. Reply to the client, or set it to Awaiting client or On hold to stop the reminders.', 'bonsai-support-tickets' ),
					'details'      => self::ticket_details( $ticket_id ),
					'button_url'   => BST_Tickets::admin_url( $ticket_id ),
					'button_label' => __( 'Open ticket', 'bonsai-support-tickets' ),
					'footer'       => __( 'Sent because response reminders are on (Support → Settings → SLAs & reminders).', 'bonsai-support-tickets' ),
				)
			);
		}
	}

	/*
	|----------------------------------------------------------------------
	| Report
	|----------------------------------------------------------------------
	*/

	/**
	 * Measured tickets created in a month, with their outcomes.
	 *
	 * @param string $month Y-m.
	 * @return array[] Each: ticket_id, priority, company_id, plan_id, response, resolution.
	 */
	public static function month_rows( $month ) {
		$month = BST_Time::sanitize_month( $month );
		list( $from, $to ) = BST_Time::month_bounds( $month );

		$ids = get_posts(
			array(
				'post_type'        => BST_Post_Types::TICKET,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
				'date_query'       => array(
					array(
						'after'     => $from . ' 00:00:00',
						'before'    => $to . ' 23:59:59',
						'inclusive' => true,
					),
				),
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_ON,
						'value' => '1',
					),
				),
			)
		);

		$rows = array();
		foreach ( $ids as $ticket_id ) {
			self::refresh( $ticket_id );
			$state   = self::state( $ticket_id );
			$company = BST_Companies::for_ticket( $ticket_id );
			$plan    = $company ? BST_Companies::term( $company, BST_Post_Types::PLAN ) : null;
			$rows[]  = array(
				'ticket_id'  => (int) $ticket_id,
				'priority'   => BST_Tickets::priority( $ticket_id ),
				'company_id' => (int) $company,
				'plan_id'    => $plan ? (int) $plan->term_id : 0,
				'response'   => $state['response'],
				'resolution' => $state['resolution'],
			);
		}
		return $rows;
	}

	/**
	 * Group rows and count outcomes.
	 *
	 * @param array[] $rows month_rows().
	 * @param string  $by   priority|company_id|plan_id.
	 * @return array<string,array> key => array( tickets, response_met, response_breached, resolution_met, resolution_breached, response_pct, resolution_pct ).
	 */
	public static function group( array $rows, $by ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$key = (string) $row[ $by ];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array_fill_keys( array( 'tickets', 'response_met', 'response_breached', 'resolution_met', 'resolution_breached' ), 0 );
			}
			++$groups[ $key ]['tickets'];
			foreach ( array( 'response', 'resolution' ) as $metric ) {
				if ( in_array( $row[ $metric ], array( 'met', 'breached' ), true ) ) {
					++$groups[ $key ][ $metric . '_' . $row[ $metric ] ];
				}
			}
		}
		foreach ( $groups as $key => $group ) {
			foreach ( array( 'response', 'resolution' ) as $metric ) {
				$decided = $group[ $metric . '_met' ] + $group[ $metric . '_breached' ];
				$groups[ $key ][ $metric . '_pct' ] = $decided ? round( $group[ $metric . '_met' ] / $decided * 100, 1 ) : null;
			}
		}
		return $groups;
	}
}
