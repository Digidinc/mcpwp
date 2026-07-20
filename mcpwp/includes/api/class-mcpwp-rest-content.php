<?php
/**
 * Content REST Controller (generic post types)
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content REST controller.
 *
 * Provides limited, generic access for listing and deleting arbitrary post types.
 * This is intentionally separate from the dedicated posts/pages controllers.
 */
class Mcpwp_REST_Content extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// List content items by post type.
		register_rest_route(
			$this->namespace,
			'/content',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_content' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$this->get_pagination_args(),
						array(
							'post_type' => array(
								'description' => __( 'Post type to list (e.g., product).', 'mcpwp' ),
								'type'        => 'string',
								'required'    => true,
							),
							'status'    => array(
								'description' => __( 'Post status filter.', 'mcpwp' ),
								'type'        => 'string',
								'default'     => 'any',
							),
							'search'    => array(
								'description' => __( 'Search term.', 'mcpwp' ),
								'type'        => 'string',
							),
						)
					),
				),
			)
		);

		// Delete a single content item by ID and post type.
		register_rest_route(
			$this->namespace,
			'/content/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_content' ),
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
	}

	/**
	 * List content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function list_content( $request ) {
		$this->log_activity( 'list_content', $request );

		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return $this->error_response(
				'invalid_post_type',
				__( 'Invalid post_type.', 'mcpwp' ),
				400
			);
		}

		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' === $status ) {
			$status = 'any';
		}

		// Security clamp: a read-only key may only enumerate published content.
		// The default (and any caller-supplied draft/private/pending/any) is
		// forced to 'publish' unless the caller carries write scope (or trusted
		// admin-AJAX), so this generic CPT listing cannot leak non-public titles,
		// URLs, or statuses.
		if ( ! $this->requester_can_see_non_public() ) {
			$status = 'publish';
		}

		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$query = new WP_Query(
			array(
				'post_type'           => $post_type,
				'post_status'         => $status,
				's'                   => $search,
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$items = array();
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = array(
					'id'       => (int) $post->ID,
					'type'     => (string) $post->post_type,
					'status'   => (string) $post->post_status,
					'title'    => (string) $post->post_title,
					'slug'     => (string) $post->post_name,
					'url'      => get_permalink( $post ),
					'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					'date'     => (string) $post->post_date,
					'modified' => (string) $post->post_modified,
				);
			}
		}

		return $this->success_response(
			array(
				'post_type'  => $post_type,
				'status'     => $status,
				'search'     => $search,
				'items'      => $items,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
				),
			)
		);
	}

	/**
	 * Delete content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_content( $request ) {
		$this->log_activity( 'delete_content', $request );

		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$post_id   = absint( $request->get_param( 'id' ) );
		$force     = (bool) $request->get_param( 'force' );

		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return $this->error_response(
				'invalid_post_type',
				__( 'Invalid post_type.', 'mcpwp' ),
				400
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return $this->error_response(
				'not_found',
				__( 'Content item not found.', 'mcpwp' ),
				404
			);
		}

		$result = wp_delete_post( $post_id, $force );
		if ( ! $result ) {
			return $this->error_response(
				'delete_failed',
				__( 'Failed to delete content item.', 'mcpwp' ),
				500
			);
		}

		return $this->success_response(
			array(
				'success'   => true,
				'post_id'   => $post_id,
				'post_type' => $post_type,
				'message'   => $force
					? __( 'Content permanently deleted.', 'mcpwp' )
					: __( 'Content moved to trash.', 'mcpwp' ),
			)
		);
	}
}
