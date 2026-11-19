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

// Wrensafe's classes need PHP 7.4 (see wrensafe.php) and can't even be loaded
// on an older PHP: leave the data for an uninstall after a PHP upgrade.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

require_once __DIR__ . '/includes/Core/Settings.php';
require_once __DIR__ . '/includes/Core/Uninstaller.php';

\WPBP\Core\Uninstaller::uninstall();
