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
		( new Blocks() )->register();
	}

	/**
	 * Switched off, the module keeps the attribute registered, without panel and filter: otherwise the block editor
	 * would drop the saved languages the next time a block is saved, and they would be lost when the module is
	 * switched on again.
	 *
	 * @return void
	 */
	public function register_inactive() {
		( new Blocks( false ) )->register();
	}
}
