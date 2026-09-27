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
				return $type;
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

		return $type;
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
			'total'       => $query->found_posts,
			'pages'       => $query->max_num_pages,
			'page'        => $args['paged'],
			'per_page'    => $args['posts_per_page'],
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
					'hint'   => sprintf(
						'Post ID %d not found. Use wp_list_posts to see available posts, or wp_search to find content by keyword. If this is a page, use wp_list_pages instead.',
						absint( $post_id )
					),
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

		// Content guardrails (#957): enforced centrally by
		// Mcpwp_Content_Guardrails::on_insert_post_data(), a wp_insert_post_data
		// filter that runs inside the wp_insert_post() call below — not here.
		// See that method's docblock for why.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set Elementor snippet location meta (head, body_start, body_end).
		if ( 'elementor_snippet' === $post_type ) {
			$allowed_locations = array( 'head', 'body_start', 'body_end' );
			$location          = isset( $data['elementor_location'] ) ? sanitize_key( $data['elementor_location'] ) : 'head';
			if ( ! in_array( $location, $allowed_locations, true ) ) {
				$location = 'head';
			}
			update_post_meta( $post_id, '_elementor_location', $location );
			// Elementor Custom Code requires these meta keys to function.
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
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

		$created = $this->get_post( $post_id );
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

		// A snippet's location may change after creation too; refusing on
		// update what create accepts breaks a client round-tripping payloads.
		if ( isset( $data['elementor_location'] ) && 'elementor_snippet' === $post->post_type ) {
			$location = sanitize_key( (string) $data['elementor_location'] );
			if ( in_array( $location, array( 'head', 'body_start', 'body_end' ), true ) ) {
				update_post_meta( $post_id, '_elementor_location', $location );
			}
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
	public function delete_post( $post_id, $force = false ) {
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

		$result = wp_delete_post( $post_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete post.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'message' => $force
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
		$data = array(
			'id'             => $post->ID,
			// Decoded for agents (#903) — see Mcpwp_Agent_Text. post_title is
			// stored with entities already encoded ("Crumb &amp; Co"); the
			// stored value and any theme rendering are untouched.
			'title'          => Mcpwp_Agent_Text::decode( $post->post_title ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'date'           => $post->post_date,
			'date_gmt'       => $post->post_date_gmt,
			'modified'       => $post->post_modified,
			'modified_gmt'   => $post->post_modified_gmt,
			'url'            => get_permalink( $post ),
			'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
			'author'         => array(
				'id'   => (int) $post->post_author,
				'name' => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'featured_image' => $this->get_featured_image( $post->ID ),
			'categories'     => $this->get_post_terms( $post->ID, 'category' ),
			'tags'           => $this->get_post_terms( $post->ID, 'post_tag' ),
		);

		if ( $include_full ) {
			$data['content']    = $post->post_content;
			$data['excerpt']    = $post->post_excerpt;
			$data['word_count'] = str_word_count( wp_strip_all_tags( $post->post_content ) );
		} else {
			$data['excerpt'] = wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 );
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

		return array_map(
			function ( $term ) {
				return array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			},
			$terms
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
