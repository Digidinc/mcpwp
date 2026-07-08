<?php
/**
 * Pro-tier tool definitions — network category group.
 *
 * Carved verbatim from Mcpwp_MCP_Pro_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Network_Trait {

	/**
	 * @return array
	 */
	private function get_multisite_pro_tools() {
		$pro_tools = array();
		// Multisite tools (only exposed on multisite installations).
		if ( is_multisite() ) {
			$pro_tools[] = $this->define_tool(
				'wp_network_sites',
				'List all sites in the WordPress multisite network with their status, plugin activation state, and API key availability.',
				array(
					'per_page' => array(
						'type'        => 'number',
						'description' => 'Number of sites to return (default 50, max 200)',
						'default'     => 50,
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search term to filter sites by name or URL',
					),
				)
			);

			$pro_tools[] = $this->define_tool(
				'wp_network_switch',
				'Get MCP connection details for a specific site in the multisite network. Returns the MCP endpoint URL and API key status so you can connect to that site.',
				array(
					'blog_id' => array(
						'type'        => 'number',
						'description' => 'Blog ID of the site to switch to',
						'required'    => true,
					),
				)
			);

			$pro_tools[] = $this->define_tool(
				'wp_network_stats',
				'Get content statistics across all sites in the multisite network including post, page, and media counts per site.',
				array()
			);
		}

		return $pro_tools;
	}
}
