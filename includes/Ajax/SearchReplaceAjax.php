<?php
/**
 * Search & Replace page: start a run, then process it in short steps.
 *
 * The run's state (search, replace, tables, cursor) is kept in a transient
 * per user, with a random token so an old browser tab can't continue a run
 * that was replaced by a newer one.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Backup\DatabaseExporter;
use WPBP\Restore\SearchReplaceEngine;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class SearchReplaceAjax {

	private const TRANSIENT = 'wpbp_search_replace_';

	/**
	 * Tables the page offers: every table with this site's prefix except
	 * Wrensafe's own (backup records, jobs and logs, whose file paths must
	 * not change).
	 *
	 * @return string[] Full table names.
	 */
	public static function tables(): array {
		global $wpdb;

		$own = $wpdb->prefix . 'wpbp_';

		return array_values(
			array_filter(
				( new DatabaseExporter() )->get_tables( true ),
				static function ( string $table ) use ( $own ): bool {
					return 0 !== strpos( $table, $own );
				}
			)
		);
	}

	private static function check_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change the database.', 'wrensafe' ) ), 403 );
		}
	}

	private static function key(): string {
		return self::TRANSIENT . get_current_user_id();
	}

	/** A checkbox sent by admin.js as "1" / "0". */
	private static function flag( string $key ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- only called by handlers after check_ajax_referer().
		return isset( $_POST[ $key ] ) && '1' === sanitize_key( wp_unslash( $_POST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::check_capability();

		// The search and replace text are used exactly as typed (they are
		// compared byte for byte with the database, never output as HTML).
		$search  = isset( $_POST['search'] ) && is_string( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- kept exactly as typed; see above.
		$replace = isset( $_POST['replace'] ) && is_string( $_POST['replace'] ) ? wp_unslash( $_POST['replace'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- kept exactly as typed; see above.
		$posted  = isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tables'] ) ) : array();
		$tables  = array_values( array_intersect( self::tables(), $posted ) );

		if ( '' === $search ) {
			wp_send_json_error( array( 'message' => __( 'Enter the text to search for.', 'wrensafe' ) ) );
		}

		if ( $search === $replace ) {
			wp_send_json_error( array( 'message' => __( 'The search and replace text are the same, so nothing would change.', 'wrensafe' ) ) );
		}

		if ( ! $tables ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one table.', 'wrensafe' ) ) );
		}

		// The texts are stored as hex: the state lives in the options table,
		// which the run searches too, and must not match itself.
		$state = array(
			'token'     => wp_generate_password( 16, false ),
			'search'    => bin2hex( $search ),
			'replace'   => bin2hex( $replace ),
			'tables'    => $tables,
			'dry_run'   => self::flag( 'dry_run' ),
			'skip_guid' => self::flag( 'skip_guid' ),
			'index'     => 0,
			'cursor'    => null,
			'seen'      => 0,
			'total'     => max( 1, self::estimate_rows( $tables ) ),
			'changed'   => array(),
			'skipped'   => array(),
		);

		set_transient( self::key(), $state, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'token'  => $state['token'],
				'tables' => count( $tables ),
			)
		);
	}

	public static function step(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::check_capability();

		$state = get_transient( self::key() );
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! is_array( $state ) || ! hash_equals( (string) $state['token'], $token ) ) {
			wp_send_json_error( array( 'message' => __( 'This run is no longer active. Start it again.', 'wrensafe' ) ) );
		}

		$engine   = new SearchReplaceEngine();
		$pairs    = array( array( self::text( $state, 'search' ), self::text( $state, 'replace' ) ) );
		$deadline = microtime( true ) + self::time_budget();
		$count    = count( $state['tables'] );

		while ( $state['index'] < $count && microtime( true ) < $deadline ) {
			$table = $state['tables'][ $state['index'] ];

			if ( null === $state['cursor'] && null === $engine->get_primary_key( $table ) ) {
				// Only worth mentioning when the table could hold the text.
				if ( self::has_text_columns( $table ) ) {
					$state['skipped'][] = $table;
				}
				++$state['index'];
				continue;
			}

			$skip = $state['skip_guid'] && preg_match( '/posts$/', $table ) ? array( 'guid' ) : array();

			try {
				$batch = $engine->process_table_after( $table, $state['cursor'], $pairs, (bool) $state['dry_run'], $skip );
			} catch ( \Throwable $e ) {
				delete_transient( self::key() );
				Logger::error( sprintf( 'search_replace_failed: stopped in %s: %s', $table, $e->getMessage() ) );
				/* translators: 1: table name, 2: error message */
				wp_send_json_error( array( 'message' => sprintf( __( 'Stopped in %1$s: %2$s', 'wrensafe' ), $table, $e->getMessage() ) ) );
			}

			$state['seen'] += (int) ( $batch['rows_seen'] ?? 0 );

			if ( $batch['rows_changed'] > 0 ) {
				$state['changed'][ $table ] = ( $state['changed'][ $table ] ?? 0 ) + (int) $batch['rows_changed'];
			}

			if ( $batch['done'] ) {
				++$state['index'];
				$state['cursor'] = null;
			} else {
				$state['cursor'] = $batch['cursor'];
			}
		}

		$done    = $state['index'] >= $count;
		$current = $done ? '' : $state['tables'][ $state['index'] ];

		if ( $done ) {
			delete_transient( self::key() );
			self::finish( $state );
		} else {
			set_transient( self::key(), $state, HOUR_IN_SECONDS );
		}

		$results = array();

		foreach ( $state['changed'] as $table => $rows ) {
			$results[] = array(
				'table' => $table,
				'rows'  => $rows,
			);
		}

		wp_send_json_success(
			array(
				'summary' => $done ? self::summary( $state ) : '',
				'done'    => $done,
				'percent' => $done ? 100 : min( 99, (int) floor( 100 * $state['seen'] / max( $state['total'], $state['seen'] + 1 ) ) ),
				'table'   => $current,
				'dry_run' => (bool) $state['dry_run'],
				'rows'    => array_sum( $state['changed'] ),
				'results' => $results,
				'skipped' => $state['skipped'],
			)
		);
	}

	/** Log a real run and clear cached options it may have changed. */
	private static function finish( array $state ): void {
		if ( $state['dry_run'] ) {
			return;
		}

		wp_cache_flush();

		Logger::success(
			sprintf(
				'search_replace_completed: replaced "%s" with "%s" in %d rows across %d tables.',
				self::short( self::text( $state, 'search' ) ),
				self::short( self::text( $state, 'replace' ) ),
				array_sum( $state['changed'] ),
				count( $state['changed'] )
			)
		);
	}

	/** What the finished run found or changed, for the page. */
	private static function summary( array $state ): string {
		$rows  = array_sum( $state['changed'] );
		$count = number_format_i18n( $rows );

		if ( $state['dry_run'] ) {
			/* translators: %s: number of rows */
			$text = $rows ? sprintf( _n( '%s row would change. Nothing has been saved yet.', '%s rows would change. Nothing has been saved yet.', $rows, 'wrensafe' ), $count ) : __( 'No rows contain this text, so nothing would change.', 'wrensafe' );
		} else {
			/* translators: %s: number of rows */
			$text = $rows ? sprintf( _n( '%s row was changed.', '%s rows were changed.', $rows, 'wrensafe' ), $count ) : __( 'No rows contained this text, so nothing was changed.', 'wrensafe' );
		}

		if ( $state['skipped'] ) {
			/* translators: %s: comma-separated table names */
			$text .= ' ' . sprintf( __( 'Skipped because they have no single-column primary key: %s.', 'wrensafe' ), implode( ', ', $state['skipped'] ) );
		}

		return $text;
	}

	/** The search or replace text from the stored state. */
	private static function text( array $state, string $key ): string {
		$hex = (string) $state[ $key ];

		return '' === $hex ? '' : (string) hex2bin( $hex );
	}

	/** Whether a table has a text column (char, text, blob, JSON, enum…). */
	private static function has_text_columns( string $table ): bool {
		global $wpdb;

		$types = $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`', 1 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted; no WordPress API for a table's columns.

		foreach ( (array) $types as $type ) {
			if ( preg_match( '/char|text|blob|json|enum|set|binary/i', (string) $type ) ) {
				return true;
			}
		}

		return false;
	}

	private static function short( string $text ): string {
		return mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 80 ) . '…' : $text;
	}

	/** Approximate row count, only to show progress. */
	private static function estimate_rows( array $tables ): int {
		global $wpdb;

		$rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- row estimates for the progress bar; no WordPress API.
		$total = 0;

		foreach ( (array) $rows as $row ) {
			if ( in_array( (string) $row['Name'], $tables, true ) ) {
				$total += (int) $row['Rows'];
			}
		}

		return $total;
	}

	/** Seconds one step may run, leaving room under max_execution_time. */
	private static function time_budget(): float {
		$max = (int) ini_get( 'max_execution_time' );

		return ( $max > 0 && $max < 20 ) ? max( 2, $max - 5 ) : 8;
	}
}
