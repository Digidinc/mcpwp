<?php
/**
 * MCP Meta Tools Registry (#907)
 *
 * `mcpwp_find_tools` and `mcpwp_call` are the two tools that make the `core`
 * tool profile viable: a client capped to a small tools/list (Cursor's
 * 40-tool limit, a fixed token budget) still reaches every tool this site
 * has, by searching for the one it needs and dispatching it by name instead
 * of needing it advertised directly.
 *
 * Neither tool maps to a REST route — Mcpwp_REST_MCP dispatches both
 * in-process (resolve_mcpwp_call() rewrites a `mcpwp_call` message into an
 * ordinary tools/call on the tool it names, before any gate runs; a
 * `mcpwp_find_tools` call is answered directly in handle_tools_call()) — so
 * get_tool_map() is intentionally empty. They still ride the same
 * visibility machinery as every other tool (category toggles, the
 * capability-requirement filter, admin-disabled categories) by being merged
 * into Mcpwp_REST_MCP::get_all_tools()/get_all_tool_categories() exactly
 * like a first-party or custom-registered tool.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for the two MCP discovery/dispatch meta tools.
 */
class Mcpwp_MCP_Meta_Tools extends Mcpwp_MCP_Tool_Registry {

	/**
	 * Category every meta tool belongs to. Kept distinct from the ordinary
	 * content/site/seo/... categories so an admin can disable discovery
	 * (WP Admin > mcpwp > Tools) independently of any content category, and
	 * so a role-restricted key needs it explicitly granted (#874) the same
	 * way it needs any other category.
	 */
	const CATEGORY = 'meta';

	/**
	 * {@inheritDoc}
	 */
	public function get_tools() {
		return array(
			$this->define_tool(
				'mcpwp_find_tools',
				'Search this site\'s full tool catalog by keyword. Use when the tool you need is not in your current tools/list — the "core" profile advertises roughly 25 tools directly, and every other tool stays reachable by searching for it here and dispatching it with mcpwp_call. Returns only tools you are currently allowed to call (category, role, and Pro gating already applied).',
				array(
					'query'    => array(
						'type'        => 'string',
						'description' => 'Keywords to match against each candidate tool\'s name, title, description, and category.',
						'required'    => true,
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Maximum number of matches to return.',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 20,
					),
					'category' => array(
						'type'        => 'string',
						'description' => 'Optional category slug to narrow the search (e.g. "content", "seo", "elementor", "media").',
					),
				)
			),
			$this->define_tool(
				'mcpwp_call',
				'Call any tool by name, including one your current tools/list does not advertise (see mcpwp_find_tools). Dispatches through the exact same path as calling tools/call directly: category toggles, role categories, Pro availability, rate limits, spend weights, idempotency, and output validation are all enforced against the NAMED tool, never against mcpwp_call itself. May not name mcpwp_call or mcpwp_find_tools.',
				array(
					'name'      => array(
						'type'        => 'string',
						'description' => 'The tool name to call, exactly as tools/list or mcpwp_find_tools returned it.',
						'required'    => true,
					),
					'arguments' => array(
						'type'        => 'object',
						'description' => 'Arguments for the named tool, exactly as a direct tools/call would take them.',
					),
				)
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Deliberately empty: neither meta tool maps to a REST route. See the
	 * class docblock for how each is actually dispatched.
	 */
	public function get_tool_map() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tool_categories() {
		return array(
			'mcpwp_find_tools' => self::CATEGORY,
			'mcpwp_call'       => self::CATEGORY,
		);
	}
}
