<?php
/**
 * What AI assistants ask a person to do (a restore, a rollback, a Search & Replace): kept for a day, shown on
 * Wrensafe's screens to those who may do it. "Review" opens the usual screen, filled in, where the person runs it
 * themselves with its usual checks; "Decline" says no. An assistant can only ask, and read the answer.
 *
 * @package WPBP\Mcp
 */

namespace WPBP\Mcp;

use WPBP\Admin\AdminMenu;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

final class Requests {

	const OPTION = 'wpbp_mcp_requests';

	/** Requests waiting at once, and how long one waits. */
	const MAX_PENDING = 10;
	const LIFETIME    = DAY_IN_SECONDS;

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_wpbp_mcp_request', array( self::class, 'answer' ) );
	}

	/** @return array<string,array<string,mixed>> Id => request, newest last. */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		foreach ( $all as $id => $request ) {
			if ( 'pending' === $request['status'] && (int) $request['at'] + self::LIFETIME < time() ) {
				$all[ $id ]['status'] = 'expired';
			}
		}

		return $all;
	}

	private static function save( array $all ): void {
		// The newest 50.
		update_option( self::OPTION, array_slice( $all, -50, null, true ), false );
	}

	/** @return array{request_id:string,status:string,message:string} */
	public static function add( string $type, array $args, string $reason ): array {
		$all     = self::all();
		$pending = array_filter(
			$all,
			static function ( array $request ): bool {
				return 'pending' === $request['status'];
			}
		);

		if ( count( $pending ) >= self::MAX_PENDING ) {
			throw new \RuntimeException( esc_html__( 'Too many requests are waiting for approval. Wait until a person has answered them.', 'wrensafe' ) );
		}

		$id         = strtolower( wp_generate_password( 12, false ) );
		$all[ $id ] = array(
			'id'     => $id,
			'type'   => $type,
			'args'   => $args,
			'reason' => sanitize_text_field( wp_html_excerpt( $reason, 300 ) ),
			'user'   => get_current_user_id(),
			'at'     => time(),
			'status' => 'pending',
		);

		self::save( $all );
		Logger::info( 'ai_request: ' . sprintf( 'An AI assistant (signed in as %1$s) asks for approval: %2$s.', wp_get_current_user()->user_login, self::describe( $all[ $id ] ) ) );

		return array(
			'request_id' => $id,
			'status'     => 'pending',
			'message'    => __( 'Waiting for a person to approve it in wp-admin (Wrensafe). Nothing has been changed. Check the answer with wrensafe/list-requests.', 'wrensafe' ),
		);
	}

	/** @return array<int,array<string,mixed>> For an assistant: what was asked and the answer, newest first. */
	public static function for_assistant(): array {
		$out = array();

		foreach ( array_reverse( self::all() ) as $request ) {
			$out[] = array(
				'request_id' => $request['id'],
				'what'       => self::describe( $request ),
				'status'     => $request['status'],
				'asked_at'   => gmdate( 'c', (int) $request['at'] ),
			);
		}

		return array_slice( $out, 0, 20 );
	}

	/** The request in a sentence. */
	public static function describe( array $request ): string {
		switch ( $request['type'] ) {
			case 'restore':
				$backup = \WPBP\Core\Database\BackupsRepository::find( (int) ( $request['args']['backup_id'] ?? 0 ) );
				/* translators: 1: backup id, 2: backup file name */
				return sprintf( __( 'restore backup #%1$d (%2$s)', 'wrensafe' ), (int) ( $request['args']['backup_id'] ?? 0 ), $backup ? $backup['file_name'] : __( 'no longer on the server', 'wrensafe' ) );
			case 'rollback':
				return __( 'undo the last restore (roll back)', 'wrensafe' );
			case 'search_replace':
				/* translators: 1: text to find, 2: replacement */
				return sprintf( __( 'replace “%1$s” with “%2$s” in the database', 'wrensafe' ), wp_html_excerpt( (string) $request['args']['search'], 80, '…' ), wp_html_excerpt( (string) $request['args']['replace'], 80, '…' ) );
		}

		return (string) $request['type'];
	}

	/** An approved Search & Replace request, to fill in its screen. */
	public static function approved( string $id ): ?array {
		$request = self::all()[ $id ] ?? null;

		return $request && 'approved' === $request['status'] ? $request : null;
	}

	/** Waiting requests, on Wrensafe's screens, for those who may run them. */
	public static function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! AdminMenu::is_plugin_screen( (string) $screen->id ) || ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			return;
		}

		foreach ( self::all() as $request ) {
			if ( 'pending' !== $request['status'] ) {
				continue;
			}

			$user = get_userdata( (int) $request['user'] );
			$link = static function ( string $answer ) use ( $request ): string {
				return wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_mcp_request&do=' . $answer . '&id=' . $request['id'] ), 'wpbp_mcp_request_' . $request['id'] );
			};
			?>
			<div class="notice notice-warning wpbp-notice wpbp-ai-request">
				<p>
					<strong><?php esc_html_e( 'An AI assistant asks for your approval', 'wrensafe' ); ?></strong><br />
					<?php
					/* translators: 1: what it asks, 2: user it signed in as */
					echo esc_html( sprintf( __( 'It asks to %1$s (signed in as %2$s).', 'wrensafe' ), self::describe( $request ), $user ? $user->user_login : '?' ) );
					?>
					<?php if ( '' !== $request['reason'] ) : ?>
						<br /><em><?php echo esc_html( $request['reason'] ); ?></em>
					<?php endif; ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $link( 'approve' ) ); ?>"><?php esc_html_e( 'Review and do it myself', 'wrensafe' ); ?></a>
					<a class="button" href="<?php echo esc_url( $link( 'decline' ) ); ?>"><?php esc_html_e( 'Decline', 'wrensafe' ); ?></a>
					<span class="description"><?php esc_html_e( 'Approving opens the usual screen. Nothing changes until you run it there.', 'wrensafe' ); ?></span>
				</p>
			</div>
			<?php
		}
	}

	/** "Review" or "Decline". */
	public static function answer(): void {
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';

		check_admin_referer( 'wpbp_mcp_request_' . $id );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wrensafe' ), '', array( 'response' => 403 ) );
		}

		$all     = self::all();
		$request = $all[ $id ] ?? null;
		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

		if ( null === $request || 'pending' !== $request['status'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wrensafe' ) );
			exit;
		}

		$all[ $id ]['status'] = 'approve' === $do ? 'approved' : 'declined';
		$all[ $id ]['by']     = get_current_user_id();
		self::save( $all );

		Logger::info( ( 'approve' === $do ? 'ai_request_approved: ' : 'ai_request_declined: ' ) . sprintf( '%1$s %2$s the AI assistant’s request to %3$s.', wp_get_current_user()->user_login, 'approve' === $do ? 'approved' : 'declined', self::describe( $request ) ) );

		if ( 'approve' !== $do ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wrensafe' ) );
			exit;
		}

		$targets = array(
			'restore'        => admin_url( 'admin.php?page=wrensafe-import-restore&backup_id=' . (int) ( $request['args']['backup_id'] ?? 0 ) ),
			'rollback'       => admin_url( 'admin.php?page=wrensafe-rollback' ),
			'search_replace' => admin_url( 'admin.php?page=wrensafe-search-replace&wpbp_request=' . $id ),
		);

		wp_safe_redirect( $targets[ $request['type'] ] ?? admin_url( 'admin.php?page=wrensafe' ) );
		exit;
	}
}
