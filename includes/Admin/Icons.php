<?php
/**
 * Inline SVG icons for buttons and status UI.
 *
 * Icon fonts inside buttons inherit colour through several layers of
 * WordPress and third-party admin CSS; on some sites that made a white
 * button icon render in the button's own blue until hover. Inline SVG
 * with stroke="currentColor" always matches the button text.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

defined( 'ABSPATH' ) || exit;

class Icons {

	/**
	 * 24×24 stroke paths (round caps/joins, 2px stroke).
	 *
	 * @var array<string,string>
	 */
	private const PATHS = array(
		'plus'         => '<path d="M12 5v14M5 12h14"/>',
		'upload'       => '<path d="M12 15V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
		'download'     => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
		'restore'      => '<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/><path d="M12 8v4l3 2"/>',
		'archive'      => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9M10 13h4"/>',
		'file'         => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
		'database'     => '<ellipse cx="12" cy="5.5" rx="7" ry="2.5"/><path d="M5 5.5v13c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-13"/><path d="M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5"/>',
		'folder'       => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
		'image'        => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-9 9"/>',
		'plugin'       => '<path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0zM12 17v4"/>',
		'theme'        => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.9 1.2-1.8-.5-1-.2-2.2 1.3-2.2H17a4 4 0 0 0 4-4c0-5.5-4-10-9-10z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10.5" cy="7.5" r="1"/><circle cx="15" cy="8" r="1"/>',
		'globe'        => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
		'arrow-right'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'check'        => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 10"/>',
		'x'            => '<path d="M6 6l12 12M18 6 6 18"/>',
		'alert'        => '<path d="M10.3 4.2 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
		'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		'shield'       => '<path d="M12 3 5 6v5.5c0 4.4 3 8.2 7 9.5 4-1.3 7-5.1 7-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
		'lock'         => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'refresh'      => '<path d="M20 11a8 8 0 0 0-14.3-4.3L4 8"/><path d="M4 4v4h4M4 13a8 8 0 0 0 14.3 4.3L20 16"/><path d="M20 20v-4h-4"/>',
		'calendar'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		'chart'        => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		'lightbulb'    => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.6 10.8c.7.6 1.1 1.4 1.1 2.2h5c0-.8.4-1.6 1.1-2.2A6 6 0 0 0 12 3z"/>',
		'list-checks'  => '<path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17M11 6h10M11 12h10M11 18h10"/>',
		'search'       => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
		'server'       => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
		'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
	);

	/**
	 * SVG markup for an icon (decorative: aria-hidden). Unknown names
	 * return an empty string.
	 */
	public static function get( string $name, int $size = 18, string $class_name = '' ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="wpbp-icon%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			'' !== $class_name ? ' ' . esc_attr( $class_name ) : '',
			$size,
			self::PATHS[ $name ]
		);
	}

	/**
	 * Print an icon. The markup is built only from the fixed path table
	 * above and escaped attributes, so it is safe to output.
	 */
	public static function render( string $name, int $size = 18, string $class_name = '' ): void {
		echo wp_kses( self::get( $name, $size, $class_name ), self::allowed_svg() );
	}

	/**
	 * Every icon as a name => markup map, for the admin script (toasts,
	 * the upload panel, the archive summary).
	 *
	 * @return array<string,string>
	 */
	public static function all( int $size = 18 ): array {
		$icons = array();

		foreach ( array_keys( self::PATHS ) as $name ) {
			$icons[ $name ] = self::get( $name, $size );
		}

		return $icons;
	}

	/**
	 * wp_kses() allow-list for the SVG elements and attributes used above.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_svg(): array {
		$shape = array(
			'd'         => true,
			'cx'        => true,
			'cy'        => true,
			'r'         => true,
			'rx'        => true,
			'ry'        => true,
			'x'         => true,
			'y'         => true,
			'width'     => true,
			'height'    => true,
			'transform' => true,
		);

		return array(
			'svg'     => array(
				'class'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'path'    => $shape,
			'circle'  => $shape,
			'rect'    => $shape,
			'ellipse' => $shape,
		);
	}
}
