<?php
/**
 * Template loader with theme overrides, WooCommerce-style.
 *
 * A theme overrides any file in this plugin's templates/ folder by copying
 * it to {theme}/support-desk/{same path}. Child theme first, then parent.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template loader.
 */
class BST_Template {

	const THEME_DIR = 'support-desk/';

	/**
	 * Find a template: theme override, else the plugin's default.
	 *
	 * @param string $name Relative path, e.g. 'my-tickets.php' or 'emails/layout.php'.
	 * @return string Absolute path, or '' if not found.
	 */
	public static function locate( $name ) {
		$name = ltrim( str_replace( '..', '', $name ), '/' );

		$theme = locate_template( self::THEME_DIR . $name );
		$path  = $theme ? $theme : BST_DIR . 'templates/' . $name;

		/**
		 * Filter the resolved template path.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Requested template.
		 */
		$path = apply_filters( 'bst_template_path', $path, $name );

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Output a template. $args are extracted into the template's scope.
	 *
	 * @param string $name Template name.
	 * @param array  $args Variables for the template.
	 */
	public static function render( $name, array $args = array() ) {
		$path = self::locate( $name );
		if ( ! $path ) {
			error_log( BST_PRODUCT_NAME . ': template not found: ' . $name );
			return;
		}
		extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope, as in core's load_template().
		include $path;
	}

	/**
	 * Render a template into a string.
	 *
	 * @param string $name Template name.
	 * @param array  $args Variables for the template.
	 * @return string
	 */
	public static function capture( $name, array $args = array() ) {
		ob_start();
		self::render( $name, $args );
		return (string) ob_get_clean();
	}
}
