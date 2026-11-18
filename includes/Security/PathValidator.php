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
	 * Whether $path is within (or equal to) $root after realpath resolution.
	 *
	 * Falls back to a simpler string-prefix check when the path does not
	 * yet exist on disk (e.g. a restore destination before extraction).
	 */
	public static function is_within( string $path, string $root ): bool {
		$real_root = realpath( $root );

		if ( false === $real_root ) {
			return false;
		}

		$real_path = realpath( $path );

		if ( false !== $real_path ) {
			return 0 === strpos( $real_path . '/', $real_root . '/' );
		}

		// Path doesn't exist yet — walk up parent directories until we find one.
		$test = $path;

		for ( $i = 0; $i < 50; $i++ ) {
			$parent = dirname( $test );

			if ( $parent === $test ) {
				break;
			}

			$real_parent = realpath( $parent );

			if ( false !== $real_parent ) {
				return 0 === strpos( $real_parent . '/', $real_root . '/' );
			}

			$test = $parent;
		}

		// Last resort: check for literal ../ sequences.
		return false === strpos( str_replace( '\\', '/', $path ), '../' );
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
