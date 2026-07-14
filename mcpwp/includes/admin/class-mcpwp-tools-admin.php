<?php
/**
 * Administration of the retained MCP tool inventory.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Render the retained MCP tool inventory in WordPress administration.
 */
class Mcpwp_Tools_Admin {
	const PAGE_SLUG = 'mcpwp-tools';
	/**
	 * Get display metadata for each retained tool category.
	 *
	 * @return array<string,array<string,string>> Category display metadata.
	 */
	public function get_categories() {
		return array(
			'site'     => array(
				'name'        => __( 'Site', 'mumega-mcp' ),
				'description' => __( 'Local site information.', 'mumega-mcp' ),
			),
			'content'  => array(
				'name'        => __( 'Content', 'mumega-mcp' ),
				'description' => __( 'Posts, pages, and drafts.', 'mumega-mcp' ),
			),
			'media'    => array(
				'name'        => __( 'Media', 'mumega-mcp' ),
				'description' => __( 'Local file and base64 uploads.', 'mumega-mcp' ),
			),
			'taxonomy' => array(
				'name'        => __( 'Taxonomy', 'mumega-mcp' ),
				'description' => __( 'Categories and tags.', 'mumega-mcp' ),
			),
		);
	}
	/**
	 * Render the retained MCP tool inventory for authorized administrators.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mumega-mcp' ) ); }
		$registry   = new Mcpwp_MCP_Free_Tools();
		$tools      = $registry->get_tools();
		$categories = $this->get_categories();
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-tools-display.php';
	}
}
