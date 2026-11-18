<?php
/**
 * Minimal singleton service container.
 *
 * Avoids scattering `new` calls and globals across the plugin while staying
 * intentionally small until real dependencies are introduced.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

defined( 'ABSPATH' ) || exit;

class ServiceContainer {

	/** @var array<string, object> */
	private array $instances = array();

	/**
	 * Get (and lazily create) a shared instance of a class.
	 */
	public function get( string $class_name ): object {
		if ( ! isset( $this->instances[ $class_name ] ) ) {
			$this->instances[ $class_name ] = new $class_name();
		}

		return $this->instances[ $class_name ];
	}

	/**
	 * Register an already-constructed instance, e.g. for testing.
	 */
	public function set( string $class_name, object $instance ): void {
		$this->instances[ $class_name ] = $instance;
	}
}
