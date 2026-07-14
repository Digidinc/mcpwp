<?php
/**
 * Content-tool inventory selection.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Free_Tools_Content_Trait {
	/**
	 * Get the exact retained content-tool names.
	 *
	 * @return string[] Content-tool names.
	 */
	private function mcpwp_content_tool_names() {
		return array( 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post', 'wp_list_pages', 'wp_get_page', 'wp_create_page', 'wp_update_page', 'wp_list_drafts', 'wp_delete_draft' ); }
}
