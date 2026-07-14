<?php
/**
 * Sanitization Trait
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input sanitization and validation functionality.
 */
trait Mcpwp_Sanitization {
	/**
	 * Validate an associative input object against a retained public schema.
	 *
	 * @param array $data Input object.
	 * @param array $schema Map of property schemas.
	 * @param bool  $require_field Require at least one supplied property.
	 * @return array|WP_Error
	 */
	protected function validate_strict_object( $data, $schema, $require_field = false ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json_body', __( 'A JSON object body is required.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		if ( count( $data ) > 32 || $this->input_depth( $data ) > 8 ) {
			return new WP_Error( 'input_too_complex', __( 'The request object exceeds the allowed complexity.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		$unknown = array_diff( array_keys( $data ), array_keys( $schema ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error( 'unknown_parameter', __( 'The request contains an unknown parameter.', 'mumega-mcp' ), array( 'status' => 400, 'parameter' => (string) reset( $unknown ) ) );
		}
		foreach ( $schema as $name => $rules ) {
			if ( ! empty( $rules['required'] ) && ! array_key_exists( $name, $data ) ) {
				return new WP_Error( 'missing_parameter', __( 'A required parameter is missing.', 'mumega-mcp' ), array( 'status' => 400, 'parameter' => $name ) );
			}
			if ( array_key_exists( $name, $data ) ) {
				$result = $this->validate_strict_value( $data[ $name ], $rules, $name );
				if ( is_wp_error( $result ) ) { return $result; }
			}
		}
		if ( $require_field && empty( $data ) ) {
			return new WP_Error( 'empty_update', __( 'At least one mutable field is required.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		return $data;
	}

	/** @return true|WP_Error */
	protected function validate_strict_value( $value, $rules, $name ) {
		$type = isset( $rules['type'] ) ? $rules['type'] : null;
		if ( 'string' === $type ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) { return $this->invalid_parameter( $name ); }
			$length_value = in_array( $name, array( 'title', 'name', 'label' ), true ) ? trim( $value ) : $value;
			$length = strlen( $length_value );
			if ( isset( $rules['minLength'] ) && $length < (int) $rules['minLength'] ) { return $this->invalid_parameter( $name ); }
			if ( isset( $rules['maxLength'] ) && $length > (int) $rules['maxLength'] ) { return $this->invalid_parameter( $name ); }
			if ( isset( $rules['enum'] ) && ! in_array( $value, $rules['enum'], true ) ) { return $this->invalid_parameter( $name ); }
			if ( isset( $rules['pattern'] ) && 1 !== preg_match( '/' . str_replace( '/', '\\/', $rules['pattern'] ) . '/u', $value ) ) { return $this->invalid_parameter( $name ); }
			if ( 'slug' === $name && sanitize_title( $value ) !== $value ) { return $this->invalid_parameter( $name ); }
			if ( 'content' === $name ) {
				$safe = $this->validate_safe_html( $value );
				if ( is_wp_error( $safe ) ) { return $safe; }
			}
			if ( 'template' === $name && ! $this->is_valid_page_template( $value ) ) { return $this->invalid_parameter( $name ); }
			return true;
		}
		if ( 'integer' === $type ) {
			if ( ! is_int( $value ) || ( isset( $rules['minimum'] ) && $value < $rules['minimum'] ) || ( isset( $rules['maximum'] ) && $value > $rules['maximum'] ) ) { return $this->invalid_parameter( $name ); }
			return true;
		}
		if ( 'boolean' === $type ) { return is_bool( $value ) ? true : $this->invalid_parameter( $name ); }
		if ( 'array' === $type ) {
			if ( ! is_array( $value ) || ( ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) { return $this->invalid_parameter( $name ); }
			$count = count( $value );
			if ( ( isset( $rules['minItems'] ) && $count < $rules['minItems'] ) || ( isset( $rules['maxItems'] ) && $count > $rules['maxItems'] ) ) { return $this->invalid_parameter( $name ); }
			if ( ! empty( $rules['uniqueItems'] ) && count( array_unique( array_map( 'serialize', $value ) ) ) !== $count ) { return $this->invalid_parameter( $name ); }
			if ( isset( $rules['items'] ) ) {
				foreach ( $value as $item ) { $result = $this->validate_strict_value( $item, $rules['items'], $name ); if ( is_wp_error( $result ) ) { return $result; } }
			}
			return true;
		}
		return $this->invalid_parameter( $name );
	}

	protected function validate_safe_html( $html ) {
		if ( false !== strpos( $html, '<?' ) || $this->contains_wrapper_html_tags( $html ) || wp_kses_post( $html ) !== $html ) {
			return new WP_Error( 'unsafe_html', __( 'The content contains unsafe HTML.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		return true;
	}

	protected function is_valid_page_template( $template ) {
		if ( 'default' === $template ) { return true; }
		if ( ! function_exists( 'wp_get_theme' ) ) { return false; }
		$templates = wp_get_theme()->get_page_templates();
		return is_array( $templates ) && array_key_exists( $template, $templates );
	}

	protected function input_depth( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) { return 1; }
		$depth = 1;
		foreach ( $value as $child ) { $depth = max( $depth, 1 + $this->input_depth( $child ) ); }
		return $depth;
	}

	protected function invalid_parameter( $name ) {
		return new WP_Error( 'invalid_parameter', __( 'A request parameter is invalid.', 'mumega-mcp' ), array( 'status' => 400, 'parameter' => $name ) );
	}
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
