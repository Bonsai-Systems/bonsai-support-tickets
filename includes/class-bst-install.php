<?php
/**
 * Activation, upgrades, roles and capabilities, custom tables.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
class BST_Install {

	/**
	 * Capabilities given to agents (and administrators).
	 *
	 * The bst_ticket post type uses capability_type bst_ticket/bst_tickets,
	 * so the primitive post caps are generated from that.
	 */
	const AGENT_CAPS = array(
		'bst_view_all_tickets',
		'bst_reply_tickets',
		'bst_add_internal_notes',
		'bst_assign_tickets',
		'edit_bst_tickets',
		'edit_others_bst_tickets',
		'edit_published_bst_tickets',
		'publish_bst_tickets',
		'read_private_bst_tickets',
	);

	/**
	 * Extra capabilities only administrators get.
	 */
	const ADMIN_CAPS = array(
		'bst_manage_settings',
		'delete_bst_tickets',
		'delete_others_bst_tickets',
		'delete_published_bst_tickets',
		'delete_private_bst_tickets',
		'edit_private_bst_tickets',
	);

	/**
	 * Runtime hooks: upgrade check.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		self::create_roles();
		self::create_private_dir();

		// Ref counter. autoload off; incremented atomically in BST_Tickets::next_ref_number().
		add_option( 'bst_ref_counter', 1000, '', false );

		BST_Post_Types::register();
		BST_Post_Types::create_default_terms();
		flush_rewrite_rules();

		BST_Cron::schedule();

		update_option( 'bst_db_version', BST_DB_VERSION, false );
	}

	/**
	 * Deactivation hook. Data and roles are left alone — see uninstall.php.
	 */
	public static function deactivate() {
		BST_Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Re-run install steps after a plugin update changes the schema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'bst_db_version' ) === BST_DB_VERSION ) {
			return;
		}
		self::create_tables();
		self::create_roles();
		self::create_private_dir();
		update_option( 'bst_db_version', BST_DB_VERSION, false );
	}

	/**
	 * Create or update the custom tables.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		$sql = "CREATE TABLE {$wpdb->prefix}bst_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			author_name varchar(190) NOT NULL DEFAULT '',
			author_email varchar(190) NOT NULL DEFAULT '',
			visibility varchar(20) NOT NULL DEFAULT 'external',
			source varchar(20) NOT NULL DEFAULT 'web',
			body longtext NOT NULL,
			email_message_id varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id),
			KEY email_message_id (email_message_id(191))
		) $charset;
		CREATE TABLE {$wpdb->prefix}bst_attachments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL,
			message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_name varchar(255) NOT NULL DEFAULT '',
			stored_path varchar(255) NOT NULL DEFAULT '',
			mime_type varchar(100) NOT NULL DEFAULT '',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id),
			KEY message_id (message_id)
		) $charset;
		CREATE TABLE {$wpdb->prefix}bst_activity (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(50) NOT NULL DEFAULT '',
			old_value varchar(255) NOT NULL DEFAULT '',
			new_value varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id)
		) $charset;";

		dbDelta( $sql );
	}

	/**
	 * Add the Support Client and Support Agent roles, and agent/admin caps.
	 */
	public static function create_roles() {
		// Clients never use wp-admin for tickets — everything is front end.
		add_role(
			'bst_client',
			__( 'Support Client', 'bonsai-support-tickets' ),
			array(
				'read'              => true,
				'bst_submit_ticket' => true,
			)
		);

		add_role(
			'bst_agent',
			__( 'Support Agent', 'bonsai-support-tickets' ),
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);

		$agent = get_role( 'bst_agent' );
		if ( $agent ) {
			foreach ( self::AGENT_CAPS as $cap ) {
				$agent->add_cap( $cap );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::AGENT_CAPS, self::ADMIN_CAPS ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Private attachment folder. Files are stored with random names and no
	 * extension and are only ever served through BST_Attachments::serve().
	 *
	 * The .htaccess only protects Apache/LiteSpeed. On nginx add:
	 * location ^~ /wp-content/uploads/bst-private/ { deny all; }
	 */
	public static function create_private_dir() {
		$dir = BST_Attachments::base_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			error_log( 'Bonsai Support Tickets: could not create private upload folder ' . $dir );
			return;
		}

		$files = array(
			'.htaccess'  => "# Bonsai Support Tickets — never serve these files directly.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);

		foreach ( $files as $name => $contents ) {
			if ( ! file_exists( $dir . $name ) ) {
				file_put_contents( $dir . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}
}
