<?php
/**
 * Plugin settings: one option (`bst_settings`), merged over defaults.
 *
 * IMAP credentials are deliberately NOT stored here — they live in
 * wp-config.php as BST_IMAP_USER / BST_IMAP_PASSWORD so they never sit in the
 * database or a DB export.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings store.
 */
class BST_Settings {

	const OPTION = 'bst_settings';

	/**
	 * Defaults. Filterable so a site can change them without a settings save.
	 *
	 * @return array
	 */
	public static function defaults() {
		return apply_filters(
			'bst_settings_defaults',
			array(
				// General.
				'ref_prefix'         => 'BDC',
				'portal_page_id'     => 0,
				'submit_page_id'     => 0,
				'auto_close_days'    => 7,
				'max_upload_mb'      => 10,
				'max_upload_files'   => 5,

				// Outbound email.
				'from_name'          => 'Bonsai Support',
				'from_email'         => '',
				'email_logo_url'     => '',

				// Inbound email.
				'inbound_address'    => 'bonsaisupport@gmail.com',
				'plus_addressing'    => 1,
				'imap_enabled'       => 0,
				'imap_host'          => 'imap.gmail.com',
				'imap_port'          => 993,
				'imap_mailbox'       => 'INBOX',
				'imap_processed_tag' => 'Bonsai Support/Processed',
			)
		);
	}

	/**
	 * All settings, saved values over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitise and save raw input (from the settings screen).
	 *
	 * @param array $input Unslashed input.
	 */
	public static function save( array $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$clean['ref_prefix']         = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['ref_prefix'] ?? '' ) ) );
		$clean['ref_prefix']         = '' !== $clean['ref_prefix'] ? substr( $clean['ref_prefix'], 0, 8 ) : $defaults['ref_prefix'];
		$clean['portal_page_id']     = absint( $input['portal_page_id'] ?? 0 );
		$clean['submit_page_id']     = absint( $input['submit_page_id'] ?? 0 );
		$clean['auto_close_days']    = min( 90, absint( $input['auto_close_days'] ?? $defaults['auto_close_days'] ) );
		$clean['max_upload_mb']      = max( 1, min( 64, absint( $input['max_upload_mb'] ?? $defaults['max_upload_mb'] ) ) );
		$clean['max_upload_files']   = max( 1, min( 20, absint( $input['max_upload_files'] ?? $defaults['max_upload_files'] ) ) );
		$clean['from_name']          = sanitize_text_field( $input['from_name'] ?? '' );
		$clean['from_email']         = sanitize_email( $input['from_email'] ?? '' );
		$clean['email_logo_url']     = esc_url_raw( $input['email_logo_url'] ?? '' );
		$clean['inbound_address']    = sanitize_email( $input['inbound_address'] ?? '' );
		$clean['plus_addressing']    = empty( $input['plus_addressing'] ) ? 0 : 1;
		$clean['imap_enabled']       = empty( $input['imap_enabled'] ) ? 0 : 1;
		$clean['imap_host']          = sanitize_text_field( $input['imap_host'] ?? $defaults['imap_host'] );
		$clean['imap_port']          = absint( $input['imap_port'] ?? $defaults['imap_port'] );
		$clean['imap_port']          = $clean['imap_port'] ? $clean['imap_port'] : $defaults['imap_port'];
		$clean['imap_mailbox']       = sanitize_text_field( $input['imap_mailbox'] ?? $defaults['imap_mailbox'] );
		$clean['imap_processed_tag'] = sanitize_text_field( $input['imap_processed_tag'] ?? '' );

		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Whether the IMAP credentials constants are defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function has_imap_credentials() {
		return defined( 'BST_IMAP_USER' ) && defined( 'BST_IMAP_PASSWORD' ) && '' !== BST_IMAP_USER && '' !== BST_IMAP_PASSWORD;
	}
}
