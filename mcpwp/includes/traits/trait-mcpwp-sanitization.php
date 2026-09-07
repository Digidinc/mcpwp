<?php
/**
 * Sanitization Trait
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input sanitization and validation functionality.
 */
trait Mcpwp_Sanitization {
	/**
	 * Check whether content contains wrapper HTML tags that should never be injected.
	 *
	 * @param string $content Content to scan.
	 * @return bool True when wrapper tags are present.
	 */
	protected function contains_wrapper_html_tags( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		return (bool) preg_match( '/<\\/?\\s*(html|head|body)\\b/i', $content );
	}

	/**
	 * Strip wrapper HTML tags (<html>, <head>, <body>) while preserving inner markup.
	 *
	 * These tags are invalid in injected snippets (e.g. wp_body_open) and can break the page.
	 *
	 * @param string $content Content to sanitize.
	 * @return array{content:string,changed:bool} Sanitized content and whether it changed.
	 */
	protected function strip_wrapper_html_tags( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return array(
				'content' => is_string( $content ) ? $content : '',
				'changed' => false,
			);
		}

		$original = $content;
		$content  = preg_replace( '/<\\/?\\s*(html|head|body)\\b[^>]*>/i', '', $content );

		return array(
			'content' => is_string( $content ) ? $content : '',
			'changed' => $content !== $original,
		);
	}


	/**
	 * Sanitize post data array.
	 *
	 * @param array $data Raw post data.
	 * @return array Sanitized data.
	 */
	protected function sanitize_post_data( $data ) {
		$sanitized = array();

		if ( isset( $data['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$sanitized['content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['excerpt'] ) ) {
			$sanitized['excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$sanitized['status'] = sanitize_key( $data['status'] );
		}

		if ( isset( $data['slug'] ) ) {
			$sanitized['slug'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['featured_image'] ) ) {
			$sanitized['featured_image'] = absint( $data['featured_image'] );
		}

		if ( isset( $data['categories'] ) ) {
			$sanitized['categories'] = array_map( 'absint', (array) $data['categories'] );
		}

		if ( isset( $data['tags'] ) ) {
			$sanitized['tags'] = array_map( 'sanitize_text_field', (array) $data['tags'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize page data array.
	 *
	 * @param array $data Raw page data.
	 * @return array Sanitized data.
	 */
	protected function sanitize_page_data( $data ) {
		$sanitized = $this->sanitize_post_data( $data );

		if ( isset( $data['parent'] ) ) {
			$sanitized['parent'] = absint( $data['parent'] );
		}

		if ( isset( $data['menu_order'] ) ) {
			$sanitized['menu_order'] = absint( $data['menu_order'] );
		}

		if ( isset( $data['template'] ) ) {
			$sanitized['template'] = sanitize_text_field( $data['template'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize query arguments.
	 *
	 * @param array $args Raw arguments.
	 * @return array Sanitized arguments.
	 */
	protected function sanitize_query_args( $args ) {
		$sanitized = array();

		if ( isset( $args['per_page'] ) ) {
			$sanitized['posts_per_page'] = min( absint( $args['per_page'] ), 100 );
		}

		if ( isset( $args['page'] ) ) {
			$sanitized['paged'] = max( 1, absint( $args['page'] ) );
		}

		if ( isset( $args['status'] ) ) {
			$valid_statuses = array( 'publish', 'draft', 'pending', 'private', 'any' );
			$status = sanitize_key( $args['status'] );
			if ( in_array( $status, $valid_statuses, true ) ) {
				$sanitized['post_status'] = $status;
			}
		}

		if ( isset( $args['orderby'] ) ) {
			$valid_orderby = array( 'date', 'modified', 'title', 'ID', 'menu_order', 'rand' );
			$orderby = sanitize_key( $args['orderby'] );
			if ( in_array( $orderby, $valid_orderby, true ) ) {
				$sanitized['orderby'] = $orderby;
			}
		}

		if ( isset( $args['order'] ) ) {
			$sanitized['order'] = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		}

		if ( isset( $args['category'] ) ) {
			$sanitized['cat'] = absint( $args['category'] );
		}

		if ( isset( $args['search'] ) ) {
			$sanitized['s'] = sanitize_text_field( $args['search'] );

			// WP_Query searches post_title, post_excerpt and post_content —
			// never post_name. A caller checking "does a page with this slug
			// already exist?" got an empty result and created a duplicate
			// (#785). Opt simple single-token searches into slug matching.
			if ( class_exists( 'Mcpwp_Slug_Search' )
				&& Mcpwp_Slug_Search::should_match_slug( $sanitized['s'] ) ) {
				$sanitized[ Mcpwp_Slug_Search::QUERY_VAR ] = true;
			}
		}

		if ( isset( $args['post_type'] ) ) {
			$sanitized['post_type'] = sanitize_key( $args['post_type'] );
		}

		if ( isset( $args['ids'] ) && '' !== $args['ids'] ) {
			$sanitized['post__in'] = array_filter( array_map( 'absint', explode( ',', (string) $args['ids'] ) ) );
		}

		if ( isset( $args['fields'] ) && '' !== $args['fields'] ) {
			$sanitized['_mcpwp_fields'] = array_map( 'sanitize_key', explode( ',', (string) $args['fields'] ) );
		}

		// Security clamp: a caller without non-public privilege (read-only key)
		// may only ever enumerate published content. Force post_status='publish'
		// here — the single choke point every list surface built on
		// sanitize_query_args() flows through (pages, posts, Elementor Pro custom
		// code). This overrides BOTH a caller-supplied draft/private/pending/any
		// status AND any non-public model default (the pages model defaults to
		// 'any'), so no such list can leak unpublished titles, URLs, or bodies to
		// an unprivileged key. Write/admin keys and trusted admin-AJAX are
		// unaffected and keep their requested (or default) status set.
		if ( ! ( method_exists( $this, 'requester_can_see_non_public' ) && $this->requester_can_see_non_public() ) ) {
			$sanitized['post_status'] = 'publish';
		}

		return $sanitized;
	}

	/**
	 * Validate post status.
	 *
	 * @param string $status Status to validate.
	 * @param array  $allowed Allowed statuses.
	 * @return string Valid status or default.
	 */
	protected function validate_post_status( $status, $allowed = null ) {
		if ( null === $allowed ) {
			$allowed = array( 'publish', 'draft', 'pending', 'private' );
		}

		$status = sanitize_key( $status );

		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Validate and sanitize URL.
	 *
	 * @param string $url URL to validate.
	 * @return string|false Sanitized URL or false.
	 */
	protected function validate_url( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return false;
		}

		// Check for valid scheme
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		return $url;
	}

	/**
	 * Sanitize JSON string.
	 *
	 * @param string $json JSON string.
	 * @return string|false Sanitized JSON or false on error.
	 */
	protected function sanitize_json( $json ) {
		if ( ! is_string( $json ) ) {
			return false;
		}

		// Try to decode and re-encode to validate
		$decoded = json_decode( $json, true );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		return wp_json_encode( $decoded );
	}

	/**
	 * Normalize a bulk-operation item list into per-item arrays.
	 *
	 * The MCP tool schemas used to advertise these parameters as a bare array
	 * with no item type (#707), so clients reasonably sent an array of JSON
	 * *strings*. Reading `$item['id']` off a string yields nothing, every item
	 * failed its required-field check, and the caller got "0 updated" with an
	 * error that described the wrong problem.
	 *
	 * The schemas now declare object items. This stays as the honest fallback:
	 * decode what is decodable, and for anything else say what was actually
	 * received instead of blaming a missing field.
	 *
	 * @param array  $items    Raw items from the request.
	 * @param string $singular Item label for error messages, e.g. 'page'.
	 * @return array Two keys: 'items' (index-preserving array of arrays) and
	 *               'errors' (list of array{index:int,message:string}).
	 */
	protected function normalize_bulk_items( $items, $singular ) {
		$normalized = array();
		$errors     = array();

		foreach ( (array) $items as $index => $item ) {
			if ( is_array( $item ) ) {
				$normalized[ $index ] = $item;
				continue;
			}

			if ( is_object( $item ) ) {
				$normalized[ $index ] = (array) $item;
				continue;
			}

			if ( is_string( $item ) ) {
				$decoded = json_decode( $item, true );

				if ( is_array( $decoded ) ) {
					$normalized[ $index ] = $decoded;
					continue;
				}
			}

			$errors[] = array(
				'index'   => $index,
				'message' => sprintf(
					/* translators: 1: item label e.g. "page", 2: PHP type received. */
					__( 'Each %1$s must be an object; received %2$s.', 'mcpwp' ),
					$singular,
					gettype( $item )
				),
			);
		}

		return array(
			'items'  => $normalized,
			'errors' => $errors,
		);
	}

	/**
	 * Sanitize filename.
	 *
	 * @param string $filename Filename.
	 * @return string Sanitized filename.
	 */
	protected function sanitize_filename( $filename ) {
		// Remove path components
		$filename = basename( $filename );

		// Use WordPress sanitization
		return sanitize_file_name( $filename );
	}
}
