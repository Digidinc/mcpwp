<?php
/**
 * Posts handler
 *
 * @package mumcp
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
	private $allowed_post_types = array( 'post' );

	/**
	 * Validate and return a post type string.
	 *
	 * @param string $type Requested post type.
	 * @return string|WP_Error Sanitized type or error.
	 */
	private function validate_post_type( $type ) {
		$type = sanitize_key( $type );

		if ( ! in_array( $type, $this->allowed_post_types, true ) ) {
			return new WP_Error( 'invalid_post_type', __( 'Only posts are supported by this endpoint.', 'mumega-mcp' ), array( 'status' => 400 ) );
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
		unset( $args['post_type'] );
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

		$args              = wp_parse_args( $args, $defaults );
		$args['post_type'] = 'post';
		if ( 'publish' !== $args['post_status'] && ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id(); }

		// Validate post type if overridden.
		if ( isset( $args['post_type'] ) && 'post' !== $args['post_type'] ) {
			$validated = $this->validate_post_type( $args['post_type'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$args['post_type'] = $validated;
		}

		// Sanitize query arguments.
		$args['posts_per_page'] = absint( $args['posts_per_page'] );
		$args['paged']          = absint( $args['paged'] );

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
			'posts'    => $posts,
			'total'    => $query->found_posts,
			'pages'    => $query->max_num_pages,
			'page'     => $args['paged'],
			'per_page' => $args['posts_per_page'],
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
				__( 'Post not found.', 'mumega-mcp' ),
				array(
					'status' => 404,
					'hint'   => sprintf(
						'Post ID %d not found. Use wp_list_posts to see available posts. If this is a page, use wp_list_pages instead.',
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
		$post_type = 'post';
		if ( ! empty( $data['post_type'] ) ) {
			$validated = $this->validate_post_type( $data['post_type'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$post_type = $validated;
		}

		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
		);

		// Validate the post status.
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $post_data['post_status'], $allowed_statuses, true ) ) {
			$post_data['post_status'] = 'draft';
		}

		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set the post categories.
		if ( ! empty( $data['categories'] ) ) {
			$categories = array_map( 'absint', (array) $data['categories'] );
			wp_set_post_categories( $post_id, $categories );
		}

		// Set the post tags.
		if ( ! empty( $data['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', (array) $data['tags'] );
			wp_set_post_tags( $post_id, $tags );
		}

		// Set the featured image.
		if ( ! empty( $data['featured_image'] ) ) {
			set_post_thumbnail( $post_id, absint( $data['featured_image'] ) );
		}

		return $this->get_post( $post_id );
	}

	/**
	 * Update a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Post data.
	 * @return array|WP_Error Updated post data or error.
	 */
	public function update_post( $post_id, $data ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post || 'page' === $post->post_type || is_wp_error( $this->validate_post_type( $post->post_type ) ) ) {
			return new WP_Error(
				'not_found',
				__( 'Post not found.', 'mumega-mcp' ),
				array(
					'status' => 404,
					'hint'   => sprintf(
						'Post ID %d not found. Use wp_list_posts to see available posts. If this is a page, use wp_update_page instead.',
						absint( $post_id )
					),
				)
			);
		}

		$post_data = array( 'ID' => $post_id );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['status'] ) ) {
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'trash' );
			$status           = sanitize_key( $data['status'] );
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

		// Update the post categories.
		if ( isset( $data['categories'] ) ) {
			$categories = array_map( 'absint', (array) $data['categories'] );
			wp_set_post_categories( $post_id, $categories );
		}

		// Update the post tags.
		if ( isset( $data['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', (array) $data['tags'] );
			wp_set_post_tags( $post_id, $tags );
		}

		// Update the featured image.
		if ( isset( $data['featured_image'] ) ) {
			if ( empty( $data['featured_image'] ) ) {
				delete_post_thumbnail( $post_id );
			} else {
				set_post_thumbnail( $post_id, absint( $data['featured_image'] ) );
			}
		}

		return $this->get_post( $post_id );
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
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'date'           => $post->post_date,
			'date_gmt'       => $post->post_date_gmt,
			'modified'       => $post->post_modified,
			'modified_gmt'   => $post->post_modified_gmt,
			'url'            => get_permalink( $post ),
			'author'         => array(
				'id'   => (int) $post->post_author,
				'name' => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'featured_image' => $this->get_featured_image( $post->ID ),
			'categories'     => $this->get_post_terms( $post->ID, 'category' ),
			'tags'           => $this->get_post_terms( $post->ID, 'post_tag' ),
		);
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$data['edit_url'] = get_edit_post_link( $post->ID, 'raw' ); }

		if ( $include_full ) {
			$data['content']    = $post->post_content;
			$data['excerpt']    = $post->post_excerpt;
			$data['word_count'] = str_word_count( wp_strip_all_tags( $post->post_content ) );
		} else {
			$data['excerpt'] = wp_trim_words( $post->post_excerpt ? $post->post_excerpt : $post->post_content, 30 );
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
