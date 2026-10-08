<?php
/**
 * Time tracking duration parsing and formatting.
 *
 * @package Support_Desk
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-bst-duration.php';

/**
 * BST_Duration.
 */
class DurationTest extends TestCase {

	/**
	 * Inputs agents type → minutes.
	 *
	 * @return array
	 */
	public static function valid_inputs() {
		return array(
			'minutes'            => array( '30m', 30 ),
			'min word'           => array( '45 min', 45 ),
			'mins word'          => array( '45 mins', 45 ),
			'minutes word'       => array( '5 minutes', 5 ),
			'hours'              => array( '2h', 120 ),
			'hrs'                => array( '2 hrs', 120 ),
			'hours word'         => array( '1 hour', 60 ),
			'decimal hours unit' => array( '1.5h', 90 ),
			'h and m'            => array( '1h 30m', 90 ),
			'h and m no space'   => array( '1h30m', 90 ),
			'h then bare mins'   => array( '1h30', 90 ),
			'long words'         => array( '1 hr 15 mins', 75 ),
			'colon'              => array( '1:30', 90 ),
			'colon minutes only' => array( '0:45', 45 ),
			'bare decimal'       => array( '1.5', 90 ),
			'bare quarter'       => array( '0.25', 15 ),
			'bare integer'       => array( '2', 120 ),
			'comma decimal'      => array( '1,5', 90 ),
			'upper case'         => array( '1H 30M', 90 ),
			'padded'             => array( '  45m  ', 45 ),
			'rounds'             => array( '0.1', 6 ),
			'one day'            => array( '24h', 1440 ),
		);
	}

	/**
	 * @dataProvider valid_inputs
	 *
	 * @param string $input    Input.
	 * @param int    $expected Minutes.
	 */
	public function test_parses( $input, $expected ) {
		$this->assertSame( $expected, BST_Duration::parse( $input ) );
	}

	/**
	 * Inputs that must be rejected.
	 *
	 * @return array
	 */
	public static function invalid_inputs() {
		return array(
			'empty'          => array( '' ),
			'zero'           => array( '0' ),
			'zero minutes'   => array( '0m' ),
			'words'          => array( 'half an hour' ),
			'negative'       => array( '-1h' ),
			'over a day'     => array( '25h' ),
			'30 bare = 30h'  => array( '30' ),
			'bad colon'      => array( '1:75' ),
			'minutes first'  => array( '30m 1h' ),
			'junk after'     => array( '1h abc' ),
			'just a unit'    => array( 'h' ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 *
	 * @param string $input Input.
	 */
	public function test_rejects( $input ) {
		$this->assertNull( BST_Duration::parse( $input ) );
	}

	public function test_format() {
		$this->assertSame( '0m', BST_Duration::format( 0 ) );
		$this->assertSame( '45m', BST_Duration::format( 45 ) );
		$this->assertSame( '2h', BST_Duration::format( 120 ) );
		$this->assertSame( '1h 30m', BST_Duration::format( 90 ) );
		$this->assertSame( '-1h 15m', BST_Duration::format( -75 ) );
	}

	public function test_hours() {
		$this->assertSame( '1.50', BST_Duration::hours( 90 ) );
		$this->assertSame( '0.25', BST_Duration::hours( 15 ) );
		$this->assertSame( '0.00', BST_Duration::hours( 0 ) );
	}
}
