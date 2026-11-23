<?php
/**
 * Why an update wasn't installed or was rolled back, in words a site owner can
 * act on. WordPress keeps the real cause of a failed download ("Forbidden") in
 * the error's data and only says "Download failed." in its message.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

defined( 'ABSPATH' ) || exit;

class UpdateFailure {

	/** Error data: who serves the download. */
	const SOURCE = 'wpbp_source';

	/** Error data: a plugin refused the download itself (upgrader_pre_download). */
	const REFUSED = 'wpbp_refused';

	const DIRECTORY = 'directory';
	const PUBLISHER = 'publisher';

	/** Who serves a download. Only the answer is kept: a download address can carry a license key. */
	public static function source_of( string $package ): string {
		$host = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );

		return in_array( $host, array( 'downloads.wordpress.org', 'wordpress.org' ), true ) ? self::DIRECTORY : self::PUBLISHER;
	}

	/** Keep with the error what explain() needs to know about its download. */
	public static function mark( \WP_Error $error, string $source, bool $refused ): void {
		$error->add_data( $source, self::SOURCE );

		if ( $refused ) {
			$error->add_data( true, self::REFUSED );
		}
	}

	/**
	 * The cause of a failed update.
	 *
	 * @return array{kind:string,label:string,text:string,fix:string,detail:string}
	 */
	public static function explain( \WP_Error $error ): array {
		$code      = (string) $error->get_error_code();
		$message   = self::clean( (string) $error->get_error_message() );
		$data      = $error->get_error_data( $code );
		$extra     = is_string( $data ) ? self::clean( $data ) : '';
		$detail    = trim( $message . ( '' !== $extra && $extra !== $message ? ' ' . $extra : '' ) );
		$publisher = self::DIRECTORY !== $error->get_error_data( self::SOURCE );

		if ( $error->get_error_data( self::REFUSED ) ) {
			return self::refused( $message, $detail );
		}

		if ( 'no_package' === $code ) {
			return self::license( __( 'The publisher didn’t provide a download for this update. For a premium plugin, this usually means its license is inactive or expired.', 'wrensafe' ), $detail );
		}

		if ( 'download_failed' === $code ) {
			return self::download( $extra, $publisher, $detail );
		}

		if ( 0 === strpos( $code, 'incompatible_archive' ) || in_array( $code, array( 'empty_archive_pclzip', 'stat_failed_ziparchive', 'extract_failed_ziparchive' ), true ) ) {
			return $publisher
				? self::cause( 'package', __( 'Invalid download', 'wrensafe' ), __( 'The file the publisher sent isn’t a valid update. With a premium plugin, this often means the license check failed and a message was sent instead of the plugin.', 'wrensafe' ), __( 'Check the license in its settings, then update again.', 'wrensafe' ), $detail )
				: self::cause( 'package', __( 'Invalid download', 'wrensafe' ), __( 'The downloaded file was damaged or incomplete.', 'wrensafe' ), __( 'Try the update again.', 'wrensafe' ), $detail );
		}

		if ( in_array( $code, array( 'incompatible_php_required_version', 'incompatible_wp_required_version' ), true ) ) {
			return self::cause( 'requirements', __( 'Requirements not met', 'wrensafe' ), '' !== $message ? $message : __( 'This version needs a newer PHP or WordPress.', 'wrensafe' ), __( 'Update WordPress, or ask your host for a newer PHP version, then try again.', 'wrensafe' ), $detail );
		}

		if ( 'disk_full_unzip_file' === $code ) {
			return self::disk( $detail );
		}

		if ( 0 === strpos( $code, 'fs_' ) || 1 === preg_match( '/^(?:mkdir|copy|dirlist)_failed/', $code ) || in_array( $code, array( 'files_not_writable', 'folder_exists', 'remove_old_failed', 'source_read_failed', 'new_source_read_failed', 'wpbp_no_filesystem' ), true ) ) {
			return self::cause( 'files', __( 'File permissions', 'wrensafe' ), __( 'WordPress couldn’t write the new files to the plugin’s folder.', 'wrensafe' ), __( 'Ask your host to check the file permissions of wp-content, then try again.', 'wrensafe' ), $detail );
		}

		if ( 'wpbp_up_to_date' === $code ) {
			return self::cause( 'changed', __( 'Update no longer listed', 'wrensafe' ), $message, __( 'Select Check again to refresh the list of updates.', 'wrensafe' ), '' );
		}

		return self::cause( 'unknown', __( 'Not installed', 'wrensafe' ), '' !== $message ? $message : __( 'WordPress didn’t install the new version.', 'wrensafe' ), __( 'Try the update again. If it keeps failing, the Activity Logs have the details.', 'wrensafe' ), '' );
	}

	/** WordPress reported nothing at all. */
	public static function not_installed(): array {
		return self::cause( 'unknown', __( 'Not installed', 'wrensafe' ), __( 'WordPress didn’t install the new version.', 'wrensafe' ), __( 'Try the update again. If it keeps failing, the Activity Logs have the details.', 'wrensafe' ), '' );
	}

	/**
	 * The cause of a rollback, from what the site check found. The item's message says what happened.
	 *
	 * @param string              $why     'fatal', 'server_error', 'blank', 'unreachable', 'timeout', or ''.
	 * @param array<string,mixed> $earlier The cause an update that failed part-way already has.
	 * @return array<string,string> Empty when the rollback wasn't caused by a problem (you chose it).
	 */
	public static function rolled_back( string $why, array $earlier = array() ): array {
		if ( '' === $why ) {
			if ( empty( $earlier['label'] ) ) {
				return array();
			}

			$earlier['text'] = '';

			return $earlier;
		}

		$labels = array(
			'fatal'        => __( 'Fatal error', 'wrensafe' ),
			'server_error' => __( 'Server error', 'wrensafe' ),
			'blank'        => __( 'Empty page', 'wrensafe' ),
			'unreachable'  => __( 'Site not loading', 'wrensafe' ),
			'timeout'      => __( 'Site too slow', 'wrensafe' ),
		);

		return self::cause(
			'site',
			$labels[ $why ] ?? __( 'Site problem', 'wrensafe' ),
			'',
			'timeout' === $why
				? __( 'The previous version is active. Try this update again when the site is less busy.', 'wrensafe' )
				: __( 'The previous version is active. Check the changelog or ask the publisher before trying this update again.', 'wrensafe' ),
			''
		);
	}

	/** The update broke the site and its previous version couldn't be put back. */
	public static function not_restored(): array {
		return self::cause( 'critical', __( 'Needs your attention', 'wrensafe' ), '', '', '' );
	}

	/**
	 * The small labels that sum up a run: what worked stays green, and what didn't is marked next to it.
	 *
	 * @param array<string,int>                $summary
	 * @param array<int,array<string,mixed>>   $items   Reported items (with 'state' and 'cause').
	 * @return array<int,array{tone:string,text:string}>
	 */
	public static function pills( array $summary, array $items, bool $stopped ): array {
		$out = array();

		if ( $summary['updated'] ) {
			/* translators: %d: number of plugins (or themes) */
			$out[] = self::pill( 'good', sprintf( _n( '%d updated', '%d updated', $summary['updated'], 'wrensafe' ), $summary['updated'] ) );
		}

		if ( $summary['rolled_back'] ) {
			/* translators: %d: number of plugins (or themes) */
			$text  = sprintf( _n( '%d rolled back', '%d rolled back', $summary['rolled_back'], 'wrensafe' ), $summary['rolled_back'] );
			$out[] = self::pill( 'warn', $text . self::shared_label( $items, 'rolled_back' ) );
		}

		if ( $summary['failed'] ) {
			/* translators: %d: number of plugins (or themes) */
			$text  = sprintf( _n( '%d failed', '%d failed', $summary['failed'], 'wrensafe' ), $summary['failed'] );
			$out[] = self::pill( 'bad', $text . self::shared_label( $items, 'failed' ) );
		}

		$left = $summary['skipped'] + $summary['cancelled'];

		if ( $left ) {
			/* translators: %d: number of plugins (or themes) */
			$out[] = self::pill( 'muted', sprintf( _n( '%d not updated', '%d not updated', $left, 'wrensafe' ), $left ) );
		}

		if ( $stopped && ! $summary['failed'] && ! $summary['rolled_back'] ) {
			$out[] = self::pill( 'bad', __( 'Stopped', 'wrensafe' ) );
		}

		return $out ? $out : array( self::pill( 'muted', __( 'Nothing was updated', 'wrensafe' ) ) );
	}

	/** " · License needed" when every item in this state has the same cause. */
	private static function shared_label( array $items, string $state ): string {
		$labels = array();

		foreach ( $items as $item ) {
			$item_state = (string) ( $item['state'] ?? '' );

			if ( $item_state === $state ) {
				$labels[] = (string) ( $item['cause']['label'] ?? '' );
			}
		}

		$labels = array_unique( $labels );

		return 1 === count( $labels ) && '' !== $labels[0] ? ' · ' . $labels[0] : '';
	}

	/** @return array{tone:string,text:string} */
	private static function pill( string $tone, string $text ): array {
		return array(
			'tone' => $tone,
			'text' => $text,
		);
	}

	/** Text for the screens and the logs, without a download address (it can carry a license key). */
	public static function clean( string $text ): string {
		// Only the start is shown (240 characters); a shorter text also keeps the patterns below fast.
		$text = html_entity_decode( rawurldecode( mb_substr( $text, 0, 2000 ) ), ENT_QUOTES );

		// First any value given to a key, token or password, wherever it appears: license_key=…, "token": "…",
		// Bearer ….
		$name = '[\w-]{0,100}(?:licen[sc]e|key|token|secret|pass(?:word)?|sig(?:nature)?)[\w-]{0,100}';
		// A closing bracket belongs to a value or an address unless it ends it: "(see https://…)" keeps it.
		$close = '\)(?=[^\s.,;:!?)"\'<>])';
		$text  = (string) preg_replace(
			array(
				'~\b(' . $name . ')\s*=\s*(?:"[^"]*"|\'[^\']*\'|(?:[^\s&"\'<>,;)]|' . $close . ')+)~i',
				'~(["\'])(' . $name . ')\1\s*:\s*(["\'])[^"\']*\3~i',
				'~\b(Bearer|Basic)\s+(?=[\w.+/=-]*[\d+/=])[\w.+/=-]{8,}~i',
			),
			array( '$1=…', '$1$2$1: …', '$1 …' ),
			$text
		);

		// Any address, whatever its scheme: plain, JSON-escaped once or twice (https:\/\/…), percent-encoded,
		// or without a scheme (//host/… or \/\/host/…).
		$text = (string) preg_replace_callback(
			'~(?:\b[a-z][a-z0-9+.\-]*:(?:\\\\{0,2}/){2}|(?<![:\w/\\\\])(?:\\\\{0,2}/){2})(?:[^\s"\'<>)]|' . $close . ')+~i',
			static function ( array $found ): string {
				$url = str_replace( '\\', '', $found[0] );

				return (string) wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
			},
			wp_strip_all_tags( $text )
		);

		return wp_html_excerpt( trim( (string) preg_replace( '/\s+/', ' ', $text ) ), 240, '…' );
	}

	/** A plugin stopped its own download and said why: its words are the most exact ones. */
	private static function refused( string $message, string $detail ): array {
		if ( 1 === preg_match( '/licen[sc]e|subscription|expired|renew|activat/i', $message ) ) {
			return self::license( $message, '' );
		}

		return self::cause( 'plugin', __( 'Refused by the plugin', 'wrensafe' ), '' !== $message ? $message : __( 'The plugin stopped its own update.', 'wrensafe' ), __( 'Follow its message, or contact its publisher.', 'wrensafe' ), '' !== $message ? '' : $detail );
	}

	private static function license( string $text, string $detail ): array {
		return self::cause( 'license', __( 'License needed', 'wrensafe' ), $text, __( 'Activate or renew the license in its settings, then update again.', 'wrensafe' ), $detail );
	}

	private static function disk( string $detail ): array {
		return self::cause( 'disk', __( 'Disk full', 'wrensafe' ), __( 'The server ran out of disk space while installing the update.', 'wrensafe' ), __( 'Free up some space, for example old backups, then try again.', 'wrensafe' ), $detail );
	}

	/** Why a download failed, from the server's answer or the connection error. */
	private static function download( string $why, bool $publisher, string $detail ): array {
		$low   = strtolower( $why );
		$retry = __( 'Try again. If it keeps happening, ask your host to check outgoing connections.', 'wrensafe' );

		if ( 1 === preg_match( '/^(?:unauthorized|payment required|forbidden)\b/', $low ) ) {
			return $publisher
				? self::license( __( 'The publisher refused the download. This usually means the license is inactive, expired, or not activated for this site’s address.', 'wrensafe' ), $detail )
				: self::cause( 'blocked', __( 'Download blocked', 'wrensafe' ), __( 'WordPress.org refused the download. A firewall on this server may be blocking it.', 'wrensafe' ), __( 'Try again later. If it keeps happening, ask your host to check the firewall.', 'wrensafe' ), $detail );
		}

		if ( 1 === preg_match( '/^(?:not found|gone)\b/', $low ) ) {
			return self::cause(
				'missing',
				__( 'Download not found', 'wrensafe' ),
				$publisher ? __( 'The publisher’s download link no longer works. With a premium plugin, this can also mean its license has expired.', 'wrensafe' ) : __( 'WordPress.org no longer has this download.', 'wrensafe' ),
				__( 'Select Check again for a fresh link. If it still fails, contact the publisher.', 'wrensafe' ),
				$detail
			);
		}

		if ( 1 === preg_match( '/^(?:too many requests|internal server error|bad gateway|service unavailable|gateway timeout)\b/', $low ) ) {
			return self::cause( 'busy', __( 'Server busy', 'wrensafe' ), __( 'The download server couldn’t send the file right now.', 'wrensafe' ), __( 'Try again in a few minutes.', 'wrensafe' ), $detail );
		}

		if ( 1 === preg_match( '/no space|curl error 23\b/', $low ) ) {
			return self::disk( $detail );
		}

		if ( 1 === preg_match( '/curl error 28\b|timed out/', $low ) ) {
			return self::cause( 'connection', __( 'Connection problem', 'wrensafe' ), __( 'The download took too long and timed out.', 'wrensafe' ), $retry, $detail );
		}

		if ( 1 === preg_match( '/curl error (?:35|51|58|60)\b|ssl|certificate/', $low ) ) {
			return self::cause( 'connection', __( 'Connection problem', 'wrensafe' ), __( 'The secure connection to the download server failed.', 'wrensafe' ), $retry, $detail );
		}

		if ( 1 === preg_match( '/curl error (?:6|7)\b|could not resolve|failed to connect|connection refused/', $low ) ) {
			return self::cause( 'connection', __( 'Connection problem', 'wrensafe' ), __( 'This server couldn’t connect to the download address.', 'wrensafe' ), $retry, $detail );
		}

		return self::cause( 'download', __( 'Download failed', 'wrensafe' ), __( 'The new version couldn’t be downloaded.', 'wrensafe' ), $retry, $detail );
	}

	/** @return array{kind:string,label:string,text:string,fix:string,detail:string} */
	private static function cause( string $kind, string $label, string $text, string $fix, string $detail ): array {
		return array(
			'kind'   => $kind,
			'label'  => $label,
			'text'   => $text,
			'fix'    => $fix,
			'detail' => $detail,
		);
	}
}
