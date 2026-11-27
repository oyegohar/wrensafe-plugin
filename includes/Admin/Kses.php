<?php
/**
 * The HTML Wrensafe's own helpers may print.
 *
 * Helpers that build markup (icons, the brand mark, local times) return it
 * built from escaped pieces. Every place that prints such markup passes it
 * through wp_kses() with this list, so only these tags and attributes can
 * reach the page:
 *
 *     echo wp_kses( Time::local_time_markup( $date ), Kses::allowed() );
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

defined( 'ABSPATH' ) || exit;

final class Kses {

	/** @return array<string, array<string, bool>> */
	public static function allowed(): array {
		static $tags = null;

		if ( null === $tags ) {
			$tags = Icons::allowed_svg();

			$tags['g']              = array( 'transform' => true );
			$tags['path']['fill']   = true;
			$tags['path']['stroke'] = true;
			$tags['span']           = array(
				'class'          => true,
				'title'          => true,
				'data-utc'       => true,
				'data-with-time' => true,
				'aria-hidden'    => true,
			);
		}

		return $tags;
	}
}
