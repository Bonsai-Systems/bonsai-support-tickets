<?php
/**
 * White-label defaults, the legacy upgrade path and first-run setup.
 * Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * A fresh install names nobody; an upgraded original site keeps its brand.
 */
class WhiteLabelTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( BST_Settings::OPTION );
		parent::tear_down();
	}

	public function test_fresh_defaults_are_neutral() {
		$defaults = BST_Settings::defaults();

		$this->assertSame( 'SUP', $defaults['ref_prefix'] );
		$this->assertSame( '', $defaults['from_name'] );
		$this->assertSame( '', $defaults['inbound_address'] );
		$this->assertSame( 'Support/Processed', $defaults['imap_processed_tag'] );

		$haystack = strtolower( wp_json_encode( $defaults ) . wp_json_encode( BST_Appearance::default_colors() ) );
		$this->assertStringNotContainsString( 'bonsai', $haystack );
		$this->assertStringNotContainsString( 'bdc', $haystack );
	}

	public function test_support_name_falls_back_to_site_title() {
		update_option( 'blogname', 'Acme Ltd' );
		$this->assertSame( 'Acme Ltd', BST_Settings::brand_name() );
		$this->assertSame( 'Acme Ltd', BST_Settings::from_name() );

		BST_Settings::save( array( 'brand_name' => 'Acme Support' ) );
		$this->assertSame( 'Acme Support', BST_Settings::brand_name() );
		$this->assertSame( 'Acme Support', BST_Settings::from_name() );

		BST_Settings::save( array( 'from_name' => 'Acme Helpdesk' ) );
		$this->assertSame( 'Acme Helpdesk', BST_Settings::from_name() );
	}

	public function test_site_name_placeholder() {
		BST_Settings::save( array( 'brand_name' => 'Acme Support' ) );
		$this->assertSame( 'Hello from Acme Support', BST_Mailer::fill_placeholders( 'Hello from {{site.name}}', 0, false ) );
	}

	public function test_failed_label_follows_processed_label() {
		$this->assertSame( 'Support/Failed', BST_Inbound::failed_label() );

		BST_Settings::save( array( 'imap_processed_tag' => 'Acme/Done' ) );
		$this->assertSame( 'Acme/Failed', BST_Inbound::failed_label() );
	}

	public function test_email_without_logo_shows_the_support_name() {
		BST_Settings::save( array( 'brand_name' => 'Acme Support' ) );
		$html = BST_Template::capture(
			'emails/layout.php',
			array(
				'heading'      => 'Hi',
				'intro'        => '',
				'message'      => null,
				'internal'     => false,
				'button_url'   => '',
				'button_label' => '',
				'footer'       => '',
				'ref'          => '',
				'subject'      => '',
				'reply_marker' => '',
				'logo_url'     => '',
				'site_name'    => BST_Settings::brand_name(),
			)
		);

		$this->assertStringContainsString( 'Acme Support', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/*
	| Legacy upgrade (development builds only)
	*/

	public function test_upgrade_from_v3_keeps_the_original_branding() {
		if ( ! class_exists( 'BST_Legacy_Defaults' ) ) {
			$this->markTestSkipped( 'Legacy defaults are not in this build.' );
		}

		// A 0.1 site that saved some settings but never touched these.
		update_option( BST_Settings::OPTION, array( 'from_email' => 'help@example.com' ) );

		do_action( 'bst_upgraded', 3 );

		$this->assertSame( 'BDC', BST_Settings::get( 'ref_prefix' ) );
		$this->assertSame( 'Bonsai Support', BST_Settings::get( 'from_name' ) );
		$this->assertSame( '#ee4367', BST_Settings::get( 'color_accent' ) );
		$this->assertSame( 'help@example.com', BST_Settings::get( 'from_email' ), 'Saved values are untouched.' );
		$this->assertStringContainsString( 'The Bonsai Digital Collective', BST_Settings::get( 'autoreply_body' ) );
	}

	public function test_upgrade_never_overwrites_saved_values() {
		if ( ! class_exists( 'BST_Legacy_Defaults' ) ) {
			$this->markTestSkipped( 'Legacy defaults are not in this build.' );
		}

		BST_Settings::save(
			array(
				'ref_prefix'   => 'ACME',
				'color_accent' => '#0055aa',
			)
		);

		do_action( 'bst_upgraded', 3 );

		$this->assertSame( 'ACME', BST_Settings::get( 'ref_prefix' ) );
		$this->assertSame( '#0055aa', BST_Settings::get( 'color_accent' ) );
	}

	public function test_current_installs_are_left_alone() {
		if ( ! class_exists( 'BST_Legacy_Defaults' ) ) {
			$this->markTestSkipped( 'Legacy defaults are not in this build.' );
		}

		do_action( 'bst_upgraded', 4 );

		$this->assertSame( 'SUP', BST_Settings::get( 'ref_prefix' ) );
	}

	/*
	| Setup checklist
	*/

	public function test_setup_is_incomplete_until_the_essentials_are_done() {
		$this->assertFalse( BST_Admin_Setup::is_complete() );

		$portal = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$submit = self::factory()->post->create( array( 'post_type' => 'page' ) );
		BST_Settings::save(
			array(
				'brand_name'     => 'Acme Support',
				'email_logo_url' => 'https://example.com/logo.png',
				'portal_page_id' => $portal,
				'submit_page_id' => $submit,
				'from_email'     => 'support@example.com',
			)
		);

		$this->assertTrue( BST_Admin_Setup::is_complete(), 'Colours and the mailbox are optional.' );
	}
}
