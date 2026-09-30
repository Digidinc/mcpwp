<?php
/**
 * Error Hints
 *
 * Enhances WP_Error responses with actionable hints and guide references
 * to help AI models learn from errors and self-correct.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides actionable error hints for AI clients.
 *
 * When an AI model makes a mistake, the error message alone is often
 * insufficient for self-correction. This class adds contextual hints
 * and optional guide references so the AI can understand what went wrong
 * and how to fix it.
 */
class Mcpwp_Error_Hints {

	/**
	 * Enhance an existing WP_Error with hint and guide data.
	 *
	 * Looks up the error code in the hint map and merges hint/guide
	 * into the error's data array. If no mapping exists, the error
	 * is returned unchanged.
	 *
	 * @param WP_Error $error   The original error.
	 * @param array    $context Optional context for dynamic hint generation.
	 * @return WP_Error The enhanced error (same object, mutated).
	 */
	public static function enhance_error( WP_Error $error, $context = array() ) {
		$code = $error->get_error_code();
		$hint_data = self::get_hint_for_code( $code, $context );

		if ( empty( $hint_data ) ) {
			return $error;
		}

		// Capture message and data before removing.
		$message       = $error->get_error_message( $code );
		$existing_data = $error->get_error_data( $code );
		if ( ! is_array( $existing_data ) ) {
			$existing_data = array( 'status' => $existing_data );
		}

		// Don't overwrite a hint that was already set explicitly.
		if ( ! empty( $hint_data['hint'] ) && empty( $existing_data['hint'] ) ) {
			$existing_data['hint'] = $hint_data['hint'];
		}

		if ( ! empty( $hint_data['guide'] ) && empty( $existing_data['guide'] ) ) {
			$existing_data['guide'] = $hint_data['guide'];
		}

		// Remove existing data and re-add with hints merged.
		$error->remove( $code );
		$error->add( $code, $message ?: '', $existing_data );

		return $error;
	}

	/**
	 * Create a new WP_Error with hint and optional guide.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param string $hint    Actionable hint for AI clients.
	 * @param string $guide   Optional guide topic reference.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error Error with hint data.
	 */
	public static function create_error( $code, $message, $hint, $guide = '', $status = 400 ) {
		$data = array( 'status' => $status );

		if ( '' !== $hint ) {
			$data['hint'] = $hint;
		}

		if ( '' !== $guide ) {
			$data['guide'] = $guide;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Get hint data for an error code.
	 *
	 * @param string $code    Error code.
	 * @param array  $context Dynamic context values (e.g. 'id', 'param', 'key', 'field').
	 * @return array Array with 'hint' and optional 'guide', or empty array.
	 */
	public static function get_hint_for_code( $code, $context = array() ) {
		$map = self::get_hint_map( $context );

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return array();
	}

	/**
	 * Build the error code to hint mapping.
	 *
	 * Hints are generated dynamically based on site capabilities
	 * where relevant. The context array allows callers to inject
	 * error-specific values for placeholder replacement.
	 *
	 * @param array $context Dynamic context values.
	 * @return array Map of error_code => array('hint' => ..., 'guide' => ...).
	 */
	private static function get_hint_map( $context = array() ) {
		$layout_mode  = self::get_elementor_layout_mode();
		$element_type = 'flexbox' === $layout_mode ? 'container' : 'section';
		$seo_plugin   = self::get_active_seo_plugin();

		// Extract context values with defaults.
		$id    = isset( $context['id'] ) ? $context['id'] : '{id}';
		$param = isset( $context['param'] ) ? $context['param'] : '{param}';
		$key   = isset( $context['key'] ) ? $context['key'] : '{key}';
		$field = isset( $context['field'] ) ? $context['field'] : '{field}';

		return array(
			// Auth errors.
			'missing_api_key'     => array(
				'hint'  => 'Include your API key in the X-API-Key header or Authorization: Bearer header. Generate keys in WP Admin > MCPWP > Settings, or call wp_create_api_key if you have admin access.',
				'guide' => '',
			),
			'invalid_api_key'     => array(
				'hint'  => 'The provided API key is not valid. Check for typos or whitespace. Keys start with "mcpwp_". Generate a new key in WP Admin > MCPWP > Settings.',
				'guide' => '',
			),
			'api_not_configured'  => array(
				'hint'  => 'No API keys have been configured yet. The site admin needs to visit WP Admin > MCPWP > Settings to generate an API key.',
				'guide' => '',
			),
			'insufficient_scope'  => array(
				'hint'  => 'This API key does not have the required permission scope. Check the key\'s scopes (read/write/admin) and request an appropriate key from the site admin.',
				'guide' => '',
			),
			'api_user_missing'    => array(
				'hint'  => 'The plugin\'s internal service account is missing. Deactivate and reactivate the MCPWP plugin in WP Admin > Plugins to reprovision it.',
				'guide' => '',
			),
			'permission_denied'   => array(
				'hint'  => 'This API key does not have permission for this action. Check the key\'s role and tool category restrictions, or use an admin-scoped key.',
				'guide' => '',
			),

			// Rate limit errors.
			'rate_limit_exceeded' => array(
				'hint'  => 'Rate limit exceeded. Wait for the Retry-After period indicated in the response headers. To increase limits, the site admin can adjust settings in WP Admin > MCPWP > Settings, or create a key with custom rate_limits.',
				'guide' => '',
			),

			// Content not found.
			'not_found'           => array(
				'hint'  => sprintf( 'The requested resource (ID: %s) was not found. Use wp_list_pages or wp_list_posts to discover available content, or wp_search to find content by keyword.', $id ),
				'guide' => '',
			),
			'page_not_found'      => array(
				'hint'  => sprintf( 'Page ID %s not found. Use wp_list_pages to see available pages, or wp_get_page_by_slug to find by URL slug.', $id ),
				'guide' => '',
			),
			'post_not_found'      => array(
				'hint'  => sprintf( 'Post ID %s not found. Use wp_list_posts to see available posts, or wp_search to find content by keyword.', $id ),
				'guide' => '',
			),

			// Post type errors.
			'invalid_post_type'   => array(
				'hint'  => 'This post type is not supported or does not exist. Use wp_site_info to check available post types, or wp_list_content(post_type=\'...\') for custom post types.',
				'guide' => '',
			),

			// Elementor errors.
			'elementor_not_active' => array(
				'hint'  => 'Elementor is not installed or active on this site. Use wp_introspect to check available page builders. For content editing without Elementor, use wp_update_page with HTML content, or wp_set_blocks for Gutenberg.',
				'guide' => '',
			),
			'invalid_elementor_data' => array(
				'hint'  => sprintf(
					'Invalid Elementor data structure. This site uses %s layout mode. Use \'%s\' as the top-level elType. Each element needs a unique id of exactly 7 alphanumeric characters. Call wp_introspect to verify layout mode.',
					$layout_mode ?: 'unknown',
					$element_type
				),
				'guide' => 'wp_get_guide(topic=\'elementor\')',
			),
			'no_elementor_data'    => array(
				'hint'  => 'This page has no Elementor data. It may use the classic editor or Gutenberg. Use wp_set_elementor to initialize Elementor data, or check the page\'s edit_mode first with wp_get_elementor.',
				'guide' => '',
			),
			'elementor_parse_error' => array(
				'hint'  => 'The Elementor JSON data could not be parsed. Ensure it is a valid JSON array of element objects. Each element needs: id, elType, settings, elements. Use wp_get_elementor on an existing page to see the expected format.',
				'guide' => 'wp_get_guide(topic=\'elementor\')',
			),

			// Widget errors.
			'unknown_widget'       => array(
				'hint'  => 'Unknown Elementor widget type. Use wp_get_elementor_widgets to list all available widget types on this site, or wp_elementor_widget_help(widget=\'...\') for a specific widget\'s schema.',
				'guide' => 'wp_get_guide(topic=\'elementor_widgets\')',
			),

			// Media errors.
			'no_file'              => array(
				'hint'  => 'No file was included in the request. For file uploads, use multipart/form-data with a "file" field. Alternatively, use wp_upload_media_from_url with a URL, or wp_upload_media_b64 with base64 data.',
				'guide' => '',
			),
			'upload_error'         => array(
				'hint'  => 'File upload failed. Supported formats: jpg, png, gif, webp, svg, pdf. Max size depends on server config (typically 2-64MB). Try a smaller file or different format.',
				'guide' => '',
			),
			'missing_url'          => array(
				'hint'  => 'The url parameter is required. Provide a fully qualified URL starting with http:// or https://.',
				'guide' => '',
			),
			'missing_params'       => array(
				'hint'  => sprintf( 'Required parameter \'%s\' is missing. Call wp_introspect with tool="<tool name>" for its full parameter documentation.', $param ),
				'guide' => '',
			),
			'invalid_media'        => array(
				'hint'  => 'The provided media_id does not point to a valid media attachment. Use wp_list_media to see available media items and their IDs.',
				'guide' => '',
			),
			'too_many_files'       => array(
				'hint'  => 'Too many files in a single request. Reduce the batch size. Maximum is typically 20 files per request.',
				'guide' => '',
			),

			// Menu errors.
			'menu_not_found'       => array(
				'hint'  => 'Menu not found. Use wp_list_menus to see all available menus and their IDs.',
				'guide' => '',
			),
			'invalid_location'     => array(
				'hint'  => 'Unknown theme menu location. Use wp_list_menu_locations to see available location keys for the active theme.',
				'guide' => '',
			),
			'item_not_found'       => array(
				'hint'  => 'Menu item not found. Use wp_list_menu_items(menu_id=...) to see items in a specific menu.',
				'guide' => '',
			),
			'missing_title'        => array(
				'hint'  => 'The title parameter is required. Provide a non-empty string for the title.',
				'guide' => '',
			),
			'missing_object'       => array(
				'hint'  => 'For post_type or taxonomy menu items, both "object" (e.g. "page", "post", "category") and "object_id" (numeric ID) are required.',
				'guide' => '',
			),

			// SEO errors.
			'seo_plugin_not_found' => array(
				'hint'  => 'No supported SEO plugin detected. Install and activate Yoast SEO, RankMath, AIOSEO, or SEOPress first. Use wp_detect_plugins to check what\'s available.',
				'guide' => '',
			),
			'invalid_seo_field'    => array(
				'hint'  => sprintf(
					'Unknown SEO field \'%s\'. This site uses %s. Use wp_get_seo on an existing page to see available fields, or call wp_introspect for field documentation.',
					$field,
					$seo_plugin ?: 'no detected SEO plugin'
				),
				'guide' => 'wp_get_guide(topic=\'seo\')',
			),

			// Batch errors.
			'batch_partial_failure' => array(
				'hint'  => 'Some operations in the batch failed. Check individual results for error details. Use smaller batches (5-10 items) for better reliability.',
				'guide' => '',
			),
			'too_many_posts'        => array(
				'hint'  => 'Too many items in batch. Maximum is 50 per request. Split into smaller batches.',
				'guide' => '',
			),
			'too_many_pages'        => array(
				'hint'  => 'Too many items in batch. Maximum is 50 per request. Split into smaller batches.',
				'guide' => '',
			),
			'too_many_ids'          => array(
				'hint'  => 'Too many IDs requested. Maximum is 25 per request. Split into smaller batches.',
				'guide' => '',
			),

			// Validation errors.
			'missing_required_param' => array(
				'hint'  => sprintf( 'Required parameter \'%s\' is missing. Call wp_introspect with tool="<tool name>" for its full parameter documentation.', $param ),
				'guide' => '',
			),
			'invalid_pages'          => array(
				'hint'  => 'The pages parameter must be a non-empty JSON array of page objects, each with at least a "title" field.',
				'guide' => '',
			),
			'invalid_posts'          => array(
				'hint'  => 'The posts parameter must be a non-empty JSON array of post objects, each with at least a "title" field.',
				'guide' => '',
			),
			'missing_urls'           => array(
				'hint'  => 'Provide either a "urls" array of URL strings, or an "items" array of objects with url/title/alt properties.',
				'guide' => '',
			),
			'missing_name'           => array(
				'hint'  => 'The name parameter is required. Provide a non-empty string for the menu name.',
				'guide' => '',
			),
			'missing_location'       => array(
				'hint'  => 'The location parameter is required. Use wp_list_menu_locations to see available theme location keys.',
				'guide' => '',
			),

			// Generic validation.
			'missing_param'          => array(
				'hint'  => sprintf( 'Required parameter \'%s\' is missing. Call wp_introspect with tool="<tool name>" for its full parameter documentation.', $param ),
				'guide' => '',
			),

			// MCP-specific errors.
			'unknown_tool'           => array(
				'hint'  => 'This tool does not exist. Call tools/list to see all available tools, or check for typos in the tool name.',
				'guide' => '',
			),
		);
	}

	/**
	 * Detect the Elementor layout mode (flexbox or classic).
	 *
	 * @return string 'flexbox', 'classic', or empty string if Elementor is not active.
	 */
	private static function get_elementor_layout_mode() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return '';
		}

		if ( class_exists( 'Mcpwp_Elementor_Experiments' ) && Mcpwp_Elementor_Experiments::is_container_active() ) {
			return 'flexbox';
		}

		return 'classic';
	}

	/**
	 * Detect which SEO plugin is active.
	 *
	 * @return string Plugin name or empty string.
	 */
	private static function get_active_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'RankMath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'AIOSEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}

		return '';
	}

	/**
	 * Enhance a WP_Error returned from error_response() in REST controllers.
	 *
	 * Convenience method that accepts the same arguments as error_response()
	 * and returns an enhanced WP_Error with hints.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @param array  $context Dynamic context for hint placeholders.
	 * @return WP_Error Enhanced error.
	 */
	public static function enhanced_error_response( $code, $message, $status = 400, $context = array() ) {
		$error = new WP_Error( $code, $message, array( 'status' => $status ) );
		return self::enhance_error( $error, $context );
	}
}
