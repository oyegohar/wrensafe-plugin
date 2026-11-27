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
use WPBP\Core\SettingsTransfer;
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
			'remote'      => CapabilityManager::MANAGE_CLOUD,
			'ai'          => CapabilityManager::MANAGE_SECURITY,
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
			'updates'          => __( 'Updates', 'wrensafe' ),
			'remote'           => __( 'Remote storage', 'wrensafe' ),
			'direct-migration' => __( 'Direct Migration', 'wrensafe' ),
			'woocommerce'      => __( 'WooCommerce Safe Mode', 'wrensafe' ),
			'notifications'    => __( 'Notifications', 'wrensafe' ),
			'security'         => __( 'Security', 'wrensafe' ),
			'ai'               => __( 'AI assistants', 'wrensafe' ),
			'support-us'       => __( 'Support Us', 'wrensafe' ),
			'tools'            => __( 'Import / Export', 'wrensafe' ),
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
			__( 'Backups', 'wrensafe' )           => array( 'general', 'backup', 'updates', 'remote', 'cloud' ),
			__( 'Migration', 'wrensafe' )         => array( 'direct-migration', 'woocommerce' ),
			__( 'Reporting', 'wrensafe' )         => array( 'notifications', 'reports' ),
			__( 'Access & security', 'wrensafe' ) => array( 'security', 'team-access', 'ai' ),
			__( 'More', 'wrensafe' )              => array( 'support-us', 'tools', 'advanced' ),
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
							<a class="wpbp-settings-nav-link" href="<?php echo esc_url( self::page_url( self::MENU_SLUG, array( 'tab' => $slug ) ) ); ?>"<?php echo $slug === $current_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tabs[ $slug ] ); ?><?php echo 'ai' === $slug ? wp_kses( \WPBP\Admin\NewFeatures::badge( 'ai' ), \WPBP\Admin\Kses::allowed() ) : ''; ?></a>
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

	/** AI assistants over MCP (WPBP\Mcp\Abilities): the switches, what is needed, and how to connect each assistant. */
	private function render_tab_ai(): void {
		\WPBP\Admin\McpSettings::render();
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

	/** Export, import and reset the settings (see WPBP\Core\SettingsTransfer). */
	private function render_tab_tools(): void {
		$undo   = SettingsTransfer::undo_info();
		$resets = SettingsTransfer::reset_count();
		$text   = array(
			'exported'   => __( 'Settings exported.', 'wrensafe' ),
			'tooBig'     => __( 'This file is too large to be a Wrensafe settings file.', 'wrensafe' ),
			'notFile'    => __( 'This isn’t a Wrensafe settings file.', 'wrensafe' ),
			'reading'    => __( 'Reading the file…', 'wrensafe' ),
			'same'       => __( 'This file matches your current settings. There is nothing to import.', 'wrensafe' ),
			/* translators: 1: site the file came from, 2: date, 3: Wrensafe version */
			'from'       => __( 'From %1$s on %2$s, Wrensafe %3$s', 'wrensafe' ),
			'review'     => __( 'Review what will change', 'wrensafe' ),
			'reviewText' => __( 'Nothing is saved until you import. Untick anything you want to keep as it is.', 'wrensafe' ),
			'changeOne'  => __( '1 change', 'wrensafe' ),
			/* translators: %d: number of changes */
			'changes'    => __( '%d changes', 'wrensafe' ),
			'importOne'  => __( 'Import 1 setting', 'wrensafe' ),
			/* translators: %d: number of settings */
			'importMany' => __( 'Import %d settings', 'wrensafe' ),
			'cancel'     => __( 'Cancel', 'wrensafe' ),
			'imported'   => __( 'Settings imported. You can undo this below.', 'wrensafe' ),
			'wasReset'   => __( 'Settings reset to their defaults. You can undo this below.', 'wrensafe' ),
			'undone'     => __( 'Your previous settings are back.', 'wrensafe' ),
			'resetTitle' => __( 'Reset Wrensafe’s settings?', 'wrensafe' ),
			'resetText'  => sprintf(
				/* translators: %d: number of settings */
				_n( '%d setting goes back to its default. Your backups, schedules, backup folder, off-site storage and notification email stay as they are. You can undo this afterwards.', '%d settings go back to their defaults. Your backups, schedules, backup folder, off-site storage and notification email stay as they are. You can undo this afterwards.', $resets, 'wrensafe' ),
				$resets
			),
			'resetGo'    => __( 'Reset settings', 'wrensafe' ),
		);
		?>
		<div id="wpbp-settings-tools" data-text="<?php echo esc_attr( (string) wp_json_encode( $text ) ); ?>">
			<?php if ( $undo ) : ?>
				<div class="wpbp-callout is-info wpbp-st-undo">
					<span class="dashicons dashicons-undo" aria-hidden="true"></span>
					<div class="wpbp-callout-body">
						<p class="wpbp-callout-title"><?php echo esc_html( 'reset' === $undo['what'] ? __( 'Settings were reset', 'wrensafe' ) : __( 'Settings were imported', 'wrensafe' ) ); ?></p>
						<p>
							<?php
							/* translators: %s: date and time */
							echo esc_html( sprintf( __( 'On %s. You can put back the settings you had before.', 'wrensafe' ), $undo['when'] ) );
							?>
						</p>
					</div>
					<button type="button" class="button" id="wpbp-st-undo"><?php esc_html_e( 'Undo', 'wrensafe' ); ?></button>
				</div>
			<?php endif; ?>

			<div class="wpbp-card">
				<h2><?php esc_html_e( 'Export settings', 'wrensafe' ); ?></h2>
				<p><?php esc_html_e( 'Save Wrensafe’s settings to a file, to use them on another site or keep a copy.', 'wrensafe' ); ?></p>
				<div class="wpbp-st-facts">
					<div class="wpbp-st-fact">
						<strong><?php esc_html_e( 'Included', 'wrensafe' ); ?></strong>
						<?php esc_html_e( 'Backup exclusions, Safe Updates, notification choices, migration and advanced options.', 'wrensafe' ); ?>
					</div>
					<div class="wpbp-st-fact">
						<strong><?php esc_html_e( 'Never included', 'wrensafe' ); ?></strong>
						<?php esc_html_e( 'Passwords and keys, off-site storage, the backup folder, your email address and schedules.', 'wrensafe' ); ?>
					</div>
				</div>
				<button type="button" class="button button-primary" id="wpbp-st-export"><?php esc_html_e( 'Export settings', 'wrensafe' ); ?></button>
			</div>

			<div class="wpbp-card">
				<h2><?php esc_html_e( 'Import settings', 'wrensafe' ); ?></h2>
				<p><?php esc_html_e( 'Use a settings file exported from Wrensafe. You see every change before anything is saved.', 'wrensafe' ); ?></p>
				<div class="wpbp-dropzone wpbp-st-drop" id="wpbp-st-drop">
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<p><?php esc_html_e( 'Drag a settings file here, or', 'wrensafe' ); ?></p>
					<button type="button" class="button" id="wpbp-st-choose"><?php esc_html_e( 'Choose file', 'wrensafe' ); ?></button>
					<input type="file" id="wpbp-st-file" accept=".json,application/json" aria-label="<?php esc_attr_e( 'Settings file', 'wrensafe' ); ?>" />
				</div>
				<p class="wpbp-st-error" id="wpbp-st-error" role="alert" hidden></p>
				<div class="wpbp-st-preview" id="wpbp-st-preview" aria-live="polite" hidden></div>
			</div>

			<div class="wpbp-card">
				<h2><?php esc_html_e( 'Reset settings', 'wrensafe' ); ?></h2>
				<p><?php esc_html_e( 'Put Wrensafe’s settings back to their defaults. You can undo this afterwards.', 'wrensafe' ); ?></p>
				<div class="wpbp-st-facts">
					<div class="wpbp-st-fact">
						<strong><?php esc_html_e( 'Goes back to default', 'wrensafe' ); ?></strong>
						<?php esc_html_e( 'Backup exclusions, Safe Updates, notification choices, migration, security and advanced options.', 'wrensafe' ); ?>
					</div>
					<div class="wpbp-st-fact">
						<strong><?php esc_html_e( 'Always kept', 'wrensafe' ); ?></strong>
						<?php esc_html_e( 'Your backups, schedules, backup folder, off-site storage and notification email.', 'wrensafe' ); ?>
					</div>
				</div>
				<button type="button" class="button button-link-delete" id="wpbp-st-reset"<?php disabled( 0, $resets ); ?>><?php esc_html_e( 'Reset to defaults', 'wrensafe' ); ?></button>
				<?php if ( 0 === $resets ) : ?>
					<span class="description wpbp-st-at-default"><?php esc_html_e( 'Your settings are already at their defaults.', 'wrensafe' ); ?></span>
				<?php endif; ?>
			</div>
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
							<?php esc_html_e( 'Delete Wrensafe\'s data when the plugin is deleted: settings, activity logs, job history, the backup list, schedules, rollback points and migration keys, the plugin copies Safe Updates saved, and the staging sites Wrensafe made (their folders and database tables).', 'wrensafe' ); ?>
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
		SupportUs::render_tip();
		SupportUs::render_video();

		// The Roadmap box only once there is a roadmap page to link to.
		$community_url = SupportUs::community_url();

		if ( '' === $community_url ) {
			return;
		}
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Roadmap', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'Follow the Wrensafe roadmap, vote for features and suggest new ones.', 'wrensafe' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $community_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View roadmap', 'wrensafe' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Checklist of the site's extra tables (not WordPress's own), with their
	 * size; checked tables are left out of backups.
	 *
	 * @param string[] $excluded
	 */
	private function render_table_exclusions( array $excluded ): void {
		global $wpdb;

		$tables = Settings::excludable_tables();

		if ( ! $tables ) {
			echo '<p class="description">' . esc_html__( 'This site has only WordPress’s own tables, which are always backed up.', 'wrensafe' ) . '</p>';
			return;
		}

		$sizes = array();
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table sizes for the settings screen; no WordPress API.

		foreach ( (array) $rows as $row ) {
			$sizes[ (string) $row['Name'] ] = (int) $row['Data_length'] + (int) $row['Index_length'];
		}

		$name = Settings::OPTION_NAME;
		?>
		<fieldset class="wpbp-checkbox-list wpbp-table-checklist">
			<legend class="screen-reader-text"><?php esc_html_e( 'Database tables to leave out', 'wrensafe' ); ?></legend>
			<?php foreach ( $tables as $table ) : ?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[excluded_tables][]" value="<?php echo esc_attr( $table ); ?>" <?php checked( in_array( $table, $excluded, true ) ); ?> />
					<code><?php echo esc_html( $wpdb->prefix . $table ); ?></code>
					<span class="wpbp-muted"><?php echo esc_html( size_format( $sizes[ $wpdb->prefix . $table ] ?? 0 ) ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Checked tables are left out, for example large log or statistics tables. WordPress’s own tables (posts, users, options…) are always backed up. When you restore, a left-out table keeps the version already on the site.', 'wrensafe' ); ?></p>
		<?php
	}

	/**
	 * Settings → Remote storage: where backups are copied after they're made.
	 */
	private function render_tab_remote(): void {
		$r     = \WPBP\Remote\RemoteStorage::get();
		$types = \WPBP\Remote\RemoteStorage::types();
		$base  = Settings::OPTION_NAME . '[remote]';
		$saved = __( 'Saved. Leave empty to keep it, unless you change the server or account.', 'wrensafe' );

		$field = static function ( string $kind, string $key, string $label, string $type = 'text', string $help = '', string $placeholder = '' ) use ( $r, $base, $saved ): void {
			$id     = 'wpbp-remote-' . $kind . '-' . str_replace( '_', '-', $key );
			$secret = 'password' === $type;
			$value  = $secret ? '' : (string) ( $r[ $kind ][ $key ] ?? '' );
			$hint   = $secret && \WPBP\Remote\RemoteStorage::has_secret( $kind, $key ) ? $saved : $placeholder;
			?>
			<tr data-wpbp-remote="<?php echo esc_attr( $kind ); ?>">
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" class="<?php echo 'number' === $type ? 'small-text' : 'regular-text'; ?>" name="<?php echo esc_attr( $base . '[' . $kind . '][' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $hint ); ?>" autocomplete="<?php echo $secret ? 'new-password' : 'off'; ?>" />
					<?php if ( '' !== $help ) : ?>
						<p class="description"><?php echo esc_html( $help ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		};

		$checkbox = static function ( string $kind, string $key, string $label, string $help = '' ) use ( $r, $base ): void {
			?>
			<tr data-wpbp-remote="<?php echo esc_attr( $kind ); ?>">
				<th scope="row"></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $base . '[' . $kind . '][' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $r[ $kind ][ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php if ( '' !== $help ) : ?>
						<p class="description"><?php echo esc_html( $help ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		};
		?>
		<form method="post" action="options.php" class="wpbp-card" id="wpbp-remote-form">
			<?php settings_fields( Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[_tab]" value="remote" />

			<h2><?php esc_html_e( 'Off-site copies', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Keep a copy of your backups on another server, so you still have them if this server fails or the site is hacked. Each backup is copied right after it’s made, in small parts, so large backups work on any host.', 'wrensafe' ); ?></p>
			<?php if ( \WPBP\Remote\RemoteStorage::has_retired_sftp() ) : ?>
				<div class="notice notice-warning inline wpbp-notice"><p><?php esc_html_e( 'Backups were copied to an SFTP server. Wrensafe no longer includes SFTP, so no copies are made there now; your backups stay on this server. Choose FTP / FTPS or S3-compatible storage below to keep off-site copies.', 'wrensafe' ); ?></p></div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpbp-remote-type"><?php esc_html_e( 'Copy backups to', 'wrensafe' ); ?></label></th>
					<td>
						<select id="wpbp-remote-type" name="<?php echo esc_attr( $base ); ?>[type]">
							<option value="" <?php selected( $r['type'], '' ); ?>><?php esc_html_e( 'Nowhere (keep backups on this server only)', 'wrensafe' ); ?></option>
							<?php foreach ( $types as $kind => $label ) : ?>
								<?php $reason = \WPBP\Remote\RemoteStorage::unavailable_reason( $kind ); ?>
								<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $r['type'], $kind ); ?> <?php disabled( '' !== $reason && $r['type'] !== $kind ); ?> data-unavailable="<?php echo esc_attr( $reason ); ?>">
									<?php echo esc_html( '' !== $reason ? sprintf( /* translators: %s: storage name */ __( '%s (not available on this server)', 'wrensafe' ), $label ) : $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php foreach ( array_keys( $types ) as $kind ) : ?>
							<?php $reason = \WPBP\Remote\RemoteStorage::unavailable_reason( $kind ); ?>
							<?php if ( '' !== $reason ) : ?>
								<p class="description"><?php echo esc_html( $types[ $kind ] . ': ' . $reason ); ?></p>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
				</tr>

				<?php
				// The fields of every type (built-in or from an add-on), in
				// rows that admin.js shows for the chosen type.
				foreach ( \WPBP\Remote\RemoteStorage::registry() as $kind => $definition ) {
					foreach ( $definition['fields'] as $key => $spec ) {
						if ( 'checkbox' === $spec['type'] ) {
							$checkbox( $kind, $key, $spec['label'], $spec['help'] );
						} else {
							$field( $kind, $key, $spec['label'], $spec['type'], $spec['help'], $spec['placeholder'] );
						}
					}
				}
				?>

				<tr data-wpbp-remote="any">
					<th scope="row"><?php esc_html_e( 'Which backups', 'wrensafe' ); ?></th>
					<td>
						<fieldset class="wpbp-checkbox-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'Which backups are copied', 'wrensafe' ); ?></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[copy_automatic]" value="1" <?php checked( ! empty( $r['copy_automatic'] ) ); ?> /> <?php esc_html_e( 'Automatic backups (schedules and backups before updates)', 'wrensafe' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[copy_manual]" value="1" <?php checked( ! empty( $r['copy_manual'] ) ); ?> /> <?php esc_html_e( 'Backups you make yourself', 'wrensafe' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr data-wpbp-remote="any">
					<th scope="row"><label for="wpbp-remote-keep"><?php esc_html_e( 'Keep there', 'wrensafe' ); ?></label></th>
					<td>
						<?php
						printf(
							/* translators: %s: number input */
							esc_html__( 'The newest %s copies', 'wrensafe' ),
							'<input type="number" id="wpbp-remote-keep" class="small-text" min="0" step="1" name="' . esc_attr( $base ) . '[keep]" value="' . esc_attr( (string) (int) $r['keep'] ) . '" />'
						);
						?>
						<p class="description"><?php esc_html_e( 'Older copies in the folder are deleted after each upload. 0 keeps them all.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr data-wpbp-remote="any">
					<th scope="row"><?php esc_html_e( 'Connection', 'wrensafe' ); ?></th>
					<td>
						<button type="button" class="button" id="wpbp-remote-test"><?php esc_html_e( 'Test connection', 'wrensafe' ); ?></button>
						<span class="wpbp-inline-status" id="wpbp-remote-test-result" role="status" aria-live="polite"></span>
						<p class="description"><?php esc_html_e( 'Writes a small test file there and removes it. Uses what’s in the form, so you can test before saving.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="wpbp-limit-note"><span><?php esc_html_e( 'Passwords and keys are stored encrypted with this site’s own security keys. They can’t be read on another site, so after moving the site, enter them again here.', 'wrensafe' ); ?></span></p>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_tab_backup(): void {
		\WPBP\Admin\Onboarding::page_viewed( 'folder' );

		$settings = Settings::get_all();
		$name     = Settings::OPTION_NAME;
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
							<button type="button" class="button" data-wpbp-dir="<?php echo esc_attr( untrailingslashit( $default ) ); ?>">
							<?php
							/* translators: %s: name of the site's content folder, usually wp-content */
							echo esc_html( sprintf( __( 'Default (inside %s)', 'wrensafe' ), basename( Paths::content_dir() ) ) );
							?>
							</button>
							<?php if ( $can_out ) : ?>
								<button type="button" class="button" data-wpbp-dir="<?php echo esc_attr( $outside ); ?>"><?php esc_html_e( 'Outside the website folder (safest)', 'wrensafe' ); ?></button>
							<?php endif; ?>
						</p>
						<p class="description wpbp-backup-dir-status" id="wpbp-backup-dir-status" aria-live="polite"></p>
						<p class="description"><?php esc_html_e( 'Enter the full path of a folder on this server. A folder outside the website folder can’t be opened from the web at all.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="description wpbp-settings-pointer">
				<?php esc_html_e( 'Backups before updates are set under Updates.', 'wrensafe' ); ?>
				<a href="<?php echo esc_url( self::page_url( self::MENU_SLUG, array( 'tab' => 'updates' ) ) ); ?>"><?php esc_html_e( 'Open Updates settings', 'wrensafe' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Checks', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The same check as Verify in the Backup Browser: the archive, its database files and every file’s checksum.', 'wrensafe' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'After each backup', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[verify_after_backup]" value="1" <?php checked( ! empty( $settings['verify_after_backup'] ) ); ?> />
							<?php esc_html_e( 'Check each backup right after it’s made', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A backup that fails the check is reported (by email too, if failure emails are on) and isn’t copied to remote storage. Takes a little longer for large sites.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Exclusions', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Files and database tables left out of every new backup.', 'wrensafe' ); ?></p>

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
				<tr>
					<th scope="row"><label for="wpbp-exclude-larger-than"><?php esc_html_e( 'Large files', 'wrensafe' ); ?></label></th>
					<td>
						<?php
						printf(
							/* translators: %s: number input for megabytes */
							esc_html__( 'Leave out files larger than %s MB', 'wrensafe' ),
							'<input type="number" id="wpbp-exclude-larger-than" class="small-text" min="0" step="1" name="' . esc_attr( $name ) . '[exclude_larger_than_mb]" value="' . esc_attr( (string) (int) ( $settings['exclude_larger_than_mb'] ?? 0 ) ) . '" />'
						);
						?>
						<p class="description"><?php esc_html_e( '0 keeps every file. Plugin and theme files are always backed up, whatever their size.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database tables', 'wrensafe' ); ?></th>
					<td>
						<?php $this->render_table_exclusions( (array) ( $settings['excluded_tables'] ?? array() ) ); ?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_tab_updates(): void {
		$s        = (array) get_option( 'wpbp_backup_before_updates', array() );
		$settings = Settings::get_all();
		$name     = Settings::OPTION_NAME;
		$type     = 'full' === ( $s['backup_type'] ?? '' ) ? 'full' : 'database'; // Not saved yet on a new or restored site.
		$modes    = \WPBP\Updates\SafeUpdates::modes();
		$mode     = \WPBP\Updates\SafeUpdates::default_mode();
		$order    = \WPBP\Updates\SafeUpdates::default_order();
		$blocked  = \WPBP\Updates\SafeUpdates::blocked_reason();
		?>
		<form method="post" action="options.php" class="wpbp-card">
			<?php settings_fields( Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_tab]" value="updates" />

			<h2><?php esc_html_e( 'Safe Updates', 'wrensafe' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When you update a plugin on the Plugins or Updates screen, Wrensafe asks how. Safe Update saves a copy of the plugin, checks your site after the update and puts the previous version back if the site shows an error.', 'wrensafe' ); ?>
				<?php if ( '' === $blocked && current_user_can( 'update_plugins' ) ) : ?>
					<a href="<?php echo esc_url( self::page_url( \WPBP\Updates\SafeUpdates::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open Safe Updates', 'wrensafe' ); ?></a>
				<?php endif; ?>
			</p>

			<?php if ( '' !== $blocked ) : ?>
				<div class="notice notice-warning inline wpbp-notice"><p><?php echo esc_html( $blocked ); ?> <?php esc_html_e( 'The Safe Updates page isn’t available until then.', 'wrensafe' ); ?></p></div>
			<?php elseif ( ! current_user_can( 'update_plugins' ) ) : ?>
				<div class="notice notice-info inline wpbp-notice"><p><?php esc_html_e( 'Your account can’t update plugins, so the Safe Updates page isn’t available to you.', 'wrensafe' ); ?></p></div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Update window', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[safe_updates_prompt]" value="1" <?php checked( ! empty( $settings['safe_updates_prompt'] ) ); ?> />
							<?php esc_html_e( 'Ask how to update when I update a plugin', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When this is off, the Plugins and Updates screens update the usual WordPress way. Safe Updates stay available under Wrensafe → Safe Updates.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default choice', 'wrensafe' ); ?></th>
					<td>
						<fieldset class="wpbp-checkbox-list wpbp-radio-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'The way to update chosen by default', 'wrensafe' ); ?></legend>
							<?php foreach ( $modes as $key => $item ) : ?>
								<label>
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[safe_updates_mode]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?> />
									<span><strong><?php echo esc_html( $item['label'] ); ?></strong> &mdash; <?php echo esc_html( $item['description'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Several plugins', 'wrensafe' ); ?></th>
					<td>
						<fieldset class="wpbp-checkbox-list wpbp-radio-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'How several plugins are updated', 'wrensafe' ); ?></legend>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[safe_updates_order]" value="one_by_one" <?php checked( $order, 'one_by_one' ); ?> />
								<span><strong><?php esc_html_e( 'One by one', 'wrensafe' ); ?></strong> &mdash; <?php esc_html_e( 'Each plugin is updated and checked before the next one. Safest.', 'wrensafe' ); ?></span>
							</label>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[safe_updates_order]" value="all_at_once" <?php checked( $order, 'all_at_once' ); ?> />
								<span><strong><?php esc_html_e( 'All at once', 'wrensafe' ); ?></strong> &mdash; <?php esc_html_e( 'Every plugin is updated, then the site is checked once. Faster.', 'wrensafe' ); ?></span>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[safe_updates_clear_cache]" value="1" <?php checked( ! empty( $settings['safe_updates_clear_cache'] ) ); ?> />
							<?php esc_html_e( 'Clear the cache after updating', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Empties the page cache of your caching plugin (WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache and others) and WordPress’s object cache. You can change it for each update.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-su-keep-days"><?php esc_html_e( 'Saved copies', 'wrensafe' ); ?></label></th>
					<td>
						<?php
						printf(
							/* translators: %s: number input for days */
							esc_html__( 'Keep the copies saved before updates for %s days', 'wrensafe' ),
							'<input type="number" id="wpbp-su-keep-days" class="small-text" min="1" max="90" step="1" name="' . esc_attr( $name ) . '[safe_updates_keep_days]" value="' . esc_attr( (string) (int) ( $settings['safe_updates_keep_days'] ?? 14 ) ) . '" />'
						);
						?>
						<p class="description"><?php esc_html_e( 'Until then, each update can be rolled back from the Safe Updates page.', 'wrensafe' ); ?></p>
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
						<p class="description"><?php esc_html_e( 'A Safe Updates run makes one backup before its first plugin.', 'wrensafe' ); ?></p>
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
						<p class="description"><?php esc_html_e( 'Both sites check this: the sending site for the address it sends to, the receiving site for the connection it gets. For local or test sites without a trusted certificate, turn it off on both sites.', 'wrensafe' ); ?></p>
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
