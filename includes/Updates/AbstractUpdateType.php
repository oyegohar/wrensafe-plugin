<?php
/**
 * Shared parts of the update types: running one of WordPress's upgraders
 * the way Safe Updates needs it, and reading what it returned.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

defined( 'ABSPATH' ) || exit;

abstract class AbstractUpdateType implements UpdateType {

	public function strings(): array {
		return array();
	}

	public function guard_data( array $ids ): array {
		return array();
	}

	public function keep_after_rollback( array $item ): void {
	}

	public function last_resort( array $item, int $job_id ): string {
		return '';
	}

	/**
	 * Run a WordPress upgrader's bulk_upgrade() for these ids (like the
	 * "update now" links do), keeping WordPress's list of updates until the
	 * run ends, and turn what it returned into id => true|WP_Error.
	 *
	 * @param string   $upgrader_class A WP_Upgrader subclass with bulk_upgrade(), e.g. \Plugin_Upgrader.
	 * @param string[] $ids
	 * @return array<string,true|\WP_Error>
	 */
	protected function bulk_upgrade( string $upgrader_class, array $ids ): array {
		$out = array();

		SafeUpdates::load_upgrader();

		if ( ! SafeUpdates::filesystem() ) {
			foreach ( $ids as $id ) {
				$out[ $id ] = new \WP_Error( 'wpbp_no_filesystem', __( 'WordPress couldn’t get write access to the files.', 'wrensafe' ) );
			}

			return $out;
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new $upgrader_class( $skin );
		// Keep WordPress's update list until the run ends: the next items need it.
		$results = $upgrader->bulk_upgrade( $ids, array( 'clear_update_cache' => false ) );
		$errors  = implode( ' ', array_map( 'wp_strip_all_tags', (array) $skin->get_error_messages() ) );

		foreach ( $ids as $id ) {
			$result = is_array( $results ) ? ( $results[ $id ] ?? null ) : null;

			if ( is_wp_error( $result ) ) {
				$out[ $id ] = $result;
			} elseif ( false === $results ) {
				$out[ $id ] = new \WP_Error( 'wpbp_no_filesystem', __( 'WordPress couldn’t connect to the file system.', 'wrensafe' ) );
			} elseif ( true === $result ) {
				$out[ $id ] = new \WP_Error( 'wpbp_up_to_date', __( 'WordPress no longer lists this update.', 'wrensafe' ) );
			} elseif ( ! is_array( $result ) ) {
				$out[ $id ] = new \WP_Error( 'wpbp_update_failed', '' !== $errors ? $errors : __( 'The update didn’t install.', 'wrensafe' ) );
			} else {
				$out[ $id ] = true;
			}
		}

		return $out;
	}

	/**
	 * The problem that keeps an update from being installed here ('' when
	 * there is none), from the fields WordPress's update list has.
	 *
	 * @param object $update     An entry of the update_plugins or update_themes transient.
	 * @param string $no_package Why there is no download, in the type's words.
	 */
	protected static function requirement_problem( $update, string $no_package ): string {
		$update       = (array) $update;
		$requires     = (string) ( $update['requires'] ?? '' );
		$requires_php = (string) ( $update['requires_php'] ?? '' );

		if ( empty( $update['package'] ) ) {
			return $no_package;
		}

		if ( '' !== $requires_php && ! is_php_version_compatible( $requires_php ) ) {
			/* translators: %s: PHP version */
			return sprintf( __( 'This version needs PHP %s or later.', 'wrensafe' ), $requires_php );
		}

		if ( '' !== $requires && ! is_wp_version_compatible( $requires ) ) {
			/* translators: %s: WordPress version */
			return sprintf( __( 'This version needs WordPress %s or later.', 'wrensafe' ), $requires );
		}

		return '';
	}
}
