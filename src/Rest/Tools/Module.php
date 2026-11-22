<?php
/**
 * REST API: posts created with their translations, translation status.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Rest\Tools;

use Ryadel\ExtensionsForPolylang\Module as Base_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints of the `extensions-for-polylang/v1` namespace.
 */
final class Module extends Base_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'rest_tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'REST API: translation tools', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Creates a post together with its translations in a single request, and reports which content is not translated yet.', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_action(
			'rest_api_init',
			static function () {
				( new Posts_Controller() )->register_routes();
			}
		);
	}
}
