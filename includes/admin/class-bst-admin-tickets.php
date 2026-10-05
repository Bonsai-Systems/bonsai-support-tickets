<?php
/**
 * Admin ticket screens: list table (columns, views, filters, bulk actions)
 * and the ticket screen (conversation, reply / internal note, details,
 * activity).
 *
 * The reply box lives inside core's post form, so "Send reply" submits the
 * whole form and save_ticket() handles the message and the field changes
 * in one go. The message is processed before the field changes so the
 * mailer knows a reply went out before it decides whether to send a
 * separate "solved" email.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin tickets.
 */
class BST_Admin_Tickets {

	const NONCE = 'bst_ticket_nonce';

	/**
	 * Hooks.
	 */
	public static function init() {
		$type = BST_Post_Types::TICKET;

		// List.
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable_columns' ) );
		add_filter( "views_edit-{$type}", array( __CLASS__, 'views' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", array( __CLASS__, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$type}", array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_count' ), 99 );

		// Single.
		add_action( "add_meta_boxes_{$type}", array( __CLASS__, 'meta_boxes' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'default_title' ), 10, 2 );
		add_action( "save_post_{$type}", array( __CLASS__, 'save_ticket' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'updated_messages' ) );

		add_action( 'admin_notices', array( 'BST_Admin_UI', 'print_flashes' ) );
	}

	/*
	|----------------------------------------------------------------------
	| List table
	|----------------------------------------------------------------------
	*/

	/**
	 * Columns.
	 *
	 * @param array $columns Core columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'                                      => $columns['cb'],
			'bst_ref'                                 => __( 'Ref', 'bonsai-support-tickets' ),
			'title'                                   => __( 'Subject', 'bonsai-support-tickets' ),
			'bst_client'                              => __( 'Client', 'bonsai-support-tickets' ),
			'bst_status'                              => __( 'Status', 'bonsai-support-tickets' ),
			'bst_priority'                            => __( 'Priority', 'bonsai-support-tickets' ),
			'bst_assignee'                            => __( 'Assignee', 'bonsai-support-tickets' ),
			'taxonomy-' . BST_Post_Types::TICKET_TYPE => __( 'Type', 'bonsai-support-tickets' ),
			'bst_activity'                            => __( 'Last activity', 'bonsai-support-tickets' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Ticket ID.
	 */
	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'bst_ref':
				echo '<strong class="bst-ref">' . esc_html( BST_Tickets::ref( $post_id ) ) . '</strong>';
				break;

			case 'bst_client':
				$company = BST_Companies::for_ticket( $post_id );
				if ( $company ) {
					printf(
						'<a href="%1$s"><strong>%2$s</strong></a><br>',
						esc_url( BST_Admin_Companies::tickets_url( $company ) ),
						esc_html( BST_Companies::name( $company ) )
					);
				}
				echo esc_html( BST_Tickets::contact_name( $post_id ) );
				if ( BST_Tickets::is_unverified( $post_id ) ) {
					echo ' ' . BST_Admin_UI::badge( __( 'Unverified', 'bonsai-support-tickets' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				}
				break;

			case 'bst_status':
				$status   = BST_Tickets::status( $post_id );
				$statuses = BST_Tickets::statuses();
				echo BST_Admin_UI::badge( BST_Tickets::status_label( $status, true ), $statuses[ $status ]['variant'] ?? 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				break;

			case 'bst_priority':
				$priority   = BST_Tickets::priority( $post_id );
				$priorities = BST_Tickets::priorities();
				echo BST_Admin_UI::badge( BST_Tickets::priority_label( $priority ), $priorities[ $priority ]['variant'] ?? 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				break;

			case 'bst_assignee':
				$assignee = BST_Tickets::assignee( $post_id );
				echo $assignee ? esc_html( get_the_author_meta( 'display_name', $assignee ) ) : '<span class="bst-muted">' . esc_html__( 'Unassigned', 'bonsai-support-tickets' ) . '</span>';
				break;

			case 'bst_activity':
				$last = get_post_meta( $post_id, BST_Tickets::META_LAST_ACTIVITY, true );
				if ( $last ) {
					$timestamp = strtotime( $last . ' UTC' );
					printf(
						'<span title="%1$s">%2$s</span>',
						esc_attr( bst_format_datetime( $last ) ),
						/* translators: %s: human time difference, e.g. "2 hours". */
						esc_html( sprintf( __( '%s ago', 'bonsai-support-tickets' ), human_time_diff( $timestamp ) ) )
					);
					if ( 'agent' === get_post_meta( $post_id, BST_Tickets::META_WAITING_ON, true ) && in_array( BST_Tickets::status( $post_id ), BST_Tickets::active_statuses(), true ) ) {
						echo '<br><span class="bst-waiting">' . esc_html__( 'Waiting on us', 'bonsai-support-tickets' ) . '</span>';
					}
				}
				break;
		}
	}

	/**
	 * Sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['bst_ref']      = 'ID';
		$columns['bst_activity'] = 'bst_activity';
		return $columns;
	}

	/**
	 * Views above the list.
	 *
	 * @param array $views Core views.
	 * @return array
	 */
	public static function views( $views ) {
		$current = isset( $_GET['bst_view'] ) ? sanitize_key( wp_unslash( $_GET['bst_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view filter.
		$base    = admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET );

		$ours = array(
			'active'     => __( 'Active', 'bonsai-support-tickets' ),
			'mine'       => __( 'Mine', 'bonsai-support-tickets' ),
			'unassigned' => __( 'Unassigned', 'bonsai-support-tickets' ),
			'unverified' => __( 'Unverified', 'bonsai-support-tickets' ),
			'resolved'   => __( 'Solved & closed', 'bonsai-support-tickets' ),
		);

		$new = array();
		if ( isset( $views['all'] ) ) {
			$new['all'] = '' === $current ? $views['all'] : str_replace( 'class="current"', '', $views['all'] );
		}

		foreach ( $ours as $key => $label ) {
			$count     = self::count_view( $key );
			$new[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'bst_view', $key, $base ) ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		// Keep core's Trash (and Drafts, if any). "Published" means nothing for tickets.
		unset( $views['all'], $views['publish'] );
		if ( $current ) {
			foreach ( $views as $key => $view ) {
				$views[ $key ] = str_replace( 'class="current"', '', $view );
			}
		}

		return array_merge( $new, $views );
	}

	/**
	 * Meta query for a view.
	 *
	 * @param string $view View key.
	 * @return array|null
	 */
	private static function view_meta_query( $view ) {
		switch ( $view ) {
			case 'active':
				return array(
					array(
						'key'     => BST_Tickets::META_STATUS,
						'value'   => BST_Tickets::active_statuses(),
						'compare' => 'IN',
					),
				);
			case 'mine':
				return array(
					'relation' => 'AND',
					array(
						'key'   => BST_Tickets::META_ASSIGNEE,
						'value' => get_current_user_id(),
						'type'  => 'NUMERIC',
					),
					array(
						'key'     => BST_Tickets::META_STATUS,
						'value'   => BST_Tickets::active_statuses(),
						'compare' => 'IN',
					),
				);
			case 'unassigned':
				return array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'   => BST_Tickets::META_ASSIGNEE,
							'value' => 0,
							'type'  => 'NUMERIC',
						),
						array(
							'key'     => BST_Tickets::META_ASSIGNEE,
							'compare' => 'NOT EXISTS',
						),
					),
					array(
						'key'     => BST_Tickets::META_STATUS,
						'value'   => BST_Tickets::active_statuses(),
						'compare' => 'IN',
					),
				);
			case 'unverified':
				return array(
					array(
						'key'   => BST_Tickets::META_UNVERIFIED,
						'value' => '1',
					),
				);
			case 'resolved':
				return array(
					array(
						'key'     => BST_Tickets::META_STATUS,
						'value'   => array( 'solved', 'closed' ),
						'compare' => 'IN',
					),
				);
		}
		return null;
	}

	/**
	 * Number of tickets in a view.
	 *
	 * @param string $view View key.
	 * @return int
	 */
	private static function count_view( $view ) {
		$query = new WP_Query(
			array(
				'post_type'              => BST_Post_Types::TICKET,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => self::view_meta_query( $view ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Filter dropdowns.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function filters( $post_type ) {
		if ( BST_Post_Types::TICKET !== $post_type ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$status   = isset( $_GET['bst_status'] ) ? sanitize_key( wp_unslash( $_GET['bst_status'] ) ) : '';
		$priority = isset( $_GET['bst_priority'] ) ? sanitize_key( wp_unslash( $_GET['bst_priority'] ) ) : '';
		$assignee = isset( $_GET['bst_assignee'] ) ? sanitize_key( wp_unslash( $_GET['bst_assignee'] ) ) : '';
		$type     = isset( $_GET['bst_type'] ) ? absint( $_GET['bst_type'] ) : 0;
		$company  = isset( $_GET['bst_company'] ) ? absint( $_GET['bst_company'] ) : 0;
		$view     = isset( $_GET['bst_view'] ) ? sanitize_key( wp_unslash( $_GET['bst_view'] ) ) : '';
		// phpcs:enable

		if ( $view ) {
			echo '<input type="hidden" name="bst_view" value="' . esc_attr( $view ) . '">';
		}
		?>
		<label class="screen-reader-text" for="bst-filter-status"><?php esc_html_e( 'Filter by status', 'bonsai-support-tickets' ); ?></label>
		<select name="bst_status" id="bst-filter-status">
			<option value=""><?php esc_html_e( 'All statuses', 'bonsai-support-tickets' ); ?></option>
			<?php foreach ( BST_Tickets::statuses() as $slug => $data ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( BST_Tickets::status_label( $slug, true ) ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="bst-filter-priority"><?php esc_html_e( 'Filter by priority', 'bonsai-support-tickets' ); ?></label>
		<select name="bst_priority" id="bst-filter-priority">
			<option value=""><?php esc_html_e( 'All priorities', 'bonsai-support-tickets' ); ?></option>
			<?php foreach ( BST_Tickets::priorities() as $slug => $data ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $priority, $slug ); ?>><?php echo esc_html( $data['label'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="bst-filter-assignee"><?php esc_html_e( 'Filter by assignee', 'bonsai-support-tickets' ); ?></label>
		<select name="bst_assignee" id="bst-filter-assignee">
			<option value=""><?php esc_html_e( 'Anyone', 'bonsai-support-tickets' ); ?></option>
			<?php foreach ( BST_Tickets::agents() as $agent ) : ?>
				<option value="<?php echo esc_attr( $agent->ID ); ?>" <?php selected( $assignee, (string) $agent->ID ); ?>><?php echo esc_html( $agent->display_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="bst-filter-company"><?php esc_html_e( 'Filter by client', 'bonsai-support-tickets' ); ?></label>
		<select name="bst_company" id="bst-filter-company">
			<option value="0"><?php esc_html_e( 'All clients', 'bonsai-support-tickets' ); ?></option>
			<?php foreach ( BST_Companies::all() as $option ) : ?>
				<option value="<?php echo esc_attr( $option->ID ); ?>" <?php selected( $company, $option->ID ); ?>><?php echo esc_html( $option->post_title ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="bst-filter-type"><?php esc_html_e( 'Filter by type', 'bonsai-support-tickets' ); ?></label>
		<select name="bst_type" id="bst-filter-type">
			<option value="0"><?php esc_html_e( 'All types', 'bonsai-support-tickets' ); ?></option>
			<?php foreach ( bst_get_ticket_types() as $term ) : ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $type, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply views, filters, ref search and default ordering to the list query.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow || BST_Post_Types::TICKET !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$meta_query = array( 'relation' => 'AND' );

		$view = isset( $_GET['bst_view'] ) ? sanitize_key( wp_unslash( $_GET['bst_view'] ) ) : '';
		$vq   = self::view_meta_query( $view );
		if ( $vq ) {
			$meta_query[] = $vq;
		}

		$status = isset( $_GET['bst_status'] ) ? sanitize_key( wp_unslash( $_GET['bst_status'] ) ) : '';
		if ( $status && array_key_exists( $status, BST_Tickets::statuses() ) ) {
			$meta_query[] = array(
				'key'   => BST_Tickets::META_STATUS,
				'value' => $status,
			);
		}

		$priority = isset( $_GET['bst_priority'] ) ? sanitize_key( wp_unslash( $_GET['bst_priority'] ) ) : '';
		if ( $priority && array_key_exists( $priority, BST_Tickets::priorities() ) ) {
			$meta_query[] = array(
				'key'   => BST_Tickets::META_PRIORITY,
				'value' => $priority,
			);
		}

		$assignee = isset( $_GET['bst_assignee'] ) ? absint( $_GET['bst_assignee'] ) : 0;
		if ( $assignee ) {
			$meta_query[] = array(
				'key'   => BST_Tickets::META_ASSIGNEE,
				'value' => $assignee,
				'type'  => 'NUMERIC',
			);
		}

		$company = isset( $_GET['bst_company'] ) ? absint( $_GET['bst_company'] ) : 0;
		if ( $company ) {
			$meta_query[] = array(
				'key'   => BST_Companies::META_TICKET,
				'value' => $company,
				'type'  => 'NUMERIC',
			);
		}

		$type = isset( $_GET['bst_type'] ) ? absint( $_GET['bst_type'] ) : 0;
		if ( $type ) {
			$query->set(
				'tax_query',
				array(
					array(
						'taxonomy' => BST_Post_Types::TICKET_TYPE,
						'terms'    => $type,
					),
				)
			);
		}
		// phpcs:enable

		// Searching for a reference (SUP-1042) jumps straight to that ticket.
		$search = (string) $query->get( 's' );
		if ( preg_match( '/^\s*[A-Za-z0-9]+-\d+\s*$/', $search ) ) {
			$meta_query[] = array(
				'key'   => BST_Tickets::META_REF,
				'value' => strtoupper( trim( $search ) ),
			);
			$query->set( 's', '' );
		}

		if ( count( $meta_query ) > 1 ) {
			$query->set( 'meta_query', $meta_query );
		}

		$orderby = $query->get( 'orderby' );
		if ( 'bst_activity' === $orderby || '' === $orderby ) {
			$query->set( 'meta_key', BST_Tickets::META_LAST_ACTIVITY );
			$query->set( 'orderby', 'meta_value' );
			if ( '' === $orderby ) {
				$query->set( 'order', 'DESC' );
			}
		}
	}

	/**
	 * Row actions: drop Quick Edit (it would bypass the logging in save_ticket()).
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( BST_Post_Types::TICKET === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		$actions['bst_assign_me'] = __( 'Assign to me', 'bonsai-support-tickets' );
		$actions['bst_solved']    = __( 'Mark as solved', 'bonsai-support-tickets' );
		$actions['bst_closed']    = __( 'Close', 'bonsai-support-tickets' );
		return $actions;
	}

	/**
	 * Run a bulk action. Core has already checked the bulk-posts nonce.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Ticket IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'bst_assign_me', 'bst_solved', 'bst_closed' ), true ) ) {
			return $redirect;
		}

		$done = 0;
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( 'bst_assign_me' === $action && current_user_can( 'bst_assign_tickets' ) ) {
				$done += (int) BST_Tickets::set_assignee( $id, get_current_user_id() );
			} elseif ( 'bst_solved' === $action ) {
				$done += (int) BST_Tickets::set_status( $id, 'solved' );
			} elseif ( 'bst_closed' === $action ) {
				$done += (int) BST_Tickets::set_status( $id, 'closed' );
			}
		}

		/* translators: %d: number of tickets. */
		BST_Admin_UI::flash( sprintf( _n( '%d ticket updated.', '%d tickets updated.', $done, 'bonsai-support-tickets' ), $done ) );
		return $redirect;
	}

	/**
	 * Count bubble on the Support menu: active tickets waiting on us.
	 */
	public static function menu_count() {
		global $menu;
		if ( ! current_user_can( 'bst_view_all_tickets' ) || ! is_array( $menu ) ) {
			return;
		}

		$count = get_transient( 'bst_menu_count' );
		if ( false === $count ) {
			$query = new WP_Query(
				array(
					'post_type'              => BST_Post_Types::TICKET,
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => BST_Tickets::META_WAITING_ON,
							'value' => 'agent',
						),
						array(
							'key'     => BST_Tickets::META_STATUS,
							'value'   => array( 'new', 'open' ),
							'compare' => 'IN',
						),
					),
				)
			);
			$count = (int) $query->found_posts;
			set_transient( 'bst_menu_count', $count, MINUTE_IN_SECONDS );
		}

		if ( ! $count ) {
			return;
		}

		foreach ( $menu as $index => $item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . BST_Post_Types::TICKET === $item[2] ) {
				$menu[ $index ][0] .= sprintf( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standard way to add a menu count.
					' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
					(int) $count,
					/* translators: %d: number of tickets. */
					esc_html( sprintf( _n( '%d ticket waiting on us', '%d tickets waiting on us', (int) $count, 'bonsai-support-tickets' ), (int) $count ) )
				);
				break;
			}
		}
	}

	/*
	|----------------------------------------------------------------------
	| Ticket screen
	|----------------------------------------------------------------------
	*/

	/**
	 * Meta boxes.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', BST_Post_Types::TICKET, 'side' );
		remove_meta_box( 'slugdiv', BST_Post_Types::TICKET, 'normal' );

		add_meta_box( 'bst_details', __( 'Details', 'bonsai-support-tickets' ), array( __CLASS__, 'box_details' ), BST_Post_Types::TICKET, 'side', 'high' );
		add_meta_box( 'bst_reply', __( 'Reply', 'bonsai-support-tickets' ), array( __CLASS__, 'box_reply' ), BST_Post_Types::TICKET, 'normal', 'high' );
		add_meta_box( 'bst_thread', __( 'Conversation', 'bonsai-support-tickets' ), array( __CLASS__, 'box_thread' ), BST_Post_Types::TICKET, 'normal', 'default' );

		if ( 'auto-draft' !== $post->post_status ) {
			add_meta_box( 'bst_activity', __( 'Activity', 'bonsai-support-tickets' ), array( __CLASS__, 'box_activity' ), BST_Post_Types::TICKET, 'side', 'low' );
		}
	}

	/**
	 * Allow file uploads in the post form.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function form_enctype( $post ) {
		if ( BST_Post_Types::TICKET === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Placeholder for the subject field.
	 *
	 * @param string  $text Placeholder.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		return BST_Post_Types::TICKET === $post->post_type ? __( 'Subject', 'bonsai-support-tickets' ) : $text;
	}

	/**
	 * Ref, client and site under the subject.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function after_title( $post ) {
		if ( BST_Post_Types::TICKET !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		$site = get_post_meta( $post->ID, BST_Tickets::META_SITE_URL, true );
		?>
		<div class="bst-ticket-meta">
			<strong class="bst-ref"><?php echo esc_html( BST_Tickets::ref( $post->ID ) ); ?></strong>
			<?php $company = BST_Companies::for_ticket( $post->ID ); ?>
			<?php if ( $company ) : ?>
				<?php if ( current_user_can( 'edit_post', $company ) ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $company ) ); ?>"><strong><?php echo esc_html( BST_Companies::name( $company ) ); ?></strong></a>
				<?php else : ?>
					<strong><?php echo esc_html( BST_Companies::name( $company ) ); ?></strong>
				<?php endif; ?>
			<?php endif; ?>
			<?php $bst_contact_email = BST_Tickets::contact_email( $post->ID ); ?>
			<span><?php echo esc_html( BST_Tickets::contact_name( $post->ID ) ); ?><?php echo '' !== $bst_contact_email ? ' &lt;' . esc_html( $bst_contact_email ) . '&gt;' : ''; ?></span>
			<?php if ( $site ) : ?>
				<a href="<?php echo esc_url( $site ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'bonsai-support-tickets' ); ?></span></a>
			<?php endif; ?>
			<span><?php echo esc_html( bst_format_datetime( get_post_field( 'post_date_gmt', $post->ID ) ) ); ?></span>
		</div>
		<?php if ( BST_Tickets::is_unverified( $post->ID ) ) : ?>
			<div class="notice notice-warning inline bst-unverified-notice">
				<p><?php esc_html_e( 'This came in by email from an address that is not linked to a client account. No automatic emails go to the sender until you choose a client under Details. You can still reply to them manually.', 'bonsai-support-tickets' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Name of the submit button: "publish" for new tickets, "save" afterwards.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function submit_name( $post ) {
		return 'auto-draft' === $post->post_status ? 'publish' : 'save';
	}

	/**
	 * Details box (replaces core's Publish box).
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function box_details( $post ) {
		wp_nonce_field( 'bst_save_ticket_' . $post->ID, self::NONCE );

		$is_new   = 'auto-draft' === $post->post_status;
		$status   = $is_new ? 'new' : BST_Tickets::status( $post->ID );
		$priority = $is_new ? 'normal' : BST_Tickets::priority( $post->ID );
		$assignee = $is_new ? get_current_user_id() : BST_Tickets::assignee( $post->ID );
		$client   = $is_new ? 0 : BST_Tickets::client_id( $post->ID );
		$company  = $is_new ? 0 : BST_Companies::for_ticket( $post->ID );
		$terms    = wp_get_object_terms( $post->ID, BST_Post_Types::TICKET_TYPE, array( 'fields' => 'ids' ) );
		$type     = ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0;
		$site     = (string) get_post_meta( $post->ID, BST_Tickets::META_SITE_URL, true );

		// The status the form was loaded with; save only applies the select if it changed.
		?>
		<input type="hidden" name="bst_original_status" value="<?php echo esc_attr( $status ); ?>">

		<div class="bst-field">
			<label for="bst-status"><?php esc_html_e( 'Status', 'bonsai-support-tickets' ); ?></label>
			<select name="bst_status" id="bst-status" class="widefat">
				<?php foreach ( BST_Tickets::statuses() as $slug => $data ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( BST_Tickets::status_label( $slug, true ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="bst-field">
			<label for="bst-priority"><?php esc_html_e( 'Priority', 'bonsai-support-tickets' ); ?></label>
			<select name="bst_priority" id="bst-priority" class="widefat">
				<?php foreach ( BST_Tickets::priorities() as $slug => $data ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $priority, $slug ); ?>><?php echo esc_html( $data['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="bst-field">
			<label for="bst-assignee"><?php esc_html_e( 'Assignee', 'bonsai-support-tickets' ); ?></label>
			<select name="bst_assignee" id="bst-assignee" class="widefat" <?php disabled( ! current_user_can( 'bst_assign_tickets' ) ); ?>>
				<option value="0"><?php esc_html_e( 'Unassigned', 'bonsai-support-tickets' ); ?></option>
				<?php foreach ( BST_Tickets::agents() as $agent ) : ?>
					<option value="<?php echo esc_attr( $agent->ID ); ?>" <?php selected( $assignee, $agent->ID ); ?>><?php echo esc_html( $agent->display_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="bst-field">
			<label for="bst-type"><?php esc_html_e( 'Type', 'bonsai-support-tickets' ); ?></label>
			<select name="bst_type" id="bst-type" class="widefat">
				<option value="0"><?php esc_html_e( 'No type', 'bonsai-support-tickets' ); ?></option>
				<?php foreach ( bst_get_ticket_types() as $term ) : ?>
					<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $type, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<input type="hidden" name="bst_original_company" value="<?php echo esc_attr( $company ); ?>">
		<div class="bst-field">
			<label for="bst-company"><?php esc_html_e( 'Client', 'bonsai-support-tickets' ); ?></label>
			<select name="bst_company" id="bst-company" class="widefat">
				<option value="0"><?php esc_html_e( 'From the contact', 'bonsai-support-tickets' ); ?></option>
				<?php foreach ( BST_Companies::all() as $option ) : ?>
					<option value="<?php echo esc_attr( $option->ID ); ?>" <?php selected( $company, $option->ID ); ?>><?php echo esc_html( $option->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'The business this ticket is for. Changing the contact moves it to their client automatically.', 'bonsai-support-tickets' ); ?></p>
		</div>

		<div class="bst-field">
			<label for="bst-client"><?php esc_html_e( 'Contact', 'bonsai-support-tickets' ); ?></label>
			<select name="bst_client" id="bst-client" class="widefat">
				<option value="0"><?php echo $is_new ? esc_html__( 'Choose a person', 'bonsai-support-tickets' ) : esc_html__( 'Not linked', 'bonsai-support-tickets' ); ?></option>
				<?php
				// Grouped by client so a business's people sit together; people with no client last.
				$groups = array();
				foreach ( BST_Tickets::clients() as $user ) {
					$groups[ BST_Clients::client_name( $user->ID ) ][] = $user;
				}
				uksort(
					$groups,
					function ( $a, $b ) {
						if ( '' === (string) $a || '' === (string) $b ) {
							return '' === (string) $a ? 1 : -1;
						}
						return strcasecmp( $a, $b );
					}
				);
				?>
				<?php foreach ( $groups as $group => $users ) : ?>
					<optgroup label="<?php echo esc_attr( '' !== (string) $group ? $group : __( 'No client', 'bonsai-support-tickets' ) ); ?>">
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $client, $user->ID ); ?>>
								<?php
								$label = $user->display_name . ' (' . $user->user_email . ')';
								if ( BST_Clients::is_pending( $user->ID ) ) {
									$label .= ' — ' . __( 'awaiting approval', 'bonsai-support-tickets' );
								}
								echo esc_html( $label );
								?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
			<?php if ( BST_Tickets::is_unverified( $post->ID ) ) : ?>
				<p class="description">
					<?php
					/* translators: %s: email address. */
					echo esc_html( sprintf( __( 'Sent from %s', 'bonsai-support-tickets' ), BST_Tickets::contact_email( $post->ID ) ) );
					?>
				</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'The person we email. Clients register on the support site (approve them under Support → Sign-ups), or add them under Users with the Support Client role.', 'bonsai-support-tickets' ); ?></p>
		</div>

		<div class="bst-field">
			<label for="bst-site-url"><?php esc_html_e( 'Website / page', 'bonsai-support-tickets' ); ?></label>
			<input type="url" name="bst_site_url" id="bst-site-url" class="widefat" value="<?php echo esc_attr( $site ); ?>" placeholder="https://">
		</div>

		<div class="bst-details-actions">
			<?php if ( ! $is_new && current_user_can( 'delete_post', $post->ID ) ) : ?>
				<a class="submitdelete" href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Move to bin', 'bonsai-support-tickets' ); ?></a>
			<?php endif; ?>
			<button type="submit" name="<?php echo esc_attr( self::submit_name( $post ) ); ?>" value="1" class="button button-primary"><?php echo $is_new ? esc_html__( 'Create ticket', 'bonsai-support-tickets' ) : esc_html__( 'Save changes', 'bonsai-support-tickets' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Reply / internal note box.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function box_reply( $post ) {
		$is_new   = 'auto-draft' === $post->post_status;
		$can_note = current_user_can( 'bst_add_internal_notes' );
		$draft    = get_transient( 'bst_reply_draft_' . get_current_user_id() . '_' . $post->ID );
		if ( $draft ) {
			delete_transient( 'bst_reply_draft_' . get_current_user_id() . '_' . $post->ID );
		}
		?>
		<div class="bst-reply-box" data-mode="external">
			<?php if ( $can_note && ! $is_new ) : ?>
				<fieldset class="bst-mode">
					<legend class="screen-reader-text"><?php esc_html_e( 'Message type', 'bonsai-support-tickets' ); ?></legend>
					<label class="bst-mode__option">
						<input type="radio" name="bst_visibility" value="external" checked>
						<span><?php esc_html_e( 'Reply to client', 'bonsai-support-tickets' ); ?></span>
					</label>
					<label class="bst-mode__option bst-mode__option--internal">
						<input type="radio" name="bst_visibility" value="internal">
						<span><?php esc_html_e( 'Internal note', 'bonsai-support-tickets' ); ?></span>
					</label>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="bst_visibility" value="external">
			<?php endif; ?>

			<p class="bst-mode-hint bst-mode-hint--external">
				<?php
				echo $is_new
					? esc_html__( 'The first message on the ticket. The client is emailed a copy.', 'bonsai-support-tickets' )
					/* translators: %s: email address. */
					: esc_html( sprintf( __( 'Emailed to %s.', 'bonsai-support-tickets' ), BST_Tickets::contact_email( $post->ID ) ) );
				?>
			</p>
			<p class="bst-mode-hint bst-mode-hint--internal"><?php esc_html_e( 'Only the support team can see internal notes. The client is not emailed.', 'bonsai-support-tickets' ); ?></p>

			<?php BST_Admin_Canned::picker( $post ); ?>

			<label class="screen-reader-text" for="bst-message"><?php esc_html_e( 'Message', 'bonsai-support-tickets' ); ?></label>
			<textarea name="bst_message" id="bst-message" rows="8" class="widefat bst-reply-box__text"><?php echo esc_textarea( is_string( $draft ) ? $draft : '' ); ?></textarea>

			<div class="bst-reply-box__row">
				<div>
					<label for="bst-attachments"><?php esc_html_e( 'Attachments', 'bonsai-support-tickets' ); ?></label>
					<input type="file" name="bst_attachments[]" id="bst-attachments" multiple accept="<?php echo esc_attr( BST_Attachments::accept_attribute() ); ?>">
				</div>
				<?php if ( ! $is_new ) : ?>
					<div>
						<label for="bst-reply-status"><?php esc_html_e( 'Then set status to', 'bonsai-support-tickets' ); ?></label>
						<select name="bst_reply_status" id="bst-reply-status">
							<option value=""><?php esc_html_e( 'Automatic', 'bonsai-support-tickets' ); ?></option>
							<?php foreach ( BST_Tickets::statuses() as $slug => $data ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( BST_Tickets::status_label( $slug, true ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
			</div>

			<div class="bst-reply-box__actions">
				<button type="submit" name="<?php echo esc_attr( self::submit_name( $post ) ); ?>" value="1" class="button button-primary bst-send"><?php echo $is_new ? esc_html__( 'Create ticket', 'bonsai-support-tickets' ) : esc_html__( 'Send reply', 'bonsai-support-tickets' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Conversation, newest first, internal notes included.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function box_thread( $post ) {
		$messages = array_reverse( BST_Messages::for_ticket( $post->ID, true ) );
		if ( ! $messages ) {
			echo '<p class="bst-muted">' . esc_html__( 'No messages yet.', 'bonsai-support-tickets' ) . '</p>';
			return;
		}

		$files   = BST_Attachments::for_messages( wp_list_pluck( $messages, 'id' ) );
		$sources = array(
			'email'   => __( 'by email', 'bonsai-support-tickets' ),
			'web'     => __( 'via the portal', 'bonsai-support-tickets' ),
			'admin'   => '',
			'monitor' => __( 'from uptime monitoring', 'bonsai-support-tickets' ),
		);
		?>
		<ol class="bst-admin-thread">
			<?php foreach ( $messages as $message ) : ?>
				<?php
				$internal = BST_Messages::INTERNAL === $message->visibility;
				$is_team  = BST_Tickets::is_agent( (int) $message->user_id );
				$classes  = 'bst-admin-message' . ( $internal ? ' bst-admin-message--internal' : '' ) . ( $is_team ? ' bst-admin-message--team' : '' );
				?>
				<li class="<?php echo esc_attr( $classes ); ?>">
					<div class="bst-admin-message__meta">
						<strong><?php echo esc_html( $message->author_name ? $message->author_name : $message->author_email ); ?></strong>
						<?php if ( $internal ) : ?>
							<?php echo BST_Admin_UI::badge( __( 'Internal note', 'bonsai-support-tickets' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
						<?php elseif ( ! $is_team ) : ?>
							<span class="bst-muted"><?php echo esc_html( $message->author_email ); ?></span>
						<?php endif; ?>
						<span class="bst-muted bst-admin-message__time">
							<?php echo esc_html( bst_format_datetime( $message->created_at ) ); ?>
							<?php echo esc_html( $sources[ $message->source ] ?? '' ); ?>
						</span>
					</div>
					<div class="bst-admin-message__body">
						<?php echo wp_kses( $message->body, BST_Messages::allowed_html() ); ?>
					</div>
					<?php if ( ! empty( $files[ (int) $message->id ] ) ) : ?>
						<ul class="bst-admin-files">
							<?php foreach ( $files[ (int) $message->id ] as $file ) : ?>
								<li><span class="dashicons dashicons-paperclip" aria-hidden="true"></span> <a href="<?php echo esc_url( BST_Attachments::url( $file->id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $file->file_name ); ?></a> <span class="bst-muted">(<?php echo esc_html( bst_format_size( $file->file_size ) ); ?>)</span></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Activity log.
	 *
	 * @param WP_Post $post Ticket.
	 */
	public static function box_activity( $post ) {
		$events = BST_Activity::for_ticket( $post->ID );
		if ( ! $events ) {
			echo '<p class="bst-muted">' . esc_html__( 'Nothing yet.', 'bonsai-support-tickets' ) . '</p>';
			return;
		}
		echo '<ul class="bst-activity">';
		foreach ( $events as $event ) {
			printf(
				'<li>%1$s<br><span class="bst-muted">%2$s</span></li>',
				esc_html( BST_Activity::describe( $event ) ),
				esc_html( bst_format_datetime( $event->created_at ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Tickets saved with no subject get a placeholder rather than "(no title)".
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function default_title( $data, $postarr ) {
		if ( BST_Post_Types::TICKET === $data['post_type'] && '' === trim( $data['post_title'] ) && 'auto-draft' !== $data['post_status'] ) {
			$data['post_title'] = __( '(no subject)', 'bonsai-support-tickets' );
		}
		return $data;
	}

	/**
	 * Save handler for the ticket screen.
	 *
	 * @param int     $post_id Ticket ID.
	 * @param WP_Post $post    Ticket.
	 */
	public static function save_ticket( $post_id, $post ) {
		// Only our form. Programmatic inserts (BST_Tickets::create) have no nonce and are skipped.
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'bst_save_ticket_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'bst_view_all_tickets' ) ) {
			return;
		}

		try {
			$is_new = ! metadata_exists( 'post', $post_id, BST_Tickets::META_STATUS );

			// New ticket created in wp-admin (e.g. from a phone call).
			if ( $is_new ) {
				BST_Tickets::ensure_ref( $post_id );
				update_post_meta( $post_id, BST_Tickets::META_STATUS, 'new' );
				update_post_meta( $post_id, BST_Tickets::META_PRIORITY, 'normal' );
				update_post_meta( $post_id, BST_Tickets::META_ASSIGNEE, 0 );
				update_post_meta( $post_id, BST_Tickets::META_WAITING_ON, 'agent' );
				update_post_meta( $post_id, BST_Tickets::META_LAST_ACTIVITY, current_time( 'mysql', true ) );
				BST_Activity::log( $post_id, 'created', '', BST_Tickets::ref( $post_id ) );
			}

			// Client first, so a reply in the same save goes to the right person.
			$client = absint( $_POST['bst_client'] ?? 0 );
			if ( $client ) {
				BST_Tickets::set_client( $post_id, $client ); // Also moves the ticket to the contact's client.
			}

			// An explicit Client choice wins over the contact's, but only if the agent changed it.
			$company          = absint( $_POST['bst_company'] ?? 0 );
			$original_company = absint( $_POST['bst_original_company'] ?? 0 );
			if ( $company && $company !== $original_company ) {
				BST_Tickets::set_company( $post_id, $company );
			} elseif ( ! $company && $original_company ) {
				// Switched back to "From the contact".
				BST_Tickets::set_company( $post_id, BST_Companies::for_user( BST_Tickets::client_id( $post_id ) ) );
			} elseif ( ! BST_Companies::for_ticket( $post_id ) ) {
				// New ticket, or no client yet: follow the contact.
				BST_Companies::stamp_ticket( $post_id, BST_Companies::for_user( BST_Tickets::client_id( $post_id ) ) );
			}

			// Message before field changes — see class docblock.
			self::save_message( $post_id );

			$status   = sanitize_key( wp_unslash( $_POST['bst_status'] ?? '' ) );
			$original = sanitize_key( wp_unslash( $_POST['bst_original_status'] ?? '' ) );
			if ( $status && $status !== $original ) {
				BST_Tickets::set_status( $post_id, $status );
			}

			BST_Tickets::set_priority( $post_id, sanitize_key( wp_unslash( $_POST['bst_priority'] ?? 'normal' ) ) );

			if ( isset( $_POST['bst_assignee'] ) && current_user_can( 'bst_assign_tickets' ) ) {
				BST_Tickets::set_assignee( $post_id, absint( $_POST['bst_assignee'] ) );
			}

			$type = absint( $_POST['bst_type'] ?? 0 );
			wp_set_object_terms( $post_id, $type ? array( $type ) : array(), BST_Post_Types::TICKET_TYPE );

			BST_Tickets::set_site_url( $post_id, esc_url_raw( wp_unslash( $_POST['bst_site_url'] ?? '' ) ) );

			delete_transient( 'bst_menu_count' );
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': ticket save failed for ' . $post_id . ': ' . $e->getMessage() );
			BST_Admin_UI::flash( __( 'Something went wrong saving the ticket. Please check it and try again.', 'bonsai-support-tickets' ), 'error' );
		}
	}

	/**
	 * Save the reply/note from the reply box, if there is one.
	 *
	 * @param int $post_id Ticket ID.
	 */
	private static function save_message( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save_ticket().
		$body    = sanitize_textarea_field( wp_unslash( $_POST['bst_message'] ?? '' ) );
		$uploads = BST_Attachments::normalise_files( $_FILES['bst_attachments'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.

		if ( '' === trim( $body ) && ! $uploads ) {
			return;
		}

		$check = BST_Attachments::validate_uploads( $uploads );
		if ( is_wp_error( $check ) ) {
			// Keep what they typed so it isn't lost.
			set_transient( 'bst_reply_draft_' . get_current_user_id() . '_' . $post_id, $body, 10 * MINUTE_IN_SECONDS );
			BST_Admin_UI::flash( $check->get_error_message() . ' ' . __( 'Your message was not sent.', 'bonsai-support-tickets' ), 'error' );
			return;
		}

		$visibility = sanitize_key( wp_unslash( $_POST['bst_visibility'] ?? 'external' ) );
		if ( BST_Messages::INTERNAL === $visibility && ! current_user_can( 'bst_add_internal_notes' ) ) {
			$visibility = BST_Messages::EXTERNAL;
		}

		$reply_status = sanitize_key( wp_unslash( $_POST['bst_reply_status'] ?? '' ) );
		// phpcs:enable

		$result = BST_Tickets::reply(
			$post_id,
			array(
				'user_id'    => get_current_user_id(),
				'visibility' => $visibility,
				'body'       => BST_Messages::text_to_html( $body ),
				'source'     => 'admin',
				'status'     => array_key_exists( $reply_status, BST_Tickets::statuses() ) ? $reply_status : '',
				'uploads'    => $uploads,
			)
		);

		if ( is_wp_error( $result ) ) {
			set_transient( 'bst_reply_draft_' . get_current_user_id() . '_' . $post_id, $body, 10 * MINUTE_IN_SECONDS );
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
			return;
		}

		BST_Admin_UI::flash( BST_Messages::INTERNAL === $visibility ? __( 'Internal note added.', 'bonsai-support-tickets' ) : __( 'Reply sent.', 'bonsai-support-tickets' ) );
	}

	/**
	 * "Post updated" messages that make sense for tickets.
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	public static function updated_messages( $messages ) {
		$saved = __( 'Ticket saved.', 'bonsai-support-tickets' );

		$messages[ BST_Post_Types::TICKET ] = array_fill( 0, 11, $saved );
		$messages[ BST_Post_Types::TICKET ][0] = '';
		$messages[ BST_Post_Types::TICKET ][6] = __( 'Ticket created.', 'bonsai-support-tickets' );

		return $messages;
	}
}
