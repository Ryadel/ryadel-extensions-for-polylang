<?php
/**
 * Translation status: counts and untranslated content.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Services;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Counts posts by language and finds the posts that miss a translation, with the `untranslated_in` query var of
 * Polylang 3.9 (the "Untranslated in" filter of the admin post lists).
 */
final class Status {

	/**
	 * Translated post types.
	 *
	 * @param bool $rest_only Whether to keep only the post types shown in the REST API.
	 * @return string[]
	 */
	public static function post_types( $rest_only = false ) {
		$args = $rest_only ? array( 'show_in_rest' => true ) : array();
		return array_values(
			array_filter(
				get_post_types( $args ),
				static function ( $post_type ) {
					return Translations::is_translated( Translations::POST, $post_type );
				}
			)
		);
	}

	/**
	 * Counts of each post type: posts per language, posts of each language without a translation in each other
	 * language, posts without language.
	 *
	 * @param string[] $post_types Post types.
	 * @param string   $status     Post status, or 'any' for every status but trash and auto-draft.
	 * @return array {
	 *     Keyed by post type.
	 *
	 *     @type int   $no_language Posts without language.
	 *     @type array $languages   Keyed by language slug: `count` and `untranslated` (counts keyed by target language).
	 * }
	 */
	public static function counts( array $post_types, $status = 'any' ) {
		$slugs = Translations::language_slugs();
		$out   = array();

		foreach ( $post_types as $post_type ) {
			$languages = array();
			foreach ( $slugs as $slug ) {
				$untranslated = array();
				foreach ( $slugs as $target ) {
					if ( $target !== $slug ) {
						$untranslated[ $target ] = self::count(
							array(
								'post_type'       => $post_type,
								'post_status'     => $status,
								'lang'            => $slug,
								'untranslated_in' => $target,
							)
						);
					}
				}
				$languages[ $slug ] = array(
					'count'        => self::count(
						array(
							'post_type'   => $post_type,
							'post_status' => $status,
							'lang'        => $slug,
						)
					),
					'untranslated' => $untranslated,
				);
			}

			$out[ $post_type ] = array(
				'no_language' => self::count(
					array(
						'post_type'   => $post_type,
						'post_status' => $status,
						'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
							array(
								'taxonomy' => 'language',
								'operator' => 'NOT EXISTS',
							),
						),
					)
				),
				'languages'   => $languages,
			);
		}

		return $out;
	}

	/**
	 * Posts without a translation in a language, newest first.
	 *
	 * @param string      $lang       Language the translation is missing in.
	 * @param string[]    $post_types Post types.
	 * @param string|null $from       Only posts in this language; null for every other language.
	 * @param string      $status     Post status, or 'any'.
	 * @param int         $page       Page, from 1.
	 * @param int         $per_page   Posts per page.
	 * @return array { @type int[] $ids Post IDs. @type int $total Total posts. }
	 */
	public static function untranslated( $lang, array $post_types, $from = null, $status = 'any', $page = 1, $per_page = 10 ) {
		$sources = null === $from ? array_diff( Translations::language_slugs(), array( $lang ) ) : array( $from );

		if ( ! $sources || ! $post_types ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$query = self::query(
			array(
				'post_type'       => $post_types,
				'post_status'     => $status,
				'lang'            => implode( ',', $sources ),
				'untranslated_in' => $lang,
				'posts_per_page'  => max( 1, (int) $per_page ),
				'paged'           => max( 1, (int) $page ),
				'orderby'         => 'date',
				'order'           => 'DESC',
			)
		);

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Number of posts matching a query.
	 *
	 * @param array $args WP_Query arguments.
	 * @return int
	 */
	private static function count( array $args ) {
		return (int) self::query( $args + array( 'posts_per_page' => 1 ) )->found_posts;
	}

	/**
	 * Runs a query of post IDs.
	 *
	 * @param array $args WP_Query arguments.
	 * @return WP_Query
	 */
	private static function query( array $args ) {
		return new WP_Query(
			$args + array(
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}
}
