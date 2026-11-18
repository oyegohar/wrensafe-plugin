<?php
/**
 * Domain wrapper around a "staging" row in wp_wpbp_jobs.
 *
 * @package WPBP\Staging
 */

namespace WPBP\Staging;

use WPBP\Core\AbstractJob;

defined( 'ABSPATH' ) || exit;

class StagingJob extends AbstractJob {

	protected static function job_type(): string {
		return 'staging';
	}
}
