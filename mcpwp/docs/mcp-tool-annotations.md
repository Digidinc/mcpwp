# MCP Tool Annotation Audit

Generated audit of every statically-registered MCP tool (free + pro + AI integration + Figma integration) ahead of the Claude connector directory's public submission (opened 2026-09-25), which requires every tool to carry a human `title` and correctness-checked `readOnlyHint`/`destructiveHint` annotations.

Per the MCP spec: `readOnlyHint` true means the tool does not modify its environment at all — not options, not cached state, not a paid third-party call. `destructiveHint` true means "may perform destructive updates"; false means "only additive updates" — any tool that overwrites or deletes something that already existed is destructive. `Mcpwp_MCP_Tool_Registry::is_destructive_tool()` therefore treats every non-read-only, first-party tool as destructive **by default**, unless its name is a known purely-additive verb (`Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()`) or an override says otherwise. A tool can also be purely-additive *by name* while still having a parameter that switches it into an overwrite/delete mode (e.g. `wp_add_section`'s `convert`, `wp_submit_to_google_index`'s `action` accepting `URL_DELETED`) — those are caught by hand and recorded as overrides, with a mechanical guard test scanning for the same pattern (a `convert`/`replace`/`overwrite`/`mode`/`action` param whose enum or description mentions delete/replace/overwrite/destroy).

Single source of truth for every override below: `mcpwp/includes/mcp/class-mcpwp-mcp-tool-annotations.php` (`Mcpwp_MCP_Tool_Annotations::ANNOTATION_OVERRIDES` / `TITLE_OVERRIDES`). It is consulted first, ahead of the HTTP-method heuristic, each registry's own `get_destructive_tools()`/`get_open_world_tools()` lists, and the default-destructive-for-write-tools rule. A tool not listed as an override below either got its hint from an explicit first-party list/DELETE method, or was checked by hand against the new default and found correct.

Custom tools registered via the `mcpwp_register_tools` filter (`Mcpwp_Custom_Tool_Registry`) are not in this table — their name, method, destructive/open-world flags and title are declared by the registrant at registration time (see that class's docblock for the contract). That registry explicitly keeps the *old*, pre-2026-09-26 default (`destructiveHint` false unless the registrant sets `destructive => true`) via `default_destructive_for_write_tools()`, so third-party tools are unaffected by the stricter first-party default below.

**Total tools audited: 311**
- free: 180
- pro: 118
- ai integration: 8
- figma integration (Pro-gated): 5
- destructive: 103
- read-only: 149
- open-world: 37
- idempotent: 203
- tools with an explicit annotation override: 105

(+4 since the 304-tool baseline: wp_get_ai_crawler_policy, wp_set_ai_crawler_policy, wp_indexnow_status, wp_indexnow_submit — #937's AI crawler policy + IndexNow feature. +3 more: wp_graph_node, wp_graph_neighbors, wp_graph_query — #943 S2's site graph read tools, all read-only GET with curated titles.)

| Tool | Tier | Method | Title | RO | Destr | Idem | OW | Reason |
|---|---|---|---|---|---|---|---|---|
| `wp_describe_image` | ai | POST | Describe image | - | - | - | Y | Returns an AI-generated description; writes nothing anywhere (Mcpwp_REST_Integrations::describe_image()). |
| `wp_download_stock_photo` | ai | POST | Download stock photo | - | - | - | Y | Downloads a Pexels photo into a NEW media attachment (Mcpwp_Provider_Pexels::download_to_media()); additive only. |
| `wp_generate_alt_text` | ai | POST | Generate alt text | - | Y | - | Y | With auto_save=true, overwrites the attachment's existing alt text via update_post_meta() (Mcpwp_REST_Integrations::generate_alt_text()). The happy-path default (auto_save omitted) only returns suggested text, but the hint must cover the write path. |
| `wp_generate_excerpt` | ai | POST | Generate excerpt | - | Y | - | Y | With auto_save=true, overwrites the post's existing excerpt via wp_update_post() (Mcpwp_REST_Integrations::generate_excerpt()). Same reasoning as wp_generate_alt_text. |
| `wp_generate_featured_image` | ai | POST | Generate featured image | - | Y | - | Y | Generates a new media attachment AND calls set_post_thumbnail() (Mcpwp_REST_Integrations::generate_featured_image()), overwriting the post's existing featured-image assignment if it had one. |
| `wp_generate_image` | ai | POST | Generate image | - | - | - | Y | Generates an AI image into a NEW media attachment (provider generate_image_to_media()); additive only — unlike wp_generate_featured_image, it does not also assign anything. |
| `wp_search_stock_photos` | ai | GET | Search stock photos | Y | - | Y | Y | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_text_to_speech` | ai | POST | Convert text to speech | - | Y | - | Y | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_figma_status` | figma | GET | Figma status | Y | - | Y | Y | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_figma_design_tokens` | figma | GET | Get Figma design tokens | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_figma_file` | figma | GET | Get Figma file | Y | - | Y | Y | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_figma_node` | figma | GET | Get Figma node | Y | - | Y | Y | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_sync_figma_design_tokens` | figma | POST | Sync Figma design tokens | - | Y | - | Y | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_add_menu_item` | free | POST | Add menu item | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_add_section` | free | POST | Add section | - | Y | - | - | With convert=true on a page that has existing non-Elementor content and no Elementor data, converting it means "its current content will not survive" (trait-mcpwp-elementor-writer.php ensure_elementor_page_or_error()) — the same content-loss path #788 already guards against. Default (convert omitted) refuses the write rather than losing content, but the hint must cover the opt-in path. |
| `wp_analytics` | free | GET | Analytics | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_apply_approval` | free | POST | Apply approval | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_apply_internal_link` | free | POST | Apply internal link | - | - | - | - | Write tool (POST). |
| `wp_approve_request` | free | POST | Approve request | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_assign_menu_location` | free | POST | Assign menu location | - | Y | Y | - | Assigns a menu to a location; the same assignment twice leaves the same state. |
| `wp_audit_content_quality` | free | GET | Audit content quality | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_audit_media_seo` | free | GET | Audit media SEO | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_batch` | free | POST | Batch | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_batch_update` | free | POST | Batch update | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_bulk_create_pages` | free | POST | Bulk create pages | - | - | - | - | Creates new pages; additive only. |
| `wp_bulk_create_posts` | free | POST | Bulk create posts | - | - | - | - | Creates new posts; additive only. |
| `wp_bulk_find_replace` | free | POST | Find and replace across pages | - | Y | Y | - | Mutates text across many pages at once with no per-page undo; matches the same pattern-name guard as other bulk-mutation tools. |
| `wp_bulk_update_pages` | free | PUT | Bulk update pages | - | Y | - | - | Overwrites fields on many pages in one call — same reasoning as wp_bulk_update_posts. |
| `wp_bulk_update_posts` | free | PUT | Bulk update posts | - | Y | - | - | Overwrites fields on many posts in one call — same edit semantics as wp_update_post, at a much larger blast radius per mistake. |
| `wp_bulk_upload_media` | free | POST | Bulk upload media | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_check_update` | free | GET | Check update | - | - | - | Y | Calls the plugin update manifest URL over HTTP and, on success, deletes + rebuilds WordPress core's own `update_plugins` site transient (Mcpwp_REST_Site_Updates::check_update()) — a real write, not a pure read. Not destructive: the transient is core's own disposable, regenerable cache, not user content or plugin configuration. |
| `wp_clone_page` | free | POST | Clone page | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_cloudflare_fix_mcp_access` | free | POST | Fix Cloudflare MCP access | - | Y | - | Y | Calls the Cloudflare API to modify WAF/firewall rules so MCP requests are not blocked (Mcpwp_REST_Cloudflare::fix_mcp_access()) — overwrites existing firewall configuration. |
| `wp_cloudflare_get_speed_settings` | free | GET | Get Cloudflare speed settings | Y | - | Y | Y | Calls the Cloudflare API to read speed/performance settings (Mcpwp_REST_Cloudflare::get_speed_settings()). |
| `wp_cloudflare_purge_cache` | free | POST | Purge Cloudflare cache | - | - | - | Y | Calls the Cloudflare API to purge the edge cache (Mcpwp_REST_Cloudflare::purge_cache()). Not destructive: a CDN cache is disposable and regenerates from origin on the next request — no user content or configuration is lost. |
| `wp_cloudflare_status` | free | GET | Cloudflare status | Y | - | Y | Y | Calls the Cloudflare API to read zone/DNS status (Mcpwp_REST_Cloudflare). |
| `wp_cloudflare_update_speed_setting` | free | POST | Update Cloudflare speed setting | - | Y | - | Y | Calls the Cloudflare API to overwrite a persistent zone setting (Mcpwp_REST_Cloudflare::update_speed_settings()) — unlike a cache purge, this replaces real configuration. |
| `wp_configure_integration` | free | POST | Configure integration | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_create_api_key` | free | POST | Create API key | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_page` | free | POST | Create page | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_post` | free | POST | Create post | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_site_blueprint` | free | POST | Create site blueprint | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_term` | free | POST | Create term | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_webhook` | free | POST | Create webhook | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_delete_all_drafts` | free | DELETE | Delete all drafts | - | Y | Y | - | A second call finds no drafts left; same end state. |
| `wp_delete_content` | free | DELETE | Delete content | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_custom_css` | free | DELETE | Delete custom CSS | - | Y | Y | - | A second call finds the CSS already cleared; same end state. |
| `wp_delete_kit_css_block` | free | DELETE | Delete kit CSS block | - | Y | Y | - | Same reasoning as wp_delete_custom_css. |
| `wp_delete_media` | free | DELETE | Delete media | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_menu` | free | DELETE | Delete menu | - | Y | Y | - | Deleting an already-deleted menu is a no-op with the same end state. |
| `wp_delete_menu_item` | free | DELETE | Delete menu item | - | Y | Y | - | Same reasoning as wp_delete_menu. |
| `wp_delete_page` | free | DELETE | Delete page | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_post` | free | DELETE | Delete post | - | Y | Y | - | Write tool (DELETE). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_delete_term` | free | DELETE | Delete term | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_webhook` | free | DELETE | Delete webhook | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_deploy_site_blueprint` | free | POST | Deploy site blueprint | - | - | - | - | Creates NEW pages via wp_insert_post() for every blueprint page, and only reuses (never deletes) an existing same-named nav menu to append items to (Mcpwp_Site_Blueprints::deploy()); no existing page or menu item is overwritten or removed. |
| `wp_detect_plugins` | free | GET | Detect plugins | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_edit_section` | free | POST | Edit section | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_edit_widget` | free | POST | Edit widget | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_elementor_status` | free | GET | Elementor status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_elementor_widget_help` | free | GET | Elementor widget help | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_extract_site_blueprint` | free | GET | Extract site blueprint | - | - | - | - | GET, but save=true persists the extracted result via Mcpwp_Site_Blueprints::save() (Mcpwp_REST_Site_Blueprints::extract_blueprint()) — not a pure read. Not destructive: extract() never sets an `id`, so save() always mints a brand-new blueprint id — this call path can only create, never overwrite an existing stored blueprint. |
| `wp_fetch` | free | GET | Fetch | Y | - | Y | Y | Fetches an arbitrary caller-supplied external URL (/fetch). |
| `wp_flush_permalinks` | free | POST | Flush permalinks | - | - | Y | - | Regenerates WordPress's rewrite-rules cache from currently-registered post types/taxonomies; disposable and deterministically reproducible, not authored content. Idempotent: running it again from the same state produces the same rules. |
| `wp_forget` | free | DELETE | Forget memory | - | Y | Y | - | Forgetting an already-forgotten memory key is a no-op with the same end state. |
| `wp_get_agent_playbook` | free | GET | Get agent playbook | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_ai_crawler_policy` | free | GET | Get AI crawler policy | Y | - | Y | - | Reviewed, matches default: pure read of the stored mcpwp_ai_crawler_policy option plus local robots.txt introspection (Mcpwp_REST_AI_Presence::get_crawler_policy()) — no external call, no write. |
| `wp_get_approval` | free | GET | Get approval | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_block_design_system` | free | GET | Get block design system | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_blocks` | free | GET | Get blocks | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_content_coherence_report` | free | GET | Get content coherence report | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_content_graph` | free | GET | Get content graph | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_css_length` | free | GET | Get CSS length | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_custom_css` | free | GET | Get custom CSS | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_design_reference` | free | GET | Get design reference | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor` | free | GET | Get Elementor | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_bulk` | free | GET | Get multiple Elementor pages | Y | - | Y | - | Reads Elementor data for multiple pages; GET, no mutation despite the "bulk" name. |
| `wp_get_elementor_summary` | free | GET | Get Elementor summary | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_widgets` | free | GET | Get Elementor widgets | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_event_schema` | free | GET | Get event schema | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_guide` | free | GET | Get guide | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_kit_css` | free | GET | Get kit CSS | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_kit_css_block` | free | GET | Get kit CSS block | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_option` | free | GET | Get option | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_options` | free | GET | Get options | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_page_by_slug` | free | GET | Get page by slug | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_plugin_settings` | free | GET | Get plugin settings | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_post_meta` | free | GET | Get post meta | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_rendered_copy` | free | GET | Get rendered copy | Y | - | Y | Y | Same live-render fetch as wp_get_rendered_html, over the extracted-copy path. |
| `wp_get_rendered_html` | free | GET | Get rendered HTML | Y | - | Y | Y | Issues a live wp_remote_get() against the rendered front-end page, which can carry third-party embeds/widgets, not just local data. |
| `wp_get_seo_issues` | free | GET | Get SEO issues | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_seo_trends` | free | GET | Get SEO trends | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_signals` | free | GET | Get signals | - | - | - | - | GET, but the first call ever (or refresh=true) recomputes and persists the signal feed via update_option() and can fire mcpwp_event_emitted for high-severity signals (Mcpwp_REST_Signals::get_signals() -> Mcpwp_Signals::compute()) — not a pure read. Not destructive: it replaces a computed health-signal cache for the types just recomputed, not user-authored content. |
| `wp_get_site_blueprint` | free | GET | Get site blueprint | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_site_context` | free | GET | Get site context | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_site_health` | free | GET | Get site health | Y | - | Y | Y | Includes update_channel status; Mcpwp_Update_Channel_Health::get_status() fetches the update manifest over HTTP whenever its cached transient has expired (Mcpwp_REST_Site_Info::get_site_health()). |
| `wp_get_site_state` | free | GET | Get site state | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_theme_info` | free | GET | Get theme info | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_widget_schema` | free | GET | Get widget schema | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_woocommerce_seo_report` | free | GET | Get WooCommerce SEO report | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_workflow` | free | GET | Get workflow | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_graph_neighbors` | free | GET | Get site graph neighbors | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_graph_node` | free | GET | Get site graph node | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_graph_query` | free | GET | Query site graph | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_indexnow_status` | free | GET | IndexNow status | Y | - | Y | - | Reviewed, matches default: pure read of stored IndexNow settings/key/queue/last-result state (Mcpwp_REST_AI_Presence::get_indexnow_status() -> Mcpwp_IndexNow::get_status()) — no external call, no write. |
| `wp_indexnow_submit` | free | POST | Submit to IndexNow | - | - | Y | Y | POST, but not purely-additive by name ("submit" only matches as the SECOND name segment; this tool's second segment is "indexnow"), so the default rule would otherwise mark it destructive. Corrected false: only notifies the shared IndexNow endpoint about URLs that already exist and are already publicly indexable — creates/overwrites/deletes nothing in WordPress. Pings https://api.indexnow.org/indexnow (open-world). Idempotent: repeat notification of the same URLs is a no-op on the receiving end and this tool's own effect on WordPress state (validation only) is unchanged on repeat calls. |
| `wp_import_search_performance` | free | POST | Import search performance | - | Y | - | - | Evicts the oldest imports/rows once storage exceeds Mcpwp_Search_Performance::MAX_IMPORTS/MAX_ROWS (array_slice() after array_unshift()/array_merge()) — a real deletion path once the cap is reached, not purely additive despite the "import_" verb. |
| `wp_integrations_status` | free | GET | Integrations status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_introspect` | free | GET | Get API introspection | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_keyword_research` | free | GET | Keyword research | - | - | - | Y | Free/keyless by default, but enrich=true routes through a configured DataForSEO provider and spends the site's lookup budget (Mcpwp_REST_SEO_Audit::keyword_research()) — spending a budget is a write, not a pure read. Not destructive: nothing existing is overwritten, only a shared lookup-cache/budget counter is consumed. |
| `wp_list_api_keys` | free | GET | List API keys | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_approvals` | free | GET | List approvals | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_block_patterns` | free | GET | List block patterns | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_block_types` | free | GET | List block types | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_categories` | free | GET | List categories | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_content` | free | GET | List content | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_design_references` | free | GET | List design references | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_drafts` | free | GET | List drafts | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_feedback` | free | GET | List feedback | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_kit_css_blocks` | free | GET | List kit CSS blocks | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_mcp_events` | free | GET | List MCP events | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_media` | free | GET | List media | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_memories` | free | GET | List memories | - | - | - | - | Same one-time migration write as wp_recall — Mcpwp_Rest_Site_Memory::list_memories() calls Mcpwp_Site_Memory::maybe_migrate_site_context() before reading. Not destructive for the same reason. |
| `wp_list_menu_items` | free | GET | List menu items | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_menu_locations` | free | GET | List menu locations | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_menus` | free | GET | List menus | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_page_templates` | free | GET | List page templates | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_pages` | free | GET | List pages | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_posts` | free | GET | List posts | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_site_blueprints` | free | GET | List site blueprints | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_tags` | free | GET | List tags | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_webhook_events` | free | GET | List webhook events | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_webhook_logs` | free | GET | List webhook logs | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_webhooks` | free | GET | List webhooks | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_onboard` | free | GET | Get onboarding briefing | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_parse_blocks` | free | POST | Parse blocks | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_patch_block_section` | free | POST | Patch block section | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_patch_elementor` | free | POST | Patch Elementor | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_preview_elementor` | free | GET | Preview Elementor | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_rate_limit_status` | free | GET | Rate limit status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_recall` | free | GET | Recall memory | - | - | - | - | Routes to the same /memory GET handler as wp_list_memories, which runs a one-time migration write on first call (Mcpwp_Site_Memory::maybe_migrate_site_context() -> update_option()) — not a pure read. Not destructive: it sets an internal one-time migration flag, never overwriting a memory value the caller can see. |
| `wp_regenerate_elementor_css` | free | POST | Regenerate Elementor CSS | - | - | Y | - | Regenerates the derived Elementor CSS cache file from current page/kit data; disposable and deterministically reproducible, not the authoritative source (the Elementor data itself). Idempotent: same input deterministically produces the same output file. |
| `wp_reject_request` | free | POST | Reject request | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_remember` | free | POST | Remember memory | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_remove_integration` | free | POST | Remove integration | - | Y | Y | - | Deletes a stored provider credential; removing an already-removed integration is a no-op with the same end state. |
| `wp_remove_section` | free | POST | Remove section | - | Y | Y | - | Removes a section from a page; a second call on an already-removed section is a no-op with the same end state. |
| `wp_reorder_menu_items` | free | POST | Reorder menu items | - | Y | Y | - | Sets an explicit final order; the same order twice leaves the same state. |
| `wp_replace_section` | free | POST | Replace section | - | Y | Y | - | Overwrites an existing section's content with new content; replacing with the same section twice yields the same page. |
| `wp_reset_rate_limit` | free | POST | Reset rate limit | - | Y | Y | - | Resetting an already-reset counter is a no-op with the same end state. |
| `wp_revoke_api_key` | free | DELETE | Revoke API key | - | Y | Y | - | Revoking an already-revoked key is a no-op with the same end state. |
| `wp_rollback_approval` | free | POST | Rollback approval | - | Y | Y | - | Overwrites current state with a prior snapshot, discarding whatever was applied after it. |
| `wp_run_seo_autofix_plan` | free | GET | Run SEO autofix plan | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_screenshot_url` | free | POST | Screenshot URL | - | - | - | Y | Renders via an external screenshot worker; save_to_media=true adds a NEW media attachment (Mcpwp_REST_Screenshot). Additive only. |
| `wp_search` | free | GET | Search | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_seo_audit_site` | free | GET | SEO audit site | - | - | - | - | GET, but store=true persists the run to Mcpwp_SEO_Audit_Store (Mcpwp_REST_SEO_Audit::audit_seo_site()) — not a pure read on every call. Not destructive: the stored audit is a disposable, re-runnable snapshot, not user content. |
| `wp_seo_flags` | free | GET | SEO flags | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_serialize_blocks` | free | POST | Serialize blocks | Y | - | Y | - | Serializes a block structure to HTML and returns it; pure transform, no write. |
| `wp_set_ai_crawler_policy` | free | PUT | Set AI crawler policy | - | Y | Y | - | readOnlyHint/destructiveHint/openWorldHint already match the base heuristic (write tool, not purely-additive by name -> destructiveHint=true by default); idempotentHint corrected true: Mcpwp_AI_Crawler_Policy::set_policy() replaces the stored per-bot allow/deny map with the given values — submitting the same map twice leaves the same state. |
| `wp_set_blocks` | free | POST | Set blocks | - | Y | - | - | Fully replaces a post's Gutenberg block content. |
| `wp_set_custom_css` | free | POST | Set custom CSS | - | Y | Y | - | Fully replaces the site's custom CSS. |
| `wp_set_elementor` | free | POST | Set Elementor | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_set_featured_image` | free | POST | Set featured image | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_set_kit_css` | free | POST | Set kit CSS | - | Y | Y | - | Can fully replace the site's global Elementor kit CSS. |
| `wp_set_post_meta` | free | POST | Set post meta | - | Y | Y | - | Sets a meta value to a given value; setting the same value twice leaves the same state. |
| `wp_set_site_context` | free | POST | Set site context | - | Y | Y | - | Replaces the stored site-context brief; same text twice leaves the same state. |
| `wp_setup_menu` | free | POST | Setup menu | - | Y | - | - | With overwrite=true and an existing same-named menu, deletes every existing menu item on that menu before repopulating it (Mcpwp_REST_Menus::setup_menu()) — a real deletion path, not purely additive. |
| `wp_site_info` | free | GET | Site info | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_submit_feedback` | free | POST | Submit feedback | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_suggest_internal_links` | free | GET | Suggest internal links | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_test_integration` | free | POST | Test integration | - | - | - | Y | Performs a live connectivity check against the provider (Mcpwp_Integration_Manager::test_provider()); does not modify the stored provider configuration. |
| `wp_test_webhook` | free | POST | Test webhook | - | - | - | Y | Sends one external test delivery to the webhook's configured URL and appends a delivery-log row (Mcpwp_Webhooks::test()); does not modify the webhook's own stored configuration. |
| `wp_trigger_update` | free | POST | Trigger update | - | Y | - | Y | Downloads and installs a plugin package via Plugin_Upgrader, overwriting the currently-installed plugin files (Mcpwp_REST_Site_Updates); also reaches the update manifest/package host over HTTP. |
| `wp_update_design_reference` | free | POST | Update design reference | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_media` | free | POST | Update media | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_menu_item` | free | POST | Update menu item | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_option` | free | POST | Update option | - | Y | Y | - | Same reasoning as wp_update_options. |
| `wp_update_options` | free | POST | Update options | - | Y | Y | - | Sets the given options to given values; same values twice leave the same state. |
| `wp_update_page` | free | POST | Update page | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_page_template` | free | POST | Update page template | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_plugin_settings` | free | PUT | Update plugin settings | - | Y | Y | - | Replaces settings with the given values; same values twice leave the same state. |
| `wp_update_post` | free | POST | Update post | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_rate_limit` | free | POST | Update rate limit | - | Y | Y | - | Replaces the rate-limit config with the given values; same values twice leave the same state. |
| `wp_update_term` | free | POST | Update term | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_webhook` | free | POST | Update webhook | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_upload_design_reference` | free | POST | Upload design reference | - | - | - | Y | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_upload_media` | free | POST | Upload media | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_upload_media_b64` | free | POST | Upload media b64 | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_upload_media_from_url` | free | POST | Upload media from URL | - | - | - | Y | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_upsert_kit_css_block` | free | POST | Create or update kit CSS block | - | Y | Y | - | Upsert by name — same block content twice leaves the same state. |
| `wp_validate_blocks` | free | POST | Validate blocks | Y | - | Y | - | Validates block structure and returns errors/warnings; pure check, no write. |
| `wp_validate_internal_links` | free | GET | Validate internal links | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_validate_seo_readiness` | free | GET | Validate SEO readiness | Y | - | Y | Y | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_validate_structured_data` | free | GET | Validate structured data | Y | - | Y | Y | Same rendered-page fetch as wp_validate_seo_readiness (Mcpwp_REST_SEO_Audit::validate_structured_data() -> fetch_rendered_html_body()). |
| `wc_analytics` | pro | GET | WooCommerce analytics | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_apply_product_archetype` | pro | POST | Apply product archetype | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wc_create_product` | pro | POST | Create product | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wc_create_product_archetype` | pro | POST | Create product archetype | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wc_create_product_category` | pro | POST | Create product category | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wc_delete_product` | pro | DELETE | Delete product | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wc_get_customer` | pro | GET | Get customer | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_get_order` | pro | GET | Get order | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_get_product` | pro | GET | Get product | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_get_product_archetype` | pro | GET | Get product archetype | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_customers` | pro | GET | List customers | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_order_statuses` | pro | GET | List order statuses | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_orders` | pro | GET | List orders | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_product_archetypes` | pro | GET | List product archetypes | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_product_categories` | pro | GET | List product categories | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_product_tags` | pro | GET | List product tags | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_list_products` | pro | GET | List products | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_status` | pro | GET | WooCommerce status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wc_update_order` | pro | PUT | Update order | - | Y | - | Y | A status change on a WooCommerce order (Mcpwp_REST_Woocommerce::update_order()) fires WooCommerce's own order-status-changed hooks, which can send customer-facing emails depending on WooCommerce email settings. |
| `wc_update_product` | pro | PUT | Update product | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wc_update_product_category` | pro | PUT | Update product category | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_add_widget` | pro | POST | Add widget | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_analyze_seo` | pro | GET | Analyze SEO | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_apply_elementor_archetype` | pro | POST | Apply Elementor archetype | - | Y | Y | - | Same reasoning as wp_apply_elementor_template. |
| `wp_apply_elementor_part` | pro | POST | Apply Elementor part | - | Y | - | - | Default mode=insert (Mcpwp_REST_Elementor_Pro::apply_part() -> insert_part_into_page()) appends another copy of the part's sections on every call; only mode=replace would behave like wp_apply_elementor_template, and that is not the default. |
| `wp_apply_elementor_template` | pro | POST | Apply Elementor template | - | Y | Y | - | Applies the same template content each call; applying it twice leaves the page in the same state. |
| `wp_assign_template` | pro | POST | Assign template | - | Y | Y | - | Assigns a theme-builder template; the same assignment twice leaves the same state. |
| `wp_build_page` | pro | POST | Build page | - | - | - | - | Creates a NEW page from title/sections (Mcpwp_Page_Builder::build()); additive only. |
| `wp_bulk_seo` | pro | POST | Bulk SEO | - | Y | - | - | Overwrites SEO metadata on many posts in one call — same blast-radius reasoning as wp_bulk_update_posts/_pages. |
| `wp_clone_elementor_page` | pro | POST | Clone Elementor page | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_course` | pro | POST | Create course | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_course_category` | pro | POST | Create course category | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_elementor_archetype` | pro | POST | Create Elementor archetype | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_elementor_custom_code` | pro | POST | Create Elementor custom code | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_elementor_part` | pro | POST | Create Elementor part | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_elementor_part_from_section` | pro | POST | Create Elementor part from section | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_elementor_template` | pro | POST | Create Elementor template | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_event` | pro | POST | Create event | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_landing_page` | pro | POST | Create landing page | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_lesson` | pro | POST | Create lesson | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_menu` | pro | POST | Create menu | - | Y | - | - | A `location` arg reassigns that theme location's nav_menu_locations entry to the new menu (Mcpwp_Site_Manager::create_menu()), overwriting whatever menu was previously assigned there — not purely additive despite the "create_" verb. |
| `wp_create_quiz` | pro | POST | Create quiz | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_theme_template` | pro | POST | Create theme template | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_create_translation` | pro | POST | Create translation | - | - | - | - | Write tool (POST). Purely additive by name -> defaults non-destructive (Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()). |
| `wp_delete_course_category` | pro | DELETE | Delete course category | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_elementor_template` | pro | DELETE | Delete Elementor template | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_widget` | pro | DELETE | Delete widget | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_disable_elementor_custom_code` | pro | POST | Disable Elementor custom code | - | Y | Y | - | Disabling an already-disabled snippet is a no-op with the same end state. |
| `wp_enable_elementor_custom_code` | pro | POST | Enable Elementor custom code | - | Y | Y | - | Enabling an already-enabled snippet is a no-op with the same end state. |
| `wp_flush_cache` | pro | POST | Flush cache | - | - | Y | - | Clears page-cache plugin caches (W3TC/WP Super Cache/LiteSpeed/WP Rocket/Cache Enabler); disposable, regenerates from origin on next request — no user content or configuration is lost. Idempotent: flushing an already-empty cache is a no-op with the same end state. |
| `wp_forms_status` | pro | GET | Forms status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_blueprint` | pro | POST | Get blueprint | - | - | - | - | Pure generator: builds and returns one Elementor section's JSON from a blueprint type + params (Mcpwp_Page_Builder::build_single_section()); writes nothing to WordPress. |
| `wp_get_course` | pro | GET | Get course | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_curriculum` | pro | GET | Get curriculum | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_archetype` | pro | GET | Get Elementor archetype | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_custom_code` | pro | GET | Get Elementor custom code | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_globals` | pro | GET | Get Elementor globals | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_part` | pro | GET | Get Elementor part | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_elementor_template` | pro | GET | Get Elementor template | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_event` | pro | GET | Get event | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_form` | pro | GET | Get form | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_form_entries` | pro | GET | Get form entries | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_menu` | pro | GET | Get menu | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_quiz_questions` | pro | GET | Get quiz questions | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_seo` | pro | GET | Get SEO | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_sidebar` | pro | GET | Get sidebar | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_sidebar_widgets` | pro | GET | Get sidebar widgets | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_staging_info` | pro | GET | Get staging info | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_staging_status` | pro | GET | Get staging status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_theme_template` | pro | GET | Get theme template | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_translations` | pro | GET | Get translations | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_widget` | pro | GET | Get widget | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_get_widget_types` | pro | GET | Get widget types | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_google_index_status` | pro | GET | Google index status | Y | - | Y | Y | Calls the Google Indexing API to check a URL's status (Mcpwp_REST_Google_Indexing::get_status()); pro-tier open-world list is empty by default. |
| `wp_languages` | pro | GET | Languages | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_blueprints` | pro | GET | List blueprints | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_course_categories` | pro | GET | List course categories | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_courses` | pro | GET | List courses | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_elementor_archetypes` | pro | GET | List Elementor archetypes | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_elementor_custom_code` | pro | GET | List Elementor custom code | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_elementor_parts` | pro | GET | List Elementor parts | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_elementor_templates` | pro | GET | List Elementor templates | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_events` | pro | GET | List events | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_forms` | pro | GET | List forms | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_lessons` | pro | GET | List lessons | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_quizzes` | pro | GET | List quizzes | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_sidebars` | pro | GET | List sidebars | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_list_theme_templates` | pro | GET | List theme templates | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_lms_stats` | pro | GET | LMS stats | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_move_widget` | pro | POST | Move widget | - | Y | Y | - | Moves a widget to an explicit target position; the same target twice leaves the same state. |
| `wp_push_to_live` | pro | POST | Push to live | - | Y | Y | - | Pushes the same source snapshot to live; the same push twice leaves live in the same state. |
| `wp_push_to_staging` | pro | POST | Push to staging | - | Y | Y | - | Same reasoning as wp_push_to_live. |
| `wp_refresh_seo_visibility` | pro | POST | Refresh SEO visibility | - | - | - | Y | Fetches a fresh ranked-keyword snapshot from the configured DataForSEO provider and REPLACES the stored one (Mcpwp_REST_SEO_Market::refresh_visibility() -> Mcpwp_SEO_Visibility::store_snapshot()) — distinct from wp_seo_visibility, which only reads the stored snapshot. Not destructive: the snapshot is a disposable, re-fetchable market-data cache, not user-authored content. |
| `wp_reorder_widgets` | pro | POST | Reorder widgets | - | Y | Y | - | Same reasoning as wp_reorder_menu_items. |
| `wp_sanitize_elementor_custom_code` | pro | POST | Sanitize Elementor custom code | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_save_section_as_template` | pro | POST | Save section as template | - | - | - | - | Creates a NEW reusable Elementor template from a live section (Mcpwp_Elementor_Pro::save_section_as_template()); the source page/section is only read, never modified. |
| `wp_seo_opportunities` | pro | GET | SEO opportunities | - | - | - | Y | Falls through to a live DataForSEO keyword lookup (spending the lookup budget) when a seed is given and no visibility rows are stored yet (Mcpwp_REST_SEO_Market::get_opportunities()) — a real write, not a pure read. Not destructive: nothing existing is overwritten, only the shared lookup cache/budget. |
| `wp_seo_report` | pro | GET | SEO report | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_seo_scan` | pro | GET | SEO scan | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_seo_status` | pro | GET | SEO status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_seo_visibility` | pro | GET | SEO visibility | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_set_curriculum` | pro | PUT | Set curriculum | - | Y | Y | - | Fully replaces a course's curriculum structure (LearnPress). |
| `wp_set_elementor_globals` | pro | POST | Set Elementor globals | - | Y | Y | - | Replaces site-wide Elementor design globals (colors/typography) used across every page. |
| `wp_set_language` | pro | POST | Set language | - | Y | Y | - | Sets the current display language; same value twice leaves the same state. |
| `wp_set_noindex` | pro | POST | Set noindex | - | Y | Y | - | Sets a boolean flag; same value twice leaves the same state. |
| `wp_set_seo` | pro | POST | Set SEO | - | Y | Y | - | Replaces a post's SEO fields with the given values; same values twice leave the same state. |
| `wp_set_template_conditions` | pro | POST | Set template conditions | - | Y | Y | - | Fully replaces a theme-builder template's display conditions; a misconfigured call can unassign the template everywhere. |
| `wp_submit_to_google_index` | pro | POST | Submit to Google index | - | Y | - | Y | Submits URLs to the Google Indexing API (Mcpwp_REST_Google_Indexing::submit_urls()); action accepts URL_DELETED, which asks Google to drop the URL from its index — not purely additive despite the "submit_" verb. |
| `wp_theme_builder_status` | pro | GET | Theme builder status | Y | - | Y | - | Read-only (GET/HEAD/OPTIONS): no environment modification, so not destructive. |
| `wp_update_course` | pro | PUT | Update course | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_course_category` | pro | PUT | Update course category | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_elementor_custom_code` | pro | POST | Update Elementor custom code | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_elementor_template` | pro | POST | Update Elementor template | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_event` | pro | PUT | Update event | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_lesson` | pro | PUT | Update lesson | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_menu` | pro | POST | Update menu | - | Y | - | - | Write tool (POST). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_quiz` | pro | PUT | Update quiz | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
| `wp_update_widget` | pro | PUT | Update widget | - | Y | - | - | Write tool (PUT). Not purely additive by name (create/add/upload/clone/import/submit/duplicate/bulk_create/bulk_upload) -> defaults destructive under Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools(). |
