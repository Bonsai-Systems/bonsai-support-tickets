<?php
/**
 * Small MIME parser for inbound support email.
 *
 * Pulls out what the ticket system needs — sender, recipients, subject,
 * threading headers, the plain-text and HTML bodies, attachments, and
 * whether the message is an auto-reply — from a raw RFC 822 message.
 *
 * No WordPress dependencies, so it can be unit tested on its own.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * MIME parser.
 */
class BST_Mime_Parser {

	/**
	 * Parse a raw message.
	 *
	 * @param string $raw Raw RFC 822 message.
	 * @return array {
	 *     @type array    $headers     Lower-case name => list of decoded values.
	 *     @type string   $message_id  Message-ID including <>.
	 *     @type string   $in_reply_to In-Reply-To.
	 *     @type string[] $references  Message IDs from References.
	 *     @type string   $from_email  Lower-case sender address.
	 *     @type string   $from_name   Sender display name.
	 *     @type string[] $recipients  Lower-case addresses from To, Cc, Delivered-To, X-Original-To.
	 *     @type string   $subject     Decoded subject.
	 *     @type string   $text        text/plain body (UTF-8).
	 *     @type string   $html        text/html body (UTF-8).
	 *     @type array[]  $attachments Each: filename, content, mime.
	 *     @type bool     $is_auto     Auto-reply, bounce or bulk mail.
	 * }
	 */
	public static function parse( $raw ) {
		$raw = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );

		list( $head, $body ) = self::split( $raw );
		$headers             = self::parse_headers( $head );

		$result = array(
			'headers'     => $headers,
			'message_id'  => self::first_message_id( self::header( $headers, 'message-id' ) ),
			'in_reply_to' => self::first_message_id( self::header( $headers, 'in-reply-to' ) ),
			'references'  => self::message_ids( self::header( $headers, 'references' ) ),
			'from_email'  => '',
			'from_name'   => '',
			'recipients'  => array(),
			'subject'     => trim( self::header( $headers, 'subject' ) ),
			'text'        => '',
			'html'        => '',
			'attachments' => array(),
			'is_auto'     => false,
		);

		$from = self::parse_addresses( self::header( $headers, 'from' ) );
		if ( $from ) {
			$result['from_email'] = $from[0]['email'];
			$result['from_name']  = $from[0]['name'];
		}

		foreach ( array( 'to', 'cc', 'delivered-to', 'x-original-to' ) as $name ) {
			foreach ( $headers[ $name ] ?? array() as $value ) {
				foreach ( self::parse_addresses( $value ) as $address ) {
					$result['recipients'][] = $address['email'];
				}
			}
		}
		$result['recipients'] = array_values( array_unique( $result['recipients'] ) );

		self::walk_part( $headers, $body, $result );

		$result['is_auto'] = self::is_auto( $headers, $result['from_email'] );

		return $result;
	}

	/**
	 * Split head and body at the first blank line.
	 *
	 * @param string $raw Normalised (\n) message or part.
	 * @return string[] array( head, body ).
	 */
	private static function split( $raw ) {
		$pos = strpos( $raw, "\n\n" );
		if ( false === $pos ) {
			return array( $raw, '' );
		}
		return array( substr( $raw, 0, $pos ), substr( $raw, $pos + 2 ) );
	}

	/**
	 * Unfold and decode headers.
	 *
	 * @param string $head Header block.
	 * @return array Lower-case name => list of values.
	 */
	public static function parse_headers( $head ) {
		$head    = preg_replace( "/\n[ \t]+/", ' ', $head );
		$headers = array();
		foreach ( explode( "\n", $head ) as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon || 0 === $colon ) {
				continue;
			}
			$name               = strtolower( trim( substr( $line, 0, $colon ) ) );
			$headers[ $name ][] = self::decode_header( trim( substr( $line, $colon + 1 ) ) );
		}
		return $headers;
	}

	/**
	 * First value of a header, or ''.
	 *
	 * @param array  $headers Parsed headers.
	 * @param string $name    Lower-case name.
	 * @return string
	 */
	public static function header( array $headers, $name ) {
		return isset( $headers[ $name ][0] ) ? (string) $headers[ $name ][0] : '';
	}

	/**
	 * Decode RFC 2047 encoded words (=?UTF-8?B?...?=) to UTF-8.
	 *
	 * @param string $value Raw header value.
	 * @return string
	 */
	public static function decode_header( $value ) {
		if ( ! str_contains( $value, '=?' ) ) {
			return $value;
		}
		// Whitespace between adjacent encoded words is not significant.
		$value   = preg_replace( '/(\?=)\s+(=\?)/', '$1$2', $value );
		$decoded = function_exists( 'iconv_mime_decode' ) ? @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $decoded && function_exists( 'mb_decode_mimeheader' ) ) {
			$decoded = mb_decode_mimeheader( $value );
		}
		return false === $decoded ? $value : $decoded;
	}

	/**
	 * Parse "Name <a@b.com>, c@d.com" into a list of addresses.
	 *
	 * @param string $value Header value.
	 * @return array[] Each: email (lower case), name.
	 */
	public static function parse_addresses( $value ) {
		$out = array();
		foreach ( self::split_address_list( (string) $value ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^(.*)<([^>]+)>\s*$/s', $part, $m ) ) {
				$email = trim( $m[2] );
				$name  = trim( trim( $m[1] ), "\"' " );
			} else {
				$email = trim( $part, '<> ' );
				$name  = '';
			}
			if ( preg_match( '/^[^@\s]+@[^@\s]+$/', $email ) ) {
				$out[] = array(
					'email' => strtolower( $email ),
					'name'  => str_replace( '\\"', '"', $name ),
				);
			}
		}
		return $out;
	}

	/**
	 * Split an address list on commas that aren't inside quotes or <>.
	 *
	 * @param string $value Header value.
	 * @return string[]
	 */
	private static function split_address_list( $value ) {
		$parts   = array();
		$current = '';
		$quoted  = false;
		$angle   = false;
		$length  = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( '\\' === $char && $quoted && $i + 1 < $length ) {
				$current .= $char . $value[ ++$i ];
				continue;
			}
			if ( '"' === $char ) {
				$quoted = ! $quoted;
			} elseif ( '<' === $char && ! $quoted ) {
				$angle = true;
			} elseif ( '>' === $char && ! $quoted ) {
				$angle = false;
			} elseif ( ',' === $char && ! $quoted && ! $angle ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return $parts;
	}

	/**
	 * First <message-id> in a header value.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	private static function first_message_id( $value ) {
		$ids = self::message_ids( $value );
		return $ids ? $ids[0] : trim( $value );
	}

	/**
	 * All <message-id>s in a header value.
	 *
	 * @param string $value Header value.
	 * @return string[]
	 */
	private static function message_ids( $value ) {
		preg_match_all( '/<[^<>\s]+>/', (string) $value, $m );
		return $m[0];
	}

	/**
	 * Parse "type/subtype; key=value; key2="value"".
	 *
	 * @param string $value Header value.
	 * @return array array( 'type' => string, 'params' => array ).
	 */
	public static function parse_params_header( $value ) {
		$parts  = self::split_params( (string) $value );
		$type   = strtolower( trim( array_shift( $parts ) ?? '' ) );
		$params = array();
		$cont   = array(); // RFC 2231 continuations: name*0*, name*1* ...

		foreach ( $parts as $part ) {
			$eq = strpos( $part, '=' );
			if ( false === $eq ) {
				continue;
			}
			$key = strtolower( trim( substr( $part, 0, $eq ) ) );
			$val = trim( substr( $part, $eq + 1 ) );
			if ( strlen( $val ) >= 2 && '"' === $val[0] && '"' === substr( $val, -1 ) ) {
				$val = str_replace( '\\"', '"', substr( $val, 1, -1 ) );
			}

			if ( preg_match( '/^([a-z0-9_-]+)\*(\d+)?(\*)?$/', $key, $m ) ) {
				$cont[ $m[1] ][ (int) ( $m[2] ?? 0 ) ] = array( $val, ! empty( $m[3] ) || ( '' === ( $m[2] ?? '' ) ) );
				continue;
			}
			$params[ $key ] = self::decode_header( $val );
		}

		foreach ( $cont as $name => $pieces ) {
			ksort( $pieces );
			$joined  = '';
			$charset = 'UTF-8';
			foreach ( $pieces as $index => $piece ) {
				list( $val, $encoded ) = $piece;
				if ( $encoded && 0 === $index && preg_match( "/^([^']*)'[^']*'(.*)$/", $val, $m ) ) {
					$charset = $m[1] ? $m[1] : 'UTF-8';
					$val     = $m[2];
				}
				$joined .= $encoded ? rawurldecode( $val ) : $val;
			}
			$params[ $name ] = self::to_utf8( $joined, $charset );
		}

		return array(
			'type'   => $type,
			'params' => $params,
		);
	}

	/**
	 * Split a header on semicolons outside quotes.
	 *
	 * @param string $value Header value.
	 * @return string[]
	 */
	private static function split_params( $value ) {
		$parts   = array();
		$current = '';
		$quoted  = false;
		$length  = strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( '"' === $char ) {
				$quoted = ! $quoted;
			}
			if ( ';' === $char && ! $quoted ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return $parts;
	}

	/**
	 * Walk a MIME part, collecting bodies and attachments into $result.
	 *
	 * @param array  $headers Part headers.
	 * @param string $body    Part body.
	 * @param array  $result  Collected result (by reference).
	 * @param int    $depth   Recursion guard.
	 */
	private static function walk_part( array $headers, $body, array &$result, $depth = 0 ) {
		if ( $depth > 10 ) {
			return;
		}

		$ct          = self::parse_params_header( self::header( $headers, 'content-type' ) );
		$type        = $ct['type'] ? $ct['type'] : 'text/plain';
		$disposition = self::parse_params_header( self::header( $headers, 'content-disposition' ) );

		if ( str_starts_with( $type, 'multipart/' ) && ! empty( $ct['params']['boundary'] ) ) {
			foreach ( self::split_multipart( $body, $ct['params']['boundary'] ) as $part ) {
				list( $part_head, $part_body ) = self::split( $part );
				self::walk_part( self::parse_headers( $part_head ), $part_body, $result, $depth + 1 );
			}
			return;
		}

		$content  = self::decode_body( $body, strtolower( trim( self::header( $headers, 'content-transfer-encoding' ) ) ) );
		$filename = $disposition['params']['filename'] ?? ( $ct['params']['name'] ?? '' );
		$is_file  = '' !== $filename || 'attachment' === $disposition['type'];

		if ( $is_file ) {
			$result['attachments'][] = array(
				'filename' => '' !== $filename ? self::clean_filename( $filename ) : 'attachment',
				'content'  => $content,
				'mime'     => $type,
			);
			return;
		}

		$charset = $ct['params']['charset'] ?? 'UTF-8';

		if ( 'text/plain' === $type && '' === $result['text'] ) {
			$result['text'] = self::to_utf8( $content, $charset );
		} elseif ( 'text/html' === $type && '' === $result['html'] ) {
			$result['html'] = self::to_utf8( $content, $charset );
		}
	}

	/**
	 * Split a multipart body into its parts.
	 *
	 * @param string $body     Body.
	 * @param string $boundary Boundary.
	 * @return string[]
	 */
	private static function split_multipart( $body, $boundary ) {
		$delimiter = '--' . $boundary;
		$parts     = array();
		$current   = null;

		foreach ( explode( "\n", $body ) as $line ) {
			$trimmed = rtrim( $line );
			if ( $trimmed === $delimiter . '--' ) {
				break;
			}
			if ( $trimmed === $delimiter ) {
				if ( null !== $current ) {
					$parts[] = $current;
				}
				$current = '';
				continue;
			}
			if ( null !== $current ) {
				$current .= $line . "\n";
			}
		}
		if ( null !== $current && '' !== $current ) {
			$parts[] = $current;
		}

		// The line break before a delimiter belongs to the delimiter.
		return array_map(
			function ( $part ) {
				return str_ends_with( $part, "\n" ) ? substr( $part, 0, -1 ) : $part;
			},
			$parts
		);
	}

	/**
	 * Undo Content-Transfer-Encoding.
	 *
	 * @param string $body     Body.
	 * @param string $encoding base64|quoted-printable|7bit|8bit|binary.
	 * @return string
	 */
	private static function decode_body( $body, $encoding ) {
		if ( 'base64' === $encoding ) {
			$decoded = base64_decode( preg_replace( '/[^A-Za-z0-9+\/=]/', '', $body ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MIME decoding.
			return false === $decoded ? '' : $decoded;
		}
		if ( 'quoted-printable' === $encoding ) {
			return quoted_printable_decode( str_replace( "\n", "\r\n", $body ) );
		}
		return $body;
	}

	/**
	 * Convert to UTF-8 from a declared charset.
	 *
	 * @param string $value   Text.
	 * @param string $charset Declared charset.
	 * @return string
	 */
	public static function to_utf8( $value, $charset ) {
		$charset = strtoupper( trim( (string) $charset, "\"' " ) );
		if ( '' === $charset || 'UTF-8' === $charset || 'US-ASCII' === $charset ) {
			return function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) && function_exists( 'mb_convert_encoding' )
				? mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' )
				: $value;
		}
		try {
			$converted = function_exists( 'mb_convert_encoding' ) ? @mb_convert_encoding( $value, 'UTF-8', $charset ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( ValueError $e ) {
			$converted = false; // Unknown charset name.
		}
		if ( false === $converted && function_exists( 'iconv' ) ) {
			$converted = @iconv( $charset, 'UTF-8//IGNORE', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return false === $converted ? $value : $converted;
	}

	/**
	 * Strip paths and control characters from an attachment name.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function clean_filename( $name ) {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		return '' !== $name ? $name : 'attachment';
	}

	/**
	 * Auto-replies, bounces and bulk mail — never turned into tickets.
	 * Answering them is how mail loops start.
	 *
	 * @param array  $headers    Parsed headers.
	 * @param string $from_email Sender.
	 * @return bool
	 */
	private static function is_auto( array $headers, $from_email ) {
		$auto_submitted = strtolower( self::header( $headers, 'auto-submitted' ) );
		if ( '' !== $auto_submitted && 'no' !== $auto_submitted ) {
			return true;
		}

		foreach ( array( 'x-autoreply', 'x-autorespond', 'x-autoresponse' ) as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				return true;
			}
		}

		$precedence = strtolower( self::header( $headers, 'precedence' ) );
		if ( in_array( $precedence, array( 'bulk', 'junk', 'list', 'auto_reply' ), true ) ) {
			return true;
		}

		if ( isset( $headers['list-id'] ) || isset( $headers['list-unsubscribe'] ) ) {
			return true;
		}

		if ( '<>' === trim( self::header( $headers, 'return-path' ) ) ) {
			return true;
		}

		return (bool) preg_match( '/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/i', $from_email );
	}
}
