<?php
/**
 * Base class of the modules.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * A feature that can be turned on and off from the settings page.
 */
abstract class Module {

	/**
	 * Unique id, used as settings key.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Name shown on the settings page.
	 *
	 * @return string
	 */
	abstract public function title();

	/**
	 * One-line description shown on the settings page.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Hooks the module into WordPress. Called only when the module is active.
	 *
	 * @return void
	 */
	abstract public function register();

	/**
	 * Called instead of `register()` when the module is switched off, also when Polylang Pro provides it. Keeps what
	 * must survive while the module is off, such as the format of data already saved. Does nothing by default.
	 *
	 * @return void
	 */
	public function register_inactive() {}

	/**
	 * Whether Polylang Pro already provides this feature.
	 *
	 * @return bool
	 */
	public function is_provided_by_pro() {
		return false;
	}

	/**
	 * Whether the module can run on this site.
	 *
	 * @return bool
	 */
	public function is_available() {
		return ! ( $this->is_provided_by_pro() && Plugin::is_polylang_pro() );
	}
}
