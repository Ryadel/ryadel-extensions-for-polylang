<?php
/**
 * Plugin bootstrap and module registry.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Starts the enabled modules once Polylang is ready.
 */
final class Plugin {

	/**
	 * Running instance, null until `boot()`.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Every module, enabled or not, keyed by id.
	 *
	 * @var Module[]
	 */
	private $modules = array();

	/**
	 * Hooked to `pll_model_init`. The Polylang API functions (`pll_*`) are not loaded yet: modules only add hooks in
	 * `register()`, or use the model through `Plugin::polylang()`.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( null !== self::$instance || '' !== extensions_for_polylang_requirements_error() ) {
			return;
		}
		self::$instance = new self();
		self::$instance->start();
	}

	/**
	 * Running instance, or null when the plugin is idle.
	 *
	 * @return Plugin|null
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * The Polylang object of the current context (`PLL_Frontend`, `PLL_Admin`, `PLL_Settings`, `PLL_REST_Request`).
	 * Same as `PLL()`, which is not available yet when the plugin boots.
	 *
	 * @return object|null
	 */
	public static function polylang() {
		return isset( $GLOBALS['polylang'] ) && is_object( $GLOBALS['polylang'] ) ? $GLOBALS['polylang'] : null;
	}

	/**
	 * Whether Polylang Pro is the active Polylang.
	 *
	 * @return bool
	 */
	public static function is_polylang_pro() {
		return defined( 'POLYLANG_PRO' ) && POLYLANG_PRO;
	}

	/**
	 * Registers the modules and starts the enabled ones.
	 *
	 * @return void
	 */
	private function start() {
		$modules = array(
			new Rest\Fields\Module(),
			new Rest\Tools\Module(),
			new InactiveLanguages\Module(),
			new BlockVisibility\Module(),
		);

		/**
		 * Filters the modules of Extensions for Polylang.
		 *
		 * @param Module[] $modules Modules.
		 */
		foreach ( (array) apply_filters( 'extensions_for_polylang_modules', $modules ) as $module ) {
			if ( $module instanceof Module ) {
				$this->modules[ $module->id() ] = $module;
			}
		}

		foreach ( $this->modules as $module ) {
			if ( $this->is_active( $module ) ) {
				$module->register();
			}
		}
	}

	/**
	 * Every module, keyed by id.
	 *
	 * @return Module[]
	 */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Whether a module is available (not already provided by Polylang Pro) and enabled in the settings.
	 *
	 * @param Module $module Module.
	 * @return bool
	 */
	public function is_active( Module $module ) {
		return $module->is_available() && Settings::is_module_enabled( $module->id() );
	}
}
