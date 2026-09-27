<?php
/**
 * Free-tier tool definitions — elementor category group.
 *
 * Carved verbatim from Mcpwp_MCP_Free_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * elementor free tool providers. Mixed into Mcpwp_MCP_Free_Tools.
 */
trait Mcpwp_Free_Tools_Elementor_Trait {

	/**
	 * @return array
	 */
	private function get_elementor_basic_tools() {
		$tools = array();
		// Elementor Basic
		$tools[] = $this->define_tool(
			'wp_get_elementor',
			'Get the full Elementor JSON data for a page or post. Returns sections, columns, widgets, and settings — or, on a site with Elementor Atomic Elements (Editor V4) active, container/leaf elements (e-div-block, e-heading, ...) with props/styles. Check elementor_layout_mode from wp_onboard before editing — it reports "section", "container", or "atomic".',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'strip_defaults' => array(
					'type'        => 'boolean',
					'description' => 'Strip default widget settings to reduce payload size by 70-80%',
				),
				'include_raw' => array(
					'type'        => 'boolean',
					'description' => 'Also include elementor_json (raw JSON string duplicate of elementor_data). Off by default — doubles the payload.',
				),
				'element_id' => array(
					'type'        => 'string',
					'description' => 'Return only the subtree of this one element (id from wp_get_elementor_summary) instead of the whole page. 404 if not found. Omit for the full page.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_elementor_bulk',
			'Get Elementor data for multiple pages in a single request (max 25). Returns results keyed by page ID with any errors listed separately. Useful for site audits and bulk operations.',
			array(
				'ids' => array(
					'type'        => 'string',
					'description' => 'Comma-separated page IDs (max 25), e.g. "10,20,30"',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_elementor_summary',
			'ALWAYS call this first before editing Elementor pages. Returns a lightweight structural summary with element IDs (needed for add/remove/replace/edit), section types, widget types, and key settings. Every element includes its ID and top-level sections include their index. Atomic Elements (e-div-block, e-heading, ...) are summarized too, with their props unwrapped for display. Use this instead of wp_get_elementor when you only need to understand the page structure.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_edit_section',
			'Surgically edit a single Elementor element without downloading/uploading the full page JSON. Find the target by element_id, section_index (0-based), or search criteria (find). Merges settings into the element and returns only the modified element. Much more token-efficient than get+set for small edits.',
			array(
				'id'              => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'element_id'      => array(
					'type'        => 'string',
					'description' => 'Elementor element ID to edit (from summary or previous get)',
				),
				'section_index'   => array(
					'type'        => 'number',
					'description' => 'Top-level section index (0-based)',
				),
				'find'            => array(
					'type'        => 'object',
					'description' => 'Search criteria to find element: {widgetType: "heading", "settings.title": "Old Text"}',
				),
				'settings'        => array(
					'type'        => 'object',
					'description' => 'Settings to merge into the found element',
				),
				'delete_settings' => array(
					'type'        => 'array',
					'description' => 'Setting keys to remove from the element',
					'items'       => array( 'type' => 'string' ),
				),
				'convert'    => array(
					'type'        => 'boolean',
					'description' => 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_edit_widget',
			'Edit a single Elementor widget by ID on a page. Updates widget settings without replacing the full page data. More efficient than get+set for updating text, colors, or other widget properties. Widget must be a widget element, not section or container — or, on Atomic Elements (Editor V4) sites, a leaf content element (e-heading, e-button, e-image, e-svg, e-divider, e-youtube, e-self-hosted-video, e-paragraph). For atomic elements, settings values must be wrapped as {"$$type": "<kind>", "value": <actual>} — call wp_get_widget_schema(widget_type) to see the expected kind per prop.',
			array(
				'id'              => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'widget_id'       => array(
					'type'        => 'string',
					'description' => 'Elementor widget element ID, from wp_get_elementor_summary or a previous get',
					'required'    => true,
				),
				'settings'        => array(
					'type'        => 'object',
					'description' => 'Settings to merge into the widget (e.g. {"title": "New Heading", "align": "center"})',
				),
				'delete_settings' => array(
					'type'        => 'array',
					'description' => 'Setting keys to remove from the widget',
					'items'       => array( 'type' => 'string' ),
				),
			),
			null,
			array(
				array(
					'id' => 42,
					'widget_id' => 'wid0001',
					'settings' => array(
						'title' => 'New Heading',
						'align' => 'center',
					),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_add_section',
			'Add a new section/container to an Elementor page at a specific position. No need to send the full page — just the new section. WORKFLOW: 1) wp_get_elementor_summary → get IDs for positioning 2) Build JSON manually or via wp_get_blueprint 3) wp_add_section(id, element, position) 4) Verify response has meta_verified=true.',
			array(
				'id'  => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'element'  => array(
					'type'        => 'object',
					'description' => 'The section/container element object with elType, elements[], and settings',
					'required'    => true,
				),
				'position' => array(
					'type'        => 'string',
					'description' => 'Where to insert: "start", "end" (default), "before:{element_id}", "after:{element_id}"',
				),
				'convert'    => array(
					'type'        => 'boolean',
					'description' => 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).',
					'default'     => false,
				),
			),
			null,
			array(
				array(
					'id' => 42,
					'element' => array(
						'id' => 'sec0001',
						'elType' => 'section',
						'settings' => array(
						),
						'elements' => array(
							array(
								'id' => 'col0001',
								'elType' => 'column',
								'settings' => array(
									'_column_size' => 100,
								),
								'elements' => array(
									array(
										'id' => 'wid0001',
										'elType' => 'widget',
										'widgetType' => 'heading',
										'settings' => array(
											'title' => 'New Section',
											'header_size' => 'h2',
										),
									),
								),
							),
						),
					),
					'position' => 'end',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_remove_section',
			'Remove a section/container from an Elementor page by its element ID. Searches both top-level and nested elements.',
			array(
				'id'    => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'element_id' => array(
					'type'        => 'string',
					'description' => 'The Elementor element ID to remove',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_replace_section',
			'Replace an entire section/container in an Elementor page by its element ID. The new element takes the position of the old one. Get the target element ID from wp_get_elementor_summary first.',
			array(
				'id'         => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'element_id' => array(
					'type'        => 'string',
					'description' => 'The Elementor element ID to replace',
					'required'    => true,
				),
				'element'    => array(
					'type'        => 'object',
					'description' => 'The replacement section/container element',
					'required'    => true,
				),
				'convert'    => array(
					'type'        => 'boolean',
					'description' => 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).',
					'default'     => false,
				),
			),
			null,
			array(
				array(
					'id' => 42,
					'element_id' => 'sec0001',
					'element' => array(
						'id' => 'sec0002',
						'elType' => 'section',
						'settings' => array(
							'background_background' => 'classic',
							'background_color' => '#FFFFFF',
						),
						'elements' => array(
							array(
								'id' => 'col0002',
								'elType' => 'column',
								'settings' => array(
									'_column_size' => 100,
								),
								'elements' => array(
									array(
										'id' => 'wid0002',
										'elType' => 'widget',
										'widgetType' => 'heading',
										'settings' => array(
											'title' => 'Updated Section',
											'header_size' => 'h2',
										),
									),
								),
							),
						),
					),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_patch_elementor',
			'Apply multiple operations to an Elementor page in a single read-write cycle (max 20 ops). More efficient than calling add/remove/replace individually. Operations: add, remove, replace, settings. Get element IDs from wp_get_elementor_summary first. Pass repair_ids=true (operations optional) to rewrite element ids that are not exactly 7 alphanumeric characters — Elementor Pro\'s Search live results and Taxonomy Filter/Loop Grid AJAX reject them — and repoint the references Elementor resolves (Taxonomy Filter selected_element, Off-canvas links); the response\'s id_repair lists every old -> new id. Like any validated write, it also applies the validator\'s standard fixes, reported in warnings.',
			array(
				'id'         => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'operations' => array(
					'type'        => 'array',
					'description' => 'Array of operations (maximum ' . Mcpwp_Elementor_Basic::MAX_PATCH_OPERATIONS . '). Each: {op: "add"|"remove"|"replace"|"settings", element_id: "...", element: {...}, position: "...", settings: {...}, delete_settings: [...]}. Required unless repair_ids is true.',
					// Machine-readable, so a client can refuse an over-long
					// batch before spending a round trip on it (#785).
					'maxItems'    => Mcpwp_Elementor_Basic::MAX_PATCH_OPERATIONS,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'op'              => array(
								'type'        => 'string',
								'description' => 'Operation type',
								'enum'        => array( 'add', 'remove', 'replace', 'settings' ),
							),
							'element_id'      => array( 'type' => 'string', 'description' => 'Target element ID (required for remove, replace, settings)' ),
							'element'         => array( 'type' => 'object', 'description' => 'New/replacement element object (required for add, replace)' ),
							'position'        => array( 'type' => 'string', 'description' => 'Insert position for add: start, end, before:{id}, after:{id}' ),
							'settings'        => array( 'type' => 'object', 'description' => 'Settings to merge (for settings op)' ),
							'delete_settings' => array(
								'type'        => 'array',
								'description' => 'Setting keys to remove (for settings op)',
								'items'       => array( 'type' => 'string' ),
							),
						),
						'required'   => array( 'op' ),
					),
				),
				'repair_ids' => array(
					'type'        => 'boolean',
					'description' => 'Rewrite every element id on the page that is not exactly 7 alphanumeric characters to a 7-character id derived from the page and the old id (the same page always gets the same ids), after the operations run, and repoint references Elementor resolves (selected_element, Off-canvas dynamic tags and links). Existing ids are otherwise never rewritten, because CSS (.elementor-element-<id>) and other widgets refer to them; the response\'s id_repair lists old -> new ids and possible_references to update by hand.',
					'default'     => false,
				),
				'convert'    => array(
					'type'        => 'boolean',
					'description' => 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).',
					'default'     => false,
				),
			),
			null,
			array(
				array(
					'id' => 42,
					'operations' => array(
						array(
							'op' => 'settings',
							'element_id' => 'wid0001',
							'settings' => array(
								'title' => 'Updated Heading',
							),
						),
						array(
							'op' => 'add',
							'element' => array(
								'id' => 'sec0003',
								'elType' => 'section',
								'settings' => array(
								),
								'elements' => array(
									array(
										'id' => 'col0003',
										'elType' => 'column',
										'settings' => array(
											'_column_size' => 100,
										),
										'elements' => array(
											array(
												'id' => 'wid0003',
												'elType' => 'widget',
												'widgetType' => 'text-editor',
												'settings' => array(
													'editor' => '<p>New paragraph</p>',
												),
											),
										),
									),
								),
							),
							'position' => 'end',
						),
					),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_set_elementor',
			'FULL PAGE REPLACEMENT — overwrites all Elementor data on the page. For surgical edits, prefer wp_add_section, wp_replace_section, wp_edit_section, or wp_edit_widget instead. Use dry_run=true to validate data without saving. For large payloads with HTML content, use elementor_data_base64 (base64-encoded JSON) instead of elementor_data to avoid quoting/escaping issues. Check elementor_layout_mode from wp_site_info first: sending "container" elements to a "section"-mode site, or Atomic Elements (e-div-block, e-heading, ...) to a site without the Atomic Elements experiment active, is rejected up front with a clear error rather than silently losing data. On an "atomic"-mode site, prefer container + classic widgets — they render correctly; Atomic Elements are accepted and saved but not yet confirmed to render, so check render_verified in the response before trusting an atomic write.',
			array(
				'id'             => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'elementor_data' => array(
					'type'        => 'string',
					'description' => 'Elementor JSON data. For large payloads with HTML, prefer elementor_data_base64 instead.',
				),
				'elementor_data_base64' => array(
					'type'        => 'string',
					'description' => 'Base64-encoded Elementor JSON data. Use this instead of elementor_data for large payloads containing HTML or special characters to avoid quoting/escaping corruption. Encode your JSON string with btoa() or base64_encode() before sending.',
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => 'If true, validate only — no changes are saved. Returns warnings and fixes without writing to database.',
					'default'     => false,
				),
				'repair_ids'     => array(
					'type'        => 'boolean',
					'description' => 'Rewrite element ids in the data you send that are not exactly 7 alphanumeric characters (Elementor Pro\'s Search and Taxonomy Filter/Loop Grid AJAX reject them) to 7-character ids derived from the page and the old id, repointing selected_element and Off-canvas references. The response\'s id_repair lists old -> new ids and settings that may still name an old id. Combine with dry_run=true to preview: the same data on the same page always gets the same ids, so the preview is what the write applies.',
					'default'     => false,
				),
				'convert'    => array(
					'type'        => 'boolean',
					'description' => 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).',
					'default'     => false,
				),
			),
			null,
			array(
				array(
					'id' => 42,
					'elementor_data' => '[{"id":"sec0001","elType":"section","settings":{"background_background":"classic","background_color":"#FFFFFF"},"elements":[{"id":"col0001","elType":"column","settings":{"_column_size":100},"elements":[{"id":"wid0001","elType":"widget","widgetType":"heading","settings":{"title":"Welcome","header_size":"h1","align":"center"}}]}]}]',
					'dry_run' => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_elementor_status',
			'Check if Elementor is active and get Elementor status information',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_regenerate_elementor_css',
			'Regenerate Elementor CSS for a specific page or the entire site. Use after updating Elementor data via API to ensure styles are applied. Returns detailed per-page results: regenerated, skipped (with reason), and failed (with error). Use force=true to delete existing CSS files before regenerating.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Page ID to regenerate CSS for. Omit to regenerate all site CSS.',
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Delete existing CSS files before regenerating (default: false)',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_elementor_widgets',
			'Get list of available Elementor widgets. Pass a widget type name to get its full controls schema with valid control keys, types, defaults, and options.',
			array(
				'widget_type' => array(
					'type'        => 'string',
					'description' => 'Widget type name (e.g. "heading", "image", "nav-menu") to get full controls schema. Omit to list all widgets.',
				),
				'search' => array(
					'type'        => 'string',
					'description' => 'List mode only: case-insensitive substring match against widget name, title, and description.',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'List mode only: filter to widgets whose reference category matches exactly.',
				),
				'fields' => array(
					'type'        => 'array',
					'description' => 'List mode only: allowlist of per-widget keys to return (e.g. ["name","category"]). Omit for every field.',
					'items'       => array( 'type' => 'string' ),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_widget_schema',
			'Get the detailed controls schema for a specific Elementor widget type. Returns all valid control keys grouped by tab (content, style, advanced) with types, labels, defaults, and options. Use this to discover valid settings keys before building pages.',
			array(
				'widget_type' => array(
					'type'        => 'string',
					'description' => 'Widget type name (e.g. "heading", "image", "button")',
					'required'    => true,
				),
				'tab' => array(
					'type'        => 'string',
					'description' => 'Return controls from only this tab (content, style, or advanced). Omit for every tab.',
				),
				'fields' => array(
					'type'        => 'array',
					'description' => 'Allowlist of top-level result keys to return (e.g. ["tabs"] or ["description","example","common_mistakes"]). Omit for every field.',
					'items'       => array( 'type' => 'string' ),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_elementor_widget_help',
			'Get complete help for an Elementor widget type: all valid settings keys with types and defaults, example JSON element, and common mistakes to avoid. Works offline (no Elementor installation required). Use this BEFORE building Elementor pages to ensure correct settings keys. If widget_type is not found, returns closest matches.',
			array(
				'widget_type' => array(
					'type'        => 'string',
					'description' => 'Widget type name (e.g. "heading", "image", "button", "icon-box", "price-table"). Use exact Elementor widget type names.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_preview_elementor',
			'Get rendered HTML preview of a page built with Elementor. Useful for reading content or checking layout before edits. Returns HTML output, text summary, and element statistics (sections, columns, widgets, widget types, word count). Use "summary" format (default) to save tokens — returns truncated text + stats without HTML. Use "text" for full text extraction, or "html" for full rendered HTML.',
			array(
				'id'     => array(
					'type'        => 'number',
					'description' => 'Page/post ID to preview',
					'required'    => true,
				),
				'format' => array(
					'type'        => 'string',
					'description' => 'Output format: "summary" (text truncated to 500 chars + stats, no HTML — default, saves tokens), "text" (full plain text extraction), "html" (full rendered HTML + text)',
					'enum'        => array( 'summary', 'text', 'html' ),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_screenshot_url',
			'Take a screenshot of a URL. Uses Cloudflare Browser Rendering (headless Chromium) if configured, otherwise falls back to WordPress mshots — which commonly blocks datacenter-hosted servers outright; when it refuses or errors this now returns an honest error (mshots_rejected / mshots_unavailable) instead of a false "success" pointing at an image that will never render (#804). By default the screenshot is saved to the media library and returned as a URL (base64 payloads overflow AI-client contexts); pass inline=true for raw base64. Set webhook_url for async mode — the webhook fires when the screenshot is ready. Pass widths (max 6, clamped to 320-1920) for a multi-breakpoint sweep in one call — the response reports succeeded/failed counts, and fails outright only if every width failed — or full_page for a full-scroll capture (Cloudflare worker only — mshots has no full-page mode and says so rather than ignoring it). inline cannot be combined with both widths and full_page (memory).',
			array(
				'url' => array(
					'type'        => 'string',
					'description' => 'URL to screenshot',
					'required'    => true,
				),
				'width' => array(
					'type'        => 'number',
					'description' => 'Screenshot width (320-1920). Ignored when widths is set.',
					'default'     => 1280,
				),
				'height' => array(
					'type'        => 'number',
					'description' => 'Screenshot height (240-1440). Applied to every entry when widths is set.',
					'default'     => 960,
				),
				'widths' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'number' ),
					'description' => 'Capture at several viewport widths in one call, e.g. [390,767,1024,1440] for a responsive sweep. Max 6. Returns "screenshots" (one result per width) instead of a single result. Not supported together with webhook_url.',
				),
				'full_page' => array(
					'type'        => 'boolean',
					'description' => 'Capture the full scrollable page height instead of just the viewport. Honored only when the Cloudflare Browser Rendering worker is configured; the mshots fallback has no full-page mode and the response says so instead of silently ignoring the request.',
					'default'     => false,
				),
				'save_to_media' => array(
					'type'        => 'boolean',
					'description' => 'Also save screenshot to WordPress media library',
					'default'     => false,
				),
				'inline' => array(
					'type'        => 'boolean',
					'description' => 'Return raw base64 image data instead of a media-library URL. Off by default — base64 (500KB+) overflows AI-client contexts.',
					'default'     => false,
				),
				'webhook_url' => array(
					'type'        => 'string',
					'description' => 'URL to POST when screenshot is ready. Enables async mode for mshots. Payload: {url, screenshot_url, status, timestamp}. Header: X-SPAI-Event: screenshot.ready. Not supported together with widths.',
				),
			)
		);

		return $tools;
	}
}
