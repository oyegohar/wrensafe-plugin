<?php
/**
 * Wrensafe → Fix Links: checks links and media after a restore or a move,
 * and fixes broken links (see WPBP\Restore\LinkCheck). The same results are
 * shown in the notice after a restore.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Restore\LinkCheck;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class FixLinksPage extends AbstractPage {

	const SLUG = 'wrensafe-fix-links';

	/** Examples shown per kind of problem on this screen. */
	const SHOW = 5;

	public function get_title(): string {
		return __( 'Fix Links', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'After a restore or a move, finds links that still point to the old site or are broken, fixes them, and lists media files that aren’t on this site.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::RESTORE_BACKUPS;
	}

	protected function render_content(): void {
		$state  = LinkCheck::state();
		$report = LinkCheck::report( $state );
		$old    = (array) $report['addresses'];
		?>
		<div class="wpbp-card wpbp-lc-card">
			<h2><?php esc_html_e( 'Check links and media', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'Wrensafe reads posts, pages, page-builder data, settings and the Media Library, and looks for:', 'wrensafe' ); ?></p>
			<ul class="wpbp-lc-list">
				<li><?php esc_html_e( 'links that still point to the old site’s address;', 'wrensafe' ); ?></li>
				<li><?php esc_html_e( 'links with a server folder in their address, so images and files don’t load;', 'wrensafe' ); ?></li>
				<li><?php esc_html_e( 'links to this site over http:// when it uses https://;', 'wrensafe' ); ?></li>
				<li><?php esc_html_e( 'links to media files that aren’t on this site, and Media Library items without their file.', 'wrensafe' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'The check changes nothing. The fix makes a database backup first. Email addresses and other domains are left alone. A link to another site that contains the old address, such as a share or web-archive link, is updated too.', 'wrensafe' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Old addresses', 'wrensafe' ); ?></th>
					<td>
						<label for="wpbp-lc-more" class="screen-reader-text"><?php esc_html_e( 'Old addresses, one per line', 'wrensafe' ); ?></label>
						<textarea id="wpbp-lc-more" class="large-text code" rows="<?php echo esc_attr( (string) max( 2, count( $old ) ) ); ?>" placeholder="<?php echo esc_attr( 'https://old-site.example' ); ?>"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $old ) ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line: the address the site had before it was moved (filled in after a restore; remove any that is wrong). Each is also looked for with and without “www.”, over http and https. Links to this site’s media and folders are checked either way.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpbp-lc-check"><?php esc_html_e( 'Check links and media', 'wrensafe' ); ?></button>
			</p>
		</div>

		<div class="wpbp-card wpbp-lc-results-card" id="wpbp-lc-box" data-token="<?php echo esc_attr( (string) $report['token'] ); ?>" data-status="<?php echo esc_attr( (string) $report['status'] ); ?>" aria-live="polite"<?php echo 'idle' === $report['status'] ? ' hidden' : ''; ?>>
			<div class="wpbp-lc-body"><?php echo wp_kses_post( self::results_html( $report, false ) ); ?></div>
		</div>
		<?php
	}

	/**
	 * The results (or the progress), for this screen and the notice.
	 *
	 * @param array<string,mixed> $r       LinkCheck::report().
	 * @param bool                $compact For the notice: counts and buttons, no lists.
	 */
	public static function results_html( array $r, bool $compact ): string {
		$status = (string) $r['status'];
		$found  = (array) $r['found'];
		$out    = '';

		if ( in_array( $status, array( 'pending', 'checking', 'fixing' ), true ) ) {
			if ( 'fixing' === $status ) {
				$label = 'backup' === $r['phase'] ? __( 'Backing up the database before fixing the links…', 'wrensafe' ) : __( 'Fixing links…', 'wrensafe' );
			} else {
				$label = ! empty( $r['fixed'] ) ? __( 'Checking the links again…', 'wrensafe' ) : __( 'Checking links and media…', 'wrensafe' );
			}

			$out .= '<p class="wpbp-lc-progress-label">' . esc_html( $label ) . '</p>';
			$out .= '<div class="wpbp-lc-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) (int) $r['percent'] ) . '"><span style="width:' . esc_attr( (string) max( 3, (int) $r['percent'] ) ) . '%"></span></div>';

			// The notice can always be closed, even if this never finishes.
			if ( $compact ) {
				$out .= '<p class="wpbp-lc-actions"><button type="button" class="button-link wpbp-lc-dismiss">' . esc_html__( 'Dismiss', 'wrensafe' ) . '</button></p>';
			}

			return $out;
		}

		if ( 'checked' !== $status ) {
			return '';
		}

		if ( '' !== (string) $r['error'] ) {
			$out .= '<p class="wpbp-lc-line is-error">' . esc_html( (string) $r['error'] ) . '</p>';
		}

		if ( ! empty( $r['fixed'] ) ) {
			$fixed = (array) $r['fixed'];
			$out  .= '<p class="wpbp-lc-line is-good">' . esc_html(
				sprintf(
					/* translators: %s: number of database rows */
					_n( 'Links fixed: %s item changed.', 'Links fixed: %s items changed.', (int) $fixed['rows'], 'wrensafe' ),
					number_format_i18n( (int) $fixed['rows'] )
				)
			);

			if ( ! empty( $fixed['backup'] ) ) {
				$out .= ' <a href="' . esc_url( admin_url( 'admin.php?page=wrensafe-browser' ) ) . '">' . esc_html__( 'The database backup made first is in the Backup Browser (“Before fixing links”).', 'wrensafe' ) . '</a>';
			}

			$out .= '</p>';
		}

		$lines = array();

		if ( $found['folder_links'] ) {
			$lines[] = array(
				'warning',
				/* translators: %s: number of links */
				sprintf( _n( '%s link has a server folder in its address, so the file doesn’t load.', '%s links have a server folder in their address, so the files don’t load.', (int) $found['folder_links'], 'wrensafe' ), number_format_i18n( (int) $found['folder_links'] ) ),
				'folder',
			);
		}

		if ( ! empty( $found['odd_links'] ) ) {
			$lines[] = array(
				'info',
				/* translators: %s: number of links */
				sprintf( _n( '%s link looks like it has a folder in its address, but not one of this site’s. It was left alone: check it by hand.', '%s links look like they have a folder in their address, but not one of this site’s. They were left alone: check them by hand.', (int) $found['odd_links'], 'wrensafe' ), number_format_i18n( (int) $found['odd_links'] ) ),
				'odd',
			);
		}

		if ( $found['old_links'] ) {
			$lines[] = array(
				'warning',
				sprintf(
					/* translators: 1: number of links, 2: addresses */
					_n( '%1$s link still points to the old address (%2$s).', '%1$s links still point to the old address (%2$s).', (int) $found['old_links'], 'wrensafe' ),
					number_format_i18n( (int) $found['old_links'] ),
					implode( ', ', array_keys( (array) $found['old'] ) )
				),
				'old',
			);
		}

		if ( $found['http_links'] ) {
			$lines[] = array(
				'warning',
				/* translators: %s: number of links */
				sprintf( _n( '%s link to this site uses http:// although the site uses https://.', '%s links to this site use http:// although the site uses https://.', (int) $found['http_links'], 'wrensafe' ), number_format_i18n( (int) $found['http_links'] ) ),
				'http',
			);
		}

		if ( $found['missing'] ) {
			$lines[] = array(
				'error',
				/* translators: %s: number of links */
				sprintf( _n( '%s link points to a media file that isn’t on this site. It can’t be fixed here: upload the file again, or remove the link.', '%s links point to media files that aren’t on this site. They can’t be fixed here: upload the files again (same names and folders), or remove the links.', (int) $found['missing'], 'wrensafe' ), number_format_i18n( (int) $found['missing'] ) ),
				'missing',
			);
		}

		if ( $found['sizes'] ) {
			$lines[] = array(
				'info',
				/* translators: %s: number of links */
				sprintf( _n( '%s link points to an image size that is missing, but its original is here: a thumbnail tool can make it again.', '%s links point to image sizes that are missing, but their originals are here: a thumbnail tool can make them again.', (int) $found['sizes'], 'wrensafe' ), number_format_i18n( (int) $found['sizes'] ) ),
				'',
			);
		}

		if ( $found['attachments'] ) {
			$lines[] = array(
				'error',
				/* translators: %s: number of items */
				sprintf( _n( '%s Media Library item has no file on this site.', '%s Media Library items have no file on this site.', (int) $found['attachments'], 'wrensafe' ), number_format_i18n( (int) $found['attachments'] ) ),
				'attach',
			);
		}

		if ( ! empty( $r['skipped'] ) ) {
			$lines[] = array(
				'info',
				/* translators: %s: database table names */
				sprintf( __( 'Not checked: %s. Left out of backups in Settings, so a fix there couldn’t be undone.', 'wrensafe' ), implode( ', ', array_map( 'strval', (array) $r['skipped'] ) ) ),
				'',
			);
		}

		if ( ! $lines ) {
			$out .= '<p class="wpbp-lc-line is-good">' . esc_html__( 'No broken links found: the links checked point to this site, and the media files they use are here.', 'wrensafe' ) . '</p>';
		} else {
			$out .= '<ul class="wpbp-lc-findings">';

			foreach ( $lines as $line ) {
				$out .= '<li class="is-' . esc_attr( $line[0] ) . '">' . esc_html( $line[1] );

				if ( ! $compact && '' !== $line[2] ) {
					$out .= self::examples( $found, $line[2] );
				}

				$out .= '</li>';
			}

			$out .= '</ul>';
		}

		$buttons = '';

		if ( (int) $r['fixable'] > 0 ) {
			$buttons .= '<button type="button" class="button button-primary wpbp-lc-fix">' . esc_html(
				sprintf(
					/* translators: %s: number of links */
					_n( 'Fix %s link', 'Fix %s links', (int) $r['fixable'], 'wrensafe' ),
					number_format_i18n( (int) $r['fixable'] )
				)
			) . '</button> ';
		}

		if ( $compact ) {
			$buttons .= '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Details', 'wrensafe' ) . '</a> ';
			$buttons .= '<button type="button" class="button-link wpbp-lc-dismiss">' . esc_html__( 'Dismiss', 'wrensafe' ) . '</button>';
		}

		if ( '' !== $buttons ) {
			$out .= '<p class="wpbp-lc-actions">' . $buttons . '</p>';
		}

		if ( (int) $r['fixable'] > 0 ) {
			$out .= '<p class="description">' . esc_html__( 'A database backup is made first. Email addresses and other domains are left alone. A link to another site that contains the old address, such as a share or web-archive link, is updated too.', 'wrensafe' ) . '</p>';
		}

		return $out;
	}

	/** A few of the items a problem was found in. */
	private static function examples( array $found, string $kind ): string {
		if ( 'missing' === $kind ) {
			$items = array_values(
				array_filter(
					(array) $found['missing_list'],
					static function ( $item ): bool {
						return empty( $item['size'] );
					}
				)
			);
		} elseif ( 'attach' === $kind ) {
			$items = (array) $found['attach_list'];
		} else {
			$items = (array) ( $found['examples'][ $kind ] ?? array() );
		}

		if ( ! $items ) {
			return '';
		}

		$out = '<ul class="wpbp-lc-examples">';

		foreach ( array_slice( $items, 0, 'missing' === $kind ? 20 : self::SHOW ) as $item ) {
			$what = (string) ( $item['file'] ?? ( $item['link'] ?? '' ) );
			$out .= '<li>' . self::where_html( (array) $item ) . ( '' !== $what ? ' <code>' . esc_html( $what ) . '</code>' : '' ) . '</li>';
		}

		$out .= '</ul>';

		return $out;
	}

	/** Where something was found, linked to its edit screen when there is one. */
	private static function where_html( array $item ): string {
		$id = (int) ( $item['id'] ?? 0 );

		switch ( (string) ( $item['kind'] ?? '' ) ) {
			case 'post':
				$label = '' !== (string) ( $item['label'] ?? '' ) ? (string) $item['label'] : __( '(no title)', 'wrensafe' );
				/* translators: 1: title, 2: post ID */
				$text = sprintf( __( '%1$s (#%2$d)', 'wrensafe' ), $label, $id );
				break;
			case 'postmeta':
				/* translators: 1: post ID, 2: name of the custom field */
				$text = sprintf( __( 'Post #%1$d, field %2$s', 'wrensafe' ), $id, (string) ( $item['label'] ?? '' ) );
				break;
			case 'option':
				/* translators: %s: option name */
				$text = sprintf( __( 'Setting %s', 'wrensafe' ), (string) ( $item['label'] ?? '' ) );
				break;
			default:
				/* translators: %d: term ID */
				$text = sprintf( __( 'Term #%d', 'wrensafe' ), $id );
		}

		$edit = in_array( (string) ( $item['kind'] ?? '' ), array( 'post', 'postmeta' ), true ) && $id > 0 ? get_edit_post_link( $id, 'raw' ) : '';

		return $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
	}
}
