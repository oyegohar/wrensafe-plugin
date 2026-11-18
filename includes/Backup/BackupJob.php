<?php
/**
 * Domain wrapper around a "backup" row in wp_wpbp_jobs.
 *
 * The actual scanning/export/archive logic is wired into this job's
 * lifecycle in the Backup Engine and Archive Writer development phases.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\AbstractJob;

defined( 'ABSPATH' ) || exit;

class BackupJob extends AbstractJob {

	protected static function job_type(): string {
		return 'backup';
	}
}
