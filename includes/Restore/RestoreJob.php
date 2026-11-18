<?php
/**
 * Domain wrapper around a "restore" row in wp_wpbp_jobs.
 *
 * The actual archive read/import logic is wired into this job's lifecycle
 * in the Chunked Import and Restore Engine development phases.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Core\AbstractJob;

defined( 'ABSPATH' ) || exit;

class RestoreJob extends AbstractJob {

	protected static function job_type(): string {
		return 'restore';
	}
}
