<?php
/**
 * Helpers for handling PHP serialized data safely during search-replace.
 *
 * WordPress stores many option and meta values as serialized PHP strings.
 * Naive string replacement breaks the byte-count metadata that makes
 * serialized strings valid, so replacements here walk the serialized
 * grammar directly: every string token is rewritten and its "s:N:" length
 * recomputed, while everything else (array keys, object class names,
 * private/protected property names, integers, custom "C:" payloads, enums)
 * is copied byte-for-byte.
 *
 * Nothing is ever unserialized, which means:
 *  - no PHP object injection is possible,
 *  - objects whose classes aren't loaded (which unserialize() would turn
 *    into __PHP_Incomplete_Class, and which throw an Error the moment a
 *    property is written) are handled like any other value,
 *  - values round-trip exactly (no float/precision or key-order drift).
 *
 * Plain and JSON text is replaced in a single strtr() pass over every
 * search form (raw, JSON-escaped and URL-encoded), so a replacement is
 * never re-replaced by a later, shorter pair (e.g. site.com → site.com/sub
 * can no longer produce /sub/sub/ inside an already-rewritten uploads URL),
 * and JSON is never decoded/re-encoded (which turned "{}" into "[]").
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

class Serializer {

	/** What makes a longer address of a site address: a letter, digit, "-", ".x" or a port. */
	public const ADDRESS_CONTINUES = '(?:[\w-]|\.[A-Za-z0-9]|:\d|%3[Aa]\d)';

	/** Nesting limit for the serialized-data walker. */
	private const MAX_DEPTH = 512;

	/** @var bool Whether the running replacement changes whole addresses only (see replace_addresses()). */
	private static $whole_addresses = false;

	/**
	 * Whether a string looks like serialized PHP data.
	 */
	public static function is_serialized( string $data ): bool {
		return is_serialized( $data );
	}

	/**
	 * Safely unserialize a string, returning null on failure.
	 * Only allows non-object types to prevent PHP object injection.
	 *
	 * @return mixed|null
	 */
	public static function safe_unserialize( string $data ) {
		if ( ! self::is_serialized( $data ) ) {
			return null;
		}

		$result = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		return false === $result ? null : $result;
	}

	/**
	 * Replace $search with $replace inside a value, preserving serialized
	 * PHP data and JSON integrity. Non-string values are returned unchanged.
	 *
	 * @param mixed  $data    Value to search within.
	 * @param string $search  String to find.
	 * @param string $replace Replacement string.
	 * @return mixed
	 */
	public static function deep_replace( $data, string $search, string $replace ) {
		if ( ! is_string( $data ) || '' === $search || $search === $replace ) {
			return $data;
		}

		return self::replace( $data, array( $search => $replace ) );
	}

	/**
	 * Apply several search => replace pairs to a string in one pass.
	 *
	 * @param string                $value Raw database value.
	 * @param array<string, string> $map   Search => replace.
	 */
	public static function replace( string $value, array $map ): string {
		$map = self::expand_map( $map );

		if ( empty( $map ) || ! self::contains_any( $value, $map ) ) {
			return $value;
		}

		return self::replace_in_string( $value, $map, 0 );
	}

	/**
	 * Like replace(), for site addresses: only whole addresses change. "https://old.com" doesn't
	 * touch "https://old.com.au", "https://old.com-shop.net" or "https://old.com:8443".
	 *
	 * @param array<string, string> $map Search => replace.
	 */
	public static function replace_addresses( string $value, array $map ): string {
		$map = self::expand_map( $map );

		if ( empty( $map ) || ! self::contains_any( $value, $map ) ) {
			return $value;
		}

		// Worked out per string, right before it is replaced: the cost stays in step with the value's size.
		self::$whole_addresses = true;

		try {
			return self::replace_in_string( $value, $map, 0 );
		} finally {
			self::$whole_addresses = false;
		}
	}

	/**
	 * The map plus, for each longer address in this value, a pair that leaves it as it is: strtr()
	 * takes the longest match at each position, so it wins over the shorter address.
	 *
	 * @param array<string, string> $map Expanded search => replace.
	 * @return array<string, string>
	 */
	private static function keep_longer_addresses( string $value, array $map ): array {
		$addresses = array();

		foreach ( array_keys( $map ) as $search ) {
			$search = (string) $search;

			// A web address in any of its forms: plain, protocol-relative, JSON-escaped, URL-encoded.
			// One ending in a slash is a whole path already: nothing can make it a longer address.
			if ( '/' !== substr( $search, -1 ) && '%2F' !== strtoupper( substr( $search, -3 ) ) && false !== strpos( $value, $search ) && 1 === preg_match( '~^(?:https?(?::|%3A))?(?:\\\\?/\\\\?/|%2F%2F)~i', $search ) ) {
				$addresses[] = $search;
			}
		}

		if ( ! $addresses ) {
			return $map;
		}

		// A file-transfer address on the same host ("ftp://old.com") is a server, not a link to the site.
		$bare = array_filter(
			$addresses,
			static function ( string $address ): bool {
				return 1 === preg_match( '~^(?:\\\\?/\\\\?/|%2F%2F)~i', $address );
			}
		);

		if ( $bare && preg_match_all( '~(?:ftps?|sftp)(?::|%3A)(?:' . implode( '|', array_map( 'preg_quote', $bare, array_fill( 0, count( $bare ), '~' ) ) ) . ')~i', $value, $transfer ) ) {
			foreach ( array_unique( $transfer[0] ) as $keep ) {
				$map[ $keep ] = $keep;
			}
		}

		usort(
			$addresses,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$quoted = array_map(
			static function ( string $address ): string {
				return preg_quote( $address, '~' );
			},
			$addresses
		);

		// The address and the one character (or ".x", ":8", "%3A8") that makes it a longer one: enough for
		// strtr() to prefer it, and few distinct lengths however many longer addresses a value holds.
		if ( ! preg_match_all( '~(?:' . implode( '|', $quoted ) . ')' . self::ADDRESS_CONTINUES . '~', $value, $found ) ) {
			return $map;
		}

		foreach ( array_unique( $found[0] ) as $longer ) {
			// "https://old.com/uploads2" is still on the old site when "https://old.com" is an address too:
			// it moves with that address, not with a longer one it only starts like (".../uploads").
			foreach ( $addresses as $address ) {
				if ( strlen( $address ) < strlen( $longer ) && 0 === strpos( $longer, $address ) && 1 !== preg_match( '~^' . self::ADDRESS_CONTINUES . '~', substr( $longer, strlen( $address ) ) ) ) {
					$map[ $longer ] = $map[ $address ] . substr( $longer, strlen( $address ) );
					continue 2;
				}
			}

			$map[ $longer ] = $longer;
		}

		return $map;
	}

	/**
	 * Add the JSON-escaped ("http:\/\/…") and URL-encoded ("http%3A%2F%2F…")
	 * form of each pair, so URLs inside JSON blobs (page builders) and query
	 * strings are rewritten too, without decoding/re-encoding anything.
	 *
	 * @param array<string, string> $map Search => replace.
	 * @return array<string, string>
	 */
	public static function expand_map( array $map ): array {
		$expanded = array();

		foreach ( $map as $search => $replace ) {
			$search  = (string) $search;
			$replace = (string) $replace;

			if ( '' === $search || $search === $replace ) {
				continue;
			}

			$expanded[ $search ] = $replace;

			$json_search  = substr( (string) wp_json_encode( $search ), 1, -1 );
			$json_replace = substr( (string) wp_json_encode( $replace ), 1, -1 );

			if ( '' !== $json_search && $json_search !== $search ) {
				$expanded[ $json_search ] = $json_replace;
			}

			$url_search = rawurlencode( $search );

			if ( $url_search !== $search ) {
				$expanded[ $url_search ] = rawurlencode( $replace );
			}
		}

		return $expanded;
	}

	/**
	 * @param array<string, string> $map Expanded search => replace.
	 */
	private static function contains_any( string $value, array $map ): bool {
		foreach ( $map as $search => $replace ) {
			if ( false !== strpos( $value, (string) $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, string> $map Expanded search => replace.
	 */
	private static function replace_in_string( string $value, array $map, int $depth ): string {
		if ( $depth < self::MAX_DEPTH && self::is_serialized( $value ) ) {
			$offset = 0;
			$result = self::walk( $value, $offset, $map, $depth + 1 );

			// Accept the rewrite only if the whole value parsed cleanly; a
			// malformed serialized string falls back to plain replacement,
			// exactly as before.
			if ( null !== $result && '' === trim( substr( $value, $offset ) ) ) {
				return $result . substr( $value, $offset );
			}
		}

		return strtr( $value, self::$whole_addresses ? self::keep_longer_addresses( $value, $map ) : $map );
	}

	/**
	 * Copy (when $map is null) or rewrite one serialized value starting at
	 * $offset, advancing $offset past it. Returns null on malformed input.
	 *
	 * @param array<string, string>|null $map Expanded map, or null to copy verbatim.
	 */
	private static function walk( string $data, int &$offset, ?array $map, int $depth ): ?string {
		if ( $depth > self::MAX_DEPTH || ! isset( $data[ $offset ] ) ) {
			return null;
		}

		$type = $data[ $offset ];

		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $data, $offset, 2 ) ) {
					return null;
				}
				$offset += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $data, ';', $offset );

				if ( false === $end || ':' !== ( $data[ $offset + 1 ] ?? '' ) ) {
					return null;
				}

				$token  = substr( $data, $offset, $end - $offset + 1 );
				$offset = $end + 1;
				return $token;

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}

				$length = (int) $m[1];
				$start  = $offset + strlen( $m[0] );

				if ( '";' !== substr( $data, $start + $length, 2 ) ) {
					return null;
				}

				$content = substr( $data, $start, $length );
				$offset  = $start + $length + 2;

				if ( null === $map ) {
					return 's:' . $length . ':"' . $content . '";';
				}

				$content = self::contains_any( $content, $map ) ? self::replace_in_string( $content, $map, $depth ) : $content;

				return 's:' . strlen( $content ) . ':"' . $content . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $m, 0, $offset ) ) {
					return null;
				}

				$offset += strlen( $m[0] );
				$body    = self::walk_members( $data, $offset, (int) $m[1], $map, $depth );

				return null === $body ? null : $m[0] . $body;

			case 'O':
				// O:<len>:"<Class>":<count>:{<members>}
				if ( ! preg_match( '/\GO:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}

				$class_end = $offset + strlen( $m[0] ) + (int) $m[1];

				if ( ! preg_match( '/\G":(\d+):\{/', $data, $m2, 0, $class_end ) ) {
					return null;
				}

				$header = substr( $data, $offset, $class_end - $offset ) . $m2[0];
				$offset = $class_end + strlen( $m2[0] );
				$body   = self::walk_members( $data, $offset, (int) $m2[1], $map, $depth );

				return null === $body ? null : $header . $body;

			case 'C':
				// C:<len>:"<Class>":<len>:{<opaque payload>} — owned by the
				// class's own unserialize(); copied verbatim.
				if ( ! preg_match( '/\GC:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}

				$class_end = $offset + strlen( $m[0] ) + (int) $m[1];

				if ( ! preg_match( '/\G":(\d+):\{/', $data, $m2, 0, $class_end ) ) {
					return null;
				}

				$end = $class_end + strlen( $m2[0] ) + (int) $m2[1];

				if ( '}' !== ( $data[ $end ] ?? '' ) ) {
					return null;
				}

				$token  = substr( $data, $offset, $end - $offset + 1 );
				$offset = $end + 1;
				return $token;

			case 'E':
				// PHP 8.1+ enum case: E:<len>:"Class:Case";
				if ( ! preg_match( '/\GE:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}

				$end = $offset + strlen( $m[0] ) + (int) $m[1];

				if ( '";' !== substr( $data, $end, 2 ) ) {
					return null;
				}

				$token  = substr( $data, $offset, $end - $offset + 2 );
				$offset = $end + 2;
				return $token;

			default:
				return null;
		}
	}

	/**
	 * Walk $count key/value pairs and the closing brace of an array/object.
	 * Keys (array keys, property names) are always copied verbatim.
	 *
	 * @param array<string, string>|null $map Expanded map, or null to copy verbatim.
	 */
	private static function walk_members( string $data, int &$offset, int $count, ?array $map, int $depth ): ?string {
		$out = '';

		for ( $i = 0; $i < $count; $i++ ) {
			$key = self::walk( $data, $offset, null, $depth + 1 );

			if ( null === $key ) {
				return null;
			}

			$value = self::walk( $data, $offset, $map, $depth + 1 );

			if ( null === $value ) {
				return null;
			}

			$out .= $key . $value;
		}

		if ( '}' !== ( $data[ $offset ] ?? '' ) ) {
			return null;
		}

		++$offset;

		return $out . '}';
	}

	/**
	 * Fix broken serialized string byte counts after a raw str_replace was
	 * applied to serialized data. Useful as a last-resort repair pass.
	 */
	public static function fix_broken_serialized( string $data ): string {
		return preg_replace_callback(
			'/s:(\d+):"(.*?)";/s',
			static function ( array $matches ): string {
				$correct_length = strlen( $matches[2] );
				return 's:' . $correct_length . ':"' . $matches[2] . '";';
			},
			$data
		) ?? $data;
	}
}
