<?php
/**
 * MCP tool definitions for the site graph read surface (#943 S2).
 *
 * All three tools are read-only and dispatch through the tool map in
 * class-mcpwp-mcp-free-tools.php to the GET /graph/* REST routes
 * (class-mcpwp-rest-graph.php). They run at read scope: none of their names
 * match the write-tool prefixes in
 * trait-mcpwp-api-auth.php::get_required_scope_for_tool_name(), and none is
 * in its $admin_tools. readOnlyHint follows from the GET route, so no entry
 * in Mcpwp_MCP_Tool_Annotations::ANNOTATION_OVERRIDES is needed. Titles are
 * curated in TITLE_OVERRIDES. Visibility and filter validation live in
 * Mcpwp_Graph_Reader, not here.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free-tier MCP tool definitions for the site graph read surface.
 */
trait Mcpwp_Free_Tools_Graph_Trait {

	/**
	 * @return array
	 */
	private function get_graph_tools() {
		$visibility = ' A read-only key sees published content only: drafts, private, pending, scheduled and trashed posts, and every edge touching one, are left out entirely, and password-protected posts expose no word count or content links.';
		$freshness  = ' Every response carries graph_status (ready, coverage, built_at, backfill, dirty_count) so you know how complete and fresh the graph is.';

		$tools = array();

		$tools[] = $this->define_tool(
			'wp_graph_node',
			'Get one node from the persistent site graph: its stored props, inbound/outbound edge counts by type, the latest value of each signal metric per source, and a 28-day trend per metric. Identify it by node_key (post:123, term:category:5, media:9) or post_id. A hidden or missing node returns not_found.' . $visibility . $freshness,
			array(
				'node_key' => array(
					'type'        => 'string',
					'description' => 'Node key, e.g. post:123, term:category:5, media:9. Provide this or post_id.',
				),
				'post_id'  => array(
					'type'        => 'integer',
					'description' => 'Post ID, shorthand for node_key=post:{post_id}.',
					'minimum'     => 1,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_graph_neighbors',
			'Walk the persistent site graph from one node: its edges (links_to, in_term, parent_of, uses_media, targets_query, ...) plus a minimal row for each neighbor, one or two hops out, in, or both. Paginate with next_cursor. Depth 2 adds the second hop from each page\'s neighbors, capped at 200 nodes in total (truncated=true when the cap is hit).' . $visibility . $freshness,
			array(
				'node_key'  => array(
					'type'        => 'string',
					'description' => 'Center node key. Provide this or post_id.',
				),
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Post ID, shorthand for node_key=post:{post_id}.',
					'minimum'     => 1,
				),
				'direction' => array(
					'type'        => 'string',
					'enum'        => array( 'out', 'in', 'both' ),
					'description' => 'out (default): edges from this node. in: edges into it. both.',
				),
				'types'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Edge types to include, e.g. ["links_to"]. Omit for all types.',
				),
				'depth'     => array(
					'type'        => 'integer',
					'enum'        => array( 1, 2 ),
					'description' => '1 (default) or 2.',
				),
				'limit'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'First-hop edges per page, 1-100. Default 50.',
				),
				'cursor'    => array(
					'type'        => 'string',
					'description' => 'next_cursor from the previous page, with the same other arguments.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_graph_query',
			'Search the persistent site graph with a fixed set of filters, never raw SQL. Find orphans (published posts no live post links to), pages under or over an inbound-link count, nodes with or without a signal metric, pages with no outbound edge of a type (no_edge_type=targets_query finds untargeted pages), stale content (modified_before) or thin content (word_count_lt). Inbound counts and orphan status only count links from published posts. Sort by label, modified, inbound, or a metric name, and paginate with next_cursor. An invalid filter value is refused with invalid_filter.' . $visibility . $freshness,
			array(
				'kind'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Node kinds to include, e.g. ["page","post"], ["term"], ["media"]. Omit for all kinds.',
				),
				'status'          => array(
					'type'        => 'string',
					'description' => 'Post status of post nodes (default publish; "any" for all). A read-only key is always limited to publish. Non-post nodes are not affected.',
				),
				'orphan'          => array(
					'type'        => 'boolean',
					'description' => 'Only published posts with no inbound links_to edge from a published post.',
				),
				'min_inbound'     => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Minimum live inbound edge count (any edge type).',
				),
				'max_inbound'     => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Maximum live inbound edge count (any edge type).',
				),
				'has_metric'      => array(
					'type'        => 'string',
					'description' => 'Only nodes with at least one signal row for this metric, e.g. clicks.',
				),
				'metric'          => array(
					'type'                 => 'object',
					'description'          => 'Filter on the SUM of a metric over the trailing window: {name, source?, op: "gt"|"lt", value, window_days? (1-400, default 28)}.',
					'properties'           => array(
						'name'        => array( 'type' => 'string' ),
						'source'      => array( 'type' => 'string' ),
						'op'          => array(
							'type' => 'string',
							'enum' => array( 'gt', 'lt' ),
						),
						'value'       => array( 'type' => 'number' ),
						'window_days' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 400,
						),
					),
					'additionalProperties' => false,
				),
				'no_edge_type'    => array(
					'type'        => 'string',
					'description' => 'Only nodes with no OUTBOUND edge of this type, e.g. targets_query (untargeted pages).',
				),
				'modified_before' => array(
					'type'        => 'string',
					'description' => 'Only posts last modified before this date (Y-m-d) or ISO 8601 date-time. Treated as UTC when no offset is given.',
				),
				'word_count_lt'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Only nodes with fewer than this many words.',
				),
				'order_by'        => array(
					'type'        => 'string',
					'description' => 'label (default), modified, inbound, or a metric name (latest value).',
				),
				'order_dir'       => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'description' => 'asc (default) or desc.',
				),
				'limit'           => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'description' => 'Results per page, 1-200. Default 50.',
				),
				'cursor'          => array(
					'type'        => 'string',
					'description' => 'next_cursor from the previous page, with the same filters.',
				),
			)
		);

		return $tools;
	}
}
