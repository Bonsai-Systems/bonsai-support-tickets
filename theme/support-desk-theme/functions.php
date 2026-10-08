<?php
/**
 * Support Desk theme — loader.
 *
 * One file per concern in inc/. Order matters only where noted.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

define( 'BSUP_VERSION', '0.3.0' );
define( 'BSUP_DIR', get_template_directory() );
define( 'BSUP_URI', get_template_directory_uri() );

$bsup_includes = array(
	'helpers',       // ACF check, settings, icons, background classes, iframe kses. Load first — others use these.
	'setup',         // Theme supports, menus, image sizes.
	'cleanup',       // Head cleanup, emojis, XML-RPC, comments, block editor off.
	'acf',           // Site settings page, ACF JSON paths, default modules, missing-ACF notice.
	'support',       // Support Desk plugin integration: URLs, brand, colours, account link, login screen.
	'page-builder',  // Flexible content renderer + head-time module CSS.
	'assets',        // Fonts, styles, scripts.
	'cpt-team',      // Team post type for the Meet the team module.
	'starter-pages', // One-click starter pages, menu and plugin settings.
);

foreach ( $bsup_includes as $bsup_include ) {
	$bsup_file = BSUP_DIR . '/inc/' . $bsup_include . '.php';
	if ( file_exists( $bsup_file ) ) {
		require_once $bsup_file;
	} else {
		error_log( 'Support Desk theme: missing include ' . $bsup_file );
	}
}
