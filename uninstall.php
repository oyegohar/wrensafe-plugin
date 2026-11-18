<?php
/**
 * Uninstall handler for Wrensafe.
 *
 * @package WPBP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Another copy of Wrensafe is running in this request (WordPress loaded it as
// an active plugin): the settings and backup records are in use and its
// classes are already declared, so keep everything.
if ( defined( 'WPBP_PLUGIN_BASENAME' ) && WPBP_PLUGIN_BASENAME !== WP_UNINSTALL_PLUGIN ) {
	return;
}

require_once __DIR__ . '/includes/Core/Settings.php';
require_once __DIR__ . '/includes/Core/Uninstaller.php';

\WPBP\Core\Uninstaller::uninstall();
