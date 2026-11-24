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
 * `POST ryadel-extensions-for-polylang/v1/posts`: creates one post per language through the core endpoint of the post type,
 * links them as translations, and deletes everything it created if any step fails.
 *
 * Terms can be given by slug or name in the taxonomy fields (`categories`, `tags`...), besides IDs.
 */
final class Posts_Controller {

	const REST_NAMESPACE = 'ryadel-extensions-for-polylang/v1';

	/**
	 * Most terms accepted in one taxonomy field of one language.
	 */
	const MAX_TERMS = 100;

	/**
	 * Polylang's taxonomies of the translation groups: their terms are created when posts or terms are linked.
	 */
	const GROUP_TAXONOMIES = array( 'post_translations', 'term_translations' );

	/**
	 * IDs of the terms created while the current request runs, by us or by Polylang, keyed by term ID.
	 *
	 * @var string[]
	 */
	private $created_terms = array();

	/**
	 * Taxonomies whose new terms are recorded for the rollback, while the current request runs.
	 *
	 * @var string[]
	 */
	private $term_taxonomies = array();

	/**
	 * IDs of the posts inserted by the inner request that is running, whether it succeeds or not.
	 *
	 * @var int[]
	 */
	private $inserted_posts = array();

	/**
	 * Inner request that is running.
	 *
	 * @var WP_REST_Request|null
	 */
	private $inner_request;

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
						'description' => __( 'Post type.', 'ryadel-extensions-for-polylang' ),
						'type'        => 'string',
						'default'     => 'post',
					),
					'create_terms' => array(
						'description' => __( 'Whether to create the terms that match no existing term in any language.', 'ryadel-extensions-for-polylang' ),
						'type'        => 'boolean',
						'default'     => false,
					),
					'translations' => array(
						'description'          => __( 'One object per language, keyed by language slug, with the fields accepted by the core endpoint of the post type. Terms can be given by slug or name.', 'ryadel-extensions-for-polylang' ),
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
				__( 'The post type does not exist, is not shown in the REST API or is not translated.', 'ryadel-extensions-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to create posts as this user.', 'ryadel-extensions-for-polylang' ),
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

		// The terms are read and checked once, before anything is created.
		$terms = $this->parse_terms( $translations, $taxonomies );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$missing = array();
		foreach ( $terms as $lang => $by_field ) {
			foreach ( $by_field as $field => $values ) {
				foreach ( $values as $value ) {
					if ( is_string( $value ) && 0 === Terms::find( $taxonomies[ $field ], $value, $lang ) ) {
						$missing[ $taxonomies[ $field ] ][ $value ] = $value;
					}
				}
			}
		}
		$error = $this->check_missing_terms( $missing, $create_terms );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$this->created_terms   = array();
		$this->term_taxonomies = array_merge( array_values( $taxonomies ), self::GROUP_TAXONOMIES );
		add_action( 'created_term', array( $this, 'record_created_term' ), 10, 3 );

		$created  = array();
		$inserted = array();
		$posts    = array();
		$error    = null;
		$failed   = '';

		foreach ( $translations as $lang => $fields ) {
			$fields = $this->resolve_terms( (array) $fields, $terms[ $lang ], $taxonomies, $lang, $create_terms );
			if ( is_wp_error( $fields ) ) {
				$error  = $fields;
				$failed = $lang;
				break;
			}

			unset( $fields['translations'] );
			$fields['lang'] = $lang;

			$inner = new WP_REST_Request( 'POST', $route );
			$inner->set_body_params( $fields );

			// The core controller inserts the post before it saves terms, meta and REST fields: a later failure still
			// leaves the post behind. The controller announces its insert with `rest_insert_{$post_type}`, with the
			// request it serves: only the post of this inner request is recorded for the rollback, not the posts that
			// other code inserts meanwhile.
			$this->inserted_posts = array();
			$this->inner_request  = $inner;
			add_action( 'rest_insert_' . $post_type, array( $this, 'record_inserted_post' ), 1, 3 );
			$response = rest_do_request( $inner );
			remove_action( 'rest_insert_' . $post_type, array( $this, 'record_inserted_post' ), 1 );
			$this->inner_request = null;
			$inserted            = array_merge( $inserted, $this->inserted_posts );

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
			$this->rollback( array_unique( array_merge( array_values( $created ), $inserted ) ) );
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
	 * Records a term created during the request, for the rollback: only in the taxonomies of the post type and in
	 * the translation groups of Polylang. Terms of other taxonomies, created by other code, are not ours.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function record_created_term( $term_id, $tt_id, $taxonomy ) {
		if ( in_array( $taxonomy, $this->term_taxonomies, true ) ) {
			$this->created_terms[ (int) $term_id ] = $taxonomy;
		}
	}

	/**
	 * Records the post inserted by the running inner request, for the rollback. Hooked to `rest_insert_{$post_type}`.
	 *
	 * @param \WP_Post        $post     Post.
	 * @param WP_REST_Request $request  Request served by the controller.
	 * @param bool            $creating Whether the post is created.
	 * @return void
	 */
	public function record_inserted_post( $post, $request, $creating ) {
		if ( $creating && $post instanceof \WP_Post && null !== $this->inner_request && $request === $this->inner_request ) {
			$this->inserted_posts[] = (int) $post->ID;
		}
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
	 * Reads the taxonomy fields of every language. Each must be a list of term IDs (integers or digit strings) or of
	 * non-empty strings (slugs or names), at most MAX_TERMS once duplicates are removed; anything else is refused
	 * before anything is created.
	 *
	 * @param array    $translations Fields keyed by language slug.
	 * @param string[] $taxonomies   Taxonomy names keyed by REST field.
	 * @return array|WP_Error Values keyed by language and field: integers for IDs, trimmed strings for slugs or names.
	 */
	private function parse_terms( array $translations, array $taxonomies ) {
		$out = array();
		foreach ( $translations as $lang => $fields ) {
			$out[ $lang ] = array();
			foreach ( $taxonomies as $field => $taxonomy ) {
				if ( ! is_array( $fields ) || ! array_key_exists( $field, $fields ) ) {
					continue;
				}
				if ( ! is_array( $fields[ $field ] ) ) {
					return $this->invalid_terms( $lang, $field );
				}
				$values = array();
				foreach ( $fields[ $field ] as $value ) {
					if ( is_int( $value ) && $value > 0 ) {
						$values[] = $value;
					} elseif ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
						$values[] = (int) $value;
					} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
						$values[] = trim( $value );
					} else {
						return $this->invalid_terms( $lang, $field );
					}
				}
				$values = array_values( array_unique( $values, SORT_REGULAR ) );
				if ( count( $values ) > self::MAX_TERMS ) {
					return new WP_Error(
						'efpl_too_many_terms',
						/* translators: 1: field name, 2: language slug, 3: maximum number of terms. */
						sprintf( __( '%1$s (%2$s) can have at most %3$d terms.', 'ryadel-extensions-for-polylang' ), $field, $lang, self::MAX_TERMS ),
						array(
							'status' => 400,
							'lang'   => $lang,
						)
					);
				}
				$out[ $lang ][ $field ] = $values;
			}
		}
		return $out;
	}

	/**
	 * Error for a malformed taxonomy field.
	 *
	 * @param string $lang  Language slug.
	 * @param string $field Taxonomy field.
	 * @return WP_Error
	 */
	private function invalid_terms( $lang, $field ) {
		return new WP_Error(
			'efpl_invalid_terms',
			/* translators: 1: field name, 2: language slug. */
			sprintf( __( '%1$s (%2$s) must be a list of term IDs, slugs or names.', 'ryadel-extensions-for-polylang' ), $field, $lang ),
			array(
				'status' => 400,
				'lang'   => $lang,
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
				sprintf( __( 'Terms not found (%s). Send create_terms=true to create them.', 'ryadel-extensions-for-polylang' ), implode( '; ', $list ) ),
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
					sprintf( __( 'Sorry, you are not allowed to create terms in %s.', 'ryadel-extensions-for-polylang' ), $taxonomy ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return true;
	}

	/**
	 * Replaces the taxonomy fields with term IDs, creating the missing terms when allowed.
	 *
	 * @param array    $fields       Fields of one language.
	 * @param array    $terms        Values of this language from `parse_terms()`, keyed by field.
	 * @param string[] $taxonomies   Taxonomy names keyed by REST field.
	 * @param string   $lang         Language slug.
	 * @param bool     $create_terms Whether the client asked to create missing terms.
	 * @return array|WP_Error
	 */
	private function resolve_terms( array $fields, array $terms, array $taxonomies, $lang, $create_terms ) {
		foreach ( $terms as $field => $values ) {
			$taxonomy = $taxonomies[ $field ];
			$ids      = array();
			foreach ( $values as $value ) {
				if ( is_int( $value ) ) {
					$ids[] = $value;
					continue;
				}
				$id = Terms::find( $taxonomy, $value, $lang );
				if ( 0 === $id ) {
					// Checked again where the term is inserted, not only in the preliminary check.
					$error = $this->check_missing_terms( array( $taxonomy => array( $value => $value ) ), $create_terms );
					if ( is_wp_error( $error ) ) {
						return $error;
					}
					$id = Terms::create( $taxonomy, $value, $lang );
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
	 * The posts go first. A recorded term is then deleted only when nothing refers to it any more: no object in the
	 * term (other code may have given it to another post meanwhile) and no child term. Deleting a term can free
	 * another one, such as the translation group of the term, hence the passes.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	private function rollback( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$terms = $this->created_terms;
		do {
			$deleted = false;
			foreach ( $terms as $term_id => $taxonomy ) {
				if ( ! term_exists( $term_id, $taxonomy ) ) {
					unset( $terms[ $term_id ] );
					continue;
				}
				$objects  = get_objects_in_term( $term_id, $taxonomy );
				$children = is_taxonomy_hierarchical( $taxonomy ) ? get_term_children( $term_id, $taxonomy ) : array();
				if ( is_array( $objects ) && ! $objects && is_array( $children ) && ! $children ) {
					wp_delete_term( $term_id, $taxonomy );
					unset( $terms[ $term_id ] );
					$deleted = true;
				}
			}
		} while ( $deleted && $terms );

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
