<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests (tests/unit) cover the email parsing classes, which have no
 * WordPress dependencies — they only need ABSPATH defined.
 *
 * Integration tests (tests/integration) need the WordPress test suite. Set
 * WP_TESTS_DIR to it (see `wp scaffold plugin-tests`) and run:
 * vendor/bin/phpunit --testsuite integration
 *
 * @package Support_Desk
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$bst_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $bst_tests_dir && file_exists( $bst_tests_dir . '/includes/functions.php' ) ) {
	require_once $bst_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		function () {
			require dirname( __DIR__ ) . '/bonsai-support-tickets.php';
		}
	);

	require $bst_tests_dir . '/includes/bootstrap.php';

	// Tables, roles and terms, as on activation.
	BST_Install::activate();
	return;
}

// Unit-only mode.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'BST_PRODUCT_NAME' ) ) {
	define( 'BST_PRODUCT_NAME', 'Support Desk' );
}

require_once dirname( __DIR__ ) . '/includes/class-bst-mime-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-bst-reply-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-bst-imap-client.php';
require_once dirname( __DIR__ ) . '/includes/class-bst-appearance.php'; // Contrast maths only; the rest needs WordPress.
