<?php
/**
 * REST endpoint: translation status.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Rest\Tools;

use Ryadel\ExtensionsForPolylang\Services\Status;
use Ryadel\ExtensionsForPolylang\Services\Translations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET extensions-for-polylang/v1/status`: counts by post type and language; with `missing=<lang>`, the paginated
 * list of the posts without a translation in that language.
 *
 * Lists include drafts, so a post type is shown only to users who can edit the posts of others.
 */
final class Status_Controller {

	/**
	 * Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes() {
		$languages = Translations::language_slugs();
		$statuses  = array_merge( array( 'any' ), array_values( get_post_stati( array( 'internal' => false ) ) ) );

		register_rest_route(
			Posts_Controller::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'type'     => array(
						'description' => __( 'Limit to one post type.', 'extensions-for-polylang' ),
						'type'        => 'string',
					),
					'status'   => array(
						'description' => __( 'Post status; "any" counts every status but trash and auto-draft.', 'extensions-for-polylang' ),
						'type'        => 'string',
						'enum'        => $statuses,
						'default'     => 'any',
					),
					'missing'  => array(
						'description' => __( 'List the posts without a translation in this language.', 'extensions-for-polylang' ),
						'type'        => 'string',
						'enum'        => $languages,
					),
					'from'     => array(
						'description' => __( 'With missing: only the posts in this language.', 'extensions-for-polylang' ),
						'type'        => 'string',
						'enum'        => $languages,
					),
					'page'     => array(
						'description' => __( 'Page of the list.', 'extensions-for-polylang' ),
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
					),
					'per_page' => array(
						'description' => __( 'Posts per page of the list.', 'extensions-for-polylang' ),
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
					),
				),
			)
		);
	}

	/**
	 * The user must be able to edit the posts of others in at least one translated post type, and in the requested
	 * one if any.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$type = $request['type'];

		if ( null !== $type && ! in_array( $type, Status::post_types( true ), true ) ) {
			return new WP_Error(
				'efpl_invalid_post_type',
				__( 'The post type does not exist, is not shown in the REST API or is not translated.', 'extensions-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->post_types( $type ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to see the translation status.', 'extensions-for-polylang' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Counts, or the list of untranslated posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post_types = $this->post_types( $request['type'] );
		$status     = (string) $request['status'];
		$missing    = $request['missing'];

		if ( null === $missing ) {
			if ( null !== $request['from'] ) {
				return new WP_Error( 'efpl_from_without_missing', __( 'The from parameter needs missing.', 'extensions-for-polylang' ), array( 'status' => 400 ) );
			}
			return $this->counts( $post_types, $status );
		}

		if ( $missing === $request['from'] ) {
			return new WP_Error( 'efpl_same_language', __( 'The from and missing languages must differ.', 'extensions-for-polylang' ), array( 'status' => 400 ) );
		}

		return $this->untranslated( $post_types, $status, $missing, $request );
	}

	/**
	 * Response with the counts.
	 *
	 * @param string[] $post_types Post types.
	 * @param string   $status     Post status.
	 * @return WP_REST_Response
	 */
	private function counts( array $post_types, $status ) {
		$counts = Status::counts( $post_types, $status );
		$types  = array();

		foreach ( $counts as $post_type => $data ) {
			$object = get_post_type_object( $post_type );
			foreach ( $data['languages'] as $slug => $language ) {
				$data['languages'][ $slug ]['untranslated'] = (object) $language['untranslated'];
			}
			$types[ $post_type ] = array(
				'label'       => $object ? $object->labels->name : $post_type,
				'no_language' => $data['no_language'],
				'languages'   => (object) $data['languages'],
			);
		}

		return new WP_REST_Response(
			array(
				'status'           => $status,
				'languages'        => Translations::language_slugs(),
				'default_language' => pll_default_language( 'slug' ),
				'types'            => (object) $types,
			)
		);
	}

	/**
	 * Response with a page of untranslated posts, and the X-WP-Total and X-WP-TotalPages headers.
	 *
	 * @param string[]        $post_types Post types.
	 * @param string          $status     Post status.
	 * @param string          $missing    Language the translation is missing in.
	 * @param WP_REST_Request $request    Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function untranslated( array $post_types, $status, $missing, $request ) {
		$per_page  = (int) $request['per_page'];
		$page      = (int) $request['page'];
		$result    = Status::untranslated( $missing, $post_types, $request['from'], $status, $page, $per_page );
		$max_pages = (int) ceil( $result['total'] / max( 1, $per_page ) );

		// Same answer as the core post collections for a page past the end.
		if ( $page > 1 && $page > $max_pages ) {
			return new WP_Error(
				'rest_post_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'extensions-for-polylang' ),
				array( 'status' => 400 )
			);
		}

		$items = array();
		foreach ( $result['ids'] as $id ) {
			$items[] = array(
				'id'           => $id,
				'type'         => get_post_type( $id ),
				'status'       => get_post_status( $id ),
				'title'        => html_entity_decode( get_the_title( $id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'lang'         => Translations::get_language( Translations::POST, $id ),
				'translations' => (object) Translations::get_translations( Translations::POST, $id ),
				'link'         => get_permalink( $id ),
				'edit_link'    => get_edit_post_link( $id, 'raw' ),
			);
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $max_pages );
		return $response;
	}

	/**
	 * Translated post types shown in REST whose posts of others the user can edit.
	 *
	 * @param string|null $type Only this post type.
	 * @return string[]
	 */
	private function post_types( $type = null ) {
		$post_types = null === $type ? Status::post_types( true ) : array( $type );
		return array_values(
			array_filter(
				$post_types,
				static function ( $post_type ) {
					$object = get_post_type_object( $post_type );
					return $object && current_user_can( $object->cap->edit_others_posts );
				}
			)
		);
	}
}
