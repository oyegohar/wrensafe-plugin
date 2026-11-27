<?php
/**
 * A Restore Test (RestoreTest) in wp_wpbp_jobs.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Core\AbstractJob;

defined( 'ABSPATH' ) || exit;

class RestoreTestJob extends AbstractJob {

	protected static function job_type(): string {
		return 'restore_test';
	}
}
