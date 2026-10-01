<?php
/**
 * BST_Mime_Parser tests.
 *
 * @package Bonsai_Support_Tickets
 */

use PHPUnit\Framework\TestCase;

/**
 * MIME parsing of inbound support email.
 */
class MimeParserTest extends TestCase {

	/**
	 * Join lines with CRLF like a real message.
	 *
	 * @param string[] $lines Lines.
	 * @return string
	 */
	private function raw( array $lines ) {
		return implode( "\r\n", $lines );
	}

	public function test_plain_message_headers_and_body() {
		$email = BST_Mime_Parser::parse(
			$this->raw(
				array(
					'From: "Jane Client" <Jane@Example.co.uk>',
					'To: bonsaisupport+t42-0123456789@gmail.com',
					'Subject: =?UTF-8?B?Q2Fmw6kgbWVudSBpcyBicm9rZW4=?=',
					'Message-ID: <abc123@mail.example.co.uk>',
					'In-Reply-To: <bst.42.ffff@support.example>',
					'References: <bst.ticket.42@support.example> <bst.42.ffff@support.example>',
					'Content-Type: text/plain; charset=UTF-8',
					'',
					'The menu page is blank.',
				)
			)
		);

		$this->assertSame( 'jane@example.co.uk', $email['from_email'] );
		$this->assertSame( 'Jane Client', $email['from_name'] );
		$this->assertSame( 'Café menu is broken', $email['subject'] );
		$this->assertSame( '<abc123@mail.example.co.uk>', $email['message_id'] );
		$this->assertSame( '<bst.42.ffff@support.example>', $email['in_reply_to'] );
		$this->assertSame( array( '<bst.ticket.42@support.example>', '<bst.42.ffff@support.example>' ), $email['references'] );
		$this->assertContains( 'bonsaisupport+t42-0123456789@gmail.com', $email['recipients'] );
		$this->assertSame( 'The menu page is blank.', trim( $email['text'] ) );
		$this->assertFalse( $email['is_auto'] );
	}

	public function test_folded_headers_and_multiple_recipients() {
		$email = BST_Mime_Parser::parse(
			$this->raw(
				array(
					'From: someone@example.com',
					'To: "Support, Bonsai" <bonsaisupport@gmail.com>,',
					' other@example.com',
					'Cc: third@example.com',
					'Delivered-To: bonsaisupport+t7-aaaaaaaaaa@gmail.com',
					'Subject: A very long subject that',
					'  wraps onto a second line',
					'',
					'Body',
				)
			)
		);

		$this->assertSame( 'A very long subject that wraps onto a second line', $email['subject'] );
		$this->assertSame(
			array( 'bonsaisupport@gmail.com', 'other@example.com', 'third@example.com', 'bonsaisupport+t7-aaaaaaaaaa@gmail.com' ),
			$email['recipients']
		);
	}

	public function test_multipart_alternative_quoted_printable() {
		$email = BST_Mime_Parser::parse(
			$this->raw(
				array(
					'From: a@example.com',
					'Subject: Test',
					'MIME-Version: 1.0',
					'Content-Type: multipart/alternative; boundary="b1"',
					'',
					'--b1',
					'Content-Type: text/plain; charset="UTF-8"',
					'Content-Transfer-Encoding: quoted-printable',
					'',
					'Price is =C2=A3100 and this line is soft=',
					' wrapped.',
					'--b1',
					'Content-Type: text/html; charset="UTF-8"',
					'Content-Transfer-Encoding: quoted-printable',
					'',
					'<p>Price is =C2=A3100</p>',
					'--b1--',
				)
			)
		);

		$this->assertSame( 'Price is £100 and this line is soft wrapped.', $email['text'] );
		$this->assertSame( '<p>Price is £100</p>', $email['html'] );
		$this->assertSame( array(), $email['attachments'] );
	}

	public function test_nested_multipart_with_base64_attachment_and_rfc2231_name() {
		$pdf = '%PDF-1.4 fake';

		$email = BST_Mime_Parser::parse(
			$this->raw(
				array(
					'From: a@example.com',
					'Subject: With file',
					'Content-Type: multipart/mixed; boundary=outer',
					'',
					'--outer',
					'Content-Type: multipart/alternative; boundary=inner',
					'',
					'--inner',
					'Content-Type: text/plain; charset=UTF-8',
					'',
					'See attached.',
					'--inner--',
					'--outer',
					'Content-Type: application/pdf',
					"Content-Disposition: attachment; filename*=UTF-8''Re%CC%81sume%CC%81%20v2.pdf",
					'Content-Transfer-Encoding: base64',
					'',
					chunk_split( base64_encode( $pdf ), 8, "\r\n" ),
					'--outer--',
				)
			)
		);

		$this->assertSame( 'See attached.', $email['text'] );
		$this->assertCount( 1, $email['attachments'] );
		$this->assertSame( "Re\u{0301}sume\u{0301} v2.pdf", $email['attachments'][0]['filename'] );
		$this->assertSame( $pdf, $email['attachments'][0]['content'] );
		$this->assertSame( 'application/pdf', $email['attachments'][0]['mime'] );
	}

	public function test_attachment_name_cannot_contain_a_path() {
		$email = BST_Mime_Parser::parse(
			$this->raw(
				array(
					'From: a@example.com',
					'Content-Type: multipart/mixed; boundary=x',
					'',
					'--x',
					'Content-Type: text/plain',
					'',
					'Hi',
					'--x',
					'Content-Type: image/png; name="../../wp-config.php"',
					'Content-Transfer-Encoding: base64',
					'',
					base64_encode( 'png' ),
					'--x--',
				)
			)
		);

		$this->assertSame( 'wp-config.php', $email['attachments'][0]['filename'] );
	}

	public function test_latin1_body_is_converted_to_utf8() {
		$email = BST_Mime_Parser::parse(
			"From: a@example.com\r\nContent-Type: text/plain; charset=ISO-8859-1\r\n\r\nCaf\xE9"
		);

		$this->assertSame( 'Café', $email['text'] );
	}

	/**
	 * @dataProvider auto_reply_headers
	 *
	 * @param string $header Extra header line.
	 * @param string $from   From address.
	 */
	public function test_auto_replies_are_flagged( $header, $from ) {
		$email = BST_Mime_Parser::parse( "From: {$from}\r\n{$header}\r\nSubject: Out of office\r\n\r\nI am away." );
		$this->assertTrue( $email['is_auto'] );
	}

	/**
	 * Headers that mark mail as automatic.
	 *
	 * @return array
	 */
	public function auto_reply_headers() {
		return array(
			'auto-submitted'  => array( 'Auto-Submitted: auto-replied', 'person@example.com' ),
			'x-autoreply'     => array( 'X-Autoreply: yes', 'person@example.com' ),
			'precedence bulk' => array( 'Precedence: bulk', 'person@example.com' ),
			'mailing list'    => array( 'List-Id: <news.example.com>', 'person@example.com' ),
			'bounce'          => array( 'Return-Path: <>', 'person@example.com' ),
			'mailer-daemon'   => array( 'X-Whatever: 1', 'MAILER-DAEMON@example.com' ),
			'noreply'         => array( 'X-Whatever: 1', 'no-reply@example.com' ),
		);
	}

	public function test_auto_submitted_no_is_a_real_person() {
		$email = BST_Mime_Parser::parse( "From: person@example.com\r\nAuto-Submitted: no\r\n\r\nHello" );
		$this->assertFalse( $email['is_auto'] );
	}
}
