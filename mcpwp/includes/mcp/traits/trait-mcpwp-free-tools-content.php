<?php
/**
 * Free-tier tool definitions — content category group.
 *
 * Carved verbatim from Mcpwp_MCP_Free_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * content free tool providers. Mixed into Mcpwp_MCP_Free_Tools.
 */
trait Mcpwp_Free_Tools_Content_Trait {

	/**
	 * @return array
	 */
	private function get_posts_tools() {
		$tools = array();
		// Posts
		$tools[] = $this->define_tool(
			'wp_list_posts',
			'List posts with optional filters. Supports custom post types including wp_block (reusable blocks/synced patterns). Use ids to fetch specific posts and fields to control which data is returned (e.g. fields=id,title,word_count to get word counts without full content).',
			array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type (default: post). Use wp_block for reusable blocks/synced patterns, or any public custom post type.',
					'default'     => 'post',
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Number of posts per page (1-100)',
					'default'     => 10,
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Current page number',
					'default'     => 1,
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Post status filter (publish, draft, pending, private)',
					'default'     => 'publish',
				),
				'category' => array(
					'type'        => 'number',
					'description' => 'Category ID filter',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
				'ids'      => array(
					'type'        => 'string',
					'description' => 'Comma-separated post IDs to fetch (e.g. "41,42,43")',
				),
				'fields'   => array(
					'type'        => 'string',
					'description' => 'Comma-separated field names to return (e.g. "id,title,word_count,content"). id is always included. Use "content" or "word_count" to include full content and word counts.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_create_post',
			'Create a new blog post. Defaults to draft — confirm before publishing. Supports custom post types: post_type=wp_block creates a reusable block/synced pattern, post_type=elementor_snippet creates Elementor Custom Code (requires Elementor Pro). To assign categories or tags, use wp_list_categories / wp_list_tags first to get their IDs.',
			array(
				'title'              => array(
					'type'        => 'string',
					'description' => 'Post title',
					'required'    => true,
				),
				'content'            => array(
					'type'        => 'string',
					'description' => 'Post body (HTML or Gutenberg block markup). Omit for Elementor posts — set content via wp_set_elementor after creation.',
					'default'     => '',
				),
				'status'             => array(
					'type'        => 'string',
					'description' => 'Publication status: "draft" (default, no public URL until published), "publish" (immediately live), "pending" (queued for review), "private" (admin-only visible)',
					'default'     => 'draft',
				),
				'post_type'          => array(
					'type'        => 'string',
					'description' => 'Post type. Default "post". Use "wp_block" for reusable blocks/synced patterns, "elementor_snippet" for Elementor Custom Code injection.',
					'default'     => 'post',
				),
				'excerpt'            => array(
					'type'        => 'string',
					'description' => 'Short summary shown in archives and feeds. Also used as the SEO meta description fallback when no per-post description is set (AIOSEO/Yoast behavior).',
					'default'     => '',
				),
				'slug'               => array(
					'type'        => 'string',
					'description' => 'URL path segment (e.g. "summer-recipes"). WordPress auto-generates from title if omitted. Must be lowercase, hyphens only.',
				),
				'categories'         => array(
					'type'        => 'array',
					'description' => 'Array of category IDs to assign (e.g. [3, 7]). Get IDs from wp_list_categories. Replaces the post\'s entire category list.',
					'items'       => array( 'type' => 'integer' ),
				),
				'tags'               => array(
					'type'        => 'array',
					'description' => 'Array of tag slugs or names to assign (e.g. ["recipes", "summer"]). Creates tags that don\'t yet exist. Replaces the post\'s entire tag list.',
					'items'       => array( 'type' => 'string' ),
				),
				'elementor_location' => array(
					'type'        => 'string',
					'description' => 'For elementor_snippet posts only: where the code injects — "head" (default, before </head>), "body_start" (after <body>), "body_end" (before </body>).',
					'default'     => 'head',
				),
				'terms'      => array(
					'type'        => 'object',
					'description' => 'Custom taxonomy terms to assign, as {taxonomy: [ids, slugs or names]}. Replaces each named taxonomy\'s current set. Unknown taxonomy or term is refused before any write. Use categories/tags for the built-in ones.',
				),
				'meta'       => array(
					'type'        => 'object',
					'description' => 'Post meta to write, as {key: value}. Registered meta runs through its registered sanitize callback; unregistered strings are reduced to post-safe HTML (URLs keep their %20). Protected/reserved keys (leading underscore, _elementor_*, mcpwp_*) are refused.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_post',
			'Update an existing blog post. Only fields you include are changed — omitted fields are untouched. To change categories or tags, pass the full desired list (replaces current). To set the featured image, use wp_set_featured_image instead.',
			array(
				'id'         => array(
					'type'        => 'number',
					'description' => 'Post ID (required)',
					'required'    => true,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Post title',
				),
				'content'    => array(
					'type'        => 'string',
					'description' => 'Post body (HTML). For Elementor posts, use wp_set_elementor / wp_edit_widget instead — post_content is empty on Elementor pages.',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'Publication status: "publish" (make live), "draft" (unpublish), "pending", "private"',
				),
				'excerpt'    => array(
					'type'        => 'string',
					'description' => 'Short summary for archives and feeds. Acts as SEO meta description fallback in most SEO plugins.',
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => 'URL path segment. Changing this breaks existing links unless you have redirect plugins.',
				),
				'categories' => array(
					'type'        => 'array',
					'description' => 'Array of category IDs. Replaces all current categories. Get IDs from wp_list_categories.',
					'items'       => array( 'type' => 'integer' ),
				),
				'tags'       => array(
					'type'        => 'array',
					'description' => 'Array of tag slugs or names. Replaces all current tags. Creates tags that don\'t exist.',
					'items'       => array( 'type' => 'string' ),
				),
				'terms'      => array(
					'type'        => 'object',
					'description' => 'Custom taxonomy terms to assign, as {taxonomy: [ids, slugs or names]}. Replaces each named taxonomy\'s current set. Unknown taxonomy or term is refused before any write. Use categories/tags for the built-in ones.',
				),
				'meta'       => array(
					'type'        => 'object',
					'description' => 'Post meta to write, as {key: value}. Registered meta runs through its registered sanitize callback; unregistered strings are reduced to post-safe HTML (URLs keep their %20). Protected/reserved keys (leading underscore, _elementor_*, mcpwp_*) are refused.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_post',
			'Delete a blog post. Moves to trash by default; set force=true to permanently delete.',
			array(
				'id'    => array(
					'type'        => 'number',
					'description' => 'Post ID',
					'required'    => true,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Force permanent deletion (bypass trash)',
					'default'     => false,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_pages_tools() {
		$tools = array();
		// Pages
		$tools[] = $this->define_tool(
			'wp_list_pages',
			'List pages with optional filters for status, search, and pagination. Use ids to fetch specific pages and fields to control which data is returned (e.g. fields=id,title,word_count).',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Number of pages per page (1-100)',
					'default'     => 10,
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Current page number',
					'default'     => 1,
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Page status filter (publish, draft, pending, private)',
					'default'     => 'publish',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
				'ids'      => array(
					'type'        => 'string',
					'description' => 'Comma-separated page IDs to fetch (e.g. "95,33,34")',
				),
				'fields'   => array(
					'type'        => 'string',
					'description' => 'Comma-separated field names to return (e.g. "id,title,word_count,content"). id is always included. Use "content" or "word_count" to include full content and word counts.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_create_page',
			'Create a new WordPress page. Defaults to draft — confirm before publishing. For Elementor pages, create first then set layout via wp_set_elementor. To use a full-width Elementor layout, set template to "elementor_header_footer" (no header/footer) or "elementor_canvas" (completely blank).',
			array(
				'title'      => array(
					'type'        => 'string',
					'description' => 'Page title (shown in browser tab and admin list)',
					'required'    => true,
				),
				'content'    => array(
					'type'        => 'string',
					'description' => 'Page body (HTML or Gutenberg block markup). Omit for Elementor pages — set layout via wp_set_elementor after creation.',
					'default'     => '',
				),
				'excerpt'    => array(
					'type'        => 'string',
					'description' => 'Page excerpt/deck. Some templates render this as hero subtitle copy (e.g. a page-templates/editorial-home.php deck).',
					'default'     => '',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'Publication status: "draft" (default, not publicly visible), "publish" (immediately live), "pending", "private"',
					'default'     => 'draft',
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => 'URL path segment (e.g. "about-us" → yoursite.com/about-us/). Auto-generated from title if omitted.',
				),
				'parent'     => array(
					'type'        => 'integer',
					'description' => 'Parent page ID for hierarchical pages (e.g. "Services" is parent of "Web Design"). Creates nested URLs: /services/web-design/. Use 0 or omit for top-level.',
				),
				'template'   => array(
					'type'        => 'string',
					'description' => 'Page template slug. "default" = theme default. "elementor_header_footer" = Elementor full-width (keeps header/footer). "elementor_canvas" = completely blank canvas. Use wp_list_page_templates to see all available templates.',
				),
				'menu_order' => array(
					'type'        => 'integer',
					'description' => 'Sort order among sibling pages (lower = first). Used by some themes and menus. Default 0.',
				),
				'terms'      => array(
					'type'        => 'object',
					'description' => 'Custom taxonomy terms to assign, as {taxonomy: [ids, slugs or names]}. Replaces each named taxonomy\'s current set. Unknown taxonomy or term is refused before any write. Use categories/tags for the built-in ones.',
				),
				'meta'       => array(
					'type'        => 'object',
					'description' => 'Post meta to write, as {key: value}. Registered meta runs through its registered sanitize callback; unregistered strings are reduced to post-safe HTML (URLs keep their %20). Protected/reserved keys (leading underscore, _elementor_*, mcpwp_*) are refused.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_page',
			'Update an existing WordPress page. Only fields you include are changed — omitted fields are untouched. For Elementor layout changes use wp_edit_widget / wp_add_section / wp_set_elementor instead — post_content is empty on Elementor pages.',
			array(
				'id'         => array(
					'type'        => 'number',
					'description' => 'Page ID (required)',
					'required'    => true,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Page title',
				),
				'content'    => array(
					'type'        => 'string',
					'description' => 'Page body (HTML or Gutenberg). For Elementor pages, use wp_set_elementor / wp_edit_widget instead.',
				),
				'excerpt'    => array(
					'type'        => 'string',
					'description' => 'Page excerpt/deck. Some templates render this as hero subtitle copy (e.g. a page-templates/editorial-home.php deck).',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'Publication status: "publish" (make live), "draft" (unpublish), "pending", "private"',
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => 'URL path segment. Changing this breaks existing links unless a redirect plugin is active.',
				),
				'parent'     => array(
					'type'        => 'integer',
					'description' => 'Parent page ID for nested hierarchy. Use 0 to move to top level.',
				),
				'template'   => array(
					'type'        => 'string',
					'description' => 'Page template slug. Common values: "default", "elementor_header_footer", "elementor_canvas". Use wp_list_page_templates to see all available templates.',
				),
				'menu_order' => array(
					'type'        => 'integer',
					'description' => 'Sort order among sibling pages. Lower numbers appear first.',
				),
				'terms'      => array(
					'type'        => 'object',
					'description' => 'Custom taxonomy terms to assign, as {taxonomy: [ids, slugs or names]}. Replaces each named taxonomy\'s current set. Unknown taxonomy or term is refused before any write. Use categories/tags for the built-in ones.',
				),
				'meta'       => array(
					'type'        => 'object',
					'description' => 'Post meta to write, as {key: value}. Registered meta runs through its registered sanitize callback; unregistered strings are reduced to post-safe HTML (URLs keep their %20). Protected/reserved keys (leading underscore, _elementor_*, mcpwp_*) are refused.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_page',
			'Delete a page (moves to trash by default, use force for permanent deletion)',
			array(
				'id'    => array(
					'type'        => 'number',
					'description' => 'Page ID',
					'required'    => true,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Force permanent deletion (bypass trash)',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_clone_page',
			'Duplicate a page including its content, Elementor data, template, and featured image',
			array(
				'id'     => array(
					'type'        => 'number',
					'description' => 'Page ID to clone',
					'required'    => true,
				),
				'title'  => array(
					'type'        => 'string',
					'description' => 'Title for the cloned page (defaults to original with Copy suffix)',
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Status for cloned page (publish, draft, pending, private)',
					'default'     => 'draft',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_page_by_slug',
			'Fetch a WordPress page by its URL slug (e.g., "about", "contact"). Use when you know the page URL but not the ID.',
			array(
				'slug' => array(
					'type'        => 'string',
					'description' => 'Page URL slug',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_set_featured_image',
			'Set or remove the featured image (thumbnail) for a post or page',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
				'media_id' => array(
					'type'        => 'number',
					'description' => 'Media attachment ID. Use 0 to remove featured image.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_categories',
			'List post categories with IDs, names, slugs, and post counts',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page (1-200)',
					'default'     => 100,
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
				'parent'   => array(
					'type'        => 'number',
					'description' => 'Parent category ID to list children',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_tags',
			'List all post tags with IDs, names, slugs, and post counts. Use to find tag IDs before assigning them to posts.',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page (1-200)',
					'default'     => 100,
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search term',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_batch_update',
			'Execute multiple REST API operations in a single request (max 100; wp_batch is the same call under the name bulk clients look for). Operations run sequentially and each returns {index, status, data}. Example: [{"method":"PUT","path":"/posts/42","body":{"title":"New Title"}}, {"method":"GET","path":"/pages"}]. Use this to reduce round-trips when making many changes.',
			array(
				'operations' => array(
					'type'        => 'array',
					'description' => 'Array of operation objects. Each must have: method (GET/POST/PUT/DELETE), path (relative to /mcpwp/v1/, e.g. "/pages/42"), body (optional object — used as request body for POST/PUT, query params for GET/DELETE)',
					'required'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'method' => array(
								'type'        => 'string',
								'description' => 'HTTP method for this operation.',
								'enum'        => array( 'GET', 'POST', 'PUT', 'DELETE' ),
							),
							'path'   => array( 'type' => 'string', 'description' => 'Path relative to /mcpwp/v1/, e.g. "/pages/42".' ),
							'body'   => array( 'type' => 'object', 'description' => 'Request body for POST/PUT, or query params for GET/DELETE.' ),
						),
						'required'   => array( 'method', 'path' ),
					),
				),
			)
		);

		// wp_batch (#802): the multi-op batch a bulk job asks for by name. Same
		// route, same controller as wp_batch_update — every protection of
		// Mcpwp_REST_Batch applies unchanged — with the cap stated in the schema
		// so a client can refuse an oversized batch before spending slots on it.
		$tools[] = $this->define_tool(
			'wp_batch',
			'Run up to 100 REST operations in one round trip. Operations run in order and each returns {index, status, data}; the response adds total, succeeded and failed. A failed operation does not stop the rest, and nothing is rolled back. Costs one rate-limit slot per operation. An operation may not target /batch or /mcp. With params._meta.request_id, a batch with any failed operation is not pinned: retry only the failed operations, each under its own request_id. Example: [{"method":"PUT","path":"/posts/42","body":{"title":"New title"}},{"method":"GET","path":"/pages"}].',
			array(
				'operations' => array(
					'type'        => 'array',
					'description' => 'Operations, in order. Each: method (GET/POST/PUT/DELETE), path relative to /mcpwp/v1/ (e.g. "/pages/42"), body (optional object — the request body for POST/PUT, query parameters for GET/DELETE).',
					'required'    => true,
					'minItems'    => 1,
					// Mcpwp_REST_Batch::MAX_OPERATIONS; the controller may not be
					// loaded when the registry is built, so the value is repeated
					// and a test holds the two equal.
					'maxItems'    => 100,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'method' => array(
								'type'        => 'string',
								'description' => 'HTTP method for this operation.',
								'enum'        => array( 'GET', 'POST', 'PUT', 'DELETE' ),
							),
							'path'   => array( 'type' => 'string', 'description' => 'Path relative to /mcpwp/v1/, e.g. "/pages/42".' ),
							'body'   => array( 'type' => 'object', 'description' => 'Request body for POST/PUT, or query params for GET/DELETE.' ),
						),
						'required'   => array( 'method', 'path' ),
					),
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_drafts_tools() {
		$tools = array();
		// Drafts
		$tools[] = $this->define_tool(
			'wp_list_drafts',
			'List all draft posts and pages. Use to review unpublished content or find work in progress.',
			array(
				'type' => array(
					'type'        => 'string',
					'description' => 'Post type filter (post, page, all)',
					'default'     => 'all',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_all_drafts',
			'Bulk delete all draft posts and pages. Permanent — cannot be undone. Always confirm with user first.',
			array(
				'type'  => array(
					'type'        => 'string',
					'description' => 'Post type filter (post, page, all)',
					'default'     => 'all',
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Force permanent deletion',
					'default'     => false,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_post_meta_tools() {
		$tools = array();
		// Post Meta
		$tools[] = $this->define_tool(
			'wp_get_post_meta',
			'Get post meta for a post or page. Returns a single key or all non-sensitive meta.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
				'key' => array(
					'type'        => 'string',
					'description' => 'Specific meta key to retrieve (omit to get all)',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_set_post_meta',
			'Set a single post meta value. Blocked keys (passwords, secrets, internal WP keys) are rejected.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post or page ID',
					'required'    => true,
				),
				'key' => array(
					'type'        => 'string',
					'description' => 'Meta key to set',
					'required'    => true,
				),
				'value' => array(
					'type'        => 'string',
					'description' => 'Meta value to set',
					'required'    => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_option_mgmt_tools() {
		$tools = array();
		// Option Management
		$tools[] = $this->define_tool(
			'wp_get_option',
			'Get a single WordPress option by key. Reachable: core settings, builder/SEO/theme/commerce prefixes (elementor_*, wpseo_*, rank_math_*, theme_mods_*, widget_*, woocommerce_*, …), namespaces of plugins that are active here (Max Mega Menu, GTM4WP, WP Mail SMTP, Complianz, forms, caches, …), and this site\'s option_allowlist. Credential-shaped fields come back as *** with their paths under redacted; the plugin\'s own state, salts, roles, active plugins, cron and transients are never reachable. A refusal names its rule.',
			array(
				'key' => array(
					'type'        => 'string',
					'description' => 'Option key (e.g., blogname, show_on_front, elementor_active_kit, wpseo_titles)',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_option',
			'Update a single WordPress option by key. Same reachability as wp_get_option, minus write-protected control surfaces (siteurl, home, admin_email, template, upload paths, …). A write that would set a credential-shaped field is refused (secret_write_refused) unless allow_secrets=true; a masked *** value or an omitted credential field is folded back to the stored value and reported under preserved, so a read→edit→write loop can never destroy a key.',
			array(
				'key' => array(
					'type'        => 'string',
					'description' => 'Option key to update',
					'required'    => true,
				),
				'value' => array(
					'type'        => 'string',
					'description' => 'New value for the option',
					'required'    => true,
				),
				'allow_secrets' => array(
					'type'        => 'boolean',
					'description' => 'Confirm a write that sets credential-shaped fields (passcodes, API keys, licence keys). Refused with secret_write_refused otherwise.',
				),
				'force_type_change' => array(
					'type'        => 'boolean',
					'description' => 'Confirm replacing an array/object option with a plain string (option_type_mismatch otherwise). When the stored value holds credential-shaped fields, allow_secrets=true is required as well.',
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_bulk_create_pages_tools() {
		$tools = array();
		// Bulk Create Pages
		$tools[] = $this->define_tool(
			'wp_bulk_create_pages',
			'Create multiple pages in one call. Returns array of created pages with IDs and slugs.',
			array(
				'pages' => array(
					'type'        => 'array',
					'description' => 'Array of page objects with: title (required), content, status (default: draft), slug, parent, template',
					'required'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'    => array( 'type' => 'string', 'description' => 'Page title.' ),
							'content'  => array( 'type' => 'string', 'description' => 'Page body (HTML or Gutenberg block markup).' ),
							'status'   => array( 'type' => 'string', 'description' => 'Publication status (default: draft).' ),
							'slug'     => array( 'type' => 'string', 'description' => 'URL path segment.' ),
							'parent'   => array( 'type' => 'integer', 'description' => 'Parent page ID.' ),
							'template' => array( 'type' => 'string', 'description' => 'Page template slug.' ),
						),
						'required'   => array( 'title' ),
					),
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_bulk_create_posts_tools() {
		$tools = array();
		// Bulk Create Posts
		$tools[] = $this->define_tool(
			'wp_bulk_create_posts',
			'Create multiple blog posts in one call. Returns array of created posts with IDs and slugs.',
			array(
				'posts' => array(
					'type'        => 'array',
					'description' => 'Array of post objects with: title (required), content, status (default: draft), categories (array of IDs), tags (array of strings), excerpt, slug, post_type',
					'required'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'      => array( 'type' => 'string', 'description' => 'Post title.' ),
							'content'    => array( 'type' => 'string', 'description' => 'Post body (HTML or Gutenberg block markup).' ),
							'status'     => array( 'type' => 'string', 'description' => 'Publication status (default: draft).' ),
							'categories' => array( 'type' => 'array', 'description' => 'Category IDs to assign.', 'items' => array( 'type' => 'integer' ) ),
							'tags'       => array( 'type' => 'array', 'description' => 'Tag slugs or names to assign.', 'items' => array( 'type' => 'string' ) ),
							'excerpt'    => array( 'type' => 'string', 'description' => 'Short summary.' ),
							'slug'       => array( 'type' => 'string', 'description' => 'URL path segment.' ),
							'post_type'  => array( 'type' => 'string', 'description' => 'Post type. Default "post".' ),
						),
						'required'   => array( 'title' ),
					),
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_bulk_update_posts_tools() {
		$tools = array();
		// Bulk Update Posts
		$tools[] = $this->define_tool(
			'wp_bulk_update_posts',
			'Update multiple posts in one call. Each item must include id plus fields to update. Returns array of updated posts and any errors.',
			array(
				'posts' => array(
					'type'        => 'array',
					'description' => 'Array of post objects with: id (required), title, content, status, excerpt, slug, categories, tags',
					'required'    => false,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'         => array( 'type' => 'integer', 'description' => 'Post ID to update.' ),
							'title'      => array( 'type' => 'string', 'description' => 'Post title.' ),
							'content'    => array( 'type' => 'string', 'description' => 'Post body (HTML).' ),
							'status'     => array( 'type' => 'string', 'description' => 'Publication status.' ),
							'excerpt'    => array( 'type' => 'string', 'description' => 'Short summary.' ),
							'slug'       => array( 'type' => 'string', 'description' => 'URL path segment.' ),
							'categories' => array( 'type' => 'array', 'description' => 'Category IDs. Replaces all current categories.', 'items' => array( 'type' => 'integer' ) ),
							'tags'       => array( 'type' => 'array', 'description' => 'Tag slugs or names. Replaces all current tags.', 'items' => array( 'type' => 'string' ) ),
						),
						'required'   => array( 'id' ),
					),
				),
				'updates' => array(
					'type'        => 'array',
					'description' => 'Same as posts: the list of objects to update, each with id plus the fields to change. Pass one of the two.',
					'required'    => false,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'         => array( 'type' => 'integer', 'description' => 'Post ID to update.' ),
							'title'      => array( 'type' => 'string', 'description' => 'Post title.' ),
							'content'    => array( 'type' => 'string', 'description' => 'Post body (HTML).' ),
							'status'     => array( 'type' => 'string', 'description' => 'Publication status.' ),
							'excerpt'    => array( 'type' => 'string', 'description' => 'Short summary.' ),
							'slug'       => array( 'type' => 'string', 'description' => 'URL path segment.' ),
							'categories' => array( 'type' => 'array', 'description' => 'Category IDs. Replaces all current categories.', 'items' => array( 'type' => 'integer' ) ),
							'tags'       => array( 'type' => 'array', 'description' => 'Tag slugs or names. Replaces all current tags.', 'items' => array( 'type' => 'string' ) ),
						),
						'required'   => array( 'id' ),
					),
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_bulk_update_pages_tools() {
		$tools = array();
		// Bulk Update Pages
		$tools[] = $this->define_tool(
			'wp_bulk_update_pages',
			'Update multiple pages in one call. Each item must include id plus fields to update. Returns array of updated pages and any errors.',
			array(
				'pages' => array(
					'type'        => 'array',
					'description' => 'Array of page objects with: id (required), title, content, status, slug, parent, template',
					'required'    => false,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer', 'description' => 'Page ID to update.' ),
							'title'    => array( 'type' => 'string', 'description' => 'Page title.' ),
							'content'  => array( 'type' => 'string', 'description' => 'Page body (HTML).' ),
							'status'   => array( 'type' => 'string', 'description' => 'Publication status.' ),
							'slug'     => array( 'type' => 'string', 'description' => 'URL path segment.' ),
							'parent'   => array( 'type' => 'integer', 'description' => 'Parent page ID.' ),
							'template' => array( 'type' => 'string', 'description' => 'Page template slug.' ),
						),
						'required'   => array( 'id' ),
					),
				),
				'updates' => array(
					'type'        => 'array',
					'description' => 'Same as pages: the list of objects to update, each with id plus the fields to change. Pass one of the two.',
					'required'    => false,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer', 'description' => 'Page ID to update.' ),
							'title'    => array( 'type' => 'string', 'description' => 'Page title.' ),
							'content'  => array( 'type' => 'string', 'description' => 'Page body (HTML).' ),
							'status'   => array( 'type' => 'string', 'description' => 'Publication status.' ),
							'slug'     => array( 'type' => 'string', 'description' => 'URL path segment.' ),
							'parent'   => array( 'type' => 'integer', 'description' => 'Parent page ID.' ),
							'template' => array( 'type' => 'string', 'description' => 'Page template slug.' ),
						),
						'required'   => array( 'id' ),
					),
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_taxonomy_tools() {
		$tools = array();
		// Taxonomy Management
		$tools[] = $this->define_tool(
			'wp_create_term',
			'Create a new taxonomy term (category, tag, or custom taxonomy)',
			array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy name (category, post_tag, or custom)',
					'required'    => true,
				),
				'name'     => array(
					'type'        => 'string',
					'description' => 'Term name',
					'required'    => true,
				),
				'slug'     => array(
					'type'        => 'string',
					'description' => 'Term URL slug',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Term description',
				),
				'parent'   => array(
					'type'        => 'number',
					'description' => 'Parent term ID (for hierarchical taxonomies)',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_term',
			'Update an existing taxonomy term (rename, change slug, update description)',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Term ID',
					'required'    => true,
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy name (category, post_tag, or custom)',
					'required'    => true,
				),
				'name'     => array(
					'type'        => 'string',
					'description' => 'New term name',
				),
				'slug'     => array(
					'type'        => 'string',
					'description' => 'New term slug',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'New term description',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_term',
			'Delete a WordPress taxonomy term (category or tag) by ID. Does not delete associated posts.',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Term ID',
					'required'    => true,
				),
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy name (category, post_tag, or custom)',
					'required'    => true,
				),
			)
		);

		return $tools;
	}
}
