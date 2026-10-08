<?php
/**
 * Support → Canned responses: edit screen, list, and the picker in the
 * ticket reply box.
 *
 * The reply text is a plain textarea saved into post_content (no rich
 * editor), because ticket messages are plain text too: what you write
 * here is exactly what lands in the reply box.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canned responses admin.
 */
class BST_Admin_Canned {

	const NONCE = 'bst_canned_nonce';

	/**
	 * Show the picker's filter box above this many replies.
	 */
	const FILTER_THRESHOLD = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		$type = BST_Post_Types::CANNED;

		add_action( "add_meta_boxes_{$type}", array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'save_text' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );

		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( "views_edit-{$type}", array( __CLASS__, 'views' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_order' ) );

		// Keep Support → Canned responses highlighted on the Tags screen.
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
	}

	/**
	 * Canned responses list URL.
	 *
	 * @return string
	 */
	public static function list_url() {
		return admin_url( 'edit.php?post_type=' . BST_Post_Types::CANNED );
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
		add_meta_box( 'bst_canned_text', __( 'Reply', 'bonsai-support-tickets' ), array( __CLASS__, 'box_text' ), BST_Post_Types::CANNED, 'normal', 'high' );
		add_meta_box( 'bst_canned_placeholders', __( 'Placeholders', 'bonsai-support-tickets' ), array( __CLASS__, 'box_placeholders' ), BST_Post_Types::CANNED, 'side', 'default' );
	}

	/**
	 * Title field placeholder.
	 *
	 * @param string  $text Default.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		return BST_Post_Types::CANNED === $post->post_type ? __( 'Name, e.g. Please send a screenshot', 'bonsai-support-tickets' ) : $text;
	}

	/**
	 * The reply text.
	 *
	 * @param WP_Post $post Canned response.
	 */
	public static function box_text( $post ) {
		wp_nonce_field( 'bst_save_canned', self::NONCE );
		?>
		<label class="screen-reader-text" for="bst-canned-text"><?php esc_html_e( 'Reply text', 'bonsai-support-tickets' ); ?></label>
		<textarea name="bst_canned_text" id="bst-canned-text" rows="14" class="widefat bst-canned-text"><?php echo esc_textarea( $post->post_content ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Plain text, exactly as it should appear in the reply box. Placeholders (see the box on the right) are filled in for the ticket you insert it into, and you can still edit the reply before sending.', 'bonsai-support-tickets' ); ?></p>
		<?php
	}

	/**
	 * Placeholder reference.
	 *
	 * @param WP_Post $post Canned response.
	 */
	public static function box_placeholders( $post ) {
		unset( $post );
		?>
		<dl class="bst-placeholder-list">
			<?php foreach ( BST_Canned::placeholder_help() as $placeholder => $meaning ) : ?>
				<dt><code><?php echo esc_html( $placeholder ); ?></code></dt>
				<?php if ( '' !== $meaning ) : ?>
					<dd><?php echo esc_html( $meaning ); ?></dd>
				<?php endif; ?>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/**
	 * Save the textarea into post_content. Runs inside wp_insert_post(), so
	 * there's no second save; the edit_post check has already happened.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr Raw (slashed) input.
	 * @return array
	 */
	public static function save_text( $data, $postarr ) {
		if ( BST_Post_Types::CANNED !== ( $data['post_type'] ?? '' ) || ! isset( $_POST[ self::NONCE ], $_POST['bst_canned_text'] ) ) {
			return $data;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'bst_save_canned' ) ) {
			return $data;
		}
		if ( ! empty( $postarr['ID'] ) && ! current_user_can( 'edit_post', (int) $postarr['ID'] ) ) {
			return $data;
		}

		$data['post_content'] = wp_slash( sanitize_textarea_field( wp_unslash( $_POST['bst_canned_text'] ) ) );
		return $data;
	}

	/*
	|----------------------------------------------------------------------
	| List screen
	|----------------------------------------------------------------------
	*/

	/**
	 * Columns.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'                                     => $columns['cb'],
			'title'                                  => __( 'Name', 'bonsai-support-tickets' ),
			'taxonomy-' . BST_Post_Types::CANNED_TAG => __( 'Tags', 'bonsai-support-tickets' ),
			'bst_preview'                            => __( 'Reply', 'bonsai-support-tickets' ),
			'date'                                   => $columns['date'] ?? __( 'Date', 'bonsai-support-tickets' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Canned response ID.
	 */
	public static function column( $column, $post_id ) {
		if ( 'bst_preview' === $column ) {
			echo '<span class="bst-muted">' . esc_html( wp_trim_words( (string) get_post_field( 'post_content', $post_id, 'raw' ), 22, '…' ) ) . '</span>';
		}
	}

	/**
	 * Tags link above the list.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		$views[ BST_Post_Types::CANNED_TAG ] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'edit-tags.php?taxonomy=' . BST_Post_Types::CANNED_TAG . '&post_type=' . BST_Post_Types::CANNED ) ),
			esc_html__( 'Tags', 'bonsai-support-tickets' )
		);
		return $views;
	}

	/**
	 * Row actions: drop Quick Edit (the reply text isn't in it).
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( BST_Post_Types::CANNED === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * List A–Z by name unless the user sorted it.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function list_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || BST_Post_Types::CANNED !== $query->get( 'post_type' ) || $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
	}

	/**
	 * Keep Support open on the Tags screen.
	 *
	 * @param string $parent_file Parent file.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		$screen = get_current_screen();
		if ( $screen && BST_Post_Types::CANNED_TAG === $screen->taxonomy ) {
			return 'edit.php?post_type=' . BST_Post_Types::TICKET;
		}
		return $parent_file;
	}

	/**
	 * Highlight Canned responses in the submenu on the Tags screen.
	 *
	 * @param string|null $submenu_file Submenu file.
	 * @param string      $parent_file  Parent file.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file, $parent_file ) {
		unset( $parent_file );
		$screen = get_current_screen();
		if ( $screen && BST_Post_Types::CANNED_TAG === $screen->taxonomy ) {
			return 'edit.php?post_type=' . BST_Post_Types::CANNED;
		}
		return $submenu_file;
	}

	/*
	|----------------------------------------------------------------------
	| Reply box picker
	|----------------------------------------------------------------------
	*/

	/**
	 * "Insert a canned response" select for the ticket reply box. Each
	 * option carries its reply already filled for this ticket, so inserting
	 * needs no request.
	 *
	 * @param WP_Post $ticket Ticket being replied to.
	 */
	public static function picker( $ticket ) {
		$can_manage = current_user_can( 'edit_bst_canned_responses' );
		$groups     = BST_Canned::grouped( $ticket->ID, get_current_user_id() );
		$count      = array_sum( array_map( 'count', $groups ) );

		if ( ! $count ) {
			if ( $can_manage ) {
				printf(
					'<p class="bst-canned-picker bst-muted"><a href="%1$s">%2$s</a></p>',
					esc_url( admin_url( 'post-new.php?post_type=' . BST_Post_Types::CANNED ) ),
					esc_html__( 'Add a canned response', 'bonsai-support-tickets' )
				);
			}
			return;
		}

		$tagged = count( $groups ) > 1 || '' !== (string) array_key_first( $groups );
		?>
		<div class="bst-canned-picker">
			<label for="bst-canned"><?php esc_html_e( 'Insert a canned response', 'bonsai-support-tickets' ); ?></label>
			<?php if ( $count > self::FILTER_THRESHOLD ) : ?>
				<input type="search" class="bst-canned-picker__filter" aria-controls="bst-canned" placeholder="<?php esc_attr_e( 'Filter…', 'bonsai-support-tickets' ); ?>" aria-label="<?php esc_attr_e( 'Filter canned responses', 'bonsai-support-tickets' ); ?>">
			<?php endif; ?>
			<select id="bst-canned" class="bst-canned-picker__select">
				<option value=""><?php esc_html_e( 'Choose a reply…', 'bonsai-support-tickets' ); ?></option>
				<?php foreach ( $groups as $group => $items ) : ?>
					<?php if ( $tagged ) : ?>
						<optgroup label="<?php echo esc_attr( '' !== $group ? $group : __( 'Untagged', 'bonsai-support-tickets' ) ); ?>">
					<?php endif; ?>
					<?php foreach ( $items as $item ) : ?>
						<option value="<?php echo esc_attr( $item['id'] ); ?>" data-text="<?php echo esc_attr( $item['text'] ); ?>"><?php echo esc_html( $item['title'] ); ?></option>
					<?php endforeach; ?>
					<?php if ( $tagged ) : ?>
						</optgroup>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<?php if ( $can_manage ) : ?>
				<a href="<?php echo esc_url( self::list_url() ); ?>" class="bst-canned-picker__manage"><?php esc_html_e( 'Manage', 'bonsai-support-tickets' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}
}
