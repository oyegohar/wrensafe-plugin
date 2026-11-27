<?php
/**
 * "New" labels on what a release added: next to its menu item and on the feature itself, for each user until they
 * have opened it. Then the label goes, for that user only.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

defined( 'ABSPATH' ) || exit;

final class NewFeatures {

	/** User meta: the features this user has opened. */
	const META = 'wpbp_features_seen';

	/** @var string[] Features first opened on this page view: their label still shows on the feature itself, once. */
	private static array $cleared = array();

	/**
	 * Each new feature: the release it came in, and the menu item that leads to it.
	 *
	 * @return array<string,array{since:string,page:string}>
	 */
	public static function all(): array {
		return array(
			'ai'           => array(
				'since' => '1.3.0',
				'page'  => 'wrensafe-settings',
			),
			'restore-test' => array(
				'since' => '1.3.0',
				'page'  => 'wrensafe-browser',
			),
			'timeline'     => array(
				'since' => '1.3.0',
				'page'  => 'wrensafe-logs',
			),
			'found'        => array(
				'since' => '1.3.0',
				'page'  => 'wrensafe-import-restore',
			),
		);
	}

	public static function is_new( string $key ): bool {
		$feature = self::all()[ $key ] ?? null;

		if ( null === $feature || ! is_user_logged_in() || version_compare( WPBP_VERSION, $feature['since'], '<' ) ) {
			return false;
		}

		if ( 'ai' === $key && ! \WPBP\Mcp\Abilities::supported() ) {
			return false; // Needs WordPress 6.9.
		}

		return ! in_array( $key, self::seen(), true );
	}

	/** This user has opened the feature: its label goes. */
	public static function seen_now( string $key ): void {
		$seen = self::seen();

		if ( isset( self::all()[ $key ] ) && ! in_array( $key, $seen, true ) ) {
			if ( self::is_new( $key ) ) {
				self::$cleared[] = $key;
			}

			$seen[] = $key;
			update_user_meta( get_current_user_id(), self::META, $seen );
		}
	}

	/** @return string[] */
	private static function seen(): array {
		$seen = get_user_meta( get_current_user_id(), self::META, true );

		return is_array( $seen ) ? array_values( array_filter( $seen, 'is_string' ) ) : array();
	}

	/**
	 * The label on the feature itself, or '' when it isn't new for this user. Opening the feature's screen clears the
	 * menu's label at once (for_page()), but the screen that cleared it still marks the feature, on that view only.
	 */
	public static function badge( string $key ): string {
		return self::is_new( $key ) || in_array( $key, self::$cleared, true ) ? '<span class="wpbp-new-badge">' . esc_html__( 'New', 'wrensafe' ) . '</span>' : '';
	}

	/** A menu item leads to something new for this user. */
	public static function for_page( string $slug ): bool {
		foreach ( self::all() as $key => $feature ) {
			if ( $slug === $feature['page'] && self::is_new( $key ) ) {
				return true;
			}
		}

		return false;
	}
}
