<?php
/**
 * Plugin Name: Support Desk
 * Description: Support ticketing for your clients: a branded client portal, email in and out, agent assignment, internal notes, client records and a help centre.
 * Version:     0.1.0
 * Author:      Support Desk
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bonsai-support-tickets
 * Domain Path: /languages
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Duplicate install guard
|--------------------------------------------------------------------------
| A second copy (e.g. a GitHub "Source code" zip unpacked alongside the real
| folder) would fatal on redeclared classes. Bail and tell the admin instead.
*/
if ( defined( 'BST_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			/* translators: %s: product name. */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( '%s is installed more than once. Please delete the duplicate plugin folder.', 'bonsai-support-tickets' ), BST_PRODUCT_NAME ) ) . '</p></div>';
		}
	);
	return;
}

/*
|--------------------------------------------------------------------------
| Product identity
|--------------------------------------------------------------------------
| The product name shown in wp-admin and logs. Everything a buyer's clients
| see (portal, emails, login) uses the buyer's own branding from Settings
| instead. Rename the product here (and in the header above) before launch.
*/
define( 'BST_PRODUCT_NAME', 'Support Desk' );
define( 'BST_PRODUCT_URL', '' ); // Product docs/help site; '' hides the link.
if ( ! defined( 'BST_MONITOR_PROMO_URL' ) ) {
	define( 'BST_MONITOR_PROMO_URL', '' ); // "Get uptime monitoring" link on Settings → Uptime monitoring; '' hides it.
}

define( 'BST_VERSION', '0.1.0' );
define( 'BST_DB_VERSION', '6' ); // 2: bst_approve_clients capability. 3: Client records. 4: neutral defaults. 5: canned responses. 6: time tracking.
define( 'BST_FILE', __FILE__ );
define( 'BST_DIR', plugin_dir_path( __FILE__ ) );
define( 'BST_URL', plugin_dir_url( __FILE__ ) );

/*
|--------------------------------------------------------------------------
| Plugin Update Checker (via Composer)
|--------------------------------------------------------------------------
| vendor/ is committed. composer.json sets a fixed autoloader suffix
| so this plugin's Composer autoloader never clashes with another plugin's.
| The GitHub update source is temporary: licensed updates replace it.
*/
if ( file_exists( BST_DIR . 'vendor/autoload.php' ) ) {
	require_once BST_DIR . 'vendor/autoload.php';
}

if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
	$bst_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Bonsai-Systems/bonsai-support-tickets',
		__FILE__,
		'bonsai-support-tickets',
		6
	);
	$bst_update_checker->setBranch( 'main' );
	$bst_update_checker->getVcsApi()->enableReleaseAssets();
}

// Core — no WordPress hooks of their own, safe to load anywhere (incl. unit tests).
require_once BST_DIR . 'includes/class-bst-mime-parser.php';
require_once BST_DIR . 'includes/class-bst-reply-parser.php';
require_once BST_DIR . 'includes/class-bst-imap-client.php';

// Data and domain.
require_once BST_DIR . 'includes/class-bst-settings.php';
require_once BST_DIR . 'includes/class-bst-appearance.php';
require_once BST_DIR . 'includes/class-bst-install.php';

// Development builds only: keeps the original site's branding when it
// upgrades to neutral defaults. Excluded from the product build.
if ( file_exists( BST_DIR . 'includes/legacy/class-bst-legacy-defaults.php' ) ) {
	require_once BST_DIR . 'includes/legacy/class-bst-legacy-defaults.php';
}
// Development builds only: moves the original site from its ACF theme to the bundled theme.
if ( file_exists( BST_DIR . 'includes/legacy/class-bst-legacy-theme.php' ) ) {
	require_once BST_DIR . 'includes/legacy/class-bst-legacy-theme.php';
}
require_once BST_DIR . 'includes/class-bst-post-types.php';
require_once BST_DIR . 'includes/class-bst-activity.php';
require_once BST_DIR . 'includes/class-bst-messages.php';
require_once BST_DIR . 'includes/class-bst-attachments.php';
require_once BST_DIR . 'includes/class-bst-tickets.php';
require_once BST_DIR . 'includes/class-bst-clients.php';
require_once BST_DIR . 'includes/class-bst-companies.php';
require_once BST_DIR . 'includes/class-bst-canned.php';
require_once BST_DIR . 'includes/class-bst-duration.php';
require_once BST_DIR . 'includes/class-bst-time.php';
require_once BST_DIR . 'includes/class-bst-business-hours.php';
require_once BST_DIR . 'includes/class-bst-sla.php';

// Email.
require_once BST_DIR . 'includes/class-bst-mailer.php';
require_once BST_DIR . 'includes/class-bst-inbound.php';
require_once BST_DIR . 'includes/class-bst-slack.php';
require_once BST_DIR . 'includes/class-bst-monitoring.php';
require_once BST_DIR . 'includes/class-bst-cron.php';

// Front end.
require_once BST_DIR . 'includes/class-bst-template.php';
require_once BST_DIR . 'includes/class-bst-forms.php';
require_once BST_DIR . 'includes/class-bst-registration.php';
require_once BST_DIR . 'includes/class-bst-frontend.php';
require_once BST_DIR . 'includes/functions.php';

// Admin.
require_once BST_DIR . 'includes/admin/class-bst-admin-ui.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-tickets.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-settings.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-setup.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-signups.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-companies.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-canned.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-time.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-sla.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-overview.php';

register_activation_hook( __FILE__, array( 'BST_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BST_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'bonsai-support-tickets', false, dirname( plugin_basename( BST_FILE ) ) . '/languages' );

		BST_Install::init();
		BST_Post_Types::init();
		BST_Tickets::init();
		BST_Clients::init();
		BST_Attachments::init();
		BST_Mailer::init();
		BST_Slack::init();
		BST_Monitoring::init();
		BST_Time::init();
		BST_SLA::init();
		BST_Cron::init();
		BST_Forms::init();
		BST_Registration::init();
		BST_Frontend::init();

		// Not admin-only: it also sets the login redirect and the front-end admin bar.
		BST_Admin_Overview::init();

		if ( is_admin() ) {
			BST_Admin_UI::init();
			BST_Admin_Tickets::init();
			BST_Admin_Settings::init();
			BST_Admin_Setup::init();
			BST_Admin_Signups::init();
			BST_Admin_Companies::init();
			BST_Admin_Canned::init();
			BST_Admin_Time::init();
			BST_Admin_SLA::init();
		}
	}
);
