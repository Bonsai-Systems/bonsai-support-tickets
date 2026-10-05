<?php
/**
 * BST_Imap_Client tests (offline parts only).
 *
 * @package Support_Desk
 */

use PHPUnit\Framework\TestCase;

/**
 * IMAP client.
 */
class ImapClientTest extends TestCase {

	public function test_quote_escapes_backslashes_and_quotes() {
		$this->assertSame( '"pa\\\\ss\\"word"', BST_Imap_Client::quote( 'pa\\ss"word' ) );
		$this->assertSame( '"Bonsai Support/Processed"', BST_Imap_Client::quote( 'Bonsai Support/Processed' ) );
	}

	public function test_commands_fail_cleanly_when_not_connected() {
		$client = new BST_Imap_Client( 'imap.example.invalid' );
		$this->expectException( RuntimeException::class );
		$client->select( 'INBOX' );
	}

	public function test_connect_failure_throws() {
		$client = new BST_Imap_Client( '127.0.0.1', 1, 2 );
		$this->expectException( RuntimeException::class );
		$client->connect( 'user', 'pass' );
	}
}
