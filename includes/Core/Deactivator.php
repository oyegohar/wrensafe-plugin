<?php
/**
 * Runs on plugin deactivation.
 *
 * Deactivation never deletes user data — it only stops the WP-Cron tick
 * that drives scheduled backups.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Scheduler\CronHandler;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	public static function deactivate(): void {
		CronHandler::unschedule();

		Logger::info( __( 'Wrensafe deactivated.', 'wrensafe' ) );
	}
}
