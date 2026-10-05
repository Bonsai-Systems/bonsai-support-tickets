<?php
/**
 * Settings store. Needs the WordPress test suite.
 *
 * @package Support_Desk
 */

/**
 * Saving one settings tab must not touch another's values.
 */
class SettingsTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( BST_Settings::OPTION );
		parent::tear_down();
	}

	public function test_saving_one_tab_keeps_the_other_tabs_values() {
		BST_Settings::save(
			array(
				'ref_prefix'           => 'ABC',
				'registration_enabled' => 0,
			)
		);

		// Outgoing email tab posts only its own fields.
		BST_Settings::save( array( 'from_name' => 'Acme Help' ) );

		$this->assertSame( 'ABC', BST_Settings::get( 'ref_prefix' ) );
		$this->assertSame( 0, BST_Settings::get( 'registration_enabled' ) );
		$this->assertSame( 'Acme Help', BST_Settings::get( 'from_name' ) );
	}

	public function test_unticked_checkbox_posts_zero_and_saves() {
		BST_Settings::save( array( 'imap_enabled' => 1 ) );
		BST_Settings::save( array( 'imap_enabled' => '0' ) );

		$this->assertSame( 0, BST_Settings::get( 'imap_enabled' ) );
	}

	public function test_saved_values_are_still_sanitised() {
		BST_Settings::save( array( 'ref_prefix' => 'ab-c!', 'imap_port' => 'nope' ) );

		$this->assertSame( 'ABC', BST_Settings::get( 'ref_prefix' ) );
		$this->assertSame( 993, BST_Settings::get( 'imap_port' ) );
	}

	public function test_brand_colours_are_validated() {
		BST_Settings::save(
			array(
				'color_accent'     => '0055AA',             // No hash, upper case.
				'color_ink'        => 'red;}body{color:x', // CSS injection attempt.
				'color_background' => '#FAF8F5',            // Same as the default.
			)
		);

		$this->assertSame( '#0055aa', BST_Settings::get( 'color_accent' ) );
		$this->assertSame( '', BST_Settings::get( 'color_ink' ) );
		$this->assertSame( '', BST_Settings::get( 'color_background' ), 'Default values store blank so theme colours still apply.' );
		$this->assertSame( '#000000', BST_Appearance::color( 'color_ink' ) );
	}

	public function test_only_saved_colours_reach_the_inline_css() {
		$this->assertSame( '', BST_Appearance::inline_css() );

		BST_Settings::save( array( 'color_accent' => '#0055aa' ) );

		$this->assertSame( '.bst{--bst-accent:#0055aa;}', BST_Appearance::inline_css() );
	}

	public function test_other_tabs_keep_brand_colours() {
		BST_Settings::save( array( 'color_accent' => '#0055aa' ) );
		BST_Settings::save( array( 'from_name' => 'Acme Support' ) );

		$this->assertSame( '#0055aa', BST_Settings::get( 'color_accent' ) );
	}
}
