<?php
/**
 * Inactive languages on the front end.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\InactiveLanguages;

defined( 'ABSPATH' ) || exit;

/**
 * Answers 404 to visitors on the URLs of a hidden language: its home and archives, and its posts and terms.
 *
 * Once the proxy hides a language, Polylang no longer knows it, so `pll_get_post_language()` returns nothing for its
 * posts. Their language is read from the language taxonomies instead: `language` for posts, `term_language` (term
 * slugs prefixed with `pll_`) for terms.
 */
final class Frontend {

	/**
	 * Whether the current request was turned into a 404 by this class.
	 *
	 * @var bool
	 */
	private $forced_404 = false;

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp', array( $this, 'maybe_404' ), 1 );
		add_filter( 'do_redirect_guess_404_permalink', array( $this, 'no_guess' ) );
		add_filter( 'redirect_canonical', array( $this, 'no_redirect' ) );
	}

	/**
	 * Keeps WordPress from redirecting our 404, e.g. `?p=<id>` to the post URL without the language prefix.
	 *
	 * @param string|false $redirect_url Redirect URL.
	 * @return string|false
	 */
	public function no_redirect( $redirect_url ) {
		return $this->forced_404 ? false : $redirect_url;
	}

	/**
	 * Hooked to `wp`.
	 *
	 * @param \WP $wp Current WordPress environment.
	 * @return void
	 */
	public function maybe_404( $wp ) {
		global $wp_query;

		$hidden = Visitor_Proxy::hidden();
		if ( ! $hidden || ! $wp_query instanceof \WP_Query ) {
			return;
		}

		if ( ! $this->is_hidden_request( $wp, $wp_query, $hidden ) ) {
			return;
		}

		$this->forced_404 = true;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Keeps WordPress from redirecting our 404 to a similar URL, which could be the same content.
	 *
	 * @param bool $guess Whether to guess.
	 * @return bool
	 */
	public function no_guess( $guess ) {
		return $this->forced_404 ? false : $guess;
	}

	/**
	 * Whether the request is about a hidden language.
	 *
	 * @param \WP       $wp       Current WordPress environment.
	 * @param \WP_Query $wp_query Main query.
	 * @param string[]  $hidden   Hidden language slugs.
	 * @return bool
	 */
	private function is_hidden_request( $wp, $wp_query, array $hidden ) {
		$lang = isset( $wp->query_vars['lang'] ) && is_string( $wp->query_vars['lang'] ) ? $wp->query_vars['lang'] : '';
		if ( '' !== $lang && array_intersect( explode( ',', $lang ), $hidden ) ) {
			return true;
		}

		$object = $wp_query->get_queried_object();

		if ( $object instanceof \WP_Post ) {
			$slugs = wp_get_object_terms( $object->ID, 'language', array( 'fields' => 'slugs' ) );
			return is_array( $slugs ) && array_intersect( $slugs, $hidden );
		}

		if ( $object instanceof \WP_Term && 'language' !== $object->taxonomy ) {
			$slugs = wp_get_object_terms( $object->term_id, 'term_language', array( 'fields' => 'slugs' ) );
			if ( ! is_array( $slugs ) ) {
				return false;
			}
			foreach ( $hidden as $slug ) {
				if ( in_array( 'pll_' . $slug, $slugs, true ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
