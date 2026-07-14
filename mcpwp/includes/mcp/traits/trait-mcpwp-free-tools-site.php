<?php
/**
 * Site-tool inventory selection.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Free_Tools_Site_Trait {
	/**
	 * Get the exact retained site-tool names.
	 *
	 * @return string[] Site-tool names.
	 */
	private function mcpwp_site_tool_names() {
		return array( 'wp_site_info' ); }
}
