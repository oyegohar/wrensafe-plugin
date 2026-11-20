<?php
/**
 * Tables of other WordPress sites that share this site's database.
 *
 * Two sites can share one database when their table prefixes differ. When
 * the other site's prefix starts with this site's (wp_ and wp_shop_, or a
 * staging copy's wp_stg_…_), its tables also start with this site's
 * prefix, so "every table with my prefix" would take them in: a backup
 * would copy them and a restore would overwrite them.
 *
 * Such a longer prefix is recognised by its own options and posts tables
 * ({other}options and {other}posts). In a multisite network the subsites'
 * tables ({base}2_options, {base}2_posts, …) look the same way but belong
 * to this site, so numeric subsite prefixes are not counted.
 *
 * Used by the backup's table list (and so by staging, the search and
 * replace tool and the excluded-tables setting), by the restore, and by
 * the clean-up of a restore's work tables.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

final class SharedDatabase {

	/**
	 * The prefixes of other sites among $tables: each one longer than
	 * $prefix, starting with it, with both an options and a posts table.
	 *
	 * @param string[] $tables       Table names (any; only those starting with $prefix count).
	 * @param string   $subsite_base The network's base prefix when this site is a multisite
	 *                               network ('' otherwise): {base}{number}_ is a subsite of it.
	 * @return string[]
	 */
	public static function other_install_prefixes( array $tables, string $prefix, string $subsite_base = '' ): array {
		$index  = array_flip( array_map( 'strval', $tables ) );
		$others = array();
		$length = strlen( $prefix . 'options' );

		foreach ( array_keys( $index ) as $table ) {
			$table = (string) $table;

			if ( '' === $prefix || strlen( $table ) <= $length || ! Str::starts_with( $table, $prefix ) || ! Str::ends_with( $table, 'options' ) ) {
				continue;
			}

			$other = substr( $table, 0, -strlen( 'options' ) );

			if ( ! isset( $index[ $other . 'posts' ] ) ) {
				continue;
			}

			if ( '' !== $subsite_base && 1 === preg_match( '/^' . preg_quote( $subsite_base, '/' ) . '\d+_$/', $other ) ) {
				continue;
			}

			$others[] = $other;
		}

		return array_values( array_unique( $others ) );
	}

	/**
	 * Whether $table belongs to one of the other sites' prefixes.
	 *
	 * @param string[] $other_prefixes From other_install_prefixes().
	 */
	public static function belongs_to_other( string $table, array $other_prefixes ): bool {
		foreach ( $other_prefixes as $other ) {
			if ( '' !== $other && Str::starts_with( $table, $other ) ) {
				return true;
			}
		}

		return false;
	}

	/** The base prefix to pass as $subsite_base for this site ('' unless multisite). */
	public static function subsite_base(): string {
		global $wpdb;

		return is_multisite() ? (string) $wpdb->base_prefix : '';
	}
}
