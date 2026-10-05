<?php
/**
 * BST_Appearance contrast maths tests.
 *
 * @package Support_Desk
 */

use PHPUnit\Framework\TestCase;

/**
 * WCAG contrast ratios used for the Appearance tab warnings.
 */
class AppearanceContrastTest extends TestCase {

	public function test_black_on_white_is_21() {
		$this->assertEqualsWithDelta( 21.0, BST_Appearance::contrast_ratio( '#000000', '#ffffff' ), 0.01 );
	}

	public function test_same_colour_is_1() {
		$this->assertEqualsWithDelta( 1.0, BST_Appearance::contrast_ratio( '#ee4367', '#ee4367' ), 0.01 );
	}

	public function test_order_does_not_matter() {
		$this->assertEqualsWithDelta(
			BST_Appearance::contrast_ratio( '#c21f48', '#faf8f5' ),
			BST_Appearance::contrast_ratio( '#faf8f5', '#c21f48' ),
			0.001
		);
	}

	public function test_shipped_default_palette_passes_aa() {
		$c = BST_Appearance::default_colors();
		// The same pairs the Appearance tab warns about.
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( $c['color_background'], $c['color_ink'] ) );
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( $c['color_surface'], $c['color_accent_hover'] ) );
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( $c['color_accent_text'], $c['color_background'] ) );
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( $c['color_text'], $c['color_surface'] ) );
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( $c['color_ink'], $c['color_surface'] ) );
	}

	public function test_example_pink_pairs_pass_aa() {
		// White on the hover pink, and accent text on the warm background.
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( '#ffffff', '#d23253' ) );
		$this->assertGreaterThanOrEqual( 4.5, BST_Appearance::contrast_ratio( '#c21f48', '#faf8f5' ) );
	}

	public function test_light_pink_fails_aa_for_white_text() {
		$this->assertLessThan( 4.5, BST_Appearance::contrast_ratio( '#ffffff', '#ee4367' ) );
	}

	public function test_short_hex_and_missing_hash_are_understood() {
		$this->assertEqualsWithDelta( 21.0, BST_Appearance::contrast_ratio( '000', 'FFF' ), 0.01 );
	}

	public function test_invalid_colour_never_warns() {
		$this->assertNull( BST_Appearance::luminance( 'not-a-colour' ) );
		$this->assertSame( 21.0, BST_Appearance::contrast_ratio( 'not-a-colour', '#ffffff' ) );
	}
}
