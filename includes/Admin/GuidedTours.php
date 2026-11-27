<?php
/**
 * Guided tours: a spotlight on one part of a screen with a tooltip beside it, moving from page to page.
 *
 * A tour only shows things. While it runs the page can't be clicked, so it can't start a backup or
 * change a setting. A step whose page this user can't open is left out, and a step whose element isn't
 * on the page right now (no updates waiting, for example) is skipped by the script.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\FixLinksPage;

defined( 'ABSPATH' ) || exit;

final class GuidedTours {

	/** @var array<int,array<string,mixed>>|null This request's tours. */
	private static $tours = null;

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue( string $hook_suffix ): void {
		if ( ! AdminMenu::is_plugin_screen( $hook_suffix ) || ! self::available() ) {
			return;
		}

		wp_enqueue_style( 'wpbp-guided-tour', WPBP_PLUGIN_URL . 'assets/admin/css/guided-tour.css', array( 'wpbp-admin' ), WPBP_VERSION );
		wp_style_add_data( 'wpbp-guided-tour', 'rtl', 'replace' );
		wp_enqueue_script( 'wpbp-guided-tour', WPBP_PLUGIN_URL . 'assets/admin/js/guided-tour.js', array( 'jquery', 'wpbp-admin' ), WPBP_VERSION, true );
		wp_localize_script(
			'wpbp-guided-tour',
			'wpbpTours',
			array(
				'tours' => self::tours(),
				'done'  => Onboarding::state()['tours'],
				'i18n'  => array(
					'title'    => __( 'Guided tours', 'wrensafe' ),
					'intro'    => __( 'Pick what you want to learn. A tour points at each part of the screen and explains it. It never changes anything on your site.', 'wrensafe' ),
					'close'    => __( 'Close', 'wrensafe' ),
					'exit'     => __( 'End the tour', 'wrensafe' ),
					'back'     => __( 'Back', 'wrensafe' ),
					'next'     => __( 'Next', 'wrensafe' ),
					'finish'   => __( 'Finish', 'wrensafe' ),
					'loading'  => __( 'Opening…', 'wrensafe' ),
					'taken'    => __( 'Done', 'wrensafe' ),
					'finished' => __( 'Tour finished. You can take any tour again from Guided tours.', 'wrensafe' ),
					/* translators: 1: this step's number, 2: number of steps */
					'stepOf'   => __( 'Step %1$d of %2$d', 'wrensafe' ),
				),
			)
		);
	}

	/** Whether this user has at least one tour. */
	public static function available(): bool {
		return array() !== self::tours();
	}

	/** @return string[] The ids of every tour, whatever this user can open. */
	public static function ids(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * The tours this user can take: steps on pages they can't open are left out.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function tours(): array {
		if ( null !== self::$tours ) {
			return self::$tours;
		}

		self::$tours = array();

		foreach ( self::definitions() as $id => $tour ) {
			$steps = array();

			foreach ( $tour['steps'] as $step ) {
				$capability = AdminMenu::capability_of( $step['page'] );

				if ( '' !== $capability && current_user_can( $capability ) ) {
					$step['url'] = admin_url( 'admin.php?page=' . $step['page'] . ( '' !== $step['tab'] ? '&tab=' . $step['tab'] : '' ) );
					$steps[]     = $step;
				}
			}

			if ( count( $steps ) < 2 ) {
				continue;
			}

			self::$tours[] = array(
				'id'    => $id,
				'title' => $tour['title'],
				'text'  => $tour['text'],
				'icon'  => $tour['icon'],
				/* translators: %d: number of steps in the tour */
				'size'  => sprintf( _n( '%d step', '%d steps', count( $steps ), 'wrensafe' ), count( $steps ) ),
				'steps' => $steps,
			);
		}

		return self::$tours;
	}

	/**
	 * One stop of a tour.
	 *
	 * @param string   $page    The page's slug.
	 * @param string[] $targets Selectors, best first: the first one visible on the page is highlighted.
	 * @param string   $title   The tooltip's title.
	 * @param string   $text    The tooltip's text.
	 * @param string   $around  Highlight this ancestor of the target (a table row, a card).
	 * @param string   $tab     A tab of the page (Settings → AI assistants: 'ai').
	 * @return array<string,mixed>
	 */
	private static function stop( string $page, array $targets, string $title, string $text, string $around = '', string $tab = '' ): array {
		return array(
			'page'    => $page,
			'tab'     => $tab,
			'targets' => $targets,
			'around'  => $around,
			'title'   => $title,
			'text'    => $text,
		);
	}

	/** @return array<string,array<string,mixed>> */
	private static function definitions(): array {
		$links = FixLinksPage::SLUG;

		return array(
			'whatsnew'   => array(
				/* translators: %s: Wrensafe version */
				'title' => sprintf( __( 'What’s new in %s', 'wrensafe' ), '1.3.0' ),
				'text'  => __( 'AI assistants, Restore Test, the activity timeline and more, where to find them.', 'wrensafe' ),
				'icon'  => 'dashicons-star-filled',
				'steps' => array(
					self::stop( AdminMenu::TOP_SLUG, array( '.wpbp-brandbar-changelog' ), __( 'Every release, in one place', 'wrensafe' ), __( 'Changelog in the header lists what changed in every version. A dot appears here after an update.', 'wrensafe' ) ),
					self::stop( 'wrensafe-settings', array( '#wpbp-ai-intro', '#wpbp-mcp-connect' ), __( 'AI assistants', 'wrensafe' ), __( 'Connect Claude, Cursor, Codex or Gemini in four steps. They can check your backups and make and test them; you approve every restore yourself.', 'wrensafe' ), '', 'ai' ),
					self::stop( 'wrensafe-browser', array( '.wpbp-backups-table tbody tr:first-child .wpbp-backup-actions', '.wpbp-backups-table', '.wpbp-browser-heading' ), __( 'Restore Test', 'wrensafe' ), __( 'Under ⋯ on a backup, Test restore restores it into a temporary copy and checks it, so you know it works before you need it.', 'wrensafe' ), 'tr' ),
					self::stop( 'wrensafe-logs', array( '.wpbp-tl-totals', '.wpbp-tl' ), __( 'Activity Logs, as a timeline', 'wrensafe' ), __( 'Each backup, restore or update is one card, with the reason when something went wrong and its steps one click away.', 'wrensafe' ) ),
					self::stop( 'wrensafe-import-restore', array( '#wpbp-found-callout', '#wpbp-import-source-grid' ), __( 'Backups from other plugins', 'wrensafe' ), __( 'Wrensafe finds UpdraftPlus, Duplicator and other plugins’ backups on the server and restores one without uploading it.', 'wrensafe' ) ),
				),
			),
			'everything' => array(
				'title' => __( 'See everything', 'wrensafe' ),
				'text'  => __( 'A quick walk through every feature, one screen at a time.', 'wrensafe' ),
				'icon'  => 'dashicons-admin-site-alt3',
				'steps' => array(
					self::stop( AdminMenu::TOP_SLUG, array( '.wpbp-hero' ), __( 'Your protection at a glance', 'wrensafe' ), __( 'This panel tells you whether the site is backed up, when the last backup ran and what runs next.', 'wrensafe' ) ),
					self::stop( 'wrensafe-backup', array( '#wpbp-backup-type-grid' ), __( 'Create Backup', 'wrensafe' ), __( 'Back up the whole site, or only the database, files, media, themes or plugins.', 'wrensafe' ) ),
					self::stop( 'wrensafe-import-restore', array( '#wpbp-import-source-grid' ), __( 'Import / Restore', 'wrensafe' ), __( 'Restore a backup from this server, a file or a web address, or bring in a backup made by another backup plugin.', 'wrensafe' ) ),
					self::stop( 'wrensafe-browser', array( '.wpbp-backups-table', '.wpbp-browser-heading' ), __( 'Backup Browser', 'wrensafe' ), __( 'Every backup on this server. Restore, download, verify, lock or delete them here.', 'wrensafe' ) ),
					self::stop( 'wrensafe-browser', array( '.wpbp-backups-table tbody tr:first-child .wpbp-backup-actions' ), __( 'Test a restore', 'wrensafe' ), __( 'Under ⋯ on each backup, Test restore restores it into a temporary copy to prove it works. Your site isn’t changed.', 'wrensafe' ), 'tr' ),
					self::stop( 'wrensafe-migration-assistant', array( '.wpbp-ma-hero', '.wpbp-ma-start' ), __( 'Migration Assistant', 'wrensafe' ), __( 'Planning a move? It checks this site and its server, then recommends the safest way to migrate.', 'wrensafe' ) ),
					self::stop( 'wrensafe-direct-migration', array( '#wpbp-mig-generate' ), __( 'Direct Migration', 'wrensafe' ), __( 'Send a backup straight to another site. The site that receives it makes a one-time key here.', 'wrensafe' ), '.wpbp-card' ),
					self::stop( 'wrensafe-schedule', array( '#wpbp-schedule-form' ), __( 'Scheduled Backups', 'wrensafe' ), __( 'Back up automatically every day, week or month, and keep only the newest copies.', 'wrensafe' ) ),
					self::stop( 'wrensafe-staging', array( '#wpbp-stg-create', '.wpbp-page-staging .wpbp-card' ), __( 'Staging', 'wrensafe' ), __( 'Make a private copy of the site to try changes, then push them to the live site when they work.', 'wrensafe' ) ),
					self::stop( 'wrensafe-rollback', array( '.wpbp-page-rollback .wpbp-card' ), __( 'Rollback', 'wrensafe' ), __( 'A rollback point is saved before each restore, so a restore that didn’t go as planned can be undone.', 'wrensafe' ) ),
					self::stop( 'wrensafe-safe-updates', array( '.wpbp-su-modes', '#wpbp-su-form' ), __( 'Safe Updates', 'wrensafe' ), __( 'Update plugins with a safety net: the site is checked after the update, and a breaking update is put back.', 'wrensafe' ) ),
					self::stop( 'wrensafe-search-replace', array( '#wpbp-sr-form' ), __( 'Search & Replace', 'wrensafe' ), __( 'Replace text anywhere in the database. A dry run shows what would change before anything is saved.', 'wrensafe' ) ),
					self::stop( $links, array( '.wpbp-lc-card' ), __( 'Fix Links', 'wrensafe' ), __( 'After a restore or a move, it finds links that still point to the old site or are broken, and fixes them.', 'wrensafe' ) ),
					self::stop( 'wrensafe-logs', array( '.wpbp-tl-totals', '.wpbp-page-logs .wpbp-card' ), __( 'Activity Logs', 'wrensafe' ), __( 'Every backup, restore and update as one card on a timeline, with the reason when something went wrong. Start here if something didn’t work.', 'wrensafe' ) ),
					self::stop( 'wrensafe-settings', array( '.wpbp-settings-nav' ), __( 'Settings', 'wrensafe' ), __( 'The backup folder, exclusions, updates, off-site copies, emails and more, grouped by task.', 'wrensafe' ) ),
					self::stop( 'wrensafe-settings', array( '#wpbp-ai-intro', '#wpbp-mcp-connect' ), __( 'AI assistants', 'wrensafe' ), __( 'Let Claude, Cursor, Codex and other assistants check your backups, make and test them, and ask you for a restore. You approve every restore yourself.', 'wrensafe' ), '', 'ai' ),
					self::stop( 'wrensafe-system-info', array( '#wpbp-malware-scan-run' ), __( 'Security scan', 'wrensafe' ), __( 'System Info shows your server’s details, and this scan looks for changed core files and suspicious code.', 'wrensafe' ), '.wpbp-card' ),
				),
			),
			'backup'     => array(
				'title' => __( 'Make a backup', 'wrensafe' ),
				'text'  => __( 'Choose what to save, start it, and make it automatic.', 'wrensafe' ),
				'icon'  => 'dashicons-archive',
				'steps' => array(
					self::stop( 'wrensafe-backup', array( '#wpbp-backup-type-grid' ), __( 'Choose what to back up', 'wrensafe' ), __( 'Full website is the safest choice. The other options save only one part of the site.', 'wrensafe' ) ),
					self::stop( 'wrensafe-backup', array( '#wpbp-backup-note' ), __( 'Add a note', 'wrensafe' ), __( 'A short note like “Before the redesign” helps you find this backup later. It’s optional.', 'wrensafe' ), 'tr' ),
					self::stop( 'wrensafe-backup', array( '#wpbp-backup-encrypt' ), __( 'Encrypt it if you like', 'wrensafe' ), __( 'Protect the backup with a password. Keep it somewhere safe: it isn’t stored, and the backup can’t be restored without it.', 'wrensafe' ), 'tr' ),
					self::stop( 'wrensafe-backup', array( '#wpbp-start-backup' ), __( 'Start the backup', 'wrensafe' ), __( 'When you’re ready, press this button. You’ll see each step live and can cancel at any time. The tour won’t press it for you.', 'wrensafe' ) ),
					self::stop( 'wrensafe-browser', array( '.wpbp-backups-table', '.wpbp-browser-heading' ), __( 'Find it afterwards', 'wrensafe' ), __( 'Finished backups appear here, ready to restore, download or verify. Lock the ones you never want deleted.', 'wrensafe' ) ),
					self::stop( 'wrensafe-schedule', array( '#wpbp-schedule-form' ), __( 'Make it automatic', 'wrensafe' ), __( 'Set a schedule and backups run by themselves. Only the newest copies are kept, so the disk doesn’t fill up.', 'wrensafe' ) ),
				),
			),
			'restore'    => array(
				'title' => __( 'Restore a backup', 'wrensafe' ),
				'text'  => __( 'Get the site back, and undo a restore if you need to.', 'wrensafe' ),
				'icon'  => 'dashicons-backup',
				'steps' => array(
					self::stop( 'wrensafe-browser', array( '.wpbp-local-restore', '.wpbp-backups-table', '.wpbp-browser-heading' ), __( 'Start from a backup', 'wrensafe' ), __( 'Each backup on this server has a Restore button. Nothing changes yet: you review it first.', 'wrensafe' ) ),
					self::stop( 'wrensafe-import-restore', array( '#wpbp-import-source-grid' ), __( 'Or bring one in', 'wrensafe' ), __( 'Upload a backup, point to one on the server or at a web address, or use a backup from another plugin, such as UpdraftPlus or All-in-One WP Migration.', 'wrensafe' ) ),
					self::stop( 'wrensafe-import-restore', array(), __( 'Review before anything changes', 'wrensafe' ), __( 'Next you’d see what’s inside the backup and what will change. Every file is checked first, and you choose what to restore.', 'wrensafe' ) ),
					self::stop( 'wrensafe-rollback', array( '.wpbp-page-rollback .wpbp-card' ), __( 'Changed your mind?', 'wrensafe' ), __( 'A rollback point is saved before each restore. Use it here to put the site back the way it was.', 'wrensafe' ) ),
				),
			),
			'move'       => array(
				'title' => __( 'Move a site', 'wrensafe' ),
				'text'  => __( 'Check the server, send the site across, and clean up the links.', 'wrensafe' ),
				'icon'  => 'dashicons-migrate',
				'steps' => array(
					self::stop( 'wrensafe-migration-assistant', array( '.wpbp-ma-hero', '.wpbp-ma-start' ), __( 'Check first', 'wrensafe' ), __( 'Migration Assistant checks this site and its server, then recommends the safest method and lists the next steps.', 'wrensafe' ) ),
					self::stop( 'wrensafe-direct-migration', array( '#wpbp-mig-generate' ), __( 'On the new site: make a key', 'wrensafe' ), __( 'Install Wrensafe on the new site and generate a one-time migration key on this screen there.', 'wrensafe' ), '.wpbp-card' ),
					self::stop( 'wrensafe-direct-migration', array( '#wpbp-mig-send' ), __( 'On this site: send it', 'wrensafe' ), __( 'Paste the new site’s address and key here, choose a backup and start the transfer.', 'wrensafe' ), '.wpbp-card' ),
					self::stop( $links, array( '.wpbp-lc-card' ), __( 'After the move: check the links', 'wrensafe' ), __( 'Fix Links finds addresses that still point to the old site, and fixes them after making a database backup.', 'wrensafe' ) ),
				),
			),
			'updates'    => array(
				'title' => __( 'Update plugins safely', 'wrensafe' ),
				'text'  => __( 'Update with a safety net, and roll back if you need to.', 'wrensafe' ),
				'icon'  => 'dashicons-update',
				'steps' => array(
					self::stop( 'wrensafe-safe-updates', array( '.wpbp-su-card-head', '#wpbp-su-form' ), __( 'See what’s waiting', 'wrensafe' ), __( 'Plugins with a new version are listed here. Select the ones you want to update.', 'wrensafe' ) ),
					self::stop( 'wrensafe-safe-updates', array( '.wpbp-su-modes' ), __( 'Quick or Safe', 'wrensafe' ), __( 'Quick Update is WordPress’s standard update. Safe Update saves a copy first, checks the site afterwards and puts a breaking update back.', 'wrensafe' ) ),
					self::stop( 'wrensafe-safe-updates', array( '.wpbp-su-order' ), __( 'One by one, or all at once', 'wrensafe' ), __( 'One by one checks the site after each plugin, which is safest. All at once is faster and checks the site after the group.', 'wrensafe' ) ),
					self::stop( 'wrensafe-safe-updates', array( '#wpbp-su-history' ), __( 'Recent updates', 'wrensafe' ), __( 'Every run is recorded here, with the reason if an update failed. While its saved copy is kept, an update can be rolled back.', 'wrensafe' ) ),
				),
			),
			'links'      => array(
				'title' => __( 'Fix broken links', 'wrensafe' ),
				'text'  => __( 'Repair links and images after a restore or a move.', 'wrensafe' ),
				'icon'  => 'dashicons-admin-links',
				'steps' => array(
					self::stop( $links, array( '.wpbp-lc-card' ), __( 'What it looks for', 'wrensafe' ), __( 'Links to the old site, server folders written into image addresses, http links on an https site, and missing media files.', 'wrensafe' ) ),
					self::stop( $links, array( '#wpbp-lc-addresses' ), __( 'Tell it the old address', 'wrensafe' ), __( 'Add the address the site had before it moved. After a restore it’s usually filled in for you.', 'wrensafe' ) ),
					self::stop( $links, array( '#wpbp-lc-check' ), __( 'Run the check', 'wrensafe' ), __( 'The check changes nothing. If it finds links to fix, a database backup is made before they’re fixed.', 'wrensafe' ) ),
				),
			),
		);
	}
}
