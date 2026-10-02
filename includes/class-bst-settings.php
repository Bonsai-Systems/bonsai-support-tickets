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
				'ref_prefix'           => 'BDC',
				'portal_page_id'       => 0,
				'submit_page_id'       => 0,
				'auto_close_days'      => 7,
				'max_upload_mb'        => 10,
				'max_upload_files'     => 5,
				'registration_enabled' => 1,

				// Outbound email.
				'from_name'            => 'Bonsai Support',
				'from_email'           => '',
				'email_logo_url'       => '',

				// Auto-reply to a new ticket. See BST_Mailer::placeholders().
				'autoreply_enabled'    => 1,
				'autoreply_subject'    => self::default_autoreply_subject(),
				'autoreply_body'       => self::default_autoreply_body(),

				// Inbound email.
				'inbound_address'      => 'bonsaisupport@gmail.com',
				'plus_addressing'      => 1,
				'imap_enabled'         => 0,
				'imap_host'            => 'imap.gmail.com',
				'imap_port'            => 993,
				'imap_mailbox'         => 'INBOX',
				'imap_processed_tag'   => 'Bonsai Support/Processed',
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
	 * Partial input is fine: each settings tab posts only its own fields, and
	 * anything not posted keeps its current value. Checkboxes post a hidden 0
	 * so unticking still saves.
	 *
	 * @param array $input Unslashed input.
	 */
	public static function save( array $input ) {
		$defaults = self::defaults();
		$input    = array_merge( self::all(), $input );
		$clean    = array();

		$clean['ref_prefix']           = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['ref_prefix'] ?? '' ) ) );
		$clean['ref_prefix']           = '' !== $clean['ref_prefix'] ? substr( $clean['ref_prefix'], 0, 8 ) : $defaults['ref_prefix'];
		$clean['portal_page_id']       = absint( $input['portal_page_id'] ?? 0 );
		$clean['submit_page_id']       = absint( $input['submit_page_id'] ?? 0 );
		$clean['auto_close_days']      = min( 90, absint( $input['auto_close_days'] ?? $defaults['auto_close_days'] ) );
		$clean['max_upload_mb']        = max( 1, min( 64, absint( $input['max_upload_mb'] ?? $defaults['max_upload_mb'] ) ) );
		$clean['max_upload_files']     = max( 1, min( 20, absint( $input['max_upload_files'] ?? $defaults['max_upload_files'] ) ) );
		$clean['registration_enabled'] = empty( $input['registration_enabled'] ) ? 0 : 1;
		$clean['from_name']            = sanitize_text_field( $input['from_name'] ?? '' );
		$clean['from_email']           = sanitize_email( $input['from_email'] ?? '' );
		$clean['email_logo_url']       = esc_url_raw( $input['email_logo_url'] ?? '' );
		$clean['autoreply_enabled']    = empty( $input['autoreply_enabled'] ) ? 0 : 1;
		$clean['autoreply_subject']    = sanitize_text_field( $input['autoreply_subject'] ?? '' );
		$clean['autoreply_body']       = wp_kses_post( $input['autoreply_body'] ?? '' );
		// Clearing a field restores the default rather than sending a blank email.
		$clean['autoreply_subject']    = '' !== $clean['autoreply_subject'] ? $clean['autoreply_subject'] : $defaults['autoreply_subject'];
		$clean['autoreply_body']       = '' !== trim( wp_strip_all_tags( $clean['autoreply_body'] ) ) ? $clean['autoreply_body'] : $defaults['autoreply_body'];
		$clean['inbound_address']     = sanitize_email( $input['inbound_address'] ?? '' );
		$clean['plus_addressing']      = empty( $input['plus_addressing'] ) ? 0 : 1;
		$clean['imap_enabled']         = empty( $input['imap_enabled'] ) ? 0 : 1;
		$clean['imap_host']            = sanitize_text_field( $input['imap_host'] ?? $defaults['imap_host'] );
		$clean['imap_port']            = absint( $input['imap_port'] ?? $defaults['imap_port'] );
		$clean['imap_port']            = $clean['imap_port'] ? $clean['imap_port'] : $defaults['imap_port'];
		$clean['imap_mailbox']         = sanitize_text_field( $input['imap_mailbox'] ?? $defaults['imap_mailbox'] );
		$clean['imap_processed_tag']   = sanitize_text_field( $input['imap_processed_tag'] ?? '' );

		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Default auto-reply subject. Not translated: it's editable content, and
	 * defaults() can run before the text domain loads.
	 *
	 * @return string
	 */
	public static function default_autoreply_subject() {
		return 'Thank you for contacting The Bonsai Digital Collective Support – [{{ticket.title}}]';
	}

	/**
	 * Default auto-reply body (HTML).
	 *
	 * @return string
	 */
	public static function default_autoreply_body() {
		return '<p>Thank you for reaching out to The Bonsai Digital Collective Support with your message titled \'<strong>{{ticket.title}}</strong>\'.</p>' . "\n"
			. '<p>This is an automated response confirming we have received your ticket. It has been assigned the unique tracking ID <strong>[{{ticket.id}}]</strong> – please keep this in the subject line of any email replies so we can assist you as quickly as possible.</p>' . "\n"
			. '<p>To help us resolve your query efficiently, please ensure you’ve included:</p>' . "\n"
			. '<ul>' . "\n"
			. '<li>A full description of the issue or request</li>' . "\n"
			. '<li>Any relevant website URLs or server names/addresses</li>' . "\n"
			. '<li>Steps to reproduce any problems you have reported</li>' . "\n"
			. '<li>Screenshots, if applicable</li>' . "\n"
			. '</ul>' . "\n"
			. '<p>Our team will review your ticket and respond as soon as possible.</p>' . "\n"
			. '<p>Thank you for choosing The Bonsai Digital Collective.</p>' . "\n"
			. '<p>The Bonsai Digital Collective Support Team<br>' . "\n"
			. '<a href="https://bonsaidigitalcollective.co.uk/">https://bonsaidigitalcollective.co.uk/</a></p>';
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
