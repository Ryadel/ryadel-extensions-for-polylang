<?php
/**
 * Inactive languages.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\InactiveLanguages;

use Ryadel\ExtensionsForPolylang\Module as Base_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Languages hidden from visitors while they are being translated.
 */
final class Module extends Base_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'inactive_languages';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Inactive languages', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Hides a language from visitors while you are still translating it.', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_provided_by_pro() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		// Implemented in M4.
	}
}
