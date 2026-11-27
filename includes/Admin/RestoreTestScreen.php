<?php
/**
 * Backup Browser → "Test restore": the script, its text, and each backup's latest result as a badge.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Restore\RestoreTest;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

final class RestoreTestScreen {

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function available(): bool {
		return CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS );
	}

	public static function enqueue( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'wrensafe-browser' ) || ! self::available() ) {
			return;
		}

		wp_enqueue_style( 'wpbp-restore-test', WPBP_PLUGIN_URL . 'assets/admin/css/restore-test.css', array( 'wpbp-admin' ), WPBP_VERSION );
		wp_style_add_data( 'wpbp-restore-test', 'rtl', 'replace' );
		wp_enqueue_script( 'wpbp-restore-test', WPBP_PLUGIN_URL . 'assets/admin/js/restore-test.js', array( 'jquery', 'wpbp-admin' ), WPBP_VERSION, true );
		wp_localize_script(
			'wpbp-restore-test',
			'wpbpRestoreTest',
			array(
				'title'       => __( 'Restore test', 'wrensafe' ),
				'intro'       => __( 'Wrensafe restores this backup into a temporary copy to prove it works, then removes the copy. Your site isn’t changed and stays online.', 'wrensafe' ),
				'space'       => __( 'It needs about as much free database space as the backup’s database, like a restore.', 'wrensafe' ),
				'password'    => __( 'This backup is encrypted. Enter its password:', 'wrensafe' ),
				'needPass'    => __( 'Enter the backup’s password.', 'wrensafe' ),
				'start'       => __( 'Start the test', 'wrensafe' ),
				'stop'        => __( 'Stop the test', 'wrensafe' ),
				'close'       => __( 'Close', 'wrensafe' ),
				'cancel'      => __( 'Cancel', 'wrensafe' ),
				'stages'      => array(
					__( 'Check every file', 'wrensafe' ),
					__( 'Import the database into a temporary copy', 'wrensafe' ),
					__( 'Check the restored copy', 'wrensafe' ),
				),
				'passed'      => __( 'This backup restores', 'wrensafe' ),
				'failed'      => __( 'This backup wouldn’t restore', 'wrensafe' ),
				'stopped'     => __( 'Test stopped. The temporary copy was removed.', 'wrensafe' ),
				'lost'        => __( 'The connection to the server was lost. Start the test again.', 'wrensafe' ),
				'leave'       => __( 'A restore test is running. Leave anyway? It will stop and remove its copy.', 'wrensafe' ),
				'badgeOk'     => __( 'Restore-tested', 'wrensafe' ),
				'badgeFailed' => __( 'Restore test failed', 'wrensafe' ),
			)
		);
	}

	/** The latest result of a backup's test, next to its status. */
	public static function badge( int $backup_id ): void {
		$result = RestoreTest::results()[ $backup_id ] ?? null;

		if ( null === $result ) {
			return;
		}

		/* translators: %s: when the test ran */
		$when = sprintf( __( 'on %s', 'wrensafe' ), wp_date( (string) get_option( 'date_format' ), (int) $result['at'] ) );
		?>
		<span class="wpbp-rt-badge <?php echo $result['ok'] ? 'is-ok' : 'is-failed'; ?>" title="<?php echo esc_attr( (string) $result['message'] . ' (' . $when . ')' ); ?>">
			<span class="dashicons <?php echo $result['ok'] ? 'dashicons-shield-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
			<?php echo esc_html( $result['ok'] ? __( 'Restore-tested', 'wrensafe' ) : __( 'Restore test failed', 'wrensafe' ) ); ?>
		</span>
		<?php
	}
}
