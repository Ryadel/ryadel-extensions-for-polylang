<?php
/**
 * Per-language block visibility.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\BlockVisibility;

use Ryadel\ExtensionsForPolylang\Module as Base_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a block only in the languages picked in the editor.
 */
final class Module extends Base_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'block_visibility';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Per-language blocks', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Shows a block only in the languages you pick, so a single header, footer or navigation works for every language.', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		// Implemented in M5.
	}
}
