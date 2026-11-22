<?php
/**
 * REST endpoint: a post created together with its translations.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Rest\Tools;

use Ryadel\ExtensionsForPolylang\Services\Terms;
use Ryadel\ExtensionsForPolylang\Services\Translations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `POST extensions-for-polylang/v1/posts`: creates one post per language through the core endpoint of the post type,
 * links them as translations, and deletes everything it created if any step fails.
 *
 * Terms can be given by slug or name in the taxonomy fields (`categories`, `tags`...), besides IDs.
 */
final class Posts_Controller {

	const REST_NAMESPACE = 'extensions-for-polylang/v1';

	/**
	 * IDs of the terms created while the current request runs, by us or by Polylang, keyed by term ID.
	 *
	 * @var string[]
	 */
	private $created_terms = array();

	/**
	 * Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes() {
		$languages = array();
		foreach ( Translations::language_slugs() as $slug ) {
			$languages[ $slug ] = array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(
					'type'         => array(
						'description' => __( 'Post type.', 'extensions-for-polylang' ),
						'type'        => 'string',
						'default'     => 'post',
					),
					'create_terms' => array(
						'description' => __( 'Whether to create the terms that match no existing term in any language.', 'extensions-for-polylang' ),
						'type'        => 'boolean',
						'default'     => false,
					),
					'translations' => array(
						'description'          => __( 'One object per language, keyed by language slug, with the fields accepted by the core endpoint of the post type. Terms can be given by slug or name.', 'extensions-for-polylang' ),
						'type'                 => 'object',
						'required'             => true,
						'minProperties'        => 1,
						'properties'           => $languages,
						'additionalProperties' => false,
					),
				),
			)
		);
	}

	/**
	 * The post type must be translated and shown in REST, and the user must be able to create its posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$post_type = get_post_type_object( (string) $request['type'] );

		if ( ! $post_type || empty( $post_type->show_in_rest ) || ! Translations::is_translated( Translations::POST, $post_type->name ) ) {
			return new WP_Error(
				'efpl_invalid_post_type',
				__( 'The post type does not exist, is not shown in the REST API or is not translated.', 'extensions-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to create posts as this user.', 'extensions-for-polylang' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Creates and links the posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$post_type    = (string) $request['type'];
		$translations = (array) $request['translations'];
		$create_terms = (bool) $request['create_terms'];
		$route        = rest_get_route_for_post_type_items( $post_type );
		$taxonomies   = $this->taxonomy_fields( $post_type );

		// Every term is checked before anything is created.
		$missing = array();
		foreach ( $translations as $lang => $fields ) {
			foreach ( $taxonomies as $field => $taxonomy ) {
				foreach ( $this->term_strings( $fields, $field ) as $value ) {
					if ( 0 === Terms::find( $taxonomy, $value, $lang ) ) {
						$missing[ $taxonomy ][ $value ] = $value;
					}
				}
			}
		}
		$error = $this->check_missing_terms( $missing, $create_terms );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$this->created_terms = array();
		add_action( 'created_term', array( $this, 'record_created_term' ), 10, 3 );

		$created = array();
		$posts   = array();
		$error   = null;
		$failed  = '';

		foreach ( $translations as $lang => $fields ) {
			$fields = $this->resolve_terms( (array) $fields, $taxonomies, $lang );
			if ( is_wp_error( $fields ) ) {
				$error  = $fields;
				$failed = $lang;
				break;
			}

			unset( $fields['translations'] );
			$fields['lang'] = $lang;

			$inner = new WP_REST_Request( 'POST', $route );
			$inner->set_body_params( $fields );
			$response = rest_do_request( $inner );

			if ( $response->is_error() ) {
				$error  = $response->as_error();
				$failed = $lang;
				break;
			}

			$data             = $response->get_data();
			$created[ $lang ] = (int) $data['id'];
			$posts[ $lang ]   = $data;
		}

		if ( null === $error && count( $created ) > 1 ) {
			$saved = Translations::save_translations( Translations::POST, reset( $created ), $created );
			if ( is_wp_error( $saved ) ) {
				$error = $saved;
			}
		}

		remove_action( 'created_term', array( $this, 'record_created_term' ), 10 );

		if ( null !== $error ) {
			$this->rollback( $created );
			return $this->with_language( $error, $failed );
		}

		foreach ( $posts as $lang => $data ) {
			if ( array_key_exists( 'translations', $data ) ) {
				$posts[ $lang ]['translations'] = (object) $created;
			}
		}

		$response = new WP_REST_Response(
			array(
				'translations' => (object) $created,
				'posts'        => (object) $posts,
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Records a term created during the request, for the rollback.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function record_created_term( $term_id, $tt_id, $taxonomy ) {
		$this->created_terms[ (int) $term_id ] = $taxonomy;
	}

	/**
	 * REST fields of the taxonomies of a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string[] Taxonomy names keyed by REST field (`categories`, `tags`...).
	 */
	private function taxonomy_fields( $post_type ) {
		$fields = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->show_in_rest ) ) {
				$fields[ empty( $taxonomy->rest_base ) ? $taxonomy->name : $taxonomy->rest_base ] = $taxonomy->name;
			}
		}
		return $fields;
	}

	/**
	 * Strings (slugs or names) given in a taxonomy field.
	 *
	 * @param mixed  $fields Fields of one language.
	 * @param string $field  Taxonomy field.
	 * @return string[]
	 */
	private function term_strings( $fields, $field ) {
		if ( ! is_array( $fields ) || ! isset( $fields[ $field ] ) || ! is_array( $fields[ $field ] ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$fields[ $field ],
				static function ( $value ) {
					return is_string( $value ) && ! is_numeric( $value ) && '' !== trim( $value );
				}
			)
		);
	}

	/**
	 * Error when some terms are missing and cannot be created.
	 *
	 * @param array $missing      Missing names, by taxonomy.
	 * @param bool  $create_terms Whether the client asked to create them.
	 * @return true|WP_Error
	 */
	private function check_missing_terms( array $missing, $create_terms ) {
		if ( ! $missing ) {
			return true;
		}

		if ( ! $create_terms ) {
			$list = array();
			foreach ( $missing as $taxonomy => $values ) {
				$list[] = $taxonomy . ': ' . implode( ', ', $values );
			}
			return new WP_Error(
				'efpl_terms_not_found',
				/* translators: %s: list of taxonomies and terms. */
				sprintf( __( 'Terms not found (%s). Send create_terms=true to create them.', 'extensions-for-polylang' ), implode( '; ', $list ) ),
				array(
					'status'  => 400,
					'missing' => array_map( 'array_values', $missing ),
				)
			);
		}

		foreach ( array_keys( $missing ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object || ! current_user_can( $object->cap->edit_terms ) ) {
				return new WP_Error(
					'efpl_cannot_create_terms',
					/* translators: %s: taxonomy. */
					sprintf( __( 'Sorry, you are not allowed to create terms in %s.', 'extensions-for-polylang' ), $taxonomy ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return true;
	}

	/**
	 * Replaces the slugs and names of the taxonomy fields with term IDs, creating the missing terms.
	 *
	 * @param array    $fields     Fields of one language.
	 * @param string[] $taxonomies Taxonomy names keyed by REST field.
	 * @param string   $lang       Language slug.
	 * @return array|WP_Error
	 */
	private function resolve_terms( array $fields, array $taxonomies, $lang ) {
		foreach ( $taxonomies as $field => $taxonomy ) {
			if ( ! isset( $fields[ $field ] ) || ! is_array( $fields[ $field ] ) ) {
				continue;
			}
			$ids = array();
			foreach ( $fields[ $field ] as $value ) {
				if ( is_numeric( $value ) ) {
					$ids[] = (int) $value;
					continue;
				}
				$id = Terms::find( $taxonomy, (string) $value, $lang );
				if ( 0 === $id ) {
					$id = Terms::create( $taxonomy, (string) $value, $lang );
					if ( is_wp_error( $id ) ) {
						return $id;
					}
				}
				$ids[] = $id;
			}
			$fields[ $field ] = $ids;
		}
		return $fields;
	}

	/**
	 * Deletes the posts and the terms created by a failed request.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	private function rollback( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->created_terms as $term_id => $taxonomy ) {
			wp_delete_term( $term_id, $taxonomy );
		}
		$this->created_terms = array();
	}

	/**
	 * Adds the language being created to an error.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $lang  Language slug.
	 * @return WP_Error
	 */
	private function with_language( WP_Error $error, $lang ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array( 'status' => 500 );
		if ( '' !== $lang && ! isset( $data['lang'] ) ) {
			$data['lang'] = $lang;
		}
		$error->add_data( $data );
		return $error;
	}
}
