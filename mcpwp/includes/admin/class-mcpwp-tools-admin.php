<?php
/**
 * MCP Tools Admin Page
 *
 * Handles the admin UI for managing MCP tool categories.
 * Allows site owners to enable/disable entire tool categories
 * to reduce noise in the AI model's context.
 *
 * @package mumcp
 * @since   1.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for MCP tool category management.
 */
class Mcpwp_Tools_Admin {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcpwp-tools';

	/**
	 * Category metadata.
	 *
	 * @return array Category slug => display metadata.
	 */
	public static function get_category_meta() {
		return array(
			'content'    => array(
				'name'        => __( 'Content', 'mumcp' ),
				'description' => __( 'Posts, pages, drafts, search, clone, bulk create', 'mumcp' ),
				'icon'        => 'dashicons-admin-post',
			),
			'media'      => array(
				'name'        => __( 'Media', 'mumcp' ),
				'description' => __( 'Upload files, import from URL, media library, screenshots', 'mumcp' ),
				'icon'        => 'dashicons-admin-media',
			),
			'elementor'  => array(
				'name'        => __( 'Elementor', 'mumcp' ),
				'description' => __( 'Get/set page data, templates, globals, widgets, CSS', 'mumcp' ),
				'icon'        => 'dashicons-editor-kitchensink',
			),
			'seo'        => array(
				'name'        => __( 'SEO', 'mumcp' ),
				'description' => __( 'Meta titles, descriptions, analysis, noindex', 'mumcp' ),
				'icon'        => 'dashicons-search',
			),
			'forms'      => array(
				'name'        => __( 'Forms', 'mumcp' ),
				'description' => __( 'List forms, view entries, plugin detection', 'mumcp' ),
				'icon'        => 'dashicons-feedback',
			),
			'gutenberg'  => array(
				'name'        => __( 'Gutenberg', 'mumcp' ),
				'description' => __( 'Block editor data, block types, patterns', 'mumcp' ),
				'icon'        => 'dashicons-block-default',
			),
			'taxonomy'   => array(
				'name'        => __( 'Taxonomy', 'mumcp' ),
				'description' => __( 'Categories, tags, custom taxonomy terms', 'mumcp' ),
				'icon'        => 'dashicons-tag',
			),
			'site'       => array(
				'name'        => __( 'Site', 'mumcp' ),
				'description' => __( 'Site info, plugins, theme, menus, options, health', 'mumcp' ),
				'icon'        => 'dashicons-admin-site-alt3',
			),
			'webhooks'   => array(
				'name'        => __( 'Webhooks', 'mumcp' ),
				'description' => __( 'Create, update, delete, test webhook subscriptions', 'mumcp' ),
				'icon'        => 'dashicons-rest-api',
			),
			'admin'      => array(
				'name'        => __( 'Admin', 'mumcp' ),
				'description' => __( 'API keys, rate limits, feedback', 'mumcp' ),
				'icon'        => 'dashicons-admin-tools',
			),
			'ai'         => array(
				'name'        => __( 'AI', 'mumcp' ),
				'description' => __( 'Stock photos, image generation, alt text, TTS', 'mumcp' ),
				'icon'        => 'dashicons-lightbulb',
			),
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mumcp' ) );
		}

		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! is_array( $disabled_categories ) ) {
			$disabled_categories = array();
		}

		$category_meta = self::get_category_meta();

		// Count tools per category.
		$tool_counts = $this->count_tools_per_category();

		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-tools-display.php';
	}

	/**
	 * Enqueue admin assets for tools page.
	 *
	 * The canonical 'mcpwp-admin' handle and its localized data (including
	 * PostHog config and toolsNonce) are already enqueued by
	 * Mcpwp_Admin::enqueue_scripts() for all mumcp admin pages.
	 * This method is intentionally a no-op — kept to avoid removing a hook
	 * that may be registered by the loader; it simply returns early.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// All assets handled by Mcpwp_Admin::enqueue_scripts() and enqueue_styles().
	}

	/**
	 * Count tools per category from all registries.
	 *
	 * @return array Category slug => tool count.
	 */
	private function count_tools_per_category() {
		$counts = array();

		// Get all tools through the MCP controller.
		$mcp = new Mcpwp_REST_MCP();
		$all_tools = method_exists( $mcp, 'get_introspection_data' )
			? $mcp->get_introspection_data()
			: array();

		$tools = isset( $all_tools['tools'] ) ? $all_tools['tools'] : array();

		foreach ( $tools as $tool ) {
			$cat = isset( $tool['annotations']['category'] ) ? $tool['annotations']['category'] : 'site';
			if ( ! isset( $counts[ $cat ] ) ) {
				$counts[ $cat ] = 0;
			}
			++$counts[ $cat ];
		}

		return $counts;
	}

	/**
	 * AJAX: Toggle tool category.
	 */
	public function ajax_toggle_category() {
		check_ajax_referer( 'mcpwp_tools_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		$enabled  = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '1';

		if ( empty( $category ) ) {
			wp_send_json_error( array( 'message' => __( 'Category is required.', 'mumcp' ) ) );
		}

		// Validate category slug.
		$valid_categories = array_keys( self::get_category_meta() );
		if ( ! in_array( $category, $valid_categories, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid category.', 'mumcp' ) ) );
		}

		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		if ( '1' === $enabled ) {
			// Enable: remove from disabled list.
			$disabled = array_values( array_diff( $disabled, array( $category ) ) );
		} else {
			// Disable: add to disabled list.
			if ( ! in_array( $category, $disabled, true ) ) {
				$disabled[] = $category;
			}
		}

		update_option( 'mcpwp_disabled_tool_categories', $disabled );

		wp_send_json_success( array(
			'message'  => '1' === $enabled
				? sprintf(
					/* translators: %s: category name */
					__( '%s tools enabled.', 'mumcp' ),
					$category
				)
				: sprintf(
					/* translators: %s: category name */
					__( '%s tools disabled.', 'mumcp' ),
					$category
				),
			'disabled' => $disabled,
		) );
	}
}
