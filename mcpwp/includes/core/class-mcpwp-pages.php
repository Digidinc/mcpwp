<?php
/**
 * Pages handler
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle page operations.
 */
class Mcpwp_Pages {

	use Mcpwp_Sanitization;

	/**
	 * List pages.
	 *
	 * @param array $args Query arguments.
	 * @return array Pages data.
	 */
	public function list_pages( $args = array() ) {
		$mcpwp_fields = isset( $args['_mcpwp_fields'] ) ? $args['_mcpwp_fields'] : null;
		unset( $args['_mcpwp_fields'] );

		$defaults = array(
			'post_type'      => 'page',
			'posts_per_page' => 10,
			'paged'          => 1,
			'post_status'    => 'any',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		);

		$args = wp_parse_args( $args, $defaults );
		$args['posts_per_page'] = absint( $args['posts_per_page'] );
		$args['paged'] = absint( $args['paged'] );

		$query = new WP_Query( $args );
		$pages = array();

		$include_full = $mcpwp_fields && ( in_array( 'content', $mcpwp_fields, true ) || in_array( 'word_count', $mcpwp_fields, true ) );

		foreach ( $query->posts as $page ) {
			$formatted = $this->format_page( $page, $include_full );
			if ( $mcpwp_fields ) {
				$formatted = $this->filter_fields( $formatted, $mcpwp_fields );
			}
			$pages[] = $formatted;
		}

		return array(
			'pages'    => $pages,
			'total'    => $query->found_posts,
			'pages_count' => $query->max_num_pages,
			'page'     => $args['paged'],
			'per_page' => $args['posts_per_page'],
		);
	}

	/**
	 * Get a single page.
	 *
	 * @param int $page_id Page ID.
	 * @return array|WP_Error Page data or error.
	 */
	public function get_page( $page_id ) {
		$page = get_post( absint( $page_id ) );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Page not found.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		return $this->format_page( $page, true );
	}

	/**
	 * Create a page.
	 *
	 * @param array $data Page data.
	 * @return array|WP_Error Created page data or error.
	 */
	/**
	 * Fields update_page() acts on; anything else is refused by name (#803).
	 *
	 * @var array
	 */
	public static $update_fields = array( 'title', 'content', 'excerpt', 'status', 'parent', 'menu_order', 'slug', 'template', 'featured_image', 'terms', 'meta' );

	/**
	 * Fields create_page() acts on.
	 *
	 * @var array
	 */
	public static $create_fields = array( 'title', 'content', 'excerpt', 'status', 'parent', 'menu_order', 'slug', 'template', 'featured_image', 'terms', 'meta' );

	public function create_page( $data ) {
		$unsupported = Mcpwp_Posts::unsupported_fields( $data, self::$create_fields );
		if ( is_wp_error( $unsupported ) ) {
			return $unsupported;
		}

		// Validate terms and meta before anything is written (#797).
		$resolved_terms = Mcpwp_Post_Fields::resolve_terms( isset( $data['terms'] ) ? $data['terms'] : null, 'page', $data );
		if ( is_wp_error( $resolved_terms ) ) {
			return $resolved_terms;
		}
		$clean_meta = Mcpwp_Post_Fields::validate_meta( isset( $data['meta'] ) ? $data['meta'] : null, false, 'page' );
		if ( is_wp_error( $clean_meta ) ) {
			return $clean_meta;
		}

		$sanitized_content = array( 'removed' => array() );
		if ( isset( $data['content'] ) ) {
			$sanitized_content = $this->sanitize_content_body( $data['content'] );
		}

		$page_data = array(
			'post_type'    => 'page',
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? $sanitized_content['content'] : '',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
		);

		// Validate status
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $page_data['post_status'], $allowed_statuses, true ) ) {
			$page_data['post_status'] = 'draft';
		}

		// Slug
		if ( isset( $data['slug'] ) ) {
			$page_data['post_name'] = sanitize_title( $data['slug'] );
		}

		// Parent page
		if ( ! empty( $data['parent'] ) ) {
			$page_data['post_parent'] = absint( $data['parent'] );
		}

		// Menu order
		if ( isset( $data['menu_order'] ) ) {
			$page_data['menu_order'] = absint( $data['menu_order'] );
		}

		// Page template
		if ( ! empty( $data['template'] ) ) {
			$page_data['page_template'] = sanitize_text_field( $data['template'] );
		}

		// Content guardrails (#957): ramp cap, thin-content floor, and
		// near-duplicate detection — only ever enforced for a write that is
		// actually publishing; may downgrade post_status to 'draft'.
		$guardrails = class_exists( 'Mcpwp_Content_Guardrails' )
			? Mcpwp_Content_Guardrails::evaluate(
				array(
					'post_type' => 'page',
					'status'    => $page_data['post_status'],
					'title'     => $page_data['post_title'],
					'content'   => $page_data['post_content'],
				)
			)
			: array( 'status' => $page_data['post_status'], 'warnings' => array(), 'guardrails' => array() );
		$page_data['post_status'] = $guardrails['status'];

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Set page template if provided
		if ( ! empty( $data['template'] ) ) {
			update_post_meta( $page_id, '_wp_page_template', sanitize_text_field( $data['template'] ) );
		}

		// Set featured image
		if ( ! empty( $data['featured_image'] ) ) {
			set_post_thumbnail( $page_id, absint( $data['featured_image'] ) );
		}

		$terms_applied = Mcpwp_Post_Fields::apply_terms( $page_id, $resolved_terms );
		$meta_deleted  = array();
		$meta_applied  = Mcpwp_Post_Fields::apply_meta( $page_id, 'page', $clean_meta, $meta_deleted );

		$created = $this->get_page( $page_id );
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
			if ( ! empty( $guardrails['guardrails'] ) ) {
				$created['guardrails'] = $guardrails['guardrails'];
			}
			if ( ! empty( $guardrails['warnings'] ) ) {
				$created['warnings'] = array_merge( isset( $created['warnings'] ) ? (array) $created['warnings'] : array(), $guardrails['warnings'] );
			}
		}

		return $created;
	}

	/**
	 * Update a page.
	 *
	 * @param int   $page_id Page ID.
	 * @param array $data    Page data.
	 * @return array|WP_Error Updated page data or error.
	 */
	public function update_page( $page_id, $data ) {
		$unsupported = Mcpwp_Posts::unsupported_fields( $data, self::$update_fields );
		if ( is_wp_error( $unsupported ) ) {
			return $unsupported;
		}

		$page = get_post( absint( $page_id ) );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Page not found.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		// Snapshot fields that might change for before/after diff.
		// Validate terms and meta before anything is written (#797).
		$resolved_terms = Mcpwp_Post_Fields::resolve_terms( isset( $data['terms'] ) ? $data['terms'] : null, 'page', $data );
		if ( is_wp_error( $resolved_terms ) ) {
			return $resolved_terms;
		}
		$clean_meta = Mcpwp_Post_Fields::validate_meta( isset( $data['meta'] ) ? $data['meta'] : null );
		if ( is_wp_error( $clean_meta ) ) {
			return $clean_meta;
		}

		$before = array(
			'title'      => Mcpwp_Agent_Text::decode( $page->post_title ),
			'excerpt'    => $page->post_excerpt ?? '',
			'status'     => $page->post_status,
			'slug'       => $page->post_name,
			'parent'     => (int) $page->post_parent,
			'menu_order' => (int) $page->menu_order,
			'template'   => get_post_meta( $page_id, '_wp_page_template', true ) ?: 'default',
		);
		if ( isset( $data['content'] ) ) {
			$before['content_chars'] = mb_strlen( $page->post_content );
		}

		$page_data         = array( 'ID' => $page_id );
		$sanitized_content = array( 'removed' => array() );

		if ( isset( $data['title'] ) ) {
			$page_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$sanitized_content         = $this->sanitize_content_body( $data['content'] );
			$page_data['post_content'] = $sanitized_content['content'];
		}

		if ( isset( $data['excerpt'] ) ) {
			$page_data['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'trash' );
			$status = sanitize_key( $data['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$page_data['post_status'] = $status;
			}
		}

		if ( isset( $data['parent'] ) ) {
			$page_data['post_parent'] = absint( $data['parent'] );
		}

		if ( isset( $data['menu_order'] ) ) {
			$page_data['menu_order'] = absint( $data['menu_order'] );
		}

		if ( isset( $data['slug'] ) ) {
			$page_data['post_name'] = sanitize_title( $data['slug'] );
		}

		$requested_slug = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : null;

		$result = wp_update_post( $page_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update template only if explicitly provided and not empty
		if ( array_key_exists( 'template', $data ) && '' !== $data['template'] ) {
			$template = sanitize_text_field( $data['template'] );

			// Validate template exists (allow 'default' or valid theme templates)
			if ( 'default' === $template || '' === $template ) {
				update_post_meta( $page_id, '_wp_page_template', 'default' );
			} else {
				$valid_templates = wp_get_theme()->get_page_templates();
				if ( isset( $valid_templates[ $template ] ) ) {
					update_post_meta( $page_id, '_wp_page_template', $template );
				}
				// Silently ignore invalid templates instead of failing
			}
		}

		// Update featured image
		if ( isset( $data['featured_image'] ) ) {
			if ( empty( $data['featured_image'] ) ) {
				delete_post_thumbnail( $page_id );
			} else {
				set_post_thumbnail( $page_id, absint( $data['featured_image'] ) );
			}
		}

		$terms_applied = Mcpwp_Post_Fields::apply_terms( $page_id, $resolved_terms );
		$meta_deleted  = array();
		$meta_applied  = Mcpwp_Post_Fields::apply_meta( $page_id, 'page', $clean_meta, $meta_deleted );

		$page_result = $this->get_page( $page_id );
		if ( is_array( $page_result ) ) {
			if ( ! empty( $terms_applied ) ) {
				$page_result['terms'] = $terms_applied;
			}
			if ( ! empty( $meta_applied ) ) {
				$page_result['meta_applied'] = $meta_applied;
			}
			if ( ! empty( $meta_deleted ) ) {
				$page_result['meta_deleted'] = $meta_deleted;
			}
			if ( ! empty( $sanitized_content['removed'] ) ) {
				$page_result['sanitized'] = $sanitized_content['removed'];
			}
		}

		// Warn when WordPress silently rewrote the requested slug (uniqueness collision,
		// e.g. auto-drafts or trashed posts already hold the slug → WP appends -2, -3...).
		if ( null !== $requested_slug && is_array( $page_result ) && isset( $page_result['slug'] ) ) {
			$stored_slug = $page_result['slug'];
			if ( $stored_slug !== $requested_slug ) {
				$page_result['slug_warning'] = sprintf(
					/* translators: 1: requested slug, 2: actual stored slug */
					__( 'Requested slug "%1$s" was not stored — WordPress rewrote it to "%2$s" due to a collision with another post. To force the slug, permanently delete any trashed or auto-draft posts that hold "%1$s" and then re-save.', 'mcpwp' ),
					$requested_slug,
					$stored_slug
				);
			}
		}

		// Build before/after change summary.
		if ( is_array( $page_result ) ) {
			$after = array(
				'title'      => $page_result['title'],
				'excerpt'    => $page_result['excerpt'],
				'status'     => $page_result['status'],
				'slug'       => $page_result['slug'],
				'parent'     => (int) $page_result['parent'],
				'menu_order' => (int) $page_result['menu_order'],
				'template'   => $page_result['template'],
			);
			if ( isset( $before['content_chars'] ) ) {
				$updated_post         = get_post( $page_id );
				$after['content_chars'] = mb_strlen( $updated_post->post_content );
			}

			$changes   = array();
			$unchanged = array();
			foreach ( $before as $field => $old_val ) {
				$new_val = isset( $after[ $field ] ) ? $after[ $field ] : null;
				if ( $new_val !== $old_val ) {
					$changes[] = array(
						'field'  => $field,
						'before' => $old_val,
						'after'  => $new_val,
					);
				} else {
					$unchanged[] = $field;
				}
			}

			$page_result['changes']   = $changes;
			$page_result['unchanged'] = $unchanged;
		}

		return $page_result;
	}

	/**
	 * Delete a page.
	 *
	 * @param int  $page_id Page ID.
	 * @param bool $force   Force permanent deletion (bypass trash).
	 * @return bool|WP_Error True on success or error.
	 */
	public function delete_page( $page_id, $force = false ) {
		$page = get_post( absint( $page_id ) );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Page not found.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		if ( $force ) {
			$result = wp_delete_post( $page_id, true );
		} else {
			$result = wp_trash_post( $page_id );
		}

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete page.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Format page for API response.
	 *
	 * @param WP_Post $page         Page object.
	 * @param bool    $include_full Include full content.
	 * @return array Formatted page.
	 */
	protected function format_page( $page, $include_full = false ) {
		$template = get_post_meta( $page->ID, '_wp_page_template', true );

		$data = array(
			'id'           => $page->ID,
			// Decoded for agents (#903) — see Mcpwp_Agent_Text. The stored
			// value and any theme rendering are untouched.
			'title'        => Mcpwp_Agent_Text::decode( $page->post_title ),
			'excerpt'      => $page->post_excerpt ?? '',
			'slug'         => $page->post_name,
			'status'       => $page->post_status,
			'date'         => $page->post_date,
			'modified'     => $page->post_modified,
			'url'          => get_permalink( $page ),
			'edit_url'     => get_edit_post_link( $page->ID, 'raw' ),
			'parent'       => $page->post_parent,
			'menu_order'   => $page->menu_order,
			'template'     => $template ?: 'default',
			'author'       => array(
				'id'   => (int) $page->post_author,
				'name' => get_the_author_meta( 'display_name', $page->post_author ),
			),
			'featured_image' => $this->get_featured_image( $page->ID ),
			'has_elementor'  => $this->has_elementor_data( $page->ID ),
		);

		if ( $include_full ) {
			$data['content']    = $page->post_content;
			$data['word_count'] = str_word_count( wp_strip_all_tags( $page->post_content ) );
		}

		return $data;
	}

	/**
	 * Get featured image data.
	 *
	 * @param int $page_id Page ID.
	 * @return array|null Image data.
	 */
	protected function get_featured_image( $page_id ) {
		$thumbnail_id = get_post_thumbnail_id( $page_id );

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
	 * Check if page has Elementor data.
	 *
	 * @param int $page_id Page ID.
	 * @return bool True if has Elementor data.
	 */
	protected function has_elementor_data( $page_id ) {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return false;
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		return ! empty( $elementor_data );
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
