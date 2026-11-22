<?php
/**
 * Plugin settings.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `extensions_for_polylang` option.
 */
final class Settings {

	const OPTION = 'extensions_for_polylang';

	/**
	 * The whole option, with defaults.
	 *
	 * @return array{modules: array<string, bool>}
	 */
	public static function get() {
		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();

		return array(
			'modules' => isset( $option['modules'] ) && is_array( $option['modules'] ) ? array_map( 'boolval', $option['modules'] ) : array(),
		);
	}

	/**
	 * Whether a module is enabled. Modules are enabled until they are turned off.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function is_module_enabled( $id ) {
		$modules = self::get()['modules'];
		return ! isset( $modules[ $id ] ) || $modules[ $id ];
	}

	/**
	 * Turns a module on or off.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled Whether the module is enabled.
	 * @return void
	 */
	public static function set_module_enabled( $id, $enabled ) {
		$option                   = self::get();
		$option['modules'][ $id ] = (bool) $enabled;
		update_option( self::OPTION, $option );
	}
}
