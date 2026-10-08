<?php
/**
 * Private attachments.
 *
 * Files live in uploads/bst-private/YYYY/MM/ under random names with a
 * .bin extension (so nothing in there can ever execute), and are only
 * served through serve(), after a permission check.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Attachment storage and download.
 */
class BST_Attachments {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bst_download', array( __CLASS__, 'serve' ) );
		add_action( 'admin_post_nopriv_bst_download', array( __CLASS__, 'serve' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bst_attachments';
	}

	/**
	 * Absolute path of the private folder, with trailing slash.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'bst-private/';
	}

	/**
	 * Allowed extensions => MIME types. Filterable.
	 *
	 * @return array
	 */
	public static function allowed_types() {
		return apply_filters(
			'bst_allowed_attachment_types',
			array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'gif'      => 'image/gif',
				'webp'     => 'image/webp',
				'pdf'      => 'application/pdf',
				'txt'      => 'text/plain',
				'csv'      => 'text/csv',
				'doc'      => 'application/msword',
				'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'xls'      => 'application/vnd.ms-excel',
				'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'zip'      => 'application/zip',
				'mp4'      => 'video/mp4',
				'mov'      => 'video/quicktime',
			)
		);
	}

	/**
	 * Comma-separated extension list for an <input accept> attribute.
	 *
	 * @return string
	 */
	public static function accept_attribute() {
		$exts = array();
		foreach ( array_keys( self::allowed_types() ) as $pattern ) {
			foreach ( explode( '|', $pattern ) as $ext ) {
				$exts[] = '.' . $ext;
			}
		}
		return implode( ',', $exts );
	}

	/**
	 * Max size of one file in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return (int) BST_Settings::get( 'max_upload_mb' ) * MB_IN_BYTES;
	}

	/**
	 * Turn a multi-file $_FILES entry into a list of single-file arrays,
	 * skipping empty slots.
	 *
	 * @param array $files A $_FILES[ name ] entry with name="field[]".
	 * @return array[]
	 */
	public static function normalise_files( $files ) {
		$out = array();
		if ( empty( $files['name'] ) ) {
			return $out;
		}

		if ( ! is_array( $files['name'] ) ) {
			return UPLOAD_ERR_NO_FILE === (int) $files['error'] ? $out : array( $files );
		}

		foreach ( $files['name'] as $i => $name ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
		}
		return $out;
	}

	/**
	 * Check a set of uploaded files before anything is saved.
	 *
	 * @param array[] $files Output of normalise_files().
	 * @return true|WP_Error
	 */
	public static function validate_uploads( array $files ) {
		$max_files = (int) BST_Settings::get( 'max_upload_files' );
		if ( count( $files ) > $max_files ) {
			/* translators: %d: max number of files. */
			return new WP_Error( 'bst_too_many_files', sprintf( __( 'You can attach up to %d files.', 'bonsai-support-tickets' ), $max_files ) );
		}

		foreach ( $files as $file ) {
			$name = sanitize_file_name( $file['name'] );

			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				/* translators: %s: file name. */
				return new WP_Error( 'bst_upload_failed', sprintf( __( '%s did not upload. Please try again.', 'bonsai-support-tickets' ), $name ) );
			}

			if ( (int) $file['size'] > self::max_bytes() ) {
				/* translators: 1: file name, 2: max size in MB. */
				return new WP_Error( 'bst_file_too_big', sprintf( __( '%1$s is too big. The limit is %2$d MB per file.', 'bonsai-support-tickets' ), $name, (int) BST_Settings::get( 'max_upload_mb' ) ) );
			}

			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_types() );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
				/* translators: %s: file name. */
				return new WP_Error( 'bst_file_type', sprintf( __( '%s is not an allowed file type.', 'bonsai-support-tickets' ), $name ) );
			}
		}

		return true;
	}

	/**
	 * Save validated uploads. Call validate_uploads() first.
	 *
	 * @param array[] $files      Output of normalise_files().
	 * @param int     $ticket_id  Ticket ID.
	 * @param int     $message_id Message ID.
	 * @return int[] Attachment IDs.
	 */
	public static function store_uploads( array $files, $ticket_id, $message_id ) {
		$ids = array();
		foreach ( $files as $file ) {
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_types() );
			$path  = self::new_path();
			if ( ! $path || ! move_uploaded_file( $file['tmp_name'], self::base_dir() . $path ) ) {
				error_log( BST_PRODUCT_NAME . ': could not store upload ' . $file['name'] );
				continue;
			}
			$ids[] = self::insert_row( $ticket_id, $message_id, $file['name'], $path, $check['type'], (int) $file['size'] );
		}
		return array_filter( $ids );
	}

	/**
	 * Save an attachment from an inbound email. Silently skips files that
	 * are too big or of a type we don't allow (logged).
	 *
	 * @param string $filename   Original file name.
	 * @param string $content    Raw (decoded) file content.
	 * @param int    $ticket_id  Ticket ID.
	 * @param int    $message_id Message ID.
	 * @return int|false Attachment ID.
	 */
	public static function store_raw( $filename, $content, $ticket_id, $message_id ) {
		$size = strlen( $content );
		if ( 0 === $size || $size > self::max_bytes() ) {
			error_log( BST_PRODUCT_NAME . ': skipped email attachment ' . $filename . ' (' . $size . ' bytes)' );
			return false;
		}

		$type = wp_check_filetype( $filename, self::allowed_types() );
		if ( empty( $type['type'] ) ) {
			error_log( BST_PRODUCT_NAME . ': skipped email attachment ' . $filename . ' (type not allowed)' );
			return false;
		}

		// Content sniff where available: the extension must agree with the bytes.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$real  = $finfo ? finfo_buffer( $finfo, $content ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( $real && ! self::mime_matches( $real, $type['type'] ) ) {
				error_log( BST_PRODUCT_NAME . ': skipped email attachment ' . $filename . ' (content is ' . $real . ')' );
				return false;
			}
		}

		$path = self::new_path();
		if ( ! $path || false === file_put_contents( self::base_dir() . $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			error_log( BST_PRODUCT_NAME . ': could not store email attachment ' . $filename );
			return false;
		}

		return self::insert_row( $ticket_id, $message_id, $filename, $path, $type['type'], $size );
	}

	/**
	 * Loose MIME comparison for sniffed content. Office files sniff as zip
	 * or octet-stream, and CSV as text/plain, so those are allowed through.
	 *
	 * @param string $real     Sniffed type.
	 * @param string $expected Type from the extension.
	 * @return bool
	 */
	private static function mime_matches( $real, $expected ) {
		if ( $real === $expected ) {
			return true;
		}
		$loose = array( 'application/zip', 'application/octet-stream', 'text/plain', 'application/x-empty', 'application/CDFV2', 'application/vnd.ms-office' );
		if ( in_array( $real, $loose, true ) ) {
			return ! str_starts_with( $expected, 'image/' );
		}
		// Same top-level type (e.g. text/x-c vs text/plain).
		return strtok( $real, '/' ) === strtok( $expected, '/' ) && ! str_starts_with( $expected, 'image/' );
	}

	/**
	 * Relative path for a new file, creating the month folder.
	 *
	 * @return string|false
	 */
	private static function new_path() {
		$sub = gmdate( 'Y/m' ) . '/';
		if ( ! wp_mkdir_p( self::base_dir() . $sub ) ) {
			return false;
		}
		return $sub . bin2hex( random_bytes( 16 ) ) . '.bin';
	}

	/**
	 * Insert an attachment row.
	 *
	 * @param int    $ticket_id  Ticket ID.
	 * @param int    $message_id Message ID.
	 * @param string $name       Original name.
	 * @param string $path       Path relative to base_dir().
	 * @param string $mime       MIME type.
	 * @param int    $size       Bytes.
	 * @return int|false
	 */
	private static function insert_row( $ticket_id, $message_id, $name, $path, $mime, $size ) {
		global $wpdb;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'ticket_id'   => absint( $ticket_id ),
				'message_id'  => absint( $message_id ),
				'file_name'   => substr( sanitize_file_name( $name ), 0, 255 ),
				'stored_path' => $path,
				'mime_type'   => substr( $mime, 0, 100 ),
				'file_size'   => absint( $size ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Attachments for a set of messages, grouped by message ID.
	 *
	 * @param int[] $message_ids Message IDs.
	 * @return array<int,object[]>
	 */
	public static function for_messages( array $message_ids ) {
		global $wpdb;
		$message_ids = array_filter( array_map( 'absint', $message_ids ) );
		if ( ! $message_ids ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE message_id IN ({$placeholders}) ORDER BY id ASC", $message_ids ) );

		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ (int) $row->message_id ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Download URL. Goes through admin-post.php so serve() can check permissions.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function url( $attachment_id ) {
		return add_query_arg(
			array(
				'action' => 'bst_download',
				'file'   => absint( $attachment_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Stream a file to a user who is allowed to see it.
	 */
	public static function serve() {
		global $wpdb;

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$id    = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, permission checked below.
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		if ( ! $file || ! BST_Tickets::user_can_view( (int) $file->ticket_id ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}

		// Files attached to internal notes are agent-only.
		$message = BST_Messages::get( (int) $file->message_id );
		if ( $message && BST_Messages::INTERNAL === $message->visibility && ! current_user_can( 'bst_view_all_tickets' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}

		// Resolve and make sure the path can't escape the private folder.
		$base = realpath( self::base_dir() );
		$path = realpath( self::base_dir() . $file->stored_path );
		if ( ! $base || ! $path || ! str_starts_with( $path, $base ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'bonsai-support-tickets' ), '', array( 'response' => 404 ) );
		}

		$inline = str_starts_with( $file->mime_type, 'image/' ) || 'application/pdf' === $file->mime_type;

		nocache_headers();
		header( 'Content-Type: ' . ( $file->mime_type ? $file->mime_type : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $file->file_name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Delete all files and rows for a ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 */
	public static function delete_for_ticket( $ticket_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT stored_path FROM {$table} WHERE ticket_id = %d", absint( $ticket_id ) ) );
		foreach ( $rows as $row ) {
			$path = self::base_dir() . $row->stored_path;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$wpdb->delete( $table, array( 'ticket_id' => absint( $ticket_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
