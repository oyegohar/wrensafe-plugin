<?php
/**
 * Safe Updates: the update window on the Plugins and Updates screens, the
 * site checks' admin ping, the temporary boot guard, and the background
 * continuation of a run. The update work itself is in SafeUpdateManager.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Core\Settings;
use WPBP\Security\NonceManager;
use WPBP\Utils\Logger;
use WPBP\Utils\Paths;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

class SafeUpdates {

	/** WP-Cron event that continues a run when no browser drives it. */
	const CONTINUE_HOOK = 'wpbp_safe_update_continue';

	/** Option read by the boot guard: what is being updated right now, and the run's secret. */
	const GUARD_OPTION = 'wpbp_safe_update_guard';

	/** Option read by the boot guard: files to read fresh in the next site check. */
	const FRESH_OPTION = 'wpbp_safe_update_fresh';

	/** File name of the temporary MU-plugin boot guard. */
	const GUARD_FILE = 'wpbp-safe-update-guard.php';

	/** Request field with the run's secret, sent by the Safe Updates script (see the guard). */
	const GUARD_FIELD = '_wpbp_su_guard';

	/** Admin page slug. */
	const PAGE_SLUG = 'wrensafe-safe-updates';

	/** Per-user flag: clear caches after WordPress's own update (Quick Update). */
	const QUICK_CACHE_FLAG = 'wpbp_su_quick_cache_';

	public function register_hooks(): void {
		add_action( self::CONTINUE_HOOK, array( self::class, 'continue_in_background' ) );
		add_action( \WPBP\Scheduler\CronHandler::HOOK, array( self::class, 'housekeeping' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'after_native_update' ), 20, 2 );

		// The site check's look at the admin area (see UpdateHealthCheck).
		add_action( 'wp_ajax_wpbp_update_ping', array( self::class, 'ping' ) );
		add_action( 'wp_ajax_nopriv_wpbp_update_ping', array( self::class, 'ping' ) );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_prompt' ) );
		}
	}

	/** Answer the site check's admin-ajax request. */
	public static function ping(): void {
		wp_send_json_success( UpdateHealthCheck::PING_ANSWER );
	}

	/**
	 * Why Safe Updates can't run on this site, or '' when they can. Only
	 * the checks that cost nothing (it runs on page views); whether WordPress
	 * can write files is checked when a run starts (filesystem_reason()).
	 */
	public static function blocked_reason(): string {
		if ( is_multisite() ) {
			return __( 'Safe Updates work on single sites. On a multisite network, update plugins from Network Admin → Updates.', 'wrensafe' );
		}

		if ( ! wp_is_file_mod_allowed( 'wpbp_safe_updates' ) ) {
			return __( 'Plugin updates are turned off on this site (DISALLOW_FILE_MODS in wp-config.php).', 'wrensafe' );
		}

		return '';
	}

	/**
	 * Why WordPress can't change files in the background here, or ''. This
	 * connects WP_Filesystem (an FTP connection on some hosts), so it runs
	 * when a run starts or a rollback is asked for, not on page views.
	 */
	public static function filesystem_reason(): string {
		if ( self::filesystem() ) {
			return '';
		}

		return __( 'WordPress asks for FTP details before it can change plugin files on this server, so updates can’t run in the background. Add the FTP details to wp-config.php (FTP_HOST, FTP_USER and FTP_PASS), or update from the Plugins screen as usual.', 'wrensafe' );
	}

	/**
	 * Connect WP_Filesystem to the plugins folder without asking for
	 * credentials (direct access, or credentials defined in wp-config.php).
	 */
	public static function filesystem(): bool {
		static $connected = null;

		if ( null !== $connected ) {
			return $connected;
		}

		self::load_upgrader();

		$skin        = new \Automatic_Upgrader_Skin();
		$credentials = $skin->request_filesystem_credentials( false, WP_PLUGIN_DIR, true );
		$connected   = false !== $credentials && WP_Filesystem( true === $credentials ? false : $credentials, WP_PLUGIN_DIR, true );

		return (bool) $connected;
	}

	/** WordPress's upgrader classes and the admin functions they need. */
	public static function load_upgrader(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * The update modes. 'safe' tells whether the mode saves a copy of each
	 * item and checks the site after the update.
	 *
	 * @return array<string,array{label:string,description:string,badge:string,safe:bool}>
	 */
	public static function modes(): array {
		$modes = array(
			'quick' => array(
				'label'       => __( 'Quick Update', 'wrensafe' ),
				'description' => __( 'WordPress’s standard update. The fastest way, with no safety net.', 'wrensafe' ),
				'badge'       => '',
				'safe'        => false,
			),
			'safe'  => array(
				'label'       => __( 'Safe Update', 'wrensafe' ),
				'description' => __( 'Wrensafe saves a copy of the plugin, updates it and checks your site. If the site shows an error, the previous version is put back automatically.', 'wrensafe' ),
				'badge'       => __( 'Recommended', 'wrensafe' ),
				'safe'        => true,
			),
		);

		/**
		 * Filters the update modes offered in the update window and on the
		 * Safe Updates page (key => label, description, badge, safe). A mode
		 * with 'safe' => true gets a restore point and the site check for
		 * every item; an add-on adds its own steps through the
		 * wpbp_safe_update_* actions and filters.
		 *
		 * @param array<string,array> $extra Modes to add.
		 */
		$extra = (array) apply_filters( 'wpbp_safe_update_modes', array() );

		foreach ( $extra as $key => $mode ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || isset( $modes[ $key ] ) || ! is_array( $mode ) || empty( $mode['label'] ) ) {
				continue;
			}

			$modes[ $key ] = array(
				'label'       => (string) $mode['label'],
				'description' => (string) ( $mode['description'] ?? '' ),
				'badge'       => (string) ( $mode['badge'] ?? '' ),
				'safe'        => ! empty( $mode['safe'] ),
			);
		}

		return $modes;
	}

	public static function is_safe_mode( string $mode ): bool {
		$modes = self::modes();

		return ! empty( $modes[ $mode ]['safe'] );
	}

	/** The mode chosen under Settings → Updates (falls back to Safe Update). */
	public static function default_mode(): string {
		$mode = (string) Settings::get( 'safe_updates_mode', 'safe' );

		return isset( self::modes()[ $mode ] ) ? $mode : 'safe';
	}

	public static function default_order(): string {
		return SafeUpdateManager::ORDER_ALL === Settings::get( 'safe_updates_order', SafeUpdateManager::ORDER_ONE ) ? SafeUpdateManager::ORDER_ALL : SafeUpdateManager::ORDER_ONE;
	}

	/** Whether the update window opens on the Plugins and Updates screens (cheap checks only). */
	public static function prompt_enabled(): bool {
		return (bool) Settings::get( 'safe_updates_prompt', true )
			&& current_user_can( 'update_plugins' )
			&& '' === self::blocked_reason();
	}

	/**
	 * The admin screens where the update window opens.
	 *
	 * @return array<string,string> Hook suffix => screen name for the script.
	 */
	public static function screens(): array {
		/**
		 * Filters the admin screens where Safe Updates' update window opens.
		 * The script and its public API (window.wpbpSafeUpdates.choose())
		 * load there; the built-in screens are hooked by the script itself,
		 * and an add-on hooks its own screen through the API.
		 *
		 * @param array<string,string> $screens Hook suffix (e.g. "plugins.php") => screen name,
		 *                                      passed to the script as wpbpSafeUpdates.screen.
		 */
		$screens = (array) apply_filters(
			'wpbp_safe_update_screens',
			array(
				'plugins.php'     => 'plugins',
				'update-core.php' => 'update-core',
			)
		);

		return array_map( 'sanitize_key', array_filter( $screens, 'is_string' ) );
	}

	/**
	 * Load the update window on the Plugins and Updates screens.
	 */
	public function enqueue_prompt( string $hook_suffix ): void {
		// The Safe Updates page itself.
		if ( Str::contains( $hook_suffix, self::PAGE_SLUG ) ) {
			if ( current_user_can( 'update_plugins' ) ) {
				self::enqueue_assets( 'page' );
			}

			return;
		}

		$screens = self::screens();

		if ( ! isset( $screens[ $hook_suffix ] ) || ! self::prompt_enabled() ) {
			return;
		}

		self::enqueue_assets( $screens[ $hook_suffix ] );
	}

	/**
	 * The update window's script and styles, also used by the Safe Updates page.
	 */
	public static function enqueue_assets( string $screen ): void {
		wp_enqueue_style( 'wpbp-safe-updates', WPBP_PLUGIN_URL . 'assets/admin/css/safe-updates.css', array(), WPBP_VERSION );
		wp_style_add_data( 'wpbp-safe-updates', 'rtl', 'replace' );

		$deps = array( 'jquery' );

		if ( 'page' !== $screen ) {
			$deps[] = 'updates';
		}

		wp_enqueue_script( 'wpbp-safe-updates', WPBP_PLUGIN_URL . 'assets/admin/js/safe-updates.js', $deps, WPBP_VERSION, true );

		$types = array();

		foreach ( UpdateTypes::all() as $key => $type ) {
			$updates = array();

			foreach ( SafeUpdateManager::available( $key ) as $id => $item ) {
				$updates[ $id ] = array(
					'name'    => $item['name'],
					'from'    => $item['from'],
					'to'      => $item['to'],
					'problem' => $item['problem'],
				);
			}

			$types[ $key ] = array(
				'label'   => $type->label(),
				'i18n'    => $type->strings(),
				'updates' => (object) $updates,
			);
		}

		$modes = array();

		foreach ( self::modes() as $key => $mode ) {
			$modes[] = array_merge( array( 'key' => $key ), $mode );
		}

		$running = SafeUpdateManager::running_job();

		wp_localize_script(
			'wpbp-safe-updates',
			'wpbpSafeUpdates',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => NonceManager::create( 'admin_ajax' ),
				'screen'       => $screen,
				'modes'        => $modes,
				'defaultMode'  => self::default_mode(),
				'defaultOrder' => self::default_order(),
				'clearCache'   => (bool) Settings::get( 'safe_updates_clear_cache', true ),
				// Plugins, as before update types existed; every type is under 'types'.
				'updates'      => $types[ PluginUpdateType::KEY ]['updates'],
				'types'        => $types,
				'running'      => $running ? $running->id() : 0,
				// The running run's secret: this script's requests load without what it is checking.
				'guardKey'     => $running && current_user_can( 'update_plugins' ) ? self::guard_key() : '',
				'pageUrl'      => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'markUrl'      => WPBP_PLUGIN_URL . 'assets/admin/images/wrensafe-mark.svg',
				'settingsUrl'  => admin_url( 'admin.php?page=wrensafe-settings&tab=updates' ),
				'logsUrl'      => admin_url( 'admin.php?page=wrensafe-logs' ),
				'i18n'         => self::strings(),
			)
		);
	}

	/**
	 * Text used by the update window and the Safe Updates page. The
	 * type-dependent texts are the plugin ones here; each type's own are
	 * under wpbpSafeUpdates.types[key].i18n, and 'mixed' has those for a
	 * list of several types.
	 */
	private static function strings(): array {
		return array_merge(
			( new PluginUpdateType() )->strings(),
			array(
				'title'          => __( 'How do you want to update?', 'wrensafe' ),
				/* translators: 1: name of the plugin (or theme), 2: current version, 3: new version */
				'updatingOne'    => __( '%1$s, from version %2$s to %3$s', 'wrensafe' ),
				'clearCache'     => __( 'Clear the cache after updating', 'wrensafe' ),
				'clearCacheHelp' => __( 'Empties the page cache of your caching plugin and WordPress’s object cache.', 'wrensafe' ),
				'orderLabel'     => __( 'Order', 'wrensafe' ),
				'orderOne'       => __( 'One by one', 'wrensafe' ),
				'orderAll'       => __( 'All at once', 'wrensafe' ),
				'cancel'         => __( 'Cancel', 'wrensafe' ),
				'close'          => __( 'Close', 'wrensafe' ),
				'update'         => __( 'Update', 'wrensafe' ),
				'settingsNote'   => __( 'Change the default, or turn this window off, under Wrensafe → Settings → Updates.', 'wrensafe' ),
				'settingsLink'   => __( 'Settings', 'wrensafe' ),
				'doneTitle'      => __( 'Updates finished', 'wrensafe' ),
				'failedTitle'    => __( 'The updates stopped', 'wrensafe' ),
				'keepOpen'       => __( 'You can close this window: the updates continue in the background, and you can follow them on the Safe Updates page.', 'wrensafe' ),
				'viewPage'       => __( 'Open Safe Updates', 'wrensafe' ),
				'reload'         => __( 'Done', 'wrensafe' ),
				'starting'       => __( 'Starting…', 'wrensafe' ),
				'maintenance'    => __( 'WordPress is in maintenance mode after an interrupted update. Wrensafe continues as soon as it ends (within 10 minutes).', 'wrensafe' ),
				'connection'     => __( 'Lost the connection to the site. Trying again…', 'wrensafe' ),
				'genericError'   => __( 'Something went wrong. See Wrensafe → Activity Logs.', 'wrensafe' ),
				'sessionError'   => __( 'Your login has expired or the page is out of date. Reload the page and try again.', 'wrensafe' ),
				'rollback'       => __( 'Roll back', 'wrensafe' ),
				/* translators: %s: version number */
				'rollbackTo'     => __( 'Roll back to %s', 'wrensafe' ),
				'rolledBack'     => __( 'Rolled back.', 'wrensafe' ),
				'checking'       => __( 'Checking for updates…', 'wrensafe' ),
				'keep'           => __( 'Keep the update', 'wrensafe' ),
				/* translators: %s: label of a picture, e.g. "Home page after the update" */
				'openImage'      => __( 'Open %s in a new tab', 'wrensafe' ),
				'mixed'          => array(
					/* translators: %d: number of updates */
					'updatingMany'    => __( '%d updates', 'wrensafe' ),
					/* translators: %d: number of updates */
					'updateMany'      => __( 'Install %d updates', 'wrensafe' ),
					'progressTitle'   => __( 'Installing updates', 'wrensafe' ),
					'cancelRun'       => __( 'Stop after this update', 'wrensafe' ),
					'cancelling'      => __( 'Stopping after the current update…', 'wrensafe' ),
					'orderOneHelp'    => __( 'Each update is installed and checked before the next one. Safest.', 'wrensafe' ),
					'orderAllHelp'    => __( 'Every update is installed, then the site is checked once. Faster.', 'wrensafe' ),
					'nothingSelected' => __( 'Select at least one update to install.', 'wrensafe' ),
					'updateSelected'  => __( 'Install selected updates', 'wrensafe' ),
					'modes'           => array(
						'safe' => __( 'Wrensafe saves a copy of each one, installs the update and checks your site. If the site shows an error, the previous version is put back automatically.', 'wrensafe' ),
					),
				),
				'states'         => array(
					'waiting'      => __( 'Waiting', 'wrensafe' ),
					'saving'       => __( 'Saving a copy…', 'wrensafe' ),
					'ready'        => __( 'Ready', 'wrensafe' ),
					'updating'     => __( 'Updating…', 'wrensafe' ),
					'installed'    => __( 'Updated, waiting for the site check', 'wrensafe' ),
					'checking'     => __( 'Checking the site…', 'wrensafe' ),
					'awaiting'     => __( 'Waiting for your decision', 'wrensafe' ),
					'rolling_back' => __( 'Putting the previous version back…', 'wrensafe' ),
					'updated'      => __( 'Updated', 'wrensafe' ),
					'rolled_back'  => __( 'Rolled back', 'wrensafe' ),
					'failed'       => __( 'Failed', 'wrensafe' ),
					'skipped'      => __( 'Skipped', 'wrensafe' ),
					'cancelled'    => __( 'Not updated (stopped)', 'wrensafe' ),
				),
			)
		);
	}

	/**
	 * Quick Update from the update window runs WordPress's own update. When
	 * the user ticked "Clear the cache", the window sets this short-lived
	 * flag first, and caches are cleared when WordPress has updated.
	 *
	 * @param \WP_Upgrader $upgrader
	 * @param array        $extra
	 */
	public static function after_native_update( $upgrader, $extra ): void {
		if ( ! is_array( $extra ) || ! in_array( $extra['type'] ?? '', array( 'plugin', 'theme', 'core' ), true ) || 'update' !== ( $extra['action'] ?? '' ) || SafeUpdateManager::is_running_here() ) {
			return;
		}

		$user = get_current_user_id();

		if ( $user && get_transient( self::QUICK_CACHE_FLAG . $user ) ) {
			CacheCleaner::clear_all();
		}
	}

	// ---------------------------------------------------------------------
	// Boot guard.
	// ---------------------------------------------------------------------

	private static function guard_path(): string {
		return Paths::mu_plugins_dir() . '/' . self::GUARD_FILE;
	}

	/**
	 * Install the temporary MU-plugin boot guard (a copy of
	 * includes/Updates/guard/safe-update-guard.php), through WP_Filesystem.
	 * Best effort: without it, a run interrupted by an update that breaks
	 * every page can only continue once that is fixed by hand.
	 */
	public static function install_guard( ?int $job_id = null ): bool {
		global $wp_filesystem;

		if ( ! self::filesystem() || ! is_object( $wp_filesystem ) ) {
			Logger::warning( 'Safe Updates guard not installed: WordPress couldn’t get write access to the files. Continuing without it.', array(), $job_id );
			return false;
		}

		$source = $wp_filesystem->find_folder( WPBP_PLUGIN_DIR . 'includes/Updates/guard/' );
		$folder = $wp_filesystem->find_folder( Paths::mu_plugins_dir() );

		if ( false === $source || false === $folder ) {
			Logger::warning( 'Safe Updates guard not installed: WordPress couldn’t find the mu-plugins folder (' . Paths::mu_plugins_dir() . '). Continuing without it.', array(), $job_id );
			return false;
		}

		$folder = trailingslashit( $folder );

		if ( ! $wp_filesystem->is_dir( $folder ) && ! $wp_filesystem->mkdir( $folder, FS_CHMOD_DIR ) ) {
			Logger::warning( 'Safe Updates guard not installed: the mu-plugins folder (' . Paths::mu_plugins_dir() . ') doesn’t exist and couldn’t be created. Continuing without it.', array(), $job_id );
			return false;
		}

		if ( ! $wp_filesystem->copy( trailingslashit( $source ) . 'safe-update-guard.php', $folder . self::GUARD_FILE, true, FS_CHMOD_FILE ) ) {
			Logger::warning( 'Safe Updates guard not installed: it couldn’t be copied to the mu-plugins folder (' . Paths::mu_plugins_dir() . ' isn’t writable). Continuing without it.', array(), $job_id );
			return false;
		}

		return true;
	}

	/** A new secret for a run (see the guard). */
	private static function new_key(): string {
		return strtolower( wp_generate_password( 32, false ) );
	}

	/**
	 * A run in a safe mode starts: the guard knows the run's secret, and
	 * nothing is being updated yet.
	 */
	public static function open_guard(): void {
		update_option(
			self::GUARD_OPTION,
			array(
				'key'     => self::new_key(),
				'plugins' => array(),
				'files'   => array(),
				'data'    => array(),
				'expires' => time() + 30 * MINUTE_IN_SECONDS,
			)
		);
	}

	/**
	 * The running run's secret, which the Safe Updates script sends with its
	 * own requests so the guard knows them ('' when there is none).
	 */
	public static function guard_key(): string {
		$guard = get_option( self::GUARD_OPTION );

		return is_array( $guard ) ? (string) ( $guard['key'] ?? '' ) : '';
	}

	/**
	 * Name what is being updated right now: if the request updating it
	 * dies, Wrensafe's own next requests load without it (see the guard).
	 *
	 * @param array<string,string[]> $by_type Update type key => ids installed but not checked yet.
	 */
	public static function guard_items( array $by_type ): void {
		$plugins = array();
		$stall   = array();
		$files   = array();
		$data    = array();
		$root    = trailingslashit( wp_normalize_path( (string) realpath( WP_PLUGIN_DIR ) ) );

		foreach ( $by_type as $key => $ids ) {
			$type = UpdateTypes::get( (string) $key );
			$ids  = array_values( array_filter( array_map( 'strval', (array) $ids ) ) );

			if ( null === $type || empty( $ids ) ) {
				continue;
			}

			$guard = (array) $type->guard_data( $ids );

			$plugins = array_merge( $plugins, array_map( 'strval', (array) ( $guard['plugins'] ?? array() ) ) );

			// Plugin updates not checked yet: the only ones a stalled run hides from visitors.
			if ( PluginUpdateType::KEY === (string) $key ) {
				$stall = array_merge( $stall, array_map( 'strval', (array) ( $guard['plugins'] ?? array() ) ) );
			}

			// Only PHP files inside the plugins folder (an add-on's own guard code).
			foreach ( (array) ( $guard['files'] ?? array() ) as $file ) {
				$real = realpath( (string) $file );

				if ( false !== $real && Str::ends_with( $real, '.php' ) && Str::starts_with( wp_normalize_path( $real ), $root ) ) {
					$files[] = wp_normalize_path( $real );
				}
			}

			unset( $guard['plugins'], $guard['files'] );

			if ( ! empty( $guard ) ) {
				$data[ $key ] = $guard;
			}
		}

		$key = self::guard_key();

		update_option(
			self::GUARD_OPTION,
			array(
				'key'     => '' !== $key ? $key : self::new_key(),
				'plugins' => array_values( array_unique( $plugins ) ),
				'files'   => array_values( array_unique( $files ) ),
				'data'    => $data,
				'expires' => time() + 30 * MINUTE_IN_SECONDS,
				'stall'   => array_values( array_unique( $stall ) ),
				'touched' => time(),
			)
		);
	}

	/**
	 * A run that waits for a decision (an add-on's check that asks the
	 * user) isn't stalled: its updates passed the standard checks, so the
	 * guard stops treating them as unchecked.
	 */
	public static function hold_guard(): void {
		$guard = get_option( self::GUARD_OPTION );

		if ( is_array( $guard ) && ! empty( $guard['stall'] ) ) {
			$guard['stall'] = array();
			update_option( self::GUARD_OPTION, $guard );
		}
	}

	/**
	 * Heartbeat of a run with updates not checked yet: each step of the run
	 * records the time. Without it for a while, the guard treats the run as
	 * cut off (see the guard).
	 */
	public static function touch_guard(): void {
		$guard = get_option( self::GUARD_OPTION );

		if ( is_array( $guard ) && ! empty( $guard['stall'] ) && (int) ( $guard['touched'] ?? 0 ) < time() ) {
			$guard['touched'] = time();
			update_option( self::GUARD_OPTION, $guard );
		}
	}

	/**
	 * The files changed since the last site check: the check requests read
	 * them from disk instead of PHP's opcode cache (see the guard).
	 *
	 * @param string[] $paths Absolute paths of folders or files.
	 */
	public static function refresh_on_check( array $paths ): void {
		update_option(
			self::FRESH_OPTION,
			array(
				'paths'   => array_values( array_unique( array_map( 'wp_normalize_path', array_map( 'strval', $paths ) ) ) ),
				'expires' => time() + 10 * MINUTE_IN_SECONDS,
			)
		);
	}

	public static function guard_installed(): bool {
		return file_exists( self::guard_path() );
	}

	public static function remove_guard(): void {
		delete_option( self::GUARD_OPTION );
		delete_option( self::FRESH_OPTION );

		if ( file_exists( self::guard_path() ) ) {
			wp_delete_file( self::guard_path() );
		}
	}

	// ---------------------------------------------------------------------
	// Background continuation and housekeeping.
	// ---------------------------------------------------------------------

	public static function schedule_continue( int $job_id, int $delay = 60 ): void {
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::CONTINUE_HOOK, array( $job_id ) );
		}
	}

	/**
	 * WP-Cron: continue a run whose browser window was closed. While a
	 * window drives the run (it asks every few seconds), this waits.
	 *
	 * @param int $job_id
	 */
	public static function continue_in_background( $job_id ): void {
		$job = SafeUpdateJob::get( (int) $job_id );

		if ( null === $job || $job->is_finished() ) {
			return;
		}

		if ( SafeUpdateManager::seconds_since_activity( $job ) < 45 ) {
			self::schedule_continue( $job->id(), 60 );
			return;
		}

		SafeUpdateManager::process( $job, false, 40 );

		if ( ! $job->is_finished() ) {
			// Straight on: no browser is waiting for this run.
			self::schedule_continue( $job->id(), 1 );
			spawn_cron();
		}
	}

	/**
	 * Every Wrensafe cron tick: delete old restore points, and remove a
	 * guard left behind by a run that is over.
	 */
	public static function housekeeping(): void {
		RestorePoint::cleanup( (int) Settings::get( 'safe_updates_keep_days', 14 ) );

		if ( null === SafeUpdateManager::running_job() && ( file_exists( self::guard_path() ) || false !== get_option( self::GUARD_OPTION, false ) || false !== get_option( self::FRESH_OPTION, false ) ) ) {
			self::remove_guard();
		}
	}
}
