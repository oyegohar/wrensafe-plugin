<?php
/**
 * Thin wrapper around the WordPress Settings API.
 *
 * All plugin settings live in a single serialized option so that future
 * settings tabs can be added without introducing new options.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_NAME  = 'wpbp_settings';
	const OPTION_GROUP = 'wpbp_settings_group';

	/**
	 * Default values for every known setting key.
	 */
	public static function defaults(): array {
		return array(
			'enable_activity_log'           => true,
			'delete_data_on_uninstall'      => false,
			'notify_on_success'             => false,
			'notify_on_failure'             => false,
			'notification_email'            => get_option( 'admin_email' ),
			'allow_locked_backup_downloads' => false,
			'enable_debug_logging'          => false,
			'include_wpbp_internal_tables'  => false,
			'chunk_size_bytes'              => 0,
			'migration_require_https'       => true,
			'migration_chunk_size_mb'       => 2,
			// Backup exclusions (Issue 09) — enabled by default so full
			// backups never re-pack other plugins' multi-hundred-MB archives.
			'exclude_backup_archives'       => true,
			'exclude_cache_folders'         => true,
			'custom_excluded_paths'         => '',
			'custom_excluded_extensions'    => '',
			'woo_safe_mode_default'         => true,
			// Folder for backups ('' = wp-content/wrensafe-storage).
			'backup_dir'                    => '',
			// Check each backup right after it's made (1.1.0).
			'verify_after_backup'           => false,
			// Leave out files larger than this many MB (0 = no limit; 1.1.0).
			'exclude_larger_than_mb'        => 0,
			// Database tables left out of backups, without the table prefix (1.1.0).
			'excluded_tables'               => array(),
		);
	}

	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );

		// options.php otherwise hardcodes 'manage_options' to authorize a
		// settings-group POST, regardless of what capability gates the page
		// that renders the form — so a Team Access user granted only
		// wpbp_manage_settings couldn't actually save anything.
		add_filter(
			'option_page_capability_' . self::OPTION_GROUP,
			static function () {
				return CapabilityManager::MANAGE_SETTINGS;
			}
		);
	}

	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the settings array before it's saved.
	 */
	public function sanitize( $value ): array {
		$value   = is_array( $value ) ? $value : array();
		$current = self::get_all();

		// No tab marker: a complete settings array, not a form post. That is
		// Settings::update(), and WordPress running this callback a second
		// time when the option doesn't exist yet (update_option() falls
		// through to add_option(), which sanitizes the already-clean value
		// again). Treating that as a General-tab post threw every other
		// change away, so the value is kept, with each key's type enforced.
		if ( ! array_key_exists( '_tab', $value ) ) {
			return self::clean_complete( $value, $current );
		}

		// Only overwrite keys present in the submitted tab; checkboxes are
		// simply absent from $value when unchecked, so we detect the tab via
		// a hidden field rather than assuming every key is always submitted.
		$submitted_tab = isset( $value['_tab'] ) ? sanitize_key( $value['_tab'] ) : '';
		unset( $value['_tab'] );

		// The 'security' tab needs its own capability on top of base Settings
		// access (see SettingsPage::tab_capability()) — options.php's own gate
		// only knows the group-level capability, not which tab was submitted,
		// so that distinction has to be enforced here too.
		if ( 'security' === $submitted_tab && ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SECURITY ) ) {
			return $current;
		}

		switch ( $submitted_tab ) {
			case 'notifications':
				$current['notify_on_success'] = ! empty( $value['notify_on_success'] );
				$current['notify_on_failure'] = ! empty( $value['notify_on_failure'] );
				$email                        = trim( (string) ( $value['notification_email'] ?? '' ) );

				if ( '' !== $email && ! is_email( $email ) ) {
					add_settings_error( self::OPTION_NAME, 'wpbp_bad_email', __( 'The email address doesn’t look right, so the previous address was kept. Your other changes were saved.', 'wrensafe' ) );
				} else {
					$current['notification_email'] = sanitize_email( $email );
				}
				break;

			case 'security':
				$current['allow_locked_backup_downloads'] = ! empty( $value['allow_locked_backup_downloads'] );
				break;

			case 'advanced':
				$current['enable_debug_logging']         = ! empty( $value['enable_debug_logging'] );
				$current['delete_data_on_uninstall']     = ! empty( $value['delete_data_on_uninstall'] );
				$current['include_wpbp_internal_tables'] = ! empty( $value['include_wpbp_internal_tables'] );
				$current['chunk_size_bytes']             = max( 0, absint( $value['chunk_size_bytes'] ?? 0 ) );
				break;

			case 'direct-migration':
				$current['migration_require_https'] = ! empty( $value['migration_require_https'] );
				$current['migration_chunk_size_mb'] = max( 1, min( 25, absint( $value['migration_chunk_size_mb'] ?? 2 ) ) );
				break;

			// Issue 09: these tabs rendered fields whose keys were never
			// persisted — every save silently discarded them.
			case 'backup':
				// "Back up before updates" keeps its own option (read by
				// Updates\BackupBeforeUpdates); it is saved with this form.
				$bbu = isset( $value['bbu'] ) && is_array( $value['bbu'] ) ? $value['bbu'] : array();
				update_option(
					'wpbp_backup_before_updates',
					array(
						'auto_core'      => ! empty( $bbu['auto_core'] ),
						'auto_plugins'   => ! empty( $bbu['auto_plugins'] ),
						'auto_themes'    => ! empty( $bbu['auto_themes'] ),
						'manual_updates' => ! empty( $bbu['manual_updates'] ),
						'backup_type'    => 'full' === ( $bbu['backup_type'] ?? '' ) ? 'full' : 'database',
					),
					false
				);

				if ( array_key_exists( 'backup_dir', $value ) ) {
					$current['backup_dir'] = self::save_backup_dir( (string) $value['backup_dir'], (string) ( $current['backup_dir'] ?? '' ) );
				}

				$current['exclude_backup_archives']    = ! empty( $value['exclude_backup_archives'] );
				$current['exclude_cache_folders']      = ! empty( $value['exclude_cache_folders'] );
				$current['custom_excluded_paths']      = sanitize_textarea_field( $value['custom_excluded_paths'] ?? '' );
				$current['custom_excluded_extensions'] = sanitize_text_field( $value['custom_excluded_extensions'] ?? '' );
				$current['verify_after_backup']        = ! empty( $value['verify_after_backup'] );
				$current['exclude_larger_than_mb']     = min( 1000000, absint( $value['exclude_larger_than_mb'] ?? 0 ) );
				$current['excluded_tables']            = self::clean_excluded_tables( (array) ( $value['excluded_tables'] ?? array() ) );
				break;

			case 'remote':
				// Remote storage keeps its own option (secrets encrypted).
				\WPBP\Remote\RemoteStorage::save( \WPBP\Remote\RemoteStorage::sanitize( is_array( $value['remote'] ?? null ) ? $value['remote'] : array() ) );
				break;

			case 'woocommerce':
				$current['woo_safe_mode_default'] = ! empty( $value['woo_safe_mode_default'] );
				break;

			case '':
			case 'general':
				$current['enable_activity_log'] = ! empty( $value['enable_activity_log'] );
				break;

			default:
				/**
				 * Sanitizes a Settings section added by an add-on (see the
				 * wpbp_settings_tabs filter). Return the updated settings.
				 *
				 * @param array  $current Settings after this save so far.
				 * @param string $tab     Section slug.
				 * @param array  $value   Raw submitted values.
				 */
				$current = (array) apply_filters( 'wpbp_sanitize_settings_tab', $current, (string) $submitted_tab, (array) $value );
				break;
		}

		\WPBP\Utils\Logger::info( 'Settings saved (' . ( $submitted_tab ? $submitted_tab : 'general' ) . ' tab) by user #' . get_current_user_id() . '.' );

		return $current;
	}

	/**
	 * Database tables of this site that can be left out of backups: every
	 * table with the site's prefix except WordPress's own tables and
	 * Wrensafe's, without the prefix.
	 *
	 * @return string[]
	 */
	public static function excludable_tables(): array {
		global $wpdb;

		$core   = array_values( $wpdb->tables( 'all', false ) );
		$tables = array();

		foreach ( ( new \WPBP\Backup\DatabaseExporter() )->get_tables( true ) as $table ) {
			$name = substr( (string) $table, strlen( $wpdb->prefix ) );

			if ( '' !== $name && ! in_array( $name, $core, true ) && 0 !== strpos( $name, 'wpbp_' ) ) {
				$tables[] = $name;
			}
		}

		return $tables;
	}

	/**
	 * @param array<int|string,mixed> $submitted Table names (without prefix).
	 * @return string[]
	 */
	private static function clean_excluded_tables( array $submitted ): array {
		$allowed = self::excludable_tables();
		$clean   = array();

		foreach ( $submitted as $name ) {
			$name = sanitize_text_field( (string) $name );

			if ( in_array( $name, $allowed, true ) ) {
				$clean[] = $name;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Validate a new backup folder and move the existing backups into it.
	 * Returns the value to store ('' for the default folder); on a problem
	 * it reports it and keeps the current folder.
	 */
	private static function save_backup_dir( string $submitted, string $saved ): string {
		$default   = \WPBP\Utils\FileSystem::default_storage_dir();
		$submitted = trim( $submitted );
		$wanted    = '' === $submitted ? '' : untrailingslashit( wp_normalize_path( $submitted ) );

		if ( untrailingslashit( $default ) === $wanted ) {
			$wanted = '';
		}

		if ( $wanted === $saved ) {
			return $saved;
		}

		if ( \WPBP\Core\Database\JobsRepository::count_active() > 0 ) {
			add_settings_error( self::OPTION_NAME, 'wpbp_backup_dir_busy', __( 'A backup, restore or transfer is running, so the backup folder wasn’t changed. Change it after it finishes. Your other changes were saved.', 'wrensafe' ) );
			return $saved;
		}

		$old_base = \WPBP\Utils\FileSystem::storage_dir();

		if ( '' !== $wanted ) {
			$check = \WPBP\Utils\FileSystem::check_storage_dir( $wanted, true );

			if ( ! $check['ok'] ) {
				/* translators: %s: why the folder can't be used */
				add_settings_error( self::OPTION_NAME, 'wpbp_backup_dir_invalid', sprintf( __( 'The backup folder wasn’t changed: %s Your other changes were saved.', 'wrensafe' ), $check['message'] ) );
				return $saved;
			}

			$wanted = $check['path'];
		}

		$new_base = '' === $wanted ? $default : trailingslashit( $wanted );

		// Same folder structure and web-access protection as the default.
		foreach ( array( '', 'backups', 'logs', 'tmp', 'uploads' ) as $sub ) {
			\WPBP\Utils\FileSystem::ensure_protected_directory( $new_base . $sub );
		}

		$moved = \WPBP\Utils\FileSystem::move_backups( $old_base, $new_base );
		\WPBP\Utils\FileSystem::reset_storage_cache();

		$message = sprintf(
			/* translators: %s: folder path */
			__( 'Settings saved. New backups are stored in %s.', 'wrensafe' ),
			$new_base
		);

		if ( $moved['moved'] ) {
			/* translators: %d: number of backups */
			$message .= ' ' . sprintf( _n( '%d existing backup was moved there.', '%d existing backups were moved there.', $moved['moved'], 'wrensafe' ), $moved['moved'] );
		}

		if ( $moved['kept'] ) {
			/* translators: %d: number of backups */
			$message .= ' ' . sprintf( _n( '%d backup couldn’t be moved and stays in the old folder; it still works.', '%d backups couldn’t be moved and stay in the old folder; they still work.', $moved['kept'], 'wrensafe' ), $moved['kept'] );
		}

		add_settings_error( self::OPTION_NAME, 'wpbp_backup_dir_changed', $message, 'success' );
		\WPBP\Utils\Logger::info( 'backup_folder_changed: Backup folder changed from ' . $old_base . ' to ' . $new_base . '; ' . $moved['moved'] . ' backups moved, ' . $moved['kept'] . ' left in the old folder.' );

		return $wanted;
	}

	/**
	 * Type-check a complete settings array. Known keys take their default's
	 * type and keys already stored keep their type. A new key (an add-on's
	 * setting on its first save, already sanitized by the add-on) is kept
	 * when it is a plain value or an array.
	 */
	private static function clean_complete( array $value, array $current ): array {
		$defaults = self::defaults();
		$clean    = $current;

		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( ! array_key_exists( $key, $current ) ) {
				if ( is_scalar( $item ) || is_array( $item ) ) {
					$clean[ $key ] = $item;
				}
				continue;
			}

			$type = array_key_exists( $key, $defaults ) ? gettype( $defaults[ $key ] ) : gettype( $current[ $key ] );

			switch ( $type ) {
				case 'boolean':
					$clean[ $key ] = (bool) $item;
					break;
				case 'integer':
					$clean[ $key ] = (int) $item;
					break;
				case 'array':
					$clean[ $key ] = is_array( $item ) ? $item : $current[ $key ];
					break;
				default:
					if ( 'notification_email' === $key ) {
						$clean[ $key ] = sanitize_email( (string) $item );
					} elseif ( 'custom_excluded_paths' === $key ) {
						$clean[ $key ] = sanitize_textarea_field( (string) $item );
					} else {
						$clean[ $key ] = is_scalar( $item ) ? sanitize_text_field( (string) $item ) : $current[ $key ];
					}
			}
		}

		return $clean;
	}

	/**
	 * Install default options on activation if they don't already exist.
	 */
	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}
	}

	/**
	 * Get every setting value merged with defaults.
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Get a single setting value.
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::get_all();

		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Update a single setting value.
	 */
	public static function update( string $key, $value ): bool {
		$all         = self::get_all();
		$all[ $key ] = $value;

		return update_option( self::OPTION_NAME, $all );
	}
}
