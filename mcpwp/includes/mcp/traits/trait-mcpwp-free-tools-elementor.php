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
			)
		);

		$tools[] = $this->define_tool(
			'wp_add_section',
			'Add a new section/container to an Elementor page at a specific position. No need to send the full page — just the new section. WORKFLOW: 1) wp_get_elementor_summary → get IDs for positioning 2) Build JSON manually or via wp_get_blueprint 3) wp_add_section(page_id, element, position) 4) Verify response has meta_verified=true.',
			array(
				'page_id'  => array(
					'type'        => 'integer',
					'description' => 'Page ID',
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
			)
		);

		$tools[] = $this->define_tool(
			'wp_remove_section',
			'Remove a section/container from an Elementor page by its element ID. Searches both top-level and nested elements.',
			array(
				'page_id'    => array(
					'type'        => 'integer',
					'description' => 'Page ID',
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
				'page_id'    => array(
					'type'        => 'integer',
					'description' => 'Page ID',
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
			)
		);

		$tools[] = $this->define_tool(
			'wp_patch_elementor',
			'Apply multiple operations to an Elementor page in a single read-write cycle (max 20 ops). More efficient than calling add/remove/replace individually. Operations: add, remove, replace, settings. Get element IDs from wp_get_elementor_summary first. Pass repair_ids=true (operations optional) to rewrite element ids that are not exactly 7 alphanumeric characters — Elementor Pro\'s Search live results and Taxonomy Filter/Loop Grid AJAX reject them — and repoint the references Elementor resolves (Taxonomy Filter selected_element, Off-canvas links); the response\'s id_repair lists every old -> new id. Like any validated write, it also applies the validator\'s standard fixes, reported in warnings.',
			array(
				'page_id'    => array(
					'type'        => 'integer',
					'description' => 'Page ID',
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
			)
		);

		$tools[] = $this->define_tool(
			'wp_set_elementor',
			'FULL PAGE REPLACEMENT — overwrites all Elementor data on the page. For surgical edits, prefer wp_add_section, wp_replace_section, wp_edit_section, or wp_edit_widget instead. Use dry_run=true to validate data without saving. For large payloads with HTML content, use elementor_data_base64 (base64-encoded JSON) instead of elementor_data to avoid quoting/escaping issues. Check elementor_layout_mode from wp_site_info first: sending "container" elements to a "section"-mode site, or Atomic Elements (e-div-block, e-heading, ...) to a site without the Atomic Elements experiment active, is rejected up front with a clear error rather than silently losing data.',
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
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_elementor_revisions',
			'List the saved revisions of an Elementor page, post or template (the Elementor kit included — kit CSS and global colors/fonts are its page settings): the undo history for Elementor writes. Each entry has id, date, author, whether it carries Elementor data, section/widget counts, and whether it matches what the post holds now. Every MCPWP Elementor write keeps the state it replaced and the state it stored as WordPress revisions; a write response\'s revision.previous_revision_id is the id that undoes it with wp_revert_elementor. When revisions are disabled or limited on the site (WP_POST_REVISIONS), `note` says so — an empty list then does not mean the post has no history.',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Page, post or Elementor template ID',
					'required'    => true,
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Revisions per page, newest first (1-100, default 20)',
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number (default 1)',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_revert_elementor',
			'Restore an Elementor page, post or template — or the kit\'s settings — from one of its revisions (from wp_list_elementor_revisions, or a write response\'s revision.previous_revision_id). The element tree goes back through the normal write path (validation, id normalisation, CSS regeneration, render check), and the current state is kept as a revision first, so a revert can itself be reverted: undo.revision_id in the response undoes it. Refused, with nothing changed: a revision of another post, an id that is not a revision, a revision with no Elementor data, a revision the post already matches, or any revert while revisions are disabled on the site. Pass dry_run=true to see what would change without writing.',
			array(
				'id'          => array(
					'type'        => 'number',
					'description' => 'Page, post or Elementor template ID',
					'required'    => true,
				),
				'revision_id' => array(
					'type'        => 'number',
					'description' => 'Revision ID to restore',
					'required'    => true,
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => 'Report what would change (sections, widgets, top-level elements added/removed, validation warnings) without writing',
					'default'     => false,
				),
				'convert'     => array(
					'type'        => 'boolean',
					'description' => 'Set true to restore Elementor data over a post that now has non-Elementor content. Without it that write is refused rather than destroying the content (#788).',
					'default'     => false,
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
			'Get list of available Elementor widgets. Pass a widget name to get its full controls schema with valid control keys, types, defaults, and options.',
			array(
				'widget' => array(
					'type'        => 'string',
					'description' => 'Widget type name (e.g. "heading", "image", "nav-menu") to get full controls schema. Omit to list all widgets.',
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
			'Take a screenshot of a URL. Uses Cloudflare Browser Rendering (headless Chromium) if configured, otherwise falls back to WordPress mshots. By default the screenshot is saved to the media library and returned as a URL (base64 payloads overflow AI-client contexts); pass inline=true for raw base64. Set webhook_url for async mode — the webhook fires when the screenshot is ready.',
			array(
				'url' => array(
					'type'        => 'string',
					'description' => 'URL to screenshot',
					'required'    => true,
				),
				'width' => array(
					'type'        => 'number',
					'description' => 'Screenshot width (320-1920)',
					'default'     => 1280,
				),
				'height' => array(
					'type'        => 'number',
					'description' => 'Screenshot height (240-1440)',
					'default'     => 960,
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
					'description' => 'URL to POST when screenshot is ready. Enables async mode for mshots. Payload: {url, screenshot_url, status, timestamp}. Header: X-SPAI-Event: screenshot.ready',
				),
			)
		);

		return $tools;
	}
}
