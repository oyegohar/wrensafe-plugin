<?php
/**
 * Uninstall handler for Wrensafe.
 *
 * @package WPBP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require __DIR__ . '/includes/Core/Settings.php';
require __DIR__ . '/includes/Core/Uninstaller.php';

\WPBP\Core\Uninstaller::uninstall();
