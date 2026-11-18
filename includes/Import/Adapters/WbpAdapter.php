<?php
/**
 * Native .wbp adapter — a thin wrapper over ArchiveReader so the native
 * format participates in the same adapter detection as external formats.
 * The actual native restore continues to run through RestoreManager.
 *
 * @package WPBP\Import\Adapters
 */

namespace WPBP\Import\Adapters;

use WPBP\Restore\ArchiveReader;

defined( 'ABSPATH' ) || exit;

class WbpAdapter implements ImportAdapterInterface {

	public function key(): string {
		return 'wbp';
	}

	public function label(): string {
		return __( 'Wrensafe Backup', 'wrensafe' );
	}

	public function can_restore(): bool {
		return true;
	}

	public function supports( string $file_path, string $extension ): bool {
		return 'wbp' === strtolower( $extension );
	}

	public function inspect( string $file_path ) {
		$result = ( new ArchiveReader( $file_path ) )->validate();

		if ( ! $result['valid'] ) {
			return new \WP_Error( 'wpbp_invalid_archive', $result['message'] );
		}

		return array(
			'manifest' => $result['manifest'],
			'metadata' => $result['metadata'],
		);
	}

	public function validate( string $file_path ) {
		$result = $this->inspect( $file_path );

		return is_wp_error( $result ) ? $result : true;
	}

	public function preview( string $file_path, array $destination_context ) {
		$result = $this->inspect( $file_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'source_site_url'   => (string) ( $result['metadata']['site_url'] ?? '' ),
			'contains_database' => ! empty( $result['manifest']['contains_database'] ),
			'contains_files'    => ! empty( $result['manifest']['contains_files'] ),
			'estimated_size'    => (int) ( $result['metadata']['total_size'] ?? 0 ),
			'format_version'    => (string) ( $result['manifest']['format_version'] ?? '' ),
			'warnings'          => array(),
		);
	}

	public function restore( string $file_path, array $restore_options, array $destination_context ) {
		// Native restores run through RestoreManager's chunked pipeline (the
		// UploadAjax flow creates the RestoreJob); this adapter never bypasses it.
		return new \WP_Error( 'wpbp_use_native_flow', __( 'Native .wbp restores run through the standard restore flow.', 'wrensafe' ) );
	}
}
