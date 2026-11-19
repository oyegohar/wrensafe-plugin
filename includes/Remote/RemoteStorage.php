<?php
/**
 * Off-site copies of backups (Settings → Remote storage).
 *
 * One destination (FTP/FTPS or S3-compatible storage). Right after a
 * backup is made, the backup job copies it there in short steps (see
 * BackupManager::step_remote_copy()) and then keeps only the newest copies
 * there. Passwords and secret keys are stored encrypted (SecretBox).
 *
 * @package WPBP\Remote
 */

namespace WPBP\Remote;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Notifier;
use WPBP\Utils\Logger;
use WPBP\Utils\SecretBox;

defined( 'ABSPATH' ) || exit;

final class RemoteStorage {

	public const OPTION = 'wpbp_remote_storage';

	/** Fields stored encrypted, per type. */
	private const SECRETS = array(
		'ftp' => array( 'password' ),
		's3'  => array( 'secret_key' ),
	);

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'type'           => '',
			'copy_automatic' => true,
			'copy_manual'    => false,
			'keep'           => 10,
			'ftp'            => array(
				'host'     => '',
				'port'     => 21,
				'username' => '',
				'password' => '',
				'folder'   => 'wrensafe',
				'ftps'     => true,
				'passive'  => true,
			),
			's3'             => array(
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'bucket'     => '',
				'access_key' => '',
				'secret_key' => '',
				'folder'     => 'wrensafe',
				'path_style' => false,
			),
		);
	}

	/** Display names. */
	public static function types(): array {
		return array(
			'ftp' => __( 'FTP / FTPS', 'wrensafe' ),
			's3'  => __( 'S3-compatible storage', 'wrensafe' ),
		);
	}

	/**
	 * Whether the saved destination is SFTP, which Wrensafe 1.1.4 no longer
	 * offers (its connection needed PHP's ssh2 stream wrapper, which many
	 * hosts block). The settings are kept; copies stop until another
	 * destination is chosen.
	 */
	public static function has_retired_sftp(): bool {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) && 'sftp' === ( $saved['type'] ?? '' );
	}

	/** Saved settings (secrets still sealed). */
	public static function get(): array {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$settings = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );

		foreach ( array_keys( self::types() ) as $type ) {
			$settings[ $type ] = array_merge( $defaults[ $type ], is_array( $saved[ $type ] ?? null ) ? array_intersect_key( $saved[ $type ], $defaults[ $type ] ) : array() );
		}

		return $settings;
	}

	public static function is_enabled(): bool {
		return isset( self::types()[ self::get()['type'] ] );
	}

	/** Whether a secret is saved for the type ("leave blank to keep"). */
	public static function has_secret( string $type, string $field ): bool {
		return '' !== (string) ( self::get()[ $type ][ $field ] ?? '' );
	}

	/**
	 * Settings from the Remote storage form, cleaned. A blank secret keeps
	 * the saved one.
	 *
	 * @param array<string,mixed> $posted Unslashed values: from options.php
	 *                                    (Settings::sanitize()), which unslashes
	 *                                    them, or from RemoteStorageAjax::test().
	 * @return array<string,mixed> Settings with secrets sealed.
	 */
	public static function sanitize( array $posted ): array {
		$current  = self::get();
		$settings = $current;
		$type     = sanitize_key( (string) ( $posted['type'] ?? '' ) );

		$settings['type']           = isset( self::types()[ $type ] ) ? $type : '';
		$settings['copy_automatic'] = ! empty( $posted['copy_automatic'] );
		$settings['copy_manual']    = ! empty( $posted['copy_manual'] );
		$settings['keep']           = min( 1000, absint( $posted['keep'] ?? 10 ) );

		foreach ( array_keys( self::types() ) as $kind ) {
			$in = is_array( $posted[ $kind ] ?? null ) ? $posted[ $kind ] : array();

			if ( ! $in ) {
				continue;
			}

			foreach ( self::defaults()[ $kind ] as $field => $default ) {
				if ( in_array( $field, self::SECRETS[ $kind ], true ) ) {
					// Kept exactly as typed (and stored encrypted), less control characters.
					$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', is_scalar( $in[ $field ] ?? '' ) ? (string) $in[ $field ] : '' );

					if ( '' !== $value ) {
						$settings[ $kind ][ $field ] = SecretBox::seal( $value );
					}
					continue;
				}

				if ( is_bool( $default ) ) {
					$settings[ $kind ][ $field ] = ! empty( $in[ $field ] );
				} elseif ( is_int( $default ) ) {
					$settings[ $kind ][ $field ] = max( 1, min( 65535, absint( $in[ $field ] ?? $default ) ) );
				} elseif ( 'endpoint' === $field ) {
					$settings[ $kind ][ $field ] = esc_url_raw( trim( (string) ( $in[ $field ] ?? '' ) ), array( 'https', 'http' ) );
				} else {
					$settings[ $kind ][ $field ] = trim( sanitize_text_field( (string) ( $in[ $field ] ?? '' ) ) );
				}
			}
		}

		return $settings;
	}

	public static function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * The target for $settings (default: the saved settings), secrets opened.
	 */
	public static function target( ?array $settings = null ): RemoteTarget {
		$settings = $settings ?? self::get();
		$type     = (string) $settings['type'];
		$config   = (array) ( $settings[ $type ] ?? array() );

		foreach ( self::SECRETS[ $type ] ?? array() as $field ) {
			$config[ $field ] = SecretBox::open( (string) ( $config[ $field ] ?? '' ) );
		}

		switch ( $type ) {
			case 'ftp':
				return new FtpTarget( $config );
			case 's3':
				return new S3Target( $config );
		}

		throw new \RuntimeException( esc_html__( 'No remote storage is set up.', 'wrensafe' ) );
	}

	/**
	 * An error's message as plain text. Exception messages are HTML-escaped
	 * when thrown; they're shown with .text(), logged and emailed as plain
	 * text, so an apostrophe from the server would otherwise read "&#039;".
	 */
	public static function error_text( \Throwable $e ): string {
		return \WPBP\Utils\Errors::text( $e );
	}

	/** Why a type can't be used on this server ('' when it can). */
	public static function unavailable_reason( string $type ): string {
		$classes = array(
			'ftp' => FtpTarget::class,
			's3'  => S3Target::class,
		);

		return isset( $classes[ $type ] ) ? $classes[ $type ]::unavailable_reason() : '';
	}

	/**
	 * Whether this backup job should be copied off-site. Rollback points
	 * (made in the middle of a restore) never are.
	 */
	public static function wants( array $payload ): bool {
		if ( ! self::is_enabled() || 0 === strpos( (string) ( $payload['note'] ?? '' ), 'Automatic rollback point' ) ) {
			return false;
		}

		$settings  = self::get();
		$automatic = ! empty( $payload['schedule_id'] ) || 'pre_update' === (string) ( $payload['tag'] ?? '' );

		return $automatic ? ! empty( $settings['copy_automatic'] ) : ! empty( $settings['copy_manual'] );
	}

	/**
	 * Copy one backup, part by part. Returns true when the copy is complete
	 * (the caller then records it); throws on a problem.
	 *
	 * @param array<string,mixed> $state Upload state kept by the caller ([] to start).
	 */
	public static function copy_step( array &$state, string $local_path, string $name, float $deadline ): bool {
		// A scheduled copy runs without the Settings page that explains this:
		// a missing PHP extension would otherwise end in "Call to undefined
		// function".
		$reason = self::unavailable_reason( (string) self::get()['type'] );

		if ( '' !== $reason ) {
			throw new \RuntimeException( esc_html( $reason ) );
		}

		$target = self::target();

		if ( empty( $state ) ) {
			clearstatcache( true, $local_path );
			$state = $target->begin( $name, (int) filesize( $local_path ) );
		}

		if ( ! $target->send( $local_path, $state, $deadline ) ) {
			return false;
		}

		$target->finish( $state );
		self::apply_retention( $target, $name );

		return true;
	}

	/** Drop an unfinished upload after a failure. */
	public static function abort( array $state ): void {
		if ( ! empty( $state ) && self::is_enabled() && '' === self::unavailable_reason( (string) self::get()['type'] ) ) {
			try {
				self::target()->abort( $state );
			} catch ( \Throwable $e ) {
				unset( $e ); // Best effort.
			}
		}
	}

	/**
	 * Keep only the newest copies there (0 keeps them all). The copy just
	 * made is never deleted.
	 */
	private static function apply_retention( RemoteTarget $target, string $just_copied ): void {
		$keep = (int) self::get()['keep'];

		if ( $keep <= 0 ) {
			return;
		}

		try {
			$copies = $target->list_backups();
		} catch ( \RuntimeException $e ) {
			Logger::warning( 'remote_retention_skipped: the remote copies couldn’t be listed (' . self::error_text( $e ) . ').' );
			return;
		}

		$removed = 0;

		foreach ( array_slice( $copies, $keep ) as $copy ) {
			if ( $copy['name'] !== $just_copied && $target->delete( $copy['name'] ) ) {
				++$removed;
			}
		}

		if ( $removed ) {
			Logger::info( sprintf( 'remote_retention_applied: %d older off-site copies deleted (keeping the newest %d).', $removed, $keep ) );
		}
	}

	/**
	 * Record the outcome on the backup and report a failure.
	 */
	public static function record( int $backup_id, bool $ok, string $message = '' ): void {
		$type = (string) self::get()['type'];

		BackupsRepository::update( $backup_id, array( 'destination' => ( $ok ? 'remote:' : 'remote-failed:' ) . $type ) );

		if ( $ok ) {
			Logger::success( sprintf( 'remote_copy_completed: backup #%d copied to %s.', $backup_id, self::types()[ $type ] ?? $type ) );
			return;
		}

		Logger::error( sprintf( 'remote_copy_failed: backup #%d wasn’t copied to %s: %s The backup itself is fine and stays on this server.', $backup_id, self::types()[ $type ] ?? $type, $message ) );
		Notifier::remote_copy_failed( $backup_id, $message );
	}

	/**
	 * @param array<int,array{name:string,time:int,size:int}> $items
	 * @return array<int,array{name:string,time:int,size:int}>
	 */
	public static function newest_first( array $items ): array {
		usort(
			$items,
			static function ( $a, $b ) {
				$by_time = $b['time'] <=> $a['time'];

				return 0 !== $by_time ? $by_time : strcmp( $b['name'], $a['name'] );
			}
		);

		return $items;
	}
}
