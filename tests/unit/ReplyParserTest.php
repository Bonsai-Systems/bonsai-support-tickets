<?php
/**
 * BST_Reply_Parser tests.
 *
 * @package Bonsai_Support_Tickets
 */

use PHPUnit\Framework\TestCase;

/**
 * Stripping quoted history from email replies.
 */
class ReplyParserTest extends TestCase {

	public function test_cuts_at_our_marker() {
		$text = "Thanks, that's fixed it.\n\n##- Please type your reply above this line -##\n\nBDC-1042\nJane replied...";
		$this->assertSame( "Thanks, that's fixed it.", BST_Reply_Parser::extract( $text ) );
	}

	public function test_cuts_at_quoted_marker() {
		$text = "Still broken on mobile.\n\n> ##- Please type your reply above this line -##\n> old stuff";
		$this->assertSame( 'Still broken on mobile.', BST_Reply_Parser::extract( $text ) );
	}

	public function test_gmail_single_line_header() {
		$text = "Yes please go ahead.\n\nOn Wed, 1 Oct 2026 at 10:00, Bonsai Support <bonsaisupport@gmail.com> wrote:\n> Shall we update it?";
		$this->assertSame( 'Yes please go ahead.', BST_Reply_Parser::extract( $text ) );
	}

	public function test_gmail_wrapped_header() {
		$text = "Yes please go ahead.\n\nOn Wed, 1 Oct 2026 at 10:00, Bonsai Support <\nbonsaisupport+t42-0123456789@gmail.com> wrote:\n\n> Shall we update it?";
		$this->assertSame( 'Yes please go ahead.', BST_Reply_Parser::extract( $text ) );
	}

	public function test_a_line_starting_with_on_is_kept() {
		$text = "On the contact page the form doesn't send.\nCan you look?";
		$this->assertSame( $text, BST_Reply_Parser::extract( $text ) );
	}

	public function test_outlook_header_block() {
		$text = "Looks good.\n\nKind regards\nJane\n\nFrom: Bonsai Support <bonsaisupport@gmail.com>\nSent: 01 October 2026 10:00\nTo: Jane\nSubject: [BDC-1042] Menu";
		$this->assertSame( "Looks good.\n\nKind regards\nJane", BST_Reply_Parser::extract( $text ) );
	}

	public function test_original_message_separator() {
		$text = "Approved.\n\n-----Original Message-----\nFrom: someone";
		$this->assertSame( 'Approved.', BST_Reply_Parser::extract( $text ) );
	}

	public function test_signature_delimiter() {
		$text = "Can you call me?\n\n-- \nJane Smith\nCafe Owner\n07700 900000";
		$this->assertSame( 'Can you call me?', BST_Reply_Parser::extract( $text ) );
	}

	public function test_trailing_quoted_lines_and_mobile_signature() {
		$text = "Done, thanks\n\nSent from my iPhone\n\n> earlier message\n> more";
		$this->assertSame( 'Done, thanks', BST_Reply_Parser::extract( $text ) );
	}

	public function test_html_only_gmail_reply() {
		$html = '<div dir="ltr">Thanks!<br>Works now.</div><br><div class="gmail_quote"><div>On Wed... wrote:</div><blockquote>old</blockquote></div>';
		$this->assertSame( "Thanks!\nWorks now.", BST_Reply_Parser::extract( '', $html ) );
	}

	public function test_html_only_outlook_reply() {
		$html = '<p>Approved &amp; signed.</p><div id="divRplyFwdMsg"><b>From:</b> Bonsai</div>';
		$this->assertSame( 'Approved & signed.', BST_Reply_Parser::extract( '', $html ) );
	}

	public function test_empty_reply() {
		$this->assertSame( '', BST_Reply_Parser::extract( "\n\n> quoted only\n" ) );
	}
}
