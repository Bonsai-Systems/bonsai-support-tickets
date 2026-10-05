<?php
/**
 * Support → Clients: the client company screens (list and edit), plus the
 * Partners and Plans lists.
 *
 * Data lives in BST_Companies. People are linked to a client on their user
 * profile (or when a sign-up is approved); this screen lists them.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client company admin.
 */
class BST_Admin_Companies {

	const NONCE = 'bst_company_nonce';

	/**
	 * Hooks.
	 */
	public static function init() {
		$type = BST_Post_Types::COMPANY;

		add_action( "add_meta_boxes_{$type}", array( __CLASS__, 'meta_boxes' ) );
		add_action( "save_post_{$type}", array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );

		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( "views_edit-{$type}", array( __CLASS__, 'views' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );

		// Keep Support → Clients highlighted on the Partners/Plans screens.
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
	}

	/**
	 * Admin URL of a list (partners or plans).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	public static function terms_url( $taxonomy ) {
		return admin_url( 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . BST_Post_Types::COMPANY );
	}

	/**
	 * Tickets list filtered to one client.
	 *
	 * @param int $company_id Company ID.
	 * @return string
	 */
	public static function tickets_url( $company_id ) {
		return add_query_arg( 'bst_company', (int) $company_id, admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ) );
	}

	/*
	|----------------------------------------------------------------------
	| Edit screen
	|----------------------------------------------------------------------
	*/

	/**
	 * Meta boxes.
	 */
	public static function meta_boxes() {
		add_meta_box( 'bst_company_details', __( 'Details', 'bonsai-support-tickets' ), array( __CLASS__, 'box_details' ), BST_Post_Types::COMPANY, 'normal', 'high' );
		add_meta_box( 'bst_company_people', __( 'People', 'bonsai-support-tickets' ), array( __CLASS__, 'box_people' ), BST_Post_Types::COMPANY, 'side', 'default' );
		add_meta_box( 'bst_company_tickets', __( 'Tickets', 'bonsai-support-tickets' ), array( __CLASS__, 'box_tickets' ), BST_Post_Types::COMPANY, 'side', 'default' );
	}

	/**
	 * Placeholder for the name field.
	 *
	 * @param string  $text Placeholder.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		return BST_Post_Types::COMPANY === $post->post_type ? __( 'Client name, e.g. The Ley Arms', 'bonsai-support-tickets' ) : $text;
	}

	/**
	 * Details: partner, plan, retainer, websites, notes.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_details( $post ) {
		wp_nonce_field( 'bst_save_company_' . $post->ID, self::NONCE );

		$retainer = get_post_meta( $post->ID, BST_Companies::META_RETAINER, true );
		$websites = BST_Companies::websites( $post->ID );
		$notes    = (string) get_post_meta( $post->ID, BST_Companies::META_NOTES, true );
		$lists    = array(
			BST_Post_Types::PARTNER => array(
				'label' => __( 'Partner', 'bonsai-support-tickets' ),
				'none'  => __( 'Direct (no partner)', 'bonsai-support-tickets' ),
				'help'  => __( 'Who the client came through, if not direct.', 'bonsai-support-tickets' ),
			),
			BST_Post_Types::PLAN    => array(
				'label' => __( 'Plan', 'bonsai-support-tickets' ),
				'none'  => __( 'No plan', 'bonsai-support-tickets' ),
				'help'  => __( 'Their support plan.', 'bonsai-support-tickets' ),
			),
		);
		?>
		<table class="form-table" role="presentation">
			<?php foreach ( $lists as $taxonomy => $list ) : ?>
				<?php
				$current = BST_Companies::term( $post->ID, $taxonomy );
				$terms   = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				$terms   = is_wp_error( $terms ) ? array() : $terms;
				$field   = 'bst_' . str_replace( 'bst_', '', $taxonomy );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( 'bst-company-' . $field ); ?>"><?php echo esc_html( $list['label'] ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( 'bst-company-' . $field ); ?>">
							<option value="0"><?php echo esc_html( $list['none'] ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $current ? $current->term_id : 0, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php echo esc_html( $list['help'] ); ?>
							<?php if ( current_user_can( 'bst_manage_settings' ) ) : ?>
								<a href="<?php echo esc_url( self::terms_url( $taxonomy ) ); ?>">
									<?php
									/* translators: %s: list name, e.g. "partners". */
									echo esc_html( sprintf( __( 'Edit the list of %s', 'bonsai-support-tickets' ), mb_strtolower( get_taxonomy( $taxonomy )->labels->name ) ) );
									?>
								</a>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="bst-company-retainer"><?php esc_html_e( 'Retainer', 'bonsai-support-tickets' ); ?></label></th>
				<td>
					<input type="number" name="bst_retainer_hours" id="bst-company-retainer" class="small-text" min="0" max="1000" step="0.25" value="<?php echo esc_attr( '' !== $retainer ? $retainer : '' ); ?>">
					<?php esc_html_e( 'hours a month', 'bonsai-support-tickets' ); ?>
					<p class="description"><?php esc_html_e( 'Blank or 0 for no retainer. Time tracking against it is coming later.', 'bonsai-support-tickets' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bst-company-websites"><?php esc_html_e( 'Websites', 'bonsai-support-tickets' ); ?></label></th>
				<td>
					<textarea name="bst_websites" id="bst-company-websites" class="large-text code" rows="4" placeholder="https://example.co.uk"><?php echo esc_textarea( implode( "\n", $websites ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line. Used later to match uptime alerts to this client.', 'bonsai-support-tickets' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bst-company-notes"><?php esc_html_e( 'Notes', 'bonsai-support-tickets' ); ?></label></th>
				<td>
					<textarea name="bst_notes" id="bst-company-notes" class="large-text" rows="5"><?php echo esc_textarea( $notes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'For the support team only. Clients never see this.', 'bonsai-support-tickets' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * People linked to the client.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_people( $post ) {
		$people = 'auto-draft' === $post->post_status ? array() : BST_Companies::people( $post->ID );

		if ( ! $people ) {
			echo '<p class="bst-muted">' . esc_html__( 'Nobody yet.', 'bonsai-support-tickets' ) . '</p>';
		} else {
			echo '<ul class="bst-company-people">';
			foreach ( $people as $person ) {
				$name = esc_html( $person->display_name );
				if ( current_user_can( 'edit_user', $person->ID ) ) {
					$name = '<a href="' . esc_url( get_edit_user_link( $person->ID ) ) . '">' . $name . '</a>';
				}
				printf(
					'<li><strong>%1$s</strong><br><span class="bst-muted">%2$s</span>%3$s</li>',
					$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					esc_html( $person->user_email ),
					BST_Clients::is_pending( $person->ID ) ? ' ' . BST_Admin_UI::badge( __( 'Awaiting approval', 'bonsai-support-tickets' ), 'warning' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				);
			}
			echo '</ul>';
		}

		echo '<p class="description">' . esc_html__( 'Link someone on their user profile (Client field), or when approving their sign-up. Each person belongs to one client, and still only sees their own tickets.', 'bonsai-support-tickets' ) . '</p>';
		if ( current_user_can( 'create_users' ) ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'Add a person', 'bonsai-support-tickets' ) . '</a></p>';
		}
	}

	/**
	 * Ticket counts with a link to the filtered list.
	 *
	 * @param WP_Post $post Company.
	 */
	public static function box_tickets( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="bst-muted">' . esc_html__( 'Save the client first.', 'bonsai-support-tickets' ) . '</p>';
			return;
		}
		$total  = BST_Companies::ticket_count( $post->ID );
		$active = BST_Companies::ticket_count( $post->ID, true );
		?>
		<p>
			<?php
			/* translators: 1: active tickets, 2: all tickets. */
			echo esc_html( sprintf( __( '%1$d active, %2$d in total.', 'bonsai-support-tickets' ), $active, $total ) );
			?>
		</p>
		<?php if ( $total ) : ?>
			<p><a href="<?php echo esc_url( self::tickets_url( $post->ID ) ); ?>"><?php esc_html_e( 'View their tickets', 'bonsai-support-tickets' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the Details box.
	 *
	 * @param int     $post_id Company ID.
	 * @param WP_Post $post    Company.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'bst_save_company_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		try {
			foreach ( array( BST_Post_Types::PARTNER, BST_Post_Types::PLAN ) as $taxonomy ) {
				$field   = 'bst_' . str_replace( 'bst_', '', $taxonomy );
				$term_id = absint( $_POST[ $field ] ?? 0 );
				$valid   = $term_id && term_exists( $term_id, $taxonomy );
				wp_set_object_terms( $post_id, $valid ? array( $term_id ) : array(), $taxonomy );
			}

			$retainer = isset( $_POST['bst_retainer_hours'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['bst_retainer_hours'] ) ) : 0;
			$retainer = max( 0, min( 1000, round( $retainer, 2 ) ) );
			if ( $retainer > 0 ) {
				update_post_meta( $post_id, BST_Companies::META_RETAINER, (string) $retainer );
			} else {
				delete_post_meta( $post_id, BST_Companies::META_RETAINER );
			}

			$sites = BST_Companies::parse_websites( sanitize_textarea_field( wp_unslash( $_POST['bst_websites'] ?? '' ) ) );
			update_post_meta( $post_id, BST_Companies::META_WEBSITES, $sites );

			update_post_meta( $post_id, BST_Companies::META_NOTES, sanitize_textarea_field( wp_unslash( $_POST['bst_notes'] ?? '' ) ) );
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': client save failed for ' . $post_id . ': ' . $e->getMessage() );
			BST_Admin_UI::flash( __( 'Something went wrong saving the client. Please check it and try again.', 'bonsai-support-tickets' ), 'error' );
		}
	}

	/*
	|----------------------------------------------------------------------
	| List screen
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
			'cb'                                  => $columns['cb'],
			'title'                               => __( 'Client', 'bonsai-support-tickets' ),
			'taxonomy-' . BST_Post_Types::PARTNER => __( 'Partner', 'bonsai-support-tickets' ),
			'taxonomy-' . BST_Post_Types::PLAN    => __( 'Plan', 'bonsai-support-tickets' ),
			'bst_retainer'                        => __( 'Retainer', 'bonsai-support-tickets' ),
			'bst_people'                          => __( 'People', 'bonsai-support-tickets' ),
			'bst_tickets'                         => __( 'Active tickets', 'bonsai-support-tickets' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Company ID.
	 */
	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'bst_retainer':
				$hours = BST_Companies::retainer_hours( $post_id );
				/* translators: %s: hours. */
				echo $hours ? esc_html( sprintf( __( '%s h/month', 'bonsai-support-tickets' ), number_format_i18n( $hours, floor( $hours ) === $hours ? 0 : 2 ) ) ) : '<span class="bst-muted">—</span>';
				break;

			case 'bst_people':
				echo esc_html( number_format_i18n( count( BST_Companies::people( $post_id ) ) ) );
				break;

			case 'bst_tickets':
				$active = BST_Companies::ticket_count( $post_id, true );
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url( add_query_arg( 'bst_view', 'active', self::tickets_url( $post_id ) ) ),
					esc_html( number_format_i18n( $active ) )
				);
				break;
		}
	}

	/**
	 * Links to the Partners and Plans lists above the Clients list.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		if ( ! current_user_can( 'bst_manage_settings' ) ) {
			return $views;
		}
		foreach ( array( BST_Post_Types::PARTNER, BST_Post_Types::PLAN ) as $taxonomy ) {
			$views[ $taxonomy ] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( self::terms_url( $taxonomy ) ),
				esc_html( get_taxonomy( $taxonomy )->labels->name )
			);
		}
		return $views;
	}

	/**
	 * Row actions: drop Quick Edit (partner/plan are set in the Details box).
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( BST_Post_Types::COMPANY === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Highlight Support in the menu on the Partners/Plans screens.
	 *
	 * @param string $parent_file Parent menu file.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->taxonomy, array( BST_Post_Types::PARTNER, BST_Post_Types::PLAN ), true ) ) {
			return 'edit.php?post_type=' . BST_Post_Types::TICKET;
		}
		return $parent_file;
	}

	/**
	 * Highlight Clients in the submenu on the Partners/Plans screens.
	 *
	 * @param string|null $submenu_file Submenu file.
	 * @param string      $parent_file  Parent file.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file, $parent_file ) {
		unset( $parent_file );
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->taxonomy, array( BST_Post_Types::PARTNER, BST_Post_Types::PLAN ), true ) ) {
			return 'edit.php?post_type=' . BST_Post_Types::COMPANY;
		}
		return $submenu_file;
	}
}
