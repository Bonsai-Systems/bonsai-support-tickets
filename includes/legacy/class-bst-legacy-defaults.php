<?php
/**
 * DEVELOPMENT BUILDS ONLY — excluded from the product zip.
 *
 * Before 0.2 the plugin shipped with The Bonsai Digital Collective's
 * branding as its defaults. 0.2 made the defaults neutral. Any setting the
 * original site never saved would silently change on upgrade, so this
 * writes the old values into its settings explicitly, once, when the DB
 * version moves past 3.
 *
 * Fresh installs never run it (activation sets the current DB version).
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the original site's branding through the move to neutral defaults.
 */
class BST_Legacy_Defaults {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'bst_upgraded', array( __CLASS__, 'on_upgrade' ) );
		// The old CSS read the theme's --bonsai-sans; the neutral CSS reads --bst-font-family.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'font_bridge' ), 20 );
	}

	/**
	 * Old defaults, as they were up to 0.1.x.
	 *
	 * @return array<string,string>
	 */
	public static function values() {
		return array(
			'brand_name'         => 'The Bonsai Digital Collective',
			'ref_prefix'         => 'BDC',
			'from_name'          => 'Bonsai Support',
			'inbound_address'    => 'bonsaisupport@gmail.com',
			'imap_processed_tag' => 'Bonsai Support/Processed',
			'autoreply_subject'  => 'Thank you for contacting The Bonsai Digital Collective Support – [{{ticket.title}}]',
			'autoreply_body'     => implode(
				"\n",
				array(
					"<p>Thank you for reaching out to The Bonsai Digital Collective Support with your message titled '<strong>{{ticket.title}}</strong>'.</p>",
					'<p>This is an automated response confirming we have received your ticket. It has been assigned the unique tracking ID <strong>[{{ticket.id}}]</strong> – please keep this in the subject line of any email replies so we can assist you as quickly as possible.</p>',
					'<p>To help us resolve your query efficiently, please ensure you’ve included:</p>',
					'<ul>',
					'<li>A full description of the issue or request</li>',
					'<li>Any relevant website URLs or server names/addresses</li>',
					'<li>Steps to reproduce any problems you have reported</li>',
					'<li>Screenshots, if applicable</li>',
					'</ul>',
					'<p>Our team will review your ticket and respond as soon as possible.</p>',
					'<p>Thank you for choosing The Bonsai Digital Collective.</p>',
					'<p>The Bonsai Digital Collective Support Team<br>',
					'<a href="https://bonsaidigitalcollective.co.uk/">https://bonsaidigitalcollective.co.uk/</a></p>',
				)
			),
			'color_accent'       => '#ee4367',
			'color_accent_hover' => '#d23253',
			'color_accent_text'  => '#c21f48',
			'color_ink'          => '#000000',
			'color_text'         => '#333333',
			'color_background'   => '#faf8f5',
			'color_surface'      => '#ffffff',
		);
	}

	/**
	 * After an upgrade from DB version 3 or earlier: save the old values for
	 * every setting that was still relying on its default.
	 *
	 * @param int $from DB version before the upgrade.
	 */
	public static function on_upgrade( $from ) {
		if ( (int) $from >= 4 ) {
			return;
		}

		try {
			$saved = get_option( BST_Settings::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			$input = array();

			foreach ( self::values() as $key => $value ) {
				if ( ! isset( $saved[ $key ] ) || '' === (string) $saved[ $key ] ) {
					$input[ $key ] = $value;
				}
			}

			if ( empty( $saved['email_logo_url'] ) ) {
				$logo = self::import_logo();
				if ( $logo ) {
					$input['email_logo_url'] = $logo;
				}
			}

			if ( $input ) {
				BST_Settings::save( $input );
			}
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': keeping the original branding failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Copy the old bundled logo into the uploads folder, so it survives the
	 * product build (which doesn't ship it).
	 *
	 * @return string URL, or '' on failure.
	 */
	private static function import_logo() {
		$source = __DIR__ . '/original-logo.jpg';
		if ( ! is_readable( $source ) ) {
			return '';
		}
		$upload = wp_upload_bits( 'support-email-logo.jpg', null, (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! empty( $upload['error'] ) ) {
			error_log( BST_PRODUCT_NAME . ': could not copy the email logo: ' . $upload['error'] );
			return '';
		}
		return (string) $upload['url'];
	}

	/**
	 * Keep the theme's font on the plugin's front end.
	 */
	public static function font_bridge() {
		if ( wp_style_is( BST_Frontend::STYLE_HANDLE, 'registered' ) ) {
			wp_add_inline_style( BST_Frontend::STYLE_HANDLE, '.bst{--bst-font-family:var(--bonsai-sans,inherit);}' );
		}
	}
}

BST_Legacy_Defaults::init();
