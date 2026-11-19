<?php
/**
 * Turns a backup made by another plugin into a Wrensafe (.wbp) archive,
 * in short resumable steps, then hands it to the normal restore flow
 * (review screen, integrity check, rollback point, URL and table-prefix
 * rewrite, plugin safety).
 *
 * Phases: files (copy site files out of each source, set SQL aside) → sql
 * (split the dumps per table) → pack (add the tables) → finish (metadata,
 * restore job).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Backup\ManifestBuilder;
use WPBP\Backup\ZipStreamWriter;
use WPBP\Import\Foreign\Formats\AllInOneWpMigration;
use WPBP\Import\Foreign\Formats\BackWpup;
use WPBP\Import\Foreign\Formats\BackupMigration;
use WPBP\Import\Foreign\Formats\Jetpack;
use WPBP\Import\Foreign\Formats\SolidBackups;
use WPBP\Import\Foreign\Formats\TotalUpkeep;
use WPBP\Import\Foreign\Formats\WpMigrate;
use WPBP\Import\Foreign\Formats\Duplicator;
use WPBP\Import\Foreign\Formats\Generic;
use WPBP\Import\Foreign\Formats\UpdraftPlus;
use WPBP\Import\Foreign\Formats\Wpvivid;
use WPBP\Restore\ImportValidator;
use WPBP\Utils\Errors;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Archives and dumps are streamed with native handles: WP_Filesystem has no
// streaming API. Every path is inside Wrensafe's protected storage.
// phpcs:disable WordPress.WP.AlternativeFunctions

class ForeignConverter {

	/** @return AbstractFormat[] In detection order (Generic last). */
	public static function formats(): array {
		return array(
			new AllInOneWpMigration(),
			new UpdraftPlus(),
			new Duplicator(),
			new Wpvivid(),
			new BackWpup(),
			new BackupMigration(),
			new TotalUpkeep(),
			new SolidBackups(),
			new WpMigrate(),
			new Jetpack(),
			new Generic(),
		);
	}

	public static function format( string $key ): ?AbstractFormat {
		foreach ( self::formats() as $format ) {
			if ( $format->key() === $key ) {
				return $format;
			}
		}

		return null;
	}

	/** @param array<string,string> $files */
	public static function detect( array $files ): ?AbstractFormat {
		foreach ( self::formats() as $format ) {
			if ( $format->matches( $files ) ) {
				return $format;
			}
		}

		return null;
	}

	/**
	 * What an uploaded set contains (the format's describe()), once it is
	 * clear this server's PHP can read every part of it: a missing Zip,
	 * Zlib or Bzip2 extension is reported now, before the conversion
	 * starts, instead of halfway through it.
	 *
	 * @param array<string,string> $files
	 * @return array{sources:array,meta:array,warnings:array}
	 * @throws \RuntimeException When the backup can't be imported on this server.
	 */
	public static function describe( AbstractFormat $format, array $files ): array {
		$plan = $format->describe( $files );

		foreach ( (array) ( $plan['sources'] ?? array() ) as $source ) {
			self::require_extensions( (array) $source );
		}

		return $plan;
	}

	/**
	 * The PHP extensions reading one source needs.
	 *
	 * @param array<string,mixed> $source
	 * @throws \RuntimeException When one of them is missing.
	 */
	private static function require_extensions( array $source ): void {
		$type = (string) ( $source['type'] ?? '' );

		if ( 'zip' === $type || ! empty( $source['inner'] ) ) {
			AbstractFormat::require_zip();
		}

		if ( 'sqlgz' === $type ) {
			AbstractFormat::require_zlib();
		}

		if ( 'tgz' === $type ) {
			if ( preg_match( '/\.bz2$/i', (string) ( $source['file'] ?? '' ) ) ) {
				AbstractFormat::require_bz2();
			} else {
				AbstractFormat::require_zlib();
			}
		}
	}

	private static function deadline(): float {
		$max = (int) ini_get( 'max_execution_time' );

		return microtime( true ) + ( ( $max > 0 && $max < 20 ) ? max( 4, $max - 6 ) : 10 );
	}

	/**
	 * Create the conversion job for an analysed set.
	 *
	 * @param array{format:string,sources:array,meta:array,warnings:array} $plan
	 */
	public static function start( string $set_id, array $plan ): ForeignImportJob {
		$size      = ImportSet::size( $set_id );
		$disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( FileSystem::uploads_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $disk_free && $disk_free < $size * 1.2 + 104857600 ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: free disk space, 2: space needed */
						__( 'Not enough free disk space to prepare this backup (free: %1$s, needed: about %2$s). Free up space and try again.', 'wrensafe' ),
						size_format( (int) $disk_free ),
						size_format( (int) ( $size * 1.2 + 104857600 ) )
					)
				)
			);
		}

		if ( empty( $plan['meta']['created_at'] ) ) {
			$plan['meta']['created_at'] = AbstractFormat::date_from_names( array_keys( ImportSet::files( $set_id ) ) );
		}

		$job = ForeignImportJob::create(
			array(
				'set_id'   => $set_id,
				'format'   => $plan['format'],
				'sources'  => $plan['sources'],
				'meta'     => $plan['meta'],
				'warnings' => $plan['warnings'],
				'total'    => $size,
				'phase'    => 'init',
			)
		);
		$job->mark_running();

		return $job;
	}

	/** Run one bounded step of a conversion job. */
	public static function step( ForeignImportJob $job ): void {
		$payload = $job->payload();

		try {
			$format = self::format( (string) $payload['format'] );

			if ( null === $format ) {
				throw new \RuntimeException( esc_html__( 'Unknown backup format.', 'wrensafe' ) );
			}

			switch ( $payload['phase'] ) {
				case 'init':
					self::init( $job, $payload );
					break;

				case 'files':
					self::step_files( $job, $payload, $format );
					break;

				case 'sql':
					self::step_sql( $job, $payload );
					break;

				case 'pack':
					self::step_pack( $job, $payload );
					break;

				case 'finish':
					self::step_finish( $job, $payload, $format );
					break;
			}
		} catch ( \Throwable $e ) {
			$message = Errors::text( $e );

			Logger::failed( 'foreign_import_failed: ' . $message, array(), $job->id() );
			self::cleanup_partial( $job, $payload );
			$job->mark_failed( $message );
		}
	}

	private static function init( ForeignImportJob $job, array $payload ): void {
		$tmp = FileSystem::job_tmp_dir( $job->id() );
		FileSystem::ensure_protected_directory( $tmp );
		FileSystem::ensure_protected_directory( FileSystem::uploads_dir() );

		$out = FileSystem::uploads_dir() . 'import-' . $payload['set_id'] . '.wbp';

		$payload['tmp']         = $tmp;
		$payload['out']         = $out;
		$payload['zip']         = ZipStreamWriter::new_state( $out, $tmp . 'central-directory.bin' );
		$payload['checksums']   = $tmp . 'entry-checksums.tsv';
		$payload['src']         = array(
			'index'  => 0,
			'cursor' => 0,
			'file'   => '',
		);
		$payload['sql_files']   = array();
		$payload['sql_names']   = array();
		$payload['sql_renames'] = array();
		$payload['files']       = 0;
		$payload['done']        = 0;
		$payload['phase']       = 'files';

		file_put_contents( $payload['checksums'], '' );

		$job->set_payload( $payload );
		$job->update_progress( 2, __( 'Reading the backup…', 'wrensafe' ) );

		Logger::info( 'foreign_import_started: converting a ' . $payload['format'] . ' backup (' . size_format( (int) $payload['total'] ) . ').', array(), $job->id() );
	}

	private static function reader( string $type ): ContainerReader {
		switch ( $type ) {
			case 'wpress':
				return new WpressReader();
			case 'daf':
				return new DafReader();
			case 'tar':
				return new TarReader();
			default:
				return new ZipReader();
		}
	}

	/**
	 * Phase 'files': walk the sources, writing site files into the new
	 * archive and setting SQL dumps aside for the next phase.
	 */
	private static function step_files( ForeignImportJob $job, array $payload, AbstractFormat $format ): void {
		$deadline = self::deadline();
		$sources  = $payload['sources'];
		$writer   = new ZipStreamWriter( $payload['zip'] );
		$sums     = fopen( $payload['checksums'], 'ab' );
		$entry    = $payload['tmp'] . 'entry.tmp';
		$count    = count( $sources );

		while ( $payload['src']['index'] < $count && microtime( true ) < $deadline ) {
			$source = $sources[ $payload['src']['index'] ];

			if ( in_array( $source['type'], array( 'sql', 'sqlgz' ), true ) ) {
				$payload = self::register_sql( $payload, $source['file'], 'sqlgz' === $source['type'], basename( (string) $source['file'] ), $format );
				$payload = self::next_source( $payload );
				continue;
			}

			$file = self::prepare_source( $source, $payload );
			$type = 'tgz' === $source['type'] ? 'tar' : $source['type'];

			$reader = self::reader( $type );
			$reader->open( $file, (int) $payload['src']['cursor'] );
			$finished = false;

			while ( microtime( true ) < $deadline ) {
				$item = $reader->next();

				if ( null === $item ) {
					$finished = true;
					break;
				}

				if ( $item['dir'] || ! empty( $item['skip'] ) ) {
					$reader->skip();
					$payload['src']['cursor'] = $reader->cursor();
					continue;
				}

				$target = $format->classify( $source, $item['path'] );

				if ( 'file' === $target[0] ) {
					$out = fopen( $entry, 'wb' );
					$reader->copy_to( $out );
					fclose( $out );

					$sha = $writer->add_file( $entry, $target[1] );
					fwrite( $sums, $target[1] . "\t" . $sha . "\n" );
					++$payload['files'];
				} elseif ( 'sql' === $target[0] || 'sqlgz' === $target[0] ) {
					$dump = $payload['tmp'] . 'dump-' . count( $payload['sql_files'] ) . ( 'sqlgz' === $target[0] ? '.sql.gz' : '.sql' );
					$out  = fopen( $dump, 'wb' );
					$reader->copy_to( $out );
					fclose( $out );
					$payload = self::register_sql( $payload, $dump, 'sqlgz' === $target[0], (string) $item['path'], $format );
				} else {
					$reader->skip();
				}

				$payload['done']         += (int) $item['size'];
				$payload['src']['cursor'] = $reader->cursor();
			}

			$reader->close();

			if ( $finished ) {
				$payload = self::next_source( $payload );
			}
		}

		fclose( $sums );
		if ( file_exists( $entry ) ) {
			wp_delete_file( $entry );
		}

		$payload['zip'] = $writer->state();
		$writer->close();

		if ( $payload['src']['index'] >= count( $sources ) ) {
			// Some formats need their dumps in a particular order (Jetpack's
			// "-updates" files after the table they change).
			$names = (array) ( $payload['sql_names'] ?? array() );

			if ( count( $names ) === count( $payload['sql_files'] ) ) {
				$order                = $format->sql_order( $names );
				$payload['sql_files'] = array_values( array_map( static fn( $i ) => $payload['sql_files'][ $i ], $order ) );
			}

			$payload['phase'] = 'sql';
			$payload['sql']   = array(
				'index' => 0,
				'state' => array( 'rename' => (array) ( $payload['sql_renames'] ?? array() ) ),
			);
		}

		$progress = 2 + (int) ( 68 * min( 1, $payload['done'] / max( 1, (int) $payload['total'] ) ) );

		$job->set_payload( $payload );
		$job->update_progress(
			$progress,
			sprintf(
				/* translators: %s: number of files */
				__( 'Copying site files… %s so far', 'wrensafe' ),
				number_format_i18n( (int) $payload['files'] )
			)
		);
	}

	/**
	 * The file to read for a source: zips stored inside another zip
	 * (WPvivid) and compressed tars are first unpacked to a temporary file.
	 */
	private static function prepare_source( array $source, array &$payload ): string {
		if ( empty( $source['inner'] ) && 'tgz' !== $source['type'] ) {
			return $source['file'];
		}

		if ( '' !== $payload['src']['file'] && file_exists( $payload['src']['file'] ) ) {
			return $payload['src']['file'];
		}

		self::require_extensions( $source );

		$tmp = $payload['tmp'] . 'source-' . $payload['src']['index'] . ( 'tgz' === $source['type'] ? '.tar' : '.zip' );
		$out = fopen( $tmp, 'wb' );

		if ( false === $out ) {
			throw new \RuntimeException( esc_html__( 'A temporary file could not be created (check the storage folder permissions and free disk space).', 'wrensafe' ) );
		}

		if ( ! empty( $source['inner'] ) ) {
			$zip = new \ZipArchive();

			if ( true !== $zip->open( $source['file'] ) ) {
				fclose( $out );
				throw new \RuntimeException( esc_html__( 'The backup file could not be opened (it may be incomplete).', 'wrensafe' ) );
			}

			$in = $zip->getStream( (string) $source['inner'] );

			if ( false === $in ) {
				fclose( $out );
				$zip->close();
				throw new \RuntimeException( esc_html__( 'A part of the backup could not be read.', 'wrensafe' ) );
			}

			stream_copy_to_stream( $in, $out );
			fclose( $in );
			$zip->close();
		} else {
			$wrapper = preg_match( '/\.bz2$/i', $source['file'] ) ? 'compress.bzip2://' : 'compress.zlib://';
			$in      = fopen( $wrapper . $source['file'], 'rb' );

			if ( false === $in ) {
				fclose( $out );
				throw new \RuntimeException( esc_html__( 'The compressed archive could not be opened.', 'wrensafe' ) );
			}

			stream_copy_to_stream( $in, $out );
			fclose( $in );
		}

		fclose( $out );
		$payload['src']['file'] = $tmp;

		return $tmp;
	}

	private static function next_source( array $payload ): array {
		if ( '' !== $payload['src']['file'] && file_exists( $payload['src']['file'] ) ) {
			wp_delete_file( $payload['src']['file'] );
		}

		$payload['src'] = array(
			'index'  => $payload['src']['index'] + 1,
			'cursor' => 0,
			'file'   => '',
		);

		return $payload;
	}

	/**
	 * Note an SQL dump (decompressing .gz) and read any site details in its header.
	 *
	 * @param string $name The dump's path inside the backup (for ordering).
	 */
	private static function register_sql( array $payload, string $file, bool $gzip, string $name, AbstractFormat $format ): array {
		if ( $gzip ) {
			AbstractFormat::require_zlib();

			$plain = $payload['tmp'] . 'dump-' . count( $payload['sql_files'] ) . '-plain.sql';
			$in    = gzopen( $file, 'rb' );
			$out   = fopen( $plain, 'wb' );

			if ( false === $in || false === $out ) {
				if ( false !== $in ) {
					gzclose( $in );
				}
				if ( false !== $out ) {
					fclose( $out );
				}
				throw new \RuntimeException( esc_html__( 'The database dump could not be decompressed.', 'wrensafe' ) );
			}

			while ( ! gzeof( $in ) ) {
				$chunk = gzread( $in, 1048576 );

				if ( false === $chunk ) {
					gzclose( $in );
					fclose( $out );
					throw new \RuntimeException( esc_html__( 'The database dump is damaged (decompression failed).', 'wrensafe' ) );
				}

				fwrite( $out, $chunk );
			}

			gzclose( $in );
			fclose( $out );

			if ( 0 === strpos( $file, $payload['tmp'] ) ) {
				wp_delete_file( $file );
			}

			$file = $plain;
		}

		$head                   = AbstractFormat::sql_head( $file, false );
		$payload['meta']       += AbstractFormat::sql_header_meta( $head );
		$payload['sql_renames'] = (array) ( $payload['sql_renames'] ?? array() ) + $format->sql_table_renames( $head );
		$payload['sql_files'][] = $file;
		$payload['sql_names'][] = $name;

		return $payload;
	}

	/** Phase 'sql': split the dumps into per-table files. */
	private static function step_sql( ForeignImportJob $job, array $payload ): void {
		$deadline = self::deadline();
		$splitter = new SqlSplitter();
		$state    = $payload['sql']['state'];
		$count    = count( $payload['sql_files'] ); // Not changed while splitting.

		while ( $payload['sql']['index'] < $count && microtime( true ) < $deadline ) {
			$file = $payload['sql_files'][ $payload['sql']['index'] ];

			if ( $splitter->split( $file, $payload['tmp'] . 'db/', $state, $deadline ) ) {
				++$payload['sql']['index'];
				$state['offset'] = 0;

				if ( 0 === strpos( $file, $payload['tmp'] ) ) {
					wp_delete_file( $file );
				}
			}
		}

		$payload['sql']['state'] = $state;

		if ( $payload['sql']['index'] >= count( $payload['sql_files'] ) ) {
			$payload          = self::build_db_manifest( $payload );
			$payload['phase'] = 'pack';
			$payload['pack']  = array(
				'index' => 0,
				'part'  => 0,
			);
		}

		$job->set_payload( $payload );
		$job->update_progress(
			75,
			sprintf(
				/* translators: %s: number of database tables */
				__( 'Reading the database… %s tables', 'wrensafe' ),
				number_format_i18n( count( (array) ( $state['tables'] ?? array() ) ) )
			)
		);
	}

	private static function build_db_manifest( array $payload ): array {
		$tables = (array) ( $payload['sql']['state']['tables'] ?? array() );
		$prefix = (string) ( $payload['meta']['prefix'] ?? '' );

		if ( '' === $prefix ) {
			$prefix = SqlSplitter::detect_prefix( array_keys( $tables ) );
		}

		$payload['meta']['prefix'] = $prefix;
		$manifest                  = array();
		$skipped                   = (int) ( $payload['sql']['state']['skipped'] ?? 0 );

		if ( $skipped > 0 ) {
			$payload['warnings'][] = sprintf(
				/* translators: %s: number of database objects */
				_n( '%s view, trigger or stored routine in the database was not imported.', '%s views, triggers or stored routines in the database were not imported.', $skipped, 'wrensafe' ),
				number_format_i18n( $skipped )
			);
		}

		// Options the backup keeps outside its SQL: All-in-One WP Migration
		// stores the active plugins and theme in package.json and leaves them
		// out of (6.x) or blank in (7.x) its dump; its importer always applies
		// the package values, so they replace whatever the dump has.
		$extra = (array) ( $payload['meta']['options'] ?? array() );

		if ( ! empty( $extra ) && ! empty( $tables[ $prefix . 'options' ]['schema'] ) ) {
			$table = $prefix . 'options';
			$sql   = '';

			foreach ( $extra as $name => $value ) {
				$value = "'" . self::sql_string( (string) $value ) . "'";
				$sql  .= 'INSERT INTO `' . str_replace( '`', '``', $table ) . "` (`option_name`, `option_value`, `autoload`) VALUES ('" . self::sql_string( (string) $name ) . "', " . $value . ", 'yes') ON DUPLICATE KEY UPDATE `option_value` = " . $value . ";\n";
				++$tables[ $table ]['inserts'];
			}

			file_put_contents( $payload['tmp'] . 'db/data/' . $tables[ $table ]['slug'] . '.data.sql', $sql, FILE_APPEND );
		}

		foreach ( $tables as $table => $info ) {
			// A site that also had Wrensafe: its jobs, logs and backup list stay this site's.
			if ( '' !== $prefix && 0 === strpos( $table, $prefix . 'wpbp_' ) ) {
				continue;
			}

			if ( empty( $info['schema'] ) ) {
				$payload['warnings'][] = sprintf(
					/* translators: %s: database table name */
					__( 'Table %s has data but no structure in the dump and was skipped.', 'wrensafe' ),
					$table
				);
				continue;
			}

			$data = $payload['tmp'] . 'db/data/' . $info['slug'] . '.data.sql';

			if ( ! file_exists( $data ) ) {
				file_put_contents( $data, '' );
			}

			$manifest[] = array(
				'source_table'     => $table,
				'normalized_table' => ( '' !== $prefix && 0 === strpos( $table, $prefix ) ) ? substr( $table, strlen( $prefix ) ) : $table,
				'source_prefix'    => $prefix,
				'rows'             => (int) $info['inserts'],
				'schema_file'      => 'schema/' . $info['slug'] . '.schema.sql',
				'data_file'        => 'data/' . $info['slug'] . '.data.sql',
				'schema_checksum'  => '',
				'data_checksum'    => '',
			);
		}

		$payload['db_manifest'] = $manifest;

		return $payload;
	}

	/** A value for a single-quoted SQL string, escaped as mysqldump does. */
	private static function sql_string( string $value ): string {
		return strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/** Phase 'pack': add the per-table SQL files to the archive. */
	private static function step_pack( ForeignImportJob $job, array $payload ): void {
		$deadline = self::deadline();
		$writer   = new ZipStreamWriter( $payload['zip'] );
		$manifest = $payload['db_manifest'];
		$total    = count( $manifest );
		$part     = (int) $payload['pack']['part'];

		for ( $i = (int) $payload['pack']['index']; $i < $total; $i++ ) {
			$files = array(
				array( 'schema_file', 'schema_checksum' ),
				array( 'data_file', 'data_checksum' ),
			);

			for ( ; $part < 2; $part++ ) {
				list( $file_key, $sum_key ) = $files[ $part ];

				$sha = $writer->add_file( $payload['tmp'] . 'db/' . $manifest[ $i ][ $file_key ], 'database/' . $manifest[ $i ][ $file_key ], $deadline );

				if ( null === $sha ) {
					$payload['pack']        = array(
						'index' => $i,
						'part'  => $part,
					);
					$payload['db_manifest'] = $manifest;
					$payload['zip']         = $writer->state();
					$job->set_payload( $payload );
					$job->update_progress( 88, __( 'Packing the database…', 'wrensafe' ) );
					return;
				}

				$manifest[ $i ][ $sum_key ] = $sha;
			}

			$part = 0;
		}

		$payload['db_manifest'] = $manifest;
		$payload['zip']         = $writer->state();
		$payload['phase']       = 'finish';

		$job->set_payload( $payload );
		$job->update_progress( 95, __( 'Finishing…', 'wrensafe' ) );
	}

	/** Phase 'finish': metadata entries, close the archive, create the restore job. */
	private static function step_finish( ForeignImportJob $job, array $payload, AbstractFormat $format ): void {
		$meta     = $payload['meta'];
		$found    = (array) ( $payload['sql']['state']['found'] ?? array() );
		$home     = untrailingslashit( (string) ( $meta['home_url'] ?? '' ) );
		$home     = '' !== $home ? $home : (string) ( $found['home'] ?? ( $meta['site_url'] ?? ( $found['siteurl'] ?? '' ) ) );
		$siteurl  = untrailingslashit( (string) ( $meta['site_url'] ?? ( $found['siteurl'] ?? $home ) ) );
		$has_db   = ! empty( $payload['db_manifest'] );
		$has_file = $payload['files'] > 0;

		if ( ! $has_db && ! $has_file ) {
			throw new \RuntimeException( esc_html__( 'No site files or database were found in this backup.', 'wrensafe' ) );
		}

		$checksums = array();
		$handle    = fopen( $payload['checksums'], 'rb' );

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$parts = explode( "\t", rtrim( $line, "\n" ), 2 );
			if ( 2 === count( $parts ) ) {
				$checksums[ $parts[0] ] = $parts[1];
			}
		}

		fclose( $handle );

		$type     = $has_db && $has_file ? 'full' : ( $has_db ? 'database' : 'files' );
		$created  = '' !== (string) ( $meta['created_at'] ?? '' ) ? (string) $meta['created_at'] : gmdate( 'c' );
		$note     = $format->note();
		$manifest = array(
			'format_version'    => ManifestBuilder::FORMAT_VERSION,
			'sql_dump_version'  => ManifestBuilder::SQL_DUMP_VERSION,
			'generator'         => 'Wrensafe ' . WPBP_VERSION . ' (converted from ' . $format->name() . ')',
			'archive_filename'  => basename( (string) $payload['out'] ),
			'backup_type'       => $type,
			'created_at'        => $created,
			'contains_database' => $has_db,
			'contains_files'    => $has_file,
			'container_format'  => 'zip',
			'source_format'     => $format->key(),
		);
		// Source values come from the other plugin's archive. When it doesn't
		// record the uploads address, WordPress's default for that site is used.
		$metadata = array(
			'site_url'        => $home,
			'site_url_option' => $siteurl,
			'uploads_baseurl' => (string) ( $meta['uploads_baseurl'] ?? ( '' !== $home ? $home . '/wp-content/uploads' : '' ) ),
			'uploads_basedir' => (string) ( $meta['uploads_basedir'] ?? '' ),
			'abspath'         => (string) ( $meta['abspath'] ?? '' ),
			'content_dir'     => (string) ( $meta['content_dir'] ?? '' ),
			'site_name'       => '',
			'wp_version'      => (string) ( $meta['wp_version'] ?? '' ),
			'php_version'     => (string) ( $meta['php_version'] ?? '' ),
			'mysql_version'   => '',
			'table_prefix'    => (string) ( $meta['prefix'] ?? '' ),
			'is_multisite'    => ! empty( $meta['is_multisite'] ),
			'file_count'      => (int) $payload['files'],
			'total_size'      => (int) $payload['total'],
			'tables_exported' => count( (array) ( $payload['db_manifest'] ?? array() ) ),
			'note'            => $note,
			'tag'             => '',
			'import_warnings' => array_values( (array) $payload['warnings'] ),
		);

		$writer  = new ZipStreamWriter( $payload['zip'] );
		$entries = array(
			'manifest.json'          => $manifest,
			'metadata.json'          => $metadata,
			'database/manifest.json' => (array) ( $payload['db_manifest'] ?? array() ),
			'checksums.json'         => array(
				'files'    => $checksums,
				'database' => '',
			),
			'search-replace.json'    => array(
				'performed'    => false,
				'replacements' => array(),
			),
			'backup-chain.json'      => array(
				'type'               => 'full',
				'baseline_backup_id' => null,
				'prior_chain_ids'    => array(),
			),
			'encryption.json'        => array( 'encrypted' => false ),
			'report.json'            => array(
				'backup_type' => $type,
				'created_at'  => $created,
				'warnings'    => $payload['warnings'],
			),
		);

		foreach ( $entries as $name => $data ) {
			$writer->add_string( $name, (string) wp_json_encode( $data ) );
		}

		$writer->finish();

		$result = ImportValidator::validate_and_create_job( (string) $payload['out'], 'import:' . $format->key(), array( 'source_format' => $format->key() ) );

		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( esc_html( (string) ( $result['message'] ?? __( 'The converted backup could not be read.', 'wrensafe' ) ) ) );
		}

		$result['warnings'] = $payload['warnings'];
		$result['format']   = $format->name();

		FileSystem::delete_directory( (string) $payload['tmp'] );
		ImportSet::delete( (string) $payload['set_id'] );

		Logger::success(
			'foreign_import_converted: ' . $format->name() . ' backup ready to restore (' . (int) $payload['files'] . ' files, ' . count( (array) $payload['db_manifest'] ) . ' tables).',
			array(),
			$job->id()
		);

		$job->mark_completed( $result );
	}

	/** Remove a half-written archive and temp files after a failure. */
	private static function cleanup_partial( ForeignImportJob $job, array $payload ): void {
		if ( ! empty( $payload['out'] ) && file_exists( $payload['out'] ) ) {
			wp_delete_file( $payload['out'] );
		}

		if ( ! empty( $payload['tmp'] ) ) {
			FileSystem::delete_directory( (string) $payload['tmp'] );
		}
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
