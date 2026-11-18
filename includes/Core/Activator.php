<?php
/**
 * Runs on plugin activation.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Core\Database\Schema;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class Activator {

	public static function activate(): void {
		if ( ! Compatibility::is_compatible() ) {
			deactivate_plugins( WPBP_PLUGIN_BASENAME );
			wp_die(
				esc_html( Compatibility::get_incompatibility_message() ),
				esc_html__( 'Plugin activation error', 'wrensafe' ),
				array( 'back_link' => true )
			);
		}

		Settings::install_defaults();
		Schema::install();
		FileSystem::adopt_legacy_storage(); // Before the new folder is created, so it can simply be renamed.
		FileSystem::ensure_storage_structure();
		CapabilityManager::add_to_admin_role();

		if ( false === get_option( 'wpbp_version', false ) ) {
			add_option( 'wpbp_version', WPBP_VERSION );
		} else {
			update_option( 'wpbp_version', WPBP_VERSION );
		}

		Logger::info( __( 'Wrensafe activated.', 'wrensafe' ) );
	}
}
