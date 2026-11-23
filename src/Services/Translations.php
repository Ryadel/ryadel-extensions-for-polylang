<?php
/**
 * Languages and translation groups of posts and terms.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes languages and translation groups through the public API of Polylang, with the checks that
 * Polylang leaves to the caller: Polylang drops invalid entries silently, this class reports them as errors.
 */
final class Translations {

	const POST = 'post';
	const TERM = 'term';

	/**
	 * Slugs of every language of the site.
	 *
	 * @return string[]
	 */
	public static function language_slugs() {
		return (array) pll_languages_list( array( 'fields' => 'slug' ) );
	}

	/**
	 * Whether a string is the slug of a language of the site.
	 *
	 * @param mixed $slug Value to check.
	 * @return bool
	 */
	public static function is_language( $slug ) {
		return is_string( $slug ) && in_array( $slug, self::language_slugs(), true );
	}

	/**
	 * Post type or taxonomy of an object.
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param int    $id   Object ID.
	 * @return string Empty string when the object does not exist.
	 */
	public static function subtype( $type, $id ) {
		if ( self::TERM === $type ) {
			$term = get_term( (int) $id );
			return $term instanceof \WP_Term ? $term->taxonomy : '';
		}
		$post_type = get_post_type( (int) $id );
		return false === $post_type ? '' : $post_type;
	}

	/**
	 * Whether Polylang translates a post type or a taxonomy.
	 *
	 * @param string $type    Translations::POST or Translations::TERM.
	 * @param string $subtype Post type or taxonomy.
	 * @return bool
	 */
	public static function is_translated( $type, $subtype ) {
		return self::TERM === $type ? pll_is_translated_taxonomy( $subtype ) : pll_is_translated_post_type( $subtype );
	}

	/**
	 * Language of an object.
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param int    $id   Object ID.
	 * @return string|null Language slug, null when the object has no language.
	 */
	public static function get_language( $type, $id ) {
		$slug = self::TERM === $type ? pll_get_term_language( (int) $id, 'slug' ) : pll_get_post_language( (int) $id, 'slug' );
		return is_string( $slug ) && '' !== $slug ? $slug : null;
	}

	/**
	 * Translation group of an object, the object included.
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param int    $id   Object ID.
	 * @return int[] Object IDs keyed by language slug; empty when the object has no language.
	 */
	public static function get_translations( $type, $id ) {
		if ( null === self::get_language( $type, $id ) ) {
			return array();
		}
		$translations = self::TERM === $type ? pll_get_term_translations( (int) $id ) : pll_get_post_translations( (int) $id );
		return array_map( 'intval', (array) $translations );
	}

	/**
	 * The part of a translation group that the current user may see, for the API responses. Terms are public, as in
	 * the core REST API; for posts, the other translations are kept only when the user could read them through the
	 * core REST API. The write logic keeps using the whole group (`get_translations()`).
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param int    $id   Object ID, always kept: the caller already shows it.
	 * @return int[] Object IDs keyed by language slug.
	 */
	public static function get_visible_translations( $type, $id ) {
		$group = self::get_translations( $type, $id );
		if ( self::POST !== $type ) {
			return $group;
		}
		return array_filter(
			$group,
			static function ( $tr_id ) use ( $id ) {
				return (int) $id === $tr_id || self::can_read_post( $tr_id );
			}
		);
	}

	/**
	 * Whether the current user can read a post, with the rule of the core REST API: a post with a public status
	 * (attachments take the status of their parent), or the `read_post` capability.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function can_read_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return false;
		}
		$status = get_post_status_object( (string) get_post_status( $post ) );
		return ( $status && $status->public ) || current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Whether the current user can edit an object.
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param int    $id   Object ID.
	 * @return bool
	 */
	public static function can_edit( $type, $id ) {
		return current_user_can( self::TERM === $type ? 'edit_term' : 'edit_post', (int) $id );
	}

	/**
	 * Sets the language of an object. Does nothing when the object already has that language.
	 *
	 * Polylang moves the object to the new language inside its translation group. When another object of the group
	 * already has the new language, Polylang would silently drop the object from the group: this method returns an
	 * error instead, unless `$leave_group` is true, in which case the object first leaves its group.
	 *
	 * @param string $type        Translations::POST or Translations::TERM.
	 * @param int    $id          Object ID.
	 * @param string $slug        Language slug.
	 * @param bool   $leave_group Whether the object may leave its group when the language is already taken there.
	 * @return true|WP_Error
	 */
	public static function set_language( $type, $id, $slug, $leave_group = false ) {
		$id = (int) $id;

		if ( ! self::is_language( $slug ) ) {
			return new WP_Error(
				'efpl_invalid_language',
				/* translators: %s: language slug. */
				sprintf( __( 'Unknown language: %s.', 'extensions-for-polylang' ), is_scalar( $slug ) ? (string) $slug : '' ),
				array( 'status' => 400 )
			);
		}

		if ( self::get_language( $type, $id ) === $slug ) {
			return true;
		}

		$group = self::get_translations( $type, $id );
		if ( isset( $group[ $slug ] ) && $group[ $slug ] !== $id ) {
			if ( ! $leave_group ) {
				return new WP_Error(
					'efpl_language_taken',
					sprintf(
						/* translators: 1: language slug, 2: object ID. */
						__( 'The translation in %1$s already exists (ID %2$d). Unlink it first, or send the new translations in the same request.', 'extensions-for-polylang' ),
						$slug,
						$group[ $slug ]
					),
					array(
						'status'      => 400,
						'translation' => $group[ $slug ],
					)
				);
			}

			$others = array_diff( $group, array( $id ) );
			$error  = self::check_can_edit( $type, $others );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			// Saving the group without the object unlinks it.
			self::save_group( $type, $others );
		}

		$done = self::TERM === $type ? pll_set_term_language( $id, $slug ) : pll_set_post_language( $id, $slug );
		if ( ! $done ) {
			return new WP_Error(
				'efpl_language_not_set',
				__( 'The language could not be set.', 'extensions-for-polylang' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Replaces the translation group of an object.
	 *
	 * The object itself always stays in the group, under its own language. Every other entry must be an object of the
	 * same post type or taxonomy, in the declared language, editable by the current user. Objects that leave a group
	 * must be editable too: Polylang unlinks the former translations of every listed object. Former translations that
	 * leave together stay linked to each other. An ID of 0 leaves that language empty.
	 *
	 * @param string $type         Translations::POST or Translations::TERM.
	 * @param int    $id           Object ID.
	 * @param array  $translations Object IDs keyed by language slug.
	 * @return int[]|WP_Error The saved group, keyed by language slug.
	 */
	public static function save_translations( $type, $id, array $translations ) {
		$plan = self::prepare_translations( $type, $id, $translations );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$saved = self::save_group( $type, $plan['group'] );
		foreach ( $plan['remainders'] as $rest ) {
			self::save_group( $type, $rest );
		}

		return array_map( 'intval', $saved );
	}

	/**
	 * Checks a new translation group without writing anything: entries, types, languages, permissions.
	 *
	 * Used by `save_translations()`, and before a change of language that comes with a new group, so that an invalid
	 * group is refused before the language changes.
	 *
	 * @param string      $type         Translations::POST or Translations::TERM.
	 * @param int         $id           Object ID.
	 * @param array       $translations Object IDs keyed by language slug.
	 * @param string|null $own          Language of the object; null for its current language.
	 * @return array|WP_Error {
	 *     @type int[]   $group      The new group, keyed by language slug.
	 *     @type int[][] $remainders Rest of each former group, to save again.
	 * }
	 */
	public static function prepare_translations( $type, $id, array $translations, $own = null ) {
		$id  = (int) $id;
		$own = null === $own ? self::get_language( $type, $id ) : $own;

		if ( null === $own ) {
			return new WP_Error(
				'efpl_no_language',
				__( 'Set the language of this item before linking its translations.', 'extensions-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		$subtype = self::subtype( $type, $id );
		$group   = array( $own => $id );

		foreach ( $translations as $slug => $tr_id ) {
			$slug = (string) $slug;

			if ( ! self::is_language( $slug ) ) {
				/* translators: %s: language slug. */
				return self::invalid_translation( sprintf( __( 'Unknown language: %s.', 'extensions-for-polylang' ), $slug ) );
			}

			if ( ! is_int( $tr_id ) && ! ( is_string( $tr_id ) && ctype_digit( $tr_id ) ) ) {
				/* translators: %s: language slug. */
				return self::invalid_translation( sprintf( __( 'The %s translation must be an ID.', 'extensions-for-polylang' ), $slug ) );
			}
			$tr_id = (int) $tr_id;

			if ( $slug === $own ) {
				if ( 0 !== $tr_id && $tr_id !== $id ) {
					return self::invalid_translation(
						/* translators: 1: language slug, 2: object ID. */
						sprintf( __( 'This item is the %1$s translation: %1$s cannot point to ID %2$d.', 'extensions-for-polylang' ), $slug, $tr_id )
					);
				}
				continue;
			}

			if ( 0 === $tr_id ) {
				continue;
			}

			if ( $tr_id === $id ) {
				/* translators: %s: language slug. */
				return self::invalid_translation( sprintf( __( 'This item cannot be its own %s translation.', 'extensions-for-polylang' ), $slug ) );
			}

			if ( self::subtype( $type, $tr_id ) !== $subtype ) {
				/* translators: %d: object ID. */
				return self::invalid_translation( sprintf( __( 'ID %d does not exist or is of a different type.', 'extensions-for-polylang' ), $tr_id ) );
			}

			if ( self::get_language( $type, $tr_id ) !== $slug ) {
				/* translators: 1: object ID, 2: language slug. */
				return self::invalid_translation( sprintf( __( 'ID %1$d is not in %2$s.', 'extensions-for-polylang' ), $tr_id, $slug ) );
			}

			$group[ $slug ] = $tr_id;
		}

		/*
		 * Polylang unlinks, one by one, every member of the current groups of the listed objects that is not listed:
		 * unlinking one post would split all of its former translations. The rest of each former group is saved
		 * again afterwards, so its members stay linked to each other.
		 *
		 * IDs are collected without their language keys: two former groups can each lose a member in the same
		 * language, and both must be checked.
		 */
		$check      = array_values( array_diff( $group, array( $id ) ) );
		$remainders = array();
		foreach ( $group as $member ) {
			$rest = array_diff( self::get_translations( $type, $member ), $group );
			if ( ! $rest ) {
				continue;
			}
			$check = array_merge( $check, array_values( $rest ) );
			if ( count( $rest ) > 1 ) {
				$key = array_values( $rest );
				sort( $key );
				$remainders[ implode( ',', $key ) ] = $rest;
			}
		}

		$error = self::check_can_edit( $type, array_values( array_unique( $check ) ) );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return array(
			'group'      => $group,
			'remainders' => array_values( $remainders ),
		);
	}

	/**
	 * Saves a translation group with Polylang, which takes the first entry as reference object.
	 *
	 * @param string $type  Translations::POST or Translations::TERM.
	 * @param int[]  $group Object IDs keyed by language slug.
	 * @return int[]
	 */
	private static function save_group( $type, array $group ) {
		return (array) ( self::TERM === $type ? pll_save_term_translations( $group ) : pll_save_post_translations( $group ) );
	}

	/**
	 * Returns an error for the first object the current user cannot edit.
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param int[]  $ids  Object IDs.
	 * @return true|WP_Error
	 */
	private static function check_can_edit( $type, array $ids ) {
		foreach ( $ids as $tr_id ) {
			if ( ! self::can_edit( $type, $tr_id ) ) {
				return new WP_Error(
					'efpl_cannot_edit_translation',
					/* translators: %d: object ID. */
					sprintf( __( 'Sorry, you are not allowed to edit the translation with ID %d.', 'extensions-for-polylang' ), $tr_id ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}
		return true;
	}

	/**
	 * Error for an invalid entry of a translation group.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function invalid_translation( $message ) {
		return new WP_Error( 'efpl_invalid_translation', $message, array( 'status' => 400 ) );
	}
}
