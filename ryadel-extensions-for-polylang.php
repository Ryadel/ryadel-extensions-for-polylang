<?php
/**
 * Plugin Name:       Ryadel Extensions for Polylang
 * Plugin URI:        https://github.com/Ryadel/ryadel-extensions-for-polylang
 * Description:       Extends Polylang: languages and translations in the WordPress REST API, inactive languages, per-language blocks.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Ryadel
 * Author URI:        https://www.ryadel.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ryadel-extensions-for-polylang
 *
 * @package ExtensionsForPolylang
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

defined( 'ABSPATH' ) || exit;

const RYADEL_EXTENSIONS_FOR_POLYLANG_VERSION      = '1.0.0';
const RYADEL_EXTENSIONS_FOR_POLYLANG_FILE         = __FILE__;
const RYADEL_EXTENSIONS_FOR_POLYLANG_MIN_POLYLANG = '3.9.2'; // 3.9.2 fixes an exposure of private post metadata.

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Ryadel\\ExtensionsForPolylang\\';
		if ( 0 === strpos( $class_name, $prefix ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

/*
 * Polylang fires `pll_model_init` on `plugins_loaded`, right after creating its model and before it detects the
 * current language, so modules can still filter the list of languages. It doesn't fire on the front end when the
 * site has no language yet: the plugin then stays idle, like Polylang itself.
 */
add_action( 'pll_model_init', array( 'Ryadel\\ExtensionsForPolylang\\Plugin', 'boot' ) );
add_action( 'admin_notices', 'ryadel_extensions_for_polylang_requirements_notice' );

/**
 * Returns why the plugin can't run, or an empty string when Polylang is there and recent enough.
 *
 * @return string
 */
function ryadel_extensions_for_polylang_requirements_error() {
	if ( ! defined( 'POLYLANG_VERSION' ) ) {
		return __( 'Ryadel Extensions for Polylang requires the Polylang plugin.', 'ryadel-extensions-for-polylang' );
	}
	if ( version_compare( POLYLANG_VERSION, RYADEL_EXTENSIONS_FOR_POLYLANG_MIN_POLYLANG, '<' ) ) {
		return sprintf(
			/* translators: 1: required Polylang version, 2: installed Polylang version. */
			__( 'Ryadel Extensions for Polylang requires Polylang %1$s or later (installed: %2$s).', 'ryadel-extensions-for-polylang' ),
			RYADEL_EXTENSIONS_FOR_POLYLANG_MIN_POLYLANG,
			POLYLANG_VERSION
		);
	}
	return '';
}

/**
 * Tells administrators why the plugin is idle, on the Plugins screen only.
 *
 * @return void
 */
function ryadel_extensions_for_polylang_requirements_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}
	$error = ryadel_extensions_for_polylang_requirements_error();
	if ( '' !== $error && current_user_can( 'activate_plugins' ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}
