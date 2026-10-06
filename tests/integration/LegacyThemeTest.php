<?php
/**
 * ACF page builder → Support Desk blocks (legacy, development builds only).
 * Needs the WordPress test suite. Writes meta in ACF's storage format
 * directly, so ACF isn't needed.
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
	}

	/**
	 * Page with ACF-style page builder meta.
	 *
	 * @param array $layouts Layout names.
	 * @param array $meta    Extra meta (key => value).
	 * @return int
	 */
	private function acf_page( array $layouts, array $meta ) {
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $id, 'page_builder', $layouts );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	public function test_fields_repeaters_links_and_defaults() {
		$id = $this->acf_page(
			array( 'faq_module', 'cta_compact_module', 'meet_the_team_module' ),
			array(
				'page_builder_0_heading'                  => 'Questions',
				'page_builder_0_faq_items'                => 2,
				'page_builder_0_faq_items_0_question'     => 'How fast?',
				'page_builder_0_faq_items_0_answer'       => "One.\n\nTwo.",
				'page_builder_0_faq_items_1_question'     => 'Weekends?',
				'page_builder_0_background_style'         => 'blue',
				'page_builder_1_heading'                  => 'Still stuck',
				'page_builder_1_primary_button'           => array( 'url' => '/x', 'title' => 'Go', 'target' => '_blank' ),
				'page_builder_2_section_tag'              => '', // Saved empty: stays empty.
				'page_builder_2_team_members'             => array( '12', '7' ),
				'page_builder_2_source'                   => 'selected',
			)
		);

		$blocks = parse_blocks( BST_Legacy_Theme::page_markup( $id ) );
		$blocks = array_values( array_filter( $blocks, fn( $b ) => null !== $b['blockName'] ) );

		$this->assertSame( array( 'support-desk/faq', 'support-desk/cta-compact', 'support-desk/meet-the-team' ), wp_list_pluck( $blocks, 'blockName' ) );

		$faq = $blocks[0]['attrs'];
		$this->assertSame( 'FAQ', $faq['section_tag'], 'ACF default for a never-saved field.' );
		$this->assertCount( 2, $faq['faq_items'] );
		$this->assertStringContainsString( '<p>One.</p>', $faq['faq_items'][0]['answer'] );
		$this->assertArrayNotHasKey( 'answer', $faq['faq_items'][1] );
		$this->assertSame( 'blue', $faq['background_style'] );

		$this->assertSame(
			array(
				'url'    => '/x',
				'title'  => 'Go',
				'target' => '_blank',
			),
			$blocks[1]['attrs']['primary_button']
		);

		$team = $blocks[2]['attrs'];
		$this->assertArrayNotHasKey( 'section_tag', $team, 'Saved empty, so no default.' );
		$this->assertSame( 'Meet the Team', $team['heading'] );
		$this->assertSame( array( 12, 7 ), $team['team_members'] );
	}

	public function test_content_block_becomes_inner_classic_block() {
		$id = $this->acf_page(
			array( 'content_block_module' ),
			array(
				'page_builder_0_heading'      => 'Privacy',
				'page_builder_0_body_content' => "First.\n\nSecond.",
			)
		);

		$block = parse_blocks( BST_Legacy_Theme::page_markup( $id ) )[0];
		$this->assertSame( 'support-desk/content-block', $block['blockName'] );
		$this->assertSame( 'core/freeform', $block['innerBlocks'][0]['blockName'] );
		$this->assertStringContainsString( '<p>First.</p>', $block['innerBlocks'][0]['innerHTML'] );
	}

	public function test_convert_keeps_acf_meta_and_previous_content_and_is_idempotent() {
		$id = $this->acf_page( array( 'subpage_hero_module' ), array( 'page_builder_0_hero_title' => 'Hello' ) );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '[old_shortcode]',
			)
		);

		$this->assertTrue( BST_Legacy_Theme::convert_page( $id ) );
		$this->assertStringContainsString( '<!-- wp:support-desk/subpage-hero', get_post_field( 'post_content', $id ) );
		$this->assertSame( '[old_shortcode]', get_post_meta( $id, BST_Legacy_Theme::META_PREV, true ) );
		$this->assertSame( array( 'subpage_hero_module' ), get_post_meta( $id, 'page_builder', true ) );

		$this->assertFalse( BST_Legacy_Theme::convert_page( $id ), 'Second run skips.' );
	}

	public function test_unknown_layouts_are_skipped() {
		$id = $this->acf_page( array( 'mystery_module', 'subpage_hero_module' ), array() );
		$blocks = array_values( array_filter( parse_blocks( BST_Legacy_Theme::page_markup( $id ) ), fn( $b ) => null !== $b['blockName'] ) );
		$this->assertSame( array( 'support-desk/subpage-hero' ), wp_list_pluck( $blocks, 'blockName' ) );
	}

	public function test_settings_and_old_fallbacks_go_to_the_new_theme() {
		update_option( 'options_support_hours', 'Mon–Fri' );
		update_option( 'options_site_main_logo', '42' );
		delete_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME );
		update_option( 'theme_mods_' . BST_Legacy_Theme::OLD_THEME, array( 'nav_menu_locations' => array( 'primary' => 5 ) ) );

		BST_Legacy_Theme::copy_settings();
		BST_Legacy_Theme::copy_menus();

		$mods = get_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME );
		$this->assertSame( 'Mon–Fri', $mods['bsup_support_hours'] );
		$this->assertSame( 42, $mods['custom_logo'] );
		$this->assertSame( 'The Bonsai Digital Collective.', $mods['bsup_copyright_text'] );
		$this->assertSame( '#e2ecf3', $mods['bsup_color_tint'] );
		$this->assertSame( 5, $mods['nav_menu_locations']['primary'] );

		// Values already set on the new theme win.
		update_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME, array( 'bsup_support_hours' => 'Kept' ) );
		BST_Legacy_Theme::copy_settings();
		$this->assertSame( 'Kept', get_option( 'theme_mods_' . BST_Legacy_Theme::NEW_THEME )['bsup_support_hours'] );
	}
}
