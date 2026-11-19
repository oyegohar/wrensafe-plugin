<?php
/**
 * Generates and verifies one-time, expiring migration keys used to
 * authenticate a direct site-to-site transfer.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Core\Database\MigrationKeysRepository;

defined( 'ABSPATH' ) || exit;

// The two updates below change one row of Wrensafe's own migration keys table
// (no WordPress API exists for it), through $wpdb->update() or a prepared
// query; the row must be read fresh, so nothing is object-cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class MigrationKey {

	/**
	 * Stored as the key's IP restriction until its first accepted request:
	 * "only accept this key from the IP address that uses it first".
	 */
	public const FIRST_SENDER = 'first-sender';

	public const MIN_EXPIRY_MINUTES = 5;
	public const MAX_EXPIRY_MINUTES = 10080; // 7 days.

	/** A key: 48 lower-case hex characters (24 random bytes). */
	public const PATTERN = '/^[a-f0-9]{48}$/';

	/**
	 * Generate a new key on the destination site. Returns the plaintext
	 * token, which is only ever available at creation time — only its hash
	 * is stored.
	 *
	 * @param string $ip_restriction '' (any address), FIRST_SENDER, or an IP address.
	 */
	public function generate( int $expiry_minutes = 30, string $ip_restriction = '' ): array {
		$expiry_minutes = min( self::MAX_EXPIRY_MINUTES, max( self::MIN_EXPIRY_MINUTES, $expiry_minutes ) );
		$token          = bin2hex( random_bytes( 24 ) );
		$token_hash     = hash( 'sha256', $token );
		$expires_at     = time() + $expiry_minutes * 60;

		$id = MigrationKeysRepository::create(
			array(
				'token_hash'     => $token_hash,
				'direction'      => 'incoming',
				'expires_at'     => gmdate( 'Y-m-d H:i:s', $expires_at ),
				'ip_restriction' => $ip_restriction,
				'used'           => 0,
			)
		);

		self::remember_key_id( $token, $expires_at );

		return array(
			'id'    => $id,
			'token' => $token,
		);
	}

	/**
	 * Verify a presented token. Returns the key row on success, or null.
	 *
	 * A key generated with FIRST_SENDER is locked to $remote_ip here, on its
	 * first accepted request; later requests must come from that address.
	 */
	public function verify( string $token, string $remote_ip = '' ): ?array {
		if ( ! preg_match( self::PATTERN, $token ) ) {
			return null;
		}

		$row = MigrationKeysRepository::find_by_token_hash( hash( 'sha256', $token ) );

		if ( null === $row ) {
			return null;
		}

		if ( ! empty( $row['used'] ) ) {
			return null;
		}

		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}

		$restriction = (string) $row['ip_restriction'];

		if ( self::FIRST_SENDER === $restriction ) {
			if ( '' === $remote_ip ) {
				return null;
			}

			// Only one address can claim the key, even with requests that
			// arrive at the same moment: the update matches the marker.
			global $wpdb;

			$claimed = $wpdb->update(
				$wpdb->prefix . 'wpbp_migration_keys',
				array( 'ip_restriction' => $remote_ip ),
				array(
					'id'             => (int) $row['id'],
					'ip_restriction' => self::FIRST_SENDER,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);

			if ( 1 === $claimed ) {
				\WPBP\Utils\Logger::info( 'Direct migration key #' . (int) $row['id'] . ' is now locked to the sending server ' . $remote_ip . '.' );
				$restriction = $remote_ip;
			} else {
				$fresh       = MigrationKeysRepository::find_by_token_hash( hash( 'sha256', $token ) );
				$restriction = null !== $fresh ? (string) $fresh['ip_restriction'] : self::FIRST_SENDER;
			}

			$row['ip_restriction'] = $restriction;
		}

		if ( '' !== $restriction && $restriction !== $remote_ip ) {
			return null;
		}

		return $row;
	}

	/**
	 * Keep a key valid while the transfer it started is running: a large
	 * backup can take longer than the key's own lifetime (it only limits
	 * when the transfer must start). Never shortens it.
	 */
	public function extend( array $row, string $token, int $until ): void {
		if ( strtotime( $row['expires_at'] . ' UTC' ) >= $until - 300 ) {
			return; // Still valid for long enough; spares a write per chunk.
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET expires_at = %s WHERE id = %d AND expires_at < %s',
				$wpdb->prefix . 'wpbp_migration_keys',
				gmdate( 'Y-m-d H:i:s', $until ),
				(int) $row['id'],
				gmdate( 'Y-m-d H:i:s', $until )
			)
		);

		self::remember_key_id( $token, $until );
	}

	/**
	 * Sign a migration chunk using the token as the shared HMAC secret.
	 */
	public function sign_chunk( string $token, int $chunk_index, string $chunk_body ): string {
		return hash_hmac( 'sha256', $chunk_index . ':' . hash( 'sha256', $chunk_body ), $token );
	}

	// -- Key ids ---------------------------------------------------------------
	//
	// The first 8 characters of a key are its id. The receiving site keeps a
	// hash of the ids of its unexpired keys, so its throttling can tell a
	// request that names one of its keys from a guess (TransferServer): wrong
	// guesses from a shared address (a proxy, a NAT) then don't lock out the
	// sender that has the key. 8 characters reveal 32 of the key's 192 bits.

	private const KEY_IDS_OPTION = 'wpbp_migration_key_ids';

	/** The id of a key ('' for a value that isn't a key). */
	public static function key_id( string $token ): string {
		return preg_match( self::PATTERN, $token ) ? substr( $token, 0, 8 ) : '';
	}

	/** Whether $token starts with the id of one of this site's unexpired keys. */
	public static function is_known_key_id( string $token ): bool {
		$id  = self::key_id( $token );
		$ids = get_option( self::KEY_IDS_OPTION, array() );

		if ( '' === $id || ! is_array( $ids ) ) {
			return false;
		}

		$hash = self::key_id_hash( $id );

		return isset( $ids[ $hash ] ) && (int) $ids[ $hash ] >= time();
	}

	private static function remember_key_id( string $token, int $until ): void {
		$id = self::key_id( $token );

		if ( '' === $id ) {
			return;
		}

		$ids = get_option( self::KEY_IDS_OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		$now = time();

		$ids = array_filter(
			$ids,
			static function ( $expires ) use ( $now ) {
				return (int) $expires >= $now;
			}
		);

		$hash         = self::key_id_hash( $id );
		$ids[ $hash ] = max( (int) ( $ids[ $hash ] ?? 0 ), $until );

		update_option( self::KEY_IDS_OPTION, $ids, false );
	}

	private static function key_id_hash( string $id ): string {
		return hash_hmac( 'sha256', $id, wp_salt( 'nonce' ) );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
