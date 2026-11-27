<?php
/**
 * Off-site copies of backups (Settings → Remote storage).
 *
 * One destination: FTP/FTPS, S3-compatible storage, or a storage type an
 * add-on registers (see registry()). Right after a backup is made, the
 * backup job copies it there in short steps (see
 * BackupManager::step_remote_copy()) and then keeps only the newest copies
 * there. Passwords and secret keys are stored encrypted (SecretBox).
 *
 * @package WPBP\Remote
 */

namespace WPBP\Remote;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Notifier;
use WPBP\Utils\Logger;
use WPBP\Utils\SecretBox;

defined( 'ABSPATH' ) || exit;

final class RemoteStorage {

	public const OPTION = 'wpbp_remote_storage';

	/** Settings shared by every type (a type can't use these keys). */
	private const COMMON = array(
		'type'           => '',
		'copy_automatic' => true,
		'copy_manual'    => false,
		'keep'           => 10,
	);

	/** Kinds of form field a type can have. */
	private const FIELD_TYPES = array( 'text', 'number', 'password', 'url', 'checkbox' );

	/**
	 * The built-in types, in the same form as a type an add-on registers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function builtin_types(): array {
		$folder_help = __( 'Inside the folder you log in to, unless it starts with / (a full path). Created if it doesn’t exist. Use a folder only for these backups.', 'wrensafe' );

		return array(
			'ftp' => array(
				'label'    => __( 'FTP / FTPS', 'wrensafe' ),
				'class'    => FtpTarget::class,
				'defaults' => array(
					'host'     => '',
					'port'     => 21,
					'username' => '',
					'password' => '',
					'folder'   => 'wrensafe',
					'ftps'     => true,
					'passive'  => true,
				),
				'secrets'  => array( 'password' ),
				'fields'   => array(
					'host'     => array(
						'label'       => __( 'Server', 'wrensafe' ),
						'placeholder' => 'ftp.example.com',
					),
					'port'     => array(
						'label' => __( 'Port', 'wrensafe' ),
						'type'  => 'number',
						'min'   => 1,
						'max'   => 65535,
					),
					'username' => array( 'label' => __( 'Username', 'wrensafe' ) ),
					'password' => array(
						'label' => __( 'Password', 'wrensafe' ),
						'type'  => 'password',
					),
					'folder'   => array(
						'label' => __( 'Folder', 'wrensafe' ),
						'help'  => $folder_help,
					),
					'ftps'     => array(
						'label' => __( 'Use TLS (FTPS)', 'wrensafe' ),
						'type'  => 'checkbox',
						'help'  => __( 'Recommended: without TLS the password and the backup travel unencrypted. Turn it off only if your server doesn’t support it.', 'wrensafe' ),
					),
					'passive'  => array(
						'label' => __( 'Passive mode', 'wrensafe' ),
						'type'  => 'checkbox',
						'help'  => __( 'Works behind most firewalls. Turn it off only if your FTP host asks you to.', 'wrensafe' ),
					),
				),
			),
			's3'  => array(
				'label'    => __( 'S3-compatible storage', 'wrensafe' ),
				'class'    => S3Target::class,
				'defaults' => array(
					'endpoint'   => '',
					'region'     => 'us-east-1',
					'bucket'     => '',
					'access_key' => '',
					'secret_key' => '',
					'folder'     => 'wrensafe',
					'path_style' => false,
				),
				'secrets'  => array( 'secret_key' ),
				'fields'   => array(
					'endpoint'   => array(
						'label'       => __( 'Endpoint', 'wrensafe' ),
						'type'        => 'url',
						'help'        => __( 'The storage service’s S3 address, for example https://s3.us-west-004.backblazeb2.com, https://s3.wasabisys.com or https://ACCOUNT.r2.cloudflarestorage.com. For Amazon S3, use the endpoint of your bucket’s region from the list at docs.aws.amazon.com/general/latest/gr/s3.html.', 'wrensafe' ),
						'placeholder' => 'https://s3.example.com',
					),
					'region'     => array(
						'label' => __( 'Region', 'wrensafe' ),
						'help'  => __( 'As shown by the service, for example us-east-1 or eu-central-003 (Cloudflare R2: auto).', 'wrensafe' ),
					),
					'bucket'     => array( 'label' => __( 'Bucket', 'wrensafe' ) ),
					'access_key' => array( 'label' => __( 'Access key', 'wrensafe' ) ),
					'secret_key' => array(
						'label' => __( 'Secret key', 'wrensafe' ),
						'type'  => 'password',
					),
					'folder'     => array(
						'label' => __( 'Folder in the bucket', 'wrensafe' ),
						'help'  => __( 'Optional. Backups are stored under this prefix.', 'wrensafe' ),
					),
					'path_style' => array(
						'label' => __( 'Path-style addresses', 'wrensafe' ),
						'type'  => 'checkbox',
						'help'  => __( 'Needed for MinIO and some other self-hosted services. Leave it off for Amazon S3, Backblaze B2, Wasabi and Cloudflare R2.', 'wrensafe' ),
					),
				),
			),
		);
	}

	/**
	 * Every storage type offered under Settings → Remote storage: the
	 * built-in ones first, then those add-ons register.
	 *
	 * @return array<string,array{label:string,class:string,defaults:array<string,mixed>,secrets:string[],fields:array<string,array{label:string,type:string,help:string,placeholder:string,min:?int,max:?int}>}>
	 */
	public static function registry(): array {
		$builtin = self::builtin_types();

		/**
		 * Filters the storage types backups can be copied to (Settings →
		 * Remote storage), so an add-on can offer another one.
		 *
		 * Add an entry under a new key (lowercase letters, digits and
		 * underscores). The key is saved as the chosen type, names the
		 * type's settings in the wpbp_remote_storage option, and is shown
		 * in the backup list ("remote:{key}"). The built-in "ftp" and "s3"
		 * entries are passed in for reference but can't be replaced or
		 * removed, and "type", "copy_automatic", "copy_manual" and "keep"
		 * are reserved. An entry that doesn't follow this contract is left
		 * out.
		 *
		 * Each entry is an array with:
		 *
		 * - label    (string) Name shown in the list, the backup list and the
		 *            Activity Log.
		 * - class    (string) A class implementing WPBP\Remote\RemoteTarget.
		 *            Its static unavailable_reason() returns '' when the
		 *            server can use it, otherwise why not (the type is then
		 *            listed as not available, with the reason). Its
		 *            constructor receives the type's settings as an array,
		 *            with secrets decrypted.
		 * - defaults (array)  Setting key => default value. Stored values
		 *            keep these keys only.
		 * - secrets  (string[]) Keys stored encrypted with the site's own
		 *            keys and never printed in the form; an empty field
		 *            keeps the saved value. Password fields always are.
		 * - fields   (array)  The form, in order: setting key => array with
		 *            label (string), type (text, number, password, url or
		 *            checkbox; default text), help (string, optional),
		 *            placeholder (string, optional), and for a number, min
		 *            and max (int, optional). Each value is cleaned for its
		 *            type when the form is saved or tested: text with
		 *            sanitize_text_field(), a URL with esc_url_raw() (http
		 *            or https), a number as a whole number within min and
		 *            max, a checkbox as true or false, a password exactly as
		 *            typed less control characters. A key in defaults
		 *            without a field keeps its stored value.
		 *
		 * The settings of a type that is no longer registered (its add-on
		 * was deactivated) are kept, and used again once it's back.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string,array<string,mixed>> $types Storage types by key.
		 */
		$filtered = apply_filters( 'wpbp_remote_storage_types', $builtin );
		$types    = $builtin + array_diff_key( is_array( $filtered ) ? $filtered : array(), $builtin );
		$registry = array();

		foreach ( $types as $key => $type ) {
			$entry = self::normalize_type( $key, $type );

			if ( null !== $entry ) {
				$registry[ $key ] = $entry;
			}
		}

		return $registry;
	}

	/**
	 * A registered type in a dependable shape, or null when it can't be
	 * used (see the wpbp_remote_storage_types filter).
	 *
	 * @param mixed $key
	 * @param mixed $type
	 * @return array<string,mixed>|null
	 */
	private static function normalize_type( $key, $type ): ?array {
		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]+$/', $key ) || array_key_exists( $key, self::COMMON ) || ! is_array( $type ) ) {
			return null;
		}

		$class = is_string( $type['class'] ?? null ) ? $type['class'] : '';

		if ( '' === $class || ! class_exists( $class ) || ! is_subclass_of( $class, RemoteTarget::class ) ) {
			return null;
		}

		$defaults = array();

		foreach ( is_array( $type['defaults'] ?? null ) ? $type['defaults'] : array() as $field => $default ) {
			if ( is_string( $field ) && preg_match( '/^[a-z0-9_]+$/', $field ) && ( is_scalar( $default ) || null === $default ) ) {
				$defaults[ $field ] = $default;
			}
		}

		$secrets = array();

		foreach ( is_array( $type['secrets'] ?? null ) ? $type['secrets'] : array() as $field ) {
			if ( is_string( $field ) && array_key_exists( $field, $defaults ) ) {
				$secrets[] = $field;
			}
		}

		$fields = array();

		foreach ( is_array( $type['fields'] ?? null ) ? $type['fields'] : array() as $field => $definition ) {
			if ( ! is_string( $field ) || ! preg_match( '/^[a-z0-9_]+$/', $field ) || ! is_array( $definition ) ) {
				continue;
			}

			$kind = $definition['type'] ?? 'text';
			$kind = in_array( $kind, self::FIELD_TYPES, true ) ? $kind : 'text';
			$kind = in_array( $field, $secrets, true ) ? 'password' : $kind;

			if ( ! array_key_exists( $field, $defaults ) ) {
				$defaults[ $field ] = 'checkbox' === $kind ? false : ( 'number' === $kind ? 0 : '' );
			}

			if ( 'password' === $kind && ! in_array( $field, $secrets, true ) ) {
				$secrets[] = $field;
			}

			$fields[ $field ] = array(
				'label'       => is_scalar( $definition['label'] ?? null ) ? (string) $definition['label'] : $field,
				'type'        => $kind,
				'help'        => is_scalar( $definition['help'] ?? null ) ? (string) $definition['help'] : '',
				'placeholder' => is_scalar( $definition['placeholder'] ?? null ) ? (string) $definition['placeholder'] : '',
				'min'         => is_numeric( $definition['min'] ?? null ) ? (int) $definition['min'] : null,
				'max'         => is_numeric( $definition['max'] ?? null ) ? (int) $definition['max'] : null,
			);
		}

		foreach ( $secrets as $field ) {
			$defaults[ $field ] = '';
		}

		$label = is_scalar( $type['label'] ?? null ) ? trim( (string) $type['label'] ) : '';

		return array(
			'label'    => '' !== $label ? $label : $key,
			'class'    => $class,
			'defaults' => $defaults,
			'secrets'  => $secrets,
			'fields'   => $fields,
		);
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		$defaults = self::COMMON;

		foreach ( self::registry() as $type => $definition ) {
			$defaults[ $type ] = $definition['defaults'];
		}

		return $defaults;
	}

	/** Display names. */
	public static function types(): array {
		return wp_list_pluck( self::registry(), 'label' );
	}

	/**
	 * Whether the saved destination is SFTP, which Wrensafe 1.1.4 no longer
	 * offers (its connection needed PHP's ssh2 stream wrapper, which many
	 * hosts block), and no add-on offers it either. The settings are kept;
	 * copies stop until another destination is chosen.
	 */
	public static function has_retired_sftp(): bool {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) && 'sftp' === ( $saved['type'] ?? '' ) && ! isset( self::types()['sftp'] );
	}

	/** Saved settings (secrets still sealed). */
	public static function get(): array {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$settings = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );

		foreach ( array_keys( array_diff_key( $defaults, self::COMMON ) ) as $type ) {
			$settings[ $type ] = array_merge( $defaults[ $type ], is_array( $saved[ $type ] ?? null ) ? array_intersect_key( $saved[ $type ], $defaults[ $type ] ) : array() );
		}

		return $settings;
	}

	public static function is_enabled(): bool {
		return isset( self::types()[ self::get()['type'] ] );
	}

	/** Whether a secret is saved for the type ("leave blank to keep"). */
	public static function has_secret( string $type, string $field ): bool {
		return '' !== (string) ( self::get()[ $type ][ $field ] ?? '' );
	}

	/** Fields that don't change where a secret is sent: a blank secret is still kept when only these change. */
	private const SAME_ACCOUNT_FIELDS = array( 'folder', 'passive', 'path_style' );

	/**
	 * Settings from the Remote storage form, cleaned. A blank secret keeps
	 * the saved one, as long as it is for the same server and account.
	 *
	 * @param array<string,mixed> $posted Unslashed values: from options.php
	 *                                    (Settings::sanitize()), which unslashes
	 *                                    them, or from RemoteStorageAjax::test().
	 * @return array<string,mixed> Settings with secrets sealed.
	 */
	public static function sanitize( array $posted ): array {
		$current  = self::get();
		$settings = $current;
		$registry = self::registry();
		$type     = sanitize_key( (string) ( $posted['type'] ?? '' ) );

		$settings['type']           = isset( $registry[ $type ] ) ? $type : '';
		$settings['copy_automatic'] = ! empty( $posted['copy_automatic'] );
		$settings['copy_manual']    = ! empty( $posted['copy_manual'] );
		$settings['keep']           = min( 1000, absint( $posted['keep'] ?? 10 ) );

		foreach ( $registry as $kind => $definition ) {
			$in = is_array( $posted[ $kind ] ?? null ) ? $posted[ $kind ] : array();

			if ( ! $in ) {
				continue;
			}

			foreach ( $definition['fields'] as $field => $spec ) {
				$raw = $in[ $field ] ?? null;

				if ( 'password' === $spec['type'] ) {
					// Kept exactly as typed (and stored encrypted), less control characters.
					$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', is_scalar( $raw ) ? (string) $raw : '' );

					if ( '' !== $value ) {
						$settings[ $kind ][ $field ] = SecretBox::seal( $value );
					}
					continue;
				}

				switch ( $spec['type'] ) {
					case 'checkbox':
						$settings[ $kind ][ $field ] = ! empty( $raw );
						break;

					case 'number':
						$number = absint( is_scalar( $raw ) ? $raw : $definition['defaults'][ $field ] );
						$number = null !== $spec['min'] ? max( $spec['min'], $number ) : $number;

						$settings[ $kind ][ $field ] = null !== $spec['max'] ? min( $spec['max'], $number ) : $number;
						break;

					case 'url':
						$settings[ $kind ][ $field ] = esc_url_raw( trim( is_scalar( $raw ) ? (string) $raw : '' ), array( 'https', 'http' ) );
						break;

					default:
						$settings[ $kind ][ $field ] = trim( sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' ) );
				}
			}

			// A saved secret is only reused for the server and account it was typed for: otherwise someone
			// allowed to edit this form could point it at their own server and receive the saved password.
			foreach ( $definition['secrets'] as $secret ) {
				// Typed means a new secret is being saved now (whatever else was posted under its name).
				$typed = (string) ( $settings[ $kind ][ $secret ] ?? '' ) !== (string) ( $current[ $kind ][ $secret ] ?? '' );

				if ( $typed || '' === (string) ( $current[ $kind ][ $secret ] ?? '' ) ) {
					continue;
				}

				foreach ( $definition['fields'] as $field => $spec ) {
					if ( 'password' === $spec['type'] || in_array( $field, self::SAME_ACCOUNT_FIELDS, true ) ) {
						continue;
					}

					if ( (string) ( $settings[ $kind ][ $field ] ?? '' ) !== (string) ( $current[ $kind ][ $field ] ?? $definition['defaults'][ $field ] ?? '' ) ) {
						$settings[ $kind ][ $secret ] = '';
						break;
					}
				}
			}
		}

		return $settings;
	}

	/**
	 * Save the settings. The settings of a type no longer registered (its
	 * add-on isn't active now) are kept for when it's back.
	 */
	public static function save( array $settings ): void {
		$stored = get_option( self::OPTION, array() );

		foreach ( is_array( $stored ) ? $stored : array() as $key => $value ) {
			if ( is_array( $value ) && ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * The target for $settings (default: the saved settings), secrets opened.
	 */
	public static function target( ?array $settings = null ): RemoteTarget {
		$settings = $settings ?? self::get();
		$type     = (string) $settings['type'];
		$registry = self::registry();

		if ( ! isset( $registry[ $type ] ) ) {
			throw new \RuntimeException( esc_html__( 'No remote storage is set up.', 'wrensafe' ) );
		}

		$config = (array) ( $settings[ $type ] ?? array() );

		foreach ( $registry[ $type ]['secrets'] as $field ) {
			$config[ $field ] = SecretBox::open( (string) ( $config[ $field ] ?? '' ) );
		}

		$class = $registry[ $type ]['class'];

		return new $class( $config );
	}

	/**
	 * An error's message as plain text. Exception messages are HTML-escaped
	 * when thrown; they're shown with .text(), logged and emailed as plain
	 * text, so an apostrophe from the server would otherwise read "&#039;".
	 */
	public static function error_text( \Throwable $e ): string {
		return \WPBP\Utils\Errors::text( $e );
	}

	/** Why a type can't be used on this server ('' when it can). */
	public static function unavailable_reason( string $type ): string {
		$registry = self::registry();

		return isset( $registry[ $type ] ) ? (string) $registry[ $type ]['class']::unavailable_reason() : '';
	}

	/**
	 * Whether this backup job should be copied off-site. Rollback points
	 * (made in the middle of a restore) never are, nor backups an AI
	 * assistant made: a copy applies the off-site retention, which deletes
	 * older copies there, and an assistant must never cause a deletion.
	 */
	public static function wants( array $payload ): bool {
		if ( ! self::is_enabled() || 0 === strpos( (string) ( $payload['note'] ?? '' ), 'Automatic rollback point' ) || 'ai_assistant' === ( $payload['made_by'] ?? '' ) ) {
			return false;
		}

		$settings  = self::get();
		$automatic = ! empty( $payload['schedule_id'] ) || 'pre_update' === (string) ( $payload['tag'] ?? '' );

		return $automatic ? ! empty( $settings['copy_automatic'] ) : ! empty( $settings['copy_manual'] );
	}

	/**
	 * Copy one backup, part by part. Returns true when the copy is complete
	 * (the caller then records it); throws on a problem.
	 *
	 * @param array<string,mixed> $state Upload state kept by the caller ([] to start).
	 */
	public static function copy_step( array &$state, string $local_path, string $name, float $deadline ): bool {
		// A scheduled copy runs without the Settings page that explains this:
		// a missing PHP extension would otherwise end in "Call to undefined
		// function".
		$reason = self::unavailable_reason( (string) self::get()['type'] );

		if ( '' !== $reason ) {
			throw new \RuntimeException( esc_html( $reason ) );
		}

		$target = self::target();

		if ( empty( $state ) ) {
			clearstatcache( true, $local_path );
			$state = $target->begin( $name, (int) filesize( $local_path ) );
		}

		if ( ! $target->send( $local_path, $state, $deadline ) ) {
			return false;
		}

		$target->finish( $state );
		self::apply_retention( $target, $name );

		return true;
	}

	/** Drop an unfinished upload after a failure. */
	public static function abort( array $state ): void {
		if ( ! empty( $state ) && self::is_enabled() && '' === self::unavailable_reason( (string) self::get()['type'] ) ) {
			try {
				self::target()->abort( $state );
			} catch ( \Throwable $e ) {
				unset( $e ); // Best effort.
			}
		}
	}

	/**
	 * Keep only the newest copies there (0 keeps them all). The copy just
	 * made is never deleted.
	 */
	private static function apply_retention( RemoteTarget $target, string $just_copied ): void {
		$keep = (int) self::get()['keep'];

		if ( $keep <= 0 ) {
			return;
		}

		try {
			$copies = $target->list_backups();
		} catch ( \RuntimeException $e ) {
			Logger::warning( 'remote_retention_skipped: the remote copies couldn’t be listed (' . self::error_text( $e ) . ').' );
			return;
		}

		$removed = 0;

		foreach ( array_slice( $copies, $keep ) as $copy ) {
			if ( $copy['name'] !== $just_copied && $target->delete( $copy['name'] ) ) {
				++$removed;
			}
		}

		if ( $removed ) {
			Logger::info( sprintf( 'remote_retention_applied: %d older off-site copies deleted (keeping the newest %d).', $removed, $keep ) );
		}
	}

	/**
	 * Record the outcome on the backup and report a failure.
	 */
	public static function record( int $backup_id, bool $ok, string $message = '' ): void {
		$type = (string) self::get()['type'];

		BackupsRepository::update( $backup_id, array( 'destination' => ( $ok ? 'remote:' : 'remote-failed:' ) . $type ) );

		if ( $ok ) {
			Logger::success( sprintf( 'remote_copy_completed: backup #%d copied to %s.', $backup_id, self::types()[ $type ] ?? $type ) );
			return;
		}

		Logger::error( sprintf( 'remote_copy_failed: backup #%d wasn’t copied to %s: %s The backup itself is fine and stays on this server.', $backup_id, self::types()[ $type ] ?? $type, $message ) );
		Notifier::remote_copy_failed( $backup_id, $message );
	}

	/**
	 * @param array<int,array{name:string,time:int,size:int}> $items
	 * @return array<int,array{name:string,time:int,size:int}>
	 */
	public static function newest_first( array $items ): array {
		usort(
			$items,
			static function ( $a, $b ) {
				$by_time = $b['time'] <=> $a['time'];

				return 0 !== $by_time ? $by_time : strcmp( $b['name'], $a['name'] );
			}
		);

		return $items;
	}
}
