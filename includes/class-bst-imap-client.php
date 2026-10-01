<?php
/**
 * Minimal IMAP client over TLS.
 *
 * Why not ext-imap or a Composer library: ext-imap is missing on many hosts
 * and was removed from PHP 8.4 core, and the popular pure-PHP libraries pull
 * in Laravel/Carbon packages that can clash with other plugins' copies. We
 * only need a handful of commands, so this speaks IMAP4rev1 directly.
 *
 * Supports: LOGIN, SELECT, UID SEARCH, UID FETCH BODY.PEEK[], UID STORE,
 * Gmail labels (X-GM-LABELS), LOGOUT.
 *
 * No WordPress dependencies, so it can be unit tested on its own.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * IMAP client.
 */
class BST_Imap_Client {

	/**
	 * Socket.
	 *
	 * @var resource|null
	 */
	private $stream = null;

	/**
	 * Command tag counter.
	 *
	 * @var int
	 */
	private $tag = 0;

	/**
	 * Server capabilities (upper case).
	 *
	 * @var string[]
	 */
	private $capabilities = array();

	/**
	 * Host.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Port.
	 *
	 * @var int
	 */
	private $port;

	/**
	 * Socket timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $host    e.g. imap.gmail.com.
	 * @param int    $port    993 (implicit TLS).
	 * @param int    $timeout Seconds.
	 */
	public function __construct( $host, $port = 993, $timeout = 20 ) {
		$this->host    = $host;
		$this->port    = (int) $port;
		$this->timeout = (int) $timeout;
	}

	/**
	 * Close the connection if still open.
	 */
	public function __destruct() {
		if ( $this->stream ) {
			fclose( $this->stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Connect and log in.
	 *
	 * @param string $user     Username (full Gmail address).
	 * @param string $password Password (Gmail app password).
	 * @throws RuntimeException On failure.
	 */
	public function connect( $user, $password ) {
		$context = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
					'SNI_enabled'      => true,
				),
			)
		);

		$errno  = 0;
		$errstr = '';
		$stream = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- error reported via exception.
			'ssl://' . $this->host . ':' . $this->port,
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( ! $stream ) {
			throw new RuntimeException( 'Could not connect to ' . $this->host . ':' . $this->port . ' — ' . $errstr . ' (' . $errno . ')' );
		}

		$this->stream = $stream;
		stream_set_timeout( $this->stream, $this->timeout );

		$greeting = $this->read_line();
		if ( ! str_starts_with( $greeting, '* OK' ) ) {
			throw new RuntimeException( 'Unexpected server greeting: ' . trim( $greeting ) );
		}

		$this->command( 'LOGIN ' . self::quote( $user ) . ' ' . self::quote( $password ), 'Login failed — check BST_IMAP_USER and BST_IMAP_PASSWORD (Gmail needs an app password).' );

		$this->load_capabilities();
	}

	/**
	 * Select a mailbox.
	 *
	 * @param string $mailbox Mailbox name, e.g. INBOX.
	 * @throws RuntimeException On failure.
	 */
	public function select( $mailbox ) {
		$this->command( 'SELECT ' . self::quote( $mailbox ) );
	}

	/**
	 * UIDs of unseen messages.
	 *
	 * @return int[]
	 */
	public function search_unseen() {
		$response = $this->command( 'UID SEARCH UNSEEN' );
		$uids     = array();
		foreach ( $response['lines'] as $line ) {
			if ( preg_match( '/^\* SEARCH\s*(.*)$/i', trim( $line ), $m ) ) {
				foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $uid ) {
					if ( ctype_digit( $uid ) ) {
						$uids[] = (int) $uid;
					}
				}
			}
		}
		sort( $uids );
		return $uids;
	}

	/**
	 * Full raw RFC 822 message, without marking it read.
	 *
	 * @param int $uid Message UID.
	 * @return string
	 * @throws RuntimeException If the message has no body.
	 */
	public function fetch_raw( $uid ) {
		$response = $this->command( 'UID FETCH ' . (int) $uid . ' (BODY.PEEK[])' );
		if ( empty( $response['literals'] ) ) {
			throw new RuntimeException( 'No body returned for UID ' . (int) $uid );
		}
		// The message body is the largest literal in the response.
		usort(
			$response['literals'],
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $response['literals'][0];
	}

	/**
	 * Mark as read.
	 *
	 * @param int $uid Message UID.
	 */
	public function mark_seen( $uid ) {
		$this->command( 'UID STORE ' . (int) $uid . ' +FLAGS.SILENT (\\Seen)' );
	}

	/**
	 * Add a Gmail label, if the server is Gmail. Gmail creates the label if
	 * it doesn't exist. Other servers: no-op.
	 *
	 * @param int    $uid   Message UID.
	 * @param string $label Label name.
	 * @return bool Whether a label was applied.
	 */
	public function add_gmail_label( $uid, $label ) {
		if ( '' === $label || ! in_array( 'X-GM-EXT-1', $this->capabilities, true ) ) {
			return false;
		}
		$this->command( 'UID STORE ' . (int) $uid . ' +X-GM-LABELS (' . self::quote( $label ) . ')' );
		return true;
	}

	/**
	 * Log out and close.
	 */
	public function logout() {
		if ( ! $this->stream ) {
			return;
		}
		try {
			$this->command( 'LOGOUT' );
		} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Already closing — nothing useful to do.
		}
		fclose( $this->stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->stream = null;
	}

	/**
	 * Read CAPABILITY.
	 */
	private function load_capabilities() {
		$response = $this->command( 'CAPABILITY' );
		foreach ( $response['lines'] as $line ) {
			if ( preg_match( '/^\* CAPABILITY (.+)$/i', trim( $line ), $m ) ) {
				$this->capabilities = array_map( 'strtoupper', preg_split( '/\s+/', trim( $m[1] ) ) );
			}
		}
	}

	/**
	 * Send a tagged command and read until its tagged completion.
	 *
	 * @param string $command      Command without tag.
	 * @param string $fail_message Message for the exception on NO/BAD.
	 * @return array{lines:string[],literals:string[]}
	 * @throws RuntimeException On NO/BAD or connection loss.
	 */
	private function command( $command, $fail_message = '' ) {
		if ( ! $this->stream ) {
			throw new RuntimeException( 'Not connected.' );
		}

		$tag = 'B' . str_pad( (string) ++$this->tag, 4, '0', STR_PAD_LEFT );
		$this->write( $tag . ' ' . $command . "\r\n" );

		$lines    = array();
		$literals = array();

		while ( true ) {
			$line = $this->read_line();

			// A literal: {n}\r\n followed by exactly n bytes.
			while ( preg_match( '/\{(\d+)\}\r\n$/', $line, $m ) ) {
				$literals[] = $this->read_bytes( (int) $m[1] );
				$line       = $this->read_line(); // Rest of the response line after the literal.
			}

			if ( str_starts_with( $line, $tag . ' ' ) ) {
				$status = strtoupper( substr( $line, strlen( $tag ) + 1, 3 ) );
				if ( 'OK ' !== $status && 'OK' !== trim( $status ) ) {
					// Don't leak the password into logs: LOGIN failures use the friendly message.
					$detail = str_starts_with( $command, 'LOGIN ' ) ? 'LOGIN' : $command;
					throw new RuntimeException( $fail_message ? $fail_message : 'IMAP command failed (' . $detail . '): ' . trim( $line ) );
				}
				return array(
					'lines'    => $lines,
					'literals' => $literals,
				);
			}

			$lines[] = $line;
		}
	}

	/**
	 * Write to the socket.
	 *
	 * @param string $data Data.
	 * @throws RuntimeException On failure.
	 */
	private function write( $data ) {
		$written = fwrite( $this->stream, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === $written || $written < strlen( $data ) ) {
			throw new RuntimeException( 'Lost connection to the mail server while writing.' );
		}
	}

	/**
	 * Read one CRLF-terminated line.
	 *
	 * @return string
	 * @throws RuntimeException On timeout or EOF.
	 */
	private function read_line() {
		$line = fgets( $this->stream );
		if ( false === $line ) {
			$meta = stream_get_meta_data( $this->stream );
			throw new RuntimeException( ! empty( $meta['timed_out'] ) ? 'Mail server timed out.' : 'Mail server closed the connection.' );
		}
		return $line;
	}

	/**
	 * Read exactly $length bytes.
	 *
	 * @param int $length Bytes.
	 * @return string
	 * @throws RuntimeException On timeout or EOF.
	 */
	private function read_bytes( $length ) {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$chunk = fread( $this->stream, min( 8192, $length - strlen( $data ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || ( '' === $chunk && feof( $this->stream ) ) ) {
				throw new RuntimeException( 'Mail server closed the connection mid-message.' );
			}
			if ( '' === $chunk ) {
				$meta = stream_get_meta_data( $this->stream );
				if ( ! empty( $meta['timed_out'] ) ) {
					throw new RuntimeException( 'Mail server timed out mid-message.' );
				}
			}
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * Quote a string argument.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function quote( $value ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
	}
}
