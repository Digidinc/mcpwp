<?php
/** Exact retained MCP tool inventory administration. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Tools_Admin {
	const PAGE_SLUG = 'mcpwp-tools';
	public function enqueue_assets( $hook ) {}
	public function get_categories() {
		return array(
			'site' => array( 'name' => __( 'Site', 'mumega-mcp' ), 'description' => __( 'Local site information.', 'mumega-mcp' ) ),
			'content' => array( 'name' => __( 'Content', 'mumega-mcp' ), 'description' => __( 'Posts, pages, and drafts.', 'mumega-mcp' ) ),
			'media' => array( 'name' => __( 'Media', 'mumega-mcp' ), 'description' => __( 'Local file and base64 uploads.', 'mumega-mcp' ) ),
			'taxonomy' => array( 'name' => __( 'Taxonomy', 'mumega-mcp' ), 'description' => __( 'Categories and tags.', 'mumega-mcp' ) ),
		);
	}
	public function render() {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'mumega-mcp' ) ); }
		$registry = new Mcpwp_MCP_Free_Tools(); $tools = $registry->get_tools(); $categories = $this->get_categories(); $disabled = (array) get_option( 'mcpwp_disabled_tool_categories', array() );
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-tools-display.php';
	}
	public function ajax_toggle_category() {
		check_ajax_referer( 'mcpwp_tools_nonce', 'nonce' );
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) { wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mumega-mcp' ) ), 403 ); }
		$category = sanitize_key( wp_unslash( $_POST['category'] ?? '' ) ); $enabled = rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ?? false ) );
		if ( ! array_key_exists( $category, $this->get_categories() ) ) { wp_send_json_error( array( 'message' => __( 'Unknown category.', 'mumega-mcp' ) ), 400 ); }
		$disabled = (array) get_option( 'mcpwp_disabled_tool_categories', array() );
		$disabled = $enabled ? array_diff( $disabled, array( $category ) ) : array_unique( array_merge( $disabled, array( $category ) ) );
		update_option( 'mcpwp_disabled_tool_categories', array_values( $disabled ), false );
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}
}
