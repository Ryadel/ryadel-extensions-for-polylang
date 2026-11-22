<?php
/**
 * REST API: `lang` and `translations` fields and `lang` filter.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Rest\Fields;

use Ryadel\ExtensionsForPolylang\Module as Base_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Languages and translations on the core REST endpoints of posts and terms.
 */
final class Module extends Base_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'rest_fields';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'REST API: languages and translations', 'extensions-for-polylang' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds the lang and translations fields to posts and terms in the REST API, and filters collections by language.', 'extensions-for-polylang' );
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
		add_action(
			'rest_api_init',
			static function () {
				( new Fields() )->register();
				( new Language_Filter() )->register();
			}
		);
	}
}
