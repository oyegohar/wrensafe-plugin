<?php
/**
 * A job converting another plugin's backup into a Wrensafe archive.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Core\AbstractJob;

defined( 'ABSPATH' ) || exit;

class ForeignImportJob extends AbstractJob {

	protected static function job_type(): string {
		return 'import';
	}
}
