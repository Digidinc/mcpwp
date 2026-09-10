# Free vs Pro Capability Split

Source of truth for what tools are available on Free vs Pro. Updated when tools move tiers.

## Tiers

| Tier | How to activate |
|------|----------------|
| **Free** | Install plugin, generate API key — no account needed |
| **Pro** | Active Freemius license (paid or trial) |
| **Agency** | Active Freemius Agency license — all Pro + unlimited sites |

## Free Tools (class-mcpwp-mcp-free-tools.php)

Available on all installs. Gated only by API key scope.

**Content:** wp_list_pages, wp_create_page, wp_update_page, wp_delete_page, wp_clone_page, wp_bulk_create_pages, wp_bulk_update_pages, wp_list_posts, wp_create_post, wp_update_post, wp_delete_post, wp_bulk_create_posts, wp_bulk_update_posts, wp_list_content, wp_search, wp_list_drafts, wp_delete_all_drafts, wp_batch_update, wp_batch

**Elementor (basic):** wp_get_elementor, wp_set_elementor, wp_get_elementor_bulk, wp_get_elementor_summary, wp_edit_section, wp_edit_widget, wp_add_section, wp_remove_section, wp_replace_section, wp_patch_elementor, wp_preview_elementor, wp_bulk_find_replace, wp_elementor_status, wp_get_widget_schema, wp_elementor_widget_help, wp_regenerate_elementor_css

**Media:** wp_upload_media, wp_upload_media_from_url, wp_upload_media_b64, wp_update_media, wp_delete_media, wp_list_media, wp_set_featured_image

**Site:** wp_site_info, wp_introspect, wp_onboard, wp_get_site_state, wp_get_guide, wp_get_agent_playbook, wp_get_workflow, wp_get_options, wp_get_option, wp_update_option, wp_update_options, wp_get_custom_css, wp_set_custom_css, wp_delete_custom_css, wp_get_kit_css, wp_set_kit_css, wp_get_css_length, wp_set_site_context, wp_get_site_context, wp_list_design_references, wp_get_design_reference, wp_upload_design_reference, wp_update_design_reference, wp_flush_permalinks, wp_detect_plugins, wp_get_theme_info, wp_get_plugin_settings, wp_update_plugin_settings

**Menus:** wp_list_menus, wp_setup_menu, wp_add_menu_item, wp_update_menu_item, wp_delete_menu_item, wp_reorder_menu_items, wp_assign_menu_location, wp_list_menu_items, wp_list_menu_locations, wp_delete_menu

**Taxonomy:** wp_list_categories, wp_list_tags, wp_create_term, wp_update_term, wp_delete_term

**Blocks (Gutenberg):** wp_get_blocks, wp_set_blocks, wp_parse_blocks, wp_serialize_blocks, wp_validate_blocks, wp_patch_block_section, wp_list_block_types, wp_list_block_patterns, wp_get_block_design_system

**Admin:** wp_list_api_keys, wp_create_api_key, wp_revoke_api_key, wp_check_update, wp_trigger_update, wp_rate_limit_status, wp_reset_rate_limit, wp_update_rate_limit, wp_list_feedback, wp_submit_feedback, wp_integrations_status, wp_configure_integration, wp_remove_integration, wp_test_integration, wp_list_mcp_events

**Approval gates:** wp_list_approvals, wp_get_approval, wp_approve_request, wp_reject_request, wp_apply_approval, wp_rollback_approval

**Webhooks:** wp_list_webhooks, wp_create_webhook, wp_update_webhook, wp_delete_webhook, wp_test_webhook, wp_list_webhook_events, wp_list_webhook_logs

**SEO (free subset):** wp_get_seo_issues, wp_run_seo_autofix_plan, wp_validate_seo_readiness, wp_audit_media_seo, wp_audit_content_quality, wp_get_content_coherence_report, wp_validate_internal_links, wp_validate_structured_data, wp_suggest_internal_links, wp_apply_internal_link, wp_get_seo_trends, wp_get_content_graph, wp_import_search_performance

**Analytics:** wp_analytics, wp_fetch

**Content inspection:** wp_get_rendered_copy (v3.3.3 — structured visible text from live rendered page; ground truth for copy audits)

## Pro Tools (class-mcpwp-mcp-pro-tools.php)

Require active Freemius license. Loaded only when `mcpwp_license()->is_pro()` is true.

**Elementor Pro:** wp_build_page, wp_create_landing_page, wp_list_blueprints, wp_get_blueprint, wp_list_elementor_parts, wp_get_elementor_part, wp_create_elementor_part, wp_create_elementor_part_from_section, wp_apply_elementor_part, wp_save_section_as_template, wp_list_elementor_templates, wp_get_elementor_template, wp_create_elementor_template, wp_update_elementor_template, wp_delete_elementor_template, wp_apply_elementor_template, wp_list_elementor_archetypes, wp_get_elementor_archetype, wp_create_elementor_archetype, wp_apply_elementor_archetype, wp_list_elementor_custom_code, wp_get_elementor_custom_code, wp_create_elementor_custom_code, wp_update_elementor_custom_code, wp_enable_elementor_custom_code, wp_disable_elementor_custom_code, wp_sanitize_elementor_custom_code, wp_get_elementor_globals, wp_set_elementor_globals, wp_theme_builder_status, wp_list_theme_templates, wp_get_theme_template, wp_create_theme_template, wp_set_template_conditions, wp_assign_template, wp_add_widget, wp_get_widget, wp_update_widget, wp_delete_widget, wp_move_widget, wp_reorder_widgets, wp_list_sidebars, wp_get_sidebar, wp_get_sidebar_widgets, wp_get_widget_types

**SEO (Pro):** wp_get_seo, wp_set_seo, wp_set_noindex, wp_seo_report, wp_seo_scan, wp_seo_status, wp_bulk_seo, wp_analyze_seo, wp_google_index_status, wp_submit_to_google_index, wp_get_woocommerce_seo_report, wp_get_event_schema, wp_validate_structured_data (extended)

**WooCommerce:** Available via includes/mcp/class-mcpwp-mcp-woocommerce-tools.php when WC is active + Pro

**LearnPress LMS:** wp_list_courses, wp_get_course, wp_create_course, wp_update_course, wp_delete_course_category, wp_list_course_categories, wp_create_course_category, wp_update_course_category, wp_list_lessons, wp_create_lesson, wp_update_lesson, wp_list_quizzes, wp_create_quiz, wp_update_quiz, wp_get_quiz_questions, wp_set_curriculum, wp_get_curriculum, wp_lms_stats

**Multisite:** wp_network_sites, wp_network_stats, wp_network_switch

**Events:** wp_list_events, wp_get_event, wp_create_event, wp_update_event

**Forms:** wp_list_forms, wp_get_form, wp_get_form_entries, wp_forms_status

**Translation:** wp_get_translations, wp_create_translation, wp_set_language, wp_languages

**Figma:** wp_figma_status, wp_get_figma_file, wp_get_figma_node, wp_get_figma_design_tokens, wp_sync_figma_design_tokens (v3.11.1: gated at the capability. `Mcpwp_Figma` refuses every Figma call and every write of Figma-derived data on Free, fail-closed. So on Free: the `/figma/*` REST routes answer 403 `pro_required`; the Figma MCP tools are not listed; the hourly design-token cron is not scheduled and an existing event is cleared; Integrations "Test connection" and the Figma OAuth flow refuse; and an expired OAuth token is never refreshed. **Storing** a Figma personal token or OAuth app on Free is allowed, because with every use refused it unlocks nothing. Status reports it as configured and, separately, as `pro_required`.)

**SEO market data (DataForSEO):** wp_seo_visibility, wp_refresh_seo_visibility, wp_seo_opportunities (v3.11.1: `GET /seo/visibility`, `POST /seo/visibility/refresh` and `GET /seo/opportunities` answer 403 `pro_required` on Free, including through `POST /batch`). Open tier question: the free `wp_keyword_research` enrichment also spends DataForSEO credit when credentials are configured.

**Cache:** wp_flush_cache (v3.3.3 — flushes WP page cache: W3TC, WP Super Cache, LiteSpeed, WP Rocket, Cache Enabler, Breeze, Cloudflare; optional `url` param for per-URL flush, omit for site-wide)

## WP.org Build Constraints

The WP.org (free) build must:
- Not include `mcpwp/includes/pro/` directory
- Not include `mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php`
- Not include `mcpwp/includes/mcp/class-mcpwp-mcp-woocommerce-tools.php`
- Not reference Freemius SDK (alternative: use freemius-init-lite.php stub)
- Pass WP.org plugin review (no obfuscation, no remote code execution)

The Freemius/paid build includes all files and loads Pro tools conditionally via `mcpwp_license()->is_pro()`.

## Gating Pattern

REST routes that belong to a Pro capability use the shared permission callback on `Mcpwp_REST_API`, so the tier is enforced on every path that reaches the route (direct HTTP, `POST /batch`, and MCP `tools/call`, which all dispatch through the REST server):

```php
register_rest_route( $this->namespace, '/my-pro-route', array(
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => array( $this, 'handle' ),
    'permission_callback' => array( $this, 'check_pro_permission' ), // auth first, then 403 pro_required
) );

// Or inside a handler shared by Free and Pro routes:
$pro = $this->require_pro(); // true|WP_Error pro_required (403)
```

The check behind both, `Mcpwp_REST_API::is_pro_entitled()`, fails closed: a WP.org build, a license class that is not loaded, or any answer other than `true` is "not entitled". Do not write `class_exists( 'Mcpwp_License' ) && ! is_pro()` as a deny condition — it allows when the class is missing.

MCP tools in class-mcpwp-mcp-pro-tools.php are only registered when the license is Pro, so they don't appear in `tools/list` for free users (a call answers JSON-RPC -32004). A built-in `Mcpwp_Integration` whose tools are Pro returns no tools and no tool map without Pro (`is_mcpwp_pro_active()`), and keeps its tool categories so the names stay reserved.
