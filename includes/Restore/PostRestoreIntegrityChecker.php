<?php
/**
 * Post-restore plugin file integrity check (Issue 11).
 *
 * After a cross-site restore, users saw fatal "No such file or directory"
 * errors from plugin autoloaders (Yoast/Elementor/WooCommerce): the source
 * database's active_plugins listed a plugin, but some of that plugin's files
 * were not actually on disk after restore — because a file extraction failed
 * silently, or because the destination already had a different build of the
 * plugin whose stale autoloader references files the restored build doesn't
 * ship.
 *
 * This runs at the very end of a restore, WHILE the temporary boot guard is
 * still active (so a half-restored plugin can't fatal a request yet), and
 * before that guard is removed. For every active plugin it verifies:
 *   1. the plugin main file exists;
 *   2. every file the BACKUP contained for that plugin was actually restored
 *      to disk (bounded existence checks against the archive manifest);
 *   3. any file whose extraction the restore explicitly reported as failed.
 * Any plugin that fails is deactivated safely, logged, and surfaced to the
 * admin via a dismissible notice — so the site loads cleanly instead of
 * fataling, and the user knows exactly which plugin to reinstall.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Utils\Logger;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

class PostRestoreIntegrityChecker {

	/** Transient holding the admin-notice payload after a restore. */
	const NOTICE_TRANSIENT = 'wpbp_restore_integrity_notice';

	/** Upper bound on file_exists checks across all plugins, to stay time-safe. */
	private const MAX_FILE_CHECKS = 30000;

	/** Missing files to remember per plugin for the log/notice. */
	private const SAMPLE_LIMIT = 20;

	/**
	 * Run the check for a finished restore.
	 *
	 * @param array    $payload The restore job payload.
	 * @param int|null $job_id  For logging.
	 * @return array{deactivated:array<int,array{plugin:string,reason:string,missing:int}>,checked:int}
	 */
	public static function run( array $payload, ?int $job_id = null ): array {
		$active = PluginSafety::stored_active_plugins(); // Not get_option(): the boot guard filters it.

		if ( empty( $active ) ) {
			return array(
				'deactivated' => array(),
				'checked'     => 0,
			);
		}

		// Maps archive entries to this site's folders exactly as the restore did.
		$restorer = new FileRestorer( RestoreManager::source_layout( $payload ) );

		// Archive file entries (entry_name => true) so we can tell which files
		// each active plugin SHOULD have on disk after restore.
		$expected_by_plugin = self::expected_plugin_files( $payload, $restorer );

		// Entries the restore explicitly failed to extract (Issue 11 Part D).
		$failed_by_plugin = self::group_failed_by_plugin( (array) ( $payload['restore_failed_files'] ?? array() ), $restorer );

		$checks_used   = 0;
		$deactivate    = array();
		$deactivations = array();

		foreach ( $active as $plugin ) {
			// Never deactivate (or report) Wrensafe itself — it's the
			// plugin running the restore, and its files are always present.
			if ( defined( 'WPBP_PLUGIN_BASENAME' ) && WPBP_PLUGIN_BASENAME === $plugin ) {
				continue;
			}

			$slug = strtok( $plugin, '/' ); // The plugin's folder name, e.g. woocommerce.

			// Single-file plugins (e.g. hello.php) have no directory — just the
			// main file check applies.
			$reason  = '';
			$missing = 0;
			$sample  = array();

			// 1) Main file present?
			if ( ! file_exists( Paths::plugins_dir() . '/' . $plugin ) ) {
				$reason = __( 'its main plugin file is missing on this site', 'wrensafe' );
			}

			// 1b) Needs a newer PHP or WordPress than this site has? WordPress
			// refuses to activate such a plugin; restored already active, it
			// would stop the whole site with a fatal error.
			if ( '' === $reason ) {
				$reason = self::requirements_problem( Paths::plugins_dir() . '/' . $plugin );
			}

			// 2) Files that failed to extract for this plugin.
			if ( '' === $reason && ! empty( $failed_by_plugin[ $slug ] ) ) {
				$missing = count( $failed_by_plugin[ $slug ] );
				$sample  = array_slice( $failed_by_plugin[ $slug ], 0, self::SAMPLE_LIMIT );
				$reason  = __( 'some of its files could not be extracted during restore', 'wrensafe' );
			}

			// 3) Manifest-based existence: every file the backup carried for this
			// plugin must be on disk. Bounded so a huge plugin can't blow the
			// time budget.
			if ( '' === $reason && ! empty( $expected_by_plugin[ $slug ] ) ) {
				foreach ( $expected_by_plugin[ $slug ] as $entry_name ) {
					if ( $checks_used >= self::MAX_FILE_CHECKS ) {
						break;
					}
					++$checks_used;

					$dest = $restorer->entry_path( $entry_name );

					if ( null === $dest || ! file_exists( $dest ) ) {
						++$missing;
						if ( count( $sample ) < self::SAMPLE_LIMIT ) {
							$sample[] = $entry_name;
						}
					}
				}

				if ( $missing > 0 ) {
					$reason = __( 'some files it needs were not restored to this site', 'wrensafe' );
				}
			}

			if ( '' !== $reason ) {
				$deactivate[]    = $plugin;
				$deactivations[] = array(
					'plugin'  => $plugin,
					'slug'    => (string) $slug,
					'reason'  => $reason,
					'missing' => $missing,
					'sample'  => $sample,
				);
			}
		}

		if ( ! empty( $deactivate ) ) {
			PluginSafety::deactivate( $deactivate, $job_id );

			foreach ( $deactivations as $d ) {
				$detail = $d['missing'] > 0 ? ' (' . $d['missing'] . ' file(s) missing; e.g. ' . implode( ', ', array_slice( $d['sample'], 0, 3 ) ) . ')' : '';
				Logger::warning( 'restore_incomplete_plugin_deactivated: ' . $d['plugin'] . ' — ' . $d['reason'] . $detail . '.', array(), $job_id );

				// A plugin the backup DID contain but whose files aren't on disk
				// is also a file-restore integrity failure, not just a
				// cross-site "not installed here" case.
				if ( $d['missing'] > 0 ) {
					Logger::failed( 'restore_file_integrity_failed: ' . $d['plugin'] . ' is missing ' . $d['missing'] . ' restored file(s).', array(), $job_id );
				}
			}

			self::store_notice( $deactivations );
			Logger::warning( 'restore_plugin_integrity_failed: ' . count( $deactivate ) . ' plugin(s) deactivated after restore to keep the site loading.', array(), $job_id );
		} else {
			Logger::info( 'restore_plugin_integrity_passed: all active plugins have their files on disk.', array(), $job_id );
		}

		return array(
			'deactivated' => $deactivations,
			'checked'     => $checks_used,
		);
	}

	/**
	 * Why a plugin can't run here: its "Requires PHP" / "Requires at least"
	 * header asks for more than this server has. '' when it can.
	 */
	public static function requirements_problem( string $main_file ): string {
		$headers = get_file_data(
			$main_file,
			array(
				'php' => 'Requires PHP',
				'wp'  => 'Requires at least',
			)
		);

		if ( '' !== $headers['php'] && version_compare( PHP_VERSION, $headers['php'], '<' ) ) {
			return sprintf(
				/* translators: 1: PHP version the plugin needs, 2: PHP version of this server */
				__( 'it needs PHP %1$s and this server runs PHP %2$s', 'wrensafe' ),
				$headers['php'],
				PHP_VERSION
			);
		}

		if ( '' !== $headers['wp'] && version_compare( (string) get_bloginfo( 'version' ), $headers['wp'], '<' ) ) {
			return sprintf(
				/* translators: 1: WordPress version the plugin needs, 2: WordPress version of this site */
				__( 'it needs WordPress %1$s and this site runs WordPress %2$s', 'wrensafe' ),
				$headers['wp'],
				(string) get_bloginfo( 'version' )
			);
		}

		return '';
	}

	/**
	 * The plugin folder an archive entry is restored into ("woocommerce"),
	 * or '' when the entry isn't inside this site's plugins folder.
	 */
	private static function plugin_slug( FileRestorer $restorer, string $entry_name ): string {
		$path = $restorer->entry_path( $entry_name );

		if ( null === $path ) {
			return '';
		}

		$plugins = Paths::plugins_dir() . '/';

		if ( 0 !== strpos( $path, $plugins ) ) {
			return '';
		}

		$rest = substr( $path, strlen( $plugins ) );

		return false !== strpos( $rest, '/' ) ? (string) strtok( $rest, '/' ) : '';
	}

	/**
	 * Read the archive's checksums.json and group the file entries that are
	 * restored into a plugin's folder by plugin slug, so each active plugin's
	 * expected files can be verified. Returns slug => string[] of entry names.
	 *
	 * @return array<string,string[]>
	 */
	private static function expected_plugin_files( array $payload, FileRestorer $restorer ): array {
		$archive_path = (string) ( $payload['archive_path'] ?? '' );

		if ( '' === $archive_path || ! file_exists( $archive_path ) ) {
			return array();
		}

		$files = \WPBP\Backup\IntegrityChecker::file_checksums( ( new ArchiveReader( $archive_path ) )->read_json( 'checksums.json' ) );

		$by_slug = array();

		foreach ( array_keys( $files ) as $entry_name ) {
			$slug = self::plugin_slug( $restorer, (string) $entry_name );

			if ( '' !== $slug ) {
				$by_slug[ $slug ][] = (string) $entry_name;
			}
		}

		return $by_slug;
	}

	/**
	 * Group explicitly-failed extraction entries by plugin slug.
	 *
	 * @param string[] $failed_entries
	 * @return array<string,string[]>
	 */
	private static function group_failed_by_plugin( array $failed_entries, FileRestorer $restorer ): array {
		$by_slug = array();

		foreach ( $failed_entries as $entry_name ) {
			$slug = is_string( $entry_name ) ? self::plugin_slug( $restorer, $entry_name ) : '';

			if ( '' !== $slug ) {
				$by_slug[ $slug ][] = $entry_name;
			}
		}

		return $by_slug;
	}

	/**
	 * Persist the admin-notice payload (shown once, dismissible) listing the
	 * plugins that were deactivated and why.
	 */
	/**
	 * Add items to the post-restore notice (keeps what's there).
	 *
	 * @param array<int,array{plugin:string,reason:string}> $items
	 */
	public static function add_notice_items( array $items ): void {
		$current = get_transient( self::NOTICE_TRANSIENT );

		set_transient( self::NOTICE_TRANSIENT, array_merge( is_array( $current ) ? $current : array(), $items ), WEEK_IN_SECONDS );
	}

	private static function store_notice( array $deactivations ): void {
		$items = array();

		foreach ( $deactivations as $d ) {
			$items[] = array(
				'plugin' => $d['plugin'],
				'reason' => $d['reason'],
			);
		}

		set_transient( self::NOTICE_TRANSIENT, $items, WEEK_IN_SECONDS );
	}
}
