<?php
/**
 * Settings store. Needs the WordPress test suite.
 *
 * @package Bonsai_Support_Tickets
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
		BST_Settings::save( array( 'from_name' => 'Bonsai Help' ) );

		$this->assertSame( 'ABC', BST_Settings::get( 'ref_prefix' ) );
		$this->assertSame( 0, BST_Settings::get( 'registration_enabled' ) );
		$this->assertSame( 'Bonsai Help', BST_Settings::get( 'from_name' ) );
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
}
