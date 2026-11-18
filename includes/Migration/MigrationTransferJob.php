<?php
/**
 * Domain wrapper around a "migration_transfer" row in wp_wpbp_jobs — the
 * source-side job that pushes a local backup to a destination site.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Core\AbstractJob;
use WPBP\Core\Database\JobsRepository;

defined( 'ABSPATH' ) || exit;

class MigrationTransferJob extends AbstractJob {

	protected static function job_type(): string {
		return 'migration_transfer';
	}

	/**
	 * Completed transfers in the last 30 days — for the "direct migrations
	 * per month" limit an add-on can set (the free plugin sets none).
	 */
	public static function count_completed_this_month(): int {
		return JobsRepository::count_completed_since( self::job_type(), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
	}
}
