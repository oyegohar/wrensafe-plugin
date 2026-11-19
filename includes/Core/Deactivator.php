<?php
/**
 * Runs on plugin deactivation.
 *
 * Deactivation never deletes user data — it stops the WP-Cron tick that
 * drives scheduled backups, and removes what an interrupted restore may
 * have left in place: the temporary restore guard in mu-plugins (it would
 * otherwise keep filtering the site's plugins and theme and pausing cron
 * with Wrensafe switched off) and the restore's maintenance notices.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Restore\PluginSafety;
use WPBP\Restore\RestoreSiteMaintenance;
use WPBP\Scheduler\CronHandler;
use WPBP\Utils\Logger;
use WPBP\WooCommerce\CheckoutMaintenance;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	public static function deactivate(): void {
		CronHandler::unschedule();

		if ( PluginSafety::has_boot_guard() ) {
			PluginSafety::remove_boot_guard();
			Logger::warning( __( 'Wrensafe was deactivated during a restore: the temporary restore guard was removed.', 'wrensafe' ) );
		}

		RestoreSiteMaintenance::disable();
		( new CheckoutMaintenance() )->disable();

		Logger::info( __( 'Wrensafe deactivated.', 'wrensafe' ) );
	}
}
