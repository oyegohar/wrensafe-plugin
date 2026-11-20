<?php
/**
 * One Safe Updates run: one or more plugin updates, each with an optional
 * restore point and a check of the site afterwards (see SafeUpdateManager).
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Core\AbstractJob;

defined( 'ABSPATH' ) || exit;

class SafeUpdateJob extends AbstractJob {

	const TYPE = 'safe_update';

	protected static function job_type(): string {
		return self::TYPE;
	}

	public function is_finished(): bool {
		return in_array( $this->status(), array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ), true );
	}
}
