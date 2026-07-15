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
							'description' => __( 'CSS selector to extract (tag, .class, or #id).', 'mcpwp' ),
							'type'        => 'string',
						),
						'max_bytes' => array(
							'description' => __( 'Maximum response size in bytes (default 51200, max 204800).', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 51200,
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

	/**
	 * Whether a post status is publicly viewable.
	 *
	 * Only statuses whose registered status object carries the `public` flag
	 * count as public — on a stock WordPress install that is exactly the
	 * built-in `publish` status. Draft, pending, private, future, trash and
	 * every internal status return false. Unknown or unregistered status
	 * strings also return false, so ambiguous input is treated as non-public.
	 *
	 * @param string $status Post status slug.
	 * @return bool True when the status is publicly viewable.
	 */
	private function is_public_status( $status ) {
		$status_obj = get_post_status_object( (string) $status );
		return $status_obj && ! empty( $status_obj->public );
	}

	/**
	 * Whether the current caller may access non-public content.
	 *
	 * Non-public content (draft, pending, private, etc.) is limited to keys
	 * carrying write scope or higher (write or admin). A read-only key — or a
	 * request with no matched key record — is treated as unprivileged and sees
	 * published content only. Trusted in-dashboard admin-AJAX callers (admin
	 * capability plus a valid nonce) are also allowed, matching the plugin's
	 * other privileged read gates.
	 *
	 * @return bool True when the caller may access non-public content.
	 */
	private function requester_can_see_non_public() {
		$key_record = $this->get_current_api_key_record();
		if ( $key_record && $this->key_has_scope( $key_record, 'write' ) ) {
			return true;
		}

		return $this->is_trusted_admin_ajax_request();
	}

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

		$post = null;
		if ( $id > 0 ) {
			$post = get_post( $id );
		} elseif ( '' !== $url ) {
			$resolved_id = $this->resolve_content_id_from_url( $url, $type );
			if ( $resolved_id > 0 ) {
				$post = get_post( $resolved_id );
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
		if ( ! $this->is_public_status( $post->post_status ) && ! $this->requester_can_see_non_public() ) {
			return $this->error_response(
				'not_found',
				__( 'Content not found.', 'mcpwp' ),
				404
			);
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

		if ( ! $post instanceof WP_Post ) {
			return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
		}

		$key = $request->get_param( 'key' );

		if ( ! empty( $key ) ) {
			$key = sanitize_key( $key );

			if ( $this->is_blocked_meta_key( $key ) ) {
				return $this->error_response( 'forbidden_meta_key', __( 'This meta key is not accessible via API.', 'mcpwp' ), 403 );
			}

			$value = get_post_meta( $post_id, $key, true );

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
				$safe_meta[ $meta_key ] = count( $meta_values ) === 1 ? $meta_values[0] : $meta_values;
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

		if ( ! $post instanceof WP_Post ) {
			return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
		}

		$key   = sanitize_key( (string) $request->get_param( 'key' ) );
		$value = $request->get_param( 'value' );

		if ( '' === $key ) {
			return $this->error_response( 'missing_key', __( 'Meta key is required.', 'mcpwp' ), 400 );
		}

		if ( $this->is_blocked_meta_key( $key ) ) {
			// Provide specific guidance for Elementor meta keys.
			if ( '_elementor_data' === $key ) {
				return $this->error_response(
					'use_elementor_endpoint',
					__( 'Use wp_set_elementor (POST /elementor/{id}) to set Elementor page data.', 'mcpwp' ),
					400
				);
			}
			if ( '_elementor_page_settings' === $key ) {
				return $this->error_response(
					'use_elementor_endpoint',
					__( 'Use wp_set_elementor with the page_settings parameter to update Elementor page settings.', 'mcpwp' ),
					400
				);
			}
			if ( 0 === strpos( $key, '_elementor' ) ) {
				return $this->error_response(
					'use_elementor_endpoint',
					__( 'Elementor meta keys cannot be set via wp_set_post_meta. Use the /elementor/{id} endpoints instead.', 'mcpwp' ),
					400
				);
			}
			return $this->error_response( 'forbidden_meta_key', __( 'This meta key is not accessible via API.', 'mcpwp' ), 403 );
		}

		// Sanitize value — decode JSON objects/arrays to PHP arrays so WordPress
		// serializes them properly (instead of storing raw JSON strings).
		if ( is_string( $value ) ) {
			$trimmed = ltrim( $value );
			if ( ( '{' === substr( $trimmed, 0, 1 ) || '[' === substr( $trimmed, 0, 1 ) ) && null !== json_decode( $value ) ) {
				// Decode JSON to a PHP array — WordPress will auto-serialize it.
				$value = json_decode( $value, true );
			} else {
				$value = sanitize_text_field( $value );
			}
		}

		$result = update_post_meta( $post_id, $key, $value );

		return $this->success_response(
			array(
				'id'      => $post_id,
				'key'     => $key,
				'value'   => $value,
				'updated' => false !== $result,
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

		foreach ( $blocked_prefixes as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return true;
			}
		}

		// Block secret-looking keys
		if ( preg_match( '/(password|secret|token|auth|credential)/i', $meta_key ) ) {
			return true;
		}

		return false;
	}

	public function get_rendered_html( $request ) {
		$this->log_activity( 'get_rendered_html', $request );

		$post_id   = $request->get_param( 'id' );
		$url       = $request->get_param( 'url' );
		$selector  = $request->get_param( 'selector' );
		$max_bytes = min( absint( $request->get_param( 'max_bytes' ) ?: 51200 ), 204800 );

		// Resolve URL from post ID.
		if ( $post_id ) {
			$post = get_post( absint( $post_id ) );
			if ( ! $post ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
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
				return $this->get_rendered_html_via_elementor( $post_id, $selector, $max_bytes );
			} else {
				// Draft/pending — use Elementor preview URL or plain preview.
				$url = add_query_arg( 'elementor-preview', $post_id, get_permalink( $post_id ) );
			}
		}

		if ( empty( $url ) ) {
			return $this->error_response( 'missing_param', __( 'Either id or url is required.', 'mcpwp' ), 400 );
		}

		// SSRF guard: only allow same-host URLs.
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$request_host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $request_host || strtolower( $request_host ) !== strtolower( $site_host ) ) {
			return $this->error_response(
				'ssrf_blocked',
				sprintf(
					/* translators: %s: allowed host */
					__( 'Only same-host URLs are allowed. Expected host: %s', 'mcpwp' ),
					$site_host
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

		// Truncate if needed.
		$truncated = false;
		if ( strlen( $html ) > $max_bytes ) {
			$html      = substr( $html, 0, $max_bytes );
			$truncated = true;
		}

		return $this->success_response(
			array(
				'url'            => $url,
				'status_code'    => $status_code,
				'html'           => $html,
				'length'         => strlen( $html ),
				'truncated'      => $truncated,
				'selector_used'  => $selector_used,
				'selector_found' => $selector_found,
			)
		);
	}

	/**
	 * Render a draft/pending/private Elementor post's content in-process.
	 *
	 * Bypasses the outbound loopback fetch used for published posts — see the
	 * caller for why that fetch is unreliable for unpublished content (#613).
	 *
	 * @param int         $post_id   Post ID.
	 * @param string|null $selector  Optional CSS selector to extract.
	 * @param int         $max_bytes Truncation limit.
	 * @return WP_REST_Response
	 */
	private function get_rendered_html_via_elementor( $post_id, $selector, $max_bytes ) {
		$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post_id, true );

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

		$truncated = false;
		if ( strlen( $html ) > $max_bytes ) {
			$html      = substr( $html, 0, $max_bytes );
			$truncated = true;
		}

		return $this->success_response(
			array(
				'url'            => add_query_arg( 'elementor-preview', $post_id, get_permalink( $post_id ) ),
				'status_code'    => 200,
				'html'           => $html,
				'length'         => strlen( $html ),
				'truncated'      => $truncated,
				'selector_used'  => $selector_used,
				'selector_found' => $selector_found,
				'render_method'  => 'internal_elementor',
				'render_note'    => 'Rendered in-process from Elementor data — this is the Elementor content only, not the surrounding theme header/footer, and did not require an HTTP round trip to the site.',
			)
		);
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
			if ( 'publish' === $post->post_status ) {
				$url = get_permalink( $post_id );
			} else {
				// Draft — use preview URL so agents can audit before publish.
				$url = get_preview_post_link( $post_id );
			}
		}

		if ( empty( $url ) ) {
			return $this->error_response( 'missing_param', __( 'Either id or url is required.', 'mcpwp' ), 400 );
		}

		// SSRF guard: same-host only.
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$request_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $request_host || strtolower( $request_host ) !== strtolower( $site_host ) ) {
			return $this->error_response(
				'ssrf_blocked',
				sprintf( __( 'Only same-host URLs allowed. Expected: %s', 'mcpwp' ), $site_host ),
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
