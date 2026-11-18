<?php
/**
 * Tools → Site Health: checks for recent backups, a private backup folder
 * and off-site copies, plus a Wrensafe section under Info.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Core\Settings;
use WPBP\Remote\RemoteStorage;
use WPBP\Security\FileGuard;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Time;

defined( 'ABSPATH' ) || exit;

class SiteHealth {

	/** A backup older than this is reported as out of date. */
	private const STALE_DAYS = 7;

	public function register_hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_action( 'wp_ajax_health-check-wrensafe-backup-folder', array( $this, 'ajax_backup_folder' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $tests
	 * @return array<string,array<string,mixed>>
	 */
	public function register_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['wrensafe_recent_backup'] = array(
			'label' => __( 'Recent backup', 'wrensafe' ),
			'test'  => array( $this, 'test_recent_backup' ),
		);

		$tests['direct']['wrensafe_offsite_copy'] = array(
			'label' => __( 'Off-site backup copies', 'wrensafe' ),
			'test'  => array( $this, 'test_offsite_copy' ),
		);

		// Asynchronous: it requests a file over HTTP.
		$tests['async']['wrensafe_backup_folder'] = array(
			'label'             => __( 'Backup folder is private', 'wrensafe' ),
			'test'              => 'wrensafe-backup-folder',
			'has_rest'          => false,
			'async_direct_test' => array( $this, 'test_backup_folder' ),
		);

		return $tests;
	}

	/** @return array<string,mixed> */
	private function result( string $test, string $status, string $label, string $description, string $action_url = '', string $action_label = '' ): array {
		$actions = '';

		if ( '' !== $action_url ) {
			$actions = sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $action_url ), esc_html( $action_label ) );
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Backups', 'wrensafe' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}

	private static function page( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/** @return array<string,mixed> */
	public function test_recent_backup(): array {
		$latest    = BackupsRepository::find_latest_completed();
		$scheduled = SchedulesRepository::count_enabled() > 0;

		if ( ! $latest ) {
			return $this->result(
				'wrensafe_recent_backup',
				'critical',
				__( 'This site has no backup yet', 'wrensafe' ),
				__( 'Without a backup, a failed update, a hack or a server problem can’t be undone. Make a first backup, and turn on scheduled backups so new ones are made automatically.', 'wrensafe' ),
				self::page( 'wrensafe-backup' ),
				__( 'Create a backup', 'wrensafe' )
			);
		}

		$created = Time::mysql_to_timestamp( (string) $latest['created_at'] );
		$age     = $created > 0 ? time() - $created : 0;
		/* translators: %s: how long ago, e.g. "3 days" */
		$when = sprintf( __( '%s ago', 'wrensafe' ), human_time_diff( $created ) );

		if ( $age > self::STALE_DAYS * DAY_IN_SECONDS ) {
			return $this->result(
				'wrensafe_recent_backup',
				'recommended',
				__( 'The newest backup is out of date', 'wrensafe' ),
				/* translators: %s: how long ago the newest backup was made */
				sprintf( __( 'The newest backup was made %s. Changes made since then (new posts, orders, comments) would be lost if the site had to be restored.', 'wrensafe' ), $when ) . ( $scheduled ? '' : ' ' . __( 'Scheduled backups are off.', 'wrensafe' ) ),
				self::page( $scheduled ? 'wrensafe-backup' : 'wrensafe-schedule' ),
				$scheduled ? __( 'Create a backup', 'wrensafe' ) : __( 'Turn on scheduled backups', 'wrensafe' )
			);
		}

		if ( ! $scheduled ) {
			return $this->result(
				'wrensafe_recent_backup',
				'recommended',
				__( 'Scheduled backups are off', 'wrensafe' ),
				/* translators: %s: how long ago the newest backup was made */
				sprintf( __( 'The newest backup was made %s, but no new backups are made automatically.', 'wrensafe' ), $when ),
				self::page( 'wrensafe-schedule' ),
				__( 'Turn on scheduled backups', 'wrensafe' )
			);
		}

		return $this->result(
			'wrensafe_recent_backup',
			'good',
			__( 'The site was backed up recently', 'wrensafe' ),
			/* translators: %s: how long ago the newest backup was made */
			sprintf( __( 'The newest backup was made %s, and scheduled backups are on.', 'wrensafe' ), $when )
		);
	}

	/** @return array<string,mixed> */
	public function test_offsite_copy(): array {
		if ( ! RemoteStorage::is_enabled() && ! has_filter( 'wpbp_backup_remote_upload' ) ) {
			return $this->result(
				'wrensafe_offsite_copy',
				'recommended',
				__( 'Backups are kept only on this server', 'wrensafe' ),
				__( 'If the server fails or is hacked, backups stored on it can be lost with the site. Copy them to FTP, SFTP or S3-compatible storage as well.', 'wrensafe' ),
				self::page( 'wrensafe-settings', array( 'tab' => 'remote' ) ),
				__( 'Set up remote storage', 'wrensafe' )
			);
		}

		$latest = BackupsRepository::find_latest_completed();

		if ( $latest && 0 === strpos( (string) $latest['destination'], 'remote-failed:' ) ) {
			return $this->result(
				'wrensafe_offsite_copy',
				'recommended',
				__( 'The newest backup wasn’t copied off-site', 'wrensafe' ),
				__( 'Copying the newest backup to your remote storage failed. The reason is in the Activity Logs. The backup itself is safe on this server.', 'wrensafe' ),
				self::page( 'wrensafe-settings', array( 'tab' => 'remote' ) ),
				__( 'Check the connection', 'wrensafe' )
			);
		}

		return $this->result(
			'wrensafe_offsite_copy',
			'good',
			__( 'Backups are copied off-site', 'wrensafe' ),
			__( 'Backups are copied to remote storage, so they survive a server failure.', 'wrensafe' )
		);
	}

	/**
	 * Request a test file in the backup folder over HTTP: it must not be
	 * readable from the web.
	 *
	 * @return array<string,mixed>
	 */
	public function test_backup_folder(): array {
		$dir     = FileSystem::storage_dir();
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$root    = trailingslashit( wp_normalize_path( ABSPATH ) );
		$url     = '';

		if ( 0 === strpos( $dir, $content ) ) {
			$url = trailingslashit( content_url() ) . substr( $dir, strlen( $content ) );
		} elseif ( 0 === strpos( $dir, $root ) ) {
			$url = trailingslashit( site_url() ) . substr( $dir, strlen( $root ) );
		}

		if ( '' === $url ) {
			return $this->result(
				'wrensafe_backup_folder',
				'good',
				__( 'The backup folder is private', 'wrensafe' ),
				__( 'Backups are stored outside the website’s folders, so they can’t be downloaded from the web.', 'wrensafe' )
			);
		}

		FileGuard::repair_if_needed();

		$token = wp_generate_password( 24, false );
		$name  = 'wrensafe-check-' . strtolower( wp_generate_password( 8, false ) ) . '.txt';
		$file  = $dir . $name;

		if ( false === file_put_contents( $file, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a short-lived test file in the plugin's own folder.
			return $this->result(
				'wrensafe_backup_folder',
				'recommended',
				__( 'The backup folder couldn’t be checked', 'wrensafe' ),
				__( 'Wrensafe couldn’t write a test file to the backup folder. Check its permissions under Wrensafe → Settings → Backup.', 'wrensafe' ),
				self::page( 'wrensafe-settings' ),
				__( 'Open the backup settings', 'wrensafe' )
			);
		}

		$response = wp_remote_get(
			$url . $name,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-site-health.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, applied the way WP_Site_Health applies it.
			)
		);

		wp_delete_file( $file );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				'wrensafe_backup_folder',
				'recommended',
				__( 'The backup folder couldn’t be checked', 'wrensafe' ),
				/* translators: %s: error message */
				sprintf( __( 'This site couldn’t request its own address to check that backups can’t be downloaded (%s). Other Site Health checks about loopback requests may explain why.', 'wrensafe' ), $response->get_error_message() )
			);
		}

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && false !== strpos( (string) wp_remote_retrieve_body( $response ), $token ) ) {
			return $this->result(
				'wrensafe_backup_folder',
				'critical',
				__( 'Backups can be downloaded from the web', 'wrensafe' ),
				__( 'This web server ignores the .htaccess file that protects the backup folder (Nginx, for example), so anyone who guesses a backup’s address can download it. Choose a backup folder outside the website’s folders under Wrensafe → Settings → Backup, or ask your host to deny web access to it.', 'wrensafe' ),
				self::page( 'wrensafe-settings' ),
				__( 'Choose another backup folder', 'wrensafe' )
			);
		}

		return $this->result(
			'wrensafe_backup_folder',
			'good',
			__( 'The backup folder is private', 'wrensafe' ),
			__( 'Files in the backup folder can’t be downloaded from the web.', 'wrensafe' )
		);
	}

	/** Site Health's own AJAX request for the asynchronous test. */
	public function ajax_backup_folder(): void {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->test_backup_folder() );
	}

	/**
	 * Tools → Site Health → Info → Wrensafe.
	 *
	 * @param array<string,array<string,mixed>> $info
	 * @return array<string,array<string,mixed>>
	 */
	public function debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$latest   = BackupsRepository::find_latest_completed();
		$next     = SchedulesRepository::get_next_run_at();
		$remote   = RemoteStorage::get();
		$types    = RemoteStorage::types();
		$excluded = (array) Settings::get( 'excluded_tables', array() );
		$limit    = (int) Settings::get( 'exclude_larger_than_mb', 0 );

		$info['wrensafe'] = array(
			'label'  => __( 'Wrensafe', 'wrensafe' ),
			'fields' => array(
				'version'     => array(
					'label' => __( 'Version', 'wrensafe' ),
					'value' => WPBP_VERSION,
				),
				'storage'     => array(
					'label'   => __( 'Backup folder', 'wrensafe' ),
					'value'   => FileSystem::storage_dir(),
					'private' => true,
				),
				'backups'     => array(
					'label' => __( 'Completed backups', 'wrensafe' ),
					/* translators: 1: number of backups, 2: total size */
					'value' => sprintf( __( '%1$d (%2$s)', 'wrensafe' ), BackupsRepository::count_completed(), size_format( BackupsRepository::sum_size() ) ),
					'debug' => BackupsRepository::count_completed(),
				),
				'latest'      => array(
					'label' => __( 'Newest backup', 'wrensafe' ),
					'value' => $latest ? Time::format_mysql( (string) $latest['created_at'] ) : __( 'None', 'wrensafe' ),
				),
				'schedules'   => array(
					'label' => __( 'Enabled schedules', 'wrensafe' ),
					'value' => SchedulesRepository::count_enabled(),
				),
				'next_run'    => array(
					'label' => __( 'Next scheduled backup', 'wrensafe' ),
					'value' => $next ? Time::format_mysql( $next ) : __( 'None', 'wrensafe' ),
				),
				'remote'      => array(
					'label' => __( 'Remote storage', 'wrensafe' ),
					'value' => $types[ $remote['type'] ] ?? __( 'Not set up', 'wrensafe' ),
					'debug' => '' !== $remote['type'] ? $remote['type'] : 'none',
				),
				'verify'      => array(
					'label' => __( 'Check each backup after it’s made', 'wrensafe' ),
					'value' => Settings::get( 'verify_after_backup', false ) ? __( 'Yes', 'wrensafe' ) : __( 'No', 'wrensafe' ),
					'debug' => Settings::get( 'verify_after_backup', false ) ? 'yes' : 'no',
				),
				'excluded'    => array(
					'label' => __( 'Database tables left out', 'wrensafe' ),
					'value' => $excluded ? implode( ', ', $excluded ) : __( 'None', 'wrensafe' ),
				),
				'large_files' => array(
					'label' => __( 'Files larger than this are left out', 'wrensafe' ),
					/* translators: %d: size in megabytes */
					'value' => $limit > 0 ? sprintf( __( '%d MB', 'wrensafe' ), $limit ) : __( 'No limit', 'wrensafe' ),
					'debug' => $limit,
				),
				'protection'  => array(
					'label' => __( 'Folder protection files', 'wrensafe' ),
					'value' => FileGuard::status()['protected'] ? __( 'Present', 'wrensafe' ) : __( 'Missing', 'wrensafe' ),
				),
			),
		);

		return $info;
	}
}
