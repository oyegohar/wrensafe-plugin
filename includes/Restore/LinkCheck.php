<?php
/**
 * Links and media after a restore or a move.
 *
 * Finds, in posts, pages, page-builder data, settings and term data:
 *
 *  - links with a server folder in their address
 *    ("https://site.com//var/www/html/wp-content/uploads/logo.png"), left by
 *    Wrensafe 1.2.6 and earlier when it restored a backup made with
 *    All-in-One WP Migration;
 *  - links that still point to the old site's address;
 *  - links to this site over http:// on a site that uses https://;
 *  - links to media files that aren't on this site, and Media Library items
 *    whose file is missing.
 *
 * It fixes the first three after a database backup (listed in the Backup
 * Browser, so the fix can be undone), with the restore's own serialized-safe
 * replacement. Missing files can't be made up: they are listed. Email
 * addresses and other sites (another subdomain of the old domain) are never
 * changed: only whole addresses ("https://old.example", "//old.example") are
 * replaced, as during a restore.
 *
 * The work runs in steps of a few seconds, driven by the screen (the notice
 * after a restore, or Wrensafe → Fix Links). Its state is one option, stored
 * hex-encoded so the search never finds it.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Ajax\SearchReplaceAjax;
use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Backup\DatabaseExporter;
use WPBP\Core\Database\JobsRepository;
use WPBP\Utils\Logger;
use WPBP\Utils\Serializer;

defined( 'ABSPATH' ) || exit;

// The scan reads the site's own tables in keyset batches; there is no
// WordPress API for that, and the rows must be read fresh. Every query is
// prepared, with table and column names as %i.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class LinkCheck {

	const OPTION = 'wpbp_link_check';

	/** One step at a time (two open screens must not run the same fix). */
	const LOCK = 'wpbp_link_check_lock';

	/** The restore whose notice was dismissed. */
	const DISMISSED = 'wpbp_link_check_dismissed';

	/** Seconds after which the lock of a request that died is taken over. */
	const LOCK_SECONDS = 180;

	/** Seconds of work per step. */
	const STEP_SECONDS = 8;

	/** Seconds one step of the check runs: short, so the screen can show where it has got to. */
	const SCAN_SECONDS = 1;

	/** Rows read per query. */
	const BATCH = 250;

	/** Examples kept per kind of problem (the counts are complete). */
	const EXAMPLES = 20;

	/** Missing media files listed. */
	const MISSING_MAX = 100;

	/** Old addresses looked for at once. */
	const ADDRESSES_MAX = 10;

	/** Unknown folders listed (the count is complete). */
	const FOLDERS_MAX = 20;

	/** A fix nothing has moved for this long no longer blocks a new check. */
	const STALLED_SECONDS = 900;

	/** @var string This request's lock: it only ever releases its own. */
	private static $held = '';

	/** @var array<string,bool> Files already looked up in this request. */
	private static $exists = array();

	public static function register(): void {
		add_action( 'wpbp_job_finished', array( self::class, 'after_job' ), 20, 2 );
	}

	// ---------------------------------------------------------------------
	// State.
	// ---------------------------------------------------------------------

	/** @return array<string,mixed> */
	public static function state(): array {
		$raw  = get_option( self::OPTION, '' );
		$data = is_string( $raw ) && '' !== $raw && 0 === strlen( $raw ) % 2 && ctype_xdigit( $raw ) ? json_decode( (string) hex2bin( $raw ), true ) : null;

		return is_array( $data ) ? $data : array( 'status' => 'idle' );
	}

	private static function save( array $s ): void {
		$s['touched'] = time();

		update_option( self::OPTION, bin2hex( (string) wp_json_encode( $s ) ), false );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
		delete_option( self::LOCK );
		delete_option( self::DISMISSED );
	}

	private static function lock(): bool {
		global $wpdb;

		$now   = time();
		$value = ( $now + self::LOCK_SECONDS ) . '|' . wp_generate_password( 8, false );
		$taken = (bool) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::LOCK, $value, 'no' ) );

		if ( ! $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK ) );

			if ( (int) $held > $now ) {
				return false;
			}

			// The request holding it was cut off: only one request can take it over.
			$taken = (bool) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, self::LOCK, $held ) );
		}

		wp_cache_delete( self::OPTION, 'options' );

		self::$held = $taken ? $value : '';

		return $taken;
	}

	private static function unlock(): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK,
				'option_value' => self::$held,
			),
			array( '%s', '%s' )
		);

		self::$held = '';
	}

	/**
	 * The database just came back from a backup, which can hold a check or a fix in progress (the backup
	 * made before a fix always does). It must not carry on by itself: an undone fix stays undone. Called
	 * right after a restore puts its database in place, and again when the restore ends.
	 */
	public static function forget_restored(): void {
		wp_cache_delete( self::OPTION, 'options' );

		if ( in_array( self::state()['status'] ?? '', array( 'pending', 'checking', 'fixing' ), true ) ) {
			delete_option( self::OPTION );
			delete_option( self::LOCK );
		}
	}

	/**
	 * A restore that moved the site finished: a check waits for the next administrator screen.
	 *
	 * @param mixed $job
	 * @param mixed $status
	 */
	public static function after_job( $job, $status ): void {
		if ( ! $job instanceof RestoreJob ) {
			return;
		}

		$payload = $job->payload();

		// Also when the restore failed after its database was put in place (see forget_restored()).
		if ( ! empty( $payload['db_swapped'] ) || ( 'completed' === (string) $status && ! empty( $payload['needs_db'] ) ) ) {
			self::forget_restored();
		}

		if ( 'completed' !== (string) $status ) {
			return;
		}

		$source = untrailingslashit( (string) ( $payload['site_url'] ?? '' ) );
		$here   = untrailingslashit( (string) ( $payload['dest_home_url'] ?? home_url() ) );

		// Only a restore that brought another site's database: links can't be wrong otherwise.
		if ( empty( $payload['needs_db'] ) || 'safe_update' === ( $payload['source'] ?? '' ) || '' === $source || self::same_address( $source, $here ) ) {
			return;
		}

		$folders     = array(
			(string) ( $payload['dest_abspath'] ?? '' ),
			(string) ( $payload['source_abspath'] ?? '' ),
			self::uploads_parent( (string) ( $payload['dest_uploads_basedir'] ?? '' ) ),
			self::uploads_parent( (string) ( $payload['source_uploads_basedir'] ?? '' ) ),
		);
		$s           = self::fresh( array( $source, (string) ( $payload['source_site_url_option'] ?? '' ) ), $job->id(), $folders );
		$s['status'] = 'pending';

		self::save( $s );
	}

	/**
	 * Start a check.
	 *
	 * @param string[] $addresses The old site's addresses (from a restore, or typed on the screen).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function start( array $addresses, int $restore = 0 ) {
		if ( self::restoring() ) {
			return new \WP_Error( 'wpbp_link_check_restore', __( 'A restore is running. Check the links when it has finished.', 'wrensafe' ) );
		}

		if ( ! self::lock() ) {
			return new \WP_Error( 'wpbp_link_check_busy', __( 'A link check or fix is running. Wait for it to finish.', 'wrensafe' ) );
		}

		$current = self::state();

		// A fix under way isn't dropped by a new check, unless nothing has moved it for a while.
		if ( 'fixing' === ( $current['status'] ?? '' ) && time() - (int) ( $current['touched'] ?? $current['started'] ?? 0 ) < self::STALLED_SECONDS ) {
			self::unlock();
			return new \WP_Error( 'wpbp_link_check_busy', __( 'A link check or fix is running. Wait for it to finish.', 'wrensafe' ) );
		}

		$s = self::fresh( $addresses, $restore, (array) ( $current['folders_ok'] ?? array() ) );

		self::save( $s );
		self::unlock();

		return $s;
	}

	/** @return array<string,mixed> */
	private static function fresh( array $addresses, int $restore, array $folders = array() ): array {
		return array(
			'status'      => 'checking',
			'token'       => strtolower( wp_generate_password( 16, false ) ),
			'restore'     => $restore,
			'addresses'   => self::clean_addresses( $addresses ),
			'folders_ok'  => self::known_folders( $folders ),
			'not_checked' => array(),
			'source'      => 0,
			'after'       => 0,
			'found'       => self::empty_found(),
			'fixed'       => array(),
			'started'     => time(),
			'error'       => '',
		);
	}

	/**
	 * The server folders a fix may take out of links: this site's (its WordPress folder and the one
	 * that holds wp-content/uploads), and those a restore recorded. Anything else that looks like a
	 * folder may be another site's address, or text someone typed: it is listed, never changed. A
	 * folder one level deep ("/code/") only counts right after this site's own address (is_short()).
	 *
	 * @param string[] $extra
	 * @return string[] Without the leading slash, with a trailing one ("var/www/html/", "C:/xampp/htdocs/").
	 */
	private static function known_folders( array $extra ): array {
		$out = array();

		$uploads = self::uploads_parent( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) );

		foreach ( array_merge( array( ABSPATH, $uploads ), $extra ) as $folder ) {
			if ( '' === (string) $folder ) {
				continue;
			}

			$key  = ltrim( trailingslashit( wp_normalize_path( (string) $folder ) ), '/' );
			$bare = (string) preg_replace( '#^[A-Za-z]:/#', '', $key );

			if ( ! array_filter( explode( '/', $bare ), 'strlen' ) ) {
				continue;
			}

			$out[] = $key;

			// Windows drive letters are written either way.
			if ( $bare !== $key ) {
				$out[] = lcfirst( $key );
				$out[] = ucfirst( $key );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * A restore is under way: its lock is held, or a restore job is still running and moved in the last
	 * few minutes (a request cut off right after the database swap may not have set the lock again).
	 */
	private static function restoring(): bool {
		if ( RestoreManager::is_locked() ) {
			return true;
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 3 * MINUTE_IN_SECONDS );

		foreach ( JobsRepository::list_by_type( 'restore', 20 ) as $row ) {
			if ( 'running' === (string) $row['status'] && (string) ( $row['updated_at'] ?? '' ) >= $since ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How a known folder can be written in a link: as stored ("C:/xampp/htdocs/"), with Windows
	 * backslashes as WordPress writes them ("C:\xampp\htdocs/"), with them doubled as JSON keeps them, and
	 * with "%20" for spaces.
	 *
	 * @return string[]
	 */
	private static function spellings( string $folder ): array {
		$out = array( $folder, str_replace( ' ', '%20', $folder ) );

		if ( 1 === preg_match( '#^[A-Za-z]:/#', $folder ) ) {
			$back = str_replace( '/', '\\', rtrim( $folder, '/' ) );

			foreach ( array( $back, str_replace( ' ', '%20', $back ) ) as $form ) {
				$out[] = $form . '/';
				// JSON keeps backslashes doubled; some page builders store it without escaping the slashes.
				$out[] = str_replace( '\\', '\\\\', $form ) . '/';
			}
		}

		return array_values( array_unique( $out ) );
	}

	/** "/var/www/html/" for "/var/www/html/wp-content/uploads", else ''. */
	private static function uploads_parent( string $basedir ): string {
		$basedir = untrailingslashit( wp_normalize_path( $basedir ) );

		return '/wp-content/uploads' === substr( $basedir, -19 ) ? substr( $basedir, 0, -18 ) : '';
	}

	/**
	 * A folder one level deep ("code/"). Such a name also appears in ordinary links
	 * ("https://github.com/acme/code/…"), so it only counts, and is only taken out, right after this
	 * site's own address ("https://site//code/wp-content/uploads/…"), the damage a restore leaves.
	 */
	private static function is_short( string $folder ): bool {
		return 0 === preg_match( '#^[A-Za-z]:/#', $folder ) && count( array_filter( explode( '/', $folder ), 'strlen' ) ) < 2;
	}

	/**
	 * This site's address without its scheme, with and without "www." ("//site.com:8080/blog").
	 * Without the scheme it is found in the http, https and protocol-relative forms alike.
	 *
	 * @return string[]
	 */
	private static function home_forms(): array {
		$to   = wp_parse_url( untrailingslashit( home_url() ) );
		$host = strtolower( (string) ( $to['host'] ?? '' ) );

		if ( '' === $host ) {
			return array();
		}

		$rest = ( isset( $to['port'] ) ? ':' . (int) $to['port'] : '' ) . untrailingslashit( (string) ( $to['path'] ?? '' ) );
		$bare = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;

		return array( '//' . $bare . $rest, '//www.' . $bare . $rest );
	}

	/**
	 * Old addresses as "scheme://host[:port][/path]", without this site's own and without duplicates.
	 *
	 * @param mixed[] $addresses
	 * @return string[]
	 */
	public static function clean_addresses( array $addresses ): array {
		$out = array();

		foreach ( $addresses as $address ) {
			$address = trim( (string) $address );
			$parts   = wp_parse_url( $address );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
				continue;
			}

			$clean = strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . untrailingslashit( (string) ( $parts['path'] ?? '' ) );

			// Not this site, nor an address this site's own is inside ("example.com" for a site at
			// "example.com/blog"): replacing it would rewrite this site's own links.
			if ( self::same_address( $clean, home_url() ) || self::contains_home( $clean ) ) {
				continue;
			}

			$out[ $clean ] = true;
		}

		return array_slice( array_keys( $out ), 0, self::ADDRESSES_MAX );
	}

	/** Whether two addresses are the same site, whatever the scheme and "www.". */
	private static function same_address( string $a, string $b ): bool {
		$strip = static function ( string $url ): string {
			return untrailingslashit( (string) preg_replace( '#^(?:https?:)?//(?:www\.)?#i', '', strtolower( trim( $url ) ) ) );
		};

		return $strip( $a ) === $strip( $b );
	}

	private static function contains_home( string $address ): bool {
		$strip = static function ( string $url ): string {
			return untrailingslashit( (string) preg_replace( '#^(?:https?:)?//(?:www\.)?#i', '', strtolower( trim( $url ) ) ) );
		};

		return 0 === strpos( $strip( home_url() ) . '/', $strip( $address ) . '/' );
	}

	/** @return array<string,mixed> */
	private static function empty_found(): array {
		return array(
			'folders'      => array(),
			'folder_links' => 0,
			'odd'          => array(),
			'odd_links'    => 0,
			'old'          => array(),
			'old_links'    => 0,
			'http_links'   => 0,
			'examples'     => array(
				'folder' => array(),
				'odd'    => array(),
				'old'    => array(),
				'http'   => array(),
			),
			'missing'      => 0,
			'sizes'        => 0,
			'missing_list' => array(),
			'attachments'  => 0,
			'attach_list'  => array(),
		);
	}

	// ---------------------------------------------------------------------
	// Steps.
	// ---------------------------------------------------------------------

	/**
	 * One step of the check or of the fix.
	 *
	 * @return array<string,mixed>|\WP_Error The report for the screen.
	 */
	public static function step( string $token ) {
		$s = self::state();

		if ( '' === $token || ! hash_equals( (string) ( $s['token'] ?? '' ), $token ) ) {
			return new \WP_Error( 'wpbp_link_check_token', __( 'This check is no longer active. Start it again.', 'wrensafe' ) );
		}

		// Nothing runs during a restore: the database is about to change under it.
		if ( self::restoring() ) {
			return array( 'busy' => true ) + self::report( $s );
		}

		// Another screen is running this step: this one waits and asks again.
		if ( ! self::lock() ) {
			return array( 'busy' => true ) + self::report( $s );
		}

		// Read again now that the lock is held: the step before may just have saved it.
		$s = self::state();

		if ( ! hash_equals( (string) ( $s['token'] ?? '' ), $token ) ) {
			self::unlock();
			return new \WP_Error( 'wpbp_link_check_token', __( 'This check is no longer active. Start it again.', 'wrensafe' ) );
		}

		if ( 'pending' === $s['status'] ) {
			$s['status'] = 'checking';
		}

		$deadline = microtime( true ) + ( 'checking' === $s['status'] ? self::SCAN_SECONDS : self::STEP_SECONDS );

		try {
			if ( 'checking' === $s['status'] ) {
				self::scan( $s, $deadline );
			} elseif ( 'fixing' === $s['status'] ) {
				self::fix( $s, $deadline );
			}

			self::save( $s );
		} finally {
			self::unlock();
		}

		return self::report( $s );
	}

	/**
	 * Fix what the check found: a database backup first, then the replacement, then a new check.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function start_fix() {
		global $wpdb;

		if ( self::restoring() ) {
			return new \WP_Error( 'wpbp_link_check_restore', __( 'A restore is running. Check the links when it has finished.', 'wrensafe' ) );
		}

		if ( ! self::lock() ) {
			return new \WP_Error( 'wpbp_link_check_busy', __( 'A link check or fix is running. Wait for it to finish.', 'wrensafe' ) );
		}

		$s = self::state();

		if ( 'checked' !== ( $s['status'] ?? '' ) || ! self::fix_pairs( $s ) ) {
			self::unlock();
			return new \WP_Error( 'wpbp_link_check_nothing', __( 'There is nothing to fix. Check the links again first.', 'wrensafe' ) );
		}

		$s['status'] = 'fixing';
		$s['phase']  = 'backup';
		$s['token']  = strtolower( wp_generate_password( 16, false ) );
		$s['pairs']  = self::fix_pairs( $s );
		$s['tables'] = self::tables();
		$s['table']  = 0;
		$s['cursor'] = null;
		$s['rows']   = 0;
		$s['error']  = '';
		// This site's own two addresses stay exactly as they are set (see fix()).
		$s['own'] = array(
			'siteurl' => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl' ) ),
			'home'    => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'home' ) ),
		);

		self::save( $s );
		self::unlock();

		return self::report( $s );
	}

	/**
	 * The tables a fix may change: this site's, and only those its backup includes (a table left out
	 * of backups in Settings couldn't be put back). On a network, not the other sites' tables, nor
	 * the shared ones (users, sites): their addresses aren't this site's to change.
	 *
	 * @return string[]
	 */
	private static function tables(): array {
		global $wpdb;

		$tables = array_values( array_intersect( SearchReplaceAjax::tables(), ( new DatabaseExporter() )->get_tables() ) );

		if ( ! is_multisite() ) {
			return $tables;
		}

		$shared = array_values( $wpdb->tables( 'global' ) );
		$others = '#^' . preg_quote( $wpdb->base_prefix, '#' ) . '\d+_#';
		$own    = is_main_site() ? '' : $wpdb->prefix;

		return array_values(
			array_filter(
				$tables,
				static function ( $table ) use ( $shared, $others, $own ): bool {
					return ! in_array( $table, $shared, true ) && ( '' !== $own ? 0 === strpos( $table, $own ) : 1 !== preg_match( $others, $table ) );
				}
			)
		);
	}

	/** Hide the notice of this restore (the results stay on the Fix Links screen). */
	public static function dismiss(): void {
		update_option( self::DISMISSED, (int) ( self::state()['restore'] ?? 0 ), false );
	}

	// ---------------------------------------------------------------------
	// The check.
	// ---------------------------------------------------------------------

	/**
	 * Where the scan looks: table, key, text columns, and the columns that say what a row is.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function sources(): array {
		global $wpdb;

		return array(
			array(
				'table'   => $wpdb->posts,
				'key'     => 'ID',
				'columns' => array( 'post_content', 'post_excerpt', 'guid' ),
				'extra'   => array( 'post_title', 'post_type' ),
				'kind'    => 'post',
			),
			array(
				'table'   => $wpdb->postmeta,
				'key'     => 'meta_id',
				'columns' => array( 'meta_value' ),
				'extra'   => array( 'post_id', 'meta_key' ),
				'kind'    => 'postmeta',
			),
			array(
				'table'   => $wpdb->options,
				'key'     => 'option_id',
				'columns' => array( 'option_value' ),
				'extra'   => array( 'option_name', 'autoload' ),
				'kind'    => 'option',
			),
			array(
				'table'   => $wpdb->termmeta,
				'key'     => 'meta_id',
				'columns' => array( 'meta_value' ),
				'extra'   => array( 'term_id', 'meta_key' ),
				'kind'    => 'termmeta',
			),
		);
	}

	private static function scan( array &$s, float $deadline ): void {
		global $wpdb;

		$sources = self::sources();
		$count   = count( $sources );
		$tables  = self::tables();

		while ( (int) $s['source'] < $count && microtime( true ) < $deadline ) {
			$src = $sources[ (int) $s['source'] ];

			// Left out of backups: a fix there couldn't be undone, so it isn't offered.
			if ( ! in_array( $src['table'], $tables, true ) ) {
				$s['not_checked'][] = $src['table'];
				self::next_source( $s );
				continue;
			}

			self::measure( $s, $src['table'], $src['key'] );

			$rows = self::rows( $src, (int) $s['after'] );

			if ( ! $rows ) {
				self::next_source( $s );
				continue;
			}

			foreach ( $rows as $row ) {
				$where      = self::where( $src, $row );
				$s['after'] = (int) $row[ $src['key'] ];
				$s['read']  = (int) ( $s['read'] ?? 0 ) + 1;
				$s['now']   = $where;

				// WordPress's caches (transients) aren't the site's content and are rebuilt on their own.
				if ( 'option' === $src['kind'] && 1 === preg_match( '/^_(?:site_)?transient_/', (string) $row['option_name'] ) ) {
					continue;
				}

				foreach ( $src['columns'] as $column ) {
					self::inspect( $s, (string) $row[ $column ], $where, 'guid' === $column );
				}
			}
		}

		// Then the Media Library: items whose file isn't on the disk.
		while ( (int) $s['source'] === $count && microtime( true ) < $deadline ) {
			self::measure( $s, $wpdb->postmeta, 'meta_id' );

			if ( self::attachments( $s ) ) {
				self::next_source( $s );
			}
		}

		if ( (int) $s['source'] > $count ) {
			$s['status']  = 'checked';
			$s['checked'] = time();

			unset( $s['source'], $s['after'], $s['upto'], $s['now'] );

			Logger::info( 'link_check_finished: ' . self::summary_line( $s ) );
		}
	}

	/** On to the next part of the scan. */
	private static function next_source( array &$s ): void {
		++$s['source'];
		$s['after'] = 0;

		unset( $s['upto'], $s['now'] );
	}

	/** Where the current part of the scan ends (its highest key), to show how far it has got. */
	private static function measure( array &$s, string $table, string $key ): void {
		global $wpdb;

		if ( ! isset( $s['upto'] ) ) {
			$s['upto'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $key, $table ) );
		}
	}

	/**
	 * The next rows that hold a link of some kind ("//", "\/\/" in JSON, "%2F%2F" encoded, or an
	 * uploads path), by key.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( array $src, int $after ): array {
		global $wpdb;

		$links = '%' . $wpdb->esc_like( '//' ) . '%';
		$json  = '%' . $wpdb->esc_like( '\\/\\/' ) . '%';
		$coded = '%' . $wpdb->esc_like( '%2F%2F' ) . '%';
		$path  = '%' . $wpdb->esc_like( 'wp-content' ) . '%';

		if ( 'post' === $src['kind'] ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ID, post_content, post_excerpt, guid, post_title, post_type FROM %i WHERE ID > %d AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s OR post_excerpt LIKE %s OR guid LIKE %s ) ORDER BY ID LIMIT %d',
					$src['table'],
					$after,
					$links,
					$json,
					$coded,
					$path,
					$links,
					$path,
					$path,
					self::BATCH
				),
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i, %i, %i, %i FROM %i WHERE %i > %d AND ( %i LIKE %s OR %i LIKE %s OR %i LIKE %s OR %i LIKE %s ) ORDER BY %i LIMIT %d',
				$src['key'],
				$src['columns'][0],
				$src['extra'][0],
				$src['extra'][1],
				$src['table'],
				$src['key'],
				$after,
				$src['columns'][0],
				$links,
				$src['columns'][0],
				$json,
				$src['columns'][0],
				$coded,
				$src['columns'][0],
				$path,
				$src['key'],
				self::BATCH
			),
			ARRAY_A
		);
	}

	/** What a row is, for the lists on the screen. @return array<string,mixed> */
	private static function where( array $src, array $row ): array {
		switch ( $src['kind'] ) {
			case 'post':
				return array(
					'kind'  => 'post',
					'id'    => (int) $row['ID'],
					'label' => wp_html_excerpt( (string) $row['post_title'], 80, '…' ),
					'type'  => (string) $row['post_type'],
				);
			case 'postmeta':
				return array(
					'kind'  => 'postmeta',
					'id'    => (int) $row['post_id'],
					'label' => (string) $row['meta_key'],
				);
			case 'option':
				return array(
					'kind'  => 'option',
					'id'    => 0,
					'label' => (string) $row['option_name'],
				);
			default:
				return array(
					'kind'  => 'term',
					'id'    => (int) $row['term_id'],
					'label' => '',
				);
		}
	}

	/** Look at one value. */
	private static function inspect( array &$s, string $value, array $where, bool $guid ): void {
		if ( '' === $value ) {
			return;
		}

		$plain   = ' ' . str_replace( '\\/', '/', $value );
		$decoded = false !== stripos( $plain, '%2F' ) ? str_ireplace( array( '%3A', '%2F' ), array( ':', '/' ), $plain ) : $plain;

		// 1. A server folder in a link.
		$known = (array) ( $s['folders_ok'] ?? array() );

		foreach ( self::folders_in( $plain, $known ) as $folder => $n ) {
			$folder = (string) $folder;

			if ( in_array( $folder, $known, true ) ) {
				$s['found']['folders'][ $folder ] = (int) ( $s['found']['folders'][ $folder ] ?? 0 ) + $n;
				$s['found']['folder_links']      += $n;
				self::example( $s, 'folder', $where, self::snippet( $plain, '/' . $folder . 'wp-content/uploads/' ) );
				continue;
			}

			// Not a folder this site knows: shown, never changed.
			$s['found']['odd_links'] += $n;

			if ( isset( $s['found']['odd'][ $folder ] ) || count( $s['found']['odd'] ) < self::FOLDERS_MAX ) {
				$s['found']['odd'][ $folder ] = (int) ( $s['found']['odd'][ $folder ] ?? 0 ) + $n;
			}

			self::example( $s, 'odd', $where, self::snippet( $plain, '/' . $folder . 'wp-content/uploads/' ) );
		}

		// 2. The old site's address, in any of its forms.
		foreach ( (array) $s['addresses'] as $address ) {
			$n = preg_match_all( self::address_pattern( (string) $address ), $decoded, $m );

			if ( $n ) {
				$s['found']['old'][ $address ] = (int) ( $s['found']['old'][ $address ] ?? 0 ) + $n;
				$s['found']['old_links']      += $n;
				self::example( $s, 'old', $where, (string) $m[0][0] );
			}
		}

		// 3. This site over http:// when it uses https://.
		$http = self::http_form();

		if ( '' !== $http ) {
			$n = preg_match_all( '~' . preg_quote( $http, '~' ) . '(?!' . Serializer::ADDRESS_CONTINUES . ')~', $decoded, $m );

			if ( $n ) {
				$s['found']['http_links'] += $n;
				self::example( $s, 'http', $where, $http );
			}
		}

		// 4. Media files that aren't here (attachment GUIDs are checked through the Media Library).
		$stale = 'revision' === ( $where['type'] ?? '' );

		if ( ! $guid && ! $stale ) {
			self::media_in( $s, $plain, $where );
		}
	}

	/**
	 * Server folders spliced into links, by folder ("/var/www/html/", "C:/xampp/htdocs/"), with their count.
	 *
	 * A folder counts when it follows a site's address ("https://site.com//var/www/…"), or stands where a
	 * link starts ("…src=\"//var/www/…\"") and looks like a folder (at least two parts, the first without a
	 * dot, unlike a host name) or is this server's own WordPress folder.
	 *
	 * @param string[] $known Folders this site knows (these may have spaces: "Users/me/Local Sites/x/").
	 * @return array<string,int>
	 */
	public static function folders_in( string $text, array $known = array() ): array {
		if ( false === strpos( $text, 'wp-content/uploads/' ) ) {
			return array();
		}

		$found = array();

		foreach ( $known as $folder ) {
			$folder = (string) $folder;

			if ( self::is_short( $folder ) ) {
				foreach ( self::home_forms() as $form ) {
					$n = substr_count( $text, $form . '//' . $folder . 'wp-content/uploads/' );

					if ( $n ) {
						$found[ $folder ] = ( $found[ $folder ] ?? 0 ) + $n;
					}
				}

				continue;
			}

			// Every known folder is counted here, in every spelling: the pattern below only finds the unknown ones.
			$start = 1 === preg_match( '#^[A-Za-z]:/#', $folder ) ? '/' : '//';

			foreach ( self::spellings( $folder ) as $form ) {
				$n = substr_count( $text, $start . $form . 'wp-content/uploads/' );

				if ( $n ) {
					$found[ $folder ] = ( $found[ $folder ] ?? 0 ) + $n;
				}
			}
		}

		if ( ! preg_match_all( '~(.)/((?:/|[A-Za-z]:/)(?:[^/"\'\s<>()\\\\]+/)+?)wp-content/uploads/~', ' ' . $text, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}

		foreach ( $matches as $m ) {
			$before = $m[1];
			$folder = $m[2];

			if ( ':' === $before ) {
				continue; // "https://host/wp-content/…": the host, not a folder.
			}

			$windows = 1 === preg_match( '#^[A-Za-z]:/#', $folder );
			$after   = 1 === preg_match( '/[A-Za-z0-9.\-\]]/', $before );
			$parts   = array_values( array_filter( explode( '/', $folder ), 'strlen' ) );
			$looks   = count( $parts ) >= 2 && false === strpos( (string) ( $parts[0] ?? '' ), '.' ) && false === strpos( (string) ( $parts[0] ?? '' ), ':' );
			$own     = trailingslashit( wp_normalize_path( ABSPATH ) ) === $folder || ( ! $windows && false === strpos( $folder, "\0" ) && is_dir( $folder . 'wp-content' ) );

			if ( $windows || $after || $looks || $own ) {
				$key = ltrim( $folder, '/' ) === $folder ? $folder : substr( $folder, 1 );

				// A known folder was counted above (also in its "%20" spelling).
				if ( in_array( $key, $known, true ) || ( false !== strpos( $key, '%20' ) && in_array( str_replace( '%20', ' ', $key ), $known, true ) ) ) {
					continue;
				}

				$found[ $key ] = ( $found[ $key ] ?? 0 ) + 1;
			}
		}

		return $found;
	}

	/** Every form of an old address: http, https, protocol-relative, with or without "www.". */
	private static function address_pattern( string $address ): string {
		$parts = wp_parse_url( $address );
		$host  = (string) preg_replace( '/^www\./', '', strtolower( (string) ( $parts['host'] ?? '' ) ) );
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path  = untrailingslashit( (string) ( $parts['path'] ?? '' ) );

		// Not when the address only starts another one: "//old.com.au", "//old.com-shop", "//old.com:8080".
		// Nor a file-transfer address on the same host ("ftp://old.com"): that's a server, not a link to the site.
		return '~(?<![fF][tT][pP]:)(?<![fF][tT][pP][sS]:)(?:https?:)?//(?:www\.)?' . preg_quote( $host . $port . $path, '~' ) . '(?!' . Serializer::ADDRESS_CONTINUES . ')~';
	}

	/** "http://this-site" when the site uses https, else ''. */
	private static function http_form(): string {
		$home = home_url();

		return 0 === strpos( $home, 'https://' ) ? 'http://' . substr( $home, 8 ) : '';
	}

	/**
	 * This site's uploads folder: its address path, its folder on disk, and the link pattern.
	 *
	 * @return array{path:string,basedir:string,pattern:string}|null
	 */
	private static function uploads(): ?array {
		static $uploads = false;

		if ( false !== $uploads ) {
			return $uploads;
		}

		$dir     = wp_upload_dir( null, false );
		$parts   = wp_parse_url( (string) ( $dir['baseurl'] ?? '' ) );
		$path    = trim( (string) ( $parts['path'] ?? '' ), '/' );
		$basedir = trailingslashit( wp_normalize_path( (string) ( $dir['basedir'] ?? '' ) ) );
		$host    = (string) preg_replace( '/^www\./', '', strtolower( (string) ( $parts['host'] ?? '' ) ) ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$home    = wp_parse_url( home_url() );
		$here    = (string) preg_replace( '/^www\./', '', strtolower( (string) ( $home['host'] ?? '' ) ) ) . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );

		if ( '' === $path || '/' === $basedir || '' === $host ) {
			$uploads = null;
			return $uploads;
		}

		// Links without a host ("/wp-content/uploads/…") only count when the uploads are on this site's host.
		$relative = $host === $here ? '|(?<=["\'(=\s])' : '';

		$uploads = array(
			'path'    => $path,
			'basedir' => $basedir,
			'pattern' => '~(?:(?:https?:)?//(?:www\.)?' . preg_quote( $host, '~' ) . $relative . ')/' . preg_quote( $path, '~' ) . '/([^"\'\s<>()\\\\?#,]+)~i',
		);

		return $uploads;
	}

	/** Links into this site's uploads folder whose file isn't there. */
	private static function media_in( array &$s, string $text, array $where ): void {
		$uploads = self::uploads();

		if ( null === $uploads || false === strpos( $text, $uploads['path'] . '/' ) || ! preg_match_all( $uploads['pattern'], $text, $m ) ) {
			return;
		}

		$path    = $uploads['path'];
		$basedir = $uploads['basedir'];

		foreach ( array_unique( $m[1] ) as $rel ) {
			$rel = rtrim( rawurldecode( html_entity_decode( (string) $rel, ENT_QUOTES ) ), '.' );

			if ( '' === $rel || false !== strpos( $rel, '..' ) || '/' === substr( $rel, -1 ) || self::exists( $basedir . $rel ) ) {
				continue;
			}

			// An image size WordPress can make again from the original.
			$size = 1 === preg_match( '/^(.*)-\d+x\d+(\.[A-Za-z0-9]+)$/', $rel, $o ) && self::exists( $basedir . $o[1] . $o[2] );

			++$s['found'][ $size ? 'sizes' : 'missing' ];

			if ( count( $s['found']['missing_list'] ) < self::MISSING_MAX ) {
				$s['found']['missing_list'][] = $where + array(
					'file' => $path . '/' . $rel,
					'size' => $size,
				);
			}
		}
	}

	private static function exists( string $file ): bool {
		// A NUL byte can't be in a real file name (and some PHP versions throw on it).
		if ( false !== strpos( $file, "\0" ) ) {
			return false;
		}

		if ( ! isset( self::$exists[ $file ] ) ) {
			if ( count( self::$exists ) > 5000 ) {
				self::$exists = array();
			}

			self::$exists[ $file ] = file_exists( $file );
		}

		return self::$exists[ $file ];
	}

	/** Media Library items whose file is missing. @return bool True when all were looked at. */
	private static function attachments( array &$s ): bool {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_id, post_id, meta_value FROM %i WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id LIMIT %d',
				$wpdb->postmeta,
				'_wp_attached_file',
				(int) $s['after'],
				self::BATCH * 2
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			$s['after'] = 0;
			return true;
		}

		$basedir = trailingslashit( wp_normalize_path( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) ) );

		foreach ( $rows as $row ) {
			$s['after'] = (int) $row['meta_id'];
			$file       = wp_normalize_path( (string) $row['meta_value'] );

			$s['read'] = (int) ( $s['read'] ?? 0 ) + 1;
			$s['now']  = array(
				'kind'  => 'media',
				'id'    => (int) $row['post_id'],
				'label' => wp_html_excerpt( wp_basename( $file ), 80, '…' ),
			);

			// Only ever looks inside the uploads folder: never at a stream ("phar://…") or elsewhere.
			if ( '' === $file || false !== strpos( $file, '://' ) || false !== strpos( '/' . $file . '/', '/../' ) ) {
				continue;
			}

			if ( '/' === $file[0] || 1 === preg_match( '#^[A-Za-z]:/#', $file ) ) {
				if ( 0 !== strpos( $file, $basedir ) ) {
					continue;
				}
			} else {
				$file = $basedir . $file;
			}

			if ( self::exists( $file ) ) {
				continue;
			}

			++$s['found']['attachments'];

			if ( count( $s['found']['attach_list'] ) < self::EXAMPLES ) {
				$s['found']['attach_list'][] = array(
					'kind'  => 'post',
					'id'    => (int) $row['post_id'],
					'label' => wp_html_excerpt( get_the_title( (int) $row['post_id'] ), 80, '…' ),
					'type'  => 'attachment',
					'file'  => (string) $row['meta_value'],
				);
			}
		}

		return false;
	}

	private static function example( array &$s, string $kind, array $where, string $link ): void {
		if ( count( $s['found']['examples'][ $kind ] ?? array() ) < self::EXAMPLES ) {
			$s['found']['examples'][ $kind ][] = $where + array( 'link' => wp_html_excerpt( $link, 160, '…' ) );
		}
	}

	/** The link around a match, for the examples. */
	private static function snippet( string $text, string $needle ): string {
		$at = strpos( $text, $needle );

		if ( false === $at ) {
			return $needle;
		}

		$start  = max( 0, $at - 40 );
		$before = (string) preg_replace( '/^.*["\'(=\s]/s', '', substr( $text, $start, $at - $start ) );
		$after  = (string) preg_replace( '/["\'()<>\s\\\\].*$/s', '', substr( $text, $at + strlen( $needle ), 80 ) );

		return trim( $before ) . $needle . $after;
	}

	// ---------------------------------------------------------------------
	// The fix.
	// ---------------------------------------------------------------------

	/**
	 * What will be replaced, longest first.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function fix_pairs( array $s ): array {
		$pairs = array();
		$found = (array) ( $s['found'] ?? array() );

		// The original link had "/wp-content/uploads/" where the folder now stands.
		foreach ( array_keys( (array) ( $found['folders'] ?? array() ) ) as $folder ) {
			$folder = (string) $folder;

			if ( ! in_array( $folder, (array) ( $s['folders_ok'] ?? array() ), true ) ) {
				continue;
			}

			if ( self::is_short( $folder ) ) {
				foreach ( self::home_forms() as $form ) {
					$pairs[ $form . '//' . $folder . 'wp-content/uploads/' ] = $form . '/wp-content/uploads/';
				}

				continue;
			}

			$windows = 1 === preg_match( '#^[A-Za-z]:/#', $folder );

			foreach ( self::spellings( $folder ) as $form ) {
				$pairs[ ( $windows ? '/' : '//' ) . $form . 'wp-content/uploads/' ] = '/wp-content/uploads/';

				// The same damage where the original had no slash before it: a relative link, or a rewrite rule.
				$full = ( $windows ? '' : '/' ) . $form . 'wp-content/uploads/';

				foreach ( array( '^', 'src="', "src='", 'href="', "href='", 'url(' ) as $before ) {
					$pairs[ $before . $full ] = $before . 'wp-content/uploads/';
				}
			}
		}

		$home = untrailingslashit( home_url() );
		$to   = wp_parse_url( $home );
		$bare = '//' . (string) ( $to['host'] ?? '' ) . ( isset( $to['port'] ) ? ':' . (int) $to['port'] : '' ) . untrailingslashit( (string) ( $to['path'] ?? '' ) );

		foreach ( array_keys( (array) ( $found['old'] ?? array() ) ) as $address ) {
			$parts = wp_parse_url( (string) $address );
			$host  = (string) preg_replace( '/^www\./', '', strtolower( (string) ( $parts['host'] ?? '' ) ) );
			$rest  = ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . untrailingslashit( (string) ( $parts['path'] ?? '' ) );

			foreach ( array( $host, 'www.' . $host ) as $one ) {
				$pairs[ 'https://' . $one . $rest ] = $home;
				$pairs[ 'http://' . $one . $rest ]  = $home;
				$pairs[ '//' . $one . $rest ]       = $bare;
			}
		}

		if ( ! empty( $found['http_links'] ) && '' !== self::http_form() ) {
			$pairs[ self::http_form() ] = $home;
		}

		unset( $pairs[ $home ] );

		uksort(
			$pairs,
			static function ( $a, $b ): int {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		$out = array();

		foreach ( $pairs as $search => $replace ) {
			if ( (string) $search !== (string) $replace ) {
				$out[] = array( (string) $search, (string) $replace );
			}
		}

		return $out;
	}

	private static function fix( array &$s, float $deadline ): void {
		if ( 'backup' === $s['phase'] ) {
			self::fix_backup( $s, $deadline );
			return;
		}

		if ( 'replace' === $s['phase'] ) {
			$engine = new SearchReplaceEngine();
			$tables = (array) $s['tables'];
			$pairs  = (array) $s['pairs'];
			$total  = count( $tables );

			while ( (int) $s['table'] < $total && microtime( true ) < $deadline ) {
				$table = (string) $tables[ (int) $s['table'] ];

				if ( null === $s['cursor'] && null === $engine->get_primary_key( $table ) ) {
					++$s['table'];
					continue;
				}

				try {
					$batch = $engine->process_table_after( $table, $s['cursor'], $pairs, false, array(), true );
				} catch ( \Throwable $e ) {
					$s['status'] = 'checked';
					/* translators: 1: table name, 2: error message */
					$s['error'] = sprintf( __( 'The fix stopped in %1$s: %2$s Rows changed before it stopped stay changed; the backup made first has the database as it was.', 'wrensafe' ), $table, $e->getMessage() );
					Logger::error( 'link_fix_failed: ' . $table . ': ' . $e->getMessage() );
					return;
				}

				$s['rows'] += (int) ( $batch['rows_changed'] ?? 0 );

				if ( ! empty( $batch['done'] ) ) {
					++$s['table'];
					$s['cursor'] = null;
				} else {
					$s['cursor'] = $batch['cursor'];
				}
			}

			if ( (int) $s['table'] >= $total ) {
				global $wpdb;

				foreach ( (array) ( $s['own'] ?? array() ) as $name => $value ) {
					if ( '' !== (string) $value ) {
						$wpdb->update( $wpdb->options, array( 'option_value' => (string) $value ), array( 'option_name' => (string) $name ), array( '%s' ), array( '%s' ) );
					}
				}

				// WordPress's uploads setting, when the same damage turned its default into this server's
				// folder: the two name the same place, and the default keeps working if the site moves again.
				if ( ! empty( $s['found']['folders'] ) && in_array( $wpdb->options, $tables, true ) ) {
					$upload_path = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'upload_path' ) );

					if ( trailingslashit( wp_normalize_path( ABSPATH ) ) . 'wp-content/uploads' === untrailingslashit( wp_normalize_path( $upload_path ) ) ) {
						$wpdb->update( $wpdb->options, array( 'option_value' => 'wp-content/uploads' ), array( 'option_name' => 'upload_path' ), array( '%s' ), array( '%s' ) );
					}
				}

				wp_cache_flush();

				$s['fixed'] = array(
					'rows'   => (int) $s['rows'],
					'links'  => (int) $s['found']['folder_links'] + (int) $s['found']['old_links'] + (int) $s['found']['http_links'],
					'backup' => (int) ( $s['backup_id'] ?? 0 ),
					'at'     => time(),
				);

				Logger::success( 'link_fix_completed: ' . (int) $s['rows'] . ' rows changed (' . count( $pairs ) . ' replacements), backup #' . (int) ( $s['backup_id'] ?? 0 ) . ' made first.' );

				// Check again (the screen goes on with the same token): what is left is shown, usually
				// files that weren't in the backup.
				$again          = self::fresh( (array) $s['addresses'], (int) $s['restore'], (array) ( $s['folders_ok'] ?? array() ) );
				$again['token'] = $s['token'];
				$again['fixed'] = $s['fixed'];
				$s              = $again;
			}
		}
	}

	private static function fix_backup( array &$s, float $deadline ): void {
		$job = ! empty( $s['backup_job'] ) ? BackupJob::get( (int) $s['backup_job'] ) : null;

		if ( null === $job ) {
			$job = BackupJob::create(
				array(
					'backup_type' => 'database',
					'note'        => __( 'Before fixing links', 'wrensafe' ),
					'tag'         => 'pre_fix_links',
				)
			);

			$s['backup_job'] = $job->id();
		}

		$manager = new BackupManager();
		$done    = array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED );

		while ( ! in_array( $job->status(), $done, true ) && microtime( true ) < $deadline ) {
			$manager->process_step( $job );
		}

		$s['backup_progress'] = $job->progress();

		if ( ! in_array( $job->status(), $done, true ) ) {
			return;
		}

		if ( BackupJob::STATUS_COMPLETED !== $job->status() ) {
			$s['status'] = 'checked';
			/* translators: %s: why the backup failed */
			$s['error'] = sprintf( __( 'Nothing was changed: the database backup made first failed (%s).', 'wrensafe' ), '' !== $job->error_message() ? $job->error_message() : __( 'see the Activity Logs', 'wrensafe' ) );
			unset( $s['backup_job'] );
			return;
		}

		$s['backup_id'] = (int) ( $job->result()['backup_id'] ?? 0 );
		$s['phase']     = 'replace';

		unset( $s['backup_job'] );
	}

	// ---------------------------------------------------------------------
	// For the screens.
	// ---------------------------------------------------------------------

	/** @return array<string,mixed> */
	public static function report( array $s ): array {
		$found   = (array) ( $s['found'] ?? self::empty_found() );
		$sources = count( self::sources() ) + 1;

		$percent = 0;

		if ( 'checking' === ( $s['status'] ?? '' ) ) {
			$upto    = (int) ( $s['upto'] ?? 0 );
			$within  = $upto > 0 ? min( 1, (int) ( $s['after'] ?? 0 ) / $upto ) : 0;
			$percent = (int) min( 99, floor( 100 * ( (int) ( $s['source'] ?? 0 ) + $within ) / $sources ) );
		} elseif ( 'fixing' === ( $s['status'] ?? '' ) ) {
			$tables  = max( 1, count( (array) ( $s['tables'] ?? array() ) ) );
			$percent = 'backup' === ( $s['phase'] ?? '' ) ? (int) floor( (int) ( $s['backup_progress'] ?? 0 ) / 2 ) : 50 + (int) floor( 50 * (int) ( $s['table'] ?? 0 ) / $tables );
		} elseif ( in_array( $s['status'] ?? '', array( 'checked' ), true ) ) {
			$percent = 100;
		}

		return array(
			'status'    => (string) ( $s['status'] ?? 'idle' ),
			'phase'     => (string) ( $s['phase'] ?? '' ),
			'token'     => in_array( $s['status'] ?? '', array( 'pending', 'checking', 'fixing' ), true ) ? (string) ( $s['token'] ?? '' ) : '',
			'percent'   => $percent,
			'stage'     => min( (int) ( $s['source'] ?? 0 ), $sources - 1 ),
			'read'      => (int) ( $s['read'] ?? 0 ),
			'now'       => (array) ( $s['now'] ?? array() ),
			'table'     => (string) ( ( (array) ( $s['tables'] ?? array() ) )[ (int) ( $s['table'] ?? 0 ) ] ?? '' ),
			'rows'      => (int) ( $s['rows'] ?? 0 ),
			'addresses' => (array) ( $s['addresses'] ?? array() ),
			'skipped'   => array_values( array_unique( (array) ( $s['not_checked'] ?? array() ) ) ),
			'found'     => $found,
			'fixable'   => (int) $found['folder_links'] + (int) $found['old_links'] + (int) $found['http_links'],
			'fixed'     => (array) ( $s['fixed'] ?? array() ),
			'error'     => (string) ( $s['error'] ?? '' ),
			'checked'   => (int) ( $s['checked'] ?? 0 ),
			'dismissed' => self::is_dismissed( $s ),
		);
	}

	private static function is_dismissed( array $s ): bool {
		$restore = (int) ( $s['restore'] ?? 0 );

		return $restore > 0 && (int) get_option( self::DISMISSED, 0 ) === $restore;
	}

	/** Whether the notice has something to say: only about a restore (a check run by hand stays on its screen). */
	public static function needs_notice(): bool {
		$s = self::state();

		if ( 'idle' === $s['status'] || empty( $s['restore'] ) || self::is_dismissed( $s ) ) {
			return false;
		}

		// The notice doesn't stay for ever: Wrensafe → Fix Links keeps the results.
		if ( time() - (int) ( $s['started'] ?? 0 ) > WEEK_IN_SECONDS ) {
			return false;
		}

		if ( in_array( $s['status'], array( 'pending', 'checking', 'fixing' ), true ) ) {
			return true;
		}

		$r = self::report( $s );

		return $r['fixable'] > 0 || (int) $r['found']['missing'] > 0 || ! empty( $r['fixed'] ) || '' !== $r['error'];
	}

	private static function summary_line( array $s ): string {
		$f = (array) $s['found'];

		return sprintf( '%d links with a server folder, %d to old addresses, %d over http, %d media files missing (%d image sizes), %d Media Library files missing.', (int) $f['folder_links'], (int) $f['old_links'], (int) $f['http_links'], (int) $f['missing'], (int) $f['sizes'], (int) $f['attachments'] );
	}
}
