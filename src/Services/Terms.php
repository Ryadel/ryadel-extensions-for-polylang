<?php
/**
 * Terms looked up by slug or name.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and creates terms from the strings sent by API clients.
 *
 * A term found in another language is fine: when Polylang (3.9+) assigns a term to a post, it replaces it with its
 * translation in the language of the post, or with a term of the same name in that language, or it creates that
 * translation.
 */
final class Terms {

	/**
	 * Finds a term by slug or name, preferring the given language.
	 *
	 * @param string      $taxonomy Taxonomy.
	 * @param string      $value    Slug or name.
	 * @param string|null $lang     Language slug, null for any language.
	 * @return int Term ID, 0 when no term matches.
	 */
	public static function find( $taxonomy, $value, $lang = null ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		$queries = array();
		if ( null !== $lang && pll_is_translated_taxonomy( $taxonomy ) ) {
			$queries[] = array(
				'slug' => sanitize_title( $value ),
				'lang' => $lang,
			);
			$queries[] = array(
				'name' => $value,
				'lang' => $lang,
			);
		}
		$queries[] = array(
			'slug' => sanitize_title( $value ),
			'lang' => '',
		);
		$queries[] = array(
			'name' => $value,
			'lang' => '',
		);

		foreach ( $queries as $query ) {
			$ids = get_terms(
				$query + array(
					'taxonomy'               => $taxonomy,
					'hide_empty'             => false,
					'fields'                 => 'ids',
					'number'                 => 1,
					'update_term_meta_cache' => false,
				)
			);
			if ( is_array( $ids ) && $ids ) {
				return (int) reset( $ids );
			}
		}

		return 0;
	}

	/**
	 * Creates a top-level term. Meant for names that match no term in any language.
	 *
	 * @param string      $taxonomy Taxonomy.
	 * @param string      $name     Term name.
	 * @param string|null $lang     Language slug, ignored by taxonomies that are not translated.
	 * @return int|WP_Error Term ID.
	 */
	public static function create( $taxonomy, $name, $lang = null ) {
		$term = wp_insert_term( trim( (string) $name ), $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$id = (int) $term['term_id'];
		if ( null !== $lang && pll_is_translated_taxonomy( $taxonomy ) && pll_get_term_language( $id, 'slug' ) !== $lang ) {
			pll_set_term_language( $id, $lang );
		}

		return $id;
	}
}
