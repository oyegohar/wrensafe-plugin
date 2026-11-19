<?php
/**
 * Solid Backups (formerly BackupBuddy, by SolidWP): one .zip of the
 * WordPress folder. The database and a backupbuddy_dat.php details file
 * are inside wp-content/uploads/backupbuddy_temp/{serial}/ (one .sql per
 * table in recent versions, db_1.sql in older ones).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;

defined( 'ABSPATH' ) || exit;

class SolidBackups extends AbstractFormat {

	private const DAT_PATTERN = '#(?:^|/)backupbuddy_temp/[^/]+/backupbuddy_dat\.php$#';

	public function key(): string {
		return 'solid-backups';
	}

	public function name(): string {
		return 'Solid Backups (BackupBuddy)';
	}

	public function matches( array $files ): bool {
		return null !== $this->archive( $files );
	}

	public function describe( array $files ): array {
		$found = $this->archive( $files );

		if ( null === $found ) {
			throw new \RuntimeException( esc_html__( 'No Solid Backups (BackupBuddy) archive was found.', 'wrensafe' ) );
		}

		list( $path, $dat ) = $found;

		return array(
			'sources'  => array(
				array(
					'type' => 'zip',
					'file' => $path,
					'base' => 'root',
				),
			),
			'meta'     => self::dat_meta( (string) self::zip_entry( $path, $dat ) ),
			'warnings' => array(),
		);
	}

	public function classify( array $source, string $path ): array {
		if ( preg_match( '#(?:^|/)backupbuddy_temp/[^/]+/[^/]+\.sql$#i', $path ) ) {
			return array( 'sql' );
		}

		return $this->root_target( $path );
	}

	/**
	 * Site details from backupbuddy_dat.php: a PHP die() line followed by
	 * the details, stored as JSON or a serialized array.
	 *
	 * @return array<string,string>
	 */
	private static function dat_meta( string $raw ): array {
		$raw  = trim( (string) preg_replace( '/^<\?php.*?\?>/s', '', $raw ) );
		$data = self::decode( $raw );

		if ( ! is_array( $data ) ) {
			return array(); // The site address is also read from the database itself.
		}

		$time = isset( $data['backup_time'] ) && is_numeric( $data['backup_time'] ) ? (int) $data['backup_time'] : 0;

		return array_filter(
			array(
				'site_url'   => untrailingslashit( (string) ( $data['siteurl'] ?? '' ) ),
				'home_url'   => untrailingslashit( (string) ( $data['homeurl'] ?? ( $data['home'] ?? '' ) ) ),
				'prefix'     => (string) ( $data['db_prefix'] ?? '' ),
				'wp_version' => (string) ( $data['wp_version'] ?? '' ),
				'created_at' => $time > 0 ? gmdate( 'Y-m-d\TH:i:s', $time ) : '',
			),
			static fn( $value ) => is_string( $value ) && '' !== $value
		);
	}

	/** @return mixed */
	private static function decode( string $raw ) {
		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			return $data;
		}

		// Plain arrays only: allowed_classes => false never instantiates objects.
		return @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	}

	/**
	 * @param array<string,string> $files
	 * @return array{0:string,1:string}|null The zip and its details file.
	 */
	private function archive( array $files ): ?array {
		foreach ( $files as $name => $path ) {
			if ( 'zip' !== self::ext( $name ) ) {
				continue;
			}

			$dat = self::zip_find( $path, self::DAT_PATTERN );

			if ( null !== $dat ) {
				return array( $path, $dat );
			}
		}

		return null;
	}
}
