<?php
/**
 * Free-tier tool definitions — blocks category group.
 *
 * Carved verbatim from Mcpwp_MCP_Free_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * blocks free tool providers. Mixed into Mcpwp_MCP_Free_Tools.
 */
trait Mcpwp_Free_Tools_Blocks_Trait {

	/**
	 * @return array
	 */
	private function get_blocks_tools() {
		$tools = array();
		// Gutenberg Blocks
		$tools[] = $this->define_tool(
			'wp_get_blocks',
			'Get parsed Gutenberg blocks for a post or page. Returns structured block data (blockName, attrs, innerBlocks, innerHTML) and the raw content.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_set_blocks',
			'Set Gutenberg blocks for a post or page. Provide either a blocks array (serialized automatically) or raw block content string. Blocks use WordPress block grammar and are safety-validated by default. An approval request is created by DEFAULT; bypassing it (approval_required=false) requires an admin-scoped API key — write/read keys are silently routed through approval.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
				'blocks' => array(
					'type'        => 'array',
					'description' => 'Array of block objects with blockName, attrs, innerBlocks, and innerContent',
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'Raw block content string (alternative to blocks array)',
				),
				'allow_restricted_blocks' => array(
					'type'        => 'boolean',
					'description' => 'Explicitly allow restricted output such as core/html or inline scripts/styles. Requires approval_note.',
				),
				'approval_required' => array(
					'type'        => 'boolean',
					'description' => 'Defaults to true (an approval request is created). Set false only for admin-scoped keys needing an immediate save; write/read keys sending false are silently gated.',
				),
				'approval_note' => array(
					'type'        => 'string',
					'description' => 'Human approval note explaining why restricted output is necessary, or why a pending approval should be created.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_patch_block_section',
			'Replace one Gutenberg section by block path, anchor, or heading. Creates an approval request by default so agents do not rewrite the full page directly.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
				'selector' => array(
					'type'        => 'object',
					'description' => 'Section selector. Use one of: {"path":"0.innerBlocks.2"}, {"anchor":"section-anchor"}, or {"heading":"Pricing"}.',
					'required'    => true,
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'Replacement section as native Gutenberg block markup.',
				),
				'blocks' => array(
					'type'        => 'array',
					'description' => 'Replacement section as parsed block objects.',
				),
				'approval_required' => array(
					'type'        => 'boolean',
					'description' => 'Defaults to true. Set false only for explicitly approved immediate saves.',
				),
				'approval_note' => array(
					'type'        => 'string',
					'description' => 'Human review note for the pending section patch.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_block_types',
			'List all registered Gutenberg block types with name, title, category, description, and supported features. Use this to discover available blocks before building pages.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_list_block_patterns',
			'List all registered block patterns with name, title, categories, and content. Patterns are pre-built block layouts that can be inserted into pages.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_parse_blocks',
			'Parse raw Gutenberg block markup into a structured block tree. Use before saving to validate that generated content is block-native, not plain HTML/classic content.',
			array(
				'content' => array(
					'type'        => 'string',
					'description' => 'Raw Gutenberg block markup or HTML-like content to parse.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_serialize_blocks',
			'Serialize a structured Gutenberg blocks array into WordPress block markup, then return a parsed round-trip result for validation.',
			array(
				'blocks' => array(
					'type'        => 'array',
					'description' => 'Array of parsed block objects with blockName, attrs, innerBlocks, innerHTML, and innerContent.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_validate_blocks',
			'Validate generated Gutenberg content before saving. Fails whole-page classic HTML, core/html shortcuts, inline script/style tags, and unsafe iframes so agents keep pages editable as native blocks.',
			array(
				'content' => array(
					'type'        => 'string',
					'description' => 'Raw Gutenberg block markup to validate.',
				),
				'blocks' => array(
					'type'        => 'array',
					'description' => 'Array of parsed block objects to serialize and validate.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_block_design_system',
			'Get an agent-facing Gutenberg design system: HTML-like block grammar, composition rules, recommended primitives, recipes, active theme, block types, and patterns.',
			array(
				'include_patterns_content' => array(
					'type'        => 'boolean',
					'description' => 'Include full pattern block markup in the response. Defaults to false to keep context compact.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_approvals',
			'List pending, approved, applied, rejected, or rolled-back approval requests for agent mutations.',
			array(
				'status' => array(
					'type'        => 'string',
					'description' => 'Optional status filter: pending, approved, applied, rejected, or rolled_back.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum requests to return. Defaults to 50.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_approval',
			'Get one approval request with diff metadata and rollback status.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Approval request ID.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_approve_request',
			'Approve a pending mutation request so it can be applied.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Approval request ID.',
					'required'    => true,
				),
				'note' => array(
					'type'        => 'string',
					'description' => 'Optional human review note.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_reject_request',
			'Reject a pending approval request. Prevents the staged change from being applied.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Approval request ID.',
					'required'    => true,
				),
				'note' => array(
					'type'        => 'string',
					'description' => 'Optional human review note.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_apply_approval',
			'Apply an approved mutation request. First slice supports approved Gutenberg post-content updates.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Approval request ID.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_rollback_approval',
			'Roll back an applied mutation request using its stored before-state.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Approval request ID.',
					'required'    => true,
				),
			)
		);

		return $tools;
	}
}
