<?php
/**
 * Posts REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts REST controller.
 */
class Mcpwp_REST_Posts extends Mcpwp_REST_API {

	/**
	 * Posts handler.
	 *
	 * @var Mcpwp_Posts
	 */
	private $posts;

	/**
	 * Drafts handler.
	 *
	 * @var Mcpwp_Drafts
	 */
	private $drafts;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->posts  = new Mcpwp_Posts();
		$this->drafts = new Mcpwp_Drafts();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// List posts
		register_rest_route(
			$this->namespace,
			'/posts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_posts' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$this->get_pagination_args(),
						array(
							'status'   => array(
								'description' => __( 'Post status filter.', 'mcpwp' ),
								'type'        => 'string',
								'default'     => 'publish',
							),
							'category' => array(
								'description' => __( 'Category ID filter.', 'mcpwp' ),
								'type'        => 'integer',
							),
							'search'   => array(
								'description' => __( 'Search term.', 'mcpwp' ),
								'type'        => 'string',
							),
							'ids'      => array(
								'description' => __( 'Comma-separated post IDs to fetch.', 'mcpwp' ),
								'type'        => 'string',
							),
							'fields'   => array(
								'description' => __( 'Comma-separated field names to return (e.g. id,title,word_count,content).', 'mcpwp' ),
								'type'        => 'string',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_post_args(),
				),
			)
		);

		// Single post
		register_rest_route(
			$this->namespace,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Force permanent deletion.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Bulk create/update posts
		register_rest_route(
			$this->namespace,
			'/posts/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_create_posts' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'posts' => array(
							'description' => __( 'Array of post objects to create.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'bulk_update_posts' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'posts' => array(
							'description' => __( 'Array of post objects to update. Each must have id.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => false,
						'updates' => array(
							'description' => __( 'Array of objects to update (alias of posts). Each carries id plus the fields to change.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => false,
						),
						),
					),
				),
			)
		);

		// Set featured image
		register_rest_route(
			$this->namespace,
			'/posts/(?P<id>\d+)/featured-image',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_featured_image' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'media_id' => array(
							'description' => __( 'Media attachment ID. Use 0 to remove featured image.', 'mcpwp' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Drafts
		register_rest_route(
			$this->namespace,
			'/drafts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_drafts' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type' => array(
							'description' => __( 'Post type filter.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'post', 'page', 'all' ),
							'default'     => 'all',
						),
					),
				),
			)
		);

		// Delete all drafts
		register_rest_route(
			$this->namespace,
			'/drafts/delete-all',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_all_drafts' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'  => array(
							'description' => __( 'Post type filter.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'post', 'page', 'all' ),
							'default'     => 'all',
						),
						'force' => array(
							'description' => __( 'Permanently delete.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * List posts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function list_posts( $request ) {
		$this->log_activity( 'list_posts', $request );

		$args   = $this->sanitize_query_args( $request->get_params() );
		$result = $this->posts->list_posts( $args );

		return $this->success_response( $result );
	}

	/**
	 * Get single post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_post( $request ) {
		$this->log_activity( 'get_post', $request );

		$post_id = $request->get_param( 'id' );

		// Non-public gate: get_post() formats the post regardless of status, so a
		// read-only key could otherwise read a draft/pending/private body. Return
		// the same 404 as a missing post so existence is never disclosed.
		$denied = $this->guard_non_public_post(
			get_post( absint( $post_id ) ),
			__( 'Post not found.', 'mcpwp' )
		);
		if ( $denied ) {
			return $denied;
		}

		$result = $this->posts->get_post( $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Create post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_post( $request ) {
		$this->log_activity( 'create_post', $request );

		$data   = $request->get_params();
		$result = $this->posts->create_post( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Update post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_post( $request ) {
		$this->log_activity( 'update_post', $request );

		$post_id = $request->get_param( 'id' );
		$data    = $request->get_params();
		unset( $data['id'] );

		$result = $this->posts->update_post( $post_id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Delete post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_post( $request ) {
		$this->log_activity( 'delete_post', $request );

		$post_id = $request->get_param( 'id' );
		$force   = $request->get_param( 'force' );

		$result = $this->posts->delete_post( $post_id, $force );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Bulk create posts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function bulk_create_posts( $request ) {
		$this->log_activity( 'bulk_create_posts', $request );

		$posts_data = $request->get_param( 'posts' );

		if ( ! is_array( $posts_data ) || empty( $posts_data ) ) {
			return $this->error_response( 'invalid_posts', __( 'Posts must be a non-empty array.', 'mcpwp' ), 400 );
		}

		if ( count( $posts_data ) > 50 ) {
			return $this->error_response( 'too_many_posts', __( 'Maximum 50 posts per batch.', 'mcpwp' ), 400 );
		}

		$normalized = $this->normalize_bulk_items( $posts_data, 'post' );
		$posts_data = $normalized['items'];

		$created = array();
		$errors  = $normalized['errors'];

		foreach ( $posts_data as $index => $post_item ) {
			if ( empty( $post_item['title'] ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'Title is required.', 'mcpwp' ),
				);
				continue;
			}

			$result = $this->posts->create_post( $post_item );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'index'   => $index,
					'title'   => $post_item['title'],
					'message' => $result->get_error_message(),
				);
			} else {
				$created[] = $result;
			}
		}

		return $this->success_response(
			array(
				'created' => $created,
				'errors'  => $errors,
				'total'   => count( $created ),
			),
			201
		);
	}

	/**
	 * Bulk update posts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function bulk_update_posts( $request ) {
		$this->log_activity( 'bulk_update_posts', $request );

		$posts_data = $request->get_param( 'posts' );

		// `updates` is what the tool name promises; `posts` is the original
		// spelling. Either is fine, and the error names both (#803).
		if ( ! is_array( $posts_data ) || empty( $posts_data ) ) {
			$posts_data = $request->get_param( 'updates' );
		}

		if ( ! is_array( $posts_data ) || empty( $posts_data ) ) {
			return $this->error_response( 'invalid_posts', __( 'Pass the items to update as `updates` (or `posts`): a non-empty array of objects, each with an id.', 'mcpwp' ), 400 );
		}

		if ( count( $posts_data ) > 50 ) {
			return $this->error_response( 'too_many_posts', __( 'Maximum 50 posts per batch.', 'mcpwp' ), 400 );
		}

		$normalized = $this->normalize_bulk_items( $posts_data, 'post' );
		$posts_data = $normalized['items'];

		$updated = array();
		$errors  = $normalized['errors'];

		foreach ( $posts_data as $index => $post_item ) {
			if ( empty( $post_item['id'] ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'id is required for each post.', 'mcpwp' ),
				);
				continue;
			}

			$post_id = absint( $post_item['id'] );
			$data    = $post_item;
			unset( $data['id'] );

			$result = $this->posts->update_post( $post_id, $data );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'index'   => $index,
					'id'      => $post_id,
					'message' => $result->get_error_message(),
				);
			} else {
				$updated[] = $result;
			}
		}

		return $this->success_response(
			array(
				'updated' => $updated,
				'errors'  => $errors,
				'total'   => count( $updated ),
			)
		);
	}

	/**
	 * Set featured image for a post or page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_featured_image( $request ) {
		$this->log_activity( 'set_featured_image', $request );

		$post_id  = absint( $request->get_param( 'id' ) );
		$media_id = absint( $request->get_param( 'media_id' ) );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return $this->error_response(
				'not_found',
				__( 'Post not found.', 'mcpwp' ),
				404,
				array( 'id' => $post_id )
			);
		}

		if ( 0 === $media_id ) {
			delete_post_thumbnail( $post_id );
			return $this->success_response(
				array(
					'success' => true,
					'post_id' => $post_id,
					'message' => __( 'Featured image removed.', 'mcpwp' ),
				)
			);
		}

		$attachment = get_post( $media_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->error_response(
				'invalid_media',
				__( 'Invalid media ID.', 'mcpwp' ),
				400
			);
		}

		set_post_thumbnail( $post_id, $media_id );

		$image = wp_get_attachment_image_src( $media_id, 'full' );

		return $this->success_response(
			array(
				'success'  => true,
				'post_id'  => $post_id,
				'media_id' => $media_id,
				'url'      => $image ? $image[0] : '',
			)
		);
	}

	/**
	 * List drafts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_drafts( $request ) {
		$this->log_activity( 'list_drafts', $request );

		// Non-public gate: /drafts enumerates every draft's title, author, dates and
		// edit URL — all unpublished. A read-only key must never see them. Mirror the
		// search_content clamp-and-report pattern: return an empty list (not a 403)
		// with status_downgraded=true, rather than disclosing unpublished content.
		if ( ! $this->requester_can_see_non_public() ) {
			return $this->success_response(
				array(
					'drafts'            => array(),
					'total'             => 0,
					'posts'             => 0,
					'pages'             => 0,
					'status_downgraded' => true,
				)
			);
		}

		$args = array(
			'type' => $request->get_param( 'type' ),
		);

		$result = $this->drafts->list_drafts( $args );

		return $this->success_response( $result );
	}

	/**
	 * Delete all drafts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function delete_all_drafts( $request ) {
		$this->log_activity( 'delete_all_drafts', $request );

		$args = array(
			'type'  => $request->get_param( 'type' ),
			'force' => $request->get_param( 'force' ),
		);

		$result = $this->drafts->delete_all_drafts( $args );

		return $this->success_response( $result );
	}
}
