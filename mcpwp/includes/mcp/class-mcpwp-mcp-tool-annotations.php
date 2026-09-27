<?php
/**
 * MCP Tool Annotation Overrides — single source of truth
 *
 * Per the MCP spec: readOnlyHint true means the tool does not modify its
 * environment AT ALL — not options, not cached state, not a paid third-party
 * call. destructiveHint true means "may perform destructive updates";
 * false means "only additive updates" — so any tool that overwrites or
 * deletes something that already existed is destructive, full stop.
 *
 * The base registry (Mcpwp_MCP_Tool_Registry) infers readOnlyHint from HTTP
 * method, and — absent an override — treats every non-read-only tool as
 * destructive UNLESS its name is a known purely-additive verb
 * (is_purely_additive_by_name() below). That combination is right most of
 * the time but wrong for a known set of tools: a GET tool that writes state
 * or spends third-party credit on some call paths (readOnlyHint), a POST
 * tool that only validates/transforms input (readOnlyHint), a tool that
 * overwrites/deletes existing state despite a non-obvious verb (destructiveHint
 * true), or one whose verb reads destructively but is actually additive-only
 * once you read the implementation (destructiveHint false). Connector
 * directory review (2026-09-25 submission, plus a follow-up adversarial
 * pass) checks these for correctness, so every inaccuracy has to be fixed at
 * one place, not scattered across registries.
 *
 * This class is that one place. `ANNOTATION_OVERRIDES` is consulted by
 * Mcpwp_MCP_Tool_Registry BEFORE the HTTP-method heuristic, before the
 * default-destructive-for-write-tools rule, and before each registry's own
 * get_destructive_tools()/get_open_world_tools() lists — an override here
 * always wins. Every entry carries a `reason` so a future change to the
 * underlying tool can be checked against why the override exists
 * (docs/mcp-tool-annotations.md is the human-readable audit trail this
 * table backs). A tool with a `save`/`store`/`refresh`/`enrich`/`persist`
 * parameter (as a whole underscore-split word, e.g. `auto_save`) is
 * guard-tested to require readOnlyHint=false unless documented here.
 *
 * `TITLE_OVERRIDES` is the curated exception list for tool names the
 * humanizer (`humanize_tool_name()`) reads badly for; everything else gets an
 * automatic human title so no tool ships without one.
 *
 * @package MCPWP
 * @since   3.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static lookup tables + helpers for MCP tool titles and annotation overrides.
 */
class Mcpwp_MCP_Tool_Annotations {

	/**
	 * Per-tool annotation overrides, keyed by tool name.
	 *
	 * Each entry may carry any of: readOnlyHint, destructiveHint,
	 * openWorldHint, idempotentHint (all bool) plus a required `reason`
	 * (string) explaining why the HTTP-method heuristic or a registry's
	 * destructive/open-world list gets this tool wrong.
	 *
	 * A key's absence means "trust the heuristic" — this table exists
	 * specifically for the tools where that would be wrong.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const ANNOTATION_OVERRIDES = array(

		// --- GET tools that read local state but also spend third-party
		// credit or otherwise reach an external system (openWorldHint was
		// false by default: pro-tier's get_open_world_tools() is empty, and
		// free tools' list only names a handful of upload/webhook tools).

		'wp_fetch'                  => array(
			'openWorldHint' => true,
			'reason'        => 'Fetches an arbitrary caller-supplied external URL (/fetch).',
		),
		'wp_keyword_research'       => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Free/keyless by default, but enrich=true routes through a configured DataForSEO provider and spends the site\'s lookup budget (Mcpwp_REST_SEO_Audit::keyword_research()) — spending a budget is a write, not a pure read. Not destructive: nothing existing is overwritten, only a shared lookup-cache/budget counter is consumed.',
		),
		'wp_check_update'           => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Calls the plugin update manifest URL over HTTP and, on success, deletes + rebuilds WordPress core\'s own `update_plugins` site transient (Mcpwp_REST_Site_Updates::check_update()) — a real write, not a pure read. Not destructive: the transient is core\'s own disposable, regenerable cache, not user content or plugin configuration.',
		),
		'wp_get_rendered_html'      => array(
			'openWorldHint' => true,
			'reason'        => 'Issues a live wp_remote_get() against the rendered front-end page, which can carry third-party embeds/widgets, not just local data.',
		),
		'wp_get_rendered_copy'      => array(
			'openWorldHint' => true,
			'reason'        => 'Same live-render fetch as wp_get_rendered_html, over the extracted-copy path.',
		),
		'wp_google_index_status'    => array(
			'openWorldHint' => true,
			'reason'        => 'Calls the Google Indexing API to check a URL\'s status (Mcpwp_REST_Google_Indexing::get_status()); pro-tier open-world list is empty by default.',
		),
		'wp_submit_to_google_index' => array(
			'destructiveHint' => true,
			'openWorldHint'   => true,
			'reason'          => 'Submits URLs to the Google Indexing API (Mcpwp_REST_Google_Indexing::submit_urls()); action accepts URL_DELETED, which asks Google to drop the URL from its index — not purely additive despite the "submit_" verb.',
		),
		'wp_refresh_seo_visibility' => array(
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Fetches a fresh ranked-keyword snapshot from the configured DataForSEO provider and REPLACES the stored one (Mcpwp_REST_SEO_Market::refresh_visibility() -> Mcpwp_SEO_Visibility::store_snapshot()) — distinct from wp_seo_visibility, which only reads the stored snapshot. Not destructive: the snapshot is a disposable, re-fetchable market-data cache, not user-authored content.',
		),
		'wp_seo_opportunities'      => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Falls through to a live DataForSEO keyword lookup (spending the lookup budget) when a seed is given and no visibility rows are stored yet (Mcpwp_REST_SEO_Market::get_opportunities()) — a real write, not a pure read. Not destructive: nothing existing is overwritten, only the shared lookup cache/budget.',
		),
		'wp_seo_audit_site'         => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'reason'          => 'GET, but store=true persists the run to Mcpwp_SEO_Audit_Store (Mcpwp_REST_SEO_Audit::audit_seo_site()) — not a pure read on every call. Not destructive: the stored audit is a disposable, re-runnable snapshot, not user content.',
		),
		'wp_extract_site_blueprint' => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'reason'          => 'GET, but save=true persists the extracted result via Mcpwp_Site_Blueprints::save() (Mcpwp_REST_Site_Blueprints::extract_blueprint()) — not a pure read. Not destructive: extract() never sets an `id`, so save() always mints a brand-new blueprint id — this call path can only create, never overwrite an existing stored blueprint.',
		),
		'wp_get_signals'            => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'reason'          => 'GET, but the first call ever (or refresh=true) recomputes and persists the signal feed via update_option() and can fire mcpwp_event_emitted for high-severity signals (Mcpwp_REST_Signals::get_signals() -> Mcpwp_Signals::compute()) — not a pure read. Not destructive: it replaces a computed health-signal cache for the types just recomputed, not user-authored content.',
		),
		'wp_recall'                 => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'reason'          => 'Routes to the same /memory GET handler as wp_list_memories, which runs a one-time migration write on first call (Mcpwp_Site_Memory::maybe_migrate_site_context() -> update_option()) — not a pure read. Not destructive: it sets an internal one-time migration flag, never overwriting a memory value the caller can see.',
		),
		'wp_list_memories'          => array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'reason'          => 'Same one-time migration write as wp_recall — Mcpwp_Rest_Site_Memory::list_memories() calls Mcpwp_Site_Memory::maybe_migrate_site_context() before reading. Not destructive for the same reason.',
		),

		// --- openWorldHint fixes: tools that reach an external service but
		// were not in any registry's get_open_world_tools() list. Several
		// (the Cloudflare tools, wp_trigger_update) are also destructiveHint
		// fixes — see the reason for each.

		'wp_validate_seo_readiness'   => array(
			'openWorldHint' => true,
			'reason'        => 'For a published post, fetches the live rendered page over HTTP for its validation basis (Mcpwp_REST_SEO_Audit::validate_seo_readiness() -> fetch_rendered_html_body()/fetch_rendered_head_seo()) — same loopback-fetch reasoning as wp_get_rendered_html.',
		),
		'wp_validate_structured_data' => array(
			'openWorldHint' => true,
			'reason'        => 'Same rendered-page fetch as wp_validate_seo_readiness (Mcpwp_REST_SEO_Audit::validate_structured_data() -> fetch_rendered_html_body()).',
		),
		'wp_get_site_health'          => array(
			'openWorldHint' => true,
			'reason'        => 'Includes update_channel status; Mcpwp_Update_Channel_Health::get_status() fetches the update manifest over HTTP whenever its cached transient has expired (Mcpwp_REST_Site_Info::get_site_health()).',
		),
		'wp_cloudflare_status'              => array(
			'openWorldHint' => true,
			'reason'        => 'Calls the Cloudflare API to read zone/DNS status (Mcpwp_REST_Cloudflare).',
		),
		'wp_cloudflare_get_speed_settings'   => array(
			'openWorldHint' => true,
			'reason'        => 'Calls the Cloudflare API to read speed/performance settings (Mcpwp_REST_Cloudflare::get_speed_settings()).',
		),
		'wp_cloudflare_purge_cache'          => array(
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Calls the Cloudflare API to purge the edge cache (Mcpwp_REST_Cloudflare::purge_cache()). Not destructive: a CDN cache is disposable and regenerates from origin on the next request — no user content or configuration is lost.',
		),
		'wp_cloudflare_update_speed_setting' => array(
			'destructiveHint' => true,
			'openWorldHint'   => true,
			'reason'          => 'Calls the Cloudflare API to overwrite a persistent zone setting (Mcpwp_REST_Cloudflare::update_speed_settings()) — unlike a cache purge, this replaces real configuration.',
		),
		'wp_cloudflare_fix_mcp_access'       => array(
			'destructiveHint' => true,
			'openWorldHint'   => true,
			'reason'          => 'Calls the Cloudflare API to modify WAF/firewall rules so MCP requests are not blocked (Mcpwp_REST_Cloudflare::fix_mcp_access()) — overwrites existing firewall configuration.',
		),
		'wp_trigger_update'                  => array(
			'destructiveHint' => true,
			'openWorldHint'   => true,
			'reason'          => 'Downloads and installs a plugin package via Plugin_Upgrader, overwriting the currently-installed plugin files (Mcpwp_REST_Site_Updates); also reaches the update manifest/package host over HTTP.',
		),
		'wc_update_order'                    => array(
			'openWorldHint' => true,
			'reason'        => 'A status change on a WooCommerce order (Mcpwp_REST_Woocommerce::update_order()) fires WooCommerce\'s own order-status-changed hooks, which can send customer-facing emails depending on WooCommerce email settings.',
		),

		// --- POST tools that validate/transform input without touching
		// WordPress state (readOnlyHint was false by default: POST implies
		// a write under the base heuristic, which is wrong for these three).

		'wp_parse_blocks'     => array(
			'readOnlyHint' => true,
			'reason'       => 'Parses HTML into a block structure and returns it; does not write to any post (POST is used only because the HTML body would not fit a GET).',
		),
		'wp_serialize_blocks' => array(
			'readOnlyHint' => true,
			'reason'       => 'Serializes a block structure to HTML and returns it; pure transform, no write.',
		),
		'wp_validate_blocks'  => array(
			'readOnlyHint' => true,
			'reason'       => 'Validates block structure and returns errors/warnings; pure check, no write.',
		),

		// --- destructiveHint corrections. destructiveHint means "this tool
		// can overwrite or remove existing content", independent of
		// readOnlyHint/HTTP method. wp_apply_elementor_template already sets
		// this precedent (#637: "replaces all existing Elementor content on
		// the target page"); wp_set_elementor does exactly the same thing to
		// the same page but was not flagged. Kept in one table so the same
		// judgment call isn't re-litigated tool-by-tool.

		'wp_set_elementor'         => array(
			'destructiveHint' => true,
			'reason'          => 'Fully replaces a page\'s Elementor data, same as wp_apply_elementor_template/_archetype/_part which already carry this hint.',
		),
		'wp_replace_section'       => array(
			'destructiveHint'  => true,
			'idempotentHint'   => true,
			'reason'           => 'Overwrites an existing section\'s content with new content; replacing with the same section twice yields the same page.',
		),
		'wp_remove_section'        => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Removes a section from a page; a second call on an already-removed section is a no-op with the same end state.',
		),
		'wp_set_blocks'            => array(
			'destructiveHint' => true,
			'reason'          => 'Fully replaces a post\'s Gutenberg block content.',
		),
		'wp_set_kit_css'           => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Can fully replace the site\'s global Elementor kit CSS.',
		),
		'wp_set_custom_css'        => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Fully replaces the site\'s custom CSS.',
		),
		'wp_set_elementor_globals' => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Replaces site-wide Elementor design globals (colors/typography) used across every page.',
		),
		'wp_set_curriculum'        => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Fully replaces a course\'s curriculum structure (LearnPress).',
		),
		'wp_set_template_conditions' => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Fully replaces a theme-builder template\'s display conditions; a misconfigured call can unassign the template everywhere.',
		),
		'wp_bulk_find_replace'     => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Mutates text across many pages at once with no per-page undo; matches the same pattern-name guard as other bulk-mutation tools.',
		),
		'wp_remove_integration'    => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Deletes a stored provider credential; removing an already-removed integration is a no-op with the same end state.',
		),
		'wp_rollback_approval'     => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Overwrites current state with a prior snapshot, discarding whatever was applied after it.',
		),
		'wp_bulk_update_posts'     => array(
			'destructiveHint' => true,
			'reason'          => 'Overwrites fields on many posts in one call — same edit semantics as wp_update_post, at a much larger blast radius per mistake.',
		),
		'wp_bulk_update_pages'     => array(
			'destructiveHint' => true,
			'reason'          => 'Overwrites fields on many pages in one call — same reasoning as wp_bulk_update_posts.',
		),
		'wp_bulk_seo'              => array(
			'destructiveHint' => true,
			'reason'          => 'Overwrites SEO metadata on many posts in one call — same blast-radius reasoning as wp_bulk_update_posts/_pages.',
		),

		// --- destructiveHint false-positives: the guard test's name pattern
		// (delete|remove|revoke|rollback|replace|bulk|push_to_live) also
		// matches these, but they are purely additive or purely read-only —
		// listed explicitly (with reason) so the guard treats them as
		// reviewed exceptions rather than silent gaps.

		'wp_bulk_upload_media'  => array(
			'destructiveHint' => false,
			'reason'          => 'Uploads new media items; additive only, nothing existing is overwritten or removed.',
		),
		'wp_get_elementor_bulk' => array(
			'destructiveHint' => false,
			'readOnlyHint'    => true,
			'reason'          => 'Reads Elementor data for multiple pages; GET, no mutation despite the "bulk" name.',
		),
		'wp_bulk_create_pages'  => array(
			'destructiveHint' => false,
			'reason'          => 'Creates new pages; additive only.',
		),
		'wp_bulk_create_posts'  => array(
			'destructiveHint' => false,
			'reason'          => 'Creates new posts; additive only.',
		),

		// --- destructiveHint=false exceptions that are NOT caught by
		// Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name() (their verb
		// isn't create/add/upload/clone/import/submit/duplicate/bulk_*), so
		// Mcpwp_MCP_Tool_Registry's new default-destructive-for-write-tools
		// rule would otherwise mark them destructive. Each was read against
		// its actual implementation to confirm it never overwrites or removes
		// something that already existed.

		'wp_apply_internal_link'  => array(
			'destructiveHint' => false,
			'reason'          => 'Appends one new paragraph linking to the target to the END of existing post_content (Mcpwp_REST_Content_Graph::apply_internal_link()) and rejects a duplicate link (409); never removes or rewrites existing content. Approval-gated by default.',
		),
		'wp_download_stock_photo' => array(
			'destructiveHint' => false,
			'reason'          => 'Downloads a Pexels photo into a NEW media attachment (Mcpwp_Provider_Pexels::download_to_media()); additive only.',
		),
		'wp_generate_image'       => array(
			'destructiveHint' => false,
			'reason'          => 'Generates an AI image into a NEW media attachment (provider generate_image_to_media()); additive only — unlike wp_generate_featured_image, it does not also assign anything.',
		),
		'wp_describe_image'       => array(
			'destructiveHint' => false,
			'reason'          => 'Returns an AI-generated description; writes nothing anywhere (Mcpwp_REST_Integrations::describe_image()).',
		),
		'wp_get_blueprint'        => array(
			'destructiveHint' => false,
			'reason'          => 'Pure generator: builds and returns one Elementor section\'s JSON from a blueprint type + params (Mcpwp_Page_Builder::build_single_section()); writes nothing to WordPress.',
		),
		'wp_build_page'           => array(
			'destructiveHint' => false,
			'reason'          => 'Creates a NEW page from title/sections (Mcpwp_Page_Builder::build()); additive only.',
		),
		'wp_save_section_as_template' => array(
			'destructiveHint' => false,
			'reason'          => 'Creates a NEW reusable Elementor template from a live section (Mcpwp_Elementor_Pro::save_section_as_template()); the source page/section is only read, never modified.',
		),
		'wp_screenshot_url'       => array(
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Renders via an external screenshot worker; save_to_media=true adds a NEW media attachment (Mcpwp_REST_Screenshot). Additive only.',
		),
		'wp_test_webhook'         => array(
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Sends one external test delivery to the webhook\'s configured URL and appends a delivery-log row (Mcpwp_Webhooks::test()); does not modify the webhook\'s own stored configuration.',
		),
		'wp_test_integration'     => array(
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'reason'          => 'Performs a live connectivity check against the provider (Mcpwp_Integration_Manager::test_provider()); does not modify the stored provider configuration.',
		),
		'wp_deploy_site_blueprint' => array(
			'destructiveHint' => false,
			'reason'          => 'Creates NEW pages via wp_insert_post() for every blueprint page, and only reuses (never deletes) an existing same-named nav menu to append items to (Mcpwp_Site_Blueprints::deploy()); no existing page or menu item is overwritten or removed.',
		),
		'wp_flush_cache'          => array(
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'reason'          => 'Clears page-cache plugin caches (W3TC/WP Super Cache/LiteSpeed/WP Rocket/Cache Enabler); disposable, regenerates from origin on next request — no user content or configuration is lost. Idempotent: flushing an already-empty cache is a no-op with the same end state.',
		),
		'wp_flush_permalinks'     => array(
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'reason'          => 'Regenerates WordPress\'s rewrite-rules cache from currently-registered post types/taxonomies; disposable and deterministically reproducible, not authored content. Idempotent: running it again from the same state produces the same rules.',
		),
		'wp_regenerate_elementor_css' => array(
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'reason'          => 'Regenerates the derived Elementor CSS cache file from current page/kit data; disposable and deterministically reproducible, not the authoritative source (the Elementor data itself). Idempotent: same input deterministically produces the same output file.',
		),

		// --- destructiveHint=true for a conditional overwrite path that is
		// easy to miss because the tool's happy path looks purely additive.
		// (Everything else that should default to true — wp_update_*,
		// wp_set_*, wp_edit_*, wp_patch_*, wp_batch*, wp_assign_*, wp_reorder_*,
		// wp_move_widget, wp_remember, wp_configure_integration,
		// wp_sync_figma_design_tokens, wp_upsert_kit_css_block, wp_apply_approval,
		// wc_update_*, wc_apply_product_archetype, wp_push_to_staging, etc. —
		// already gets destructiveHint=true from
		// Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(),
		// since none of those verbs match is_purely_additive_by_name(). No
		// override entry is needed for them, and none is added here, so this
		// table doesn't rot into a second, driftable copy of that list.)

		'wp_generate_featured_image' => array(
			'destructiveHint' => true,
			'reason'          => 'Generates a new media attachment AND calls set_post_thumbnail() (Mcpwp_REST_Integrations::generate_featured_image()), overwriting the post\'s existing featured-image assignment if it had one.',
		),
		'wp_generate_alt_text'       => array(
			'destructiveHint' => true,
			'reason'          => 'With auto_save=true, overwrites the attachment\'s existing alt text via update_post_meta() (Mcpwp_REST_Integrations::generate_alt_text()). The happy-path default (auto_save omitted) only returns suggested text, but the hint must cover the write path.',
		),
		'wp_generate_excerpt'        => array(
			'destructiveHint' => true,
			'reason'          => 'With auto_save=true, overwrites the post\'s existing excerpt via wp_update_post() (Mcpwp_REST_Integrations::generate_excerpt()). Same reasoning as wp_generate_alt_text.',
		),
		'wp_setup_menu'              => array(
			'destructiveHint' => true,
			'reason'          => 'With overwrite=true and an existing same-named menu, deletes every existing menu item on that menu before repopulating it (Mcpwp_REST_Menus::setup_menu()) — a real deletion path, not purely additive.',
		),
		'wp_add_section'             => array(
			'destructiveHint' => true,
			'reason'          => 'With convert=true on a page that has existing non-Elementor content and no Elementor data, converting it means "its current content will not survive" (trait-mcpwp-elementor-writer.php ensure_elementor_page_or_error()) — the same content-loss path #788 already guards against. Default (convert omitted) refuses the write rather than losing content, but the hint must cover the opt-in path.',
		),
		'wp_import_search_performance' => array(
			'destructiveHint' => true,
			'reason'          => 'Evicts the oldest imports/rows once storage exceeds Mcpwp_Search_Performance::MAX_IMPORTS/MAX_ROWS (array_slice() after array_unshift()/array_merge()) — a real deletion path once the cap is reached, not purely additive despite the "import_" verb.',
		),
		'wp_create_menu'             => array(
			'destructiveHint' => true,
			'reason'          => 'A `location` arg reassigns that theme location\'s nav_menu_locations entry to the new menu (Mcpwp_Site_Manager::create_menu()), overwriting whatever menu was previously assigned there — not purely additive despite the "create_" verb.',
		),

		// --- idempotentHint for meaningfully-idempotent write tools. Default
		// (no override) is: read-only tools are idempotent, other write
		// tools are not asserted either way (false). These are the write
		// tools where calling twice with the same arguments provably leaves
		// the site in the same state as calling once.

		'wp_delete_post'             => array( 'idempotentHint' => true, 'reason' => 'Deleting an already-deleted post is a no-op with the same end state.' ),
		'wp_delete_page'             => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_all_drafts'       => array( 'idempotentHint' => true, 'reason' => 'A second call finds no drafts left; same end state.' ),
		'wp_delete_menu'             => array( 'idempotentHint' => true, 'reason' => 'Deleting an already-deleted menu is a no-op with the same end state.' ),
		'wp_delete_menu_item'        => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_menu.' ),
		'wp_delete_media'            => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_webhook'          => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_term'             => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_content'          => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_custom_css'       => array( 'idempotentHint' => true, 'reason' => 'A second call finds the CSS already cleared; same end state.' ),
		'wp_delete_kit_css_block'    => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_custom_css.' ),
		'wp_delete_course_category'  => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_elementor_template' => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_widget'           => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wc_delete_product'          => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_revoke_api_key'          => array( 'idempotentHint' => true, 'reason' => 'Revoking an already-revoked key is a no-op with the same end state.' ),
		'wp_reset_rate_limit'        => array( 'idempotentHint' => true, 'reason' => 'Resetting an already-reset counter is a no-op with the same end state.' ),
		'wp_forget'                  => array( 'idempotentHint' => true, 'reason' => 'Forgetting an already-forgotten memory key is a no-op with the same end state.' ),
		'wp_set_post_meta'           => array( 'idempotentHint' => true, 'reason' => 'Sets a meta value to a given value; setting the same value twice leaves the same state.' ),
		'wp_set_site_context'        => array( 'idempotentHint' => true, 'reason' => 'Replaces the stored site-context brief; same text twice leaves the same state.' ),
		'wp_set_noindex'             => array( 'idempotentHint' => true, 'reason' => 'Sets a boolean flag; same value twice leaves the same state.' ),
		'wp_set_language'            => array( 'idempotentHint' => true, 'reason' => 'Sets the current display language; same value twice leaves the same state.' ),
		'wp_set_seo'                 => array( 'idempotentHint' => true, 'reason' => 'Replaces a post\'s SEO fields with the given values; same values twice leave the same state.' ),
		'wp_upsert_kit_css_block'    => array( 'idempotentHint' => true, 'reason' => 'Upsert by name — same block content twice leaves the same state.' ),
		'wp_disable_elementor_custom_code' => array( 'idempotentHint' => true, 'reason' => 'Disabling an already-disabled snippet is a no-op with the same end state.' ),
		'wp_enable_elementor_custom_code'  => array( 'idempotentHint' => true, 'reason' => 'Enabling an already-enabled snippet is a no-op with the same end state.' ),
		'wp_assign_menu_location'    => array( 'idempotentHint' => true, 'reason' => 'Assigns a menu to a location; the same assignment twice leaves the same state.' ),
		'wp_assign_template'         => array( 'idempotentHint' => true, 'reason' => 'Assigns a theme-builder template; the same assignment twice leaves the same state.' ),
		'wp_reorder_menu_items'      => array( 'idempotentHint' => true, 'reason' => 'Sets an explicit final order; the same order twice leaves the same state.' ),
		'wp_reorder_widgets'         => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_reorder_menu_items.' ),
		'wp_move_widget'             => array( 'idempotentHint' => true, 'reason' => 'Moves a widget to an explicit target position; the same target twice leaves the same state.' ),
		'wp_update_rate_limit'       => array( 'idempotentHint' => true, 'reason' => 'Replaces the rate-limit config with the given values; same values twice leave the same state.' ),
		'wp_update_plugin_settings'  => array( 'idempotentHint' => true, 'reason' => 'Replaces settings with the given values; same values twice leave the same state.' ),
		'wp_update_options'          => array( 'idempotentHint' => true, 'reason' => 'Sets the given options to given values; same values twice leave the same state.' ),
		'wp_update_option'           => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_update_options.' ),
		'wp_push_to_live'            => array( 'idempotentHint' => true, 'reason' => 'Pushes the same source snapshot to live; the same push twice leaves live in the same state.' ),
		'wp_push_to_staging'         => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_push_to_live.' ),
		// Reviewed against a P1 finding that these regenerate element ids on
		// every apply. Traced instead: Mcpwp_Elementor_Pro::apply_archetype_to_page()
		// delegates to the same apply_template_to_page() that backs
		// wp_apply_elementor_template — it copies the source's stored
		// _elementor_data verbatim through save_elementor_data() ->
		// set_elementor_data(), which only fills in a MISSING id (validator:
		// "auto-generated missing ID"), never rewrites an existing valid one.
		// The generate_element_id() calls found nearby live in the unrelated
		// summarize_template_data() preview path. Confirmed idempotentHint=true
		// for these two on that evidence.
		'wp_apply_elementor_template' => array( 'idempotentHint' => true, 'reason' => 'Applies the same template content each call; applying it twice leaves the page in the same state.' ),
		'wp_apply_elementor_archetype' => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_apply_elementor_template.' ),
		// wp_apply_elementor_part is different: Mcpwp_REST_Elementor_Pro::apply_part()
		// defaults `mode` to 'insert', which calls insert_part_into_page() —
		// a loop of additive add_section() calls, not the full-replace
		// apply_template_to_page() path (that only runs for mode=replace).
		// Calling with the same args twice inserts a second copy of the
		// part's sections each time, so — unlike template/archetype — this
		// one is not idempotent.
		'wp_apply_elementor_part'    => array( 'idempotentHint' => false, 'reason' => 'Default mode=insert (Mcpwp_REST_Elementor_Pro::apply_part() -> insert_part_into_page()) appends another copy of the part\'s sections on every call; only mode=replace would behave like wp_apply_elementor_template, and that is not the default.' ),

		// --- AI crawler policy / IndexNow (#937). GET tools included even
		// though their fields match the heuristic's own default, per review:
		// this documents the review happened, not a correction. The other
		// two need real corrections (noted per field below).

		'wp_get_ai_crawler_policy' => array(
			'readOnlyHint'   => true,
			'openWorldHint'  => false,
			'idempotentHint' => true,
			'reason'         => 'Reviewed, matches default: pure read of the stored mcpwp_ai_crawler_policy option plus local robots.txt introspection (Mcpwp_REST_AI_Presence::get_crawler_policy()) — no external call, no write.',
		),
		'wp_set_ai_crawler_policy' => array(
			'idempotentHint' => true,
			'reason'         => 'readOnlyHint/destructiveHint/openWorldHint already match the base heuristic (write tool, not purely-additive by name -> destructiveHint=true by default; not in any open-world list -> openWorldHint=false by default), so only idempotentHint needs a correction: Mcpwp_AI_Crawler_Policy::set_policy() replaces the stored per-bot allow/deny map with the given values (Mcpwp_REST_AI_Presence::update_crawler_policy()) — submitting the same {bot: allow|deny} map twice leaves the same state.',
		),
		'wp_indexnow_status' => array(
			'readOnlyHint'   => true,
			'openWorldHint'  => false,
			'idempotentHint' => true,
			'reason'         => 'Reviewed, matches default: pure read of stored IndexNow settings/key/queue/last-result state (Mcpwp_REST_AI_Presence::get_indexnow_status() -> Mcpwp_IndexNow::get_status()) — no external call, no write.',
		),
		'wp_indexnow_submit' => array(
			'destructiveHint' => false,
			'openWorldHint'   => true,
			'idempotentHint'  => true,
			'reason'          => 'POST, but not purely-additive by name (verb is "indexnow_submit", not one of is_purely_additive_by_name()\'s create/add/upload/clone/duplicate/import/submit/bulk_create/bulk_upload stems — "submit" only matches as the SECOND name segment, and this tool\'s second segment is "indexnow"), so the default-destructive-for-write-tools rule would otherwise mark it destructiveHint=true. Corrected false: Mcpwp_IndexNow::submit()/validate_explicit_urls() only ever notifies the shared IndexNow endpoint about URLs that already exist and are already publicly indexable — it does not create, overwrite, or delete anything in WordPress. openWorldHint=true: pings https://api.indexnow.org/indexnow (Mcpwp_IndexNow::ENDPOINT). idempotentHint=true: IndexNow\'s own protocol treats repeat notifications of the same URL as a no-op on the receiving end, and this tool\'s own effect on WordPress state (validation only) is identical on repeat calls with the same URLs.',
		),
	);

	/**
	 * Curated title overrides for tool names the automatic humanizer reads
	 * badly for. Every other tool gets `humanize_tool_name( $name )`.
	 *
	 * @var array<string, string>
	 */
	const TITLE_OVERRIDES = array(
		'wp_bulk_find_replace'               => 'Find and replace across pages',
		'wp_get_elementor_bulk'               => 'Get multiple Elementor pages',
		'wp_cloudflare_fix_mcp_access'         => 'Fix Cloudflare MCP access',
		'wp_cloudflare_get_speed_settings'     => 'Get Cloudflare speed settings',
		'wp_cloudflare_update_speed_setting'   => 'Update Cloudflare speed setting',
		'wp_cloudflare_purge_cache'            => 'Purge Cloudflare cache',
		'wp_upsert_kit_css_block'              => 'Create or update kit CSS block',
		'wp_onboard'                           => 'Get onboarding briefing',
		'wp_introspect'                        => 'Get API introspection',
		'wp_recall'                            => 'Recall memory',
		'wp_remember'                          => 'Remember memory',
		'wp_forget'                            => 'Forget memory',
		'wp_text_to_speech'                    => 'Convert text to speech',
		'wp_get_ai_crawler_policy'             => 'Get AI crawler policy',
		'wp_set_ai_crawler_policy'             => 'Set AI crawler policy',
		'wp_indexnow_status'                   => 'IndexNow status',
		// "Submit to IndexNow" reads more naturally than the humanizer's literal
		// word order ("IndexNow submit") — same convention as
		// wp_submit_to_google_index -> "Submit to Google index".
		'wp_indexnow_submit'                   => 'Submit to IndexNow',
		// Site graph read tools (#943 S2): the humanizer would read these as
		// "Graph node" / "Graph query", which name a thing rather than an action.
		'wp_graph_node'                        => 'Get site graph node',
		'wp_graph_neighbors'                   => 'Get site graph neighbors',
		'wp_graph_query'                       => 'Query site graph',

		// wc_* tools: the humanizer would prefix every one with "WooCommerce"
		// (from the wc_ stem), which is redundant once the tool is grouped
		// under the woocommerce category — curate plain, human titles instead
		// and reserve the "WooCommerce" prefix for the two tools that need it
		// to stand alone (status, analytics).
		'wc_status'                  => 'WooCommerce status',
		'wc_analytics'               => 'WooCommerce analytics',
		'wc_list_products'           => 'List products',
		'wc_get_product'             => 'Get product',
		'wc_list_product_archetypes' => 'List product archetypes',
		'wc_get_product_archetype'   => 'Get product archetype',
		'wc_create_product_archetype' => 'Create product archetype',
		'wc_apply_product_archetype' => 'Apply product archetype',
		'wc_create_product'          => 'Create product',
		'wc_update_product'          => 'Update product',
		'wc_delete_product'          => 'Delete product',
		'wc_list_product_categories' => 'List product categories',
		'wc_create_product_category' => 'Create product category',
		'wc_update_product_category' => 'Update product category',
		'wc_list_product_tags'       => 'List product tags',
		'wc_list_orders'             => 'List orders',
		'wc_get_order'               => 'Get order',
		'wc_update_order'            => 'Update order',
		'wc_list_order_statuses'     => 'List order statuses',
		'wc_list_customers'          => 'List customers',
		'wc_get_customer'            => 'Get customer',
	);

	/**
	 * Words rendered with fixed casing regardless of position — acronyms and
	 * product/brand names that ucfirst() alone would not get right in the
	 * middle of a title (e.g. "Submit to google index" instead of
	 * "Submit to Google index").
	 *
	 * @var array<string, string>
	 */
	const KNOWN_TERMS = array(
		'seo'         => 'SEO',
		'css'         => 'CSS',
		'api'         => 'API',
		'url'         => 'URL',
		'id'          => 'ID',
		'html'        => 'HTML',
		'ai'          => 'AI',
		'faq'         => 'FAQ',
		'mcp'         => 'MCP',
		'lms'         => 'LMS',
		'cta'         => 'CTA',
		'json'        => 'JSON',
		'oauth'       => 'OAuth',
		'elementor'   => 'Elementor',
		'woocommerce' => 'WooCommerce',
		'figma'       => 'Figma',
		'cloudflare'  => 'Cloudflare',
		'google'      => 'Google',
		'gutenberg'   => 'Gutenberg',
		'pexels'      => 'Pexels',
		'indexnow'    => 'IndexNow',
	);

	/**
	 * Get the human title for a tool: curated override, else auto-humanized.
	 *
	 * @param string $name Tool name.
	 * @return string Non-empty human-readable title.
	 */
	public static function get_title( $name ) {
		if ( isset( self::TITLE_OVERRIDES[ $name ] ) ) {
			return self::TITLE_OVERRIDES[ $name ];
		}
		return self::humanize_tool_name( $name );
	}

	/**
	 * Turn a tool name into a short human title.
	 *
	 * `wp_`/`wc_` prefixes are dropped, the remaining words are split on `_`;
	 * the first word is capitalized, known acronyms/brand names keep their
	 * fixed casing wherever they appear, everything else is lowercased. E.g.
	 * `wp_get_elementor_summary` -> "Get Elementor summary".
	 *
	 * @param string $name Tool name.
	 * @return string Human title. A real (non-empty) tool name always
	 *                produces a non-empty title; an empty/degenerate name is
	 *                passed through unchanged rather than throwing.
	 */
	public static function humanize_tool_name( $name ) {
		$name = (string) $name;
		if ( '' === $name ) {
			return $name;
		}

		$stem = $name;
		if ( 0 === strpos( $stem, 'wp_' ) || 0 === strpos( $stem, 'wc_' ) ) {
			$stem = substr( $stem, 3 );
		}

		$words = array_values( array_filter( explode( '_', $stem ), 'strlen' ) );
		if ( empty( $words ) ) {
			return $name;
		}

		$out = array();
		foreach ( $words as $i => $word ) {
			$lower = strtolower( $word );
			if ( isset( self::KNOWN_TERMS[ $lower ] ) ) {
				$out[] = self::KNOWN_TERMS[ $lower ];
			} elseif ( 0 === $i ) {
				$out[] = ucfirst( $lower );
			} else {
				$out[] = $lower;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * Get the annotation override entry for a tool, if any.
	 *
	 * @param string $name Tool name.
	 * @return array<string, mixed>|null Override array, or null if the tool
	 *                                   has no override (heuristic applies).
	 */
	public static function get_override( $name ) {
		return isset( self::ANNOTATION_OVERRIDES[ $name ] ) ? self::ANNOTATION_OVERRIDES[ $name ] : null;
	}

	/**
	 * Whether a tool's name identifies it as purely additive — creates or
	 * appends a new thing without ever touching something that already
	 * existed. Used by Mcpwp_MCP_Tool_Registry::is_destructive_tool() as the
	 * one exemption from "every other write tool defaults to destructive."
	 *
	 * Deliberately narrow: only verbs that are additive in EVERY tool that
	 * uses them belong here. A verb like "apply"/"generate"/"save"/"setup" is
	 * additive in some tools and an overwrite in others (see the
	 * per-tool overrides above for those), so none of them are in this list
	 * — guessing from the verb alone would silently misclassify one of them.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function is_purely_additive_by_name( $name ) {
		return (bool) preg_match(
			'/^(?:wp|wc)_(?:create|add|upload|clone|duplicate|import|submit|bulk_create|bulk_upload)_/',
			(string) $name
		);
	}
}
