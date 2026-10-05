<?php
/**
 * Uninstall.
 *
 * Settings, cron events and roles are always removed. Tickets, messages,
 * attachments and help articles are client records, so they are only
 * deleted when wp-config.php has:
 *
 * define( 'BST_REMOVE_ALL_DATA', true );
 *
 * @package Support_Desk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'bst_poll_mailbox' );
wp_clear_scheduled_hook( 'bst_daily_maintenance' );

foreach ( array( 'bst_settings', 'bst_inbound_status', 'bst_db_version', 'bst_default_terms_created', 'bst_monitor_log', 'bst_canned_seeded' ) as $bst_option ) {
	delete_option( $bst_option );
}
delete_transient( 'bst_menu_count' );
delete_transient( 'bst_inbound_lock' );

// Capabilities added to administrators, and the agent role. The client role
// is only removed with the data, so client accounts don't silently lose their role.
$bst_caps = array(
	'bst_view_all_tickets',
	'bst_reply_tickets',
	'bst_add_internal_notes',
	'bst_assign_tickets',
	'bst_approve_clients',
	'bst_manage_settings',
	'bst_log_time',
	'bst_manage_time',
	'edit_bst_tickets',
	'edit_others_bst_tickets',
	'edit_published_bst_tickets',
	'edit_private_bst_tickets',
	'publish_bst_tickets',
	'read_private_bst_tickets',
	'delete_bst_tickets',
	'delete_others_bst_tickets',
	'delete_published_bst_tickets',
	'delete_private_bst_tickets',
	'edit_bst_canned_responses',
	'edit_others_bst_canned_responses',
	'edit_published_bst_canned_responses',
	'edit_private_bst_canned_responses',
	'publish_bst_canned_responses',
	'read_private_bst_canned_responses',
	'delete_bst_canned_responses',
	'delete_others_bst_canned_responses',
	'delete_published_bst_canned_responses',
	'delete_private_bst_canned_responses',
);
$bst_admin = get_role( 'administrator' );
if ( $bst_admin ) {
	foreach ( $bst_caps as $bst_cap ) {
		$bst_admin->remove_cap( $bst_cap );
	}
}
remove_role( 'bst_agent' );

if ( ! defined( 'BST_REMOVE_ALL_DATA' ) || true !== BST_REMOVE_ALL_DATA ) {
	return;
}

// Posts (tickets, clients, canned responses and help articles) and their meta/terms.
$bst_post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('bst_ticket','bst_company','bst_canned','bst_article')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $bst_post_ids as $bst_post_id ) {
	wp_delete_post( (int) $bst_post_id, true );
}

foreach ( array( 'bst_ticket_type', 'bst_article_topic', 'bst_partner', 'bst_plan', 'bst_canned_tag' ) as $bst_taxonomy ) {
	$bst_terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $bst_taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $bst_terms as $bst_term_id ) {
		wp_delete_term( (int) $bst_term_id, $bst_taxonomy );
	}
}

// Custom tables.
foreach ( array( 'bst_messages', 'bst_attachments', 'bst_activity', 'bst_time_entries' ) as $bst_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$bst_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Private files.
$bst_uploads = wp_upload_dir( null, false );
$bst_dir     = trailingslashit( $bst_uploads['basedir'] ) . 'bst-private';
if ( is_dir( $bst_dir ) ) {
	$bst_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $bst_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $bst_files as $bst_file ) {
		if ( $bst_file->isDir() ) {
			rmdir( $bst_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $bst_file->getPathname() );
		}
	}
	rmdir( $bst_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

delete_option( 'bst_ref_counter' );

// Client fields on user accounts (the accounts themselves are kept).
foreach ( array( 'bst_client_name', 'bst_company_id', 'bst_phone', 'bst_pending', 'bst_registered_via' ) as $bst_meta_key ) {
	delete_metadata( 'user', 0, $bst_meta_key, '', true );
}

remove_role( 'bst_client' );
