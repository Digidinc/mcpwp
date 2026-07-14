<?php
/**
 * Taxonomy-tool inventory selection.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Free_Tools_Ops_Trait {
	/**
	 * Get the exact retained taxonomy-tool names.
	 *
	 * @return string[] Taxonomy-tool names.
	 */
	private function mcpwp_taxonomy_tool_names() {
		return array( 'wp_list_categories', 'wp_list_tags', 'wp_create_term' ); }
}
