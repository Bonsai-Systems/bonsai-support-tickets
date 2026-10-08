<?php
/**
 * Old Support theme → Support Desk theme (legacy, development builds only).
 * Needs the WordPress test suite. Reads and writes ACF's raw option format,
 * so ACF isn't needed.
 *
 * @package Support_Desk
 */

/**
 * BST_Legacy_Theme.
 */
class LegacyThemeTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'BST_Legacy_Theme' ) ) {
			$this->markTestSkipped( 'Legacy code is not in this build.' );
		}
		delete_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME );
	}

	public function test_old_fallbacks_fill_empty_settings_only() {
		update_option( 'options_help_title', 'Kept' );
		delete_option( 'options_copyright_text' );
		delete_option( 'options_color_tint' );

		BST_Legacy_Theme::fill_settings();

		$this->assertSame( 'Kept', get_option( 'options_help_title' ) );
		$this->assertSame( 'The Bonsai Digital Collective.', get_option( 'options_copyright_text' ) );
		$this->assertSame( 'field_bso_copyright', get_option( '_options_copyright_text' ), 'ACF field key reference.' );
		$this->assertSame( '#e2ecf3', get_option( 'options_color_tint' ) );
	}

	public function test_logo_and_menus_go_to_the_new_theme() {
		update_option( 'options_site_main_logo', '42' );
		update_option( 'theme_mods_' . BST_Legacy_Theme::OLD_THEME, array( 'nav_menu_locations' => array( 'primary' => 5 ) ) );

		BST_Legacy_Theme::copy_logo();
		BST_Legacy_Theme::copy_menus();

		$mods = get_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME );
		$this->assertSame( 42, $mods['custom_logo'] );
		$this->assertSame( 5, $mods['nav_menu_locations']['primary'] );

		// A logo already set on the new theme wins.
		update_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME, array( 'custom_logo' => 7 ) );
		BST_Legacy_Theme::copy_logo();
		$this->assertSame( 7, get_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME )['custom_logo'] );
	}

	public function test_migrate_leaves_pages_untouched() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[old_shortcode]',
			)
		);
		update_post_meta( $id, 'page_builder', array( 'subpage_hero_module' ) );
		update_post_meta( $id, 'page_builder_0_hero_title', 'Hello' );

		$result = BST_Legacy_Theme::migrate();

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( '[old_shortcode]', get_post_field( 'post_content', $id ) );
		$this->assertSame( array( 'subpage_hero_module' ), get_post_meta( $id, 'page_builder', true ) );
		$this->assertSame( 'Hello', get_post_meta( $id, 'page_builder_0_hero_title', true ) );
	}
}
