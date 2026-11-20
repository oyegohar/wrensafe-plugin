<?php
/**
 * Plugins, the update type built into Safe Updates.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Restore\PluginSafety;
use WPBP\Utils\Paths;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// keep_after_rollback() writes active_plugins straight to the database, see there.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PluginUpdateType extends AbstractUpdateType {

	const KEY = 'plugin';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'Plugins', 'wrensafe' );
	}

	public function capability(): string {
		return 'update_plugins';
	}

	public function strings(): array {
		return array(
			/* translators: %d: number of plugins */
			'updatingMany'    => __( '%d plugins', 'wrensafe' ),
			/* translators: %d: number of plugins */
			'updateMany'      => __( 'Update %d plugins', 'wrensafe' ),
			'progressTitle'   => __( 'Updating plugins', 'wrensafe' ),
			'cancelRun'       => __( 'Stop after this plugin', 'wrensafe' ),
			'cancelling'      => __( 'Stopping after the current plugin…', 'wrensafe' ),
			'orderOneHelp'    => __( 'Each plugin is updated and checked before the next one. Safest.', 'wrensafe' ),
			'orderAllHelp'    => __( 'Every plugin is updated, then the site is checked once. Faster.', 'wrensafe' ),
			'nothingSelected' => __( 'Select at least one plugin to update.', 'wrensafe' ),
			/* translators: 1: plugin name, 2: version number */
			'rollbackConfirm' => __( 'Put %1$s back to version %2$s? The files of the current version are replaced with the copy saved before the update.', 'wrensafe' ),
			'section'         => __( 'Plugin updates', 'wrensafe' ),
			'column'          => __( 'Plugin', 'wrensafe' ),
			'empty'           => __( 'All plugins are up to date.', 'wrensafe' ),
			'selectAll'       => __( 'Select every plugin that can be updated', 'wrensafe' ),
			'updateSelected'  => __( 'Update selected plugins', 'wrensafe' ),
		);
	}

	/**
	 * Plugins WordPress has an update for, by plugin basename.
	 */
	public function available(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$transient = get_site_transient( 'update_plugins' );
		$response  = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$installed = get_plugins();
		$active    = PluginSafety::stored_active_plugins();
		$excluded  = self::excluded();
		$list      = array();

		foreach ( $response as $plugin => $update ) {
			$plugin = (string) $plugin;

			if ( ! isset( $installed[ $plugin ] ) || ! is_object( $update ) ) {
				continue;
			}

			$from = (string) $installed[ $plugin ]['Version'];
			$to   = (string) ( $update->new_version ?? '' );

			if ( '' === $to || version_compare( $from, $to, '>=' ) ) {
				continue;
			}

			$problem = in_array( $plugin, $excluded, true )
				? __( 'Wrensafe doesn’t update itself here. Update it from the Plugins screen.', 'wrensafe' )
				: self::requirement_problem( $update, __( 'No download is available for this update. Premium plugins often need an active license.', 'wrensafe' ) );

			$list[ $plugin ] = array(
				'id'      => $plugin,
				'plugin'  => $plugin,
				'name'    => wp_strip_all_tags( (string) $installed[ $plugin ]['Name'] ),
				'from'    => $from,
				'to'      => $to,
				'active'  => in_array( $plugin, $active, true ),
				'tested'  => (string) ( $update->tested ?? '' ),
				'problem' => $problem,
			);
		}

		return $list;
	}

	/**
	 * Plugins never updated by Safe Updates: Wrensafe itself (a run can't
	 * replace the code that runs it).
	 *
	 * @return string[]
	 */
	public static function excluded(): array {
		/**
		 * Filters the plugins Safe Updates leaves to the Plugins screen.
		 *
		 * @param string[] $plugins Plugin basenames.
		 */
		return array_map( 'strval', (array) apply_filters( 'wpbp_safe_update_excluded_plugins', array( WPBP_PLUGIN_BASENAME ) ) );
	}

	/** The plugin's version on disk now ('' when its main file is missing). */
	public function installed_version( string $id ): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$file = WP_PLUGIN_DIR . '/' . $id;

		clearstatcache( true, $file );

		if ( ! is_file( $file ) ) {
			return '';
		}

		$data = get_plugin_data( $file, false, false );

		return (string) ( $data['Version'] ?? '' );
	}

	public function is_active( string $id ): bool {
		return in_array( $id, PluginSafety::stored_active_plugins(), true );
	}

	/**
	 * The plugin's folder name, or '' for a plugin that is a single file
	 * directly in wp-content/plugins.
	 */
	public static function plugin_folder( string $plugin ): string {
		$folder = dirname( $plugin );

		return '.' === $folder ? '' : $folder;
	}

	public function folder( string $id ): array {
		$folder = self::plugin_folder( $id );

		return array(
			'path' => Paths::plugins_dir() . '/' . ( '' !== $folder ? $folder : $id ),
			'main' => $id,
		);
	}

	public function upgrade( array $ids ): array {
		// WordPress refreshes its update list now and then; make sure it still has these.
		$current = get_site_transient( 'update_plugins' );

		foreach ( $ids as $plugin ) {
			if ( ! is_object( $current ) || ! isset( $current->response[ $plugin ] ) ) {
				wp_update_plugins();
				break;
			}
		}

		return $this->bulk_upgrade( \Plugin_Upgrader::class, $ids );
	}

	/**
	 * The plugin a reported file belongs to, or ''.
	 */
	public function owner_of_file( string $file, array $ids ): string {
		$file = wp_normalize_path( $file );

		if ( '' !== $file && '/' !== $file[0] && ! preg_match( '#^[A-Za-z]:/#', $file ) ) {
			$file = Paths::root() . '/' . ltrim( $file, '/' );
		}

		$root = Paths::plugins_dir() . '/';

		if ( ! Str::starts_with( $file, $root ) ) {
			return '';
		}

		$relative = substr( $file, strlen( $root ) );
		$folder   = explode( '/', $relative, 2 )[0];

		foreach ( $ids as $plugin ) {
			$plugin = (string) $plugin;

			if ( $plugin === $relative || ( Str::contains( $plugin, '/' ) && Str::starts_with( $plugin, $folder . '/' ) ) ) {
				return $plugin;
			}
		}

		return '';
	}

	/**
	 * A plugin that was active before the update stays active after the
	 * rollback. Written to the database directly, not with update_option():
	 * during a recovery the boot guard hides the plugin from this request's
	 * active_plugins (the option_active_plugins filter), so a read-modify-write
	 * through the options API would save the list without it.
	 */
	public function keep_after_rollback( array $item ): void {
		global $wpdb;

		$plugin = (string) ( $item['id'] ?? $item['plugin'] ?? '' );

		if ( empty( $item['active'] ) || '' === $plugin ) {
			return;
		}

		$active = PluginSafety::stored_active_plugins();

		if ( in_array( $plugin, $active, true ) ) {
			return;
		}

		$active[] = $plugin;
		sort( $active );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s',
				$wpdb->options,
				serialize( array_values( $active ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores active_plugins serialized.
				'active_plugins'
			)
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'active_plugins', 'options' );
	}

	/** Turn the plugin off so the site works again. */
	public function last_resort( array $item, int $job_id ): string {
		PluginSafety::deactivate( array( (string) ( $item['id'] ?? $item['plugin'] ?? '' ) ), $job_id );

		return __( 'The plugin was deactivated to keep the site working.', 'wrensafe' );
	}

	/**
	 * Remove the plugins just updated from WordPress's update list, without
	 * asking WordPress.org again.
	 */
	public function forget_update( array $ids ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_clean_plugins_cache( false );

		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return;
		}

		$installed = get_plugins();
		$changed   = false;

		foreach ( $transient->response as $plugin => $update ) {
			$version = isset( $installed[ $plugin ] ) ? (string) $installed[ $plugin ]['Version'] : '';

			if ( in_array( $plugin, $ids, true ) || ( '' !== $version && is_object( $update ) && version_compare( $version, (string) ( $update->new_version ?? '' ), '>=' ) ) ) {
				unset( $transient->response[ $plugin ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			set_site_transient( 'update_plugins', $transient );
		}
	}

	/** Wrensafe's own requests load without these plugins (see the guard). */
	public function guard_data( array $ids ): array {
		return array( 'plugins' => array_values( array_map( 'strval', $ids ) ) );
	}
}
