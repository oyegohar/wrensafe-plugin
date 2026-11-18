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

class MigrationKey {

	/**
	 * Generate a new key on the destination site. Returns the plaintext
	 * token, which is only ever available at creation time — only its hash
	 * is stored.
	 */
	public function generate( int $expiry_minutes = 30, string $ip_restriction = '' ): array {
		$token      = bin2hex( random_bytes( 24 ) );
		$token_hash = hash( 'sha256', $token );

		$id = MigrationKeysRepository::create(
			array(
				'token_hash'     => $token_hash,
				'direction'      => 'incoming',
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + $expiry_minutes * 60 ),
				'ip_restriction' => $ip_restriction,
				'used'           => 0,
			)
		);

		return array(
			'id'    => $id,
			'token' => $token,
		);
	}

	/**
	 * Verify a presented token. Returns the key row on success, or null.
	 */
	public function verify( string $token, string $remote_ip = '' ): ?array {
		$row = MigrationKeysRepository::find_by_token_hash( hash( 'sha256', $token ) );

		if ( null === $row ) {
			return null;
		}

		if ( ! empty( $row['used'] ) ) {
			return null;
		}

		if ( strtotime( $row['expires_at'] ) < time() ) {
			return null;
		}

		if ( ! empty( $row['ip_restriction'] ) && $row['ip_restriction'] !== $remote_ip ) {
			return null;
		}

		return $row;
	}

	/**
	 * Sign a migration chunk using the token as the shared HMAC secret.
	 */
	public function sign_chunk( string $token, int $chunk_index, string $chunk_body ): string {
		return hash_hmac( 'sha256', $chunk_index . ':' . hash( 'sha256', $chunk_body ), $token );
	}
}
