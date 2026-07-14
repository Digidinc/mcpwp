<?php
/**
 * Core local site-information helpers.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Provide core local site information.
 */
class Mcpwp_Core {
	/**
	 * Get readable local site and plugin metadata.
	 *
	 * @return array<string,mixed> Site and plugin metadata.
	 */
	public function get_site_info() {
		return array(
			'name'     => get_bloginfo( 'name' ),
			'url'      => get_site_url(),
			'language' => get_bloginfo( 'language' ),
			'timezone' => wp_timezone_string(),
			'plugin'   => array(
				'name'    => 'Mumega MCP',
				'version' => MCPWP_VERSION,
			),
		);
	}
}
