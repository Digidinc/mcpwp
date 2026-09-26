# MCP Tool Annotation Audit

Generated audit of every statically-registered MCP tool (free + pro + AI integration + Figma integration) ahead of the Claude connector directory's public submission (opened 2026-09-25), which requires every tool to carry a human `title` and correctness-checked `readOnlyHint`/`destructiveHint` annotations.

Single source of truth for every override below: `mcpwp/includes/mcp/class-mcpwp-mcp-tool-annotations.php` (`Mcpwp_MCP_Tool_Annotations::ANNOTATION_OVERRIDES` / `TITLE_OVERRIDES`). It is consulted by `Mcpwp_MCP_Tool_Registry` before the HTTP-method heuristic (readOnly = GET/HEAD/OPTIONS, destructive = DELETE) and before each registry's own `get_destructive_tools()`/`get_open_world_tools()` lists. A tool not listed as "Overridden" below is trusted to the heuristic/list and was checked by hand during this audit.

Custom tools registered via the `mcpwp_register_tools` filter (`Mcpwp_Custom_Tool_Registry`) are not in this table — their name, method, destructive/open-world flags and title are declared by the registrant at registration time (see that class's docblock for the contract); they inherit the same title-generation and HTTP-method heuristic, just not a static, name-keyed override.

**Total tools audited: 303**
- free: 172
- pro: 118
- ai integration: 8
- figma integration (Pro-gated): 5
- destructive: 37
- read-only: 150
- open-world: 25
- idempotent: 203
- tools with an explicit annotation override: 71

| Tool | Tier | Method | Title | RO | Destr | Idem | OW | Reason |
|---|---|---|---|---|---|---|---|---|
| `wp_describe_image` | ai | POST | Describe image | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_download_stock_photo` | ai | POST | Download stock photo | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_generate_alt_text` | ai | POST | Generate alt text | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_generate_excerpt` | ai | POST | Generate excerpt | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_generate_featured_image` | ai | POST | Generate featured image | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_generate_image` | ai | POST | Generate image | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_search_stock_photos` | ai | GET | Search stock photos | Y | - | Y | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_text_to_speech` | ai | POST | Convert text to speech | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_figma_status` | figma | GET | Figma status | Y | - | Y | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_figma_design_tokens` | figma | GET | Get Figma design tokens | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_figma_file` | figma | GET | Get Figma file | Y | - | Y | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_figma_node` | figma | GET | Get Figma node | Y | - | Y | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_sync_figma_design_tokens` | figma | POST | Sync Figma design tokens | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_add_menu_item` | free | POST | Add menu item | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_add_section` | free | POST | Add section | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_analytics` | free | GET | Analytics | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_apply_approval` | free | POST | Apply approval | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_apply_internal_link` | free | POST | Apply internal link | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_approve_request` | free | POST | Approve request | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_assign_menu_location` | free | POST | Assign menu location | - | - | Y | - | Assigns a menu to a location; the same assignment twice leaves the same state. |
| `wp_audit_content_quality` | free | GET | Audit content quality | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_audit_media_seo` | free | GET | Audit media SEO | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_batch` | free | POST | Batch | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_batch_update` | free | POST | Batch update | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_bulk_create_pages` | free | POST | Bulk create pages | - | - | - | - | Creates new pages; additive only. |
| `wp_bulk_create_posts` | free | POST | Bulk create posts | - | - | - | - | Creates new posts; additive only. |
| `wp_bulk_find_replace` | free | POST | Find and replace across pages | - | Y | Y | - | Mutates text across many pages at once with no per-page undo; matches the same pattern-name guard as other bulk-mutation tools. |
| `wp_bulk_update_pages` | free | PUT | Bulk update pages | - | Y | - | - | Overwrites fields on many pages in one call — same reasoning as wp_bulk_update_posts. |
| `wp_bulk_update_posts` | free | PUT | Bulk update posts | - | Y | - | - | Overwrites fields on many posts in one call — same edit semantics as wp_update_post, at a much larger blast radius per mistake. |
| `wp_bulk_upload_media` | free | POST | Bulk upload media | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_check_update` | free | GET | Check update | Y | - | Y | Y | Calls the plugin update manifest URL over HTTP (Mcpwp_Updater::check_for_update() -> wp_remote_get()). |
| `wp_clone_page` | free | POST | Clone page | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_cloudflare_fix_mcp_access` | free | POST | Fix Cloudflare MCP access | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_cloudflare_get_speed_settings` | free | GET | Get Cloudflare speed settings | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_cloudflare_purge_cache` | free | POST | Purge Cloudflare cache | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_cloudflare_status` | free | GET | Cloudflare status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_cloudflare_update_speed_setting` | free | POST | Update Cloudflare speed setting | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_configure_integration` | free | POST | Configure integration | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_api_key` | free | POST | Create API key | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_page` | free | POST | Create page | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_post` | free | POST | Create post | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_site_blueprint` | free | POST | Create site blueprint | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_term` | free | POST | Create term | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_webhook` | free | POST | Create webhook | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_delete_all_drafts` | free | DELETE | Delete all drafts | - | Y | Y | - | A second call finds no drafts left; same end state. |
| `wp_delete_content` | free | DELETE | Delete content | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_custom_css` | free | DELETE | Delete custom CSS | - | Y | Y | - | A second call finds the CSS already cleared; same end state. |
| `wp_delete_kit_css_block` | free | DELETE | Delete kit CSS block | - | Y | Y | - | Same reasoning as wp_delete_custom_css. |
| `wp_delete_media` | free | DELETE | Delete media | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_menu` | free | DELETE | Delete menu | - | Y | Y | - | Deleting an already-deleted menu is a no-op with the same end state. |
| `wp_delete_menu_item` | free | DELETE | Delete menu item | - | Y | Y | - | Same reasoning as wp_delete_menu. |
| `wp_delete_page` | free | DELETE | Delete page | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_post` | free | DELETE | Delete post | - | Y | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_delete_term` | free | DELETE | Delete term | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_webhook` | free | DELETE | Delete webhook | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_deploy_site_blueprint` | free | POST | Deploy site blueprint | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_detect_plugins` | free | GET | Detect plugins | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_edit_section` | free | POST | Edit section | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_edit_widget` | free | POST | Edit widget | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_elementor_status` | free | GET | Elementor status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_elementor_widget_help` | free | GET | Elementor widget help | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_extract_site_blueprint` | free | GET | Extract site blueprint | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_fetch` | free | GET | Fetch | Y | - | Y | Y | Fetches an arbitrary caller-supplied external URL (/fetch). |
| `wp_flush_permalinks` | free | POST | Flush permalinks | - | - | Y | - | Regenerates rewrite rules from current state; running it again produces the same rules. |
| `wp_forget` | free | DELETE | Forget memory | - | Y | Y | - | Forgetting an already-forgotten memory key is a no-op with the same end state. |
| `wp_get_agent_playbook` | free | GET | Get agent playbook | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_approval` | free | GET | Get approval | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_block_design_system` | free | GET | Get block design system | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_blocks` | free | GET | Get blocks | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_content_coherence_report` | free | GET | Get content coherence report | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_content_graph` | free | GET | Get content graph | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_css_length` | free | GET | Get CSS length | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_custom_css` | free | GET | Get custom CSS | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_design_reference` | free | GET | Get design reference | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor` | free | GET | Get Elementor | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_bulk` | free | GET | Get multiple Elementor pages | Y | - | Y | - | Reads Elementor data for multiple pages; GET, no mutation despite the "bulk" name. |
| `wp_get_elementor_summary` | free | GET | Get Elementor summary | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_widgets` | free | GET | Get Elementor widgets | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_event_schema` | free | GET | Get event schema | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_guide` | free | GET | Get guide | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_kit_css` | free | GET | Get kit CSS | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_kit_css_block` | free | GET | Get kit CSS block | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_option` | free | GET | Get option | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_options` | free | GET | Get options | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_page_by_slug` | free | GET | Get page by slug | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_plugin_settings` | free | GET | Get plugin settings | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_post_meta` | free | GET | Get post meta | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_rendered_copy` | free | GET | Get rendered copy | Y | - | Y | Y | Same live-render fetch as wp_get_rendered_html, over the extracted-copy path. |
| `wp_get_rendered_html` | free | GET | Get rendered HTML | Y | - | Y | Y | Issues a live wp_remote_get() against the rendered front-end page, which can carry third-party embeds/widgets, not just local data. |
| `wp_get_seo_issues` | free | GET | Get SEO issues | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_seo_trends` | free | GET | Get SEO trends | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_signals` | free | GET | Get signals | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_site_blueprint` | free | GET | Get site blueprint | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_site_context` | free | GET | Get site context | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_site_health` | free | GET | Get site health | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_site_state` | free | GET | Get site state | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_theme_info` | free | GET | Get theme info | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_widget_schema` | free | GET | Get widget schema | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_woocommerce_seo_report` | free | GET | Get WooCommerce SEO report | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_workflow` | free | GET | Get workflow | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_import_search_performance` | free | POST | Import search performance | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_integrations_status` | free | GET | Integrations status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_introspect` | free | GET | Get API introspection | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_keyword_research` | free | GET | Keyword research | Y | - | Y | Y | Free/keyless by default, but enrich=true routes through a configured DataForSEO provider and spends the site's lookup budget (Mcpwp_REST_SEO_Audit::keyword_research()). |
| `wp_list_api_keys` | free | GET | List API keys | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_approvals` | free | GET | List approvals | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_block_patterns` | free | GET | List block patterns | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_block_types` | free | GET | List block types | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_categories` | free | GET | List categories | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_content` | free | GET | List content | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_design_references` | free | GET | List design references | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_drafts` | free | GET | List drafts | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_feedback` | free | GET | List feedback | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_kit_css_blocks` | free | GET | List kit CSS blocks | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_mcp_events` | free | GET | List MCP events | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_media` | free | GET | List media | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_memories` | free | GET | List memories | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_menu_items` | free | GET | List menu items | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_menu_locations` | free | GET | List menu locations | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_menus` | free | GET | List menus | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_page_templates` | free | GET | List page templates | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_pages` | free | GET | List pages | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_posts` | free | GET | List posts | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_site_blueprints` | free | GET | List site blueprints | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_tags` | free | GET | List tags | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_webhook_events` | free | GET | List webhook events | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_webhook_logs` | free | GET | List webhook logs | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_webhooks` | free | GET | List webhooks | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_onboard` | free | GET | Get onboarding briefing | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_parse_blocks` | free | POST | Parse blocks | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_patch_block_section` | free | POST | Patch block section | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_patch_elementor` | free | POST | Patch Elementor | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_preview_elementor` | free | GET | Preview Elementor | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_rate_limit_status` | free | GET | Rate limit status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_recall` | free | GET | Recall memory | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_regenerate_elementor_css` | free | POST | Regenerate Elementor CSS | - | - | Y | - | Deterministically regenerates CSS from current page data; same input produces the same output file. |
| `wp_reject_request` | free | POST | Reject request | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_remember` | free | POST | Remember memory | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_remove_integration` | free | POST | Remove integration | - | Y | Y | - | Deletes a stored provider credential; removing an already-removed integration is a no-op with the same end state. |
| `wp_remove_section` | free | POST | Remove section | - | Y | Y | - | Removes a section from a page; a second call on an already-removed section is a no-op with the same end state. |
| `wp_reorder_menu_items` | free | POST | Reorder menu items | - | - | Y | - | Sets an explicit final order; the same order twice leaves the same state. |
| `wp_replace_section` | free | POST | Replace section | - | Y | Y | - | Overwrites an existing section's content with new content; replacing with the same section twice yields the same page. |
| `wp_reset_rate_limit` | free | POST | Reset rate limit | - | Y | Y | - | Resetting an already-reset counter is a no-op with the same end state. |
| `wp_revoke_api_key` | free | DELETE | Revoke API key | - | Y | Y | - | Revoking an already-revoked key is a no-op with the same end state. |
| `wp_rollback_approval` | free | POST | Rollback approval | - | Y | Y | - | Overwrites current state with a prior snapshot, discarding whatever was applied after it. |
| `wp_run_seo_autofix_plan` | free | GET | Run SEO autofix plan | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_screenshot_url` | free | POST | Screenshot URL | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_search` | free | GET | Search | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_seo_audit_site` | free | GET | SEO audit site | - | - | - | - | GET, but store=true persists the run to Mcpwp_SEO_Audit_Store (Mcpwp_REST_SEO_Audit::audit_seo_site()) — not a pure read on every call. |
| `wp_serialize_blocks` | free | POST | Serialize blocks | Y | - | Y | - | Serializes a block structure to HTML and returns it; pure transform, no write. |
| `wp_set_blocks` | free | POST | Set blocks | - | Y | - | - | Fully replaces a post's Gutenberg block content. |
| `wp_set_custom_css` | free | POST | Set custom CSS | - | Y | Y | - | Fully replaces the site's custom CSS. |
| `wp_set_elementor` | free | POST | Set Elementor | - | Y | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_set_featured_image` | free | POST | Set featured image | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_set_kit_css` | free | POST | Set kit CSS | - | Y | Y | - | Can fully replace the site's global Elementor kit CSS. |
| `wp_set_post_meta` | free | POST | Set post meta | - | - | Y | - | Sets a meta value to a given value; setting the same value twice leaves the same state. |
| `wp_set_site_context` | free | POST | Set site context | - | - | Y | - | Replaces the stored site-context brief; same text twice leaves the same state. |
| `wp_setup_menu` | free | POST | Setup menu | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_site_info` | free | GET | Site info | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_submit_feedback` | free | POST | Submit feedback | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_suggest_internal_links` | free | GET | Suggest internal links | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_test_integration` | free | POST | Test integration | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_test_webhook` | free | POST | Test webhook | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_trigger_update` | free | POST | Trigger update | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_design_reference` | free | POST | Update design reference | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_media` | free | POST | Update media | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_menu_item` | free | POST | Update menu item | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_option` | free | POST | Update option | - | - | Y | - | Same reasoning as wp_update_options. |
| `wp_update_options` | free | POST | Update options | - | - | Y | - | Sets the given options to given values; same values twice leave the same state. |
| `wp_update_page` | free | POST | Update page | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_page_template` | free | POST | Update page template | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_plugin_settings` | free | PUT | Update plugin settings | - | - | Y | - | Replaces settings with the given values; same values twice leave the same state. |
| `wp_update_post` | free | POST | Update post | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_rate_limit` | free | POST | Update rate limit | - | - | Y | - | Replaces the rate-limit config with the given values; same values twice leave the same state. |
| `wp_update_term` | free | POST | Update term | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_webhook` | free | POST | Update webhook | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_upload_design_reference` | free | POST | Upload design reference | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_upload_media` | free | POST | Upload media | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_upload_media_b64` | free | POST | Upload media b64 | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_upload_media_from_url` | free | POST | Upload media from URL | - | - | - | Y | heuristic (method-derived); reviewed, no correction needed |
| `wp_upsert_kit_css_block` | free | POST | Create or update kit CSS block | - | - | Y | - | Upsert by name — same block content twice leaves the same state. |
| `wp_validate_blocks` | free | POST | Validate blocks | Y | - | Y | - | Validates block structure and returns errors/warnings; pure check, no write. |
| `wp_validate_internal_links` | free | GET | Validate internal links | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_validate_seo_readiness` | free | GET | Validate SEO readiness | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_validate_structured_data` | free | GET | Validate structured data | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_analytics` | pro | GET | WooCommerce analytics | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_apply_product_archetype` | pro | POST | Apply product archetype | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_create_product` | pro | POST | Create product | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_create_product_archetype` | pro | POST | Create product archetype | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_create_product_category` | pro | POST | Create product category | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_delete_product` | pro | DELETE | Delete product | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wc_get_customer` | pro | GET | Get customer | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_get_order` | pro | GET | Get order | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_get_product` | pro | GET | Get product | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_get_product_archetype` | pro | GET | Get product archetype | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_customers` | pro | GET | List customers | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_order_statuses` | pro | GET | List order statuses | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_orders` | pro | GET | List orders | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_product_archetypes` | pro | GET | List product archetypes | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_product_categories` | pro | GET | List product categories | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_product_tags` | pro | GET | List product tags | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_list_products` | pro | GET | List products | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_status` | pro | GET | WooCommerce status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_update_order` | pro | PUT | Update order | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_update_product` | pro | PUT | Update product | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wc_update_product_category` | pro | PUT | Update product category | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_add_widget` | pro | POST | Add widget | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_analyze_seo` | pro | GET | Analyze SEO | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_apply_elementor_archetype` | pro | POST | Apply Elementor archetype | - | Y | Y | - | Same reasoning as wp_apply_elementor_template. |
| `wp_apply_elementor_part` | pro | POST | Apply Elementor part | - | Y | Y | - | Same reasoning as wp_apply_elementor_template. |
| `wp_apply_elementor_template` | pro | POST | Apply Elementor template | - | Y | Y | - | Applies the same template content each call; applying it twice leaves the page in the same state. |
| `wp_assign_template` | pro | POST | Assign template | - | - | Y | - | Assigns a theme-builder template; the same assignment twice leaves the same state. |
| `wp_build_page` | pro | POST | Build page | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_bulk_seo` | pro | POST | Bulk SEO | - | Y | - | - | Overwrites SEO metadata on many posts in one call — same blast-radius reasoning as wp_bulk_update_posts/_pages. |
| `wp_clone_elementor_page` | pro | POST | Clone Elementor page | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_course` | pro | POST | Create course | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_course_category` | pro | POST | Create course category | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_elementor_archetype` | pro | POST | Create Elementor archetype | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_elementor_custom_code` | pro | POST | Create Elementor custom code | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_elementor_part` | pro | POST | Create Elementor part | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_elementor_part_from_section` | pro | POST | Create Elementor part from section | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_elementor_template` | pro | POST | Create Elementor template | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_event` | pro | POST | Create event | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_landing_page` | pro | POST | Create landing page | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_lesson` | pro | POST | Create lesson | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_menu` | pro | POST | Create menu | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_quiz` | pro | POST | Create quiz | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_theme_template` | pro | POST | Create theme template | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_create_translation` | pro | POST | Create translation | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_delete_course_category` | pro | DELETE | Delete course category | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_elementor_template` | pro | DELETE | Delete Elementor template | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_delete_widget` | pro | DELETE | Delete widget | - | Y | Y | - | Same reasoning as wp_delete_post. |
| `wp_disable_elementor_custom_code` | pro | POST | Disable Elementor custom code | - | - | Y | - | Disabling an already-disabled snippet is a no-op with the same end state. |
| `wp_enable_elementor_custom_code` | pro | POST | Enable Elementor custom code | - | - | Y | - | Enabling an already-enabled snippet is a no-op with the same end state. |
| `wp_flush_cache` | pro | POST | Flush cache | - | - | Y | - | Flushing an already-empty cache is a no-op with the same end state. |
| `wp_forms_status` | pro | GET | Forms status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_blueprint` | pro | POST | Get blueprint | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_course` | pro | GET | Get course | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_curriculum` | pro | GET | Get curriculum | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_archetype` | pro | GET | Get Elementor archetype | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_custom_code` | pro | GET | Get Elementor custom code | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_globals` | pro | GET | Get Elementor globals | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_part` | pro | GET | Get Elementor part | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_elementor_template` | pro | GET | Get Elementor template | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_event` | pro | GET | Get event | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_form` | pro | GET | Get form | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_form_entries` | pro | GET | Get form entries | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_menu` | pro | GET | Get menu | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_quiz_questions` | pro | GET | Get quiz questions | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_seo` | pro | GET | Get SEO | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_sidebar` | pro | GET | Get sidebar | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_sidebar_widgets` | pro | GET | Get sidebar widgets | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_staging_info` | pro | GET | Get staging info | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_staging_status` | pro | GET | Get staging status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_theme_template` | pro | GET | Get theme template | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_translations` | pro | GET | Get translations | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_widget` | pro | GET | Get widget | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_get_widget_types` | pro | GET | Get widget types | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_google_index_status` | pro | GET | Google index status | Y | - | Y | Y | Calls the Google Indexing API to check a URL's status (Mcpwp_REST_Google_Indexing::get_status()); pro-tier open-world list is empty by default. |
| `wp_languages` | pro | GET | Languages | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_blueprints` | pro | GET | List blueprints | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_course_categories` | pro | GET | List course categories | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_courses` | pro | GET | List courses | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_elementor_archetypes` | pro | GET | List Elementor archetypes | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_elementor_custom_code` | pro | GET | List Elementor custom code | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_elementor_parts` | pro | GET | List Elementor parts | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_elementor_templates` | pro | GET | List Elementor templates | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_events` | pro | GET | List events | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_forms` | pro | GET | List forms | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_lessons` | pro | GET | List lessons | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_quizzes` | pro | GET | List quizzes | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_sidebars` | pro | GET | List sidebars | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_list_theme_templates` | pro | GET | List theme templates | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_lms_stats` | pro | GET | LMS stats | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_move_widget` | pro | POST | Move widget | - | - | Y | - | Moves a widget to an explicit target position; the same target twice leaves the same state. |
| `wp_push_to_live` | pro | POST | Push to live | - | Y | Y | - | Pushes the same source snapshot to live; the same push twice leaves live in the same state. |
| `wp_push_to_staging` | pro | POST | Push to staging | - | - | Y | - | Same reasoning as wp_push_to_live. |
| `wp_refresh_seo_visibility` | pro | POST | Refresh SEO visibility | - | - | - | Y | Fetches a fresh ranked-keyword snapshot from the configured DataForSEO provider and stores it (Mcpwp_REST_SEO_Market::refresh_visibility()) — distinct from wp_seo_visibility, which only reads the stored snapshot. |
| `wp_reorder_widgets` | pro | POST | Reorder widgets | - | - | Y | - | Same reasoning as wp_reorder_menu_items. |
| `wp_sanitize_elementor_custom_code` | pro | POST | Sanitize Elementor custom code | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_save_section_as_template` | pro | POST | Save section as template | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_seo_opportunities` | pro | GET | SEO opportunities | Y | - | Y | Y | Falls through to a live DataForSEO keyword lookup (spending the lookup budget) when a seed is given and no visibility rows are stored yet (Mcpwp_REST_SEO_Market::get_opportunities()). |
| `wp_seo_report` | pro | GET | SEO report | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_seo_scan` | pro | GET | SEO scan | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_seo_status` | pro | GET | SEO status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_seo_visibility` | pro | GET | SEO visibility | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_set_curriculum` | pro | PUT | Set curriculum | - | Y | Y | - | Fully replaces a course's curriculum structure (LearnPress). |
| `wp_set_elementor_globals` | pro | POST | Set Elementor globals | - | Y | Y | - | Replaces site-wide Elementor design globals (colors/typography) used across every page. |
| `wp_set_language` | pro | POST | Set language | - | - | Y | - | Sets the current display language; same value twice leaves the same state. |
| `wp_set_noindex` | pro | POST | Set noindex | - | - | Y | - | Sets a boolean flag; same value twice leaves the same state. |
| `wp_set_seo` | pro | POST | Set SEO | - | - | Y | - | Replaces a post's SEO fields with the given values; same values twice leave the same state. |
| `wp_set_template_conditions` | pro | POST | Set template conditions | - | Y | Y | - | Fully replaces a theme-builder template's display conditions; a misconfigured call can unassign the template everywhere. |
| `wp_submit_to_google_index` | pro | POST | Submit to Google index | - | - | - | Y | Submits URLs to the Google Indexing API (Mcpwp_REST_Google_Indexing::submit_urls()). |
| `wp_theme_builder_status` | pro | GET | Theme builder status | Y | - | Y | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_course` | pro | PUT | Update course | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_course_category` | pro | PUT | Update course category | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_elementor_custom_code` | pro | POST | Update Elementor custom code | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_elementor_template` | pro | POST | Update Elementor template | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_event` | pro | PUT | Update event | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_lesson` | pro | PUT | Update lesson | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_menu` | pro | POST | Update menu | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_quiz` | pro | PUT | Update quiz | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
| `wp_update_widget` | pro | PUT | Update widget | - | - | - | - | heuristic (method-derived); reviewed, no correction needed |
