<?php
/**
 * REST parameter `lang` on collections and search.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Rest\Fields;

use Ryadel\ExtensionsForPolylang\Services\Translations;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Filters by language the post collections of translated post types and the post results of `/wp/v2/search`.
 *
 * Polylang already filters terms by the `lang` of the request, so term collections only get the parameter
 * declared, which makes an unknown language a 400 error instead of a silent fallback to the default language.
 */
final class Language_Filter {

	/**
	 * Hooked to `rest_api_init`, before WordPress registers its routes.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
			if ( Translations::is_translated( Translations::POST, $post_type ) ) {
				add_filter( "rest_{$post_type}_collection_params", array( $this, 'add_collection_param' ) );
				add_filter( "rest_{$post_type}_query", array( $this, 'filter_query' ), 10, 2 );
			}
		}

		foreach ( get_taxonomies( array( 'show_in_rest' => true ) ) as $taxonomy ) {
			if ( Translations::is_translated( Translations::TERM, $taxonomy ) ) {
				add_filter( "rest_{$taxonomy}_collection_params", array( $this, 'add_collection_param' ) );
			}
		}

		add_filter( 'rest_endpoints', array( $this, 'add_search_param' ) );
		add_filter( 'rest_post_search_query', array( $this, 'filter_query' ), 10, 2 );
	}

	/**
	 * Declares `lang` among the parameters of a collection.
	 *
	 * @param array $params Collection parameters.
	 * @return array
	 */
	public function add_collection_param( $params ) {
		$params['lang'] = array(
			'description' => __( 'Limit results to the items in a language.', 'extensions-for-polylang' ),
			'type'        => 'string',
			'enum'        => Translations::language_slugs(),
		);
		return $params;
	}

	/**
	 * Declares `lang` on the search route.
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array
	 */
	public function add_search_param( $endpoints ) {
		if ( empty( $endpoints['/wp/v2/search'] ) ) {
			return $endpoints;
		}
		foreach ( $endpoints['/wp/v2/search'] as $key => $handler ) {
			if ( is_array( $handler ) && isset( $handler['args'] ) && ! isset( $handler['args']['lang'] ) ) {
				$endpoints['/wp/v2/search'][ $key ]['args'] = $this->add_collection_param( $handler['args'] );
			}
		}
		return $endpoints;
	}

	/**
	 * Adds the language to the `WP_Query` arguments of a collection or of the post search.
	 *
	 * @param array           $args    WP_Query arguments.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function filter_query( $args, $request ) {
		$lang = $request instanceof WP_REST_Request ? $request->get_param( 'lang' ) : null;
		if ( Translations::is_language( $lang ) ) {
			// Polylang registers its language taxonomy with the `lang` query var.
			$args['lang'] = $lang;
		}
		return $args;
	}
}
