<?php
/**
 * Fine-grained Wrensafe capabilities, layered on top of WordPress's
 * own role system.
 *
 * Every capability is granted to the administrator role on activation, so
 * existing admins never lose access. The Team Access settings tab lets the
 * admin additionally grant specific capabilities to other users — Free
 * Scope is "admin-only by default, extensible by add-ons" per the
 * development plan.
 *
 * @package WPBP\Security
 */

namespace WPBP\Security;

defined( 'ABSPATH' ) || exit;

class CapabilityManager {

	const VIEW_BACKUPS            = 'wpbp_view_backups';
	const CREATE_BACKUPS          = 'wpbp_create_backups';
	const DOWNLOAD_BACKUPS        = 'wpbp_download_backups';
	const DELETE_BACKUPS          = 'wpbp_delete_backups';
	const RESTORE_BACKUPS         = 'wpbp_restore_backups';
	const MANAGE_CLOUD            = 'wpbp_manage_cloud';
	const MANAGE_SCHEDULES        = 'wpbp_manage_schedules';
	const MANAGE_SETTINGS         = 'wpbp_manage_settings';
	const VIEW_DASHBOARD          = 'wpbp_view_dashboard';
	const VIEW_LOGS               = 'wpbp_view_logs';
	const MANAGE_STAGING          = 'wpbp_manage_staging';
	const MANAGE_DIRECT_MIGRATION = 'wpbp_manage_direct_migration';
	const MANAGE_REPORTS          = 'wpbp_manage_reports';
	const MANAGE_TEAM_ACCESS      = 'wpbp_manage_team_access';
	const MANAGE_SECURITY         = 'wpbp_manage_security';

	public static function all_capabilities(): array {
		return array(
			self::VIEW_BACKUPS,
			self::CREATE_BACKUPS,
			self::DOWNLOAD_BACKUPS,
			self::DELETE_BACKUPS,
			self::RESTORE_BACKUPS,
			self::MANAGE_CLOUD,
			self::MANAGE_SCHEDULES,
			self::MANAGE_SETTINGS,
			self::VIEW_DASHBOARD,
			self::VIEW_LOGS,
			self::MANAGE_STAGING,
			self::MANAGE_DIRECT_MIGRATION,
			self::MANAGE_REPORTS,
			self::MANAGE_TEAM_ACCESS,
			self::MANAGE_SECURITY,
		);
	}

	/**
	 * Named role bundles shown in the Team Access UI — each is just a
	 * convenience set of the capabilities above, not a real WP role.
	 */
	public static function role_bundles(): array {
		return array(
			'backup_viewer'    => array( __( 'Backup Viewer', 'wrensafe' ), array( self::VIEW_BACKUPS ) ),
			'backup_creator'   => array( __( 'Backup Creator', 'wrensafe' ), array( self::VIEW_BACKUPS, self::CREATE_BACKUPS ) ),
			'backup_manager'   => array( __( 'Backup Manager', 'wrensafe' ), array( self::VIEW_BACKUPS, self::CREATE_BACKUPS, self::DOWNLOAD_BACKUPS, self::DELETE_BACKUPS ) ),
			'restore_manager'  => array( __( 'Restore Manager', 'wrensafe' ), array( self::VIEW_BACKUPS, self::RESTORE_BACKUPS ) ),
			'cloud_manager'    => array( __( 'Cloud Manager', 'wrensafe' ), array( self::MANAGE_CLOUD ) ),
			'settings_manager' => array( __( 'Settings Manager', 'wrensafe' ), array( self::MANAGE_SETTINGS ) ),
			'full_admin'       => array( __( 'Full Admin', 'wrensafe' ), self::all_capabilities() ),
		);
	}

	/**
	 * Administrators always hold every Wrensafe capability, whatever the
	 * stored roles say. Restoring a database from a site that never had
	 * Wrensafe (for example a backup made by another plugin) replaces the
	 * roles option, which removed these capabilities and hid Wrensafe's
	 * menu while the plugin stayed active.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_to_administrators' ) );
		add_filter( 'map_meta_cap', array( self::class, 'require_super_admin' ), 10, 3 );
		add_action( 'admin_init', array( self::class, 'repair_admin_role' ) );
	}

	/**
	 * On multisite only super admins hold Wrensafe's capabilities. A site's
	 * own administrator must not restore files, run Search & Replace, import
	 * from a server path or change the backup folder: on a network those
	 * reach every site (and let them run PHP), which is a super admin's job.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @return string[]
	 */
	public static function require_super_admin( $caps, $cap, $user_id ) {
		if ( is_multisite() && is_string( $cap ) && in_array( $cap, self::all_capabilities(), true ) && ! is_super_admin( (int) $user_id ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Whether the current user is a full administrator: one who can manage
	 * options on a single site, a super admin on multisite. For the few
	 * actions Team Access never grants (importing a backup from a path on
	 * the server).
	 */
	public static function is_site_administrator(): bool {
		return is_multisite() ? is_super_admin() : current_user_can( 'manage_options' );
	}

	/**
	 * @param array<string,bool> $allcaps
	 * @return array<string,bool>
	 */
	public static function grant_to_administrators( $allcaps ) {
		// On multisite super admins already pass every check, and nobody else
		// gets these capabilities (see require_super_admin()).
		if ( ! is_multisite() && is_array( $allcaps ) && ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::all_capabilities() as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	/** Put the capabilities back on the stored administrator role after a restore. */
	public static function repair_admin_role(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role && ! $role->has_cap( self::VIEW_DASHBOARD ) ) {
			self::add_to_admin_role();
		}
	}

	/**
	 * Grant every capability to administrators — run on activation.
	 */
	public static function add_to_admin_role(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		foreach ( self::all_capabilities() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	public static function grant_bundle_to_user( int $user_id, string $bundle_key ): bool {
		$bundles = self::role_bundles();

		if ( ! isset( $bundles[ $bundle_key ] ) ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return false;
		}

		// Reset, then grant exactly this bundle's capabilities.
		foreach ( self::all_capabilities() as $cap ) {
			$user->remove_cap( $cap );
		}

		foreach ( $bundles[ $bundle_key ][1] as $cap ) {
			$user->add_cap( $cap );
		}

		return true;
	}

	public static function revoke_all_from_user( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return;
		}

		foreach ( self::all_capabilities() as $cap ) {
			$user->remove_cap( $cap );
		}
	}

	/**
	 * Capability check helper — administrators always pass via
	 * add_to_admin_role(), other users only if explicitly granted. On
	 * multisite only super admins pass (see require_super_admin()).
	 */
	public static function current_user_can( string $capability ): bool {
		if ( is_multisite() ) {
			return is_super_admin() && current_user_can( $capability );
		}

		return current_user_can( $capability ) || current_user_can( 'manage_options' );
	}
}
