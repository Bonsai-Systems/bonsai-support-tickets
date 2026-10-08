<?php
/**
 * DEVELOPMENT BUILDS ONLY — excluded from the product zip.
 *
 * Moves the original support site from its theme (bonsai-support-theme) to
 * the bundled Support Desk theme. Both use the same ACF field groups (same
 * keys), so pages, Site settings and team members carry over untouched.
 * This only covers what the old theme kept elsewhere or hard-coded:
 *
 * - The header logo (old Site settings field) becomes the Site Identity logo.
 * - The old theme's hard-coded fallbacks (footer tagline, main website,
 *   help centre text, copyright, credit, light-blue tint) are written into
 *   Site settings where they're still empty, so the site looks the same.
 * - Menu locations are copied across.
 *
 * Runs when the site switches to the Support Desk theme (from the notice
 * below, or Appearance → Themes). Reads and writes raw options, so ACF
 * doesn't need to be active. Safe to run again: filled values are kept.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Old Support theme → Support Desk theme, for the original site.
 */
class BST_Legacy_Theme {

	const OLD_THEME = 'bonsai-support-theme';
	const NEW_THEME = 'support-desk-theme';
	const RESULT    = 'bst_legacy_theme_migration';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'after_switch_theme', array( __CLASS__, 'on_switch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_bst_legacy_switch_theme', array( __CLASS__, 'handle_switch' ) );
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
	 * Runs on switching to the new theme (any route).
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
						/* translators: %d: problems. */
						__( 'Moved to the Support Desk theme. Pages, Site settings and team members carried over as they were; the logo, menus and the old footer text were copied. Problems: %d (see the PHP error log).', 'bonsai-support-tickets' ),
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
			<p><?php esc_html_e( 'The support theme now ships with the plugin. It uses the same page modules and Site settings (ACF Pro), so pages carry over as they are. Switching also copies the logo, menus and the old footer text. You can switch back under Appearance → Themes.', 'bonsai-support-tickets' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bst_legacy_switch_theme">
				<?php wp_nonce_field( 'bst_legacy_switch_theme' ); ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Switch theme', 'bonsai-support-tickets' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Switch to the new theme.
	 */
	public static function handle_switch() {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the theme.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bst_legacy_switch_theme' );

		// The theme ships in the plugin; install it if needed.
		if ( ! wp_get_theme( self::NEW_THEME )->exists() && class_exists( 'BST_Theme_Installer' ) ) {
			$installed = BST_Theme_Installer::install();
			if ( is_wp_error( $installed ) ) {
				wp_die( esc_html( $installed->get_error_message() ) );
			}
		}

		$theme = wp_get_theme( self::NEW_THEME );
		if ( ! $theme->exists() || $theme->errors() ) {
			wp_die( esc_html__( 'The Support Desk theme is not installed yet. Copy the plugin\'s theme/support-desk-theme folder to wp-content/themes and try again.', 'bonsai-support-tickets' ) );
		}

		// Copy first, so the new theme's first render has its settings.
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
	 * Copy the logo, fill Site settings fallbacks and copy menus. Records a summary.
	 *
	 * @return array{errors:string[],at:int}
	 */
	public static function migrate() {
		$result = array(
			'errors' => array(),
			'at'     => time(),
		);

		try {
			self::copy_logo();
			self::fill_settings();
			self::copy_menus();
		} catch ( Throwable $e ) {
			$result['errors'][] = $e->getMessage();
			error_log( BST_PRODUCT_NAME . ': theme migration failed: ' . $e->getMessage() );
		}

		update_option( self::RESULT, $result, false );
		return $result;
	}

	/**
	 * Site settings the old theme filled in itself when they were empty:
	 * option field name => [field key, old fallback]. Plus the footer credit
	 * and light-blue tint it hard-coded, which are now settings.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function settings_map() {
		return array(
			'footer_tagline' => array( 'field_bso_footer_tagline', 'Senior-led support for the websites we build and look after.' ),
			'main_site_url'  => array( 'field_bsup_main_site', 'https://bonsaidigitalcollective.co.uk/' ),
			'help_title'     => array( 'field_bsup_help_title', 'How can we help' ),
			'help_lead'      => array( 'field_bsup_help_lead', 'Answers to common questions about your website, hosting and email.' ),
			'copyright_text' => array( 'field_bso_copyright', 'The Bonsai Digital Collective.' ),
			'credit_text'    => array( 'field_bsup_credit_text', 'Website by The Bonsai Digital Collective' ),
			'credit_url'     => array( 'field_bsup_credit_url', 'https://bonsaidigitalcollective.co.uk/' ),
			'color_tint'     => array( 'field_bsup_color_tint', '#e2ecf3' ),
		);
	}

	/**
	 * Write the old fallbacks into Site settings where still empty. Uses ACF's
	 * storage format (options_{name} + _options_{name} = field key) directly.
	 */
	public static function fill_settings() {
		foreach ( self::settings_map() as $name => list( $key, $fallback ) ) {
			$current = get_option( 'options_' . $name, '' );
			if ( is_scalar( $current ) && '' !== (string) $current ) {
				continue;
			}
			update_option( 'options_' . $name, $fallback, false );
			update_option( '_options_' . $name, $key, false );
		}
	}

	/**
	 * Old header logo (Site settings) → Site Identity logo, unless one is set.
	 */
	public static function copy_logo() {
		$logo = absint( get_option( 'options_site_main_logo', 0 ) );
		$mods = self::new_mods();
		if ( $logo && empty( $mods['custom_logo'] ) ) {
			$mods['custom_logo'] = $logo;
			self::save_new_mods( $mods );
		}
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
