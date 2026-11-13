<?php
/**
 * Posts handler
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle post operations.
 */
class Mcpwp_Posts {

	use Mcpwp_Sanitization;

	/**
	 * Allowed post types for the posts controller.
	 *
	 * @var array
	 */
	private $allowed_post_types = array( 'post', 'wp_block' );

	/**
	 * Post types that should never be created/managed through this controller.
	 *
	 * @var array
	 */
	private $blocked_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part' );

	/**
	 * Validate and return a post type string.
	 *
	 * @param string $type Requested post type.
	 * @return string|WP_Error Sanitized type or error.
	 */
	/**
	 * Non-public post types that are safe to allow through the API.
	 *
	 * @var array
	 */
	private $safe_nonpublic_types = array( 'elementor_snippet', 'elementor_library' );

	/**
	 * Whether a post type is one this plugin never writes to.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	/**
	 * Whether a post type is one this plugin writes to: what validate_post_type()
	 * admits (public, or the whitelisted non-public Elementor types), never a
	 * blocked one. An allowlist, like every other write gate here.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_writable_post_type( $type ) {
		$type = (string) $type;

		if ( '' === $type || self::is_blocked_post_type( $type ) ) {
			return false;
		}

		if ( in_array( $type, array( 'post', 'page', 'elementor_snippet', 'elementor_library', 'wp_block' ), true ) ) {
			return true;
		}

		if ( ! function_exists( 'get_post_type_object' ) ) {
			return false;
		}

		$object = get_post_type_object( $type );

		return is_object( $object ) && ! empty( $object->public );
	}


	public static function is_blocked_post_type( $type ) {
		return in_array( (string) $type, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part' ), true );
	}

	/**
	 * Fields update_post() acts on. Anything else is refused by name (#803):
	 * a field that is accepted and ignored is a 200 that did nothing.
	 *
	 * @var array
	 */
	public static $update_fields = array( 'title', 'content', 'status', 'excerpt', 'slug', 'categories', 'tags', 'featured_image', 'elementor_location', 'terms', 'meta' );

	/**
	 * Fields create_post() acts on.
	 *
	 * @var array
	 */
	public static $create_fields = array( 'title', 'content', 'status', 'excerpt', 'slug', 'categories', 'tags', 'featured_image', 'post_type', 'elementor_location', 'terms', 'meta' );

	/**
	 * Parameters the transport adds that are not the caller's fields.
	 *
	 * @var array
	 */
	public static $transport_params = array( 'id', 'context', '_locale', '_fields', '_embed', '_envelope', '_method', '_wpnonce', 'rest_route' );

	/**
	 * Drop the query-string auth parameter from a request's merged params so
	 * it is not mistaken for a body field — and ONLY when it came on the
	 * query string. A body field named api_key is still an unsupported
	 * field, so a 200 keeps meaning every field landed (#803 round 4).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function body_fields( $request ) {
		$data  = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : array();
		$query = method_exists( $request, 'get_query_params' ) ? (array) $request->get_query_params() : array();

		if ( isset( $query['api_key'] ) && isset( $data['api_key'] ) ) {
			$body = method_exists( $request, 'get_body_params' ) ? (array) $request->get_body_params() : array();
			$json = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
			if ( ! isset( $body['api_key'] ) && ! ( is_array( $json ) && isset( $json['api_key'] ) ) ) {
				unset( $data['api_key'] );
			}
		}

		return $data;
	}

	/**
	 * Refuse fields the writer does not act on (#803).
	 *
	 * @param mixed $data  Submitted fields.
	 * @param array $known Fields the writer acts on.
	 * @return true|WP_Error
	 */
	public static function unsupported_fields( $data, array $known ) {
		if ( ! is_array( $data ) ) {
			return true;
		}

		$unsupported = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( ! is_string( $field ) || in_array( $field, $known, true ) || in_array( $field, self::$transport_params, true ) ) {
				continue;
			}
			$unsupported[] = $field;
		}

		if ( empty( $unsupported ) ) {
			return true;
		}

		return new WP_Error(
			'unsupported_fields',
			sprintf(
				/* translators: %s: comma-separated field names */
				__( 'Unsupported field(s): %s. Nothing was written.', 'mcpwp' ),
				implode( ', ', $unsupported )
			),
			array(
				'status'      => 400,
				'unsupported' => $unsupported,
				'supported'   => array_values( $known ),
				'hint'        => 'This writer acts only on the listed supported fields. A field it does not know used to be accepted and silently dropped; it is now refused so a 200 always means every field landed.',
			)
		);
	}


	/**
	 * Category check injected by Mcpwp_REST_Posts right after authentication
	 * (#1022 BLOCK-1, round-1 adversarial review of #1020's fix).
	 *
	 * Mcpwp_Posts has no REST/auth context of its own — it doesn't know what
	 * an API key or a tool category is — but every one of its callers is
	 * that ONE controller (get_post, update_post, delete_post, create_post,
	 * list_posts have no other call site in this codebase), so a single
	 * injected check reached through validate_post_type() covers every
	 * read/write/delete/create path, including each item inside
	 * bulk_update_posts/bulk_create_posts (both call update_post()/
	 * create_post() per item, so a category-denied item is refused there
	 * too, with no bulk-specific code needed).
	 *
	 * Before this fix, is_writable_post_type()/validate_post_type() admitted
	 * 'product' (any public custom type) and the whitelisted non-public
	 * Elementor types with no idea which MCPWP tool category owns them — an
	 * author key (categories: content/media/taxonomy) with 'woocommerce' and
	 * 'elementor-templates' disabled site-wide could still PUT/DELETE a
	 * product or an Elementor template through this generic post endpoint,
	 * or POST a brand new one, even though #1020 had already closed the
	 * identical gap on the READ side (GET /post-meta, /content, /fetch).
	 *
	 * Null (the default, and every context with no key record — e.g.
	 * trusted admin-AJAX) means unrestricted, matching every other category
	 * gate's convention in this plugin.
	 *
	 * @var callable|null Takes a post_type string, returns bool.
	 */
	private $category_allowed_callback = null;

	/**
	 * Inject the category check. Called once per request, from
	 * Mcpwp_REST_Posts::check_permission() — the one permission_callback
	 * shared by every route this controller registers — right after
	 * verify_api_key() succeeds.
	 *
	 * @param callable|null $callback Takes a post_type string, returns bool.
	 * @return void
	 */
	public function set_category_allowed_callback( $callback ) {
		$this->category_allowed_callback = is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Whether the current caller's category check (if any) admits $type.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private function category_allowed( $type ) {
		if ( null === $this->category_allowed_callback ) {
			return true;
		}
		return (bool) call_user_func( $this->category_allowed_callback, $type );
	}

	/**
	 * A category-denied WP_Error. get_post()/update_post()/delete_post() use
	 * this only as an `is_wp_error()` boolean — folded into the SAME
	 * existence check that already builds their own uniform "not found"
	 * response for a genuinely missing post, so a category denial is
	 * byte-identical to that response by construction (round-1 review,
	 * WARN-1: no existence oracle). create_post() and list_posts() DO
	 * propagate this error's own text directly to the caller — there is no
	 * existing post to protect the existence of on either of those paths
	 * (create makes a new one; list's caller named the type up front), so a
	 * plainly-worded 403 is the right shape, matching
	 * Mcpwp_Api_Auth::guard_post_type_category_for_type()'s convention.
	 *
	 * @param string $type Post type.
	 * @return WP_Error
	 */
	private function category_denied_error( $type ) {
		return new WP_Error(
			'forbidden_category',
			sprintf(
				/* translators: %s: post type */
				__( 'Post type "%s" belongs to a tool category this API key does not have access to, or which has been disabled by the site administrator.', 'mcpwp' ),
				$type
			),
			array( 'status' => 403 )
		);
	}

	private function validate_post_type( $type ) {
		$type = sanitize_key( $type );

		if ( in_array( $type, $this->blocked_post_types, true ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'Invalid or unsupported post type.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => sprintf(
						'Post type "%s" is blocked for security reasons. Use wp_site_info to check available post types, or wp_list_content(post_type=\'...\') for custom post types.',
						$type
					),
				)
			);
		}

		if ( ! in_array( $type, $this->allowed_post_types, true ) ) {
			// Allow whitelisted non-public types.
			if ( in_array( $type, $this->safe_nonpublic_types, true ) && post_type_exists( $type ) ) {
				return $this->category_allowed( $type ) ? $type : $this->category_denied_error( $type );
			}
			// Also allow any public custom post type that isn't blocked.
			if ( ! post_type_exists( $type ) || ! get_post_type_object( $type )->public ) {
				return new WP_Error(
					'invalid_post_type',
					__( 'Invalid or unsupported post type.', 'mcpwp' ),
					array(
						'status' => 400,
						'hint'   => sprintf(
							'Post type "%s" does not exist or is not public. Use wp_site_info to check available post types. Common types: post, page.',
							$type
						),
					)
				);
			}
		}

		return $this->category_allowed( $type ) ? $type : $this->category_denied_error( $type );
	}

	/**
	 * List posts.
	 *
	 * @param array $args Query arguments.
	 * @return array Posts data.
	 */
	public function list_posts( $args = array() ) {
		$mcpwp_fields = isset( $args['_mcpwp_fields'] ) ? $args['_mcpwp_fields'] : null;
		unset( $args['_mcpwp_fields'] );

		$defaults = array(
			'post_type'      => 'post',
			'posts_per_page' => 10,
			'paged'          => 1,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate post type if overridden.
		if ( isset( $args['post_type'] ) && 'post' !== $args['post_type'] ) {
			$validated = $this->validate_post_type( $args['post_type'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$args['post_type'] = $validated;
		}

		// Sanitize arguments
		$args['posts_per_page'] = absint( $args['posts_per_page'] );
		$args['paged'] = absint( $args['paged'] );

		$query = new WP_Query( $args );
		$posts = array();

		$include_full = $mcpwp_fields && ( in_array( 'content', $mcpwp_fields, true ) || in_array( 'word_count', $mcpwp_fields, true ) );

		foreach ( $query->posts as $post ) {
			$formatted = $this->format_post( $post, $include_full );
			if ( $mcpwp_fields ) {
				$formatted = $this->filter_fields( $formatted, $mcpwp_fields );
			}
			$posts[] = $formatted;
		}

		return array(
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'pages'       => (int) $query->max_num_pages,
			'page'        => (int) $args['paged'],
			'per_page'    => (int) $args['posts_per_page'],
		);
	}

	/**
	 * The "not found" hint for /posts/{id}, keyed by post id.
	 *
	 * Single source of truth for get_post()'s own missing-post branch AND
	 * Mcpwp_REST_Posts::get_post()'s guard_non_public_post() call — the two
	 * MUST produce byte-identical hints for the same id, or a non-public post
	 * (denied by the guard) becomes distinguishable from a genuinely missing
	 * one purely by hint text (#1026).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function not_found_hint( $post_id ) {
		return sprintf(
			'Post ID %d not found. Use wp_list_posts to see available posts, or wp_search to find content by keyword. If this is a page, use wp_list_pages instead.',
			absint( $post_id )
		);
	}

	/**
	 * Get a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error Post data or error.
	 */
	public function get_post( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post || 'page' === $post->post_type || is_wp_error( $this->validate_post_type( $post->post_type ) ) ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'mcpwp' ),
				array(
					'status' => 404,
					'hint'   => self::not_found_hint( $post_id ),
				)
			);
		}

		return $this->format_post( $post, true );
	}

	/**
	 * Create a post.
	 *
	 * @param array $data Post data.
	 * @return array|WP_Error Created post data or error.
	 */
	public function create_post( $data ) {
		$unsupported = self::unsupported_fields( $data, self::$create_fields );
		if ( is_wp_error( $unsupported ) ) {
			return $unsupported;
		}

		$post_type = 'post';
		if ( ! empty( $data['post_type'] ) ) {
			$validated = $this->validate_post_type( $data['post_type'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$post_type = $validated;
		}

		// elementor_snippet is whitelisted in $safe_nonpublic_types (a
		// generic caller can list/read them fine), but a generic create
		// here writes only post_content — never _elementor_code,
		// _elementor_location, or _elementor_priority. That produces a
		// snippet that reads back via the "may be altered" legacy fallback
		// in Mcpwp_REST_Elementor_Pro::read_custom_code_source() forever
		// (never rendered by Elementor, since its own renderer reads
		// _elementor_code, not post_content — see #871) and, being a
		// snippet with no _elementor_location at all, never enters
		// Elementor's location-keyed conditions cache either (#974/#976
		// adversarial review, NIT-c). Refusing here, once, is smaller than
		// teaching this generic writer the Custom Code shape.
		if ( 'elementor_snippet' === $post_type ) {
			return new WP_Error(
				'use_custom_code_tool',
				__( 'Elementor Custom Code snippets cannot be created through the generic post endpoint.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'Use wp_create_elementor_custom_code (POST /elementor/custom-code) instead — it writes the _elementor_code/_elementor_location/_elementor_priority meta Elementor\'s own renderer and conditions cache require, which this generic endpoint does not.',
				)
			);
		}

		// Validate terms and meta before anything is written (#797).
		$resolved_terms = Mcpwp_Post_Fields::resolve_terms( isset( $data['terms'] ) ? $data['terms'] : null, $post_type, $data );
		if ( is_wp_error( $resolved_terms ) ) {
			return $resolved_terms;
		}
		$clean_meta = Mcpwp_Post_Fields::validate_meta( isset( $data['meta'] ) ? $data['meta'] : null, false, $post_type );
		if ( is_wp_error( $clean_meta ) ) {
			return $clean_meta;
		}

		$sanitized_content = array( 'removed' => array() );
		if ( isset( $data['content'] ) ) {
			$sanitized_content = $this->sanitize_content_body( $data['content'] );
		}

		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? $sanitized_content['content'] : '',
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
		);

		// Validate status
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $post_data['post_status'], $allowed_statuses, true ) ) {
			$post_data['post_status'] = 'draft';
		}

		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}

		// #1108: a user-run principal may write only the meta WordPress lets
		// that user write — judged before the post exists, against the post
		// about to be created (same type, own author, intended status), so a refusal
		// fires no hook and leaves nothing behind.
		$meta_refused = Mcpwp_Post_Fields::meta_write_refusal( 0, $clean_meta, $post_type, array( 'status' => $post_data['post_status'] ) );
		if ( is_wp_error( $meta_refused ) ) {
			return $meta_refused;
		}

		// Content guardrails (#957): enforced centrally by
		// Mcpwp_Content_Guardrails::on_insert_post_data(), a wp_insert_post_data
		// filter that runs inside the wp_insert_post() call below — not here.
		// See that method's docblock for why.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set categories
		if ( ! empty( $data['categories'] ) ) {
			$categories = array_map( 'absint', (array) $data['categories'] );
			wp_set_post_categories( $post_id, $categories );
		}

		// Set tags
		if ( ! empty( $data['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', (array) $data['tags'] );
			wp_set_post_tags( $post_id, $tags );
		}

		// Set featured image
		if ( ! empty( $data['featured_image'] ) ) {
			set_post_thumbnail( $post_id, absint( $data['featured_image'] ) );
		}

		$terms_applied = Mcpwp_Post_Fields::apply_terms( $post_id, $resolved_terms );
		$meta_deleted  = array();
		$meta_applied  = Mcpwp_Post_Fields::apply_meta( $post_id, $post_type, $clean_meta, $meta_deleted );

		// Read back any supported type, including pages, and revalidate after insert hooks.
		$post = get_post( $post_id );
		if ( ! $post || is_wp_error( $this->validate_post_type( $post->post_type ) ) ) {
			return $this->get_post( $post_id );
		}
		$created = $this->format_post( $post, true );
		if ( is_array( $created ) ) {
			if ( ! empty( $terms_applied ) ) {
				$created['terms'] = $terms_applied;
			}
			if ( ! empty( $meta_applied ) ) {
				$created['meta_applied'] = $meta_applied;
			}
			if ( ! empty( $meta_deleted ) ) {
				$created['meta_deleted'] = $meta_deleted;
			}
			if ( ! empty( $sanitized_content['removed'] ) ) {
				$created['sanitized'] = $sanitized_content['removed'];
			}
		}

		return $created;
	}

	/**
	 * update_post()'s "no such post" answer. Shared with
	 * Mcpwp_REST_Posts::bulk_update_posts(), which gives an item the caller
	 * may not see (#1030 round-5) exactly this answer without calling
	 * update_post() at all.
	 *
	 * @param int $post_id Post id.
	 * @return WP_Error
	 */
	public static function update_target_not_found_error( $post_id ) {
		return new WP_Error(
			'not_found',
			__( 'Post not found.', 'mcpwp' ),
			array(
				'status' => 404,
				'hint'   => sprintf(
					'Post ID %d not found. Use wp_list_posts to see available posts. If this is a page, use wp_update_page instead.',
					absint( $post_id )
				),
			)
		);
	}

	/**
	 * Update a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Post data.
	 * @return array|WP_Error Updated post data or error.
	 */
	public function update_post( $post_id, $data ) {
		$unsupported = self::unsupported_fields( $data, self::$update_fields );
		if ( is_wp_error( $unsupported ) ) {
			return $unsupported;
		}

		$post = get_post( absint( $post_id ) );

		if ( ! $post || 'page' === $post->post_type || is_wp_error( $this->validate_post_type( $post->post_type ) ) ) {
			return self::update_target_not_found_error( $post_id );
		}

		// #974/#976 review round 2, W1: this route used to accept
		// `elementor_location` on an elementor_snippet update and write it
		// as the SHORT public value (e.g. "body_end") straight into
		// `_elementor_location` — which Elementor's own renderer reads as
		// its literal internal value (elementor_head/elementor_body_start/
		// elementor_body_end; see get_custom_code_location_map() in
		// Mcpwp_REST_Elementor_Pro), so the snippet kept printing in its
		// OLD place. It also never called
		// Mcpwp_REST_Elementor_Pro::sync_custom_code_conditions_cache(),
		// so Elementor Pro's real conditions cache kept the snippet filed
		// under its stale former location and it eventually stopped
		// rendering there too, matching what create_post() already refuses
		// (#974/#976 review round 1, NIT-c) for the identical reason.
		// `content` (post_content) is refused the same way: it is not what
		// Elementor's renderer reads either (`_elementor_code` is — see
		// Mcpwp_REST_Elementor_Pro::read_custom_code_source()), so writing
		// it here produces exactly the divergence that fix already guards
		// against on create. A title-only (or status/excerpt/slug/meta/
		// terms) update carries neither field and is unaffected.
		if ( 'elementor_snippet' === $post->post_type && ( isset( $data['elementor_location'] ) || isset( $data['content'] ) ) ) {
			return new WP_Error(
				'use_custom_code_tool',
				__( 'Elementor Custom Code snippets cannot have their code or injection location updated through the generic post endpoint.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'Use wp_update_elementor_custom_code (PUT /elementor/custom-code/{id}) instead — it writes the internal _elementor_location value Elementor\'s renderer actually reads and keeps Elementor Pro\'s conditions cache in sync, neither of which this generic endpoint does.',
				)
			);
		}

		// Snapshot fields that might change for before/after diff.
		// Validate terms and meta before anything is written (#797).
		$resolved_terms = Mcpwp_Post_Fields::resolve_terms( isset( $data['terms'] ) ? $data['terms'] : null, $post->post_type, $data );
		if ( is_wp_error( $resolved_terms ) ) {
			return $resolved_terms;
		}
		$clean_meta = Mcpwp_Post_Fields::validate_meta( isset( $data['meta'] ) ? $data['meta'] : null, false, $post->post_type );
		if ( is_wp_error( $clean_meta ) ) {
			return $clean_meta;
		}
		// #1108: refused before anything is written.
		$meta_refused = Mcpwp_Post_Fields::meta_write_refusal( $post_id, $clean_meta, $post->post_type );
		if ( is_wp_error( $meta_refused ) ) {
			return $meta_refused;
		}

		$before = array(
			'title'  => Mcpwp_Agent_Text::decode( $post->post_title ),
			'status' => $post->post_status,
			'slug'   => $post->post_name,
		);
		if ( isset( $data['excerpt'] ) ) {
			$before['excerpt'] = $post->post_excerpt;
		}
		if ( isset( $data['content'] ) ) {
			$before['content_chars'] = mb_strlen( $post->post_content );
		}
		if ( isset( $data['categories'] ) ) {
			$before['categories'] = wp_list_pluck( wp_get_post_categories( $post_id, array( 'fields' => 'all' ) ), 'name' );
		}
		if ( isset( $data['tags'] ) ) {
			$before['tags'] = wp_list_pluck( wp_get_post_tags( $post_id ), 'slug' );
		}

		$post_data         = array( 'ID' => $post_id );
		$sanitized_content = array( 'removed' => array() );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$sanitized_content         = $this->sanitize_content_body( $data['content'] );
			$post_data['post_content'] = $sanitized_content['content'];
		}

		if ( isset( $data['status'] ) ) {
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'trash' );
			$status = sanitize_key( $data['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$post_data['post_status'] = $status;
			}
		}

		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update categories
		if ( isset( $data['categories'] ) ) {
			$categories = array_map( 'absint', (array) $data['categories'] );
			wp_set_post_categories( $post_id, $categories );
		}

		// Update tags
		if ( isset( $data['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', (array) $data['tags'] );
			wp_set_post_tags( $post_id, $tags );
		}

		// Update featured image
		if ( isset( $data['featured_image'] ) ) {
			if ( empty( $data['featured_image'] ) ) {
				delete_post_thumbnail( $post_id );
			} else {
				set_post_thumbnail( $post_id, absint( $data['featured_image'] ) );
			}
		}

		$terms_applied = Mcpwp_Post_Fields::apply_terms( $post_id, $resolved_terms );
		$meta_deleted  = array();
		$meta_applied  = Mcpwp_Post_Fields::apply_meta( $post_id, $post->post_type, $clean_meta, $meta_deleted );

		$post_result = $this->get_post( $post_id );
		if ( is_array( $post_result ) ) {
			if ( ! empty( $terms_applied ) ) {
				$post_result['terms'] = $terms_applied;
			}
			if ( ! empty( $meta_applied ) ) {
				$post_result['meta_applied'] = $meta_applied;
			}
			if ( ! empty( $meta_deleted ) ) {
				$post_result['meta_deleted'] = $meta_deleted;
			}
			if ( ! empty( $sanitized_content['removed'] ) ) {
				$post_result['sanitized'] = $sanitized_content['removed'];
			}
		}

		// Build before/after change summary.
		if ( is_array( $post_result ) ) {
			$after = array(
				'title'  => $post_result['title'],
				'status' => $post_result['status'],
				'slug'   => $post_result['slug'],
			);
			if ( isset( $before['excerpt'] ) ) {
				$after['excerpt'] = $post_result['excerpt'];
			}
			if ( isset( $before['content_chars'] ) ) {
				$updated          = get_post( $post_id );
				$after['content_chars'] = mb_strlen( $updated->post_content );
			}
			if ( isset( $before['categories'] ) ) {
				$after['categories'] = wp_list_pluck( wp_get_post_categories( $post_id, array( 'fields' => 'all' ) ), 'name' );
			}
			if ( isset( $before['tags'] ) ) {
				$after['tags'] = wp_list_pluck( wp_get_post_tags( $post_id ), 'slug' );
			}

			$changes   = array();
			$unchanged = array();
			foreach ( $before as $field => $old_val ) {
				$new_val = isset( $after[ $field ] ) ? $after[ $field ] : null;
				if ( is_array( $old_val ) ) {
					$a = (array) $old_val;
					$b = (array) $new_val;
					sort( $a );
					sort( $b );
					$changed = $a !== $b;
				} else {
					$changed = $new_val !== $old_val;
				}
				if ( $changed ) {
					$changes[] = array(
						'field'  => $field,
						'before' => $old_val,
						'after'  => $new_val,
					);
				} else {
					$unchanged[] = $field;
				}
			}

			$post_result['changes']   = $changes;
			$post_result['unchanged'] = $unchanged;
		}

		return $post_result;
	}

	/**
	 * Delete a post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Force delete (skip trash).
	 * @return array|WP_Error Success or error.
	 */
	/**
	 * Resolve a post for deletion: existence, type (blocked types refused),
	 * and — via validate_post_type()'s injected category check — tool
	 * category, all in ONE place and ONE error shape.
	 *
	 * #1022 round-2 adversarial review, BLOCK-R2-1: Mcpwp_REST_Posts::
	 * delete_post() used to run its OWN, separate existence check ONLY when
	 * the destructive-approval gate happened to be engaged
	 * (Mcpwp_Destructive_Gate::should_gate()), before ever deciding whether
	 * to defer to approval — a check that knew nothing about category. A
	 * category-denied but otherwise-writable type (an Elementor template,
	 * or literally anything with force=true, which is always "permanent")
	 * sailed past that pre-check and straight into defer_destructive(),
	 * queuing an approval request an admin could later apply — completing a
	 * delete the calling key was never authorized for in the first place.
	 * Making the REST handler call THIS method first, unconditionally,
	 * before any destructive-gate decision, closes that: the same
	 * existence+category check now always runs before an approval can even
	 * be considered, and produces the exact same error whether called from
	 * there or from delete_post() below — they cannot disagree because it
	 * is the same call.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error The resolved post, or the not-found error
	 *                           delete_post() has always returned for this case.
	 */
	public function resolve_deletable_post( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post || 'page' === $post->post_type || is_wp_error( $this->validate_post_type( $post->post_type ) ) ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'mcpwp' ),
				array(
					'status' => 404,
					'hint'   => sprintf(
						'Post ID %d not found. Use wp_list_posts to verify the post exists. If this is a page, use wp_delete_page instead.',
						absint( $post_id )
					),
				)
			);
		}

		return $post;
	}

	public function delete_post( $post_id, $force = false ) {
		$post = $this->resolve_deletable_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// #1023 (round-1 adversarial review of #1020/#1022): wp_delete_post()
		// with force=false only auto-trashes when post_type is LITERALLY
		// 'post' or 'page' — every other type this method admits (product,
		// elementor_snippet, elementor_library, any public custom type) is
		// PERMANENTLY deleted by that same call regardless of $force, while
		// the message below still said "moved to trash". Decide the real
		// outcome with the same test the destructive-approval gate uses
		// (Mcpwp_Destructive_Gate::delete_is_permanent()) and trash
		// explicitly (wp_trash_post(), which works for any post type) when
		// the answer is "not permanent", instead of trusting
		// wp_delete_post()'s own narrower built-in branch.
		$permanent = class_exists( 'Mcpwp_Destructive_Gate' )
			? Mcpwp_Destructive_Gate::delete_is_permanent( $post->post_type, $force, (string) $post->post_status )
			: $force;

		if ( $permanent ) {
			$result = wp_delete_post( $post_id, true );
		} elseif ( in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$result = wp_delete_post( $post_id, false );
		} else {
			$result = wp_trash_post( $post_id );
		}

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete post.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'message' => $permanent
				? __( 'Post permanently deleted.', 'mcpwp' )
				: __( 'Post moved to trash.', 'mcpwp' ),
			'post_id' => $post_id,
		);
	}

	/**
	 * Format post for API response.
	 *
	 * @param WP_Post $post         Post object.
	 * @param bool    $include_full Include full content.
	 * @return array Formatted post.
	 */
	protected function format_post( $post, $include_full = false ) {
		// Leaves cast to the types wp_list_posts declares in its
		// outputSchema (#906).
		$data = array(
			'id'             => (int) $post->ID,
			// Decoded for agents (#903) — see Mcpwp_Agent_Text. post_title is
			// stored with entities already encoded ("Crumb &amp; Co"); the
			// stored value and any theme rendering are untouched.
			'title'          => (string) Mcpwp_Agent_Text::decode( $post->post_title ),
			'slug'           => (string) $post->post_name,
			'status'         => (string) $post->post_status,
			'date'           => (string) $post->post_date,
			'date_gmt'       => (string) $post->post_date_gmt,
			'modified'       => (string) $post->post_modified,
			'modified_gmt'   => (string) $post->post_modified_gmt,
			'url'            => (string) get_permalink( $post ),
			'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
			'author'         => array(
				'id'   => (int) $post->post_author,
				'name' => (string) get_the_author_meta( 'display_name', $post->post_author ),
			),
			'featured_image' => $this->get_featured_image( $post->ID ),
			'categories'     => $this->get_post_terms( $post->ID, 'category' ),
			'tags'           => $this->get_post_terms( $post->ID, 'post_tag' ),
		);

		if ( $include_full ) {
			$data['content']    = (string) $post->post_content;
			$data['excerpt']    = (string) $post->post_excerpt;
			$data['word_count'] = (int) str_word_count( wp_strip_all_tags( $post->post_content ) );
		} else {
			$data['excerpt'] = (string) wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 );
		}

		return $data;
	}

	/**
	 * Get featured image data.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Image data or null.
	 */
	protected function get_featured_image( $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			return null;
		}

		$image = wp_get_attachment_image_src( $thumbnail_id, 'full' );

		return array(
			'id'     => $thumbnail_id,
			'url'    => $image ? $image[0] : '',
			'width'  => $image ? $image[1] : 0,
			'height' => $image ? $image[2] : 0,
		);
	}

	/**
	 * Get post terms.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Terms.
	 */
	protected function get_post_terms( $post_id, $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// array_values(): always a JSON array, whatever keys WP handed back.
		return array_values(
			array_map(
				function ( $term ) {
					return array(
						'id'   => (int) $term->term_id,
						'name' => (string) $term->name,
						'slug' => (string) $term->slug,
					);
				},
				(array) $terms
			)
		);
	}

	/**
	 * Filter formatted data to only include requested fields.
	 *
	 * @param array $data   Formatted data.
	 * @param array $fields Requested field names.
	 * @return array Filtered data (always includes 'id').
	 */
	protected function filter_fields( $data, $fields ) {
		$fields[] = 'id';
		$fields   = array_unique( $fields );

		return array_intersect_key( $data, array_flip( $fields ) );
	}
}
