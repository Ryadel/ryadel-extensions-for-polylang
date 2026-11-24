<?php
/**
 * Languages proxy that hides inactive languages from visitors.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\InactiveLanguages;

use WP_Syntex\Polylang\Model\Languages_Proxy_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Registered as an "automatic" proxy of the Polylang language list (Polylang 3.8+) on the front end: for users who
 * cannot edit posts, inactive languages are removed from the list, so the language switcher, hreflang links,
 * browser detection, sitemaps and URLs all ignore them. The default language is never hidden.
 */
final class Visitor_Proxy implements Languages_Proxy_Interface {

	/**
	 * Slugs of the languages hidden by the last call of `filter()`.
	 *
	 * @var string[]
	 */
	private static $hidden = array();

	/**
	 * Proxy key.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'ryadel_extensions_for_polylang_hide_inactive';
	}

	/**
	 * Removes the inactive languages, unless the current user can edit posts.
	 *
	 * @param array $languages PLL_Language objects.
	 * @return array
	 */
	public function filter( array $languages ): array {
		self::$hidden = array();

		if ( current_user_can( 'edit_posts' ) ) {
			return $languages;
		}

		$visible = array();
		foreach ( $languages as $language ) {
			if ( isset( $language->active ) && false === $language->active && empty( $language->is_default ) ) {
				self::$hidden[] = $language->slug;
			} else {
				$visible[] = $language;
			}
		}

		return $visible;
	}

	/**
	 * Slugs of the languages hidden from the current user.
	 *
	 * @return string[]
	 */
	public static function hidden() {
		return self::$hidden;
	}
}
