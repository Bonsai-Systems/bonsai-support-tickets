<?php
/**
 * DEVELOPMENT BUILDS ONLY — excluded from the product zip.
 *
 * Moves the original support site from its ACF-based theme
 * (bonsai-support-theme) to the bundled Support Desk theme:
 *
 * - Pages: the ACF flexible content (page_builder) becomes Support Desk
 *   blocks in post_content. The theme's block attributes use the ACF field
 *   names, so values carry over one to one. The page's previous content is
 *   kept in a revision and in _bst_pre_blocks_content, and the ACF meta is
 *   left untouched, so switching back to the old theme still works.
 * - Site settings (ACF options page) become the theme's Customiser values;
 *   the old theme's hard-coded footer/credit text is written explicitly.
 * - Menu locations are copied across.
 *
 * Runs when the site switches to the Support Desk theme (from the notice
 * below, or Appearance → Themes). Reads raw post meta and options, so ACF
 * doesn't need to be active. Safe to run again: converted pages are skipped.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * ACF page builder → Support Desk blocks, for the original site.
 */
class BST_Legacy_Theme {

	const OLD_THEME = 'bonsai-support-theme';
	const NEW_THEME = 'support-desk-theme';
	const RESULT    = 'bst_legacy_theme_migration';
	const META_DONE = '_bst_blocks_from_acf';
	const META_PREV = '_bst_pre_blocks_content';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'after_switch_theme', array( __CLASS__, 'on_switch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_bst_legacy_switch_theme', array( __CLASS__, 'handle_switch' ) );
	}

	/**
	 * Old layouts: block slug and ACF field types (from the old theme's
	 * acf-json/group_bsup_page_builder.json).
	 *
	 * Types: text, textarea, select, number, bool, link, ids, wysiwyg, inner
	 * (wysiwyg that becomes the Content block's inner blocks), and
	 * repeater => sub fields.
	 *
	 * "defaults" are the ACF default values that differ from the block's own
	 * defaults. ACF showed them when a field had never been saved (no meta
	 * row), so the converter does the same.
	 *
	 * @return array<string,array>
	 */
	public static function layouts() {
		$bg = array( 'background_style' => 'select' );

		return array(
			'help_search_hero_module' => array(
				'block'  => 'help-search-hero',
				'fields' => array(
					'hero_badge'         => 'text',
					'hero_title'         => 'text',
					'hero_lead'          => 'textarea',
					'search_placeholder' => 'text',
					'popular_articles'   => 'ids',
				) + $bg,
			),
			'support_links_module'    => array(
				'block'  => 'support-links',
				'fields' => array(
					'section_tag' => 'text',
					'heading'     => 'text',
					'description' => 'text',
					'links'       => array(
						'link_type'   => 'select',
						'icon'        => 'select',
						'link'        => 'link',
						'title'       => 'text',
						'description' => 'text',
					),
				) + $bg,
			),
			'help_topics_module'      => array(
				'block'  => 'help-topics',
				'fields' => array(
					'section_tag'        => 'text',
					'heading'            => 'text',
					'description'        => 'text',
					'topics_source'      => 'select',
					'topics'             => 'ids',
					'articles_per_topic' => 'number',
					'columns'            => 'select',
				) + $bg,
			),
			'ticket_portal_module'    => array(
				'block'  => 'ticket-portal',
				'fields' => array( 'intro' => 'textarea' ) + $bg,
			),
			'submit_request_module'   => array(
				'block'    => 'submit-request',
				'defaults' => array( 'show_help_link' => true ),
				'fields' => array(
					'section_tag'    => 'text',
					'heading'        => 'text',
					'intro'          => 'wysiwyg',
					'tips'           => array( 'tip' => 'text' ),
					'show_help_link' => 'bool',
				) + $bg,
			),
			'subpage_hero_module'     => array(
				'block'  => 'subpage-hero',
				'fields' => array(
					'hero_badge' => 'text',
					'hero_title' => 'text',
					'hero_lead'  => 'textarea',
					'hero_style' => 'select',
				) + $bg,
			),
			'content_block_module'    => array(
				'block'  => 'content-block',
				'fields' => array(
					'section_tag'   => 'text',
					'content_width' => 'select',
					'heading_level' => 'select',
					'heading'       => 'text',
					'body_content'  => 'inner',
				) + $bg,
			),
			'faq_module'              => array(
				'block'    => 'faq',
				'defaults' => array( 'section_tag' => 'FAQ' ),
				'fields' => array(
					'section_tag' => 'text',
					'heading'     => 'text',
					'description' => 'text',
					'faq_items'   => array(
						'question' => 'text',
						'answer'   => 'wysiwyg',
					),
				) + $bg,
			),
			'feature_cards_module'    => array(
				'block'  => 'feature-cards',
				'fields' => array(
					'eyebrow'       => 'text',
					'heading'       => 'text',
					'columns'       => 'select',
					'feature_cards' => array(
						'card_label'       => 'text',
						'card_title'       => 'text',
						'card_description' => 'textarea',
					),
				) + $bg,
			),
			'process_module'          => array(
				'block'    => 'process',
				'defaults' => array(
					'section_tag' => 'Methodology',
					'heading'     => 'Our Process',
				),
				'fields' => array(
					'section_tag' => 'text',
					'heading'     => 'text',
					'description' => 'text',
					'steps'       => array(
						'step_number'      => 'text',
						'step_title'       => 'text',
						'step_description' => 'text',
					),
				) + $bg,
			),
			'cta_compact_module'      => array(
				'block'  => 'cta-compact',
				'fields' => array(
					'heading'          => 'text',
					'description'      => 'textarea',
					'primary_button'   => 'link',
					'secondary_button' => 'link',
				),
			),
			'meet_the_team_module'    => array(
				'block'    => 'meet-the-team',
				'defaults' => array(
					'section_tag' => 'The Team',
					'heading'     => 'Meet the Team',
				),
				'fields' => array(
					'section_tag'  => 'text',
					'heading'      => 'text',
					'intro'        => 'wysiwyg',
					'source'       => 'select',
					'team_members' => 'ids',
				) + $bg,
			),
		);
	}

	/*
	|----------------------------------------------------------------------
	| Switching
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether this site still runs the old theme.
	 *
	 * @return bool
	 */
	public static function on_old_theme() {
		return self::OLD_THEME === get_stylesheet() || self::OLD_THEME === get_template();
	}

	/**
	 * Converted on switching to the new theme (any route).
	 *
	 * @param string $old_name Previous theme name (unused).
	 */
	public static function on_switch( $old_name = '' ) {
		unset( $old_name );
		if ( self::NEW_THEME === get_template() ) {
			self::migrate();
		}
	}

	/**
	 * Admin notice on the old theme: one click to move over. After a run,
	 * a summary.
	 */
	public static function notice() {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		$result = get_option( self::RESULT );
		if ( is_array( $result ) && empty( $result['seen'] ) && self::NEW_THEME === get_template() ) {
			$result['seen'] = 1;
			update_option( self::RESULT, $result, false );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: pages converted, 2: pages skipped, 3: problems. */
						__( 'Moved to the Support Desk theme: %1$d pages converted to blocks, %2$d already done or not built with modules, %3$d problems (see the PHP error log). Site settings and menus were copied to Appearance → Customise.', 'bonsai-support-tickets' ),
						(int) $result['converted'],
						(int) $result['skipped'],
						count( (array) $result['errors'] )
					)
				)
			);
			return;
		}

		if ( ! self::on_old_theme() ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Move to the Support Desk theme', 'bonsai-support-tickets' ); ?></strong></p>
			<p><?php esc_html_e( 'The support theme now ships with the plugin and no longer needs ACF. Switching converts every page built with modules into blocks, and copies Site settings and menus to the Customiser. The ACF content is kept, so you can switch back under Appearance → Themes.', 'bonsai-support-tickets' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bst_legacy_switch_theme">
				<?php wp_nonce_field( 'bst_legacy_switch_theme' ); ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Switch and convert', 'bonsai-support-tickets' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Switch and convert.
	 */
	public static function handle_switch() {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the theme.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_legacy_switch_theme' );

		// The theme ships in the plugin; install it if needed (bundling step).
		if ( ! wp_get_theme( self::NEW_THEME )->exists() && class_exists( 'BST_Theme_Installer' ) ) {
			BST_Theme_Installer::install();
		}

		$theme = wp_get_theme( self::NEW_THEME );
		if ( ! $theme->exists() || $theme->errors() ) {
			wp_die( esc_html__( 'The Support Desk theme is not installed yet. Copy the plugin\'s theme/support-desk-theme folder to wp-content/themes and try again.', 'bonsai-support-tickets' ) );
		}

		// Convert first, so the new theme never renders unconverted pages.
		self::migrate();
		switch_theme( self::NEW_THEME );

		wp_safe_redirect( admin_url( 'edit.php?post_type=page' ) );
		exit;
	}

	/*
	|----------------------------------------------------------------------
	| Migration
	|----------------------------------------------------------------------
	*/

	/**
	 * Convert pages, copy settings and menus. Records a summary.
	 *
	 * @return array{converted:int,skipped:int,errors:string[]}
	 */
	public static function migrate() {
		$result = array(
			'converted' => 0,
			'skipped'   => 0,
			'errors'    => array(),
			'at'        => time(),
		);

		try {
			foreach ( self::pages() as $page_id ) {
				$status = self::convert_page( $page_id );
				if ( true === $status ) {
					++$result['converted'];
				} elseif ( false === $status ) {
					++$result['skipped'];
				} else {
					$result['errors'][] = $status;
					error_log( BST_PRODUCT_NAME . ': theme migration: ' . $status );
				}
			}
			self::copy_settings();
			self::copy_menus();
		} catch ( Throwable $e ) {
			$result['errors'][] = $e->getMessage();
			error_log( BST_PRODUCT_NAME . ': theme migration failed: ' . $e->getMessage() );
		}

		// A second run (e.g. after_switch_theme after the button already
		// converted everything) shouldn't replace the real summary.
		if ( $result['converted'] || $result['errors'] || ! is_array( get_option( self::RESULT ) ) ) {
			update_option( self::RESULT, $result, false );
		}
		return $result;
	}

	/**
	 * Pages (any status) with page builder data.
	 *
	 * @return int[]
	 */
	public static function pages() {
		return get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
				'meta_key'         => 'page_builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
	}

	/**
	 * Convert one page.
	 *
	 * @param int $page_id Page.
	 * @return bool|string True converted, false skipped, string error.
	 */
	public static function convert_page( $page_id ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return false;
		}
		if ( get_post_meta( $page_id, self::META_DONE, true ) || false !== strpos( $post->post_content, '<!-- wp:support-desk/' ) ) {
			return false;
		}

		$markup = self::page_markup( $page_id );
		if ( '' === $markup ) {
			return false;
		}

		// Keep what was there (usually empty, sometimes a shortcode).
		update_post_meta( $page_id, self::META_PREV, wp_slash( $post->post_content ) );
		wp_save_post_revision( $page_id );

		$updated = wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => wp_slash( $markup ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return sprintf( 'page %d: %s', $page_id, $updated->get_error_message() );
		}

		update_post_meta( $page_id, self::META_DONE, time() );
		return true;
	}

	/**
	 * Block markup for a page's page builder rows.
	 *
	 * @param int $page_id Page.
	 * @return string '' when there's nothing to convert.
	 */
	public static function page_markup( $page_id ) {
		$rows = get_post_meta( $page_id, 'page_builder', true );
		if ( ! is_array( $rows ) || ! $rows ) {
			return '';
		}

		$layouts = self::layouts();
		$blocks  = array();

		foreach ( array_values( $rows ) as $index => $layout ) {
			if ( ! is_string( $layout ) || ! isset( $layouts[ $layout ] ) ) {
				error_log( BST_PRODUCT_NAME . ': theme migration: unknown layout "' . ( is_string( $layout ) ? $layout : gettype( $layout ) ) . '" on page ' . $page_id );
				continue;
			}

			$prefix   = 'page_builder_' . $index . '_';
			$attrs    = array();
			$inner    = '';
			$defaults = $layouts[ $layout ]['defaults'] ?? array();

			foreach ( $layouts[ $layout ]['fields'] as $name => $type ) {
				if ( isset( $defaults[ $name ] ) && ! metadata_exists( 'post', $page_id, $prefix . $name ) ) {
					$attrs[ $name ] = $defaults[ $name ];
					continue;
				}
				if ( 'inner' === $type ) {
					$inner = (string) get_post_meta( $page_id, $prefix . $name, true );
					continue;
				}
				$value = is_array( $type )
					? self::repeater( $page_id, $prefix . $name, $type )
					: self::value( get_post_meta( $page_id, $prefix . $name, true ), $type );
				if ( null !== $value ) {
					$attrs[ $name ] = $value;
				}
			}

			$blocks[] = self::block( $layouts[ $layout ]['block'], $attrs, $inner );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Repeater rows.
	 *
	 * @param int    $page_id Page.
	 * @param string $key     Repeater meta key.
	 * @param array  $fields  Sub field types.
	 * @return array|null
	 */
	private static function repeater( $page_id, $key, array $fields ) {
		$count = (int) get_post_meta( $page_id, $key, true );
		if ( $count < 1 ) {
			return null;
		}
		$rows = array();
		for ( $r = 0; $r < $count; $r++ ) {
			$row = array();
			foreach ( $fields as $name => $type ) {
				$value = self::value( get_post_meta( $page_id, $key . '_' . $r . '_' . $name, true ), $type );
				if ( null !== $value ) {
					$row[ $name ] = $value;
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * One raw ACF value as a block attribute, or null to leave it out.
	 *
	 * @param mixed  $raw  Meta value.
	 * @param string $type Field type.
	 * @return mixed|null
	 */
	public static function value( $raw, $type ) {
		switch ( $type ) {
			case 'bool':
				return '' === $raw ? null : (bool) $raw;
			case 'number':
				return is_numeric( $raw ) ? (int) $raw : null;
			case 'ids':
				$ids = is_array( $raw ) ? $raw : ( is_numeric( $raw ) ? array( $raw ) : array() );
				$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
				return $ids ? $ids : null;
			case 'link':
				if ( ! is_array( $raw ) || empty( $raw['url'] ) ) {
					return null;
				}
				return array(
					'url'    => (string) $raw['url'],
					'title'  => (string) ( $raw['title'] ?? '' ),
					'target' => (string) ( $raw['target'] ?? '' ),
				);
			case 'wysiwyg':
				// ACF ran wpautop on output; the blocks print what's stored.
				return is_string( $raw ) && '' !== trim( $raw ) ? wpautop( $raw ) : null;
			default:
				return is_scalar( $raw ) && '' !== (string) $raw ? (string) $raw : null;
		}
	}

	/**
	 * Serialised Support Desk block (same format as the theme's
	 * bsup_block_markup(), which may not be loaded here).
	 *
	 * @param string $slug  Block slug.
	 * @param array  $attrs Attributes.
	 * @param string $inner Content block HTML (becomes a Classic block inside).
	 * @return string
	 */
	public static function block( $slug, array $attrs, $inner = '' ) {
		$inner_blocks = array();
		if ( '' !== trim( $inner ) ) {
			$html           = wpautop( $inner );
			$inner_blocks[] = array(
				'blockName'    => 'core/freeform',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $html,
				'innerContent' => array( $html ),
			);
		}

		return serialize_block(
			array(
				'blockName'    => 'support-desk/' . $slug,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => '',
				'innerContent' => $inner_blocks ? array( null ) : array(),
			)
		);
	}

	/**
	 * ACF option name (Site settings) → theme mod, with the old theme's
	 * hard-coded fallbacks for anything never filled in.
	 *
	 * @return array<string,array{0:string,1:string}> option field => [theme mod, old fallback].
	 */
	public static function settings_map() {
		return array(
			'site_main_logo'    => array( 'custom_logo', '' ),
			'site_footer_logo'  => array( 'bsup_site_footer_logo', '' ),
			'header_label'      => array( 'bsup_header_label', '' ),
			'nav_cta_text'      => array( 'bsup_nav_cta_text', '' ),
			'nav_cta_url'       => array( 'bsup_nav_cta_url', '' ),
			'footer_tagline'    => array( 'bsup_footer_tagline', 'Senior-led support for the websites we build and look after.' ),
			'contact_email'     => array( 'bsup_contact_email', '' ),
			'contact_telephone' => array( 'bsup_contact_telephone', '' ),
			'support_hours'     => array( 'bsup_support_hours', '' ),
			'main_site_url'     => array( 'bsup_main_site_url', 'https://bonsaidigitalcollective.co.uk/' ),
			'help_title'        => array( 'bsup_help_title', 'How can we help' ),
			'help_lead'         => array( 'bsup_help_lead', 'Answers to common questions about your website, hosting and email.' ),
			'copyright_text'    => array( 'bsup_copyright_text', 'The Bonsai Digital Collective.' ),
		);
	}

	/**
	 * Copy Site settings into the new theme's mods (only where not set yet).
	 */
	public static function copy_settings() {
		$mods = self::new_mods();

		foreach ( self::settings_map() as $field => list( $mod, $fallback ) ) {
			if ( isset( $mods[ $mod ] ) && '' !== $mods[ $mod ] ) {
				continue;
			}
			$value = get_option( 'options_' . $field, '' );
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === $value ) {
				$value = $fallback;
			}
			if ( '' !== $value ) {
				$mods[ $mod ] = in_array( $mod, array( 'custom_logo', 'bsup_site_footer_logo' ), true ) ? absint( $value ) : $value;
			}
		}

		// The old footer's fixed credit and the light-blue band.
		$extras = array(
			'bsup_credit_text' => 'Website by The Bonsai Digital Collective',
			'bsup_credit_url'  => 'https://bonsaidigitalcollective.co.uk/',
			'bsup_color_tint'  => '#e2ecf3',
		);
		foreach ( $extras as $mod => $value ) {
			if ( empty( $mods[ $mod ] ) ) {
				$mods[ $mod ] = $value;
			}
		}

		self::save_new_mods( $mods );
	}

	/**
	 * Copy menu locations from the old theme.
	 */
	public static function copy_menus() {
		$old  = get_option( 'theme_mods_' . self::OLD_THEME, array() );
		$mods = self::new_mods();
		$from = is_array( $old ) && ! empty( $old['nav_menu_locations'] ) ? (array) $old['nav_menu_locations'] : array();
		$to   = ! empty( $mods['nav_menu_locations'] ) ? (array) $mods['nav_menu_locations'] : array();

		foreach ( $from as $location => $menu_id ) {
			if ( empty( $to[ $location ] ) && $menu_id ) {
				$to[ $location ] = (int) $menu_id;
			}
		}

		if ( $to ) {
			$mods['nav_menu_locations'] = $to;
			self::save_new_mods( $mods );
		}
	}

	/**
	 * The new theme's mods (whether or not it's active yet).
	 *
	 * @return array
	 */
	private static function new_mods() {
		$mods = get_option( 'theme_mods_' . self::NEW_THEME, array() );
		return is_array( $mods ) ? $mods : array();
	}

	/**
	 * Save the new theme's mods.
	 *
	 * @param array $mods Mods.
	 */
	private static function save_new_mods( array $mods ) {
		update_option( 'theme_mods_' . self::NEW_THEME, $mods );
	}
}

BST_Legacy_Theme::init();
