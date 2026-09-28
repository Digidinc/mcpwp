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

		// #1020: this generic listing is a 'content'-classified ROUTE, but the
		// caller names an arbitrary post type — a WooCommerce product, an
		// Elementor archetype — that belongs to a different tool category.
		// The caller named the type up front, so a 403 (not a 404) is the
		// right shape here; see guard_post_type_category_for_type().
		$category_denied = $this->guard_post_type_category_for_type( $post_type );
		if ( $category_denied ) {
			return $category_denied;
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
				// Match slugs as well as title/excerpt/content, the same as
				// the pages and posts listings. This query is built by hand
				// rather than through sanitize_query_args(), so it needs the
				// opt-in explicitly — without it wp_list_content(search: "<a
				// slug>") still answers with the empty result that made a
				// caller create a duplicate page (#785).
				Mcpwp_Slug_Search::QUERY_VAR => Mcpwp_Slug_Search::should_match_slug( $search ),
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

		// #1020: unlike list_content() above, a specific id IS being resolved
		// here, so a denial takes the not-found shape rather than 403 — a
		// scoped key must not be able to tell "wrong category" apart from
		// "no such product/template exists".
		$category_denied = $this->guard_post_type_category( $post, __( 'Content item not found.', 'mcpwp' ) );
		if ( $category_denied ) {
			return $category_denied;
		}

		// A permanent delete has no undo: approval by default (#536).
		$permanent = Mcpwp_Destructive_Gate::delete_is_permanent( $post_type, $force, (string) $post->post_status );

		if ( $permanent && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			return $this->defer_destructive( 'delete_content', $request, array( 'type' => $post_type, 'id' => $post_id, 'title' => get_the_title( $post_id ) ), sprintf( 'Permanently delete %s #%d', $post_type, $post_id ) );
		}

		// #1023 (round-1 adversarial review of #1020/#1022): wp_delete_post()
		// with force=false only auto-trashes when the post_type is LITERALLY
		// 'post' or 'page' — every other type (product, elementor_library, any
		// custom CPT) is PERMANENTLY deleted regardless of $force, silently
		// disagreeing with the $permanent decision above (and with the
		// "moved to trash" message this response used to send anyway). Trash
		// explicitly via wp_trash_post() — which works for any post type —
		// whenever $permanent said this was not a permanent delete, so the
		// actual outcome always matches what was decided and reported.
		if ( $permanent ) {
			$result = wp_delete_post( $post_id, true );
		} elseif ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
			$result = wp_delete_post( $post_id, false );
		} else {
			$result = wp_trash_post( $post_id );
		}
		if ( ! $result ) {
			return $this->error_response(
				'delete_failed',
				__( 'Failed to delete content item.', 'mcpwp' ),
				500
			);
		}

		// #952: this generic endpoint can delete an elementor_library post
		// too (a Theme Builder template has no dedicated CPT of its own).
		// elementor_pro_theme_builder_conditions cleanup happens via
		// Mcpwp_Theme_Builder::handle_before_delete_post(), hooked on
		// WordPress's own before_delete_post — it fires only for the
		// permanent branch above (trashing a post never fires
		// before_delete_post — the row still exists), which is correct: a
		// trashed template can still be restored, so its conditions cache
		// entry has nothing to clean up yet.
		return $this->success_response(
			array(
				'success'   => true,
				'post_id'   => $post_id,
				'post_type' => $post_type,
				'message'   => $permanent
					? __( 'Content permanently deleted.', 'mcpwp' )
					: __( 'Content moved to trash.', 'mcpwp' ),
			)
		);
	}
}
