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

		// Custom-field meta on a non-public post (draft/pending/private) can hold
		// unpublished data (pricing, PII, ACF fields). Restrict it to write scope
		// or higher, matching the fetch/search/render gates; 404 for anyone else
		// so the hidden post is not disclosed.
		if ( ! $this->is_public_status( $post->post_status ) && ! $this->requester_can_see_non_public() ) {
			return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
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

		if ( ! $post instanceof WP_Post ) {
			return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
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

		// The featured image goes through the same check as the dedicated
		// route: it has to be an attachment.
		if ( '_thumbnail_id' === $key && ! empty( $value ) ) {
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
		if ( is_string( $value ) ) {
			$trimmed = ltrim( $value );
			if ( ( '{' === substr( $trimmed, 0, 1 ) || '[' === substr( $trimmed, 0, 1 ) ) && null !== json_decode( $value ) ) {
				$value = json_decode( $value, true );
			}
		}

		// The same policy as meta on a post write (#797): one answer to "can
		// this key be written, and how is the value treated". The two core
		// keys this route has always accepted stay accepted here.
		$clean = Mcpwp_Post_Fields::validate_meta( array( $key => $value ), true, (string) $post->post_type );
		if ( is_wp_error( $clean ) ) {
			return $clean;
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
			if ( ! $this->is_public_status( $post->post_status ) && ! $this->requester_can_see_non_public() ) {
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
				return $this->get_rendered_html_via_elementor( $post_id, $selector, $max_bytes, $offset );
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
				'selector_used'  => $selector_used,
				'selector_found' => $selector_found,
			)
		);
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
	 * @param int         $post_id   Post ID.
	 * @param string|null $selector  Optional CSS selector to extract.
	 * @param int         $max_bytes Window size in bytes.
	 * @param int         $offset    Byte offset to start the window at.
	 * @return WP_REST_Response
	 */
	private function get_rendered_html_via_elementor( $post_id, $selector, $max_bytes, $offset = 0 ) {
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
			if ( ! $this->is_public_status( $post->post_status ) && ! $this->requester_can_see_non_public() ) {
				return $this->error_response( 'not_found', __( 'Post not found.', 'mcpwp' ), 404 );
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
				return $this->get_rendered_copy_in_process( $post, $include_chrome );
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
	 * Render a draft/pending/private Elementor post's visible text in-process.
	 *
	 * Mirrors get_rendered_html_via_elementor() — bypasses the outbound
	 * loopback fetch entirely for content that isn't publicly reachable yet.
	 *
	 * @param int  $post_id        Post ID.
	 * @param bool $include_chrome Whether to include nav/header/footer text.
	 * @return WP_REST_Response
	 */
	private function get_rendered_copy_in_process( $post, $include_chrome ) {
		$post_id  = (int) $post->ID;
		$rendered = $this->render_post_html_in_process( $post );
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
