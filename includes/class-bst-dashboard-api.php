<?php
/**
 * Read-only ticket summary for desktop dashboards and other agent tools.
 *
 *   GET /wp-json/bst/v1/dashboard/summary
 *
 * Authenticated with a WordPress Application Password (Users → Profile →
 * Application Passwords) over HTTPS. Only agents can read it: the caller
 * needs bst_view_all_tickets. Nothing is ever changed, and no message
 * bodies, client emails or internal notes are returned — just counts and
 * the ticket headline fields an agent already sees in the ticket list.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard summary endpoint.
 */
class BST_Dashboard_API {

	const REST_NAMESPACE = 'bst/v1';
	const LATEST_LIMIT   = 8;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the endpoint.
	 */
	public static function routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/dashboard/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'summary' ),
				'permission_callback' => array( __CLASS__, 'authorise' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Agents only.
	 *
	 * @return bool|WP_Error
	 */
	public static function authorise() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'bst_dashboard_auth', __( 'Authentication required.', 'bonsai-support-tickets' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'bst_view_all_tickets' ) ) {
			return new WP_Error( 'bst_dashboard_forbidden', __( 'Only support agents can read the dashboard summary.', 'bonsai-support-tickets' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Counts per active status, mine/unassigned/SLA-missed counts and the
	 * most recently active open tickets.
	 *
	 * @return WP_REST_Response
	 */
	public static function summary() {
		$counts = array();
		foreach ( BST_Tickets::active_statuses() as $status ) {
			$counts[ $status ] = self::count(
				array(
					array(
						'key'   => BST_Tickets::META_STATUS,
						'value' => $status,
					),
				)
			);
		}

		$active     = BST_Admin_Tickets::view_meta_query( 'active' );
		$sla_missed = null;
		if ( BST_Settings::get( 'sla_enabled' ) ) {
			$sla_missed = self::count(
				array(
					'relation' => 'AND',
					$active,
					array(
						'relation' => 'OR',
						array(
							'key'   => BST_SLA::META_RESPONSE,
							'value' => 'breached',
						),
						array(
							'key'   => BST_SLA::META_RESOLUTION,
							'value' => 'breached',
						),
					),
				)
			);
		}

		$latest = array();
		foreach ( self::latest_ids( $active ) as $ticket_id ) {
			$latest[] = self::ticket_row( $ticket_id );
		}

		return rest_ensure_response(
			array(
				'site'         => BST_Settings::brand_name(),
				'active_total' => array_sum( $counts ),
				'counts'       => $counts,
				'mine'         => self::count( BST_Admin_Tickets::view_meta_query( 'mine' ) ),
				'unassigned'   => self::count( BST_Admin_Tickets::view_meta_query( 'unassigned' ) ),
				'sla_missed'   => $sla_missed,
				'latest'       => $latest,
				'list_url'     => admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ),
				'generated_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Number of published tickets matching a meta query.
	 *
	 * @param array $meta_query Meta query.
	 * @return int
	 */
	private static function count( array $meta_query ) {
		$query = new WP_Query(
			array(
				'post_type'              => BST_Post_Types::TICKET,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Most recently active open tickets.
	 *
	 * @param array $meta_query Meta query for open tickets.
	 * @return int[]
	 */
	private static function latest_ids( array $meta_query ) {
		return get_posts(
			array(
				'post_type'        => BST_Post_Types::TICKET,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => self::LATEST_LIMIT,
				'suppress_filters' => true,
				'meta_key'         => BST_Tickets::META_LAST_ACTIVITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'          => 'meta_value',
				'order'            => 'DESC',
				'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}

	/**
	 * Headline fields for one ticket. Plain values; the client escapes them.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array
	 */
	private static function ticket_row( $ticket_id ) {
		$assignee = BST_Tickets::assignee( $ticket_id );
		$user     = $assignee ? get_userdata( $assignee ) : false;
		$last     = (string) get_post_meta( $ticket_id, BST_Tickets::META_LAST_ACTIVITY, true );
		$status   = BST_Tickets::status( $ticket_id );
		$priority = BST_Tickets::priority( $ticket_id );

		return array(
			'id'             => (int) $ticket_id,
			'ref'            => BST_Tickets::ref( $ticket_id ),
			'title'          => html_entity_decode( get_the_title( $ticket_id ), ENT_QUOTES, 'UTF-8' ),
			'status'         => $status,
			'status_label'   => BST_Tickets::status_label( $status, true ),
			'priority'       => $priority,
			'priority_label' => BST_Tickets::priority_label( $priority ),
			'assignee'       => $user ? $user->display_name : '',
			'last_activity'  => $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : null,
			'url'            => BST_Tickets::admin_url( $ticket_id ),
		);
	}
}
