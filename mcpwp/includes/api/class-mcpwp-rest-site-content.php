<?php
/**
 * Search, fetch, post-meta & rendered HTML.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search, fetch, post-meta & rendered HTML.
 */
class Mcpwp_REST_Site_Content extends Mcpwp_REST_API {

	/**
	 * Token-reasonable default window for a rendered-HTML response. Large
	 * enough to see meaningful content, small enough that an agent calling
	 * this in a loop doesn't blow its context — but the response always
	 * reports the document's true total_bytes and how to page for the rest,
	 * so a small default is never a silent cut (#804).
	 */
	const RENDERED_HTML_DEFAULT_MAX_BYTES = 51200;

	/**
	 * Hard per-call ceiling. Raised from the old 200 KB cap (#804) so a
	 * caller who explicitly asks for the whole document gets it in one call
	 * for anything up to 512 KB of rendered HTML — comfortably covers a real
	 * page's head+body+footer. Beyond that, offset-based paging is the
	 * documented path; there is no size a caller can be locked out of, only
	 * one that takes more than one call.
	 *
	 * Set at 512 KB rather than the 1 MB this first shipped with (#804
	 * adversarial review, W5): every window this size is held in PHP memory
	 * at once (the fetched body, the extracted/selector-matched copy, and
	 * the windowed slice can all be live simultaneously), and a caller may
	 * ask for the ceiling on every operation of a large batch. The spend
	 * budget also prices a call by how much of this ceiling it asks for
	 * (SPEND_SIZE_ROUTES in Mcpwp_Operation_Weights), so a batch of
	 * near-ceiling reads is charged for the memory it holds open, not free.
	 */
	const RENDERED_HTML_MAX_BYTES_CEILING = 524288;

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search_content' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'query'    => array(
							'description' => __( 'Search query string.', 'mcpwp' ),
							'type'        => 'string',
						),
						'q'        => array(
							'description' => __( 'Alias for query string.', 'mcpwp' ),
							'type'        => 'string',
						),
						'type'     => array(
							'description' => __( 'Content type filter (post, page, or any).', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'any',
						),
						'status'   => array(
							'description' => __( 'Post status filter.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'publish',
						),
						'per_page' => array(
							'description' => __( 'Results per page.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'page'     => array(
							'description' => __( 'Current page.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/fetch',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'fetch_content' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'              => array(
							'description' => __( 'Post or page ID.', 'mcpwp' ),
							'type'        => 'integer',
						),
						'url'             => array(
							'description' => __( 'Canonical post/page URL.', 'mcpwp' ),
							'type'        => 'string',
						),
						'type'            => array(
							'description' => __( 'Expected content type (post, page, or any).', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'any',
						),
						'include_content' => array(
							'description' => __( 'Include full content body in response.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/post-meta/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_meta_handler' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'  => array(
							'description' => __( 'Post or page ID.', 'mcpwp' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'key' => array(
							'description' => __( 'Specific meta key to retrieve.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_post_meta_handler' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'    => array(
							'description' => __( 'Post or page ID.', 'mcpwp' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'key'   => array(
							'description' => __( 'Meta key to set.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'value' => array(
							'description' => __( 'Meta value to set.', 'mcpwp' ),
							'required'    => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/rendered-html',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rendered_html' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'        => array(
							'description' => __( 'Post or page ID to fetch rendered HTML for.', 'mcpwp' ),
							'type'        => 'integer',
						),
						'url'       => array(
							'description' => __( 'URL to fetch (same-host only for SSRF safety).', 'mcpwp' ),
							'type'        => 'string',
						),
						'selector'  => array(
							'description' => __( 'CSS selector to extract (tag, .class, or #id) — applied to the full document before any windowing, so e.g. selector=footer is unaffected by max_bytes/offset.', 'mcpwp' ),
							'type'        => 'string',
						),
						'max_bytes' => array(
							'description' => __( 'Maximum response size in bytes (default 51200, max 524288/512KB). A negative value is refused with 400.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => self::RENDERED_HTML_DEFAULT_MAX_BYTES,
						),
						'offset'    => array(
							'description' => __( 'Byte offset into the full document to start reading from (default 0). Snapped to the nearest whole-character boundary for multi-byte UTF-8 content, so the response may report a slightly earlier actual offset than requested. The response reports total_bytes and next_offset so a caller can page through a document larger than one window, including reaching the tail/footer. A negative value is refused with 400.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 0,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/rendered-copy',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rendered_copy' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'              => array(
							'description' => __( 'Post or page ID.', 'mcpwp' ),
							'type'        => 'integer',
						),
						'url'             => array(
							'description' => __( 'URL to fetch (same-host only).', 'mcpwp' ),
							'type'        => 'string',
						),
						'include_chrome'  => array(
							'description' => __( 'Include nav/header/footer text (default false).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	// is_public_status() and requester_can_see_non_public() were promoted to the
	// shared Mcpwp_Api_Auth trait (trait-mcpwp-api-auth.php) so every REST
	// controller that reads or renders post bodies — elementor, blocks, pages,
	// posts — enforces the same non-public gate. This controller now inherits
	// them; see the trait for the canonical implementation.

	public function search_content( $request ) {
		$this->log_activity( 'search_content', $request );

		if ( ! class_exists( 'WP_Query' ) ) {
			return $this->error_response(
				'search_unavailable',
				__( 'Search is not available in this environment.', 'mcpwp' ),
				500
			);
		}

		$query = (string) $request->get_param( 'query' );
		if ( '' === trim( $query ) ) {
			$query = (string) $request->get_param( 'q' );
		}
		$query = sanitize_text_field( $query );

		if ( '' === $query ) {
			return $this->error_response(
				'missing_query',
				__( 'Search query is required.', 'mcpwp' ),
				400
			);
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! in_array( $type, array( 'post', 'page', 'any' ), true ) ) {
			$type = 'any';
		}

		// Coerce an array-valued status param (e.g. status[]=draft) to empty so it
		// falls through to the published default instead of raising an
		// "Array to string conversion" warning and being treated as an unknown
		// status. Ambiguous input still resolves toward public content.
		$status_param = $request->get_param( 'status' );
		$status       = is_array( $status_param ) ? '' : sanitize_key( (string) $status_param );
		if ( '' === $status ) {
			$status = 'publish';
		}

		// Non-public statuses (draft, private, pending, or the "any" catch-all)
		// are only searchable by a key with write scope or higher. For an
		// unprivileged key we clamp the query down to published content and
		// report the downgrade, so a read-only key can never enumerate the
		// titles, slugs, URLs or excerpts of unpublished content — while still
		// getting useful public results instead of an error.
		$status_downgraded = false;
		if ( ( 'any' === $status || ! $this->is_public_status( $status ) ) && ! $this->requester_can_see_non_public() ) {
			if ( 'publish' !== $status ) {
				$status_downgraded = true;
			}
			$status = 'publish';
		}

		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$post_types = 'any' === $type ? array( 'post', 'page' ) : array( $type );

		$search_query = new WP_Query(
			array(
				'post_type'           => $post_types,
				'post_status'         => $status,
				's'                   => $query,
				// wp_search is the tool a caller reaches for to check whether
				// a slug is taken, so it needs slug matching more than the
				// listings do (#785).
				Mcpwp_Slug_Search::QUERY_VAR => Mcpwp_Slug_Search::should_match_slug( $query ),
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$items = array();
		foreach ( $search_query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->format_content_item( $post, false );
			}
		}

		$response = array(
			'query'      => $query,
			'type'       => $type,
			'status'     => $status,
			'items'      => $items,
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $search_query->found_posts,
				'total_pages' => (int) $search_query->max_num_pages,
			),
		);

		if ( $status_downgraded ) {
			$response['status_downgraded'] = true;
			$response['note']              = __( 'The requested status filter includes non-public content, which requires a key with write scope or higher. Results were limited to published content.', 'mcpwp' );
		}

		return $this->success_response( $response );
	}

	public function fetch_content( $request ) {
		$this->log_activity( 'fetch_content', $request );

		$id  = absint( $request->get_param( 'id' ) );
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );

		if ( 0 === $id && '' === $url ) {
			return $this->error_response(
				'missing_identifier',
				__( 'Provide either id or url to fetch content.', 'mcpwp' ),
				400
			);
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! in_array( $type, array( 'post', 'page', 'any' ), true ) ) {
			$type = 'any';
		}

		// This site's own REST URLs (#989): answered as a logged-out visitor.
		if ( 0 === $id && 'any' === $type && class_exists( 'Mcpwp_Site_Url_Resolver' ) && self::is_same_site_origin( $url ) ) {
			$rest = Mcpwp_Site_Url_Resolver::rest_route( $url );
			if ( null !== $rest ) {
				return $this->fetch_rest_url( $url, $rest['route'], $rest['query'] );
			}
		}

		$post = null;
		if ( $id > 0 ) {
			$post = get_post( $id );
		} elseif ( '' !== $url ) {
			$resolved_id = $this->resolve_content_id_from_url( $url, $type );
			if ( $resolved_id > 0 ) {
				$post = get_post( $resolved_id );
			}

			// This site's own archive URLs (#989): category, tag, custom
			// taxonomy, author, date, post type archive, search, blog index.
			// The posts page resolves to its (empty) page post above, so it
			// is answered as the blog index it actually renders.
			if ( 'any' === $type && class_exists( 'Mcpwp_Site_Url_Resolver' ) && self::is_same_site_origin( $url ) ) {
				$posts_page = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

				// Answered as the blog index only when the caller may read the
				// posts page itself; otherwise the guards below answer it, the
				// same way as any other page.
				if ( $post instanceof WP_Post && $posts_page > 0 && (int) $post->ID === $posts_page
					&& $this->requester_can_read_post( $post ) && null === $this->guard_post_type_category( $post, '' ) ) {
					$request_path = Mcpwp_Site_Url_Resolver::request_path( $url );
					$paged        = 1;
					if ( null !== $request_path && preg_match( '#/page/([0-9]+)$#', '/' . $request_path['path'], $m ) ) {
						$paged = max( 1, (int) $m[1] );
					}

					return $this->fetch_archive(
						$url,
						array(
							'type'  => 'home',
							'paged' => $paged,
						)
					);
				}

				if ( ! $post instanceof WP_Post ) {
					$parsed  = Mcpwp_Site_Url_Resolver::query_vars( $url );
					$archive = null === $parsed ? null : Mcpwp_Site_Url_Resolver::classify( $parsed['vars'], $parsed['path'] );

					if ( is_array( $archive ) && 'front_page' === $archive['type'] ) {
						$post = get_post( (int) $archive['post_id'] );
					} elseif ( is_array( $archive ) ) {
						return $this->fetch_archive( $url, $archive );
					}
				}
			}
		}

		if ( ! $post instanceof WP_Post ) {
			return $this->error_response(
				'not_found',
				__( 'Content not found.', 'mcpwp' ),
				404
			);
		}

		// A non-public post (draft, pending, private, etc.) may only be fetched
		// by a key with write scope or higher. For any other caller we return
		// the same 404 as a genuinely missing post, so the response never
		// discloses that the unpublished post exists. This one guard covers both
		// the numeric ?id vector and the ?url resolver vector, because both
		// resolve into $post above.
		if ( ! $this->requester_can_read_post( $post ) ) {
			return $this->error_response(
				'not_found',
				__( 'Content not found.', 'mcpwp' ),
				404
			);
		}

		// #1020: the resolved post's OWN category, not the route's — e.g. a
		// WooCommerce product or an Elementor archetype fetched by id through
		// this content-labeled route. Same not-found shape as every other
		// guard on this door, so category denial cannot be told apart from a
		// missing post.
		$category_denied = $this->guard_post_type_category( $post, __( 'Content not found.', 'mcpwp' ) );
		if ( $category_denied ) {
			return $category_denied;
		}

		if ( 'any' !== $type && $type !== $post->post_type ) {
			return $this->error_response(
				'not_found',
				__( 'Content not found for the requested type.', 'mcpwp' ),
				404
			);
		}

		$include_content = $request->get_param( 'include_content' );
		$include_content = null === $include_content ? true : (bool) $include_content;

		return $this->success_response(
			$this->format_content_item( $post, $include_content )
		);
	}

	/**
	 * Answer an archive URL of this site with the archive and one page of its
	 * posts (#989).
	 *
	 * Lists only published posts without a password, the same as a logged-out
	 * visitor sees, and leaves out any post this key's categories do not
	 * cover. Mcpwp_Site_Url_Resolver::listing_args() builds the query from the
	 * classified archive alone.
	 *
	 * @param string $url     Requested URL.
	 * @param array  $archive Mcpwp_Site_Url_Resolver::classify() result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function fetch_archive( $url, array $archive ) {
		$per_page = (int) get_option( 'posts_per_page', 10 );
		if ( $per_page < 1 ) {
			$per_page = 10;
		}

		$args  = Mcpwp_Site_Url_Resolver::listing_args( $archive, $per_page );
		$query = new WP_Query( $args );

		$items = array();
		foreach ( (array) $query->posts as $post ) {
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password
				|| ! Mcpwp_Site_Url_Resolver::is_viewable_post_type( $post->post_type ) ) {
				continue;
			}
			if ( ! $this->requester_can_read_post( $post ) || $this->guard_post_type_category( $post, '' ) ) {
				continue;
			}
			$items[] = $this->format_content_item( $post, false );
		}

		$response = array(
			'resource'     => 'archive',
			'archive_type' => $archive['type'],
			'url'          => $url,
			'title'        => '',
		);

		switch ( $archive['type'] ) {
			case 'category':
			case 'tag':
			case 'taxonomy':
				$term                 = $archive['term'];
				$response['title']    = isset( $term->name ) ? (string) $term->name : '';
				$response['term']     = array(
					'id'          => (int) $term->term_id,
					'name'        => isset( $term->name ) ? (string) $term->name : '',
					'slug'        => isset( $term->slug ) ? (string) $term->slug : '',
					'taxonomy'    => (string) $archive['taxonomy'],
					'description' => isset( $term->description ) ? (string) $term->description : '',
					'parent'      => isset( $term->parent ) ? (int) $term->parent : 0,
					'count'       => isset( $term->count ) ? (int) $term->count : null,
				);
				break;

			case 'author':
				// Only an author with published posts has a public archive;
				// anything else reads as not found, so the door cannot be
				// used to list user names by id.
				$user = (int) $query->found_posts > 0 ? get_userdata( (int) $archive['author_id'] ) : false;
				if ( ! is_object( $user ) ) {
					return $this->error_response( 'not_found', __( 'Content not found.', 'mcpwp' ), 404 );
				}
				$response['title']  = isset( $user->display_name ) ? (string) $user->display_name : '';
				$response['author'] = array(
					'id'           => (int) $user->ID,
					'display_name' => isset( $user->display_name ) ? (string) $user->display_name : '',
					'slug'         => isset( $user->user_nicename ) ? (string) $user->user_nicename : '',
				);
				break;

			case 'post_type_archive':
				$type_object           = get_post_type_object( $archive['post_type'] );
				$response['title']     = is_object( $type_object ) && isset( $type_object->labels->name ) ? (string) $type_object->labels->name : (string) $archive['post_type'];
				$response['post_type'] = (string) $archive['post_type'];
				break;

			case 'date':
				$response['date']  = $archive['date'];
				$response['title'] = implode( '-', array_map( 'strval', array_values( $archive['date'] ) ) );
				break;

			case 'search':
				$response['search'] = (string) $archive['search'];
				$response['title']  = sprintf(
					/* translators: %s: search terms */
					__( 'Search results for "%s"', 'mcpwp' ),
					(string) $archive['search']
				);
				break;

			default:
				$response['title'] = __( 'Latest posts', 'mcpwp' );

				// The posts page is named only to a caller who may read it.
				$posts_page_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
				$posts_page    = $posts_page_id > 0 ? get_post( $posts_page_id ) : null;
				if ( $posts_page instanceof WP_Post && $this->requester_can_read_post( $posts_page ) && null === $this->guard_post_type_category( $posts_page, '' ) ) {
					$response['posts_page_id'] = (int) $posts_page->ID;
					$response['title']         = get_the_title( $posts_page );
					$response['edit_url']      = admin_url( 'post.php?post=' . (int) $posts_page->ID . '&action=edit' );
				}
		}

		$response['items']      = $items;
		$response['pagination'] = array(
			'page'        => (int) $args['paged'],
			'per_page'    => (int) $args['posts_per_page'],
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
		$response['note']       = __( 'Archive of published posts, as a logged-out visitor sees it. Pass a post id or post URL to fetch one post with its content.', 'mcpwp' );

		return $this->success_response( $response );
	}

	/**
	 * Answer a REST URL of this site the way a logged-out visitor gets it
	 * (#989).
	 *
	 * The request is dispatched internally with no current user and outside
	 * any MCP/batch sub-request, so it gets exactly the public answer: this
	 * key's own privileges never apply to it. This plugin's own namespace is
	 * refused outright, because its routes authenticate by API key and are
	 * reached through their own tools.
	 *
	 * @param string $url   Requested URL.
	 * @param string $route REST route.
	 * @param array  $query Query args from the URL.
	 * @return WP_REST_Response|WP_Error
	 */
	private function fetch_rest_url( $url, $route, array $query ) {
		if ( preg_match( '#^/mcpwp(?:/|$)#i', (string) $route ) ) {
			return $this->error_response(
				'own_namespace',
				__( 'MCPWP\'s own REST routes need an API key, so wp_fetch does not request them as a visitor. Call the matching MCPWP tool instead.', 'mcpwp' ),
				400
			);
		}

		$category_denied = $this->rest_route_category_denial( (string) $route, $query );
		if ( $category_denied ) {
			return $category_denied;
		}

		foreach ( array( '_method', '_envelope', '_jsonp', 'rest_route' ) as $reserved ) {
			unset( $query[ $reserved ] );
		}

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $query );

		$previous_user = get_current_user_id();
		$batch_flag    = class_exists( 'Mcpwp_REST_Batch' ) ? Mcpwp_REST_Batch::$is_batch_sub_request : null;
		$user_switched = false;
		$visitor       = array(
			'server'   => array(),
			'globals'  => array(),
			'wp'       => null,
			'detached' => array(),
		);

		try {
			// Every state change happens inside the try, and each records
			// itself before it is made, so the finally restores exactly what
			// changed even when one of them throws part-way (a throwing
			// set_current_user callback, for one).
			$this->enter_visitor_request( $url, (string) $route, $visitor );

			if ( null !== $batch_flag ) {
				Mcpwp_REST_Batch::$is_batch_sub_request = false;
			}

			$user_switched = true;
			wp_set_current_user( 0 );

			// rest_do_request() skips WP_REST_Server::serve_request(), which is
			// where core applies rest_authentication_errors. A site that locks
			// anonymous REST access (or one route of it) does so there, so it
			// is applied here, as the visitor this request stands in for: no
			// user, and the request URI, method and rest_route of the TARGET
			// route (enter_visitor_request()), so a lock that decides by URL
			// judges this route, not the outer /mcp request.
			$auth_error = apply_filters( 'rest_authentication_errors', null );

			$response = is_wp_error( $auth_error ) ? $auth_error : rest_do_request( $request );
		} finally {
			// Callback-free restores first, so a throwing set_current_user
			// callback below cannot leave them changed.
			$this->leave_visitor_request( $visitor );
			if ( null !== $batch_flag ) {
				Mcpwp_REST_Batch::$is_batch_sub_request = $batch_flag;
			}
			if ( $user_switched ) {
				wp_set_current_user( $previous_user );
			}
		}

		if ( isset( $auth_error ) && is_wp_error( $auth_error ) ) {
			$error_data = $auth_error->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 401;

			return $this->error_response(
				'rest_visitor_refused',
				sprintf(
					/* translators: %s: the site's own refusal message */
					__( 'This site refuses this REST request from a logged-out visitor: %s', 'mcpwp' ),
					$auth_error->get_error_message()
				),
				$status >= 400 ? $status : 401
			);
		}

		$status = 200;
		$data   = null;
		if ( $response instanceof WP_REST_Response ) {
			$status = (int) $response->get_status();
			$server = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
			$data   = ( is_object( $server ) && method_exists( $server, 'response_to_data' ) )
				? $server->response_to_data( $response, false )
				: $response->get_data();
		} elseif ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
			$data       = array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			);
		}

		$encoded = wp_json_encode( $data );
		if ( is_string( $encoded ) && strlen( $encoded ) > self::RENDERED_HTML_MAX_BYTES_CEILING ) {
			return $this->error_response(
				'response_too_large',
				sprintf(
					/* translators: 1: response size in bytes, 2: limit in bytes */
					__( 'The REST response is %1$d bytes, over the %2$d byte limit. Narrow it with per_page or _fields in the URL.', 'mcpwp' ),
					strlen( $encoded ),
					self::RENDERED_HTML_MAX_BYTES_CEILING
				),
				413
			);
		}

		$result = array(
			'resource'    => 'rest',
			'url'         => $url,
			'route'       => $route,
			'status_code' => $status,
			'data'        => $data,
			'note'        => __( 'Requested internally as a logged-out visitor, so this is the public response, whatever this API key may do.', 'mcpwp' ),
		);

		if ( $response instanceof WP_REST_Response && method_exists( $response, 'get_headers' ) ) {
			$headers = (array) $response->get_headers();
			foreach ( array( 'X-WP-Total' => 'total', 'X-WP-TotalPages' => 'total_pages' ) as $header => $key ) {
				if ( isset( $headers[ $header ] ) ) {
					$result[ $key ] = (int) $headers[ $header ];
				}
			}
		}

		return $this->success_response( $result );
	}

	/**
	 * Make the request look like the visitor's request for $url while the
	 * REST authentication filters and the dispatch run.
	 *
	 * Sets REQUEST_URI (path and query of the target URL), REQUEST_METHOD
	 * (GET), $_GET and $_REQUEST (the target URL's query: a visitor's GET
	 * carries no body) and the global WP query var rest_route, and detaches
	 * core's two credential checks: rest_cookie_check_errors re-verifies the
	 * OUTER request's nonce against user 0 and always fails a browser
	 * session, and rest_application_password_check_errors answers true for an
	 * outer request that used an application password, which would end the
	 * filter chain before a site's own lock runs. Neither credential belongs
	 * to the visitor.
	 *
	 * Each change is recorded in $state BEFORE it is made, so
	 * leave_visitor_request() restores exactly what changed, even if this
	 * stops part-way.
	 *
	 * @param string $url   Target URL.
	 * @param string $route Target REST route.
	 * @param array  $state Saved state, filled in by reference.
	 */
	private function enter_visitor_request( $url, $route, array &$state ) {
		$parts = wp_parse_url( (string) $url );
		$uri   = ( is_array( $parts ) && isset( $parts['path'] ) ) ? (string) $parts['path'] : '/';
		$query = array();
		if ( is_array( $parts ) && isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$uri .= '?' . $parts['query'];
			parse_str( (string) $parts['query'], $query );
		}

		foreach ( array( 'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET' ) as $key => $value ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- saved verbatim, restored verbatim.
			$state['server'][ $key ] = array_key_exists( $key, $_SERVER ) ? array( $_SERVER[ $key ] ) : null;
			$_SERVER[ $key ]         = $value;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- saved and restored verbatim, never read here.
		$state['globals']['get']     = array( $_GET );
		$_GET                        = is_array( $query ) ? $query : array();
		$state['globals']['request'] = array( $_REQUEST );
		$_REQUEST                    = $_GET;
		// phpcs:enable

		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ) {
			$had_vars    = isset( $GLOBALS['wp']->query_vars ) && is_array( $GLOBALS['wp']->query_vars );
			$vars        = $had_vars ? $GLOBALS['wp']->query_vars : array();
			$state['wp'] = array(
				'had_vars' => $had_vars,
				'vars'     => $vars,
			);

			$vars['rest_route']        = (string) $route;
			$GLOBALS['wp']->query_vars = $vars;
		}

		// Detaching and re-adding a callback moves it to the END of its
		// priority slot: after leave_visitor_request(), another callback at
		// priority 90 or 100 that used to run after core's check now runs
		// before it. Both core checks pass an earlier error through
		// unchanged, so only a same-priority callback that depends on running
		// after them can see a difference.
		foreach ( array( 'rest_cookie_check_errors', 'rest_application_password_check_errors' ) as $callback ) {
			$priority = has_filter( 'rest_authentication_errors', $callback );
			if ( false !== $priority ) {
				$state['detached'][ $callback ] = (int) $priority;
				remove_filter( 'rest_authentication_errors', $callback, (int) $priority );
			}
		}
	}

	/**
	 * Undo enter_visitor_request(), for whatever part of it ran.
	 *
	 * @param array $state Saved state.
	 */
	private function leave_visitor_request( array $state ) {
		foreach ( $state['server'] as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value[0];
			}
		}

		if ( isset( $state['globals']['get'] ) ) {
			$_GET = $state['globals']['get'][0];
		}
		if ( isset( $state['globals']['request'] ) ) {
			$_REQUEST = $state['globals']['request'][0];
		}

		if ( is_array( $state['wp'] ) && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ) {
			if ( $state['wp']['had_vars'] ) {
				$GLOBALS['wp']->query_vars = $state['wp']['vars'];
			} else {
				unset( $GLOBALS['wp']->query_vars );
			}
		}

		foreach ( $state['detached'] as $callback => $priority ) {
			if ( false === has_filter( 'rest_authentication_errors', $callback ) ) {
				add_filter( 'rest_authentication_errors', $callback, $priority );
			}
		}
	}

	/**
	 * Refuse a REST route that returns something this key's categories do
	 * not cover (#1020 rule, applied to the REST door of wp_fetch).
	 *
	 * @param string $route REST route.
	 * @param array  $query Query args of the request.
	 * @return WP_Error|null
	 */
	private function rest_route_category_denial( $route, array $query = array() ) {
		foreach ( $this->categories_for_rest_route( $route, $query ) as $category ) {
			if ( $this->caller_may_read_category( $category ) ) {
				continue;
			}

			return new WP_Error(
				'forbidden_category',
				sprintf(
					/* translators: 1: REST route, 2: tool category */
					__( 'The REST route %1$s returns data in the "%2$s" tool category, which this API key does not have access to, or which has been disabled by the site administrator.', 'mcpwp' ),
					(string) $route,
					$category
				),
				array(
					'status'   => 403,
					'category' => $category,
				)
			);
		}

		return null;
	}

	/**
	 * Tool categories a REST route's response belongs to. Fails closed: a
	 * route this map does not know needs the `admin` category.
	 *
	 * - `/` and the `wp/v2` index list routes only: `content`.
	 * - `wp/v2`: a post type or taxonomy route by its post type; `search`,
	 *   and `comments` without `post=`, span every public type;
	 *   `comments?post=` by that post's type; `users` is `admin`, the same
	 *   category MCPWP's own /users needs; `types`, `taxonomies` and
	 *   `statuses` are `content`; any other `wp/v2` route is `admin`.
	 * - `oembed/1.0/embed?url=`: by the type of the post it embeds; `admin`
	 *   when the URL does not resolve to a post on this site.
	 * - `wc/*`, `wc-*`: WooCommerce. `elementor*`: Elementor templates.
	 * - A post type or taxonomy under its own rest_namespace: its post type.
	 * - Any other namespace: `admin`.
	 *
	 * @param string $route REST route.
	 * @param array  $query Query args of the request.
	 * @return string[]
	 */
	private function categories_for_rest_route( $route, array $query = array() ) {
		$route    = strtolower( '/' . trim( (string) $route, '/' ) );
		$segments = array_values( array_filter( explode( '/', trim( $route, '/' ) ), 'strlen' ) );

		$for_types = static function ( array $types ) {
			$categories = array();
			foreach ( $types as $type ) {
				$categories[] = self::category_for_post_type( (string) $type );
			}

			return array_values( array_unique( $categories ) );
		};

		$public_types = static function () {
			$types = array();
			foreach ( (array) get_post_types( array( 'public' => true ) ) as $type ) {
				$types[] = is_object( $type ) ? (string) $type->name : (string) $type;
			}

			return $types;
		};

		if ( empty( $segments ) ) {
			return array( 'content' );
		}

		if ( preg_match( '#^/wc(?:/|-|$)#', $route ) ) {
			return $for_types( array( 'product' ) );
		}

		if ( preg_match( '#^/elementor(?:-pro)?(?:/|$)#', $route ) ) {
			return $for_types( array( 'elementor_library' ) );
		}

		$namespace = isset( $segments[1] ) ? $segments[0] . '/' . $segments[1] : $segments[0];
		$base      = isset( $segments[2] ) ? $segments[2] : '';

		if ( 'oembed/1.0' === $namespace ) {
			if ( 'embed' !== $base ) {
				return array( 'admin' );
			}
			$target = ( isset( $query['url'] ) && is_string( $query['url'] ) && function_exists( 'url_to_postid' ) )
				? get_post( absint( url_to_postid( $query['url'] ) ) )
				: null;

			// An embed URL that does not resolve to a post here may still be
			// answered (an oembed_request_post_id filter, another site of a
			// network), so what it returns is unknown: admin.
			return $target instanceof WP_Post ? $for_types( array( $target->post_type ) ) : array( 'admin' );
		}

		if ( 'wp/v2' === $namespace ) {
			switch ( $base ) {
				case '':
				case 'types':
				case 'taxonomies':
				case 'statuses':
					return array( 'content' );
				case 'users':
					return array( 'admin' );
				case 'search':
					return $for_types( $public_types() );
				case 'comments':
					$raw      = isset( $query['post'] ) ? $query['post'] : array();
					$post_ids = array_filter( array_map( 'absint', is_array( $raw ) ? $raw : preg_split( '/[,\s]+/', (string) $raw ) ) );
					if ( empty( $post_ids ) ) {
						return $for_types( $public_types() );
					}
					$types = array();
					foreach ( $post_ids as $post_id ) {
						$post    = get_post( $post_id );
						$types[] = $post instanceof WP_Post ? $post->post_type : 'post';
					}

					return $for_types( $types );
			}
		}

		$types = $this->post_types_for_rest_route( $route );
		if ( ! empty( $types ) ) {
			return $for_types( $types );
		}

		return array( 'admin' );
	}

	/**
	 * Post types whose posts a REST route returns, matched by each post
	 * type's and taxonomy's own rest_namespace/rest_base.
	 *
	 * @param string $route REST route.
	 * @return string[]
	 */
	private function post_types_for_rest_route( $route ) {
		$route = strtolower( '/' . trim( (string) $route, '/' ) );
		$types = array();

		$core_bases = array(
			'post'       => 'posts',
			'page'       => 'pages',
			'attachment' => 'media',
		);

		$matches = static function ( $namespace, $base ) use ( $route ) {
			$prefix = strtolower( '/' . trim( (string) $namespace, '/' ) . '/' . trim( (string) $base, '/' ) );

			return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
		};

		foreach ( (array) get_post_types( array(), 'objects' ) as $type ) {
			$object = is_object( $type ) ? $type : get_post_type_object( (string) $type );
			if ( ! is_object( $object ) || empty( $object->name ) ) {
				continue;
			}
			$name      = (string) $object->name;
			$namespace = ! empty( $object->rest_namespace ) ? $object->rest_namespace : 'wp/v2';
			$base      = ! empty( $object->rest_base ) ? $object->rest_base : ( $core_bases[ $name ] ?? $name );
			if ( $matches( $namespace, $base ) ) {
				$types[] = $name;
			}
		}

		if ( function_exists( 'get_taxonomies' ) ) {
			foreach ( (array) get_taxonomies( array(), 'objects' ) as $taxonomy ) {
				if ( ! is_object( $taxonomy ) || empty( $taxonomy->name ) ) {
					continue;
				}
				$namespace = ! empty( $taxonomy->rest_namespace ) ? $taxonomy->rest_namespace : 'wp/v2';
				$base      = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : (string) $taxonomy->name;
				if ( 'category' === $taxonomy->name && empty( $taxonomy->rest_base ) ) {
					$base = 'categories';
				} elseif ( 'post_tag' === $taxonomy->name && empty( $taxonomy->rest_base ) ) {
					$base = 'tags';
				}
				if ( $matches( $namespace, $base ) ) {
					foreach ( (array) ( $taxonomy->object_type ?? array() ) as $object_type ) {
						$types[] = (string) $object_type;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $types ) ) );
	}

	private function format_content_item( $post, $include_content ) {
		$excerpt = (string) $post->post_excerpt;
		if ( '' === trim( $excerpt ) ) {
			$excerpt = function_exists( 'wp_trim_words' )
				? wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40, '...' )
				: '';
		}

		$item = array(
			'id'           => (int) $post->ID,
			'type'         => (string) $post->post_type,
			'status'       => (string) $post->post_status,
			'slug'         => (string) $post->post_name,
			'title'        => get_the_title( $post ),
			'url'          => (string) get_permalink( $post ),
			'excerpt'      => $excerpt,
			'date_gmt'     => (string) $post->post_date_gmt,
			'modified_gmt' => (string) $post->post_modified_gmt,
		);

		if ( $include_content ) {
			$raw_content     = (string) $post->post_content;
			$item['content'] = array(
				'raw'      => $raw_content,
				'rendered' => apply_filters( 'the_content', $raw_content ),
			);

			// Flag Elementor pages so callers know to use wp_get_elementor.
			$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
			if ( ! empty( $elementor_data ) ) {
				$item['elementor'] = true;
				if ( '' === trim( $raw_content ) ) {
					$item['content']['note'] = 'This page is built with Elementor. Use wp_get_elementor to retrieve the layout data.';
				}
			}
		}

		return $item;
	}

	private function resolve_content_id_from_url( $url, $type ) {
		$post_id = function_exists( 'url_to_postid' ) ? absint( url_to_postid( $url ) ) : 0;
		if ( $post_id > 0 ) {
			return $post_id;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === trim( $path ) ) {
			return 0;
		}

		$path = trim( $path, '/' );
		if ( '' === $path || ! function_exists( 'get_page_by_path' ) ) {
			return 0;
		}

		$post_types = 'any' === $type ? array( 'post', 'page' ) : array( $type );

		// First try published posts (default behavior).
		$post = get_page_by_path( $path, OBJECT, $post_types );
		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}

		// A URL only resolves to a non-public post (draft, pending, private) for
		// a key with write scope or higher. For everyone else we stop here, so an
		// unpublished slug resolves to nothing — the same "not found" outcome as a
		// slug that does not exist. fetch_content() enforces the same rule as the
		// authoritative check (and also covers the numeric ?id vector); this is
		// defense in depth.
		if ( ! $this->requester_can_see_non_public() ) {
			return 0;
		}

		$non_public_statuses = array( 'private', 'draft', 'pending' );
		foreach ( $non_public_statuses as $status ) {
			$found = get_posts(
				array(
					'name'           => basename( $path ),
					'post_type'      => $post_types,
					'post_status'    => $status,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( ! empty( $found ) ) {
				return (int) $found[0];
			}
		}

		return 0;
	}

	public function get_post_meta_handler( $request ) {
		$this->log_activity( 'get_post_meta', $request );

		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		// #1030 round-3 review, item 2: 'id' is a declared post-ref param for
		// /post-meta (Mcpwp_Api_Auth::get_post_ref_param_map()), so the
		// chokepoint already refuses a missing OR category-gated id with
		// post_ref_not_found_error() before this handler runs. The three
		// checks below stay as defense-in-depth for a direct handler call
		// that bypasses check_permission(), now sharing that SAME error so
		// missing/non-public/category-denied cannot drift apart from each
		// other or from the guard.
		if ( ! $post instanceof WP_Post ) {
			return $this->error_response_from_wp_error( $this->post_ref_not_found_error( $request ) );
		}

		// Custom-field meta on a non-public post (draft/pending/private) can hold
		// unpublished data (pricing, PII, ACF fields). Restrict it to write scope
		// or higher, matching the fetch/search/render gates; 404 for anyone else
		// so the hidden post is not disclosed.
		if ( ! $this->requester_can_read_post( $post ) ) {
			return $this->error_response_from_wp_error( $this->post_ref_not_found_error( $request ) );
		}

		// #1020: this route classifies as 'content' by PREFIX, but the post
		// it resolved may not be — a WooCommerce product's _sku, an
		// Elementor archetype's _mcpwp_archetype_brief. Gate on what the id
		// actually resolved to, not on what /post-meta looks like.
		$category_denied = $this->guard_post_type_category( $post, __( 'The requested resource was not found.', 'mcpwp' ) );
		if ( $category_denied ) {
			return $category_denied;
		}

		$key = $request->get_param( 'key' );

		if ( ! empty( $key ) ) {
			$key = sanitize_key( $key );

			if ( $this->is_blocked_meta_key( $key ) ) {
				return $this->error_response( 'forbidden_meta_key', __( 'This meta key is not accessible via API.', 'mcpwp' ), 403 );
			}

			$value = $this->mask_credential_value( get_post_meta( $post_id, $key, true ) );

			return $this->success_response(
				array(
					'id'    => $post_id,
					'key'   => $key,
					'value' => $value,
				)
			);
		}

		// Return all non-blocked meta
		$all_meta  = get_post_meta( $post_id );
		$safe_meta = array();

		foreach ( $all_meta as $meta_key => $meta_values ) {
			if ( ! $this->is_blocked_meta_key( $meta_key ) ) {
				$safe_meta[ $meta_key ] = $this->mask_credential_value( count( $meta_values ) === 1 ? $meta_values[0] : $meta_values );
			}
		}

		return $this->success_response(
			array(
				'id'   => $post_id,
				'meta' => $safe_meta,
			)
		);
	}

	public function set_post_meta_handler( $request ) {
		$this->log_activity( 'set_post_meta', $request );

		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		// See get_post_meta_handler()'s matching comment: the chokepoint
		// already covers this in production; kept for direct-call defense
		// in depth, sharing the same error.
		if ( ! $post instanceof WP_Post ) {
			return $this->error_response_from_wp_error( $this->post_ref_not_found_error( $request ) );
		}

		// #1020: same category guard as the read side above — a write-scope
		// key with only 'content' must not be able to set a WooCommerce
		// product's or an Elementor template's meta through this route.
		$category_denied = $this->guard_post_type_category( $post, __( 'The requested resource was not found.', 'mcpwp' ) );
		if ( $category_denied ) {
			return $category_denied;
		}

		$raw_key = trim( (string) $request->get_param( 'key' ) );
		$key     = Mcpwp_Post_Fields::sanitize_meta_key( $raw_key );
		$value   = $request->get_param( 'value' );

		if ( '' === $key ) {
			return $this->error_response( 'missing_key', __( 'Meta key is required.', 'mcpwp' ), 400 );
		}

		// A key that does not survive the charset is refused, not rewritten
		// onto a different key the caller never named.
		if ( $key !== $raw_key ) {
			return $this->error_response(
				'invalid_meta_key',
				sprintf(
					/* translators: %s: key */
					__( 'Meta key "%s" contains characters outside [A-Za-z0-9_-.:]. Nothing was written.', 'mcpwp' ),
					$raw_key
				),
				400
			);
		}

		// An id-meta's value is canonicalised ONCE: the ids the authorization
		// guard checked and the value stored below are the same list, for every
		// principal (#1121). Not an id-meta: the generic decoder, as before.
		$canonical = self::canonicalize_post_meta_value( $key, $value );
		if ( is_wp_error( $canonical ) ) {
			return $this->error_response_from_wp_error( $canonical );
		}
		if ( is_array( $canonical ) ) {
			$value = $canonical['value'];
		}

		// The featured image goes through the same check as the dedicated
		// route: it has to be an attachment.
		// Compared lowercase: meta_key collates case-insensitively, so
		// '_THUMBNAIL_ID' is the same row (#1121).
		if ( '_thumbnail_id' === strtolower( $key ) && ! empty( $value ) ) {
			$attachment = get_post( absint( $value ) );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return $this->error_response( 'invalid_media', __( '_thumbnail_id must be the ID of a media attachment.', 'mcpwp' ), 400 );
			}
		}

		// Elementor keys have their own tools; say which.
		if ( '_elementor_data' === $key ) {
			return $this->error_response( 'use_elementor_endpoint', __( 'Use wp_set_elementor (POST /elementor/{id}) to set Elementor page data.', 'mcpwp' ), 400 );
		}
		if ( '_elementor_page_settings' === $key ) {
			return $this->error_response( 'use_elementor_endpoint', __( 'Use wp_set_elementor with the page_settings parameter to update Elementor page settings.', 'mcpwp' ), 400 );
		}
		if ( 0 === strpos( $key, '_elementor' ) ) {
			return $this->error_response( 'use_elementor_endpoint', __( 'Elementor meta keys cannot be set via wp_set_post_meta. Use the /elementor/{id} endpoints instead.', 'mcpwp' ), 400 );
		}

		// A JSON object/array sent as a string is meant as a structure.
		// The same decoder the authorization guard reads the value with
		// (decode_post_meta_value(), #1121).
		if ( ! is_array( $canonical ) ) {
			$value = self::decode_post_meta_value( $value );
		}

		// The same policy as meta on a post write (#797): one answer to "can
		// this key be written, and how is the value treated". The two core
		// keys this route has always accepted stay accepted here.
		$clean = Mcpwp_Post_Fields::validate_meta( array( $key => $value ), true, (string) $post->post_type );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// #1108: the shared user-run meta authorization (WordPress's
		// edit_post_meta), the same one every post/page write door applies.
		$refused = Mcpwp_Post_Fields::meta_write_refusal( $post_id, $clean, (string) $post->post_type );
		if ( is_wp_error( $refused ) ) {
			return $this->error_response_from_wp_error( $refused );
		}

		$deleted = array();
		$written = Mcpwp_Post_Fields::apply_meta( $post_id, $post->post_type, $clean, $deleted );
		$stored  = get_post_meta( $post_id, $key, true );

		return $this->success_response(
			array(
				'id'      => $post_id,
				'key'     => $key,
				'value'   => $stored,
				'updated' => in_array( $key, $written, true ),
				'deleted' => in_array( $key, $deleted, true ),
			)
		);
	}

	private function is_blocked_meta_key( $meta_key ) {
		$blocked_prefixes = array( '_wp_', 'mcpwp_api_key', '_edit_lock', '_edit_last', '_elementor_' );
		$blocked_keys     = array( '_wp_page_template', '_thumbnail_id', '_elementor_data', '_elementor_page_settings', '_elementor_css', '_elementor_edit_mode' );

		// Allow these specific WordPress keys (non-Elementor).
		$allowed_keys = array( '_wp_page_template', '_thumbnail_id' );

		if ( in_array( $meta_key, $allowed_keys, true ) ) {
			return false;
		}

		if ( in_array( $meta_key, $blocked_keys, true ) ) {
			return true;
		}

		// Case-insensitive, like the write side: wp_postmeta.meta_key
		// collates case-insensitively, so _WP_ATTACHED_FILE is _wp_attached_file.
		$lower = strtolower( (string) $meta_key );
		foreach ( $blocked_prefixes as $prefix ) {
			if ( 0 === strpos( $lower, strtolower( $prefix ) ) ) {
				return true;
			}
		}
		foreach ( array( '_menu_item_', '_transient', '_site_transient', '_customize_changeset', '_pingme', '_encloseme', '_downloadable_files' ) as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return true;
			}
		}

		// Secret-looking keys: the shared detector, OR the substring set the
		// read side has always filtered — the read side is never looser
		// than it was (#797 rounds 4–5). author* is the one deliberate
		// widening: it was a false positive of the old regex.
		if ( Mcpwp_Post_Fields::is_secret_shaped_key( $meta_key ) || preg_match( '/(password|secret|token|credential|auth(?!or))/i', $meta_key ) ) {
			return true;
		}

		// Meta-specific names that carry a bearer on read even though they
		// are ordinary enough to write (#797 follow-up): a Slack/Discord
		// webhook URL is the credential, so is a PayPal signature.
		if ( preg_match( '/(^|_)(webhook_?url|api_?signature|serial(_?number)?|pin_?code|(access|security|user)_?pin|licen[cs]e)(_|$)/i', str_replace( array( '-', '.', ':' ), '_', strtolower( (string) $meta_key ) ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * A meta VALUE that is a credential by shape is masked on read whatever
	 * its key is called (#797 follow-up) — the same detector the option
	 * surface uses.
	 *
	 * @param mixed $value Meta value.
	 * @return mixed
	 */
	private function mask_credential_value( $value ) {
		if ( is_string( $value ) && class_exists( 'Mcpwp_Option_Access' ) && Mcpwp_Option_Access::looks_like_credential( $value ) ) {
			return '***';
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->mask_credential_value( $v );
			}
		}
		return $value;
	}

	/**
	 * scheme://host[:port] of a URL, lower-cased with the default port
	 * dropped; '' when it is not an absolute http(s) URL or carries
	 * credentials.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin_of_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$default = 'https' === $scheme ? 443 : 80;
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ( $port !== $default ? ':' . $port : '' );
	}

	/**
	 * Whether a URL is on this site's own origin (home_url() or site_url()),
	 * and, for a site installed in a subdirectory, under that directory.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_same_site_origin( $url ) {
		$origin = self::origin_of_url( $url );
		if ( '' === $origin ) {
			return false;
		}
		$path = (string) wp_parse_url( trim( (string) $url ), PHP_URL_PATH );
		$path = trim( rawurldecode( $path ), '/' );

		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $own ) {
			if ( $origin !== self::origin_of_url( $own ) ) {
				continue;
			}

			// A site installed in a subdirectory (a subdirectory multisite
			// subsite, or WordPress in /blog/) owns only the paths under
			// that directory; the rest of the origin belongs to other sites.
			$own_path = trim( (string) wp_parse_url( $own, PHP_URL_PATH ), '/' );
			if ( '' === $own_path ) {
				return true;
			}

			if ( in_array( '..', explode( '/', $path ), true ) ) {
				continue;
			}

			if ( $path === $own_path || 0 === strpos( $path . '/', $own_path . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	public function get_rendered_html( $request ) {
		$this->log_activity( 'get_rendered_html', $request );

		// #804 NIT-1: absint() on a negative value silently flips its sign —
		// a caller who sent offset=-5000 meaning "from the end" would get
		// offset=5000 instead, a different window with no error to explain
		// why. Refuse a negative value outright rather than reinterpreting it.
		foreach ( array( 'max_bytes', 'offset' ) as $param ) {
			$rejection = $this->reject_if_negative( $request, $param );
			if ( null !== $rejection ) {
				return $rejection;
			}
		}

		$post_id   = $request->get_param( 'id' );
		$url       = $request->get_param( 'url' );
		$post      = null;
		$selector  = $request->get_param( 'selector' );
		$max_bytes = min(
			absint( $request->get_param( 'max_bytes' ) ?: self::RENDERED_HTML_DEFAULT_MAX_BYTES ),
			self::RENDERED_HTML_MAX_BYTES_CEILING
		);
		$offset    = absint( $request->get_param( 'offset' ) ?: 0 );

		// Resolve URL from post ID.
		if ( $post_id ) {
			$post = get_post( absint( $post_id ) );
			if ( ! $post ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
			}
			// A non-public post (draft, pending, private, etc.) may only be
			// rendered for a key with write scope or higher. The in-process
			// Elementor render below reaches unpublished content directly (no
			// loopback that would otherwise fail without an auth cookie), so
			// without this gate a read-only key could read the full body of an
			// unpublished page. Return the same 404 as a missing post so the
			// response never discloses that the hidden post exists.
			if ( ! $this->requester_can_read_post( $post ) ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
			}
			// #1030 round-3 review, test_every_id_shaped_route_is_declared_
			// or_explicitly_excluded: 'id' resolves ANY post type with no
			// category check at all — a content-only key could render a
			// WooCommerce product's or Elementor template's rendered HTML.
			// 'id' is now a declared post-ref param for /rendered-html
			// (Mcpwp_Api_Auth::get_post_ref_param_map()), so the chokepoint
			// already refuses a gated id before this handler runs; this stays
			// as defense-in-depth for a direct handler call.
			$category_denied = $this->guard_post_type_category( $post, __( 'The requested resource was not found.', 'mcpwp' ) );
			if ( $category_denied ) {
				return $category_denied;
			}
			if ( 'publish' === $post->post_status ) {
				$url = get_permalink( $post_id );
			} elseif ( class_exists( '\Elementor\Plugin' ) && get_post_meta( $post_id, '_elementor_data', true ) ) {
				// Draft/pending/private Elementor content can't reliably be reached
				// by an outbound loopback fetch: WP's own preview link needs a
				// logged-in session wp_remote_get() doesn't carry, and hosting-level
				// gates (e.g. HostGator's "Coming Soon" staging lock, #613) intercept
				// it before WordPress even runs. Render in-process instead — no
				// network hop, so neither problem applies. Note: this returns the
				// Elementor content only, not the surrounding theme header/footer.
				return $this->get_rendered_html_via_elementor( $post_id, $selector, $max_bytes, $offset, '', $post );
			} else {
				// Draft/pending — use Elementor preview URL or plain preview.
				$url = add_query_arg( 'elementor-preview', $post_id, get_permalink( $post_id ) );
			}
		}

		if ( empty( $url ) ) {
			return $this->error_response( 'missing_param', __( 'Either id or url is required.', 'mcpwp' ), 400 );
		}

		// SSRF guard: only this site's own origin — scheme, host AND port.
		// Comparing the host alone let http://<site-host>:3306/ (or any other
		// port on the same host) through: a port oracle into the server's
		// own services (#981 review WARN-3). Credentials in the URL are
		// refused outright.
		if ( ! self::is_same_site_origin( (string) $url ) ) {
			return $this->error_response(
				'ssrf_blocked',
				sprintf(
					/* translators: %s: allowed origin */
					__( 'Only this site\'s own URLs are allowed (same scheme, host and port as %s). Pass id to render a post instead.', 'mcpwp' ),
					self::origin_of_url( home_url( '/' ) )
				),
				403
			);
		}

		// Fetch the page. redirection=0 prevents a redirect to a private/loopback IP
		// from bypassing the same-host SSRF guard we checked above.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'sslverify'   => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_response(
				'fetch_failed',
				$response->get_error_message(),
				502
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$html        = wp_remote_retrieve_body( $response );

		// Read from the whole document, before any selector narrows it: the
		// templates a page used are only visible in its full markup (#989).
		$effective_template = $this->effective_template_report(
			$post instanceof WP_Post ? $post : $this->readable_post_for_url( (string) $url ),
			class_exists( 'Mcpwp_Effective_Template' ) ? Mcpwp_Effective_Template::rendered_from_html( (string) $html ) : null,
			'The response is not a full HTML document, so the templates it rendered could not be read.'
		);

		$selector_used  = null;
		$selector_found = true;

		// Extract by CSS selector if provided.
		if ( $selector && $html ) {
			$extracted = $this->extract_html_by_selector( $html, $selector );
			if ( null !== $extracted ) {
				$html           = $extracted;
				$selector_used  = $selector;
			} else {
				$selector_found = false;
				$selector_used  = $selector;
			}
		}

		// Window the (possibly selector-extracted) HTML honestly: never a
		// silent cut — the response always carries the document's real
		// total_bytes and, when this window isn't the whole thing, exactly
		// how to page for the rest (#804).
		$window = $this->window_html_response( $html, $offset, $max_bytes );

		return $this->success_response(
			array(
				'url'            => $url,
				'status_code'    => $status_code,
				'html'           => $window['html'],
				'length'         => $window['length'],
				'total_bytes'    => $window['total_bytes'],
				'offset'         => $window['offset'],
				'truncated'      => $window['truncated'],
				'next_offset'    => $window['next_offset'],
				'note'           => $window['note'],
				'selector_used'      => $selector_used,
				'selector_found'     => $selector_found,
				'effective_template' => $effective_template,
			)
		);
	}

	/**
	 * The post a same-site URL points at, when this caller may read it.
	 *
	 * @param string $url URL.
	 * @return WP_Post|null
	 */
	private function readable_post_for_url( $url ) {
		if ( '' === $url || ! function_exists( 'url_to_postid' ) ) {
			return null;
		}

		$post = get_post( absint( url_to_postid( $url ) ) );
		if ( ! $post instanceof WP_Post || ! $this->requester_can_read_post( $post ) ) {
			return null;
		}

		if ( $this->guard_post_type_category( $post, '' ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * The effective-template block of a rendered-HTML response (#989).
	 *
	 * `assigned` is what the post is set to use (page template, and the Theme
	 * Builder templates Elementor Pro's conditions pick for it). `rendered` is
	 * what the fetched document shows. `warnings` names every disagreement,
	 * such as an assigned header template that did not render.
	 *
	 * Theme Builder template ids this caller cannot read are left out, the
	 * same rule every other post reference follows.
	 *
	 * @param WP_Post|null $post          Post the page renders, when known.
	 * @param array|null   $rendered      Mcpwp_Effective_Template::rendered_from_html() result.
	 * @param string       $rendered_note Why $rendered is null, when it is.
	 * @return array|null Null when the reporter is not loaded.
	 */
	private function effective_template_report( $post, $rendered, $rendered_note ) {
		if ( ! class_exists( 'Mcpwp_Effective_Template' ) ) {
			return null;
		}

		$assigned = $post instanceof WP_Post ? Mcpwp_Effective_Template::assigned_for_post( $post ) : null;

		if ( is_array( $assigned ) && ! empty( $assigned['theme_builder']['locations'] ) ) {
			foreach ( $assigned['theme_builder']['locations'] as $location => $ids ) {
				$visible = array_values(
					array_filter(
						(array) $ids,
						function ( $id ) {
							return $this->post_ref_readable( (int) $id, 'elementor-theme' );
						}
					)
				);
				if ( empty( $visible ) ) {
					unset( $assigned['theme_builder']['locations'][ $location ] );
				} else {
					$assigned['theme_builder']['locations'][ $location ] = $visible;
				}
			}
		}

		$warnings = is_array( $rendered ) && ! empty( $rendered['warnings'] ) ? $rendered['warnings'] : array();
		$warnings = array_merge( $warnings, Mcpwp_Effective_Template::compare( $assigned, $rendered ) );

		$report = array(
			'assigned' => $assigned,
			'rendered' => $rendered,
			'warnings' => array_values( array_unique( $warnings ) ),
		);

		if ( null === $rendered ) {
			$report['rendered_note'] = $rendered_note;
		}

		return $report;
	}

	/**
	 * A 400 refusal when a numeric request param was sent negative, or null
	 * when the param is absent or non-negative (#804 NIT-1).
	 *
	 * absint() silently flips a negative value's sign, which would turn a
	 * caller's mistake (or an attempt to use a Python-style "offset from the
	 * end") into a different, unremarked window rather than an error they can
	 * see and fix.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name to check.
	 * @return WP_Error|null
	 */
	private function reject_if_negative( $request, $param ) {
		$value = $request->get_param( $param );

		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_numeric( $value ) && (float) $value < 0 ) {
			return $this->error_response(
				'invalid_param',
				sprintf(
					/* translators: %s: request parameter name */
					__( '%s must not be negative.', 'mcpwp' ),
					$param
				),
				400
			);
		}

		return null;
	}

	/**
	 * A UTF-8 character is at most 4 bytes: a lead byte plus up to this many
	 * continuation bytes. Bounds how far either snap below may walk.
	 */
	const UTF8_MAX_CONTINUATION_BYTES = 3;

	/**
	 * The nearest whole-UTF-8-character byte position at or before $pos —
	 * bounded to at most UTF8_MAX_CONTINUATION_BYTES steps back.
	 *
	 * A UTF-8 continuation byte always has its top two bits set to 10
	 * (0x80-0xBF); backing off while sat on one lands on the lead byte (or a
	 * plain single-byte/ASCII byte) that starts the character — never
	 * mid-character (#804 W4). 0 and strlen($html) are always boundaries and
	 * returned unchanged.
	 *
	 * #804 W-R2-1: the walk is capped at 3 steps. Invalid UTF-8 (or binary
	 * content) can run continuation bytes far longer than any real
	 * character — an uncapped walk over, say, 2 MB of stray 0x80 bytes
	 * would back off (or, via the forward fallback below, walk forward)
	 * across the ENTIRE run, returning a window many times larger than
	 * max_bytes and the documented ceiling. Past the cap, this gives up on
	 * finding a boundary nearby and returns the raw position unchanged —
	 * still capped in width by the ceiling, at the cost of a possible
	 * (rare, and only on invalid input) split character at that one edge.
	 *
	 * @param string $html Byte string.
	 * @param int    $pos  Candidate byte position.
	 * @return int
	 */
	private function snap_utf8_boundary_backward( $html, $pos ) {
		$len = strlen( $html );
		$pos = max( 0, min( $pos, $len ) );

		if ( 0 === $pos || $len === $pos ) {
			return $pos;
		}

		$original = $pos;
		$steps    = 0;

		while ( $pos > 0 && $steps < self::UTF8_MAX_CONTINUATION_BYTES && 0x80 === ( ord( $html[ $pos ] ) & 0xC0 ) ) {
			--$pos;
			++$steps;
		}

		if ( 0x80 === ( ord( $html[ $pos ] ) & 0xC0 ) ) {
			// Still on a continuation byte after the cap: not valid UTF-8
			// (or a boundary genuinely isn't nearby). Give up rather than
			// keep walking, and use the position as asked.
			return $original;
		}

		return $pos;
	}

	/**
	 * The nearest whole-UTF-8-character byte position at or after $pos —
	 * the mirror of snap_utf8_boundary_backward(), used only as a fallback
	 * to guarantee paging progress (see window_html_response()). Bounded
	 * the same way and for the same reason (#804 W-R2-1).
	 *
	 * @param string $html Byte string.
	 * @param int    $pos  Candidate byte position.
	 * @return int
	 */
	private function snap_utf8_boundary_forward( $html, $pos ) {
		$len = strlen( $html );
		$pos = max( 0, min( $pos, $len ) );

		if ( 0 === $pos || $len === $pos ) {
			return $pos;
		}

		$original = $pos;
		$steps    = 0;

		while ( $pos < $len && $steps < self::UTF8_MAX_CONTINUATION_BYTES && 0x80 === ( ord( $html[ $pos ] ) & 0xC0 ) ) {
			++$pos;
			++$steps;
		}

		if ( $pos < $len && 0x80 === ( ord( $html[ $pos ] ) & 0xC0 ) ) {
			return $original;
		}

		return $pos;
	}

	/**
	 * Slice a string of (possibly already selector-extracted) HTML into an
	 * honest, pageable window.
	 *
	 * The old behavior silently cut the document at max_bytes with no way to
	 * learn how big it really was or how to see the rest — on any page over
	 * ~51 KB that reliably lost the tail (the theme footer, Custom Code
	 * snippets, closing markup) while never saying so (#804). This always
	 * reports total_bytes and, whenever the window doesn't cover the whole
	 * document, a next_offset/note that gets the caller to the missing part,
	 * including the tail, by paging forward.
	 *
	 * Both edges are snapped to a whole UTF-8 character boundary (#804 W4):
	 * a raw byte offset/max_bytes can land mid-character in multi-byte
	 * content (Farsi, emoji, …), which would hand back invalid UTF-8 on one
	 * page and the orphaned remainder on the next. Snapping backward at both
	 * edges means a start this method itself produced (offset=0, or a prior
	 * next_offset) is already a boundary — a no-op — and only a caller-
	 * supplied arbitrary offset can move, with the actual (possibly earlier)
	 * offset reported rather than silently used. Sequential pages therefore
	 * still concatenate back to the original exactly, byte for byte.
	 *
	 * @param string $html      Full (or selector-extracted) HTML.
	 * @param int    $offset    Byte offset to start the window at.
	 * @param int    $max_bytes Window size in bytes.
	 * @return array{html:string,total_bytes:int,offset:int,length:int,truncated:bool,next_offset:int|null,note:string|null}
	 */
	private function window_html_response( $html, $offset, $max_bytes ) {
		$total_bytes = strlen( $html );
		// The offset as the caller actually asked for it — bounds-clamped,
		// but before any UTF-8 boundary snapping. next_offset is judged
		// against THIS value (#804 N-R3-1), not the snapped serving offset
		// below: a client pages by feeding next_offset back in as its next
		// offset, so it is next_offset advancing past what was JUST
		// requested that guarantees progress, not next_offset merely
		// exceeding wherever this call happened to start serving from.
		$requested_offset = max( 0, min( absint( $offset ), $total_bytes ) );
		$offset           = $this->snap_utf8_boundary_backward( $html, $requested_offset );

		$raw_end = min( $total_bytes, $offset + $max_bytes );
		$end     = $this->snap_utf8_boundary_backward( $html, $raw_end );

		// Backing off both edges can only collapse the window to nothing when
		// max_bytes is narrower than the one character sitting at this
		// boundary (a pathologically small max_bytes, not a realistic one).
		// Snap forward instead so a page never returns empty with the same
		// offset it was asked at — that would neither move the caller nor
		// finish the document.
		if ( $end <= $offset && $offset < $total_bytes ) {
			$end = $this->snap_utf8_boundary_forward( $html, $raw_end );
		}

		$window    = substr( $html, $offset, $end - $offset );
		$length    = strlen( $window );
		$end       = $offset + $length;
		$truncated = $end < $total_bytes || $offset > 0;

		$next_offset = $end < $total_bytes ? $end : null;

		// #804 N-R3-1: on invalid UTF-8 with a tiny max_bytes, backward
		// snapping the START can move it BEHIND the requested offset (e.g.
		// requested_offset=1 snaps to offset=0 above), while the forward
		// fallback above is capped at the same 3-byte walk — so $end can
		// land at or before $requested_offset too. A client that keeps
		// resending next_offset as its offset would then get back to this
		// exact call forever. Force next_offset strictly past what was
		// requested, even if that means serving less than a full character
		// past it on this one pathological page — progress beats a
		// byte-perfect split on unparseable input.
		if ( null !== $next_offset && $next_offset <= $requested_offset ) {
			$next_offset = min( $total_bytes, max( $end, $requested_offset + 1 ) );
		}

		$note = null;
		if ( $truncated ) {
			if ( null !== $next_offset ) {
				$note = sprintf(
					/* translators: 1: start byte, 2: end byte, 3: total bytes, 4: max bytes ceiling */
					__( 'Showing bytes %1$d-%2$d of %3$d total. Pass offset=%2$d to continue reading — paging to the end reaches the tail, including the theme footer — or raise max_bytes (ceiling %4$d) to fetch more per call.', 'mcpwp' ),
					$offset,
					$end,
					$total_bytes,
					self::RENDERED_HTML_MAX_BYTES_CEILING
				);
			} else {
				$note = sprintf(
					/* translators: 1: start byte, 2: end byte, 3: total bytes */
					__( 'Showing bytes %1$d-%2$d of %3$d total — this is the end of the document. Pass offset=0 to see the beginning.', 'mcpwp' ),
					$offset,
					$end,
					$total_bytes
				);
			}
		}

		return array(
			'html'        => $window,
			'total_bytes' => $total_bytes,
			'offset'      => $offset,
			'length'      => $length,
			'truncated'   => $truncated,
			'next_offset' => $next_offset,
			'note'        => $note,
		);
	}

	/**
	 * Render a draft/pending/private Elementor post's content in-process.
	 *
	 * Bypasses the outbound loopback fetch used for published posts — see the
	 * caller for why that fetch is unreliable for unpublished content (#613).
	 *
	 * @param int         $post_id        Post ID.
	 * @param string|null $selector       Optional CSS selector to extract.
	 * @param int         $max_bytes      Window size in bytes.
	 * @param int         $offset         Byte offset to start the window at.
	 * @param string      $route_category The resolving route's own category,
	 *                                     for the #1032 nested-template
	 *                                     redaction — see
	 *                                     render_elementor_in_process().
	 * @param WP_Post|null $post          The resolved post, for the
	 *                                     effective-template report.
	 * @return WP_REST_Response
	 */
	private function get_rendered_html_via_elementor( $post_id, $selector, $max_bytes, $offset = 0, $route_category = '', $post = null ) {
		$html = $this->render_elementor_in_process( $post_id, $route_category, true );

		$selector_used  = null;
		$selector_found = true;

		if ( $selector && $html ) {
			$extracted = $this->extract_html_by_selector( $html, $selector );
			if ( null !== $extracted ) {
				$html          = $extracted;
				$selector_used = $selector;
			} else {
				$selector_found = false;
				$selector_used  = $selector;
			}
		}

		$window = $this->window_html_response( $html, $offset, $max_bytes );

		$result = array(
			'url'            => add_query_arg( 'elementor-preview', $post_id, get_permalink( $post_id ) ),
			'status_code'    => 200,
			'html'           => $window['html'],
			'length'         => $window['length'],
			'total_bytes'    => $window['total_bytes'],
			'offset'         => $window['offset'],
			'truncated'      => $window['truncated'],
			'next_offset'    => $window['next_offset'],
			'note'           => $window['note'],
			'selector_used'  => $selector_used,
			'selector_found' => $selector_found,
			'render_method'  => 'internal_elementor',
			'render_note'    => 'Rendered in-process from Elementor data — this is the Elementor content only, not the surrounding theme header/footer, and did not require an HTTP round trip to the site.',
			'effective_template' => $this->effective_template_report(
				$post instanceof WP_Post ? $post : get_post( (int) $post_id ),
				null,
				'This post is not published, so it was rendered in-process without the theme. Only the assigned templates are known; publish it (or fetch its public URL) to see which templates render.'
			),
		);

		// This method exists BECAUSE host-level gates intercept a loopback
		// fetch — the branch that chooses it says so. Working around a gate and
		// not mentioning it hands the caller a confident answer to a question
		// they did not ask: a form was QA'd as working here while the public
		// URL served a Coming Soon page with a 404 (#693).
		$result['public_route'] = Mcpwp_Public_Route::check( $post_id );

		if ( ! empty( $result['public_route']['warning'] ) ) {
			$result['warning'] = $result['public_route']['warning'];
		}

		return $this->success_response( $result );
	}

	private function extract_html_by_selector( $html, $selector ) {
		$doc = new DOMDocument();
		// Suppress warnings for malformed HTML.
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $doc );

		// Build XPath from simple CSS selector.
		if ( '#' === $selector[0] ) {
			// ID selector.
			$id         = substr( $selector, 1 );
			$xpath_expr = sprintf( '//*[@id="%s"]', $id );
		} elseif ( '.' === $selector[0] ) {
			// Class selector.
			$class      = substr( $selector, 1 );
			$xpath_expr = sprintf( '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class );
		} else {
			// Tag selector.
			$xpath_expr = '//' . $selector;
		}

		$nodes = $xpath->query( $xpath_expr );

		if ( ! $nodes || 0 === $nodes->length ) {
			return null;
		}

		$result = '';
		foreach ( $nodes as $node ) {
			$result .= $doc->saveHTML( $node );
		}

		return $result;
	}

	/**
	 * GET /rendered-copy — return structured visible text from a live page.
	 *
	 * Returns headings, paragraphs, button labels, list items, link text, and
	 * image alt text extracted from the rendered HTML body — NOT post_content.
	 * This is the ground-truth answer to "what does the live page actually say?"
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_rendered_copy( $request ) {
		$this->log_activity( 'get_rendered_copy', $request );

		$post_id        = $request->get_param( 'id' );
		$url            = $request->get_param( 'url' );
		$include_chrome = (bool) $request->get_param( 'include_chrome' );

		// Resolve URL from post ID.
		if ( $post_id ) {
			$post = get_post( absint( $post_id ) );
			if ( ! $post ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
			}
			// Gate non-public content behind write scope, same as get_rendered_html
			// and fetch_content: the in-process render below reads unpublished
			// bodies directly, so a read-only key must not reach it. 404 (not 403)
			// so the response never reveals that the hidden post exists.
			if ( ! $this->requester_can_read_post( $post ) ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
			}
			// #1030 round-3 review, test_every_id_shaped_route_is_declared_
			// or_explicitly_excluded: same gap and same fix as
			// get_rendered_html() above — 'id' is now a declared post-ref
			// param for /rendered-copy.
			$category_denied = $this->guard_post_type_category( $post, __( 'The requested resource was not found.', 'mcpwp' ) );
			if ( $category_denied ) {
				return $category_denied;
			}
			if ( 'publish' === $post->post_status ) {
				$url = get_permalink( $post_id );
			} else {
				// Unpublished content (draft/pending/private) isn't reliably
				// reachable via an outbound loopback: preview URLs need a
				// logged-in session wp_remote_get() can't carry, and hosting-level
				// gates (staging "Coming Soon" locks, #613/#655) intercept the
				// request before WordPress runs — so a loopback fetch 404s/502s
				// even though the page renders fine for an authenticated agent.
				// Render in-process instead so pre-publish copy QA works without
				// publishing (#675). This mirrors get_rendered_html's Elementor
				// branch, extended to classic/Gutenberg drafts.
				$route_category = self::get_category_for_route(
					$this->normalize_route_for_authorization( (string) $request->get_route() )
				);
				return $this->get_rendered_copy_in_process( $post, $include_chrome, $route_category );
			}
		}

		if ( empty( $url ) ) {
			return $this->error_response( 'missing_param', __( 'Either id or url is required.', 'mcpwp' ), 400 );
		}

		// SSRF guard: this site's own origin only (scheme + host + port) —
		// the same rule as get_rendered_html() (#981 review WARN-3).
		if ( ! self::is_same_site_origin( (string) $url ) ) {
			return $this->error_response(
				'ssrf_blocked',
				sprintf(
					/* translators: %s: allowed origin */
					__( 'Only this site\'s own URLs are allowed (same scheme, host and port as %s). Pass id to render a post instead.', 'mcpwp' ),
					self::origin_of_url( home_url( '/' ) )
				),
				403
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'sslverify'   => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_response( 'fetch_failed', $response->get_error_message(), 502 );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$html        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code || '' === $html ) {
			return $this->error_response(
				'fetch_error',
				sprintf( __( 'Page returned HTTP %d.', 'mcpwp' ), $status_code ),
				502
			);
		}

		$sections = $this->parse_visible_text( $html, $include_chrome );
		$all_text = implode( ' ', array_column( $sections, 'text' ) );

		return $this->success_response(
			array(
				'post_id'          => $post_id ? absint( $post_id ) : null,
				'url'              => $url,
				'sections'         => $sections,
				'all_text'         => $all_text,
				'word_count'       => str_word_count( $all_text ),
				'section_count'    => count( $sections ),
				'include_chrome'   => $include_chrome,
				'validation_basis' => 'rendered_html',
				'validation_note'  => 'Text extracted from live rendered page — reflects what visitors actually see, including SEO plugin injections and Elementor/Gutenberg output.',
			)
		);
	}

	/**
	 * Render a draft/pending/private Elementor post's visible text in-process.
	 *
	 * Mirrors get_rendered_html_via_elementor() — bypasses the outbound
	 * loopback fetch entirely for content that isn't publicly reachable yet.
	 *
	 * @param WP_Post $post           Post object.
	 * @param bool    $include_chrome Whether to include nav/header/footer text.
	 * @param string  $route_category The resolving route's own category, for
	 *                                 the #1032 nested-template redaction —
	 *                                 see render_elementor_in_process().
	 * @return WP_REST_Response
	 */
	private function get_rendered_copy_in_process( $post, $include_chrome, $route_category = '' ) {
		$post_id  = (int) $post->ID;
		$rendered = $this->render_post_html_in_process( $post, $route_category );
		$html     = $rendered['html'];
		$method   = $rendered['method'];

		$sections = $this->parse_visible_text( $html, $include_chrome );
		$all_text = implode( ' ', array_column( $sections, 'text' ) );

		return $this->success_response(
			array(
				'post_id'          => $post_id,
				'url'              => get_permalink( $post_id ),
				'sections'         => $sections,
				'all_text'         => $all_text,
				'word_count'       => str_word_count( $all_text ),
				'section_count'    => count( $sections ),
				'include_chrome'   => $include_chrome,
				'validation_basis' => 'rendered_html',
				'validation_note'  => 'internal_elementor' === $method
					? 'Text from an in-process Elementor render (no HTTP round trip) — Elementor content only, not the surrounding theme header/footer.'
					: 'Text from an in-process content render (no HTTP round trip) — post content only, not the surrounding theme header/footer.',
				'render_method'    => $method,
			)
		);
	}

	/**
	 * Parse visible text from HTML into structured sections.
	 *
	 * @param string $html           Full page HTML.
	 * @param bool   $include_chrome Whether to include nav/header/footer text.
	 * @return array<int,array> Sections with type, text, and optional metadata.
	 */
	private function parse_visible_text( $html, $include_chrome = false ) {
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $doc );

		// Remove non-visible elements.
		$remove_tags = array( 'head', 'script', 'style', 'noscript', 'svg', 'iframe' );
		if ( ! $include_chrome ) {
			$remove_tags = array_merge( $remove_tags, array( 'nav', 'header', 'footer' ) );
		}
		foreach ( $remove_tags as $tag ) {
			$nodes = $xpath->query( '//' . $tag );
			if ( $nodes ) {
				foreach ( iterator_to_array( $nodes ) as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}

		$sections = array();

		// Helper: trim and collapse whitespace.
		$clean = static function ( $text ) {
			return trim( preg_replace( '/\s+/', ' ', $text ) );
		};

		// Walk in document order: headings, paragraphs, buttons, links, list items, image alts.
		$expr  = '//h1|//h2|//h3|//h4|//h5|//h6|//p|//button|//li|//img[@alt]';
		$nodes = $xpath->query( $expr );

		// Also collect <a> tags (link text) but only those not already inside a button.
		$link_nodes = $xpath->query( '//a[not(ancestor::button)]' );

		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$tag  = strtolower( $node->nodeName );
				$text = $clean( $node->textContent );

				if ( '' === $text && 'img' !== $tag ) {
					continue;
				}

				if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
					$sections[] = array(
						'type'  => 'heading',
						'level' => (int) substr( $tag, 1 ),
						'tag'   => $tag,
						'text'  => $text,
					);
				} elseif ( 'p' === $tag ) {
					if ( $text !== '' ) {
						$sections[] = array(
							'type' => 'paragraph',
							'text' => $text,
						);
					}
				} elseif ( 'button' === $tag ) {
					if ( $text !== '' ) {
						$sections[] = array(
							'type' => 'button',
							'text' => $text,
						);
					}
				} elseif ( 'li' === $tag ) {
					// Skip list items that are just containers (have nested block els).
					$child_text = $clean( $node->textContent );
					if ( $child_text !== '' ) {
						$sections[] = array(
							'type' => 'list_item',
							'text' => $child_text,
						);
					}
				} elseif ( 'img' === $tag ) {
					$alt = $clean( (string) $node->getAttribute( 'alt' ) );
					if ( $alt !== '' ) {
						$sections[] = array(
							'type' => 'image_alt',
							'text' => $alt,
							'src'  => $clean( (string) $node->getAttribute( 'src' ) ),
						);
					}
				}
			}
		}

		// Append anchor link text (deduplicated against button text already collected).
		$seen_link_texts = array();
		if ( $link_nodes ) {
			foreach ( $link_nodes as $a ) {
				$text = $clean( $a->textContent );
				$href = $clean( (string) $a->getAttribute( 'href' ) );
				if ( '' === $text || isset( $seen_link_texts[ $text ] ) ) {
					continue;
				}
				$seen_link_texts[ $text ] = true;
				$sections[]               = array(
					'type' => 'link',
					'text' => $text,
					'href' => $href,
				);
			}
		}

		return $sections;
	}

}
