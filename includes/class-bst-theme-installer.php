<?php
/**
 * The bundled Support Desk theme: install it from the plugin and keep it
 * up to date when the plugin updates.
 *
 * The theme's source ships in theme/support-desk-theme. Installing copies
 * it into the themes folder (via a temporary folder, then a swap, so a
 * failed copy never leaves a half-written theme) and writes a marker file
 * with a fingerprint of the files.
 *
 * Updates: once per plugin version, the bundled files are compared with the
 * marker. If they differ, the installed copy is replaced, unless someone
 * edited the installed files directly (their fingerprint no longer matches
 * the marker): then admins are asked first, so edits aren't lost. A theme
 * folder this plugin didn't install is never touched.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bundled theme installer and updater.
 */
class BST_Theme_Installer {

	const SLUG    = 'support-desk-theme';
	const MARKER  = '.support-desk-bundled.json';
	const SYNCED  = 'bst_theme_synced';   // Plugin version the theme was last checked for.
	const STATE   = 'bst_theme_update';   // Pending problem: array( type => edited|error, message ).

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_update' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_bst_install_theme', array( __CLASS__, 'handle_install' ) );
		add_action( 'admin_post_bst_activate_theme', array( __CLASS__, 'handle_activate' ) );
	}

	/**
	 * Bundled theme folder.
	 *
	 * @return string
	 */
	public static function source() {
		return BST_DIR . 'theme/' . self::SLUG;
	}

	/**
	 * Installed theme folder.
	 *
	 * @return string
	 */
	public static function target() {
		return trailingslashit( get_theme_root() ) . self::SLUG;
	}

	/**
	 * Whether the bundled theme is in this build.
	 *
	 * @return bool
	 */
	public static function bundled() {
		return is_readable( self::source() . '/style.css' );
	}

	/**
	 * Bundled theme version.
	 *
	 * @return string
	 */
	public static function bundled_version() {
		if ( ! self::bundled() ) {
			return '';
		}
		$data = get_file_data( self::source() . '/style.css', array( 'Version' => 'Version' ) );
		return (string) $data['Version'];
	}

	/**
	 * Whether the theme is installed.
	 *
	 * @return bool
	 */
	public static function installed() {
		return is_readable( self::target() . '/style.css' );
	}

	/**
	 * Whether it's the active theme (or the parent of it).
	 *
	 * @return bool
	 */
	public static function active() {
		return self::SLUG === get_template();
	}

	/**
	 * Marker written when we installed the theme, or null.
	 *
	 * @return array|null array( version, hash, installed_at ).
	 */
	public static function marker() {
		$file = self::target() . '/' . self::MARKER;
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return is_array( $data ) && ! empty( $data['hash'] ) ? $data : null;
	}

	/**
	 * Fingerprint of a theme folder: every file's path and contents
	 * (the marker itself excluded).
	 *
	 * @param string $dir Folder.
	 * @return string
	 */
	public static function hash( $dir ) {
		$dir   = untrailingslashit( wp_normalize_path( $dir ) );
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $dir ) ), '/' );
			if ( self::MARKER === $rel ) {
				continue;
			}
			$files[ $rel ] = md5_file( $file->getPathname() );
		}
		ksort( $files );
		return md5( (string) wp_json_encode( $files ) );
	}

	/**
	 * Whether file changes are allowed on this site.
	 *
	 * @return bool
	 */
	public static function can_write() {
		return wp_is_file_mod_allowed( 'bst_bundled_theme' );
	}

	/**
	 * Install (or replace) the theme from the bundle.
	 *
	 * @param bool $force Replace a copy we installed even if its files were edited.
	 * @return true|WP_Error
	 */
	public static function install( $force = false ) {
		if ( ! self::bundled() ) {
			return new WP_Error( 'bst_theme_missing', __( 'This copy of the plugin doesn\'t include the theme.', 'bonsai-support-tickets' ) );
		}
		if ( ! self::can_write() ) {
			return new WP_Error( 'bst_theme_no_mods', __( 'This site doesn\'t allow installing files (DISALLOW_FILE_MODS). Upload the theme folder by hand.', 'bonsai-support-tickets' ) );
		}

		$marker = self::marker();
		if ( self::installed() && ! $marker ) {
			return new WP_Error( 'bst_theme_foreign', __( 'A support-desk-theme folder already exists and wasn\'t installed by this plugin, so it was left alone.', 'bonsai-support-tickets' ) );
		}
		if ( $marker && ! $force && self::hash( self::target() ) !== $marker['hash'] ) {
			return new WP_Error( 'bst_theme_edited', __( 'The installed theme\'s files have been edited, so they weren\'t replaced.', 'bonsai-support-tickets' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'bst_theme_fs', __( 'Couldn\'t write to the themes folder. Upload the theme folder by hand.', 'bonsai-support-tickets' ) );
		}
		global $wp_filesystem;

		$root   = trailingslashit( get_theme_root() );
		$suffix = strtolower( wp_generate_password( 6, false, false ) );
		$tmp    = $root . self::SLUG . '-new-' . $suffix;
		$old    = $root . self::SLUG . '-old-' . $suffix;

		try {
			if ( ! $wp_filesystem->mkdir( $tmp, FS_CHMOD_DIR ) ) {
				return new WP_Error( 'bst_theme_fs', __( 'Couldn\'t write to the themes folder. Upload the theme folder by hand.', 'bonsai-support-tickets' ) );
			}
			$copied = copy_dir( self::source(), $tmp );
			if ( is_wp_error( $copied ) ) {
				$wp_filesystem->delete( $tmp, true );
				return $copied;
			}

			$version = self::bundled_version();
			$wp_filesystem->put_contents(
				$tmp . '/' . self::MARKER,
				(string) wp_json_encode(
					array(
						'version'      => $version,
						'hash'         => self::hash( $tmp ),
						'installed_at' => time(),
						'plugin'       => BST_VERSION,
					)
				),
				FS_CHMOD_FILE
			);

			// Swap: current → old, new → current, then remove old.
			if ( $wp_filesystem->exists( self::target() ) && ! $wp_filesystem->move( self::target(), $old ) ) {
				$wp_filesystem->delete( $tmp, true );
				return new WP_Error( 'bst_theme_fs', __( 'Couldn\'t replace the installed theme.', 'bonsai-support-tickets' ) );
			}
			if ( ! $wp_filesystem->move( $tmp, self::target() ) ) {
				if ( $wp_filesystem->exists( $old ) ) {
					$wp_filesystem->move( $old, self::target() );
				}
				$wp_filesystem->delete( $tmp, true );
				return new WP_Error( 'bst_theme_fs', __( 'Couldn\'t install the theme.', 'bonsai-support-tickets' ) );
			}
			if ( $wp_filesystem->exists( $old ) ) {
				$wp_filesystem->delete( $old, true );
			}
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': theme install failed: ' . $e->getMessage() );
			return new WP_Error( 'bst_theme_fs', __( 'The theme couldn\'t be installed. See the PHP error log.', 'bonsai-support-tickets' ) );
		}

		wp_clean_themes_cache();
		delete_option( self::STATE );

		/**
		 * The bundled theme was installed or updated.
		 *
		 * @param string $version Theme version.
		 */
		do_action( 'bst_theme_installed', $version );
		return true;
	}

	/**
	 * Once per plugin version: bring an installed copy up to date.
	 */
	public static function maybe_update() {
		if ( wp_doing_ajax() || get_option( self::SYNCED ) === BST_VERSION ) {
			return;
		}
		update_option( self::SYNCED, BST_VERSION, false );

		$marker = self::marker();
		if ( ! self::bundled() || ! self::installed() || ! $marker ) {
			return; // Not installed by us: nothing to update.
		}
		if ( self::hash( self::source() ) === $marker['hash'] ) {
			return; // Already current.
		}

		$result = self::install();
		if ( is_wp_error( $result ) ) {
			update_option(
				self::STATE,
				array(
					'type'    => 'bst_theme_edited' === $result->get_error_code() ? 'edited' : 'error',
					'message' => $result->get_error_message(),
					'version' => self::bundled_version(),
				),
				false
			);
			error_log( BST_PRODUCT_NAME . ': theme update skipped: ' . $result->get_error_message() );
		}
	}

	/**
	 * Notice when an update couldn't be applied.
	 */
	public static function notice() {
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || ! current_user_can( 'install_themes' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( 'themes' !== $screen->id && ! BST_Admin_UI::is_plugin_screen() ) ) {
			return;
		}

		$edited = 'edited' === $state['type'];
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Support Desk theme not updated', 'bonsai-support-tickets' ); ?></strong>
				&mdash;
				<?php
				echo esc_html(
					$edited
						/* translators: %s: theme version. */
						? sprintf( __( 'Version %s is ready, but the installed theme\'s files have been edited, so they weren\'t replaced. Updating replaces those edits (put changes in a child theme instead).', 'bonsai-support-tickets' ), $state['version'] )
						: $state['message']
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bst_install_theme">
				<input type="hidden" name="force" value="<?php echo $edited ? '1' : '0'; ?>">
				<?php wp_nonce_field( 'bst_install_theme' ); ?>
				<p><button type="submit" class="button"><?php echo esc_html( $edited ? __( 'Update anyway', 'bonsai-support-tickets' ) : __( 'Try again', 'bonsai-support-tickets' ) ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Permission check for the theme actions.
	 *
	 * @param string $nonce Nonce action.
	 * @param string $cap   Capability.
	 */
	private static function verify( $nonce, $cap ) {
		if ( ! current_user_can( $cap ) || ! current_user_can( 'bst_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to change themes.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Install (or update) from the setup card or the notice.
	 */
	public static function handle_install() {
		self::verify( 'bst_install_theme', 'install_themes' );
		$force  = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$result = self::install( $force );

		if ( is_wp_error( $result ) ) {
			BST_Admin_UI::flash( $result->get_error_message(), 'error' );
		} else {
			BST_Admin_UI::flash( self::active() ? __( 'The Support Desk theme is up to date.', 'bonsai-support-tickets' ) : __( 'The Support Desk theme is installed. Preview it or activate it below.', 'bonsai-support-tickets' ) );
		}
		wp_safe_redirect( BST_Admin_Settings::url( 'general' ) );
		exit;
	}

	/**
	 * Activate the installed theme.
	 */
	public static function handle_activate() {
		self::verify( 'bst_activate_theme', 'switch_themes' );

		$theme = wp_get_theme( self::SLUG );
		if ( ! $theme->exists() || $theme->errors() ) {
			BST_Admin_UI::flash( __( 'Install the theme first.', 'bonsai-support-tickets' ), 'error' );
		} else {
			switch_theme( self::SLUG );
			BST_Admin_UI::flash( __( 'The Support Desk theme is active. Use "Create starter pages" to build your help centre pages.', 'bonsai-support-tickets' ) );
		}
		wp_safe_redirect( BST_Admin_Settings::url( 'general' ) );
		exit;
	}

	/**
	 * Theme actions for the setup card: install, or preview + activate.
	 */
	public static function render_actions() {
		if ( ! self::bundled() || self::active() || ! current_user_can( 'switch_themes' ) ) {
			return;
		}
		?>
		<div class="bonsai-ui-actions bst-setup__theme">
			<?php if ( ! self::installed() ) : ?>
				<?php if ( current_user_can( 'install_themes' ) && self::can_write() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bst_install_theme">
						<?php wp_nonce_field( 'bst_install_theme' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Install the Support Desk theme', 'bonsai-support-tickets' ); ?></button>
					</form>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Optional: a help centre, request form and client portal styled with your brand. Installing doesn\'t change your site until you activate it.', 'bonsai-support-tickets' ); ?></p>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'customize.php?theme=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Preview the theme', 'bonsai-support-tickets' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bst_activate_theme">
					<?php wp_nonce_field( 'bst_activate_theme' ); ?>
					<button type="submit" class="button" data-bst-confirm="<?php esc_attr_e( 'Switch your site to the Support Desk theme? Your current theme stays installed, so you can switch back under Appearance → Themes.', 'bonsai-support-tickets' ); ?>"><?php esc_html_e( 'Activate the theme', 'bonsai-support-tickets' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
