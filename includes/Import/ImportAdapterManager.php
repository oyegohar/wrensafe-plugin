<?php
/**
 * Registry + detection for import adapters (Issue 09). Given an uploaded
 * archive, finds the adapter that understands its format so the Import /
 * Restore flow can branch between the native .wbp pipeline and external
 * formats like All-in-One WP Migration's .wpress.
 *
 * @package WPBP\Import
 */

namespace WPBP\Import;

use WPBP\Import\Adapters\ImportAdapterInterface;
use WPBP\Import\Adapters\WbpAdapter;
use WPBP\Import\Adapters\WpressAdapter;

defined( 'ABSPATH' ) || exit;

class ImportAdapterManager {

	/**
	 * @return ImportAdapterInterface[]
	 */
	public static function adapters(): array {
		return array(
			new WbpAdapter(),
			new WpressAdapter(),
		);
	}

	/**
	 * Extensions the upload UI/endpoint should accept.
	 *
	 * @return string[] Lower-case extensions without the dot.
	 */
	public static function allowed_extensions(): array {
		return array( 'wbp', 'wpress' );
	}

	/**
	 * Find the adapter for a file, by its extension.
	 */
	public static function detect( string $file_path, string $filename ): ?ImportAdapterInterface {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		foreach ( self::adapters() as $adapter ) {
			if ( $adapter->supports( $file_path, $extension ) ) {
				return $adapter;
			}
		}

		return null;
	}
}
