<?php
/**
 * Inactive languages in the admin.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\InactiveLanguages;

use Ryadel\ExtensionsForPolylang\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "Deactivate" and "Activate" row actions in the languages table of Polylang, and a notice that lists the inactive
 * languages. The state is the `active` property that Polylang already stores with each language.
 */
final class Admin {

	const DEACTIVATE = 'efpl-deactivate-lang';
	const ACTIVATE   = 'efpl-activate-lang';

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pll_languages_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'mlang_action_' . self::DEACTIVATE, array( $this, 'deactivate' ) );
		add_action( 'mlang_action_' . self::ACTIVATE, array( $this, 'activate' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Adds the action that switches the language state. The default language cannot be deactivated.
	 *
	 * @param string[] $actions Row actions.
	 * @param object   $item    PLL_Language.
	 * @return string[]
	 */
	public function row_actions( $actions, $item ) {
		$active = false !== $item->active;
		if ( $active && $item->is_default ) {
			return $actions;
		}

		$action = $active ? self::DEACTIVATE : self::ACTIVATE;
		$url    = wp_nonce_url( '?page=mlang&pll_action=' . $action . '&noheader=true&lang=' . (int) $item->term_id, $action );

		$actions['efpl_active'] = sprintf(
			'<a title="%s" href="%s">%s</a>',
			esc_attr( $active ? __( 'Hide this language from visitors', 'ryadel-extensions-for-polylang' ) : __( 'Show this language to visitors', 'ryadel-extensions-for-polylang' ) ),
			esc_url( $url ),
			esc_html( $active ? __( 'Deactivate', 'ryadel-extensions-for-polylang' ) : __( 'Activate', 'ryadel-extensions-for-polylang' ) )
		);

		return $actions;
	}

	/**
	 * Handles the "Deactivate" action. Polylang checks the page capability and redirects afterwards.
	 *
	 * @return void
	 */
	public function deactivate() {
		$this->switch_state( self::DEACTIVATE, false );
	}

	/**
	 * Handles the "Activate" action.
	 *
	 * @return void
	 */
	public function activate() {
		$this->switch_state( self::ACTIVATE, true );
	}

	/**
	 * Lists the inactive languages on the Languages page.
	 *
	 * @return void
	 */
	public function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads the current page.
		if ( ! isset( $_GET['page'] ) || 'mlang' !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$names = array();
		foreach ( self::languages() as $language ) {
			if ( false === $language->active && ! $language->is_default ) {
				$names[] = $language->name;
			}
		}

		if ( $names ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of language names. */
						__( 'Inactive languages, hidden from visitors: %s. Editors still see them on the site.', 'ryadel-extensions-for-polylang' ),
						implode( ', ', $names )
					)
				)
			);
		}
	}

	/**
	 * Sets whether a language is active.
	 *
	 * @param int  $term_id Language term ID.
	 * @param bool $active  New state.
	 * @return true|WP_Error
	 */
	public static function set_active( $term_id, $active ) {
		$polylang = Plugin::polylang();
		$language = $polylang ? $polylang->model->languages->get( (int) $term_id ) : null;

		if ( ! $language ) {
			return new WP_Error( 'efpl_invalid_language', __( 'The language does not exist.', 'ryadel-extensions-for-polylang' ) );
		}
		if ( ! $active && $language->is_default ) {
			return new WP_Error( 'efpl_default_language', __( 'The default language cannot be deactivated. Choose another default language first.', 'ryadel-extensions-for-polylang' ) );
		}

		$metas = static function ( $add_data ) use ( $active ) {
			$add_data['active'] = (bool) $active;
			return $add_data;
		};

		add_filter( 'pll_language_metas', $metas );
		$result = $polylang->model->languages->update( array( 'lang_id' => (int) $term_id ) );
		remove_filter( 'pll_language_metas', $metas );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Every language, inactive ones included (the proxy only acts on the front end).
	 *
	 * @return array PLL_Language objects.
	 */
	private static function languages() {
		$polylang = Plugin::polylang();
		return $polylang ? $polylang->model->languages->get_list() : array();
	}

	/**
	 * Common part of the two actions.
	 *
	 * @param string $action Action, also the nonce action.
	 * @param bool   $active New state.
	 * @return void
	 */
	private function switch_state( $action, $active ) {
		check_admin_referer( $action );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$term_id = isset( $_GET['lang'] ) ? (int) $_GET['lang'] : 0;
		$result  = self::set_active( $term_id, $active );

		if ( ! function_exists( 'pll_add_notice' ) ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			pll_add_notice( $result );
		} else {
			pll_add_notice(
				new WP_Error(
					'efpl_language_state',
					$active ? __( 'Language activated.', 'ryadel-extensions-for-polylang' ) : __( 'Language deactivated: visitors no longer see it.', 'ryadel-extensions-for-polylang' ),
					'success'
				)
			);
		}
	}
}
