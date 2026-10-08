<?php
/**
 * Support → Overview: the team's home screen, and (by default) the
 * replacement for the WordPress dashboard.
 *
 * While "Replace the WordPress dashboard" is on, anyone who can reply to
 * tickets lands here after logging in and when they open the Dashboard.
 * Administrators keep the Dashboard menu (for Updates); agents don't see it.
 * Other users (editors etc.) and clients are not affected.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview screen and dashboard replacement.
 */
class BST_Admin_Overview {

	const SLUG = 'bst-overview';

	/**
	 * Tickets listed per panel.
	 */
	const LIST_LIMIT = 8;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'hide_dashboard_menu' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_dashboard' ), 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	/**
	 * Overview URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET . '&page=' . self::SLUG ) );
	}

	/**
	 * Whether the dashboard should be replaced for a user.
	 *
	 * @param WP_User|int|null $user User (default current).
	 * @return bool
	 */
	public static function replaces_dashboard( $user = null ) {
		if ( ! BST_Settings::get( 'replace_dashboard' ) ) {
			return false;
		}
		$user = null === $user ? wp_get_current_user() : ( $user instanceof WP_User ? $user : get_userdata( (int) $user ) );
		return $user instanceof WP_User && $user->exists() && user_can( $user, 'bst_reply_tickets' );
	}

	/**
	 * Ticket list URL with filters.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private static function list_url( array $args ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ) );
	}

	/**
	 * Overview submenu, first under Support (so the Support menu opens it).
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . BST_Post_Types::TICKET,
			__( 'Support overview', 'bonsai-support-tickets' ),
			__( 'Overview', 'bonsai-support-tickets' ),
			'bst_reply_tickets',
			self::SLUG,
			array( __CLASS__, 'render' ),
			0
		);
	}

	/**
	 * Agents don't see the Dashboard menu. Administrators keep it for
	 * Updates; its Home item redirects here.
	 */
	public static function hide_dashboard_menu() {
		if ( self::replaces_dashboard() && ! current_user_can( 'update_core' ) && ! current_user_can( 'update_plugins' ) ) {
			remove_menu_page( 'index.php' );
		}
	}

	/**
	 * Dashboard → Overview. Only the dashboard itself: pages that plugins
	 * hang off index.php (?page=…) still work.
	 */
	public static function redirect_dashboard() {
		global $pagenow;
		if ( 'index.php' !== $pagenow || wp_doing_ajax() || isset( $_GET['page'] ) || ! self::replaces_dashboard() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * After logging in, the team lands on the overview (unless they were
	 * heading somewhere specific).
	 *
	 * @param string           $redirect_to Destination.
	 * @param string           $requested   Requested destination.
	 * @param WP_User|WP_Error $user        User.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! self::replaces_dashboard( $user ) ) {
			return $redirect_to;
		}
		$admin = admin_url();
		if ( '' === $requested || untrailingslashit( $requested ) === untrailingslashit( $admin ) || $requested === $admin . 'index.php' ) {
			return self::url();
		}
		return $redirect_to;
	}

	/**
	 * Admin bar: the site menu's "Dashboard" link opens the overview.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! self::replaces_dashboard() ) {
			return;
		}
		$node = $bar->get_node( 'dashboard' );
		if ( $node ) {
			$bar->add_node(
				array(
					'id'    => 'dashboard',
					'title' => __( 'Support overview', 'bonsai-support-tickets' ),
					'href'  => self::url(),
				)
			);
		}
	}

	/*
	|----------------------------------------------------------------------
	| Data
	|----------------------------------------------------------------------
	*/

	/**
	 * Count tickets matching a meta query.
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
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Meta clause for one status.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	private static function status_clause( $status ) {
		return array(
			array(
				'key'   => BST_Tickets::META_STATUS,
				'value' => $status,
			),
		);
	}

	/**
	 * Headline numbers, each with a link to the matching list.
	 *
	 * @return array[] Each: label, count, url, tone (neutral|warning|error).
	 */
	public static function stats() {
		$stats = array(
			array(
				'label' => __( 'My open tickets', 'bonsai-support-tickets' ),
				'count' => self::count( BST_Admin_Tickets::view_meta_query( 'mine' ) ),
				'url'   => self::list_url( array( 'bst_view' => 'mine' ) ),
				'tone'  => 'neutral',
			),
			array(
				'label' => __( 'Unassigned', 'bonsai-support-tickets' ),
				'count' => self::count( BST_Admin_Tickets::view_meta_query( 'unassigned' ) ),
				'url'   => self::list_url( array( 'bst_view' => 'unassigned' ) ),
				'tone'  => 'warning',
			),
			array(
				'label' => __( 'New', 'bonsai-support-tickets' ),
				'count' => self::count( self::status_clause( 'new' ) ),
				'url'   => self::list_url( array( 'bst_status' => 'new' ) ),
				'tone'  => 'neutral',
			),
		);

		if ( BST_SLA::enabled() ) {
			$stats[] = array(
				'label' => __( 'Overdue', 'bonsai-support-tickets' ),
				'count' => count( self::overdue_ids() ),
				'url'   => self::list_url(
					array(
						'bst_view' => 'active',
						'orderby'  => 'bst_due',
						'order'    => 'asc',
					)
				),
				'tone'  => 'error',
			);
		}

		$stats[] = array(
			'label' => BST_Tickets::status_label( 'awaiting_client', true ),
			'count' => self::count( self::status_clause( 'awaiting_client' ) ),
			'url'   => self::list_url( array( 'bst_status' => 'awaiting_client' ) ),
			'tone'  => 'neutral',
		);
		$stats[] = array(
			'label' => BST_Tickets::status_label( 'on_hold', true ),
			'count' => self::count( self::status_clause( 'on_hold' ) ),
			'url'   => self::list_url( array( 'bst_status' => 'on_hold' ) ),
			'tone'  => 'neutral',
		);

		$unverified = self::count( BST_Admin_Tickets::view_meta_query( 'unverified' ) );
		if ( $unverified ) {
			$stats[] = array(
				'label' => __( 'Unverified', 'bonsai-support-tickets' ),
				'count' => $unverified,
				'url'   => self::list_url( array( 'bst_view' => 'unverified' ) ),
				'tone'  => 'warning',
			);
		}

		/**
		 * Filters the Overview's headline numbers.
		 *
		 * @param array[] $stats Each: label, count, url, tone.
		 */
		return apply_filters( 'bst_overview_stats', $stats );
	}

	/**
	 * Active measured tickets past a due time, soonest-due first.
	 *
	 * @return int[]
	 */
	private static function overdue_ids() {
		return get_posts(
			array(
				'post_type'        => BST_Post_Types::TICKET,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => 100,
				'suppress_filters' => true,
				'meta_key'         => BST_SLA::META_NEXT_DUE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'          => 'meta_value',
				'order'            => 'ASC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => BST_SLA::META_NEXT_DUE,
						'value'   => BST_SLA::to_gmt( BST_SLA::now() ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => BST_Tickets::META_STATUS,
						'value'   => BST_Tickets::active_statuses(),
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/**
	 * Tickets for a panel.
	 *
	 * @param array  $meta_query Meta query.
	 * @param string $order_key  Meta key to sort by.
	 * @param string $order      ASC|DESC.
	 * @return int[]
	 */
	private static function ticket_ids( array $meta_query, $order_key, $order ) {
		return get_posts(
			array(
				'post_type'        => BST_Post_Types::TICKET,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => self::LIST_LIMIT,
				'suppress_filters' => true,
				'meta_key'         => $order_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'          => 'meta_value',
				'order'            => $order,
				'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}

	/**
	 * Latest activity across all tickets.
	 *
	 * @param int $limit Rows.
	 * @return object[]
	 */
	private static function recent_activity( $limit = 10 ) {
		global $wpdb;
		$table = BST_Activity::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", absint( $limit ) * 2 ) );

		// Skip events for deleted or trashed tickets.
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( 'publish' === get_post_status( (int) $row->ticket_id ) ) {
				$out[] = $row;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/*
	|----------------------------------------------------------------------
	| Screen
	|----------------------------------------------------------------------
	*/

	/**
	 * One ticket row.
	 *
	 * @param int  $ticket_id Ticket.
	 * @param bool $show_due  Show the SLA due cell instead of last activity.
	 */
	private static function ticket_row( $ticket_id, $show_due ) {
		$company = BST_Companies::name( BST_Companies::for_ticket( $ticket_id ) );
		$who     = '' !== $company ? $company : BST_Tickets::contact_name( $ticket_id );
		$last    = (string) get_post_meta( $ticket_id, BST_Tickets::META_LAST_ACTIVITY, true );
		?>
		<li class="bst-overview-ticket">
			<a class="bst-overview-ticket__link" href="<?php echo esc_url( BST_Tickets::admin_url( $ticket_id ) ); ?>">
				<span class="bst-overview-ticket__ref"><?php echo esc_html( BST_Tickets::ref( $ticket_id ) ); ?></span>
				<span class="bst-overview-ticket__title"><?php echo esc_html( get_the_title( $ticket_id ) ); ?></span>
			</a>
			<span class="bst-overview-ticket__meta">
				<?php if ( '' !== $who ) : ?>
					<span><?php echo esc_html( $who ); ?></span>
				<?php endif; ?>
				<?php echo BST_Admin_UI::badge( BST_Tickets::priority_label( BST_Tickets::priority( $ticket_id ) ), 'urgent' === BST_Tickets::priority( $ticket_id ) ? 'error' : ( 'high' === BST_Tickets::priority( $ticket_id ) ? 'warning' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
				<?php echo BST_Admin_UI::badge( BST_Tickets::status_label( BST_Tickets::status( $ticket_id ), true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
				<?php if ( $show_due ) : ?>
					<?php echo BST_Admin_SLA::due_html( $ticket_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in due_html(). ?>
				<?php elseif ( $last ) : ?>
					<span class="bst-muted">
						<?php
						/* translators: %s: time since, e.g. 3 hours. */
						echo esc_html( sprintf( __( '%s ago', 'bonsai-support-tickets' ), human_time_diff( strtotime( $last . ' UTC' ) ) ) );
						?>
					</span>
				<?php endif; ?>
			</span>
		</li>
		<?php
	}

	/**
	 * A panel of tickets.
	 *
	 * @param string $title    Heading.
	 * @param int[]  $ids      Tickets.
	 * @param string $more_url "View all" link.
	 * @param string $empty    Empty-state text.
	 * @param bool   $show_due Show due times.
	 */
	private static function ticket_panel( $title, array $ids, $more_url, $empty, $show_due = false ) {
		?>
		<section class="bonsai-ui-card bst-overview-panel">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title"><?php echo esc_html( $title ); ?></h2>
				<a href="<?php echo esc_url( $more_url ); ?>"><?php esc_html_e( 'View all', 'bonsai-support-tickets' ); ?><span class="screen-reader-text"> <?php echo esc_html( $title ); ?></span></a>
			</div>
			<?php if ( $ids ) : ?>
				<ul class="bst-overview-list">
					<?php
					foreach ( $ids as $ticket_id ) {
						self::ticket_row( (int) $ticket_id, $show_due );
					}
					?>
				</ul>
			<?php else : ?>
				<p class="bst-muted"><?php echo esc_html( $empty ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Support → Overview.
	 */
	public static function render() {
		if ( ! current_user_can( 'bst_reply_tickets' ) ) {
			return;
		}

		$user    = wp_get_current_user();
		$sla     = BST_SLA::enabled();
		$active  = array(
			'key'     => BST_Tickets::META_STATUS,
			'value'   => BST_Tickets::active_statuses(),
			'compare' => 'IN',
		);
		$mine    = self::ticket_ids( BST_Admin_Tickets::view_meta_query( 'mine' ), BST_Tickets::META_LAST_ACTIVITY, 'DESC' );
		$waiting = self::ticket_ids( BST_Admin_Tickets::view_meta_query( 'unassigned' ), BST_Tickets::META_LAST_ACTIVITY, 'ASC' );
		$due     = $sla
			? self::ticket_ids(
				array(
					$active,
					array(
						'key'     => BST_SLA::META_NEXT_DUE,
						'value'   => BST_SLA::FAR_FUTURE,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
				BST_SLA::META_NEXT_DUE,
				'ASC'
			)
			: array();
		$alerts  = self::ticket_ids(
			array(
				$active,
				array(
					'key'     => BST_Monitoring::META_KEY,
					'compare' => 'EXISTS',
				),
			),
			BST_Tickets::META_LAST_ACTIVITY,
			'DESC'
		);
		?>
		<div class="wrap bonsai-ui bst-overview">
			<?php
			BST_Admin_UI::header(
				/* translators: %s: user's first name or display name. */
				sprintf( __( 'Hello, %s', 'bonsai-support-tickets' ), $user->first_name ? $user->first_name : $user->display_name ),
				__( 'What needs doing across your support desk.', 'bonsai-support-tickets' ),
				array(
					array(
						'label' => __( 'All tickets', 'bonsai-support-tickets' ),
						'url'   => self::list_url( array( 'bst_view' => 'active' ) ),
					),
					array(
						'label' => __( 'New ticket', 'bonsai-support-tickets' ),
						'url'   => admin_url( 'post-new.php?post_type=' . BST_Post_Types::TICKET ),
					),
				)
			);
			?>

			<ul class="bst-overview-stats">
				<?php foreach ( self::stats() as $stat ) : ?>
					<li class="bst-overview-stat<?php echo $stat['count'] && 'neutral' !== $stat['tone'] ? ' bst-overview-stat--' . esc_attr( $stat['tone'] ) : ''; ?>">
						<a href="<?php echo esc_url( $stat['url'] ); ?>">
							<span class="bst-overview-stat__count"><?php echo esc_html( number_format_i18n( $stat['count'] ) ); ?></span>
							<span class="bst-overview-stat__label"><?php echo esc_html( $stat['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="bst-overview-grid">
				<div class="bst-overview-main">
					<?php
					if ( $sla ) {
						self::ticket_panel(
							__( 'Due next', 'bonsai-support-tickets' ),
							$due,
							self::list_url(
								array(
									'bst_view' => 'active',
									'orderby'  => 'bst_due',
									'order'    => 'asc',
								)
							),
							__( 'Nothing due. Nice.', 'bonsai-support-tickets' ),
							true
						);
					}

					self::ticket_panel(
						__( 'My tickets', 'bonsai-support-tickets' ),
						$mine,
						self::list_url( array( 'bst_view' => 'mine' ) ),
						__( 'Nothing assigned to you right now.', 'bonsai-support-tickets' ),
						$sla
					);

					self::ticket_panel(
						__( 'Unassigned, oldest first', 'bonsai-support-tickets' ),
						$waiting,
						self::list_url( array( 'bst_view' => 'unassigned' ) ),
						__( 'Every open ticket has an owner.', 'bonsai-support-tickets' ),
						$sla
					);
					?>
				</div>

				<div class="bst-overview-side">
					<?php self::render_side_panels( $alerts ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sign-ups, uptime alerts, retainers and activity.
	 *
	 * @param int[] $alerts Open monitor tickets.
	 */
	private static function render_side_panels( array $alerts ) {
		if ( current_user_can( 'bst_approve_clients' ) && BST_Settings::get( 'registration_enabled' ) ) {
			$pending = BST_Clients::pending_count();
			if ( $pending ) {
				?>
				<section class="bonsai-ui-card bst-overview-panel">
					<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Sign-ups', 'bonsai-support-tickets' ); ?></h2>
					<p>
						<?php
						/* translators: %s: number of accounts. */
						echo esc_html( sprintf( _n( '%s account is waiting for approval.', '%s accounts are waiting for approval.', $pending, 'bonsai-support-tickets' ), number_format_i18n( $pending ) ) );
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( BST_Admin_Signups::url() ); ?>"><?php esc_html_e( 'Review sign-ups', 'bonsai-support-tickets' ); ?></a></p>
				</section>
				<?php
			}
		}

		if ( $alerts ) {
			self::ticket_panel(
				__( 'Uptime alerts', 'bonsai-support-tickets' ),
				$alerts,
				self::list_url( array( 'bst_view' => 'monitor' ) ),
				''
			);
		}

		if ( BST_Time::enabled() && current_user_can( 'bst_manage_time' ) ) {
			$month = BST_Time::current_month();
			$rows  = array_filter(
				BST_Time::summary( $month ),
				function ( $row ) {
					return in_array( $row['usage']['level'], array( 'warning', 'over' ), true );
				}
			);
			if ( $rows ) {
				?>
				<section class="bonsai-ui-card bst-overview-panel">
					<div class="bonsai-ui-card__head">
						<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Retainers', 'bonsai-support-tickets' ); ?></h2>
						<a href="<?php echo esc_url( BST_Admin_Time::url() ); ?>"><?php esc_html_e( 'View all', 'bonsai-support-tickets' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'retainers', 'bonsai-support-tickets' ); ?></span></a>
					</div>
					<ul class="bst-overview-list">
						<?php foreach ( $rows as $row ) : ?>
							<li class="bst-overview-ticket">
								<a class="bst-overview-ticket__link" href="<?php echo esc_url( BST_Admin_Time::url( array( 'company' => $row['company_id'] ) ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
								<span class="bst-overview-ticket__meta">
									<?php
									echo BST_Admin_UI::badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
										number_format_i18n( (float) $row['usage']['percent'], 0 ) . '%',
										'over' === $row['usage']['level'] ? 'error' : 'warning'
									);
									?>
									<span class="bst-muted">
										<?php
										/* translators: 1: time used, 2: allowance. */
										echo esc_html( sprintf( __( '%1$s of %2$s', 'bonsai-support-tickets' ), BST_Duration::format( $row['usage']['used'] ), BST_Duration::format( $row['usage']['allowance'] ) ) );
										?>
									</span>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
				<?php
			}
		}

		$events = self::recent_activity();
		?>
		<section class="bonsai-ui-card bst-overview-panel">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Latest activity', 'bonsai-support-tickets' ); ?></h2>
			<?php if ( $events ) : ?>
				<ul class="bst-overview-activity">
					<?php foreach ( $events as $event ) : ?>
						<li>
							<a href="<?php echo esc_url( BST_Tickets::admin_url( (int) $event->ticket_id ) ); ?>"><?php echo esc_html( BST_Tickets::ref( (int) $event->ticket_id ) ); ?></a>
							<?php echo esc_html( BST_Activity::describe( $event ) ); ?>
							<span class="bst-muted">
								<?php
								/* translators: %s: time since, e.g. 3 hours. */
								echo esc_html( sprintf( __( '%s ago', 'bonsai-support-tickets' ), human_time_diff( strtotime( $event->created_at . ' UTC' ) ) ) );
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="bst-muted"><?php esc_html_e( 'No activity yet.', 'bonsai-support-tickets' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
