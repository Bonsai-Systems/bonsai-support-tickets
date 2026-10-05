<?php
/**
 * Fonts, styles and scripts.
 *
 * Lightweight: no sliders, animation libraries, Bootstrap or icon fonts.
 * Fonts are self-hosted (assets/fonts, OFL).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Version string from a file's modified time (cache busting).
 *
 * @param string $rel Path relative to the theme.
 * @return string
 */
function bsup_asset_version( $rel ) {
	$path = BSUP_DIR . '/' . $rel;
	return file_exists( $path ) ? (string) filemtime( $path ) : BSUP_VERSION;
}

/**
 * Front-end assets.
 */
function bsup_enqueue_assets() {
	// Self-hosted fonts: no visitor IPs sent to Google (GDPR).
	wp_enqueue_style( 'bsup-fonts', BSUP_URI . '/assets/css/fonts.css', array(), bsup_asset_version( 'assets/css/fonts.css' ) );

	wp_enqueue_style( 'bsup-base', BSUP_URI . '/assets/css/base.css', array( 'bsup-fonts' ), bsup_asset_version( 'assets/css/base.css' ) );
	wp_enqueue_style( 'bsup-header', BSUP_URI . '/assets/css/header.css', array( 'bsup-base' ), bsup_asset_version( 'assets/css/header.css' ) );
	wp_enqueue_style( 'bsup-footer', BSUP_URI . '/assets/css/footer.css', array( 'bsup-base' ), bsup_asset_version( 'assets/css/footer.css' ) );

	// Help centre styles: the help templates, plus any page using a help
	// block (enqueued from inc/blocks.php).
	wp_register_style( 'bsup-help', BSUP_URI . '/assets/css/help.css', array( 'bsup-base' ), bsup_asset_version( 'assets/css/help.css' ) );
	if ( is_singular( 'bst_article' ) || is_post_type_archive( 'bst_article' ) || is_tax( 'bst_article_topic' ) || is_search() || is_404() || is_home() || is_archive() ) {
		wp_enqueue_style( 'bsup-help' );
	}

	wp_enqueue_script( 'bsup-main', BSUP_URI . '/assets/js/main.js', array( 'jquery' ), bsup_asset_version( 'assets/js/main.js' ), true );
}
add_action( 'wp_enqueue_scripts', 'bsup_enqueue_assets' );

/**
 * Preload the two Latin font files used above the fold, so headings don't
 * swap fonts after first paint (CLS).
 */
function bsup_preload_fonts() {
	foreach ( array( 'plus-jakarta-sans-latin-wght-normal', 'bricolage-grotesque-latin-wght-normal' ) as $file ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "
",
			esc_url( BSUP_URI . '/assets/fonts/' . $file . '.woff2' )
		);
	}
}
add_action( 'wp_head', 'bsup_preload_fonts', 1 );

/**
 * Flag JS support as early as possible, so scroll-reveal content is only
 * hidden when the script that reveals it can run. (the original theme hid
 * content without this guard — if main.js failed, sections stayed blank.)
 */
function bsup_js_flag() {
	echo "<script>document.documentElement.classList.add('js');</script>\n";
}
add_action( 'wp_head', 'bsup_js_flag', 0 );

/**
 * Editor styles. The block editor (pages) gets the front-end fonts, tokens
 * and every module stylesheet, so the live block previews look like the
 * site. The classic editor (help articles) gets the reading styles only.
 *
 * @param WP_Screen $screen Current screen.
 */
function bsup_editor_styles( $screen ) {
	if ( ! $screen instanceof WP_Screen || 'post' !== $screen->base ) {
		return;
	}

	if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		$styles = array( 'assets/css/fonts.css', 'assets/css/base.css', 'assets/css/help.css' );
		foreach ( glob( BSUP_DIR . '/assets/css/modules/*.css' ) as $file ) {
			$styles[] = 'assets/css/modules/' . basename( $file );
		}
		$styles[] = 'assets/css/editor-blocks.css';
		add_editor_style( $styles );
		return;
	}

	add_editor_style( 'assets/css/editor.css' );
}
add_action( 'current_screen', 'bsup_editor_styles' );

/**
 * Block editor: the plugin's front-end styles (ticket blocks) and the
 * brand colour tokens.
 *
 * @param array $settings Editor settings.
 * @return array
 */
function bsup_block_editor_settings( $settings ) {
	$tokens = bsup_color_tokens();
	if ( '' !== $tokens ) {
		$settings['styles'][] = array( 'css' => ':root{' . $tokens . '}' );
	}
	$file = defined( 'BST_DIR' ) ? BST_DIR . 'assets/frontend/bst-frontend.css' : '';
	if ( $file && defined( 'BST_URL' ) && file_exists( $file ) ) {
		$settings['styles'][] = array(
			'css'     => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			'baseURL' => BST_URL . 'assets/frontend/bst-frontend.css',
		);
		if ( class_exists( 'BST_Appearance' ) ) {
			$settings['styles'][] = array( 'css' => BST_Appearance::inline_css() );
		}
	}
	return $settings;
}
add_filter( 'block_editor_settings_all', 'bsup_block_editor_settings' );
