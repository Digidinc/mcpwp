<?php
/**
 * Media-tool inventory selection.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Free_Tools_Media_Trait {
	/**
	 * Get the exact retained media-tool names.
	 *
	 * @return string[] Media-tool names.
	 */
	private function mcpwp_media_tool_names() {
		return array( 'wp_list_media', 'wp_get_media', 'wp_upload_media_b64' ); }
}
