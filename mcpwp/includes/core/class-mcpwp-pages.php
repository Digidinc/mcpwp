<?php
/**
 * Pages handler
 *
 * @package mumcp
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
		unset( $args['post_type'] );
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

		$args              = wp_parse_args( $args, $defaults );
		$args['post_type'] = 'page';
		if ( 'publish' !== $args['post_status'] && ! current_user_can( 'edit_others_pages' ) ) {
			$args['author'] = get_current_user_id(); }
		$args['posts_per_page'] = absint( $args['posts_per_page'] );
		$args['paged']          = absint( $args['paged'] );

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
			'pages'       => $pages,
			'total'       => $query->found_posts,
			'pages_count' => $query->max_num_pages,
			'page'        => $args['paged'],
			'per_page'    => $args['posts_per_page'],
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
				__( 'Page not found.', 'mumega-mcp' ),
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
	public function create_page( $data ) {
		$page_data = array(
			'post_type'    => 'page',
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
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

		return $this->get_page( $page_id );
	}

	/**
	 * Update a page.
	 *
	 * @param int   $page_id Page ID.
	 * @param array $data    Page data.
	 * @return array|WP_Error Updated page data or error.
	 */
	public function update_page( $page_id, $data ) {
		$page = get_post( absint( $page_id ) );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Page not found.', 'mumega-mcp' ),
				array( 'status' => 404 )
			);
		}

		$page_data = array( 'ID' => $page_id );

		if ( isset( $data['title'] ) ) {
			$page_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$page_data['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['status'] ) ) {
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'trash' );
			$status           = sanitize_key( $data['status'] );
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

		$page_result = $this->get_page( $page_id );

		// Warn when WordPress silently rewrote the requested slug (uniqueness collision,
		// e.g. auto-drafts or trashed posts already hold the slug → WP appends -2, -3...).
		if ( null !== $requested_slug && is_array( $page_result ) && isset( $page_result['slug'] ) ) {
			$stored_slug = $page_result['slug'];
			if ( $stored_slug !== $requested_slug ) {
				$page_result['slug_warning'] = sprintf(
					/* translators: 1: requested slug, 2: actual stored slug */
					__( 'Requested slug "%1$s" was not stored — WordPress rewrote it to "%2$s" due to a collision with another post. To force the slug, permanently delete any trashed or auto-draft posts that hold "%1$s" and then re-save.', 'mumega-mcp' ),
					$requested_slug,
					$stored_slug
				);
			}
		}

		return $page_result;
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
			'id'             => $page->ID,
			'title'          => $page->post_title,
			'slug'           => $page->post_name,
			'status'         => $page->post_status,
			'date'           => $page->post_date,
			'modified'       => $page->post_modified,
			'url'            => get_permalink( $page ),
			'parent'         => $page->post_parent,
			'menu_order'     => $page->menu_order,
			'template'       => $template ?: 'default',
			'author'         => array(
				'id'   => (int) $page->post_author,
				'name' => get_the_author_meta( 'display_name', $page->post_author ),
			),
			'featured_image' => $this->get_featured_image( $page->ID ),
		);
		if ( current_user_can( 'edit_post', $page->ID ) ) {
			$data['edit_url'] = get_edit_post_link( $page->ID, 'raw' ); }

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
