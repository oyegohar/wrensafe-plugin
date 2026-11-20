<?php
/**
 * The kinds of updates Safe Updates can run: plugins, and any type an
 * add-on registers through the wpbp_safe_update_types filter.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

defined( 'ABSPATH' ) || exit;

final class UpdateTypes {

	/** @var array<string,UpdateType>|null */
	private static ?array $types = null;

	/**
	 * Every registered type, by key; plugins first.
	 *
	 * @return array<string,UpdateType>
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$plugin = new PluginUpdateType();

		/**
		 * Filters the kinds of updates Safe Updates can run. Add an object
		 * that implements WPBP\Updates\UpdateType, keyed by its key(), to let
		 * the update window, the Safe Updates page and the engine (restore
		 * point, site check, rollback, boot guard) handle another kind of
		 * update. Items of a run carry the type's key in 'type'.
		 *
		 * @param array<string,UpdateType> $types Key => type. 'plugin' is built in and can't be replaced.
		 */
		$filtered = (array) apply_filters( 'wpbp_safe_update_types', array( PluginUpdateType::KEY => $plugin ) );
		$types    = array( PluginUpdateType::KEY => $plugin );

		foreach ( $filtered as $type ) {
			if ( ! $type instanceof UpdateType ) {
				continue;
			}

			$key = sanitize_key( $type->key() );

			if ( '' === $key || isset( $types[ $key ] ) ) {
				continue;
			}

			$types[ $key ] = $type;
		}

		// Add-ons register their types while plugins load; a call before that isn't kept.
		if ( did_action( 'init' ) ) {
			self::$types = $types;
		}

		return $types;
	}

	public static function get( string $key ): ?UpdateType {
		$types = self::all();

		return $types[ '' !== $key ? $key : PluginUpdateType::KEY ] ?? null;
	}

	/** The type of a run's item (items saved before types existed are plugins). */
	public static function key_of( array $item ): string {
		$key = (string) ( $item['type'] ?? '' );

		return '' !== $key ? $key : PluginUpdateType::KEY;
	}

	/** The id of a run's item within its type. */
	public static function id_of( array $item ): string {
		return (string) ( $item['id'] ?? $item['plugin'] ?? '' );
	}

	/** Forget the registered types (tests, or after an add-on was switched on). */
	public static function reset(): void {
		self::$types = null;
	}
}
