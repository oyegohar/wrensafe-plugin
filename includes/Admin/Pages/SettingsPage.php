<?php
/**
 * Settings page with the full tab set from the development plan.
 *
 * Tabs that don't yet have real functionality to configure show an
 * informational card instead of fake controls — wiring lands as each
 * module is built in its own development phase.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\SupportUs;
use WPBP\Core\LimitsManager;
use WPBP\Core\Settings;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

class SettingsPage extends AbstractPage {

	const MENU_SLUG = 'wrensafe-settings';

	public function get_title(): string {
		return __( 'Settings', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Control how backups, restores and migrations behave on this site.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::MANAGE_SETTINGS;
	}

	/**
	 * Some tabs cover more sensitive ground than general settings (granting
	 * other users capabilities, generating an emergency restore script) and
	 * need their own, narrower capability on top of base Settings access —
	 * so a "Settings Manager" bundle doesn't implicitly include those.
	 */
	private function tab_capability( string $tab ): string {
		$map = array(
			'security'    => CapabilityManager::MANAGE_SECURITY,
			'team-access' => CapabilityManager::MANAGE_TEAM_ACCESS,
		);

		return $map[ $tab ] ?? CapabilityManager::MANAGE_SETTINGS;
	}

	/**
	 * Map of tab slug => label.
	 */
	private function tabs(): array {
		$tabs = array(
			'general'          => __( 'General', 'wrensafe' ),
			'backup'           => __( 'Backup', 'wrensafe' ),
			'direct-migration' => __( 'Direct Migration', 'wrensafe' ),
			'woocommerce'      => __( 'WooCommerce Safe Mode', 'wrensafe' ),
			'notifications'    => __( 'Notifications', 'wrensafe' ),
			'security'         => __( 'Security', 'wrensafe' ),
			'support-us'       => __( 'Support Us', 'wrensafe' ),
			'advanced'         => __( 'Advanced', 'wrensafe' ),
		);

		/**
		 * Filters the Settings sections (slug => label). Add-ons register a
		 * section here and print it on the "wpbp_settings_tab_{slug}" action.
		 *
		 * @param array<string,string> $tabs Section slug => label.
		 */
		$extra = (array) apply_filters( 'wpbp_settings_tabs', array() );

		foreach ( $extra as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug && ! isset( $tabs[ $slug ] ) ) {
				$tabs[ $slug ] = (string) $label;
			}
		}

		return $tabs;
	}

	private function current_tab(): string {
		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, validated against tabs().

		return isset( $tabs[ $tab ] ) ? $tab : 'general';
	}

	protected function render_content(): void {
		$tabs        = $this->tabs();
		$current_tab = $this->current_tab();

		// After a save, options.php returns here with settings-updated=1 and
		// its messages ("Settings saved." or a field error) in a transient.
		// They are shown as toasts; errors also stay on the page.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether to show the save result.
			foreach ( get_settings_errors() as $message ) {
				$is_error = 'error' === $message['type'];
				$text     = wp_strip_all_tags( (string) $message['message'] );

				if ( 'settings_updated' === $message['code'] ) {
					$text = __( 'Settings saved.', 'wrensafe' );
				}

				printf(
					'<div class="wpbp-flash%1$s" data-type="%2$s"%3$s>%4$s</div>',
					$is_error ? ' notice notice-error inline' : '',
					esc_attr( $is_error ? 'error' : 'success' ),
					$is_error ? '' : ' hidden',
					$is_error ? '<p>' . esc_html( $text ) . '</p>' : esc_html( $text )
				);
			}
		}
		?>
		<?php
		$groups = array(
			__( 'Backups', 'wrensafe' )           => array( 'general', 'backup', 'cloud' ),
			__( 'Migration', 'wrensafe' )         => array( 'direct-migration', 'woocommerce' ),
			__( 'Reporting', 'wrensafe' )         => array( 'notifications', 'reports' ),
			__( 'Access & security', 'wrensafe' ) => array( 'security', 'team-access' ),
			__( 'More', 'wrensafe' )              => array( 'support-us', 'advanced' ),
		);

		/**
		 * Filters the Settings navigation groups (label => tab slugs), so an
		 * add-on can place its sections next to related ones.
		 *
		 * @param array<string,string[]> $groups
		 */
		$groups = (array) apply_filters( 'wpbp_settings_groups', $groups );

		// Any tab not placed in a group above still gets a link.
		$grouped = array_merge( ...array_values( $groups ) );
		$extra   = array_diff( array_keys( $tabs ), $grouped );
		if ( $extra ) {
			$groups[ __( 'More', 'wrensafe' ) ] = array_merge( $groups[ __( 'More', 'wrensafe' ) ], $extra );
		}
		?>
		<div class="wpbp-settings-layout">
			<nav class="wpbp-settings-nav wpbp-nav-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'wrensafe' ); ?>">
				<?php foreach ( $groups as $group_label => $slugs ) : ?>
					<div class="wpbp-settings-nav-group">
						<p class="wpbp-settings-nav-label"><?php echo esc_html( $group_label ); ?></p>
						<?php foreach ( $slugs as $slug ) : ?>
							<?php
							if ( ! isset( $tabs[ $slug ] ) ) {
								continue;
							}
							?>
							<a class="wpbp-settings-nav-link" href="<?php echo esc_url( self::page_url( self::MENU_SLUG, array( 'tab' => $slug ) ) ); ?>"<?php echo $slug === $current_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tabs[ $slug ] ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</nav>

			<div class="wpbp-tab-content">
				<?php
				if ( ! CapabilityManager::current_user_can( $this->tab_capability( $current_tab ) ) ) {
					echo '<div class="notice notice-error wpbp-notice"><p>' . esc_html__( 'You do not have permission to view this tab.', 'wrensafe' ) . '</p></div>';
				} else {
					$method = 'render_tab_' . str_replace( '-', '_', $current_tab );

					if ( method_exists( $this, $method ) ) {
						$this->$method();
					} else {
						/**
						 * Prints a Settings section registered through the
						 * wpbp_settings_tabs filter.
						 */
						do_action( 'wpbp_settings_tab_' . $current_tab );
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Wrap a settings form for a given tab around the supplied field-rendering callback.
	 */
	/**
	 * A Settings section form posting to options.php. Public so add-on
	 * sections (wpbp_settings_tab_{slug}) save through the same option.
	 */
	public static function render_settings_form( string $tab, callable $render_fields ): void {
		?>
		<form method="post" action="options.php" class="wpbp-card">
			<?php settings_fields( Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>" />

			<table class="form-table" role="presentation">
				<?php $render_fields(); ?>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_tab_general(): void {
		$settings = Settings::get_all();

		self::render_settings_form(
			'general',
			function () use ( $settings ) {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Activity logging', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enable_activity_log]" value="1" <?php checked( ! empty( $settings['enable_activity_log'] ) ); ?> />
							<?php esc_html_e( 'Record every backup, restore and migration step on the Activity Logs page.', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Warnings and errors are always recorded.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Getting started', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'See the short introduction again, or bring back the Get started checklist on the Dashboard.', 'wrensafe' ); ?></p>
			<div class="wpbp-button-row">
				<button type="button" class="button" data-wpbp-welcome-open><?php esc_html_e( 'Show the welcome tour', 'wrensafe' ); ?></button>
				<button type="button" class="button" id="wpbp-onboarding-restart" data-url="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe#wpbp-onboarding' ) ); ?>"><?php esc_html_e( 'Show the Get started checklist', 'wrensafe' ); ?></button>
			</div>
		</div>
		<?php
	}

	private function render_tab_notifications(): void {
		$settings = Settings::get_all();

		self::render_settings_form(
			'notifications',
			function () use ( $settings ) {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'When something fails', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[notify_on_failure]" value="1" <?php checked( ! empty( $settings['notify_on_failure'] ) ); ?> />
							<?php esc_html_e( 'Email me when a backup (manual or automatic) or a restore fails.', 'wrensafe' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When something succeeds', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[notify_on_success]" value="1" <?php checked( ! empty( $settings['notify_on_success'] ) ); ?> />
							<?php esc_html_e( 'Email me when a backup or a restore completes.', 'wrensafe' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-notification-email"><?php esc_html_e( 'Send emails to', 'wrensafe' ); ?></label></th>
					<td>
						<input type="email" id="wpbp-notification-email" class="regular-text" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Emails are sent with WordPress\'s own mail function. If they don\'t arrive, check that this site can send email (an SMTP plugin often helps).', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);
	}

	private function render_tab_security(): void {
		$settings = Settings::get_all();

		self::render_settings_form(
			'security',
			function () use ( $settings ) {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Locked backups', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[allow_locked_backup_downloads]" value="1" <?php checked( ! empty( $settings['allow_locked_backup_downloads'] ) ); ?> />
							<?php esc_html_e( 'Allow downloading locked backups.', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A locked backup can\'t be deleted or restored until it is unlocked. With this off, it can\'t be downloaded either.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);
	}

	private function render_restore_lock_card(): void {
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Is a restore stuck?', 'wrensafe' ); ?></h2>
			<p class="wpbp-limit-note">
				<?php esc_html_e( 'If the site stays in maintenance mode or a restore never finishes, this ends maintenance mode, releases the restore lock and marks the stuck restore as failed.', 'wrensafe' ); ?>
			</p>
			<button type="button" id="wpbp-clear-restore-lock" class="button button-primary"><?php esc_html_e( 'Clear restore lock', 'wrensafe' ); ?></button>
			<span id="wpbp-clear-restore-lock-msg" style="margin-left:12px;display:none;"></span>
		</div>

		<?php
	}

	private function render_tab_advanced(): void {
		$settings = Settings::get_all();

		$this->render_restore_lock_card();

		self::render_settings_form(
			'advanced',
			function () use ( $settings ) {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug logging', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enable_debug_logging]" value="1" <?php checked( ! empty( $settings['enable_debug_logging'] ) ); ?> />
							<?php esc_html_e( 'Also record detailed debug entries on the Activity Logs page (useful when asking for support).', 'wrensafe' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
							<?php esc_html_e( 'Delete Wrensafe\'s data from the database when the plugin is deleted (settings, activity logs, job history, the backup list, schedules, rollback points, staging records and migration keys).', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Backup files are never deleted this way. Remove the backup folder (Settings → Backup) yourself if you no longer need them.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Wrensafe\'s own tables', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[include_wpbp_internal_tables]" value="1" <?php checked( ! empty( $settings['include_wpbp_internal_tables'] ) ); ?> />
							<?php esc_html_e( 'Include Wrensafe\'s own database tables (jobs, logs, the backup list) in backups.', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. They belong to this installation and are never restored, so this only matters for support.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-chunk-size-bytes"><?php esc_html_e( 'Read/write block size (bytes)', 'wrensafe' ); ?></label></th>
					<td>
						<input type="number" id="wpbp-chunk-size-bytes" min="0" step="262144" class="regular-text" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[chunk_size_bytes]" value="<?php echo esc_attr( $settings['chunk_size_bytes'] ?? 0 ); ?>" />
						<p class="description"><?php esc_html_e( 'How much data is read or written at a time while encrypting and extracting backups. Leave at 0 to let Wrensafe choose from this server\'s memory limit.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);

		/**
		 * Prints extra cards at the end of Settings → Advanced
		 * (for example the Pro add-on's Emergency Restore Kit).
		 */
		do_action( 'wpbp_settings_advanced' );
	}

	private function render_tab_support_us(): void {
		SupportUs::render_card();
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Roadmap', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'Follow the Wrensafe roadmap, vote for features and suggest new ones.', 'wrensafe' ); ?></p>
			<?php $community_url = SupportUs::community_url(); ?>
			<?php if ( '' !== $community_url ) : ?>
				<p><a class="button" href="<?php echo esc_url( $community_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View roadmap', 'wrensafe' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tab_backup(): void {
		\WPBP\Admin\Onboarding::page_viewed( 'folder' );

		$s        = (array) get_option( 'wpbp_backup_before_updates', array() );
		$settings = Settings::get_all();
		$name     = Settings::OPTION_NAME;
		$type     = 'full' === ( $s['backup_type'] ?? '' ) ? 'full' : 'database'; // Not saved yet on a new or restored site.
		$default  = \WPBP\Utils\FileSystem::default_storage_dir();
		$chosen   = (string) ( $settings['backup_dir'] ?? '' );
		$in_use   = \WPBP\Utils\FileSystem::storage_dir();
		$outside  = trailingslashit( wp_normalize_path( dirname( Paths::root() ) ) ) . 'wrensafe-backups';
		$parent   = dirname( $outside );
		$can_out  = @is_dir( $parent ) && @is_writable( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- open_basedir can forbid even looking.
		?>
		<form method="post" action="options.php" class="wpbp-card">
			<?php settings_fields( Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_tab]" value="backup" />

			<h2><?php esc_html_e( 'Backup folder', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Where Wrensafe keeps backups on this server. Existing backups move to the new folder when you save.', 'wrensafe' ); ?></p>

			<?php if ( '' !== $chosen && untrailingslashit( $in_use ) !== untrailingslashit( wp_normalize_path( $chosen ) ) ) : ?>
				<div class="notice notice-warning inline wpbp-notice"><p>
					<?php
					/* translators: %s: folder path */
					echo esc_html( sprintf( __( 'The folder %s isn’t available right now, so backups are being saved in the default folder. Check that the folder exists and WordPress can write to it.', 'wrensafe' ), $chosen ) );
					?>
				</p></div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpbp-backup-dir"><?php esc_html_e( 'Folder', 'wrensafe' ); ?></label></th>
					<td>
						<div class="wpbp-field-row">
							<input type="text" id="wpbp-backup-dir" class="code" name="<?php echo esc_attr( $name ); ?>[backup_dir]" value="<?php echo esc_attr( untrailingslashit( '' !== $chosen ? wp_normalize_path( $chosen ) : $default ) ); ?>" spellcheck="false" autocomplete="off" />
							<button type="button" class="button" id="wpbp-backup-dir-check"><?php esc_html_e( 'Check folder', 'wrensafe' ); ?></button>
						</div>
						<p class="wpbp-backup-dir-presets">
							<span><?php esc_html_e( 'Quick choice:', 'wrensafe' ); ?></span>
							<button type="button" class="button-link" data-wpbp-dir="<?php echo esc_attr( untrailingslashit( $default ) ); ?>">
							<?php
							/* translators: %s: name of the site's content folder, usually wp-content */
							echo esc_html( sprintf( __( 'Default (inside %s)', 'wrensafe' ), basename( Paths::content_dir() ) ) );
							?>
							</button>
							<?php if ( $can_out ) : ?>
								<button type="button" class="button-link" data-wpbp-dir="<?php echo esc_attr( $outside ); ?>"><?php esc_html_e( 'Outside the website folder (safest)', 'wrensafe' ); ?></button>
							<?php endif; ?>
						</p>
						<p class="description wpbp-backup-dir-status" id="wpbp-backup-dir-status" aria-live="polite"></p>
						<p class="description"><?php esc_html_e( 'Enter the full path of a folder on this server. A folder outside the website folder can’t be opened from the web at all.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Back up before updates', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Wrensafe makes a backup just before WordPress, a plugin or a theme updates, so you can roll back if an update breaks something.', 'wrensafe' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic updates', 'wrensafe' ); ?></th>
					<td>
						<fieldset class="wpbp-checkbox-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'Back up before these automatic updates', 'wrensafe' ); ?></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bbu][auto_core]" value="1" <?php checked( ! empty( $s['auto_core'] ) ); ?> /> <?php esc_html_e( 'WordPress core', 'wrensafe' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bbu][auto_plugins]" value="1" <?php checked( ! empty( $s['auto_plugins'] ) ); ?> /> <?php esc_html_e( 'Plugins', 'wrensafe' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bbu][auto_themes]" value="1" <?php checked( ! empty( $s['auto_themes'] ) ); ?> /> <?php esc_html_e( 'Themes', 'wrensafe' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Updates you start', 'wrensafe' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bbu][manual_updates]" value="1" <?php checked( ! empty( $s['manual_updates'] ) ); ?> /> <?php esc_html_e( 'Plugin, theme and WordPress updates you start from the dashboard', 'wrensafe' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-bbu-type"><?php esc_html_e( 'What to back up', 'wrensafe' ); ?></label></th>
					<td>
						<select id="wpbp-bbu-type" name="<?php echo esc_attr( $name ); ?>[bbu][backup_type]">
							<option value="database" <?php selected( $type, 'database' ); ?>><?php esc_html_e( 'Database only (recommended)', 'wrensafe' ); ?></option>
							<option value="full" <?php selected( $type, 'full' ); ?>><?php esc_html_e( 'Full website (database and files)', 'wrensafe' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'The backup runs before the update starts. A database backup takes seconds; a full backup of a large site can make the update take several minutes.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Exclusions', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Files left out of every new backup.', 'wrensafe' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Other backup files', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude_backup_archives]" value="1" <?php checked( ! empty( $settings['exclude_backup_archives'] ) ); ?> />
							<?php esc_html_e( 'Leave out backups made by other plugins and by Wrensafe (ai1wm-backups, updraft, wpvividbackups, .wpress and .wbp files, …)', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Recommended. Old backups inside a new backup make it hundreds of megabytes larger and can make it fail.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache folders', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude_cache_folders]" value="1" <?php checked( ! empty( $settings['exclude_cache_folders'] ) ); ?> />
							<?php
							/* translators: %s: the cache folder in the site's content folder, usually wp-content/cache */
							echo esc_html( sprintf( __( 'Leave out cache folders (%s, wflogs). They are rebuilt automatically.', 'wrensafe' ), basename( Paths::content_dir() ) . '/cache' ) );
							?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-custom-excluded-paths"><?php esc_html_e( 'Folders and files', 'wrensafe' ); ?></label></th>
					<td>
						<textarea id="wpbp-custom-excluded-paths" class="large-text" rows="3"
							name="<?php echo esc_attr( $name ); ?>[custom_excluded_paths]"
							placeholder="<?php echo esc_attr( ltrim( Paths::relative_to_root( Paths::uploads_dir() ) . '/large-videos', '/' ) ); ?>"><?php echo esc_textarea( (string) ( $settings['custom_excluded_paths'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One path per line, relative to the site folder. Everything under these paths is left out.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-custom-excluded-extensions"><?php esc_html_e( 'File types', 'wrensafe' ); ?></label></th>
					<td>
						<input type="text" id="wpbp-custom-excluded-extensions" class="regular-text"
							name="<?php echo esc_attr( $name ); ?>[custom_excluded_extensions]"
							value="<?php echo esc_attr( (string) ( $settings['custom_excluded_extensions'] ?? '' ) ); ?>"
							placeholder="mp4, mov" />
						<p class="description"><?php esc_html_e( 'File extensions to leave out, separated by commas.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_tab_direct_migration(): void {
		$s = Settings::get_all();
		?>
		<?php if ( ! is_ssl() ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'This site doesn\'t use HTTPS, so a direct migration would travel unencrypted. Turn on HTTPS before sharing a migration key.', 'wrensafe' ); ?></p>
		</div>
		<?php endif; ?>
		<?php
		self::render_settings_form(
			'direct-migration',
			function () use ( $s ) {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require HTTPS', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[migration_require_https]" value="1" <?php checked( ! empty( $s['migration_require_https'] ) ); ?>>
							<?php esc_html_e( 'Only send or receive a direct migration over HTTPS.', 'wrensafe' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-migration-chunk-size"><?php esc_html_e( 'Transfer chunk size (MB)', 'wrensafe' ); ?></label></th>
					<td>
						<input type="number" id="wpbp-migration-chunk-size" class="small-text"
							name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[migration_chunk_size_mb]"
							value="<?php echo esc_attr( $s['migration_chunk_size_mb'] ?? '2' ); ?>" min="1" max="25" step="1">
						<p class="description"><?php esc_html_e( 'How much of the backup is sent per request. Lower it if transfers fail on a slow or strict host.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);
	}

	private function render_tab_woocommerce(): void {
		$s = Settings::get_all();
		self::render_settings_form(
			'woocommerce',
			function () use ( $s ) {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Safe Mode on by default', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[woo_safe_mode_default]" value="1" <?php checked( ! empty( $s['woo_safe_mode_default'] ) ); ?>>
							<?php esc_html_e( 'Tick WooCommerce Safe Mode automatically when restoring a backup on this store.', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Safe Mode keeps this store\'s live orders, customers and stock while the rest of the site is restored. You can still switch it off on the review screen.', 'wrensafe' ); ?></p>
						<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
							<p class="description"><?php esc_html_e( 'WooCommerce is not currently active on this site.', 'wrensafe' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
			}
		);
	}
}
