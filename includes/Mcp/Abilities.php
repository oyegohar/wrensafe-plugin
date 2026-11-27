<?php
/**
 * AI assistants over MCP: Wrensafe's abilities for WordPress's Abilities API (WordPress 6.9+), which the MCP Adapter
 * plugin offers to assistants such as Claude, Cursor or Codex.
 *
 * Off until an administrator turns it on (Settings → AI assistants). Three levels:
 *   read      the site's backup status, backups, recent activity, available updates, a job's progress;
 *   actions   (opt-in) create or verify a backup, test a restore, check links: they don't change the site's content;
 *   requests  (opt-in) ask for a restore, a rollback or a Search & Replace: a person approves each one in wp-admin
 *             and runs it there (Requests); an assistant can never run them itself.
 * Deleting backups is never available. Every call is logged (Activity Logs). Each ability also needs the Wrensafe
 * capability of the user the assistant signs in as (an Application Password, or WP-CLI's --user).
 *
 * @package WPBP\Mcp
 */

namespace WPBP\Mcp;

use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Backup\VerifyManager;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Settings;
use WPBP\Restore\LinkCheck;
use WPBP\Restore\RestoreTest;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Errors;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	const CATEGORY = 'wrensafe';

	/** Backups an assistant may make in 24 hours, and where their times are kept. */
	const BACKUPS_PER_DAY = 6;
	const BACKUP_TIMES    = 'wpbp_mcp_backup_times';
	const BACKUP_LOCK     = 'wpbp_mcp_backup_lock';

	/** WP-Cron finishes a job an assistant started (it doesn't have to keep asking). */
	const CONTINUE_HOOK = 'wpbp_mcp_continue';

	/** Seconds of work inside one call; the rest goes on in the background. */
	const STEP_SECONDS = 20;

	public static function register(): void {
		add_action( self::CONTINUE_HOOK, array( self::class, 'continue_job' ), 10, 2 );

		if ( ! self::enabled() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/** The Abilities API is there (WordPress 6.9+). */
	public static function supported(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/** The MCP Adapter plugin, which offers abilities to assistants, is active. */
	public static function adapter_active(): bool {
		return class_exists( '\WP\MCP\Core\McpAdapter' ) || class_exists( '\WP\MCP\Plugin' );
	}

	public static function enabled(): bool {
		return self::supported() && (bool) Settings::get( 'mcp_enabled', false );
	}

	public static function allows( string $level ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		if ( 'actions' === $level ) {
			return (bool) Settings::get( 'mcp_actions', false );
		}

		if ( 'requests' === $level ) {
			return (bool) Settings::get( 'mcp_requests', false );
		}

		return true;
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Wrensafe',
				'description' => __( 'Backups, restores and safe updates of this WordPress site, by Wrensafe.', 'wrensafe' ),
			)
		);
	}

	public static function register_abilities(): void {
		foreach ( self::definitions() as $name => $def ) {
			if ( ! self::allows( $def['level'] ) ) {
				continue;
			}

			wp_register_ability(
				'wrensafe/' . $name,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $def['input'],
					'execute_callback'    => static function ( $input = null ) use ( $name, $def ) {
						return self::run( $name, $def, is_array( $input ) ? $input : array() );
					},
					'permission_callback' => static function () use ( $def ) {
						if ( ! self::allows( $def['level'] ) ) {
							return new \WP_Error( 'wrensafe_mcp_off', __( 'An administrator has turned this off in Wrensafe → Settings → AI assistants.', 'wrensafe' ) );
						}

						return CapabilityManager::current_user_can( $def['capability'] );
					},
					'meta'                => array(
						'annotations' => array(
							'readonly'    => 'read' === $def['level'],
							'destructive' => false,
							'idempotent'  => 'read' === $def['level'],
						),
						'mcp'         => array(
							'public' => true,
							'type'   => 'tool',
						),
					),
				)
			);
		}
	}

	/**
	 * Every ability: what it does, its level, the capability it needs, its input, and the method that runs it.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$none   = array(
			'type'                 => 'object',
			'properties'           => (object) array(),
			'additionalProperties' => false,
		);
		$backup = array(
			'type'                 => 'object',
			'properties'           => array(
				'backup_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The backup’s id, from wrensafe/list-backups.', 'wrensafe' ),
				),
				'job_id'    => array(
					'type'        => 'string',
					'description' => __( 'To continue a check that is running, the job_id an earlier call returned.', 'wrensafe' ),
				),
			),
			'additionalProperties' => false,
		);
		$reason = array(
			'type'        => 'string',
			'maxLength'   => 300,
			'description' => __( 'Why, in a sentence, for the person who approves it.', 'wrensafe' ),
		);

		return array(
			'site-status'            => array(
				'level'       => 'read',
				'capability'  => CapabilityManager::VIEW_DASHBOARD,
				'label'       => __( 'Backup status of this site', 'wrensafe' ),
				'description' => __( 'Whether the site is backed up: the latest backup, how many backups there are and their size, scheduled backups, the last restore, updates waiting and problems in the last 30 days.', 'wrensafe' ),
				'input'       => $none,
				'method'      => 'site_status',
			),
			'list-backups'           => array(
				'level'       => 'read',
				'capability'  => CapabilityManager::VIEW_BACKUPS,
				'label'       => __( 'List backups', 'wrensafe' ),
				'description' => __( 'The backups on this server, newest first: id, file name, type, size, date, status, whether it is locked or encrypted, its note, and its latest restore test.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 10,
						),
					),
					'additionalProperties' => false,
				),
				'method'      => 'list_backups',
			),
			'list-activity'          => array(
				'level'       => 'read',
				'capability'  => CapabilityManager::VIEW_LOGS,
				'label'       => __( 'Recent activity', 'wrensafe' ),
				'description' => __( 'What Wrensafe did lately (backups, restores, Safe Updates runs, moves, link checks), newest first, with the reason when something went wrong.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind'  => array(
							'type' => 'string',
							'enum' => array( 'all', 'backup', 'restore', 'update', 'move', 'tools', 'problems' ),
						),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 25,
							'default' => 10,
						),
					),
					'additionalProperties' => false,
				),
				'method'      => 'list_activity',
			),
			'list-updates'           => array(
				'level'       => 'read',
				'capability'  => CapabilityManager::VIEW_DASHBOARD,
				'label'       => __( 'Updates waiting', 'wrensafe' ),
				'description' => __( 'Plugins and themes with an update available, with the installed and new versions. Wrensafe’s Safe Updates (in wp-admin) can install them with a rollback if the site breaks.', 'wrensafe' ),
				'input'       => $none,
				'method'      => 'list_updates',
			),
			'get-job'                => array(
				'level'       => 'read',
				'capability'  => CapabilityManager::VIEW_DASHBOARD,
				'label'       => __( 'Progress of a job', 'wrensafe' ),
				'description' => __( 'How a backup, verification, restore test or link check is going, by the job_id another ability returned.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'job_id' => array( 'type' => 'string' ),
					),
					'required'             => array( 'job_id' ),
					'additionalProperties' => false,
				),
				'method'      => 'get_job',
			),
			'list-requests'          => array(
				'level'       => 'requests',
				'capability'  => CapabilityManager::VIEW_DASHBOARD,
				'label'       => __( 'Requests waiting for approval', 'wrensafe' ),
				'description' => __( 'Restores, rollbacks and Search & Replace runs that AI assistants asked for, and whether a person approved or declined them.', 'wrensafe' ),
				'input'       => $none,
				'method'      => 'list_requests',
			),
			'create-backup'          => array(
				'level'       => 'actions',
				'capability'  => CapabilityManager::CREATE_BACKUPS,
				'label'       => __( 'Create a backup', 'wrensafe' ),
				'description' => __( 'Starts a backup (full by default). It runs for up to 20 seconds in this call and then finishes in the background; follow it with wrensafe/get-job.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'type' => array(
							'type'    => 'string',
							'enum'    => array( 'full', 'database', 'files' ),
							'default' => 'full',
						),
						'note' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'method'      => 'create_backup',
			),
			'verify-backup'          => array(
				'level'       => 'actions',
				'capability'  => CapabilityManager::VIEW_BACKUPS,
				'label'       => __( 'Verify a backup', 'wrensafe' ),
				'description' => __( 'Checks a backup’s archive, database files and every file’s checksum. Encrypted backups are verified in wp-admin (their password is never shared with an assistant).', 'wrensafe' ),
				'input'       => $backup,
				'method'      => 'verify_backup',
			),
			'test-restore'           => array(
				'level'       => 'actions',
				'capability'  => CapabilityManager::RESTORE_BACKUPS,
				'label'       => __( 'Test that a backup restores', 'wrensafe' ),
				'description' => __( 'Restores a backup into a temporary copy to prove it works, then removes the copy. The live site isn’t changed. Encrypted backups are tested in wp-admin.', 'wrensafe' ),
				'input'       => $backup,
				'method'      => 'test_restore',
			),
			'check-links'            => array(
				'level'       => 'actions',
				'capability'  => CapabilityManager::RESTORE_BACKUPS,
				'label'       => __( 'Check links and media', 'wrensafe' ),
				'description' => __( 'Fix Links’ check: links that still point to an old address or a server folder, links over http, and media files that are missing. It changes nothing; fixing is done in wp-admin.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'job_id' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'method'      => 'check_links',
			),
			'request-restore'        => array(
				'level'       => 'requests',
				'capability'  => CapabilityManager::RESTORE_BACKUPS,
				'label'       => __( 'Ask to restore a backup', 'wrensafe' ),
				'description' => __( 'Asks a person to restore a backup. Nothing happens until someone approves it in wp-admin and runs the restore there.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'backup_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'reason'    => $reason,
					),
					'required'             => array( 'backup_id' ),
					'additionalProperties' => false,
				),
				'method'      => 'request_restore',
			),
			'request-rollback'       => array(
				'level'       => 'requests',
				'capability'  => CapabilityManager::RESTORE_BACKUPS,
				'label'       => __( 'Ask to undo the last restore', 'wrensafe' ),
				'description' => __( 'Asks a person to roll back to the rollback point saved before the last restore. Nothing happens until someone approves it in wp-admin and runs it there.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'reason' => $reason,
					),
					'additionalProperties' => false,
				),
				'method'      => 'request_rollback',
			),
			'request-search-replace' => array(
				'level'       => 'requests',
				'capability'  => CapabilityManager::MANAGE_SETTINGS,
				'label'       => __( 'Ask to replace text in the database', 'wrensafe' ),
				'description' => __( 'Asks a person to run Search & Replace. Nothing happens until someone approves it in wp-admin; it opens there with a dry run first.', 'wrensafe' ),
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'  => array(
							'type'      => 'string',
							'minLength' => 3,
							'maxLength' => 500,
						),
						'replace' => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'reason'  => $reason,
					),
					'required'             => array( 'search', 'replace' ),
					'additionalProperties' => false,
				),
				'method'      => 'request_search_replace',
			),
		);
	}

	/** Log the call, run it, and turn anything thrown into an error the assistant can read. */
	private static function run( string $name, array $def, array $input ) {
		$user = wp_get_current_user();

		// Checking a job's progress isn't logged: an assistant asks every few seconds, and the log keeps 5,000 entries.
		if ( 'get-job' !== $name ) {
			Logger::info( 'ai_action: ' . sprintf( 'An AI assistant (signed in as %1$s) used “%2$s”', $user->user_login, $def['label'] ) . self::summary( $input ) . '.' );
		}

		try {
			return call_user_func( array( self::class, $def['method'] ), $input );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wrensafe_mcp_failed', Errors::text( $e ) );
		}
	}

	/** The input in the log, short and without long text. */
	private static function summary( array $input ): string {
		$parts = array();

		foreach ( $input as $key => $value ) {
			if ( is_scalar( $value ) && 'reason' !== $key ) {
				$parts[] = $key . ' ' . wp_html_excerpt( (string) $value, 60, '…' );
			}
		}

		return $parts ? ' (' . implode( ', ', $parts ) . ')' : '';
	}

	// ---------------------------------------------------------------------
	// Read.
	// ---------------------------------------------------------------------

	private static function site_status(): array {
		$latest = BackupsRepository::find_latest_completed();
		$totals = \WPBP\Admin\ActivityTimeline::totals();
		$next   = (string) \WPBP\Core\Database\SchedulesRepository::get_next_run_at();

		return array(
			'site'               => home_url(),
			'protected'          => null !== $latest,
			'latest_backup'      => $latest ? self::backup_row( $latest ) : null,
			'backups'            => BackupsRepository::count_completed(),
			'storage_used_bytes' => BackupsRepository::sum_size(),
			'next_scheduled'     => $next,
			'last_30_days'       => $totals,
			'updates_waiting'    => count( self::list_updates()['updates'] ),
			'wrensafe_version'   => WPBP_VERSION,
		);
	}

	private static function list_backups( array $input ): array {
		$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
		$rows  = array();

		foreach ( BackupsRepository::list_all( $limit ) as $backup ) {
			$rows[] = self::backup_row( $backup );
		}

		return array( 'backups' => $rows );
	}

	private static function backup_row( array $backup ): array {
		$test = RestoreTest::results()[ (int) $backup['id'] ] ?? null;

		return array(
			'id'           => (int) $backup['id'],
			'file_name'    => (string) $backup['file_name'],
			'type'         => (string) $backup['backup_type'],
			'size_bytes'   => (int) $backup['size'],
			'created_at'   => (string) $backup['created_at'],
			'status'       => (string) $backup['status'],
			'locked'       => ! empty( $backup['is_locked'] ),
			'encrypted'    => ! empty( $backup['is_encrypted'] ),
			'note'         => (string) ( $backup['note'] ?? '' ),
			'restore_test' => $test ? array(
				'passed' => (bool) $test['ok'],
				'at'     => gmdate( 'c', (int) $test['at'] ),
			) : null,
		);
	}

	private static function list_activity( array $input ): array {
		$kind  = (string) ( $input['kind'] ?? 'all' );
		$limit = max( 1, min( 25, (int) ( $input['limit'] ?? 10 ) ) );
		$cards = array();

		foreach ( array_slice( \WPBP\Admin\ActivityTimeline::page( 'all' === $kind ? '' : $kind, '', 0 )['items'], 0, $limit ) as $item ) {
			$cards[] = array(
				'time'    => (string) $item['time'],
				'kind'    => (string) $item['kind'],
				'title'   => (string) $item['title'],
				'status'  => (string) $item['status'],
				'summary' => (string) $item['summary'],
				'facts'   => array_values( (array) $item['facts'] ),
			);
		}

		return array( 'activity' => $cards );
	}

	private static function list_updates(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates = array();
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$all     = get_plugins();

		foreach ( (array) ( is_object( $plugins ) ? $plugins->response : array() ) as $file => $update ) {
			$updates[] = array(
				'type'      => 'plugin',
				'name'      => (string) ( $all[ $file ]['Name'] ?? $file ),
				'installed' => (string) ( $all[ $file ]['Version'] ?? '' ),
				'new'       => (string) ( is_object( $update ) ? $update->new_version : ( $update['new_version'] ?? '' ) ),
			);
		}

		foreach ( (array) ( is_object( $themes ) ? $themes->response : array() ) as $slug => $update ) {
			$theme     = wp_get_theme( (string) $slug );
			$updates[] = array(
				'type'      => 'theme',
				'name'      => $theme->exists() ? $theme->get( 'Name' ) : (string) $slug,
				'installed' => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
				'new'       => (string) ( $update['new_version'] ?? '' ),
			);
		}

		return array( 'updates' => $updates );
	}

	private static function get_job( array $input ): array {
		$id = (string) ( $input['job_id'] ?? '' );

		if ( 0 === strpos( $id, 'verify_' ) ) {
			$state = VerifyManager::get( $id );

			if ( null === $state ) {
				throw new \RuntimeException( esc_html__( 'Job not found.', 'wrensafe' ) );
			}

			return self::verify_summary( $state );
		}

		if ( 0 === strpos( $id, 'links' ) ) {
			return self::links_summary( LinkCheck::state() );
		}

		$row = JobsRepository::find( absint( $id ) );

		if ( null === $row || ! in_array( $row['job_type'], array( 'backup', 'restore', 'restore_test', 'safe_update', 'migration_transfer', 'import', 'staging' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'Job not found.', 'wrensafe' ) );
		}

		$result = json_decode( (string) $row['result'], true );

		return array(
			'job_id'    => (string) $row['id'],
			'type'      => (string) $row['job_type'],
			'status'    => (string) $row['status'],
			'progress'  => (int) $row['progress'],
			'step'      => (string) $row['current_step'],
			'error'     => (string) $row['error_message'],
			'backup_id' => (int) ( is_array( $result ) ? ( $result['backup_id'] ?? 0 ) : 0 ),
			'message'   => (string) ( is_array( $result ) ? ( $result['message'] ?? '' ) : '' ),
		);
	}

	private static function list_requests(): array {
		return array( 'requests' => Requests::for_assistant() );
	}

	// ---------------------------------------------------------------------
	// Actions: they run for STEP_SECONDS here, and WP-Cron finishes them.
	// ---------------------------------------------------------------------

	private static function create_backup( array $input ): array {
		// Two calls at the same moment: only one gets past the checks below, until its job exists.
		if ( ! self::lock_backup_start() ) {
			throw new \RuntimeException( esc_html__( 'Another backup, restore or update is running. Try again when it has finished.', 'wrensafe' ) );
		}

		try {
			$job = self::start_backup( $input );
		} finally {
			self::unlock_backup_start();
		}

		self::advance( 'backup', (string) $job->id() );

		return self::get_job( array( 'job_id' => (string) $job->id() ) );
	}

	/** The checks and the new job, under the start lock. */
	private static function start_backup( array $input ): BackupJob {
		if ( JobsRepository::count_active() > 0 ) {
			throw new \RuntimeException( esc_html__( 'Another backup, restore or update is running. Try again when it has finished.', 'wrensafe' ) );
		}

		// A few a day: an assistant (or a prompt that misleads it) can't fill the disk with backups.
		$recent = array_values(
			array_filter(
				array_map( 'intval', (array) get_option( self::BACKUP_TIMES, array() ) ),
				static function ( int $time ): bool {
					return $time > time() - DAY_IN_SECONDS;
				}
			)
		);

		if ( count( $recent ) >= self::BACKUPS_PER_DAY ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %d: how many backups an assistant may make in 24 hours */
						__( 'An assistant can make up to %d backups in 24 hours. Make more from Wrensafe → Create Backup.', 'wrensafe' ),
						self::BACKUPS_PER_DAY
					)
				)
			);
		}

		$recent[] = time();
		update_option( self::BACKUP_TIMES, $recent, false );

		$note = sanitize_text_field( (string) ( $input['note'] ?? '' ) );
		$job  = BackupJob::create(
			array(
				'backup_type' => in_array( $input['type'] ?? 'full', array( 'full', 'database', 'files' ), true ) ? (string) ( $input['type'] ?? 'full' ) : 'full',
				'note'        => '' !== $note ? $note : __( 'Made by an AI assistant', 'wrensafe' ),
				// Kept on this server only: copying it off-site would apply the
				// off-site retention, which deletes older copies there.
				'made_by'     => 'ai_assistant',
			)
		);

		return $job;
	}

	/**
	 * A short lock while an assistant's backup is checked and created. An options row written with INSERT IGNORE (as
	 * Safe Updates' run lock): only one request can add it, and it works on every MySQL and MariaDB host. It expires
	 * after a minute, in case a request dies holding it.
	 */
	private static function lock_backup_start(): bool {
		global $wpdb;

		$now   = time();
		$taken = (bool) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::BACKUP_LOCK, (string) ( $now + MINUTE_IN_SECONDS ), 'no' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock must bypass the options cache.

		if ( ! $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::BACKUP_LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock's current value, not cached.

			if ( (int) $held > $now ) {
				return false;
			}

			// Left by a request that died: take it over (only one request can).
			$taken = (bool) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) ( $now + MINUTE_IN_SECONDS ), self::BACKUP_LOCK, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the lock row.
		}

		return $taken;
	}

	private static function unlock_backup_start(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, self::BACKUP_LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock row written above.
		wp_cache_delete( self::BACKUP_LOCK, 'options' );
	}

	private static function verify_backup( array $input ): array {
		if ( ! empty( $input['job_id'] ) ) {
			return self::get_job( array( 'job_id' => (string) $input['job_id'] ) );
		}

		$backup = self::backup_for( $input );
		$state  = VerifyManager::start( (int) $backup['id'] );

		self::advance( 'verify', (string) $state['job_id'] );

		return self::verify_summary( (array) VerifyManager::get( (string) $state['job_id'] ) );
	}

	private static function test_restore( array $input ): array {
		if ( ! empty( $input['job_id'] ) ) {
			return self::get_job( array( 'job_id' => (string) $input['job_id'] ) );
		}

		$backup = self::backup_for( $input );
		$state  = RestoreTest::start( (int) $backup['id'] );

		self::advance( 'restore_test', (string) $state['job_id'] );

		return self::get_job( array( 'job_id' => (string) $state['job_id'] ) );
	}

	private static function check_links( array $input ): array {
		if ( ! empty( $input['job_id'] ) ) {
			return self::links_summary( LinkCheck::state() );
		}

		$state = LinkCheck::start( array() );

		if ( is_wp_error( $state ) ) {
			throw new \RuntimeException( esc_html( $state->get_error_message() ) );
		}

		self::advance( 'links', (string) $state['token'] );

		return self::links_summary( LinkCheck::state() );
	}

	/** A backup an assistant may verify or test: it exists, and it isn't encrypted. */
	private static function backup_for( array $input ): array {
		$backup = BackupsRepository::find( (int) ( $input['backup_id'] ?? 0 ) );

		if ( null === $backup ) {
			throw new \RuntimeException( esc_html__( 'Backup not found. Use wrensafe/list-backups for the ids.', 'wrensafe' ) );
		}

		if ( ! empty( $backup['is_encrypted'] ) ) {
			throw new \RuntimeException( esc_html__( 'This backup is encrypted. Its password is never shared with an assistant: check it in wp-admin (Backup Browser).', 'wrensafe' ) );
		}

		return $backup;
	}

	private static function verify_summary( array $state ): array {
		return array(
			'job_id'        => (string) ( $state['job_id'] ?? '' ),
			'type'          => 'verify',
			'status'        => 'failed' === ( $state['status'] ?? '' ) ? 'failed' : ( 'completed' === ( $state['status'] ?? '' ) ? 'completed' : 'running' ),
			'progress'      => (int) ( $state['progress'] ?? 0 ),
			'message'       => (string) ( $state['message'] ?? $state['current_step'] ?? '' ),
			'checked_files' => (int) ( $state['summary']['checked_files'] ?? $state['checked_files'] ?? 0 ),
		);
	}

	private static function links_summary( array $state ): array {
		$report = LinkCheck::report( $state );
		$status = (string) $report['status'];

		return array(
			'job_id'   => 'links',
			'type'     => 'link_check',
			'status'   => in_array( $status, array( 'pending', 'checking', 'fixing' ), true ) ? 'running' : $status,
			'progress' => (int) $report['percent'],
			'found'    => (array) ( $report['found'] ?? array() ),
			'fixable'  => (int) ( $report['fixable'] ?? 0 ),
			'note'     => __( 'Fixing is done by a person in wp-admin: Wrensafe → Fix Links.', 'wrensafe' ),
		);
	}

	/** Run a job for up to STEP_SECONDS (or $seconds); if it isn't done, WP-Cron goes on with it. */
	private static function advance( string $kind, string $id, int $seconds = self::STEP_SECONDS ): bool {
		$deadline = microtime( true ) + $seconds;
		$done     = false;

		while ( ! $done && microtime( true ) < $deadline ) {
			$done = self::step( $kind, $id );
		}

		if ( ! $done && ! wp_next_scheduled( self::CONTINUE_HOOK, array( $kind, $id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CONTINUE_HOOK, array( $kind, $id ) );
		}

		return $done;
	}

	/** One step of a job. True when it has ended. */
	private static function step( string $kind, string $id ): bool {
		switch ( $kind ) {
			case 'backup':
				$job = BackupJob::get( (int) $id );

				if ( null === $job || in_array( $job->status(), array( 'completed', 'failed', 'cancelled' ), true ) ) {
					return true;
				}

				( new BackupManager() )->process_step( $job );
				return false;

			case 'verify':
				$state = VerifyManager::step( $id );
				return null === $state || in_array( $state['status'], array( 'completed', 'failed' ), true );

			case 'restore_test':
				$state = RestoreTest::step( (int) $id );
				return null === $state || 'running' !== $state['status'];

			case 'links':
				$state = LinkCheck::state();

				if ( (string) ( $state['token'] ?? '' ) !== $id || ! in_array( $state['status'] ?? '', array( 'pending', 'checking' ), true ) ) {
					return true;
				}

				LinkCheck::step( $id );
				return false;
		}

		return true;
	}

	/** WP-Cron: go on with a job an assistant started, for up to four minutes at a time. */
	public static function continue_job( $kind, $id ): void {
		if ( in_array( (string) $kind, array( 'backup', 'verify', 'restore_test', 'links' ), true ) ) {
			self::advance( (string) $kind, (string) $id, 4 * MINUTE_IN_SECONDS );
		}
	}

	// ---------------------------------------------------------------------
	// Requests: a person approves them in wp-admin.
	// ---------------------------------------------------------------------

	private static function request_restore( array $input ): array {
		$backup = BackupsRepository::find( (int) ( $input['backup_id'] ?? 0 ) );

		if ( null === $backup ) {
			throw new \RuntimeException( esc_html__( 'Backup not found. Use wrensafe/list-backups for the ids.', 'wrensafe' ) );
		}

		return Requests::add( 'restore', array( 'backup_id' => (int) $backup['id'] ), (string) ( $input['reason'] ?? '' ) );
	}

	private static function request_rollback( array $input ): array {
		return Requests::add( 'rollback', array(), (string) ( $input['reason'] ?? '' ) );
	}

	private static function request_search_replace( array $input ): array {
		return Requests::add(
			'search_replace',
			array(
				'search'  => (string) ( $input['search'] ?? '' ),
				'replace' => (string) ( $input['replace'] ?? '' ),
			),
			(string) ( $input['reason'] ?? '' )
		);
	}
}
