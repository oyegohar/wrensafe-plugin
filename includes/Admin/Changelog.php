<?php
/**
 * The plugin's release history, read from changelog.txt in the plugin folder (nothing is fetched from
 * anywhere), and the small marker on the header's Changelog link after an update.
 *
 * Format of changelog.txt: "= 1.2.8 - 2026-11-23 =" for a release (the date is optional), a plain line for
 * an introduction, and "* Fixed: text" for a change.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\AbstractPage;
use WPBP\Admin\Pages\ChangelogPage;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

final class Changelog {

	/** User meta: the newest version this user has seen the Changelog for. */
	const SEEN = 'wpbp_changelog_seen';

	/** Filter group of each kind of change. */
	const GROUPS = array(
		'new'      => 'new',
		'improved' => 'improved',
		'changed'  => 'improved',
		'removed'  => 'improved',
		'note'     => 'improved',
		'fixed'    => 'fixed',
		'security' => 'security',
	);

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, ChangelogPage::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wpbp-changelog', WPBP_PLUGIN_URL . 'assets/admin/css/changelog.css', array( 'wpbp-admin' ), WPBP_VERSION );
		wp_style_add_data( 'wpbp-changelog', 'rtl', 'replace' );
		wp_enqueue_script( 'wpbp-changelog', WPBP_PLUGIN_URL . 'assets/admin/js/changelog.js', array(), WPBP_VERSION, true );
		wp_localize_script(
			'wpbp-changelog',
			'wpbpChangelog',
			array(
				/* translators: %d: number of changes in this release */
				'showAll'  => __( 'Show all %d changes', 'wrensafe' ),
				'showLess' => __( 'Show fewer', 'wrensafe' ),
				/* translators: %d: number of older releases */
				'older'    => __( 'Show %d older versions', 'wrensafe' ),
				/* translators: 1: number of changes, 2: number of versions */
				'found'    => __( '%1$d changes in %2$d versions', 'wrensafe' ),
				'foundOne' => __( '1 change in 1 version', 'wrensafe' ),
			)
		);
	}

	/** The Changelog is for Wrensafe's own users: not on a White Label site. */
	public static function available(): bool {
		return AbstractPage::brand()['credit'] && CapabilityManager::current_user_can( CapabilityManager::VIEW_DASHBOARD );
	}

	/** This user hasn't opened the Changelog since Wrensafe was updated (never on a new install). */
	public static function has_news(): bool {
		$seen = (string) get_user_meta( get_current_user_id(), self::SEEN, true );
		$from = '' !== $seen ? $seen : (string) get_option( 'wpbp_version', WPBP_VERSION );

		return version_compare( $from, WPBP_VERSION, '<' );
	}

	public static function mark_seen(): void {
		if ( WPBP_VERSION !== (string) get_user_meta( get_current_user_id(), self::SEEN, true ) ) {
			update_user_meta( get_current_user_id(), self::SEEN, WPBP_VERSION );
		}
	}

	/** @return array<string,string> Kind of change => its label. */
	public static function labels(): array {
		return array(
			'new'      => __( 'New', 'wrensafe' ),
			'improved' => __( 'Improved', 'wrensafe' ),
			'fixed'    => __( 'Fixed', 'wrensafe' ),
			'security' => __( 'Security', 'wrensafe' ),
			'changed'  => __( 'Changed', 'wrensafe' ),
			'removed'  => __( 'Removed', 'wrensafe' ),
			'note'     => __( 'Note', 'wrensafe' ),
		);
	}

	/** @return array<int,array{version:string,date:string,intro:string,entries:array<int,array{type:string,text:string}>}> */
	public static function releases(): array {
		$file = WPBP_PLUGIN_DIR . 'changelog.txt';

		if ( ! is_readable( $file ) ) {
			return array();
		}

		return self::parse( (array) file( $file, FILE_IGNORE_NEW_LINES ) );
	}

	/**
	 * @param array<int,string> $lines
	 * @return array<int,array{version:string,date:string,intro:string,entries:array<int,array{type:string,text:string}>}>
	 */
	public static function parse( array $lines ): array {
		$releases = array();
		$current  = -1;

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( 1 === preg_match( '/^= (.+?)(?: - (\d{4}-\d{2}-\d{2}))? =$/', $line, $match ) ) {
				$releases[] = array(
					'version' => $match[1],
					'date'    => $match[2] ?? '',
					'intro'   => '',
					'entries' => array(),
				);
				++$current;
			} elseif ( $current >= 0 && 0 === strpos( $line, '* ' ) ) {
				$text = substr( $line, 2 );
				$type = 'note';

				if ( 1 === preg_match( '/^(New|Improved|Fixed|Security|Changed|Removed):\s*(.+)$/u', $text, $match ) ) {
					$type = strtolower( $match[1] );
					$text = ucfirst( $match[2] );
				}

				$releases[ $current ]['entries'][] = array(
					'type' => $type,
					'text' => $text,
				);
			} elseif ( $current >= 0 && '' !== $line ) {
				$releases[ $current ]['intro'] = trim( $releases[ $current ]['intro'] . ' ' . $line );
			}
		}

		return $releases;
	}

	/** A line of the changelog as safe HTML: escaped, with `code` kept. */
	public static function format( string $text ): string {
		return (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', esc_html( $text ) );
	}
}
