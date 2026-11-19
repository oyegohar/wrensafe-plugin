<?php
/**
 * Path traversal protection utility.
 *
 * Centralises the "is this resolved path still inside the allowed root?"
 * check used by FileRestorer, ArchiveWriter, and any other component that
 * maps user-supplied or archive-derived filenames to disk paths.
 *
 * @package WPBP\Security
 */

namespace WPBP\Security;

defined( 'ABSPATH' ) || exit;

class PathValidator {

	/**
	 * Whether $path is within (or equal to) $root, an existing folder.
	 *
	 * Both are resolved the way the filesystem would resolve them: "." and
	 * ".." segments are applied, and every part that exists is replaced by
	 * its real location (symlinks followed). Parts that don't exist yet (a
	 * restore destination before extraction) are resolved by name, so the
	 * answer for "missing/../../x" is the same whether "missing" exists or not.
	 */
	public static function is_within( string $path, string $root ): bool {
		$real_root = realpath( $root );

		if ( false === $real_root ) {
			return false;
		}

		$resolved = self::resolve( $path );

		if ( null === $resolved ) {
			return false;
		}

		$real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' );

		return $resolved === $real_root || 0 === strpos( $resolved . '/', $real_root . '/' );
	}

	/**
	 * Where $path really points (forward slashes, no trailing slash), or
	 * null when that can't be told: a NUL byte, no working directory for a
	 * relative path, or a dangling symlink (it would write wherever it points).
	 */
	private static function resolve( string $path ): ?string {
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return null;
		}

		$real = realpath( $path );

		if ( false !== $real ) {
			return rtrim( str_replace( '\\', '/', $real ), '/' );
		}

		$path = str_replace( '\\', '/', $path );

		if ( ! preg_match( '#^(?:[A-Za-z]:)?/#', $path ) ) {
			$cwd = getcwd();

			if ( false === $cwd ) {
				return null;
			}

			$path = rtrim( str_replace( '\\', '/', $cwd ), '/' ) . '/' . $path;
		}

		$drive   = preg_match( '#^([A-Za-z]:)/#', $path, $m ) ? $m[1] : '';
		$current = $drive; // The top folder ("" or "C:"); segments are appended as "/name".
		$check   = true;   // Whether $current exists, so its children may too.

		foreach ( explode( '/', substr( $path, strlen( $drive ) ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				// $current is already resolved, so its parent by name is its real parent.
				$cut     = strrpos( $current, '/' );
				$current = false === $cut ? $drive : substr( $current, 0, $cut );
				$check   = true;
				continue;
			}

			$next = $current . '/' . $segment;

			if ( $check ) {
				$real = realpath( $next );

				if ( false !== $real ) {
					$current = rtrim( str_replace( '\\', '/', $real ), '/' );
					continue;
				}

				if ( is_link( $next ) ) {
					return null;
				}

				$check = false; // Nothing below a missing folder exists either.
			}

			$current = $next;
		}

		return $current;
	}

	/**
	 * Strip `../` and `..\` sequences from a relative path component.
	 */
	public static function sanitize_relative( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		// Remove ../ sequences repeatedly until none remain.
		while ( false !== strpos( $path, '../' ) ) {
			$path = str_replace( '../', '', $path );
		}
		return ltrim( $path, '/' );
	}

	/**
	 * Assert that $path is within $root. Returns false (does not throw) if not.
	 * Designed as a guard at extraction points.
	 */
	public static function assert_within( string $path, string $root ): bool {
		return self::is_within( $path, $root );
	}
}
