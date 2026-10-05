<?php
/**
 * Plugin settings: one option (`bst_settings`), merged over defaults.
 *
 * IMAP credentials are deliberately NOT stored here — they live in
 * wp-config.php as BST_IMAP_USER / BST_IMAP_PASSWORD so they never sit in the
 * database or a DB export.
 *
 * @package Support_Desk
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
				'brand_name'           => '', // Support name clients see; '' = site title.
				'ref_prefix'           => 'SUP',
				'portal_page_id'       => 0,
				'submit_page_id'       => 0,
				'auto_close_days'      => 7,
				'max_upload_mb'        => 10,
				'max_upload_files'     => 5,
				'registration_enabled' => 1,

				// Outbound email.
				'from_name'            => '', // '' = support name.
				'from_email'           => '',
				'email_logo_url'       => '',

				// Auto-reply to a new ticket. See BST_Mailer::placeholders().
				'autoreply_enabled'    => 1,
				'autoreply_subject'    => self::default_autoreply_subject(),
				'autoreply_body'       => self::default_autoreply_body(),

				// Inbound email.
				'inbound_address'      => '',
				'plus_addressing'      => 1,
				'imap_enabled'         => 0,
				'imap_host'            => 'imap.gmail.com',
				'imap_port'            => 993,
				'imap_mailbox'         => 'INBOX',
				'imap_processed_tag'   => 'Support/Processed',

				// Slack. The webhook URL is BST_SLACK_WEBHOOK_URL in wp-config.php.
				'slack_enabled'        => 1,

				// Uptime monitoring. Secrets are generated on the settings screen
				// (or BST_MONITOR_SECRET / BST_UPTIMEROBOT_KEY in wp-config.php).
				'monitor_enabled'      => 0,
				'monitor_secret'       => '',
				'uptimerobot_enabled'  => 0,
				'uptimerobot_key'      => '',
			)
			// Brand colours. '' = default; see BST_Appearance.
			+ array_fill_keys( array_keys( BST_Appearance::default_colors() ), '' )
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

		$clean['brand_name']           = sanitize_text_field( $input['brand_name'] ?? '' );
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
		$clean['slack_enabled']        = empty( $input['slack_enabled'] ) ? 0 : 1;
		$clean['monitor_enabled']      = empty( $input['monitor_enabled'] ) ? 0 : 1;
		$clean['monitor_secret']       = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['monitor_secret'] ?? '' ) );
		$clean['uptimerobot_enabled']  = empty( $input['uptimerobot_enabled'] ) ? 0 : 1;
		$clean['uptimerobot_key']      = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['uptimerobot_key'] ?? '' ) );

		foreach ( array_keys( BST_Appearance::default_colors() ) as $color_key ) {
			$clean[ $color_key ] = BST_Appearance::sanitize( $color_key, $input[ $color_key ] ?? '' );
		}

		update_option( self::OPTION, $clean, false );
	}

	/**
	 * The support name clients see in emails, the portal and the login
	 * screen: the Support name setting, else the site title.
	 *
	 * @return string
	 */
	public static function brand_name() {
		$name = trim( (string) self::get( 'brand_name' ) );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * From name on outgoing email: the From name setting, else the support name.
	 *
	 * @return string
	 */
	public static function from_name() {
		$name = trim( (string) self::get( 'from_name' ) );
		return '' !== $name ? $name : self::brand_name();
	}

	/**
	 * Default auto-reply subject. Not translated: it's editable content, and
	 * defaults() can run before the text domain loads.
	 *
	 * @return string
	 */
	public static function default_autoreply_subject() {
		return "We've received your request – [{{ticket.title}}]";
	}

	/**
	 * Default auto-reply body (HTML).
	 *
	 * @return string
	 */
	public static function default_autoreply_body() {
		return implode(
			"\n",
			array(
				'<p>Hi {{client.name}},</p>',
				"<p>Thanks for contacting {{site.name}} about '<strong>{{ticket.title}}</strong>'. This is an automatic reply to confirm we have your request.</p>",
				'<p>Your reference is <strong>[{{ticket.id}}]</strong>. Please keep it in the subject line of any email replies so we can help you quickly.</p>',
				"<p>To help us sort it out first time, please make sure you've included:</p>",
				'<ul>',
				'<li>A full description of the issue or request</li>',
				'<li>Any relevant website addresses</li>',
				'<li>Steps to reproduce any problem</li>',
				'<li>Screenshots, if you have them</li>',
				'</ul>',
				"<p>We'll be in touch as soon as possible.</p>",
				'<p>{{site.name}}</p>',
			)
		);
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
