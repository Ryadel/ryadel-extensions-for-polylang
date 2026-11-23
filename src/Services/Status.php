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
 *
 * Every query sees only the posts the current user could read: private posts need `read_private_posts` on their
 * post type, unless the user wrote them, as in WordPress. Counts, lists and totals follow the same rule.
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
	 * With L languages that is L * L + 1 queries per post type: `$sources` limits the untranslated counts to some
	 * source languages, as the admin page does with the default language.
	 *
	 * @param string[]      $post_types Post types.
	 * @param string        $status     Post status, or 'any' for every status but trash and auto-draft.
	 * @param string[]|null $sources    Languages whose untranslated posts are counted; null for every language.
	 * @return array {
	 *     Keyed by post type.
	 *
	 *     @type int   $no_language Posts without language.
	 *     @type array $languages   Keyed by language slug: `count` and `untranslated` (counts keyed by target language,
	 *                              empty for the languages left out of `$sources`).
	 * }
	 */
	public static function counts( array $post_types, $status = 'any', $sources = null ) {
		$slugs = Translations::language_slugs();
		$out   = array();

		foreach ( $post_types as $post_type ) {
			$languages = array();
			foreach ( $slugs as $slug ) {
				$untranslated = array();
				foreach ( $slugs as $target ) {
					if ( $target !== $slug && ( null === $sources || in_array( $slug, $sources, true ) ) ) {
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

		$total = (int) $query->found_posts;
		if ( $total < 1 && $page > 1 ) {
			// WP_Query skips the count when the page is empty: count again, to tell a page past the end from no result.
			$total = self::count(
				array(
					'post_type'       => $post_types,
					'post_status'     => $status,
					'lang'            => implode( ',', $sources ),
					'untranslated_in' => $lang,
				)
			);
		}

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => $total,
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
	 * Runs a query of post IDs, limited to the posts the current user could read.
	 *
	 * @param array $args WP_Query arguments.
	 * @return WP_Query
	 */
	private static function query( array $args ) {
		add_filter( 'posts_where', array( __CLASS__, 'readable_where' ), 10, 2 );
		$query = new WP_Query(
			$args + array(
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		remove_filter( 'posts_where', array( __CLASS__, 'readable_where' ), 10 );
		return $query;
	}

	/**
	 * Leaves out the private posts of others in the post types where the current user cannot read them. WP_Query
	 * has no such rule for `post_status => any`, nor for several post types at once. Hooked to `posts_where` only
	 * while `query()` runs.
	 *
	 * @param string   $where WHERE clause.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public static function readable_where( $where, $query ) {
		global $wpdb;

		$hidden = array();
		foreach ( (array) $query->get( 'post_type' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object && ! current_user_can( $object->cap->read_private_posts ) ) {
				$hidden[] = $post_type;
			}
		}
		$statuses = array_values( get_post_stati( array( 'private' => true ) ) );
		if ( ! $hidden || ! $statuses ) {
			return $where;
		}

		$where .= $wpdb->prepare(
			" AND NOT ( {$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $hidden ), '%s' ) ) . ')' .
			" AND {$wpdb->posts}.post_status IN (" . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')' .
			" AND {$wpdb->posts}.post_author <> %d )",
			array_merge( $hidden, $statuses, array( get_current_user_id() ) )
		);
		return $where;
	}
}
