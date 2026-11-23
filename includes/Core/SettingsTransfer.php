<?php
/**
 * Export, import and reset of Wrensafe's settings (Settings → Import / Export).
 *
 * Only settings that are safe to move between sites are handled, each checked
 * against its own rule. A settings file can never set passwords, off-site
 * storage, the backup folder, the notification address or a security option,
 * so a file from someone else can't send backups or reports anywhere, or weaken
 * a protection. The settings as they were are kept once, so a change can be undone.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Core\Database\JobsRepository;
use WPBP\Security\CapabilityManager;
use WPBP\Updates\SafeUpdateManager;
use WPBP\Updates\SafeUpdates;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class SettingsTransfer {

	/** The file format's version. */
	const SCHEMA = 1;

	/** The settings as they were before the last import or reset. */
	const UNDO = 'wpbp_settings_undo';

	const BEFORE_UPDATES = 'wpbp_backup_before_updates';

	/** Settings a reset puts back and an undo restores, but a file can never set. */
	const RESET_ONLY = array( 'delete_data_on_uninstall', 'allow_locked_backup_downloads' );

	/** @return array<string,string> Group => label. */
	public static function groups(): array {
		return array(
			'general'       => __( 'General', 'wrensafe' ),
			'backup'        => __( 'Backup', 'wrensafe' ),
			'updates'       => __( 'Updates', 'wrensafe' ),
			'notifications' => __( 'Notifications', 'wrensafe' ),
			'migration'     => __( 'Migration', 'wrensafe' ),
			'advanced'      => __( 'Advanced', 'wrensafe' ),
		);
	}

	/**
	 * Every setting a file can carry, with its rule. "bbu_" settings live in the
	 * "back up before updates" option.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function spec(): array {
		$bool  = static function ( string $group, string $label ): array {
			return array(
				'group' => $group,
				'type'  => 'bool',
				'label' => $label,
			);
		};
		$int   = static function ( string $group, string $label, int $min, int $max ): array {
			return array(
				'group' => $group,
				'type'  => 'int',
				'label' => $label,
				'min'   => $min,
				'max'   => $max,
			);
		};
		$modes = array();

		foreach ( SafeUpdates::modes() as $mode => $data ) {
			$modes[ (string) $mode ] = (string) ( $data['label'] ?? $mode );
		}

		return array(
			'enable_activity_log'          => $bool( 'general', __( 'Activity log', 'wrensafe' ) ),
			'exclude_backup_archives'      => $bool( 'backup', __( 'Skip other plugins’ backup files', 'wrensafe' ) ),
			'exclude_cache_folders'        => $bool( 'backup', __( 'Skip cache folders', 'wrensafe' ) ),
			'custom_excluded_paths'        => array(
				'group' => 'backup',
				'type'  => 'textarea',
				'label' => __( 'Excluded paths', 'wrensafe' ),
			),
			'custom_excluded_extensions'   => array(
				'group' => 'backup',
				'type'  => 'text',
				'label' => __( 'Excluded file types', 'wrensafe' ),
			),
			'verify_after_backup'          => $bool( 'backup', __( 'Check each backup after it’s made', 'wrensafe' ) ),
			'exclude_larger_than_mb'       => $int( 'backup', __( 'Skip files larger than (MB)', 'wrensafe' ), 0, 1000000 ),
			'excluded_tables'              => array(
				'group' => 'backup',
				'type'  => 'tables',
				'label' => __( 'Excluded database tables', 'wrensafe' ),
			),
			'safe_updates_prompt'          => $bool( 'updates', __( 'Ask how to update', 'wrensafe' ) ),
			'safe_updates_mode'            => array(
				'group'   => 'updates',
				'type'    => 'choice',
				'label'   => __( 'Default update type', 'wrensafe' ),
				'choices' => $modes,
			),
			'safe_updates_order'           => array(
				'group'   => 'updates',
				'type'    => 'choice',
				'label'   => __( 'Several plugins', 'wrensafe' ),
				'choices' => array(
					'one_by_one'  => __( 'One by one', 'wrensafe' ),
					'all_at_once' => __( 'All at once', 'wrensafe' ),
				),
			),
			'safe_updates_clear_cache'     => $bool( 'updates', __( 'Clear the cache after updating', 'wrensafe' ) ),
			'safe_updates_keep_days'       => $int( 'updates', __( 'Keep saved copies (days)', 'wrensafe' ), 1, 90 ),
			'bbu_auto_core'                => $bool( 'updates', __( 'Back up before WordPress auto-updates', 'wrensafe' ) ),
			'bbu_auto_plugins'             => $bool( 'updates', __( 'Back up before plugin auto-updates', 'wrensafe' ) ),
			'bbu_auto_themes'              => $bool( 'updates', __( 'Back up before theme auto-updates', 'wrensafe' ) ),
			'bbu_manual_updates'           => $bool( 'updates', __( 'Back up before updates you start', 'wrensafe' ) ),
			'bbu_backup_type'              => array(
				'group'   => 'updates',
				'type'    => 'choice',
				'label'   => __( 'Backup before updates', 'wrensafe' ),
				'choices' => array(
					'database' => __( 'Database', 'wrensafe' ),
					'full'     => __( 'Full site', 'wrensafe' ),
				),
			),
			'notify_on_success'            => $bool( 'notifications', __( 'Email when a job succeeds', 'wrensafe' ) ),
			'notify_on_failure'            => $bool( 'notifications', __( 'Email when a job fails', 'wrensafe' ) ),
			'migration_require_https'      => array(
				'group' => 'migration',
				'type'  => 'on_only',
				'label' => __( 'Require HTTPS for Direct Migration', 'wrensafe' ),
			),
			'migration_chunk_size_mb'      => $int( 'migration', __( 'Transfer chunk size (MB)', 'wrensafe' ), 1, 25 ),
			'woo_safe_mode_default'        => $bool( 'migration', __( 'WooCommerce Safe Mode by default', 'wrensafe' ) ),
			'enable_debug_logging'         => $bool( 'advanced', __( 'Debug logging', 'wrensafe' ) ),
			'include_wpbp_internal_tables' => $bool( 'advanced', __( 'Include Wrensafe’s own tables', 'wrensafe' ) ),
			'chunk_size_bytes'             => $int( 'advanced', __( 'Read/write block size (bytes)', 'wrensafe' ), 0, 67108864 ),
		);
	}

	/**
	 * A value checked against its rule, or null when it isn't valid.
	 *
	 * @param array<string,mixed> $rule
	 * @param mixed               $value
	 * @return mixed
	 */
	private static function clean( array $rule, $value ) {
		switch ( $rule['type'] ) {
			case 'bool':
			case 'on_only':
				if ( is_bool( $value ) ) {
					return $value;
				}

				if ( in_array( $value, array( 1, 0, '1', '0', 'true', 'false', '' ), true ) ) {
					return in_array( $value, array( 1, '1', 'true' ), true );
				}

				return null;

			case 'int':
				if ( ! is_numeric( $value ) || (float) $value < 0 ) {
					return null;
				}

				return max( (int) $rule['min'], min( (int) $rule['max'], (int) $value ) );

			case 'choice':
				return is_scalar( $value ) && isset( $rule['choices'][ (string) $value ] ) ? (string) $value : null;

			case 'text':
				return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 1000 ) : null;

			case 'textarea':
				return is_scalar( $value ) ? mb_substr( sanitize_textarea_field( (string) $value ), 0, 5000 ) : null;

			case 'tables':
				if ( '' === $value ) {
					return array();
				}

				if ( ! is_array( $value ) ) {
					return null;
				}

				// Only tables this site has can be left out.
				$names = array_map( 'strval', array_filter( $value, 'is_scalar' ) );

				return array_values( array_intersect( Settings::excludable_tables(), $names ) );
		}

		return null;
	}

	/** @return array<string,mixed> The current value of every setting a file can carry. */
	private static function current(): array {
		$all = Settings::get_all();
		$bbu = (array) get_option( self::BEFORE_UPDATES, array() );
		$out = array();

		foreach ( self::spec() as $key => $rule ) {
			if ( 0 === strpos( $key, 'bbu_' ) ) {
				$sub   = substr( $key, 4 );
				$value = 'backup_type' === $sub ? ( 'full' === ( $bbu[ $sub ] ?? '' ) ? 'full' : 'database' ) : ! empty( $bbu[ $sub ] );
			} else {
				$value = $all[ $key ] ?? null;
			}

			$clean = self::clean( $rule, $value );

			if ( null === $clean ) {
				$clean = self::default_of( $key );
			}

			$out[ $key ] = $clean;
		}

		return $out;
	}

	/** @return mixed */
	private static function default_of( string $key ) {
		if ( 'bbu_backup_type' === $key ) {
			return 'database';
		}

		if ( 0 === strpos( $key, 'bbu_' ) ) {
			return false;
		}

		return Settings::defaults()[ $key ] ?? null;
	}

	/** @return array<string,mixed> The contents of a settings file for this site. */
	public static function export(): array {
		Logger::info( 'Settings exported by user #' . get_current_user_id() . '.' );

		return array(
			'plugin'      => 'wrensafe',
			'type'        => 'settings',
			'schema'      => self::SCHEMA,
			'version'     => WPBP_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'settings'    => self::current(),
		);
	}

	public static function file_name(): string {
		$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return 'wrensafe-settings-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';
	}

	/**
	 * The valid settings of an uploaded file.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function read( array $settings, array $meta ) {
		if ( 'wrensafe' !== ( $meta['plugin'] ?? '' ) || 'settings' !== ( $meta['type'] ?? '' ) ) {
			return new \WP_Error( 'wpbp_settings_file', __( 'This isn’t a Wrensafe settings file.', 'wrensafe' ) );
		}

		$schema = (int) ( $meta['schema'] ?? 0 );

		if ( $schema < 1 ) {
			return new \WP_Error( 'wpbp_settings_file', __( 'This isn’t a Wrensafe settings file.', 'wrensafe' ) );
		}

		if ( $schema > self::SCHEMA ) {
			return new \WP_Error( 'wpbp_settings_newer', __( 'This file was made by a newer version of Wrensafe. Update Wrensafe on this site, then import it.', 'wrensafe' ) );
		}

		$out = array();

		foreach ( self::spec() as $key => $rule ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$value = self::clean( $rule, $settings[ $key ] );

			// A file can turn this protection on, never off.
			if ( null === $value || ( 'on_only' === $rule['type'] && true !== $value ) ) {
				continue;
			}

			$out[ $key ] = $value;
		}

		if ( ! $out ) {
			return new \WP_Error( 'wpbp_settings_empty', __( 'This file has no settings that can be imported.', 'wrensafe' ) );
		}

		return $out;
	}

	/**
	 * What importing a file would change, by group.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function preview( array $settings, array $meta ) {
		$incoming = self::read( $settings, $meta );

		if ( is_wp_error( $incoming ) ) {
			return $incoming;
		}

		$spec    = self::spec();
		$current = self::current();
		$groups  = array();
		$total   = 0;

		foreach ( self::groups() as $group => $label ) {
			$changes = array();

			foreach ( $incoming as $key => $value ) {
				if ( $group !== $spec[ $key ]['group'] || $value === $current[ $key ] ) {
					continue;
				}

				$changes[] = array(
					'label' => (string) $spec[ $key ]['label'],
					'from'  => self::show( $spec[ $key ], $current[ $key ] ),
					'to'    => self::show( $spec[ $key ], $value ),
				);
			}

			if ( $changes ) {
				$total   += count( $changes );
				$groups[] = array(
					'key'     => $group,
					'label'   => $label,
					'changes' => $changes,
				);
			}
		}

		$time = strtotime( (string) ( $meta['exported_at'] ?? '' ) );

		return array(
			'total'  => $total,
			'groups' => $groups,
			'site'   => sanitize_text_field( (string) ( $meta['site'] ?? '' ) ),
			'date'   => $time ? wp_date( (string) get_option( 'date_format' ), $time ) : '',
			'from'   => sanitize_text_field( (string) ( $meta['version'] ?? '' ) ),
		);
	}

	/** @param mixed $value */
	private static function show( array $rule, $value ): string {
		switch ( $rule['type'] ) {
			case 'bool':
			case 'on_only':
				return $value ? __( 'On', 'wrensafe' ) : __( 'Off', 'wrensafe' );
			case 'choice':
				return (string) ( $rule['choices'][ (string) $value ] ?? $value );
			case 'tables':
				return $value ? implode( ', ', (array) $value ) : __( 'None', 'wrensafe' );
			case 'int':
				return number_format_i18n( (int) $value );
		}

		return '' !== (string) $value ? wp_html_excerpt( (string) $value, 120, '…' ) : __( 'None', 'wrensafe' );
	}

	/**
	 * Import the chosen groups of a file.
	 *
	 * @param string[] $groups
	 * @return int|\WP_Error How many settings changed.
	 */
	public static function import( array $settings, array $meta, array $groups ) {
		$busy = self::busy();

		if ( is_wp_error( $busy ) ) {
			return $busy;
		}

		$incoming = self::read( $settings, $meta );

		if ( is_wp_error( $incoming ) ) {
			return $incoming;
		}

		$spec    = self::spec();
		$current = self::current();
		$values  = array();

		foreach ( $incoming as $key => $value ) {
			if ( in_array( $spec[ $key ]['group'], $groups, true ) && $value !== $current[ $key ] ) {
				$values[ $key ] = $value;
			}
		}

		if ( ! $values ) {
			return new \WP_Error( 'wpbp_settings_nothing', __( 'There is nothing to import: the chosen settings already match.', 'wrensafe' ) );
		}

		self::keep_for_undo( 'import', $values );
		self::write( $values );
		Logger::info( 'Settings imported by user #' . get_current_user_id() . ': ' . count( $values ) . ' changed.' );

		return count( $values );
	}

	/**
	 * Put the settings back to their defaults. Backups, schedules, the backup folder, off-site storage
	 * and the notification address stay.
	 *
	 * @return int|\WP_Error How many settings changed.
	 */
	public static function reset() {
		$busy = self::busy();

		if ( is_wp_error( $busy ) ) {
			return $busy;
		}

		$values = self::reset_values();

		if ( ! $values ) {
			return new \WP_Error( 'wpbp_settings_nothing', __( 'Your settings are already at their defaults.', 'wrensafe' ) );
		}

		self::keep_for_undo( 'reset', $values );
		self::write( $values );
		Logger::info( 'Settings reset to defaults by user #' . get_current_user_id() . ': ' . count( $values ) . ' changed.' );

		return count( $values );
	}

	/** How many settings a reset would change. */
	public static function reset_count(): int {
		return count( self::reset_values() );
	}

	/** @return array<string,mixed> The settings that differ from their defaults, with their defaults. */
	private static function reset_values(): array {
		$values = array();

		foreach ( self::current() as $key => $value ) {
			$default = self::clean( self::spec()[ $key ], self::default_of( $key ) );

			if ( $value !== $default ) {
				$values[ $key ] = $default;
			}
		}

		$all = Settings::get_all();

		foreach ( self::RESET_ONLY as $key ) {
			if ( ! empty( $all[ $key ] ) && self::may_change( $key ) ) {
				$values[ $key ] = false;
			}
		}

		return $values;
	}

	/** Undo the last import or reset. @return int|\WP_Error */
	public static function undo() {
		$busy = self::busy();

		if ( is_wp_error( $busy ) ) {
			return $busy;
		}

		$saved = get_option( self::UNDO );

		if ( ! is_array( $saved ) || empty( $saved['values'] ) || ! is_array( $saved['values'] ) ) {
			return new \WP_Error( 'wpbp_settings_no_undo', __( 'There is nothing to undo.', 'wrensafe' ) );
		}

		$spec    = self::spec();
		$values  = array();
		$wrote   = is_array( $saved['wrote'] ?? null ) ? $saved['wrote'] : array();
		$now     = self::current();
		$all     = Settings::get_all();
		$skipped = 0;

		foreach ( $saved['values'] as $key => $value ) {
			// Only what the import or reset changed, and only while it still has the value it set:
			// a setting changed on its tab since then keeps that change.
			if ( ! array_key_exists( $key, $wrote ) ) {
				continue;
			}

			$current = array_key_exists( $key, $now ) ? $now[ $key ] : ! empty( $all[ $key ] );

			if ( $current !== $wrote[ $key ] ) {
				++$skipped;
				continue;
			}

			if ( isset( $spec[ $key ] ) ) {
				$clean = self::clean( 'on_only' === $spec[ $key ]['type'] ? array_merge( $spec[ $key ], array( 'type' => 'bool' ) ) : $spec[ $key ], $value );

				if ( null !== $clean ) {
					$values[ $key ] = $clean;
				}
			} elseif ( in_array( $key, self::RESET_ONLY, true ) && self::may_change( (string) $key ) ) {
				$values[ $key ] = ! empty( $value );
			}
		}

		self::write( $values );
		delete_option( self::UNDO );
		Logger::info( 'Settings change undone by user #' . get_current_user_id() . ': ' . count( $values ) . ' put back, ' . $skipped . ' changed since and kept.' );

		return count( $values );
	}

	/** @return array{what:string,when:string}|null The change that can be undone. */
	public static function undo_info(): ?array {
		$saved = get_option( self::UNDO );

		if ( ! is_array( $saved ) || empty( $saved['values'] ) ) {
			return null;
		}

		return array(
			'what' => 'reset' === ( $saved['what'] ?? '' ) ? 'reset' : 'import',
			'when' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $saved['at'] ?? time() ) ),
		);
	}

	private static function may_change( string $key ): bool {
		return 'allow_locked_backup_downloads' !== $key || CapabilityManager::current_user_can( CapabilityManager::MANAGE_SECURITY );
	}

	/** @param array<string,mixed> $wrote The values the import or reset is about to write. */
	private static function keep_for_undo( string $what, array $wrote ): void {
		$values = self::current();
		$all    = Settings::get_all();

		foreach ( self::RESET_ONLY as $key ) {
			$values[ $key ] = ! empty( $all[ $key ] );
		}

		update_option(
			self::UNDO,
			array(
				'what'   => $what,
				'at'     => time(),
				'values' => $values,
				'wrote'  => $wrote,
			),
			false
		);
	}

	/** @param array<string,mixed> $values */
	private static function write( array $values ): void {
		$plain = array();
		$bbu   = (array) get_option( self::BEFORE_UPDATES, array() );
		$touch = false;

		foreach ( $values as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'bbu_' ) ) {
				$bbu[ substr( (string) $key, 4 ) ] = $value;
				$touch                             = true;
			} else {
				$plain[ $key ] = $value;
			}
		}

		if ( $plain ) {
			Settings::update_many( $plain );
		}

		if ( $touch ) {
			update_option( self::BEFORE_UPDATES, $bbu, false );
		}
	}

	/** Settings don't change under a running job. @return true|\WP_Error */
	private static function busy() {
		if ( null !== SafeUpdateManager::running_job() || JobsRepository::count_active() > 0 ) {
			return new \WP_Error( 'wpbp_settings_busy', __( 'An update, backup, restore or transfer is running. Try again when it has finished.', 'wrensafe' ) );
		}

		return true;
	}
}
