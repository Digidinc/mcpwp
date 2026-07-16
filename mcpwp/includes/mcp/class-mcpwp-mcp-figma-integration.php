<?php
/**
 * Figma MCP Integration
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only Figma design intake for MCP clients.
 */
class Mcpwp_MCP_Figma_Integration extends Mcpwp_Integration {

	/**
	 * Get integration metadata.
	 *
	 * @return array
	 */
	public function get_info() {
		return array(
			'slug'    => 'figma',
			'name'    => 'Figma Design Context',
			'version' => '1.0.0',
		);
	}

	/**
	 * Get capabilities.
	 *
	 * @return array
	 */
	public function get_capabilities() {
		$status = ( new Mcpwp_Figma() )->get_status();

		return array(
			'figma'                  => ! empty( $status['configured'] ),
			'figma_default_file_key' => isset( $status['default_file_key'] ) ? (string) $status['default_file_key'] : '',
		);
	}

	/**
	 * Tool categories.
	 *
	 * @return array
	 */
	public function get_tool_categories() {
		return array(
			'wp_figma_status'             => 'site',
			'wp_get_figma_file'           => 'site',
			'wp_get_figma_node'           => 'site',
			'wp_get_figma_design_tokens'  => 'site',
			'wp_sync_figma_design_tokens' => 'site',
		);
	}

	/**
	 * Tool definitions.
	 *
	 * @return array
	 */
	public function get_tools() {
		return array(
			$this->define_tool(
				'wp_figma_status',
				'Check whether a Figma integration is configured and whether a default file key is available for this site.',
				array()
			),
			$this->define_tool(
				'wp_get_figma_file',
				'Fetch a Figma file summary and outline so you can translate approved design structure into Elementor archetypes, reusable parts, and site briefs.',
				array(
					'file_key' => array(
						'type'        => 'string',
						'description' => 'Figma file key. Optional if a default file key is configured.',
					),
					'depth'    => array(
						'type'        => 'number',
						'description' => 'Outline depth to request from Figma (default 2, max 4).',
						'default'     => 2,
					),
				)
			),
			$this->define_tool(
				'wp_get_figma_node',
				'Fetch a specific Figma frame or node by ID. Use this when an approved design section should become a page archetype or reusable Elementor part.',
				array(
					'node_id'  => array(
						'type'        => 'string',
						'description' => 'Figma node ID such as 12:34.',
						'required'    => true,
					),
					'file_key' => array(
						'type'        => 'string',
						'description' => 'Figma file key. Optional if a default file key is configured.',
					),
					'depth'    => array(
						'type'        => 'number',
						'description' => 'Subtree depth to request from Figma (default 2, max 4).',
						'default'     => 2,
					),
				)
			),
			$this->define_tool(
				'wp_get_figma_design_tokens',
				'Read the cached design tokens (colors + typography) last synced from the Figma file\'s shared styles. Does not call Figma — use wp_sync_figma_design_tokens first to refresh.',
				array()
			),
			$this->define_tool(
				'wp_sync_figma_design_tokens',
				'Pull colors and typography from the configured Figma file\'s shared styles, resolve them to CSS-usable values (hex/rgba colors, font properties), and cache them so a companion WordPress theme can read them as CSS custom properties.',
				array(
					'file_key' => array(
						'type'        => 'string',
						'description' => 'Figma file key. Optional if a default file key is configured.',
					),
				)
			),
		);
	}

	/**
	 * Tool map.
	 *
	 * @return array
	 */
	public function get_tool_map() {
		return array(
			'wp_figma_status'             => array(
				'route'  => '/figma/status',
				'method' => 'GET',
			),
			'wp_get_figma_file'           => array(
				'route'  => '/figma/file',
				'method' => 'GET',
			),
			'wp_get_figma_node'           => array(
				'route'  => '/figma/node',
				'method' => 'GET',
			),
			'wp_get_figma_design_tokens'  => array(
				'route'  => '/figma/design-tokens',
				'method' => 'GET',
			),
			'wp_sync_figma_design_tokens' => array(
				'route'  => '/figma/design-tokens/sync',
				'method' => 'POST',
			),
		);
	}

	/**
	 * Figma calls external APIs.
	 *
	 * @return array
	 */
	protected function get_open_world_tools() {
		return array(
			'wp_figma_status',
			'wp_get_figma_file',
			'wp_get_figma_node',
			'wp_sync_figma_design_tokens',
		);
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		( new Mcpwp_REST_Figma() )->register_routes();
	}
}
