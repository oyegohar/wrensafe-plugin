<?php
/**
 * String checks that work on PHP 7.4.
 *
 * str_starts_with() and friends are PHP 8 functions. WordPress 5.9+ adds them
 * on older PHP versions, but Wrensafe doesn't rely on that.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

final class Str {

	public static function starts_with( string $haystack, string $needle ): bool {
		return '' === $needle || 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}

	public static function ends_with( string $haystack, string $needle ): bool {
		$length = strlen( $needle );

		return '' === $needle || ( strlen( $haystack ) >= $length && substr( $haystack, -$length ) === $needle );
	}

	public static function contains( string $haystack, string $needle ): bool {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}
