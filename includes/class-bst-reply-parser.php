<?php
/**
 * Pulls the new text out of an email reply, dropping the quoted history
 * and signature underneath it.
 *
 * Order of attempts:
 * 1. Our own "type your reply above this line" marker (most reliable).
 * 2. Common quote headers: "On ... wrote:", Outlook's "From: / Sent:"
 *    block, "-----Original Message-----", underscore separators.
 * 3. Trailing ">" quoted lines and a "-- " signature.
 *
 * No WordPress dependencies, so it can be unit tested on its own.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reply parser.
 */
class BST_Reply_Parser {

	/**
	 * The marker text (without the ##- -## wrapper, which some clients mangle).
	 */
	const MARKER = 'Please type your reply above this line';

	/**
	 * Get the new part of a reply as plain text.
	 *
	 * @param string $text text/plain body.
	 * @param string $html text/html body, used when there's no plain text.
	 * @return string
	 */
	public static function extract( $text, $html = '' ) {
		$text = (string) $text;
		if ( '' === trim( $text ) && '' !== trim( (string) $html ) ) {
			$text = self::html_to_text( $html );
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// 1. Our marker: everything from the marker's line down is history.
		$pos = stripos( $text, self::MARKER );
		if ( false !== $pos ) {
			$line_start = strrpos( substr( $text, 0, $pos ), "\n" );
			$text       = false === $line_start ? '' : substr( $text, 0, $line_start );
		}

		$lines = explode( "\n", $text );
		$cut   = self::find_quote_header( $lines );
		if ( null !== $cut ) {
			$lines = array_slice( $lines, 0, $cut );
		}

		// 3. Signature delimiter "-- " (RFC 3676).
		foreach ( $lines as $i => $line ) {
			if ( '-- ' === $line || '--' === rtrim( $line ) ) {
				$lines = array_slice( $lines, 0, $i );
				break;
			}
		}

		// Trailing quoted lines and blank lines.
		while ( $lines ) {
			$last = trim( end( $lines ) );
			if ( '' === $last || str_starts_with( $last, '>' ) ) {
				array_pop( $lines );
				continue;
			}
			break;
		}

		// "Sent from my iPhone" style lines at the very end.
		while ( $lines && preg_match( '/^(sent from my |get outlook for )/i', trim( end( $lines ) ) ) ) {
			array_pop( $lines );
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Index of the first line of quoted history, or null.
	 *
	 * @param string[] $lines Lines.
	 * @return int|null
	 */
	private static function find_quote_header( array $lines ) {
		$count = count( $lines );

		for ( $i = 0; $i < $count; $i++ ) {
			$line = trim( $lines[ $i ] );

			// Gmail / Apple Mail: "On Wed, 1 Oct 2026 at 10:00, Name <a@b.com> wrote:" — may wrap over two or three lines.
			if ( preg_match( '/^On\s.+/i', $line ) ) {
				$joined = $line;
				for ( $j = 1; $j <= 2 && $i + $j < $count; $j++ ) {
					$joined .= ' ' . trim( $lines[ $i + $j ] );
					if ( preg_match( '/wrote:\s*$/i', $joined ) ) {
						return $i;
					}
				}
				if ( preg_match( '/wrote:\s*$/i', $line ) ) {
					return $i;
				}
			}

			// "-----Original Message-----".
			if ( preg_match( '/^-{2,}\s*(Original Message|Forwarded message)\s*-{2,}/i', $line ) ) {
				return $i;
			}

			// Outlook separator line.
			if ( preg_match( '/^_{10,}$/', $line ) ) {
				return $i;
			}

			// Outlook header block: "From: ..." followed by "Sent:" or "Date:" within 3 lines.
			if ( preg_match( '/^\*?From:\*?\s.+/i', $line ) ) {
				for ( $j = 1; $j <= 3 && $i + $j < $count; $j++ ) {
					if ( preg_match( '/^\*?(Sent|Date):\*?\s/i', trim( $lines[ $i + $j ] ) ) ) {
						return $i;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Rough HTML to text for replies that only have an HTML part.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		// Gmail wraps quoted history in div.gmail_quote, Outlook in #divRplyFwdMsg / #appendonsend.
		$html = preg_replace( '#<div[^>]+class="[^"]*gmail_quote[^"]*".*$#is', '', $html );
		$html = preg_replace( '#<div[^>]+id="(divRplyFwdMsg|appendonsend)".*$#is', '', $html );
		$html = preg_replace( '#<blockquote.*?</blockquote>#is', '', $html );
		$html = preg_replace( '#<(head|style|script)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<(br|/p|/div|/h[1-6]|/li|/tr)[^>]*>#i', "\n", $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = preg_replace( "/\n[ \t]+/", "\n", $text );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}
}
