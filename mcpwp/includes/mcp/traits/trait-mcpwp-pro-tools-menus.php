<?php
/**
 * Pro-tier tool definitions — menus category group.
 *
 * Carved verbatim from Mcpwp_MCP_Pro_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Menus_Trait {

	/**
	 * @return array
	 */
	private function get_menus_pro_pro_tools() {
		$pro_tools = array();
		// Menu Management Tools (Pro)
		$pro_tools[] = $this->define_tool(
			'wp_get_menu',
			'Get a single menu with all items, assigned locations, and metadata',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Menu ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_menu',
			'Create a navigation menu with initial items and optional location assignment',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Menu name',
					'required'    => true,
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'Theme menu location key to assign (e.g., primary)',
				),
				'items' => array(
					'type'        => 'array',
					'description' => 'Initial menu items to add (array of {title, url, type, object, object_id} objects)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_menu',
			'Rename a navigation menu or change its assigned theme location. Use to reorganize site navigation.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Menu ID',
					'required'    => true,
				),
				'name' => array(
					'type'        => 'string',
					'description' => 'New menu name',
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'Theme menu location key to assign',
				),
			)
		);

		return $pro_tools;
	}
}
