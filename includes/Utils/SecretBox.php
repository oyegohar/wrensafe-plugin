<?php
/**
 * Encrypts short secrets (remote storage passwords and keys) before they are
 * saved in the database.
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305; WordPress ships a fallback
 * implementation, so it works on every host) with a key derived from this
 * site's own authentication salt. Because the salts never leave the site
 * (wp-config.php is not in backups), a database restored on another site
 * can't read these secrets: the remote storage must be connected again there.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

final class SecretBox {

	private const PREFIX = 'wsbox1:';

	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'secure_auth' ) . '|wrensafe-remote-storage', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function seal( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . sodium_bin2base64( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ), SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/** The plain secret, or '' when it can't be opened (other site, damaged). */
	public static function open( string $sealed ): string {
		if ( 0 !== strpos( $sealed, self::PREFIX ) ) {
			return '';
		}

		try {
			$raw = sodium_base642bin( substr( $sealed, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );

		return false === $plain ? '' : (string) $plain;
	}
}
