<?php
/**
 * Contract for import adapters: each adapter teaches Wrensafe how to
 * inspect/preview/restore ONE external (or native) backup archive format,
 * keeping format-specific code out of the core .wbp restore flow.
 *
 * @package WPBP\Import\Adapters
 */

namespace WPBP\Import\Adapters;

defined( 'ABSPATH' ) || exit;

interface ImportAdapterInterface {

	/**
	 * A short machine key for this adapter, e.g. 'wbp', 'wpress'.
	 */
	public function key(): string;

	/**
	 * Human label shown as the source-format badge, e.g.
	 * "Wrensafe Backup" / "All-in-One WP Migration Backup (.wpress) — Experimental".
	 */
	public function label(): string;

	/**
	 * Whether restoring through this adapter is enabled. Experimental
	 * adapters return false until they are fully tested — preview only.
	 */
	public function can_restore(): bool;

	/**
	 * Whether this adapter handles the given file/extension.
	 */
	public function supports( string $file_path, string $extension ): bool;

	/**
	 * Cheap structural inspection (headers/manifest only — never a full read).
	 *
	 * @return array|\WP_Error Format facts on success.
	 */
	public function inspect( string $file_path );

	/**
	 * Deeper validation that the archive is safely readable.
	 *
	 * @return true|\WP_Error
	 */
	public function validate( string $file_path );

	/**
	 * Data for the pre-restore preview (source URL, database/files present,
	 * estimated size, format version, warnings).
	 *
	 * @return array|\WP_Error
	 */
	public function preview( string $file_path, array $destination_context );

	/**
	 * Perform (or refuse) the restore. Experimental adapters must return a
	 * WP_Error rather than attempt an unsafe partial restore.
	 *
	 * @return array|\WP_Error
	 */
	public function restore( string $file_path, array $restore_options, array $destination_context );
}
