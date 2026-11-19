<?php
/**
 * Error messages from exceptions.
 *
 * Exception messages are escaped where they are thrown (esc_html()), as
 * WordPress.org asks. Most of them are later shown as plain text: in a JSON
 * response that the admin script puts on screen with .text(), in the log, in a
 * plain-text email. text() turns the escaped message back into plain text for
 * those places, so nobody sees "&#039;" instead of an apostrophe. Anything that
 * prints a message into HTML escapes it again at that point.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

final class Errors {

	/** The message of $e as plain text. */
	public static function text( \Throwable $e ): string {
		return self::plain( $e->getMessage() );
	}

	/** An escaped message as plain text. */
	public static function plain( string $message ): string {
		return html_entity_decode( $message, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * A value for a message, with invalid UTF-8 replaced. esc_html() returns
	 * an empty string for invalid UTF-8, which would blank out the whole
	 * message when it names a file with an odd name or quotes a database error.
	 */
	public static function utf8( string $value ): string {
		return wp_check_invalid_utf8( $value, true );
	}
}
