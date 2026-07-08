<?php
/**
 * Pro-tier tool definitions — forms category group.
 *
 * Carved verbatim from Mcpwp_MCP_Pro_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Forms_Trait {

	/**
	 * @return array
	 */
	private function get_forms_pro_tools() {
		$pro_tools = array();
		// Form Tools (Read-only)
		$pro_tools[] = $this->define_tool(
			'wp_list_forms',
			'List all forms from supported plugins (Contact Form 7, WPForms, Gravity Forms)',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_form',
			'Get details and configuration for a specific contact or lead-capture form. Returns fields, settings, and submission counts.',
			array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Form plugin (cf7, wpforms, gravityforms)',
					'required'    => true,
				),
				'id'     => array(
					'type'        => 'number',
					'description' => 'Form ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_form_entries',
			'Get form submission entries from a contact form. Returns lead data, timestamps, and field values.',
			array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Form plugin (cf7, wpforms, gravityforms)',
					'required'    => true,
				),
				'id'     => array(
					'type'        => 'number',
					'description' => 'Form ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_forms_status',
			'Get status of all installed form plugins. Returns plugin name, version, active forms count, and feature availability.',
			array()
		);

		return $pro_tools;
	}
}
