<?php
/**
 * Registers the Wrensafe admin menu and its submenu pages.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\BackupBrowserPage;
use WPBP\Admin\Pages\AbstractPage;
use WPBP\Admin\Pages\BackupPage;
use WPBP\Admin\Pages\ChangelogPage;
use WPBP\Admin\Pages\DashboardPage;
use WPBP\Admin\Pages\DirectMigrationPage;
use WPBP\Admin\Pages\ImportRestorePage;
use WPBP\Admin\Pages\LogsPage;
use WPBP\Admin\Pages\MigrationAssistantPage;
use WPBP\Admin\Pages\RollbackPage;
use WPBP\Admin\Pages\SafeUpdatesPage;
use WPBP\Admin\Pages\SchedulePage;
use WPBP\Admin\Pages\FixLinksPage;
use WPBP\Admin\Pages\SearchReplacePage;
use WPBP\Admin\Pages\SettingsPage;
use WPBP\Admin\Pages\StagingPage;
use WPBP\Admin\Pages\SystemInfoPage;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class AdminMenu {

	const TOP_SLUG = 'wrensafe';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_badge_styles' ) );
	}

	/**
	 * Tiny stylesheet for the admin menu: the Wrensafe icon and the Backup
	 * Browser submenu badge. The menu is on every screen of a site's admin
	 * for the users who can open Wrensafe, so these rules are added there,
	 * for those users only (never in the Network Admin, which has no Wrensafe
	 * menu); the plugin's main stylesheet only loads on its own pages. Every
	 * rule is scoped to Wrensafe's own menu item. Printed via the Styles API
	 * on a file-less handle instead of a raw <style> tag.
	 *
	 * The icon is the colour mark (Royal Cobalt over Ice Blue). When the item
	 * is hovered, open or current, WordPress gives it the colour scheme's
	 * highlight (often blue), so the mark then sits on a small white tile to
	 * stay readable.
	 */
	public function enqueue_badge_styles(): void {
		if ( is_network_admin() || is_user_admin() || ! $this->user_sees_menu() ) {
			return;
		}

		$mark = esc_url( WPBP_PLUGIN_URL . 'assets/admin/images/wrensafe-mark.svg' );
		$item = '#adminmenu #toplevel_page_' . self::TOP_SLUG;

		wp_register_style( 'wpbp-admin-menu', false, array(), WPBP_VERSION );
		wp_enqueue_style( 'wpbp-admin-menu' );
		wp_add_inline_style(
			'wpbp-admin-menu',
			$item . ' div.wp-menu-image::before{content:"";width:20px;height:20px;margin-top:7px;padding:0;border-radius:5px;background:url("' . $mark . '") center/18px 18px no-repeat;transition:background-color .15s ease-in-out,background-size .15s ease-in-out}'
			. $item . ':is(.current,.wp-has-current-submenu,.opensub,:hover) div.wp-menu-image::before,' . $item . ' > a:focus div.wp-menu-image::before{background-color:#fff;background-size:14px 14px}'
			. $item . ' .wp-submenu a:has(> .wpbp-menu-badge){white-space:nowrap}'
			. $item . ' .wpbp-menu-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;margin-inline-start:6px;border-radius:999px;background:#2457f5;color:#fff;font-size:11px;font-weight:600;line-height:18px;vertical-align:middle;box-sizing:border-box}'
			. $item . ' .wpbp-menu-badge.is-alert{background:#d63638}'
			. $item . ' .wp-submenu a[href$="page=' . ChangelogPage::SLUG . '"]{display:none}'
		);
	}

	/**
	 * Whether WordPress shows the Wrensafe menu to the current user: they can
	 * open at least one of its pages (with the capability WordPress checks
	 * for the menu item).
	 */
	private function user_sees_menu(): bool {
		foreach ( $this->pages() as $page ) {
			if ( current_user_can( $page[2] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map of submenu slug => [ menu title, page class, required capability ].
	 *
	 * The capability is passed straight to add_menu_page()/add_submenu_page()
	 * so WordPress itself hides menu items a Team Access user isn't granted,
	 * rather than every item being visible to anyone who can reach wp-admin.
	 */
	private function pages(): array {
		$pages = array(
			self::TOP_SLUG                 => array( __( 'Dashboard', 'wrensafe' ), DashboardPage::class, CapabilityManager::VIEW_DASHBOARD ),
			'wrensafe-backup'              => array( __( 'Create Backup', 'wrensafe' ), BackupPage::class, CapabilityManager::CREATE_BACKUPS ),
			'wrensafe-import-restore'      => array( __( 'Import / Restore', 'wrensafe' ), ImportRestorePage::class, CapabilityManager::RESTORE_BACKUPS ),
			'wrensafe-browser'             => array( __( 'Backup Browser', 'wrensafe' ), BackupBrowserPage::class, CapabilityManager::VIEW_BACKUPS ),
			'wrensafe-migration-assistant' => array( __( 'Migration Assistant', 'wrensafe' ), MigrationAssistantPage::class, CapabilityManager::RESTORE_BACKUPS ),
			'wrensafe-direct-migration'    => array( __( 'Direct Migration', 'wrensafe' ), DirectMigrationPage::class, CapabilityManager::MANAGE_DIRECT_MIGRATION ),
			'wrensafe-schedule'            => array( __( 'Scheduled Backups', 'wrensafe' ), SchedulePage::class, CapabilityManager::MANAGE_SCHEDULES ),
			'wrensafe-staging'             => array( __( 'Staging', 'wrensafe' ), StagingPage::class, CapabilityManager::MANAGE_STAGING ),
			'wrensafe-rollback'            => array( __( 'Rollback', 'wrensafe' ), RollbackPage::class, CapabilityManager::RESTORE_BACKUPS ),
			'wrensafe-safe-updates'        => array( __( 'Safe Updates', 'wrensafe' ), SafeUpdatesPage::class, 'update_plugins' ),
			'wrensafe-search-replace'      => array( __( 'Search & Replace', 'wrensafe' ), SearchReplacePage::class, CapabilityManager::RESTORE_BACKUPS ),
			FixLinksPage::SLUG             => array( __( 'Fix Links', 'wrensafe' ), FixLinksPage::class, CapabilityManager::RESTORE_BACKUPS ),
			'wrensafe-logs'                => array( __( 'Activity Logs', 'wrensafe' ), LogsPage::class, CapabilityManager::VIEW_LOGS ),
			'wrensafe-settings'            => array( __( 'Settings', 'wrensafe' ), SettingsPage::class, CapabilityManager::MANAGE_SETTINGS ),
			'wrensafe-system-info'         => array( __( 'System Info', 'wrensafe' ), SystemInfoPage::class, CapabilityManager::VIEW_DASHBOARD ),
		);

		// Opened from the link in the header, so it isn't listed in the menu (see enqueue_badge_styles()). Not with White Label.
		if ( AbstractPage::brand()['credit'] ) {
			$pages[ ChangelogPage::SLUG ] = array( __( 'Changelog', 'wrensafe' ), ChangelogPage::class, CapabilityManager::VIEW_DASHBOARD );
		}

		/**
		 * Filters the Wrensafe admin pages: slug => array( menu label,
		 * page class extending AbstractPage, capability ). Pages added by an
		 * add-on are inserted before Activity Logs.
		 *
		 * @param array<string,array{0:string,1:string,2:string}> $pages
		 */
		$extra = (array) apply_filters( 'wpbp_admin_pages', array() );

		if ( empty( $extra ) ) {
			return $pages;
		}

		$position = array_search( 'wrensafe-logs', array_keys( $pages ), true );
		$position = false === $position ? count( $pages ) : $position;

		return array_slice( $pages, 0, $position, true ) + $extra + array_slice( $pages, $position, null, true );
	}

	/** The capability a Wrensafe page needs ('' for a page that doesn't exist). */
	public static function capability_of( string $slug ): string {
		$pages = ( new self() )->pages();

		return (string) ( $pages[ $slug ][2] ?? '' );
	}

	public function register_menu(): void {
		$pages = $this->pages();

		add_menu_page(
			__( 'Wrensafe', 'wrensafe' ),
			__( 'Wrensafe', 'wrensafe' ),
			CapabilityManager::VIEW_DASHBOARD,
			self::TOP_SLUG,
			$this->page_callback( self::TOP_SLUG ),
			// The Wrensafe colour mark is drawn by CSS (see enqueue_badge_styles()).
			'none',
			80
		);

		// Numeric badge on the Backup Browser submenu: completed local backups
		// only (failed/corrupted records and cancelled jobs are excluded).
		// Counted only for users who see that item.
		$backup_count = current_user_can( CapabilityManager::VIEW_BACKUPS ) ? \WPBP\Core\Database\BackupsRepository::count_completed() : 0;

		foreach ( $pages as $slug => $page ) {
			// The Dashboard (TOP_SLUG) is already registered by add_menu_page()
			// above, and WordPress auto-adds it as the first submenu item.
			// Re-adding a submenu with the same slug would bind a SECOND render
			// callback to the same page hook, making the Dashboard render twice.
			if ( self::TOP_SLUG === $slug ) {
				continue;
			}

			list( $menu_title, , $capability ) = $page;
			$page_title                        = $menu_title;

			if ( 'wrensafe-browser' === $slug && $backup_count > 0 ) {
				// Inline count pill (Issue 09) — styled globally by
				// enqueue_badge_styles() so it renders correctly on every admin
				// screen, vertically centered next to the menu text.
				$menu_title .= sprintf(
					' <span class="wpbp-menu-badge" aria-label="%s">%d</span>',
					esc_attr(
						sprintf(
							/* translators: %d: number of completed backups */
							_n( '%d completed backup', '%d completed backups', $backup_count, 'wrensafe' ),
							$backup_count
						)
					),
					$backup_count
				);
			}

			// "Needs you" marks: updates waiting, links to fix, a failed restore or scheduled backup (red).
			$mark = current_user_can( $capability ) ? MenuBadges::for_page( $slug ) : null;

			if ( null !== $mark ) {
				$menu_title .= sprintf(
					' <span class="wpbp-menu-badge is-alert" aria-label="%s" title="%s">%s</span>',
					esc_attr( $mark['label'] ),
					esc_attr( $mark['label'] ),
					esc_html( $mark['text'] )
				);
			} elseif ( current_user_can( $capability ) && NewFeatures::for_page( $slug ) ) {
				// Something this release added, until the user has opened it.
				$menu_title .= ' <span class="wpbp-menu-badge is-new">' . esc_html__( 'New', 'wrensafe' ) . '</span>';
			}

			add_submenu_page(
				self::TOP_SLUG,
				$page_title,
				$menu_title,
				$capability,
				$slug,
				$this->page_callback( $slug )
			);
		}

		// The top-level menu duplicates the Dashboard entry by default; rename it explicitly.
		global $submenu;
		if ( isset( $submenu[ self::TOP_SLUG ][0] ) ) {
			$submenu[ self::TOP_SLUG ][0][0] = __( 'Dashboard', 'wrensafe' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- renames the auto-added first submenu item, the documented way to label it.
		}
	}

	/**
	 * Build a render callback for a given page slug.
	 */
	private function page_callback( string $slug ): callable {
		return function () use ( $slug ): void {
			$pages = $this->pages();

			if ( ! isset( $pages[ $slug ] ) ) {
				return;
			}

			list( , $page_class ) = $pages[ $slug ];

			( new $page_class() )->render();
		};
	}

	/**
	 * Whether the given admin hook suffix belongs to a Wrensafe page.
	 */
	public static function is_plugin_screen( string $hook_suffix ): bool {
		return false !== strpos( $hook_suffix, self::TOP_SLUG );
	}
}
