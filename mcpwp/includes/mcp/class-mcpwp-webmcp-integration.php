<?php
/**
 * WebMCP-only tools (#981).
 *
 * Holds wp_propose_content_change: turn a content edit into an approval
 * request instead of a write. Registered through the ordinary
 * mcpwp_integrations seam so it is dispatched by the SAME tools/call
 * chokepoint as every other tool (category gates, write-scope floor for
 * callbacks, spend budget, rate limit, idempotency, analytics) — but its
 * definition and route map only exist while a WebMCP dispatch is running
 * (or while wp-admin builds the WebMCP tool list). An API key, OAuth token
 * or broker token never sees it in tools/list and cannot call it.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for tools that are reachable through WebMCP only.
 */
class Mcpwp_WebMCP_Integration extends Mcpwp_Integration {

	/**
	 * {@inheritDoc}
	 */
	public function get_info() {
		return array(
			'slug'    => 'webmcp',
			'name'    => 'WebMCP (wp-admin)',
			'version' => '1.0.0',
		);
	}

	/**
	 * Unconditional, so the name stays reserved against custom-tool
	 * shadowing even when the tool itself is not exposed.
	 *
	 * @return array
	 */
	public function get_tool_categories() {
		return array(
			Mcpwp_WebMCP::PROPOSE_TOOL => 'content',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tools() {
		if ( ! Mcpwp_WebMCP::exposes_webmcp_only_tools() ) {
			return array();
		}

		return array(
			$this->define_tool(
				Mcpwp_WebMCP::PROPOSE_TOOL,
				'Propose new content for an existing post or page WITHOUT changing it. Creates an approval request (with a diff) that an administrator reviews in MCPWP > Control Room and applies or rejects. Use this for any edit to existing content; nothing on the site changes until a person approves.',
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the post or page to change.',
						'required'    => true,
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'The full proposed post content (replaces the current content when approved).',
						'required'    => true,
					),
					'note'    => array(
						'type'        => 'string',
						'description' => 'Why this change is proposed — shown to the reviewer.',
					),
				)
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tool_map() {
		if ( ! Mcpwp_WebMCP::exposes_webmcp_only_tools() ) {
			return array();
		}

		return array(
			Mcpwp_WebMCP::PROPOSE_TOOL => array(
				'method'   => 'POST',
				'callback' => array( 'Mcpwp_WebMCP', 'propose_content_change' ),
			),
		);
	}

	/**
	 * Proposes, never writes: annotate it as the additive, non-destructive
	 * operation it is. (The name carries no write verb, so the method-based
	 * heuristic would otherwise call a POST callback destructive.)
	 *
	 * @return array
	 */
	protected function get_destructive_tools() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_destructive_for_write_tools() {
		return false;
	}
}
