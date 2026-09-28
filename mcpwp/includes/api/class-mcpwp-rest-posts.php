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
	 * Authenticate, then wire the category check into Mcpwp_Posts (#1022
	 * BLOCK-1, round-1 adversarial review of #1020's fix).
	 *
	 * Every route this controller registers shares this ONE
	 * permission_callback, so this is the single place — not one per
	 * write handler — where the caller's key becomes known, letting
	 * Mcpwp_Posts::validate_post_type() enforce category on every
	 * read/write/delete/create path (and, since bulk_update_posts()/
	 * bulk_create_posts() call update_post()/create_post() per item,
	 * every bulk item too) without that class needing any REST/auth
	 * context of its own. Wired only on a successful authentication —
	 * an insufficient-scope/disabled-category denial at the route level
	 * never reaches a handler, so there is nothing to wire for those.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		$result = parent::check_permission( $request );

		if ( true === $result ) {
			$this->posts->set_category_allowed_callback(
				function ( $post_type ) {
					return $this->caller_may_read_category( $this->category_for_post_type( $post_type ) );
				}
			);
		}

		return $result;
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
					'args'                => $this->get_post_update_args(),
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
						),
						'updates' => array(
							'description' => __( 'Array of objects to update (alias of posts). Each carries id plus the fields to change.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => false,
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

		// #1020: /posts is a 'content'-classified ROUTE, but ?post_type=
		// lets the caller name any writable type — product, elementor_library
		// — that belongs to a different tool category. Checked against the
		// raw param (before sanitize_query_args() folds it into $args) so an
		// unset param (defaults to 'post' downstream) is never checked
		// against the empty string.
		$requested_type = $request->get_param( 'post_type' );
		if ( null !== $requested_type && '' !== $requested_type ) {
			$category_denied = $this->guard_post_type_category_for_type( sanitize_key( (string) $requested_type ) );
			if ( $category_denied ) {
				return $category_denied;
			}
		}

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

		// #1020's category guard for this door now lives INSIDE
		// Mcpwp_Posts::get_post() (via validate_post_type(), wired through
		// check_permission() above) rather than here as a separate check —
		// round-1 adversarial review WARN-1: a standalone guard here built
		// its own generic 404, which carried a DIFFERENT (and less specific)
		// hint than get_post()'s own "post genuinely missing" branch,
		// letting a caller distinguish "wrong category" from "no such post"
		// by hint text alone. Folding the check into the SAME existence
		// condition Mcpwp_Posts::get_post() already uses makes a category
		// denial byte-identical to a missing post, by construction.
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

		$data   = Mcpwp_Posts::body_fields( $request );
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
		$data    = Mcpwp_Posts::body_fields( $request );
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

		$post_id = absint( $request->get_param( 'id' ) );
		$force   = rest_sanitize_boolean( $request->get_param( 'force' ) );

		// A permanent delete has no undo: approval by default (#536). Only for
		// a post that exists — a deferral is not a way to manufacture records.
		// Permanent is decided by the TYPE, not the flag alone: WordPress
		// trashes only post and page (#536 round 5).
		if ( Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			$post = get_post( $post_id );
			// The same type test the core method applies, so a type it would
			// refuse is refused here and never queued (#536 round 8).
			if ( ! $post || 'page' === $post->post_type || Mcpwp_Posts::is_blocked_post_type( $post->post_type ) || ( method_exists( 'Mcpwp_Posts', 'is_writable_post_type' ) && ! Mcpwp_Posts::is_writable_post_type( $post->post_type ) ) ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404, array( 'id' => $post_id ) );
			}
		}
		if ( isset( $post ) && $post && Mcpwp_Destructive_Gate::delete_is_permanent( $post->post_type, $force, (string) $post->post_status ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			return $this->defer_destructive( 'delete_post', $request, array( 'type' => (string) $post->post_type, 'id' => $post_id, 'title' => (string) $post->post_title ), sprintf( 'Permanently delete post #%d', $post_id ) );
		}

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

		// Content guardrails (#957 rule 2): a single bulk call publishing
		// more than the configured threshold goes through the approval flow
		// instead — every item below is force-created as a draft, and one
		// approval request covers publishing the whole batch. A batch that
		// only creates drafts is never gated.
		$bulk_plan = class_exists( 'Mcpwp_Content_Guardrails' )
			? Mcpwp_Content_Guardrails::plan_bulk_publish( $posts_data )
			: array( 'requires_approval' => false, 'threshold' => 0 );

		$created          = array();
		$errors           = $normalized['errors'];
		$forced_draft_ids = array();

		foreach ( $posts_data as $index => $post_item ) {
			if ( empty( $post_item['title'] ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'Title is required.', 'mcpwp' ),
				);
				continue;
			}

			$originally_publish = isset( $post_item['status'] ) && 'publish' === sanitize_key( (string) $post_item['status'] );
			if ( $bulk_plan['requires_approval'] && $originally_publish ) {
				$post_item['status'] = 'draft';
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
				if ( $bulk_plan['requires_approval'] && $originally_publish && ! empty( $result['id'] ) ) {
					$forced_draft_ids[] = (int) $result['id'];
				}
			}
		}

		$response = array(
			'created' => $created,
			'errors'  => $errors,
			'total'   => count( $created ),
		);

		if ( $bulk_plan['requires_approval'] && ! empty( $forced_draft_ids ) ) {
			$response['guardrails'] = $this->queue_bulk_publish_approval( $forced_draft_ids, 'post', $bulk_plan['threshold'], 'wp_bulk_create_posts' );
		}

		return $this->success_response( $response, 201 );
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
		// #1020/#1022 round-1 review WARN-1: the category check is folded
		// into this SAME condition (not a separate guard call afterward) so
		// a category denial is byte-identical to a genuinely missing post —
		// is_writable_post_type() above allows any public custom type
		// (product included) plus the Elementor safe_nonpublic_types, with
		// no idea which MCPWP tool category owns them, so without this a
		// 'content'-only key could still set a WooCommerce product's or an
		// Elementor template's featured image.
		if ( ! $post || ! Mcpwp_Posts::is_writable_post_type( $post->post_type ) || ! $this->caller_may_read_category( $this->category_for_post_type( $post->post_type ) ) ) {
			return $this->error_response(
				'not_found',
				__( 'Post not found.', 'mcpwp' ),
				404,
				array( 'id' => $post_id )
			);
		}

		// Any type that declares thumbnail support, not only post and page
		// (#797). A type without it is told so, rather than "not found" for a
		// post that plainly exists.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) && ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return $this->error_response(
				'thumbnails_unsupported',
				sprintf(
					/* translators: %s: post type */
					__( 'Post type "%s" does not support featured images.', 'mcpwp' ),
					$post->post_type
				),
				400,
				array(
					'id'        => $post_id,
					'post_type' => $post->post_type,
					'hint'      => 'The type was registered without "thumbnail" in its supports list. Add it in the plugin that registers the type, or store the attachment id in a meta field of your own.',
				)
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
			'force' => rest_sanitize_boolean( $request->get_param( 'force' ) ),
		);

		// Purging every draft permanently has no undo: approval by default (#536).
		// Drafts are post/page only (Mcpwp_Drafts), so this is permanent on
		// force, or on a site with EMPTY_TRASH_DAYS = 0.
		if ( Mcpwp_Destructive_Gate::delete_is_permanent( 'post', $args['force'] ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			return $this->defer_destructive( 'delete_all_drafts', $request, array( 'type' => 'drafts', 'id' => null, 'title' => 'all drafts' ), 'Permanently delete all drafts' );
		}

		$result = $this->drafts->delete_all_drafts( $args );

		return $this->success_response( $result );
	}
}
