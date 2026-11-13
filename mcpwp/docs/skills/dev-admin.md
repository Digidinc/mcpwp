---
name: dev-admin
description: Admin UI layer — page registration, settings, design system, partials. Load when working in mcpwp/includes/admin/ or mcpwp/admin/.
---

# MCPWP Dev — Admin UI

> Load when working in `mcpwp/includes/admin/` or `mcpwp/admin/`. Page slugs `mcpwp*`. Naming `Mcpwp_*` / `mcpwp_*`.

## What this area is

The admin layer wires WP Admin menu pages, settings, the design-system CSS, PHP→JS data bridging, AJAX handlers, and the HTML partials that power each page. It is ~5 500 lines of PHP across five classes plus one JS file and one CSS file. No front-end build step — plain jQuery + vanilla JS, loaded only on MCPWP pages.

## Admin pages

| Page slug | Title | Capability | Render class → method | Partial |
|---|---|---|---|---|
| `mcpwp` | Setup | `activate_plugins` | `Mcpwp_Admin::render_setup_page()` | `mcpwp-setup-display.php` |
| `mcpwp-control-room` | Control Room | `activate_plugins` | `Mcpwp_Admin::render_control_room_page()` | `mcpwp-control-room-display.php` |
| `mcpwp-chat` | Chat | `edit_posts` | `Mcpwp_Admin::render_chat_page()` | `mcpwp-chat-display.php` |
| `mcpwp-library` | Library | `activate_plugins` | `Mcpwp_Admin::render_library_page()` | `mcpwp-library-display.php` |
| `mcpwp-integrations` | Integrations | `activate_plugins` | `Mcpwp_Integrations_Admin::render()` | `mcpwp-integrations-display.php` |
| `mcpwp-tools` | Tools | `activate_plugins` | `Mcpwp_Tools_Admin::render()` | `mcpwp-tools-display.php` |
| `mcpwp-settings` | Settings | `activate_plugins` | `Mcpwp_Admin::render_settings_page()` | `mcpwp-settings-display.php` |
| `mcpwp-activity-log` | Activity Log | `activate_plugins` | `Mcpwp_Admin::render_activity_log_page()` (inline, no partial) | — |
| `mcpwp-network` | MCPWP — Network | `manage_network_plugins` | `Mcpwp_Admin::render_network_admin_page()` | — |

Note: `mcpwp` (Setup) is both the top-level menu and the first visible submenu — same slug, so clicking the top-level entry always lands on Setup.

Chat uses `edit_posts`, not `activate_plugins`, making it accessible to editors.

## Menu registration & routing

`Mcpwp_Admin::add_admin_menu()` is hooked to `admin_menu` by `Mcpwp_Loader`. The top-level entry is registered with `add_menu_page()` at position 80, then each tab is an `add_submenu_page()` call. Integrations and Tools delegate to their own class instances (`Mcpwp_Integrations_Admin`, `Mcpwp_Tools_Admin`) which hold their own `PAGE_SLUG` constants and `render()` methods.

Dispatch is direct: WordPress resolves `?page=<slug>` to the registered callback. There is no internal router — each `render_*` method calls `current_user_can()` and then `include`s the corresponding partial from `admin/partials/`.

Multisite gets an additional `add_menu_page()` call in `add_network_admin_menu()` (hooked to `network_admin_menu`) registering `mcpwp-network`.

## Settings

**Class:** `Mcpwp_Settings` — hooked at `admin_init` via `register_settings()`.

**Primary option key:** `mcpwp_settings` (group `mcpwp_settings_group`). Read via `Mcpwp_Settings::get_settings()` which merges stored values with `get_defaults()`.

**Key toggles in `mcpwp_settings`:**

| Key | Default | Purpose |
|---|---|---|
| `enable_logging` | `true` | Activity logging on/off |
| `log_retention_days` | `30` | Days to keep activity log rows |
| `log_store_response_data` | `true` | Store redacted response bodies |
| `log_redaction_keys` | array of sensitive key names | Keys whose values are scrubbed |
| `analytics_enabled` | `false` | PostHog server-side MCP analytics opt-in |
| `alerts_enabled` | `false` | Error-spike email alerts |
| `oauth_enabled` | on when never stored | OAuth sign-in for AI apps. On by default; saving the Setup -> Advanced switch stores an explicit value. See `docs/OAUTH-CONNECT.md` |
| `oauth_client_credentials_max_scope` | `'write'` | Ceiling for machine (client_credentials) tokens; a request for a higher scope is downgraded, never honoured. Allowed: `read`, `write`, `admin` |
| `allowed_origins` | `''` | CORS origin allowlist |

**Secondary option keys registered separately:**

| Key | Group | Purpose |
|---|---|---|
| `mcpwp_rate_limit_settings` | `mcpwp_rate_limit_group` | Rate limiting (enabled, rpm, rph, burst) |
| `mcpwp_chat_model` | `mcpwp_chat_group` | Chat model selector: `auto\|openai\|gemini\|workers` |
| `mcpwp_action_log_retention_days` | `mcpwp_settings_group` | Action/approval log retention |

Settings sections: `mcpwp_general_section` renders under the WP Settings API on `admin.php?page=mcpwp-settings`.

## Design system (CSS)

**File:** `mcpwp/admin/css/mcpwp-admin.css` — enqueued at `admin_enqueue_scripts` only on MCPWP pages via `is_mcpwp_admin_page()`, handle `mcpwp-admin`, versioned with `MCPWP_VERSION`.

**CSS custom properties (`:root`):**

| Token | Value | Use |
|---|---|---|
| `--mcpwp-ink` | `#0b1220` | Dark background, header bg base |
| `--mcpwp-ink-soft` | `#1d355f` | Secondary dark surface |
| `--mcpwp-blue` | `#2f7cff` | Primary action / accent |
| `--mcpwp-blue-dark` | `#1559d6` | Button hover |
| `--mcpwp-green` | `#20b86f` | Success / connected |
| `--mcpwp-amber` | `#f59e0b` | Warning |
| `--mcpwp-red` | `#d63638` | Error / critical |
| `--mcpwp-slate-50/100/200/500` | `#f8fafc … #64748b` | Background tiers, muted text |
| `--mcpwp-surface` | `#f6f7f7` | Page surface |
| `--mcpwp-border` | `#dcdcde` | Dividers |
| `--mcpwp-text-muted` | `#50575e` | Secondary text |
| `--mcpwp-status-good/warning/critical` | — | Status badge bg + text pairs |
| `--mcpwp-radius-sm/md/lg/pill` | `4px / 6px / 14px / 999px` | Consistent corner radii |
| `--mcpwp-radius` | `14px` | Legacy alias for `--mcpwp-radius-lg` |
| `--mcpwp-shadow` | `0 18px 44px …` | Card shadow |
| `--mcpwp-shadow-soft` | `0 8px 24px …` | Softer shadow |

**Dark-header pattern:** `.mcpwp-header` uses a `radial-gradient` + `linear-gradient` from `--mcpwp-ink` to `#10264f` with a subtle grid `::after` pseudo-element. Any new admin page should open with `<div class="mcpwp-header">` to match the visual identity.

**JS assets:** a single enqueued script `admin/js/mcpwp-admin.js` (handle `mcpwp-admin`, dep `jquery`, footer). All PHP→JS data bridged via `wp_localize_script('mcpwp-admin', 'spaiAdmin', [...])` — no inline `<script>` blocks anywhere. This was an explicit rule added after a chat-history XSS vector where JSON was previously interpolated into an inline script tag.

## Partials

All templates live in `mcpwp/admin/partials/` and are included via `include MCPWP_PLUGIN_DIR . 'admin/partials/<file>'` from the render method. They have access to local PHP variables scoped by the calling method.

| File | Renders |
|---|---|
| `mcpwp-setup-display.php` | MCP endpoint URL, API key generator, role-scoped key creation, connection test, update check |
| `mcpwp-control-room-display.php` | Approval inbox, SEO issues, event log, rollback controls |
| `mcpwp-chat-display.php` | AI chat UI with streaming, tool-call execution, chat history |
| `mcpwp-library-display.php` | Design references browser, archetypes, reusable parts |
| `mcpwp-settings-display.php` | General settings form (WP Settings API) + rate limit form |
| `mcpwp-integrations-display.php` | Provider key cards (OpenAI, Gemini, PostHog, Figma, etc.) with test/save/remove |
| `mcpwp-tools-display.php` | Tool category toggles, enforcement state, capability discovery |

## Conventions

- **Capability:** most pages check `activate_plugins`. Chat alone uses `edit_posts`. AJAX handlers re-check capability inside the handler — do not rely solely on the menu-registration capability.
- **Nonces:** form submissions use `check_admin_referer()` with named actions (`mcpwp_regenerate_key`, `mcpwp_control_room_actions`, etc.). AJAX calls use `check_ajax_referer('mcpwp_admin_nonce', 'nonce')`. The nonce is passed to JS in `spaiAdmin.nonce`.
- **Escaping:** always `esc_html()` for text nodes, `esc_attr()` for attributes, `esc_url()` for URLs, `wp_json_encode()` for JSON inside JS (never bare `json_encode`). `wp_die(esc_html__(...))` for hard permission failures.
- **Enqueue discipline:** styles and scripts only load when `is_mcpwp_admin_page()` returns true. Extra page-specific assets (integrations, tools) are enqueued by their own class `enqueue_assets()` method also gated on the correct page hook.
- **No inline JS:** all PHP data goes through `wp_localize_script`. Adding data to a new page = add a key to the `spaiAdmin` object in `enqueue_scripts()`.
- **Text domain:** `mcpwp` — use in all `__()` / `esc_html__()` calls.

## Gotchas

- **Page slug after rebrand:** all slugs are `mcpwp*`, not `site-pilot-ai*`. The Freemius build injects its own menu item under the `mcpwp` parent — the first sub-page is always `admin.php?page=mcpwp` (Setup) so users land there when clicking the top-level entry.
- **`spaiAdmin` JS object name:** despite the rebrand the localized JS object is still named `spaiAdmin` (not `mcpwpAdmin`). Do not rename without updating every reference in `mcpwp-admin.js`.
- **Chat history XSS fix:** chat history JSON was previously written via an inline `<script>` tag. It was moved to AJAX-only access (`ajax_chat_save_history` / `ajax_chat_clear_history`) to avoid injecting unescaped content into the DOM. Never re-introduce inline JSON interpolation in partials.
- **PostHog single-init:** `mcpwp-admin.js` guards `posthog.init` with `e.__SV` to prevent double-initialisation if the snippet runs more than once on the same page. Do not add a second `posthog.init` call in a partial.
- **`analytics_enabled` default is `false`:** the server-side analytics is opt-in on free tier. The admin Settings page renders `render_analytics_enabled_field()` which shows a paid-tier badge when Freemius says the user is free.
- **Activity Log has no partial:** `render_activity_log_page()` and `Mcpwp_Activity_Log_Page::render()` write HTML directly via `echo` — unlike every other page. Adding a partial here would be the correct refactor.
- **`mcpwp_settings` vs option name:** `register_setting` uses option name `mcpwp_settings` but the page slug passed to `add_settings_section` is also the string `'mcpwp_settings'` (not the page slug `mcpwp-settings`). Keep them distinct mentally.

## How to add an admin page

1. Add a `const MY_PAGE_SLUG = 'mcpwp-mypage';` constant to `Mcpwp_Admin` (or a new dedicated class).
2. Add an `add_submenu_page()` call inside `add_admin_menu()` referencing the new slug and a `render_mypage_page()` method.
3. Create `mcpwp/admin/partials/mcpwp-mypage-display.php` with the page HTML.
4. In `render_mypage_page()`: check `current_user_can('activate_plugins')`, prepare any PHP variables, then `include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-mypage-display.php';`.
5. If the page needs extra JS data, add keys to the `spaiAdmin` array in `enqueue_scripts()` — do not add an inline `<script>` block in the partial.
6. If it has its own AJAX actions, register them in `Mcpwp_Loader` as `wp_ajax_mcpwp_<action>` and verify the nonce + capability inside the handler.

## Testing

- **Syntax:** `php -l mcpwp/includes/admin/class-mcpwp-admin.php` (and the other admin classes) before committing.
- **Smoke test:** load each admin page in local WP at `http://localhost:8080/wp-admin/admin.php?page=<slug>` and confirm it renders without a PHP fatal or WP notice.
- **AJAX actions:** test via the browser dev-tools Network tab or with `curl` passing the nonce + `X-WP-Nonce` header against the WP admin-ajax endpoint.
- **Settings save:** submit the Settings form and verify the option value with `wp option get mcpwp_settings --format=json` via WP-CLI inside the Docker container.

## Related skills
- [[dev-core]] — services that admin pages read and write (analytics, integrations, rate limiter, event store)
- [[dev-pro]] — Freemius menu integration and paid-tier capability checks
- [[dev-architecture]] — top-level map of all plugin areas
