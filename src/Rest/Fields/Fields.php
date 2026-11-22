<?php
/**
 * REST fields `lang` and `translations`.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Rest\Fields;

use Ryadel\ExtensionsForPolylang\Services\Translations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `lang` and `translations` to the posts and terms of every translated post type and taxonomy shown in REST.
 */
final class Fields {

	/**
	 * Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		$post_types = array_filter(
			get_post_types( array( 'show_in_rest' => true ) ),
			static function ( $post_type ) {
				return Translations::is_translated( Translations::POST, $post_type );
			}
		);
		$taxonomies = array_filter(
			get_taxonomies( array( 'show_in_rest' => true ) ),
			static function ( $taxonomy ) {
				return Translations::is_translated( Translations::TERM, $taxonomy );
			}
		);

		if ( $post_types ) {
			$this->register_fields( Translations::POST, array_values( $post_types ) );
		}
		if ( $taxonomies ) {
			$this->register_fields( Translations::TERM, array_values( $taxonomies ) );
		}
	}

	/**
	 * Registers both fields on a group of post types or taxonomies. `lang` goes first: WordPress applies the fields
	 * in registration order, and `translations` needs the final language.
	 *
	 * @param string   $type         Translations::POST or Translations::TERM.
	 * @param string[] $object_types Post types or taxonomies.
	 * @return void
	 */
	private function register_fields( $type, array $object_types ) {
		$slugs = Translations::language_slugs();

		register_rest_field(
			$object_types,
			'lang',
			array(
				'get_callback'    => static function ( $data ) use ( $type ) {
					return Translations::get_language( $type, (int) $data['id'] );
				},
				'update_callback' => static function ( $value, $item, $field_name, $request ) use ( $type ) {
					if ( null === $value ) {
						return true;
					}
					return self::update_language( $type, $item, $value, $request );
				},
				'schema'          => array(
					'description' => __( 'Language slug. Changing it moves the item to another language.', 'extensions-for-polylang' ),
					'type'        => array( 'string', 'null' ),
					'enum'        => array_merge( $slugs, array( null ) ),
					'context'     => array( 'view', 'edit', 'embed' ),
				),
			)
		);

		$properties = array();
		foreach ( $slugs as $slug ) {
			$properties[ $slug ] = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
		}

		register_rest_field(
			$object_types,
			'translations',
			array(
				'get_callback'    => static function ( $data ) use ( $type ) {
					return (object) Translations::get_translations( $type, (int) $data['id'] );
				},
				'update_callback' => static function ( $value, $item ) use ( $type ) {
					$saved = Translations::save_translations( $type, self::item_id( $type, $item ), (array) $value );
					return is_wp_error( $saved ) ? $saved : true;
				},
				'schema'          => array(
					'description'          => __( 'IDs of the translations keyed by language slug, the item itself included. Writing it replaces the whole translation group.', 'extensions-for-polylang' ),
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
					'context'              => array( 'view', 'edit' ),
				),
			)
		);
	}

	/**
	 * Update callback of `lang`.
	 *
	 * @param string          $type    Translations::POST or Translations::TERM.
	 * @param object          $item    WP_Post or WP_Term.
	 * @param string          $value   Language slug.
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function update_language( $type, $item, $value, $request ) {
		$id           = self::item_id( $type, $item );
		$translations = $request instanceof WP_REST_Request ? $request->get_param( 'translations' ) : null;

		if ( null !== $translations && Translations::is_language( $value ) && Translations::get_language( $type, $id ) !== $value ) {
			// The new group is checked against the new language before anything is written: a 400 changes nothing.
			$plan = Translations::prepare_translations( $type, $id, (array) $translations, $value );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
		}

		// When the same request sends the new group, the item may leave its current group.
		return Translations::set_language( $type, $id, $value, null !== $translations );
	}

	/**
	 * ID of the object passed to an update callback.
	 *
	 * @param string $type Translations::POST or Translations::TERM.
	 * @param object $item WP_Post or WP_Term.
	 * @return int
	 */
	private static function item_id( $type, $item ) {
		return (int) ( Translations::TERM === $type ? $item->term_id : $item->ID );
	}
}
