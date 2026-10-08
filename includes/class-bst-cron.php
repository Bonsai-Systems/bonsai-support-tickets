<?php
/**
 * Scheduled jobs: mailbox polling every 2 minutes, daily auto-close.
 *
 * WP-Cron only runs when someone visits the site. On a quiet support
 * subdomain, set a real server cron to hit wp-cron.php every 2 minutes and
 * add define( 'DISABLE_WP_CRON', true ); to wp-config.php.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron.
 */
class BST_Cron {

	const POLL_HOOK  = 'bst_poll_mailbox';
	const DAILY_HOOK = 'bst_daily_maintenance';
	const INTERVAL   = 'bst_every_two_minutes';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 2 minutes is intentional.
		add_action( self::POLL_HOOK, array( 'BST_Inbound', 'run' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'auto_close' ) );

		// Self-heal if the events have gone missing (e.g. cron table cleared).
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Add the two-minute interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_interval( $schedules ) {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 2 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every two minutes', 'bonsai-support-tickets' ),
		);
		return $schedules;
	}

	/**
	 * Schedule both events if they aren't already.
	 */
	public static function schedule() {
		// Activation runs after plugins_loaded, so make sure the interval exists.
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		}

		if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::POLL_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Remove both events.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::POLL_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * Close tickets that have been Solved for longer than the setting.
	 * 0 days = never auto-close.
	 */
	public static function auto_close() {
		$days = (int) BST_Settings::get( 'auto_close_days' );
		if ( $days < 1 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$ids = get_posts(
			array(
				'post_type'      => BST_Post_Types::TICKET,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => BST_Tickets::META_STATUS,
						'value' => 'solved',
					),
					array(
						'key'     => BST_Tickets::META_SOLVED_AT,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		foreach ( $ids as $ticket_id ) {
			BST_Tickets::set_status( $ticket_id, 'closed', 0 );
		}
	}
}
