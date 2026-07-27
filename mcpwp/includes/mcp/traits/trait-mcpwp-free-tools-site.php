<?php
/**
 * Free-tier tool definitions — site category group.
 *
 * Carved verbatim from Mcpwp_MCP_Free_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * site free tool providers. Mixed into Mcpwp_MCP_Free_Tools.
 */
trait Mcpwp_Free_Tools_Site_Trait {

	/**
	 * @return array
	 */
	private function get_site_analytics_tools() {
		$tools = array();
		// Site & Analytics
		$tools[] = $this->define_tool(
			'wp_site_info',
			'Get WordPress site information including name, URL, version, theme, active plugins, content counts, and RTL/text direction detection',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_introspect',
			'Get a machine-readable description of this plugin (auth, endpoints, tools, capabilities) so AI clients can self-configure instead of guessing',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_onboard',
			'Get a complete first-connection briefing for this WordPress site. Returns site identity, content inventory, active integrations, available tools grouped by category, site context, recommended first actions, and a quick reference card. Call this first when connecting to a new site.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_list_design_references',
			'List stored design references that models can reuse when building pages, archetypes, and Elementor parts from uploaded screenshots or approved design images.',
			array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Optional text query.',
				),
				'page_intent' => array(
					'type'        => 'string',
					'description' => 'Optional page intent such as landing_page, blog_post, product_page, or service_page.',
				),
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Optional archetype class filter.',
				),
				'style' => array(
					'type'        => 'string',
					'description' => 'Optional style filter.',
				),
				'source_type' => array(
					'type'        => 'string',
					'description' => 'Optional source type filter such as upload, url, figma, or stitch.',
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page.',
					'default'     => 20,
				),
				'page' => array(
					'type'        => 'number',
					'description' => 'Page number.',
					'default'     => 1,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_design_reference',
			'Get one stored design reference, including its image, intent, style, reuse notes, and linked archetypes or parts.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Design reference ID.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_upload_design_reference',
			'Create a reusable design reference from an image (external URL, Base64, or existing media library attachment). Use when a human shares a screenshot or design that should guide future page creation. Retry strategy: if image_url or image_base64 fails, upload the image first with wp_upload_media_from_url or wp_upload_media_b64, then retry with the returned media_id — the media_id path never fails due to image transfer errors.',
			array(
				'title' => array(
					'type'        => 'string',
					'description' => 'Reference title.',
				),
				'media_id' => array(
					'type'        => 'number',
					'description' => 'Existing media library attachment ID. Use this as the fallback if image_url or image_base64 upload fails — upload the image separately first, then pass the resulting ID here.',
				),
				'image_url' => array(
					'type'        => 'string',
					'description' => 'External image URL to import. If this fails, upload via wp_upload_media_from_url and use media_id instead.',
				),
				'image_base64' => array(
					'type'        => 'string',
					'description' => 'Base64-encoded image data. If this fails, upload via wp_upload_media_b64 and use media_id instead.',
				),
				'filename' => array(
					'type'        => 'string',
					'description' => 'Filename when using image_base64 or importing from URL.',
				),
				'page_intent' => array(
					'type'        => 'string',
					'description' => 'Target page type such as landing_page, blog_post, service_page, or product_page.',
				),
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Archetype class the design best maps to.',
				),
				'style' => array(
					'type'        => 'string',
					'description' => 'Style label, for example editorial, premium, showcase, or minimal.',
				),
				'notes' => array(
					'type'        => 'string',
					'description' => 'Freeform design notes.',
				),
				'analysis_summary' => array(
					'type'        => 'string',
					'description' => 'Short summary of what matters in the design.',
				),
				'tags' => array(
					'type'        => 'array',
					'description' => 'Array of short tags.',
					'items'       => array( 'type' => 'string' ),
				),
				'must_keep' => array(
					'type'        => 'array',
					'description' => 'Array of elements that should be preserved.',
					'items'       => array( 'type' => 'string' ),
				),
				'avoid' => array(
					'type'        => 'array',
					'description' => 'Array of design choices to avoid when recreating this reference.',
					'items'       => array( 'type' => 'string' ),
				),
				'section_outline' => array(
					'type'        => 'array',
					'description' => 'Ordered list of sections inferred from the design.',
					'items'       => array( 'type' => 'string' ),
				),
				'source_type' => array(
					'type'        => 'string',
					'description' => 'Optional source type override: upload, url, base64, figma, stitch, manual, or media.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_design_reference',
			'Update metadata for a stored design reference. Use this to refine intent, style, notes, section outline, or linked archetypes after review.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Design reference ID.',
					'required'    => true,
				),
				'title' => array(
					'type'        => 'string',
					'description' => 'Reference title.',
				),
				'page_intent' => array(
					'type'        => 'string',
					'description' => 'Target page type.',
				),
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Archetype class.',
				),
				'style' => array(
					'type'        => 'string',
					'description' => 'Style label.',
				),
				'notes' => array(
					'type'        => 'string',
					'description' => 'Freeform notes.',
				),
				'analysis_summary' => array(
					'type'        => 'string',
					'description' => 'Short analysis summary.',
				),
				'tags' => array(
					'type'        => 'array',
					'description' => 'Array of short tags.',
					'items'       => array( 'type' => 'string' ),
				),
				'must_keep' => array(
					'type'        => 'array',
					'description' => 'Array of design requirements to preserve.',
					'items'       => array( 'type' => 'string' ),
				),
				'avoid' => array(
					'type'        => 'array',
					'description' => 'Array of design constraints to avoid.',
					'items'       => array( 'type' => 'string' ),
				),
				'section_outline' => array(
					'type'        => 'array',
					'description' => 'Ordered section list.',
					'items'       => array( 'type' => 'string' ),
				),
				'linked_archetype_ids' => array(
					'type'        => 'array',
					'description' => 'Array of related Elementor archetype template IDs.',
					'items'       => array( 'type' => 'integer' ),
				),
				'linked_part_ids' => array(
					'type'        => 'array',
					'description' => 'Array of related Elementor part template IDs.',
					'items'       => array( 'type' => 'integer' ),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_analytics',
			'Get site analytics including post counts, page counts, comment counts, and user counts',
			array(
				'days' => array(
					'type'        => 'number',
					'description' => 'Number of days for analytics period',
					'default'     => 30,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_detect_plugins',
			'Detect active plugins and available capabilities (Elementor, WooCommerce, SEO plugins, etc.)',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_get_options',
			'Get WordPress reading options (front page, posts page, and related settings)',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_update_options',
			'Update WordPress reading options (set static homepage, posts page, visibility)',
			array(
				'show_on_front' => array(
					'type'        => 'string',
					'description' => "Reading setting: 'posts' or 'page'",
				),
				'page_on_front' => array(
					'type'        => 'number',
					'description' => 'Front page ID (0 to unset)',
				),
				'page_for_posts' => array(
					'type'        => 'number',
					'description' => 'Posts page ID (0 to unset)',
				),
				'blog_public' => array(
					'type'        => 'boolean',
					'description' => 'Search engine visibility (true to allow indexing)',
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_site_context_tools() {
		$tools = array();
		// Site Context
		$tools[] = $this->define_tool(
			'wp_get_site_context',
			'Get the site context — a master prompt / style guide that defines design rules, header/footer structure, color palette, typography, predefined sections, and page layout guidelines. Always read this first when building or editing pages.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_set_site_context',
			'Set the site context (AI brief). This is a markdown document that tells AI assistants how to build pages for this site: design tokens, header/footer rules, reusable sections, and page structure templates. Included automatically in wp_introspect.',
			array(
				'context' => array(
					'type'        => 'string',
					'description' => 'Markdown text defining site style rules, header/footer structure, predefined sections, color palette, typography, and page layout guidelines',
					'required'    => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_site_memory_tools() {
		$tools = array();
		// Site Memory (#362) — structured key-value memory persisted across AI sessions.
		$tools[] = $this->define_tool(
			'wp_remember',
			'Store a structured memory entry that persists across AI sessions. Use namespaces: identity (brand voice, colors, client name), constraints (rules the AI must follow), history (completed work, decisions), preferences (layout patterns, widget choices), contacts (client POC info).',
			array(
				'namespace' => array(
					'type'        => 'string',
					'description' => 'Memory namespace: identity, constraints, history, preferences, or contacts.',
					'required'    => true,
				),
				'key' => array(
					'type'        => 'string',
					'description' => 'Memory key (snake_case, e.g. brand_voice, primary_color).',
					'required'    => true,
				),
				'value' => array(
					'description' => 'Value to store — string, number, array, or object.',
					'required'    => true,
				),
				'ttl_days' => array(
					'type'        => 'integer',
					'description' => 'Optional TTL in days. 0 = no expiry.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_recall',
			'Retrieve stored memory entries. Pass namespace + key for exact lookup, or namespace + query for keyword search. Leave both empty to return everything.',
			array(
				'namespace' => array(
					'type'        => 'string',
					'description' => 'Namespace filter: identity, constraints, history, preferences, contacts.',
				),
				'key' => array(
					'type'        => 'string',
					'description' => 'Exact key to retrieve (requires namespace).',
				),
				'query' => array(
					'type'        => 'string',
					'description' => 'Keyword to search across keys and values.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_forget',
			'Delete a stored memory entry by namespace and key. Removes a saved rule, preference, or value from site memory.',
			array(
				'namespace' => array(
					'type'        => 'string',
					'description' => 'Namespace: identity, constraints, history, preferences, or contacts.',
					'required'    => true,
				),
				'key' => array(
					'type'        => 'string',
					'description' => 'Key to delete.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_memories',
			'List all stored memory entries, optionally filtered by namespace. Returns grouped structure with all values and timestamps.',
			array(
				'namespace' => array(
					'type'        => 'string',
					'description' => 'Filter to this namespace only.',
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_site_signals_tools() {
		$tools = array();
		// Site Signals (#363) — proactive signal feed.
		$tools[] = $this->define_tool(
			'wp_get_signals',
			'Get the proactive site signal feed — actionable issues WordPress has detected without you asking. Signal types: stale_content (old posts), broken_elementor (invalid page data), missing_featured_image, draft_accumulation, pending_update (plugin updates), seo_issue. Each signal has severity (high/medium/low), entity reference, detail, and action_hint.',
			array(
				'types' => array(
					'type'        => 'string',
					'description' => 'Comma-separated signal types to filter (e.g. "stale_content,missing_featured_image"). Leave empty for all types.',
				),
				'since' => array(
					'type'        => 'string',
					'description' => 'ISO 8601 timestamp — return signals detected after this time.',
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Max results (1-200). Defaults to 50.',
				),
				'refresh' => array(
					'type'        => 'boolean',
					'description' => 'Recompute signals before returning. Adds latency — use sparingly.',
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_site_blueprints_tools() {
		$tools = array();
		// Site Blueprints (#364) — multi-page site structure definitions.
		$tools[] = $this->define_tool(
			'wp_list_site_blueprints',
			'List all available site blueprints — pre-built multi-page site structures for different business types (law firm, restaurant, SaaS, real estate, portfolio) plus any custom blueprints you\'ve saved. Each blueprint defines pages, sections, menus, and site context ready to deploy.',
			array(
				'category'         => array(
					'type'        => 'string',
					'description' => 'Filter by category: professional, hospitality, tech, creative, custom.',
				),
				'include_starters' => array(
					'type'        => 'boolean',
					'description' => 'Include built-in starter blueprints (default true).',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_site_blueprint',
			'Get a single site blueprint by ID, including full page definitions, section types, menus, and site context.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Blueprint ID (e.g. "law-firm", "restaurant", "saas", "real-estate", "portfolio", or a custom ID).',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_create_site_blueprint',
			'Save a new site blueprint — a reusable multi-page site structure. Blueprints can be deployed to any site to create all pages, menus, and site context in one step.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Blueprint name.',
					'required'    => true,
				),
				'pages' => array(
					'description' => 'Array of page definitions. Each: {slug, title, template?, sections: []}.',
					'required'    => true,
				),
				'menus' => array(
					'description' => 'Array of menu definitions. Each: {name, items: [slug1, slug2]}.',
				),
				'site_context' => array(
					'type'        => 'string',
					'description' => 'Site context / AI brief to set when deploying.',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Blueprint description.',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Category for filtering: professional, hospitality, tech, creative, custom.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_deploy_site_blueprint',
			'Deploy a site blueprint: creates all defined pages, navigation menus, and sets site context in one operation. Pages are created as drafts by default. After deployment, use wp_get_blueprint + wp_add_section to build Elementor layouts for each page\'s section types.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Blueprint ID to deploy.',
					'required'    => true,
				),
				'post_status' => array(
					'type'        => 'string',
					'description' => 'Page status after creation: draft (default) or publish.',
				),
				'name_prefix' => array(
					'type'        => 'string',
					'description' => 'Optional prefix for page titles (e.g. client name).',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_extract_site_blueprint',
			'Analyze the current site and generate a blueprint definition from it. Extracts page structure, Elementor section types, navigation menus, and site context. Use save=true to store the result as a reusable custom blueprint.',
			array(
				'save' => array(
					'type'        => 'boolean',
					'description' => 'Save the extracted blueprint as a custom blueprint (default false).',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_content_graph',
			'Get a weighted internal content graph for SEO and internal linking. Returns content nodes, link/taxonomy edges, inbound/outbound counts, hub/rank/freshness signals, menu depth, orphan candidates, headings, and anchor text.',
			array(
				'post_types' => array(
					'type'        => 'string',
					'description' => 'Comma-separated post types to include. Defaults to page,post.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum number of content nodes, 1-500. Defaults to 100.',
				),
				'include_drafts' => array(
					'type'        => 'boolean',
					'description' => 'Include draft/private content nodes. Defaults to false.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_suggest_internal_links',
			'Suggest internal links for a source post or page using the content graph. Returns existing site URLs only and approval-ready diffs without mutating content.',
			array(
				'source_id' => array(
					'type'        => 'number',
					'description' => 'Source post or page ID that needs internal links.',
					'required'    => true,
				),
				'post_types' => array(
					'type'        => 'string',
					'description' => 'Comma-separated post types to include. Defaults to page,post.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum graph nodes to inspect, 1-500. Defaults to 100.',
				),
				'max_suggestions' => array(
					'type'        => 'number',
					'description' => 'Maximum suggestions to return, 1-20. Defaults to 5.',
				),
				'include_drafts' => array(
					'type'        => 'boolean',
					'description' => 'Include draft/private candidates. Defaults to false.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_apply_internal_link',
			'Apply an accepted internal link suggestion using an existing graph target. Creates an approval request by DEFAULT and appends a native Gutenberg Related paragraph. Bypassing approval (approval_required=false) requires an admin-scoped API key — write/read keys are silently routed through approval.',
			array(
				'source_id' => array(
					'type'        => 'number',
					'description' => 'Source post or page ID to update.',
					'required'    => true,
				),
				'target_id' => array(
					'type'        => 'number',
					'description' => 'Existing published target post or page ID from the content graph.',
					'required'    => true,
				),
				'anchor' => array(
					'type'        => 'string',
					'description' => 'Optional link anchor text. Defaults to target title.',
				),
				'approval_required' => array(
					'type'        => 'boolean',
					'description' => 'Defaults to true (an approval request is created). Set false only for admin-scoped keys needing an immediate save; write/read keys sending false are silently gated.',
				),
				'approval_note' => array(
					'type'        => 'string',
					'description' => 'Optional human review note for the pending link insertion.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_validate_internal_links',
			'Validate existing internal links without mutating content. Detects self-links, duplicate targets, weak anchors, missing targets, unpublished targets, and non-canonical URLs.',
			array(
				'post_types' => array(
					'type'        => 'string',
					'description' => 'Comma-separated post types to include. Defaults to page,post.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum graph nodes to inspect, 1-500. Defaults to 100.',
				),
				'include_drafts' => array(
					'type'        => 'boolean',
					'description' => 'Include draft/private source content. Defaults to false.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_validate_seo_readiness',
			'Validate SEO pre-publish readiness for a post or page without mutating content. Checks title, slug, H1, heading order, meta description, image alt text, internal links, indexability, canonical, robots, sitemap, and schema hints.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID to validate.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_validate_structured_data',
			'Validate structured data for a post or page without mutating content. Inventories JSON-LD, microdata, and schema.org hints, reports parse/shape issues, and recommends schema types that match visible WordPress content.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID to validate.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_audit_media_seo',
			'Audit media SEO for a post or page without mutating content. Checks featured image coverage, content image alt text, filenames, dimensions, file size, lazy-loading hints, and duplicate image use.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID to audit.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_seo_audit_site',
			'Run a read-only SEO site audit summary across recent posts and pages. Aggregates SEO readiness, structured data, media SEO, and content quality issues into prioritized URL-level recommendations.',
			array(
				'post_types' => array(
					'type'        => 'string',
					'description' => 'Comma-separated post types to include. Defaults to page,post.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum URLs to audit, 1-50. Defaults to 25.',
				),
				'include_drafts' => array(
					'type'        => 'boolean',
					'description' => 'Include draft/private content. Defaults to false.',
				),
				'store' => array(
					'type'        => 'boolean',
					'description' => 'Store this run and normalized issues. Defaults to false.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_seo_issues',
			'List stored SEO issue records from stored audit runs. Filter by status, severity, category, post ID, or run ID.',
			array(
				'status' => array(
					'type'        => 'string',
					'description' => 'Issue status: open or resolved.',
				),
				'severity' => array(
					'type'        => 'string',
					'description' => 'Severity: error, warning, or info.',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Category such as readiness, structured_data, media, or content_quality.',
				),
				'post_id' => array(
					'type'        => 'number',
					'description' => 'Filter issues for a post/page ID.',
				),
				'run_id' => array(
					'type'        => 'string',
					'description' => 'Filter issues first or last seen in a stored audit run.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum issues to return, 1-200. Defaults to 50.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_run_seo_autofix_plan',
			'Build an approval-safe SEO autofix plan from stored SEO issues. This is read-only: it tells agents which fixes can be prepared, which tool/playbook to use, and why every publish-facing change still needs approval.',
			array(
				'severity' => array(
					'type'        => 'string',
					'description' => 'Filter by severity: error, warning, or info.',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Filter by issue category such as readiness, structured_data, media, or content_quality.',
				),
				'post_id' => array(
					'type'        => 'number',
					'description' => 'Filter issues for a post/page ID.',
				),
				'run_id' => array(
					'type'        => 'string',
					'description' => 'Filter issues first or last seen in a stored audit run.',
				),
				'issue_id' => array(
					'type'        => 'string',
					'description' => 'Plan a single stored issue by ID.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum issues to inspect, 1-200. Defaults to 50.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_import_search_performance',
			'Import provider-neutral search performance rows from Google Search Console, Bing Webmaster Tools, rank trackers, or a manual export. Stores evidence only; does not fetch external APIs or mutate SEO content.',
			array(
				'provider' => array(
					'type'        => 'string',
					'description' => 'Provider slug: google_search_console, bing_webmaster, or manual.',
				),
				'source' => array(
					'type'        => 'string',
					'description' => 'Optional source label such as export filename, date range, or connector name.',
				),
				'rows' => array(
					'type'        => 'array',
					'description' => 'Rows with date, url or page, query, clicks, impressions, ctr, and position.',
					'required'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'date'        => array( 'type' => 'string', 'description' => 'Row date (any parseable date format).' ),
							'url'         => array( 'type' => 'string', 'description' => 'Page URL. Either url or page is required.' ),
							'page'        => array( 'type' => 'string', 'description' => 'Alternate key for the page URL, used if url is absent.' ),
							'query'       => array( 'type' => 'string', 'description' => 'Search query text.' ),
							'clicks'      => array( 'type' => 'integer', 'description' => 'Click count.' ),
							'impressions' => array( 'type' => 'integer', 'description' => 'Impression count.' ),
							'ctr'         => array( 'type' => 'number', 'description' => 'Click-through rate (0-1). Derived from clicks/impressions if omitted.' ),
							'position'    => array( 'type' => 'number', 'description' => 'Average search position.' ),
						),
					),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_seo_trends',
			'Read stored search performance trends from imported Search Console/Bing/manual rows. Returns summary, top queries, top URLs, daily aggregates, and import history as read-only SEO evidence.',
			array(
				'provider' => array(
					'type'        => 'string',
					'description' => 'Optional provider filter.',
				),
				'url' => array(
					'type'        => 'string',
					'description' => 'Optional exact URL filter.',
				),
				'query' => array(
					'type'        => 'string',
					'description' => 'Optional search query contains filter.',
				),
				'days' => array(
					'type'        => 'number',
					'description' => 'Lookback window in days, 1-365. Defaults to 90.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum grouped rows to return, 1-100. Defaults to 20.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_woocommerce_seo_report',
			'Get a read-only WooCommerce SEO intelligence report for products. Inspects product content depth, short descriptions, category evidence, images, price/schema signals, stock review opportunities, and imported search performance evidence.',
			array(
				'status' => array(
					'type'        => 'string',
					'description' => 'Product status: publish, draft, private, or any. Defaults to publish.',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum products to inspect, 1-100. Defaults to 25.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_audit_content_quality',
			'Audit content quality and AI-search citation readiness for a post or page without mutating content. Checks answer depth, summaries, FAQ/question coverage, entity-like names, freshness, trust signals, and reference hints.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID to audit.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_keyword_research',
			'Discover related searches, long-tail keywords, and question clusters for any seed phrase using Google Suggest autocomplete. Keyless and free by default — no API key required, no cost, safe to call repeatedly in an autonomous content-marketing loop. Returns a deduplicated keyword set and grouped questions to fuel SEO topic expansion, content ideas, and blog post planning. Similar to AnswerThePublic. Pass enrich=true to also attach DataForSEO market metrics (search volume, CPC, competition, keyword difficulty) when DataForSEO is configured — that spends DataForSEO credit, so call it explicitly per topic you actually intend to act on, not from inside a loop over every candidate.',
			array(
				'seed' => array(
					'type'        => 'string',
					'description' => 'Seed keyword phrase to expand, e.g. "organic coffee".',
					'required'    => true,
					'minLength'   => 1,
				),
				'hl'   => array(
					'type'        => 'string',
					'description' => 'Language code for Google Suggest, and (when enrich=true) the DataForSEO language_code (default: en).',
				),
				'gl'   => array(
					'type'        => 'string',
					'description' => 'Country code for Google Suggest (default: us). When enrich=true, only "us" currently maps to a DataForSEO location — any other value returns enrichment_reason "locale_not_supported" rather than mismatched market data.',
				),
				'max'  => array(
					'type'        => 'number',
					'description' => 'Overall cap on returned suggestion items, 1-500. Defaults to 100.',
				),
				'enrich' => array(
					'type'        => 'boolean',
					'description' => 'When true and DataForSEO is configured, attach market metrics (search volume, CPC, competition, keyword difficulty) to each result. Spends DataForSEO credit — call explicitly per topic, not in a loop. Defaults to false (keyless, free, autocomplete-only).',
				),
			)
		);

		if ( ! defined( 'MCPWP_WPORG_BUILD' ) ) {
			// Custom CSS tools (not available in WP.org build).
			$tools[] = $this->define_tool(
				'wp_get_custom_css',
				'Get the Additional CSS from the WordPress Customizer. Returns the full CSS string currently applied to the site.',
				array()
			);

			$tools[] = $this->define_tool(
				'wp_set_custom_css',
				'Set or append CSS to the WordPress Customizer Additional CSS. Use mode "append" to add new rules without removing existing ones, or "replace" to overwrite all custom CSS. CSS is applied site-wide immediately.',
				array(
					'css' => array(
						'type'        => 'string',
						'description' => 'CSS code to set or append',
						'required'    => true,
					),
					'mode' => array(
						'type'        => 'string',
						'description' => 'How to apply: "replace" overwrites all CSS, "append" adds to existing (default)',
						'enum'        => array( 'replace', 'append' ),
						'default'     => 'append',
					),
				)
			);

			$tools[] = $this->define_tool(
				'wp_delete_custom_css',
				'Delete all Additional CSS from the WordPress Customizer. Removes all custom CSS rules. Returns the previous CSS length.',
				array()
			);

			$tools[] = $this->define_tool(
				'wp_get_css_length',
				'Get the length and line count of the Additional CSS without returning the full CSS body. Lightweight check to see if custom CSS exists.',
				array()
			);
		} // end if ( ! defined( 'MCPWP_WPORG_BUILD' ) )

		$tools[] = $this->define_tool(
			'wp_get_rendered_html',
			'Fetch the rendered HTML of a page as the browser sees it. Useful for verifying CSS, fonts, meta tags, and actual content rendering. Supports CSS selector extraction (tag, .class, #id). Only same-host URLs allowed (SSRF-safe).',
			array(
				'id'        => array(
					'type'        => 'number',
					'description' => 'Post or page ID to fetch rendered HTML for',
				),
				'url'       => array(
					'type'        => 'string',
					'description' => 'URL to fetch (same-host only). Either id or url is required.',
				),
				'selector'  => array(
					'type'        => 'string',
					'description' => 'CSS selector to extract (e.g. "head", ".my-class", "#main")',
				),
				'max_bytes' => array(
					'type'        => 'number',
					'description' => 'Maximum response size in bytes (default 51200, max 204800)',
					'default'     => 51200,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_rendered_copy',
			'Return the structured visible text of a live page — headings, paragraphs, button labels, list items, link text, and image alt text — extracted from the rendered HTML, not from post_content. This is the ground-truth answer to "what does the live page actually say?" and reflects Elementor, Gutenberg, and SEO plugin output. Use before auditing copy, validating content, or comparing what is stored vs what visitors see.',
			array(
				'id'             => array(
					'type'        => 'number',
					'description' => 'Post or page ID. Either id or url is required.',
				),
				'url'            => array(
					'type'        => 'string',
					'description' => 'URL to fetch (same-host only). Either id or url is required.',
				),
				'include_chrome' => array(
					'type'        => 'boolean',
					'description' => 'Include nav/header/footer text (default false — body content only)',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_menus',
			'List all navigation menus (including unassigned ones) with id, name, slug, and item count',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_list_menu_locations',
			'List all theme menu locations with assigned menu names and IDs. Use to find where navigation menus are displayed.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_setup_menu',
			'Create a menu, add page links, and assign it to a theme menu location',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Menu name',
					'required'    => true,
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'Theme menu location key (e.g., primary)',
				),
				'page_ids' => array(
					'type'        => 'array',
					'description' => 'Array of page IDs to add as menu items',
					'items'       => array( 'type' => 'number' ),
					'default'     => array(),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => 'If true, creates a new menu even if name exists',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_menu_items',
			'List all items in a menu with their titles, URLs, types, and parent/child relationships',
			array(
				'menu_id' => array(
					'type'        => 'number',
					'description' => 'Menu ID to list items for',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_add_menu_item',
			'Add a menu item: custom link (any URL), post type (page, post, product), or taxonomy (category, tag). Supports sub-menus via parent_id.',
			array(
				'menu_id'   => array(
					'type'        => 'number',
					'description' => 'Menu ID to add item to',
					'required'    => true,
				),
				'title'     => array(
					'type'        => 'string',
					'description' => 'Menu item label',
					'required'    => true,
				),
				'type'      => array(
					'type'        => 'string',
					'description' => "Item type: 'custom' (URL link), 'post_type' (page/post/product), or 'taxonomy' (category/tag)",
					'default'     => 'custom',
				),
				'url'       => array(
					'type'        => 'string',
					'description' => 'URL for custom link items',
				),
				'object'    => array(
					'type'        => 'string',
					'description' => 'Object type for post_type/taxonomy items (e.g., page, product, category)',
				),
				'object_id' => array(
					'type'        => 'number',
					'description' => 'Object ID for post_type/taxonomy items',
				),
				'parent_id' => array(
					'type'        => 'number',
					'description' => 'Parent menu item ID to create a sub-menu item',
					'default'     => 0,
				),
				'position'  => array(
					'type'        => 'number',
					'description' => 'Menu order position',
				),
				'classes'   => array(
					'type'        => 'array',
					'description' => 'CSS classes for styling this menu item',
					'items'       => array( 'type' => 'string' ),
				),
				'target'    => array(
					'type'        => 'string',
					'description' => 'Link target: _blank (new tab) or _self (same tab)',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Item description (used as tooltip or subtitle by some themes)',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_menu_item',
			'Update an existing menu item: rename its title, change URL, move to different parent, or reposition. Get menu_id from wp_list_menus; get item_id from wp_list_menu_items. If menu_id is unknown, use wp_update_menu_item_auto (item_id only).',
			array(
				'menu_id'   => array(
					'type'        => 'number',
					'description' => 'Menu ID — get from wp_list_menus',
					'required'    => true,
				),
				'item_id'   => array(
					'type'        => 'number',
					'description' => 'Menu item ID — get from wp_list_menu_items. If menu_id is unknown, use wp_update_menu_item_auto instead.',
					'required'    => true,
				),
				'title'     => array(
					'type'        => 'string',
					'description' => 'New menu item label',
				),
				'url'       => array(
					'type'        => 'string',
					'description' => 'New URL (for custom link items)',
				),
				'parent_id' => array(
					'type'        => 'number',
					'description' => 'New parent menu item ID (0 for top level)',
				),
				'position'  => array(
					'type'        => 'number',
					'description' => 'New menu order position',
				),
				'classes'   => array(
					'type'        => 'array',
					'description' => 'CSS classes for styling this menu item',
					'items'       => array( 'type' => 'string' ),
				),
				'target'    => array(
					'type'        => 'string',
					'description' => 'Link target: _blank (new tab) or _self (same tab)',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Item description (used as tooltip or subtitle by some themes)',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_menu_item',
			'Remove a single item from a WordPress navigation menu by item ID. Use to clean up or restructure navigation.',
			array(
				'menu_id' => array(
					'type'        => 'number',
					'description' => 'Menu ID',
					'required'    => true,
				),
				'item_id' => array(
					'type'        => 'number',
					'description' => 'Menu item ID to delete',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_reorder_menu_items',
			'Reorder and reparent navigation menu items in a single call. Use to restructure menu hierarchy or move items.',
			array(
				'menu_id' => array(
					'type'        => 'number',
					'description' => 'Menu ID',
					'required'    => true,
				),
				'items'   => array(
					'type'        => 'array',
					'description' => 'Array of {id, position, parent_id} objects',
					'required'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array( 'type' => 'integer', 'description' => 'Menu item ID to update.' ),
							'position'  => array( 'type' => 'integer', 'description' => 'New menu order position.' ),
							'parent_id' => array( 'type' => 'integer', 'description' => 'New parent menu item ID (0 for top level).' ),
						),
						'required'   => array( 'id' ),
					),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_menu',
			'Delete an entire navigation menu and all its items. Use when removing or replacing a navigation menu.',
			array(
				'menu_id' => array(
					'type'        => 'number',
					'description' => 'Menu ID to delete',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_assign_menu_location',
			'Assign a menu to a theme menu location without modifying menu items',
			array(
				'menu_id'  => array(
					'type'        => 'number',
					'description' => 'Menu ID to assign',
					'required'    => true,
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'Theme menu location key (e.g., primary, footer)',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_page_template',
			'Change a page template (e.g., default, elementor_header_footer, elementor_canvas)',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Page ID',
					'required'    => true,
				),
				'template' => array(
					'type'        => 'string',
					'description' => 'Template slug (e.g., default, elementor_header_footer, elementor_canvas)',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_page_templates',
			'List all available page templates for the active WordPress theme. Use to find template options before assigning one to a page.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_bulk_find_replace',
			'Search and replace text in Elementor data for a given post or page. Replacement is performed on the decoded element tree (not the raw JSON string) to prevent JSON corruption. Safe to use for URLs and text values.',
			array(
				'id'      => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
				'search'  => array(
					'type'        => 'string',
					'description' => 'Text to search for in Elementor data',
					'required'    => true,
				),
				'replace' => array(
					'type'        => 'string',
					'description' => 'Replacement text',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_kit_css',
			'Read the Elementor Kit global CSS. Returns custom CSS rules applied site-wide via the Elementor Kit settings.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_set_kit_css',
			'Write global CSS to the Elementor kit. Reliable alternative to wp_set_custom_css — bypasses WordPress Customizer output order and child theme cascade issues. Works on any Elementor site without Elementor Pro. Default mode is replace; use append to add without clobbering existing rules. For CSS you plan to update across multiple build passes (e.g. a template library\'s shared styles), prefer wp_upsert_kit_css_block instead — append duplicates content on every retry.',
			array(
				'css'  => array(
					'type'        => 'string',
					'description' => 'CSS to write to the kit global stylesheet',
					'required'    => true,
				),
				'mode' => array(
					'type'        => 'string',
					'enum'        => array( 'replace', 'append' ),
					'description' => 'replace (default) overwrites existing kit CSS; append adds to it',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_kit_css_blocks',
			'List named, hash-tagged Kit CSS blocks managed via wp_upsert_kit_css_block. Returns name/hash/byte count for each — not the CSS body itself, use wp_get_kit_css_block for that.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_get_kit_css_block',
			'Get one named Kit CSS block\'s full CSS body and content hash.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Block name',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_upsert_kit_css_block',
			'Create or update a named, idempotent region inside the Elementor Kit CSS, without touching any other block or any unmanaged CSS elsewhere in the kit. Re-submitting identical CSS is a no-op (reported as "skipped") instead of growing the kit — use this instead of wp_set_kit_css mode=append when the same CSS may be written more than once across build/polish passes. Pass expected_hash (from a prior wp_get_kit_css_block/wp_list_kit_css_blocks call) to fail with a conflict if the block changed since you last read it.',
			array(
				'name'          => array(
					'type'        => 'string',
					'description' => 'Block name — 1-64 lowercase letters, digits, hyphens, or underscores, starting with a letter or digit',
					'required'    => true,
				),
				'css'           => array(
					'type'        => 'string',
					'description' => 'CSS body for this block',
					'required'    => true,
				),
				'expected_hash' => array(
					'type'        => 'string',
					'description' => 'Optional optimistic-concurrency hash from a prior read',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_kit_css_block',
			'Delete a named Kit CSS block. All other CSS in the kit (other blocks and any unmanaged CSS) is left untouched.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Block name',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_media',
			'List media library items with IDs, URLs, titles, alt text, and MIME types. Before uploading an image, search here first — reuse existing media IDs to avoid duplicates. Filter by mime_type "image" to get only images. Use returned IDs with wp_set_elementor, wp_set_featured_image, or wp_upload_design_reference.',
			array(
				'per_page'  => array(
					'type'        => 'number',
					'description' => 'Items per page (1-100)',
					'default'     => 20,
				),
				'page'      => array(
					'type'        => 'number',
					'description' => 'Page number',
					'default'     => 1,
				),
				'mime_type' => array(
					'type'        => 'string',
					'description' => "Filter by MIME type (e.g., 'image', 'image/png', 'video')",
				),
				'search'    => array(
					'type'        => 'string',
					'description' => 'Search media by title or filename',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_content',
			'List content for any post type (e.g., WooCommerce products) with search and pagination',
			array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to list (e.g., product, lp_course)',
					'required'    => true,
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Status filter (publish, draft, any, etc.)',
					'default'     => 'any',
				),
				'search' => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Items per page (1-100)',
					'default'     => 10,
				),
				'page' => array(
					'type'        => 'number',
					'description' => 'Page number',
					'default'     => 1,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_content',
			'Delete a single content item by post type and ID (supports CPT like product)',
			array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type (e.g., product)',
					'required'    => true,
				),
				'id' => array(
					'type'        => 'number',
					'description' => 'Post ID',
					'required'    => true,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Force permanent deletion',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_search',
			'Search posts and pages by query string with pagination and status filters',
			array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Search query',
					'required'    => true,
				),
				'type'  => array(
					'type'        => 'string',
					'description' => 'Content type filter (post, page, any)',
					'default'     => 'any',
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Status filter (publish, draft, pending, private, any)',
					'default'     => 'publish',
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page (1-50)',
					'default'     => 10,
				),
				'page' => array(
					'type'        => 'number',
					'description' => 'Page number',
					'default'     => 1,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_fetch',
			'Fetch a single post or page by its WordPress ID or public URL. Returns content, status, metadata, and edit URL.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID (use id or url)',
				),
				'url' => array(
					'type'        => 'string',
					'description' => 'Canonical URL (use id or url)',
				),
				'type' => array(
					'type'        => 'string',
					'description' => 'Expected type filter (post, page, any)',
					'default'     => 'any',
				),
				'include_content' => array(
					'type'        => 'boolean',
					'description' => 'Include full content in response',
					'default'     => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_theme_info_tools() {
		$tools = array();
		// Theme Info
		$tools[] = $this->define_tool(
			'wp_get_theme_info',
			'Get detailed theme information: name, version, parent theme, block vs classic, Elementor compatibility, and template locations',
			array()
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_flush_permalinks_tools() {
		$tools = array();
		// Flush Permalinks
		$tools[] = $this->define_tool(
			'wp_flush_permalinks',
			'Flush WordPress rewrite rules (equivalent to visiting Settings > Permalinks). Use after creating pages or changing slugs.',
			array()
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_site_health_tools() {
		$tools = array();
		// Site Health
		$tools[] = $this->define_tool(
			'wp_get_site_health',
			'Get a site health snapshot: content counts by status, pages missing SEO metadata, orphan pages not in menus, missing featured images, and active plugins',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_get_site_state',
			'Get the compact AI-first site-state snapshot agents should read before multi-step work. Includes content, graph, SEO, approvals, events, capabilities, and recommended next actions.',
			array(
				'graph_limit' => array(
					'type'        => 'number',
					'description' => 'Maximum content records to inspect for graph health.',
					'default'     => 100,
				),
				'event_limit' => array(
					'type'        => 'number',
					'description' => 'Maximum recent events to include.',
					'default'     => 20,
				),
				'include_drafts' => array(
					'type'        => 'boolean',
					'description' => 'Include draft/private content in graph health.',
					'default'     => false,
				),
				'include_plugins' => array(
					'type'        => 'boolean',
					'description' => 'Include active plugin file names in capability output.',
					'default'     => false,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_guides_workflows_tools() {
		$tools = array();
		// Guides & Workflows
		$tools[] = $this->define_tool(
			'wp_get_guide',
			'Get a detailed guide on a specific topic. Call with no topic to list all available topics. Topics include: onboarding, elementor, seo, menus, media, content, forms, woocommerce, workflows, troubleshooting. Only shows topics relevant to active plugins.',
			array(
				'topic' => array(
					'type'        => 'string',
					'description' => 'Guide topic slug (e.g., "onboarding", "elementor", "woocommerce", "workflows"). Omit to list all available topics.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_workflow',
			'Get a step-by-step workflow template for a common task. Each workflow includes tool names, parameters, and tips. Available workflows include: build_landing_page, build_from_parts_library, build_from_page_archetype, build_product_from_archetype, seo_audit, content_migration, site_redesign, menu_setup, media_management, form_setup.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Workflow name (e.g., "build_from_page_archetype", "build_product_from_archetype")',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_agent_playbook',
			'Get a deterministic agent playbook contract with required reads, tool order, validation gates, approval gates, rollback path, and stop conditions. Call with no name to list playbooks.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Playbook name such as build_gutenberg_page, update_gutenberg_section, seo_audit_triage, internal_link_improvement, or rollback_change. Omit to list all playbooks.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_content_coherence_report',
			'Get a read-only content coherence score and prioritized recommendations across site context, graph, content depth, SEO, approvals, and events.',
			array()
		);
		return $tools;
	}
}
