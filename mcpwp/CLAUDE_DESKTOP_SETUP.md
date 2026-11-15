# Connecting AI Assistants to MCPWP

## Quick Start

MCPWP exposes your WordPress site as an MCP server. Any MCP-compatible client (Claude Desktop, Claude Code, Cursor, Windsurf, etc.) can connect directly over HTTP.

```
AI Client → POST /wp-json/mcpwp/v1/mcp → WordPress Plugin → 200+ tools
```

## Prerequisites

1. MCPWP plugin installed and activated (v2.8.31+)
2. API key generated (WordPress Admin → MCPWP → Setup)
3. An MCP-compatible AI client

## Setup by Client

### Claude Code (CLI)

Create or edit `.mcp.json` in your project root:

```json
{
  "mcpServers": {
    "my-wordpress": {
      "url": "https://yoursite.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_your_api_key_here"
      }
    }
  }
}
```

Restart Claude Code. Your tools appear automatically.

### Claude Desktop

Edit the config file:

- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`
- **Linux:** `~/.config/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "my-wordpress": {
      "transport": {
        "type": "http",
        "url": "https://yoursite.com/wp-json/mcpwp/v1/mcp",
        "headers": {
          "X-API-Key": "mcpwp_your_api_key_here"
        }
      }
    }
  }
}
```

Restart Claude Desktop to load.

### Cursor / Windsurf / Other MCP Clients

Most MCP clients support Streamable HTTP. Use the same endpoint URL and API key header — refer to your client's MCP documentation for the config format.

## Multiple Sites

```json
{
  "mcpServers": {
    "production": {
      "url": "https://mysite.com/wp-json/mcpwp/v1/mcp",
      "headers": { "X-API-Key": "mcpwp_production_key" }
    },
    "staging": {
      "url": "https://staging.mysite.com/wp-json/mcpwp/v1/mcp",
      "headers": { "X-API-Key": "mcpwp_staging_key" }
    }
  }
}
```

## Verify Connection

```bash
curl -X POST "https://yoursite.com/wp-json/mcpwp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: mcpwp_your_key" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}' | jq '.result.tools | length'
```

Should return a number (100+). Or just ask your AI: *"What WordPress tools do you have?"*

## Tool Categories (200+)

| Category | Examples | Plan scope |
|----------|----------|------|
| **Site & Settings** | wp_site_info, wp_onboard, wp_get_options, wp_get_custom_css, wp_set_custom_css | Core |
| **Content** | wp_list_posts, wp_create_post, wp_list_pages, wp_create_page, wp_search, wp_bulk_create_posts | Core |
| **Media** | wp_upload_media, wp_upload_media_from_url, wp_list_media | Core |
| **Menus** | wp_list_menus, wp_setup_menu, wp_add_menu_item, wp_reorder_menu_items | Core |
| **Elementor** | wp_get_elementor, wp_set_elementor, wp_edit_section, wp_get_elementor_summary, wp_preview_elementor, wp_build_page | Core |
| **Widgets & Sidebars** | wp_list_sidebars, wp_add_widget, wp_update_widget, wp_reorder_widgets | Core |
| **Webhooks & API Keys** | wp_create_webhook, wp_list_webhooks, wp_create_api_key | Core |
| **AI Education** | wp_get_guide, wp_widget_help, wp_get_error_hint, wp_list_workflows | Core |
| **SEO** | wp_get_seo, wp_set_seo, wp_analyze_seo, wp_bulk_seo, wp_seo_scan, wp_seo_report | Paid |
| **Forms** | wp_list_forms, wp_get_form, wp_get_form_entries | Paid |
| **Elementor Pro** | wp_list_elementor_templates, wp_apply_elementor_template, wp_create_theme_template, wp_clone_elementor_page | Paid |
| **Theme Builder** | wp_theme_builder_status, wp_list_theme_templates, wp_set_template_conditions | Paid |
| **LearnPress** | wp_list_courses, wp_create_course, wp_list_lessons, wp_list_quizzes (18 tools) | Paid |
| **WooCommerce** | wp_list_products, wp_create_product, wp_list_orders, wp_list_coupons (17 tools) | Paid |
| **Events** | wp_list_events, wp_create_event, wp_event_bookings | Paid |
| **AI Integrations** | wp_search_stock_photos, wp_generate_image, wp_generate_alt_text, wp_text_to_speech | Paid |
| **Multilanguage** | wp_languages, wp_set_language, wp_get_translations, wp_create_translation | Pro |
| **Multisite** | wp_network_sites, wp_network_switch, wp_network_stats | Pro |

Tools are auto-detected based on installed plugins. If Elementor isn't active, Elementor tools won't appear. Same for WooCommerce, LearnPress, etc.

## Design Workflow Best Practices

These patterns come from real-world experience using AI assistants with MCPWP:

### Start Every Session with Onboarding

> "Run wp_onboard to learn about my site"

This returns site identity, content inventory, active integrations, and available tools — giving the AI full context before any changes.

### Use CSS for Quick Design Tweaks

> "Reduce the hero section padding and add a subtle background color to the pricing section"

AI agents strongly prefer **incremental, low-risk** operations. Custom CSS (`wp_get_custom_css` / `wp_set_custom_css`) is the safest way to make visual changes without touching Elementor data.

### Use wp_edit_section for Targeted Changes

> "Change the heading in section 3 from h2 to h1 and update the text"

For modifying specific sections, `wp_edit_section` is safer than replacing the entire page with `wp_set_elementor`. It edits a single section by index without touching the rest.

### Use wp_get_elementor_summary Before Full Edits

> "Show me the structure of page 42"

Returns a lightweight section/widget tree (types, IDs, key settings) without the full JSON — much easier for AI to reason about than raw Elementor data.

### Use wp_preview_elementor to Verify Changes

> "Preview page 42 as text"

After saving Elementor data, use the preview endpoint (supports `html`, `text`, and `summary` formats) to verify the result without opening a browser.

### Use Base64 for Large Payloads

> "Set this Elementor data using base64 encoding"

For pages with 10+ sections, use `elementor_data_base64` instead of `elementor_data` to avoid JSON quoting issues in MCP transport. The plugin decodes it server-side.

### Build Pages in Steps

1. `wp_build_page` — create the page with initial sections
2. `wp_edit_section` — refine individual sections
3. `wp_set_custom_css` — polish with CSS
4. `wp_preview_elementor` — verify the result

### Use Drafts First

> "Create a draft page with..."

Always create as `draft` first. Review via preview, then publish when satisfied.

## Troubleshooting

### No tools visible

1. Check config file path and JSON syntax
2. Restart your AI client
3. Verify the URL ends with `/wp-json/mcpwp/v1/mcp`
4. Test with curl (see Verify Connection above)

### "Unauthorized" or 401/403

1. Verify API key starts with `mcpwp_`
2. Copy exact key from WordPress admin
3. Check the `X-API-Key` header is set (not `Authorization`)

### Tools list seems small

1. Update plugin to v1.5.2+ (earlier versions had fewer tools)
2. Paid tools require a Freemius license or active trial
3. Integration tools (WooCommerce, LearnPress) only appear when those plugins are active

### Elementor data saves but editor shows empty

1. Update to v1.5.2+ (fixed in #174)
2. The plugin now sets `_elementor_version` meta automatically

### Large Elementor payloads lose sections

1. Update to v1.5.2+ (fixed in #175)
2. Check `sections_saved` vs `sections_submitted` in the response
3. Use `elementor_data_base64` for payloads over 20KB

### Rate limited

Go to WordPress Admin → MCPWP → Settings and increase rate limits.

## Security

1. **Keep API keys secret** — don't commit to git, use environment variables
2. **Use HTTPS** — always use secure connections
3. **Regenerate if exposed** — new key in WordPress admin
4. **Enable rate limiting** — prevent abuse
5. **Review before publishing** — always review AI-generated content

## Resources

- [MCP Protocol Reference](docs/MCP_NATIVE_ENDPOINT.md)
- [Elementor Widget Reference](docs/ELEMENTOR_WIDGET_REFERENCE.md)
- [REST API Reference](docs/API.md)
- GitHub: https://github.com/Digidinc/mcpwp

---

**Last Updated:** 2026-03-01
**Plugin Version:** 1.5.2+
**MCP Protocol:** 2024-11-05 (Streamable HTTP)
