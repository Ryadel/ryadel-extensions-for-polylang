<?php
/**
 * Inactive languages.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\InactiveLanguages;

use Ryadel\ExtensionsForPolylang\Module as Base_Module;
use Ryadel\ExtensionsForPolylang\Plugin;

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
		$polylang = Plugin::polylang();
		if ( ! $polylang || ! isset( $polylang->model->languages ) ) {
			return;
		}

		if ( $polylang instanceof \PLL_Frontend && interface_exists( 'WP_Syntex\\Polylang\\Model\\Languages_Proxy_Interface' ) ) {
			$polylang->model->languages->register_proxy( new Visitor_Proxy(), 'automatic' );
			// Polylang may have built the list already: rebuild it with the proxy (the transient is not touched).
			$polylang->model->languages->clean_local_cache();
			( new Frontend() )->register();
		}

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}
}
