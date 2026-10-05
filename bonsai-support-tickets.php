<?php
/**
 * Plugin Name: Bonsai Support Tickets
 * Plugin URI:  https://bonsaidigitalcollective.co.uk/
 * Description: Support ticketing for The Bonsai Digital Collective — client portal, agent assignment, internal notes, email in/out and a help centre.
 * Version:     0.1.0
 * Author:      The Bonsai Digital Collective
 * Author URI:  https://bonsaidigitalcollective.co.uk/
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bonsai-support-tickets
 * Domain Path: /languages
 *
 * @package Bonsai_Support_Tickets
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Bonsai Support Tickets is installed more than once. Please delete the duplicate plugin folder.', 'bonsai-support-tickets' ) . '</p></div>';
		}
	);
	return;
}

define( 'BST_VERSION', '0.1.0' );
define( 'BST_DB_VERSION', '3' ); // 2: bst_approve_clients capability. 3: Client records.
define( 'BST_FILE', __FILE__ );
define( 'BST_DIR', plugin_dir_path( __FILE__ ) );
define( 'BST_URL', plugin_dir_url( __FILE__ ) );

/*
|--------------------------------------------------------------------------
| Plugin Update Checker (via Composer)
|--------------------------------------------------------------------------
| vendor/ is committed. composer.json sets a fixed autoloader suffix
| (BonsaiSupportTickets) so this plugin's Composer autoloader never clashes
| with another Bonsai plugin's — see bonsai-code-injector 1.1.2.
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
require_once BST_DIR . 'includes/class-bst-post-types.php';
require_once BST_DIR . 'includes/class-bst-activity.php';
require_once BST_DIR . 'includes/class-bst-messages.php';
require_once BST_DIR . 'includes/class-bst-attachments.php';
require_once BST_DIR . 'includes/class-bst-tickets.php';
require_once BST_DIR . 'includes/class-bst-clients.php';
require_once BST_DIR . 'includes/class-bst-companies.php';

// Email.
require_once BST_DIR . 'includes/class-bst-mailer.php';
require_once BST_DIR . 'includes/class-bst-inbound.php';
require_once BST_DIR . 'includes/class-bst-slack.php';
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
require_once BST_DIR . 'includes/admin/class-bst-admin-signups.php';
require_once BST_DIR . 'includes/admin/class-bst-admin-companies.php';

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
		BST_Cron::init();
		BST_Forms::init();
		BST_Registration::init();
		BST_Frontend::init();

		if ( is_admin() ) {
			BST_Admin_UI::init();
			BST_Admin_Tickets::init();
			BST_Admin_Settings::init();
			BST_Admin_Signups::init();
			BST_Admin_Companies::init();
		}
	}
);
