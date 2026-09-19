---
name: dev-pro
description: Reference for pro feature modules, Freemius licensing, and the is_pro gating chain. Load when working in includes/pro/, freemius-init.php, class-mcpwp-license.php, or any code that branches on paid vs. free.
---

# MCPWP Dev — Pro Features & Licensing

> Load when working in `mcpwp/includes/pro/`, `freemius-init.php`, or licensing. All names follow `Mcpwp_*` / `mcpwp_*` / `MCPWP_*`. Helper: `mcpwp_fs()`. Never write `spai_`, `Spai_`, `spa_fs`, `MUMCP`, `site-pilot-ai`, or `mumcp`.

## What this area is

Pro modules extend the free plugin with additional REST endpoints and feature handlers. They are gated by license status and only loaded in non-WP.org builds when an active entitlement is confirmed. Freemius handles SDK-level license verification; `Mcpwp_License` wraps Freemius + a Lemon Squeezy fallback + a 14-day trial path into a single `is_pro()` call.

## File map

| File | Class | Responsibility | ~Lines |
|------|-------|---------------|--------|
| `includes/pro/class-mcpwp-pro-bootstrap.php` | `Mcpwp_Pro_Bootstrap` | Requires all pro core+API files on `mcpwp_register_rest_routes`; adds `pro_active`/`plan` to capabilities filter | 117 |
| `includes/pro/core/class-mcpwp-page-builder.php` | `Mcpwp_Page_Builder` | Generates valid Elementor JSON from 24 named section blueprints (hero, features, cta, pricing…) | 2610 |
| `includes/pro/core/class-mcpwp-woocommerce.php` | `Mcpwp_WooCommerce` | Products, orders, coupons, analytics via WooCommerce API | 1382 |
| `includes/pro/core/class-mcpwp-learnpress.php` | `Mcpwp_LearnPress` | Courses, lessons, quizzes, enrollments via LearnPress CPTs | 1215 |
| `includes/pro/core/class-mcpwp-elementor-pro.php` | `Mcpwp_Elementor_Pro` | Templates, landing pages, reusable parts (`_mcpwp_is_part`), Custom Code (CPT `elementor_snippet`) | 1300 |
| `includes/pro/core/class-mcpwp-seo.php` | `Mcpwp_SEO` | Advanced SEO operations beyond the free audit store | 844 |
| `includes/pro/core/class-mcpwp-multilang.php` | `Mcpwp_Multilang` | WPML/Polylang translation helpers | 775 |
| `includes/pro/core/class-mcpwp-themes.php` | `Mcpwp_Themes` | Theme switching, child-theme creation | 757 |
| `includes/pro/core/class-mcpwp-theme-builder.php` | `Mcpwp_Theme_Builder` | Elementor Pro Theme Builder templates | 649 |
| `includes/pro/core/class-mcpwp-forms.php` | `Mcpwp_Forms` | CF7/Gravity Forms/WPForms read/write | 635 |
| `includes/pro/core/class-mcpwp-site-manager.php` | `Mcpwp_Site_Manager` | Multisite sub-site management | 588 |
| `includes/pro/core/class-mcpwp-users.php` | `Mcpwp_Users` | User CRUD + role management | 384 |
| `includes/pro/core/class-mcpwp-widgets.php` | `Mcpwp_Widgets` | Sidebar widget manipulation | 434 |
| `includes/pro/core/class-mcpwp-google-indexing.php` | `Mcpwp_Google_Indexing` | Submit URLs to Google via Indexing API | 419 |
| `includes/pro/core/class-mcpwp-events.php` | `Mcpwp_Events` | `tp_event` CPT (The Events Calendar compat) | 258 |
| `includes/pro/api/class-mcpwp-rest-*.php` | `Mcpwp_REST_*` | One controller per feature above; extends `Mcpwp_REST_API` | 124–1906 |
| `includes/freemius-init.php` | — | `mcpwp_fs()` singleton; SDK bootstrap; Freemius filters/hooks; uninstall cleanup | 246 |
| `includes/class-mcpwp-license.php` | `Mcpwp_License` | `is_pro()` decision tree; Lemon Squeezy activation; trial management | 514 |

## Free vs Pro gating — the is_pro chain

`Mcpwp_License::is_pro()` (`includes/class-mcpwp-license.php:82`) runs these checks in order. **First truthy wins; first hard-false exits immediately.**

```
1. defined('MCPWP_WPORG_BUILD')            → false (hard stop — always free on WP.org)
2. agency_license_active() (includes/agency-license.php marker file, written only
   by scripts/build-agency.sh — NEVER a wp-config.php constant, see #863) → true
2b. test_override_active() (MCPWP_TESTS true AND PHP_SAPI === 'cli', CI-only) → true
3. mcpwp_get_fs_instance()->can_use_premium_code() → true  (Freemius SDK: paid + premium ZIP)
4. mcpwp_get_fs_instance()->is_paying()    → true  (Freemius: active paid subscription)
5. mcpwp_get_fs_instance()->is_trial()     → true  (Freemius: in-trial)
6. get_option('mcpwp_pro_license')['valid'] && !is_expired() → true (Lemon Squeezy stored key)
7. is_trial_active()                       → true  (local 14-day trial from mcpwp_trial_started)
8. (none matched)                          → false
```

**At bootstrap** (`mcpwp.php:251–258`), the pro module is loaded only when:
```php
$pro_active = ! defined( 'MCPWP_WPORG_BUILD' )
    && class_exists( 'Mcpwp_License' )
    && Mcpwp_License::get_instance()->is_pro();
```
If false, `includes/pro/` is never `require_once`d — no routes registered.

**Inside MCP dispatch** (`class-mcpwp-rest-mcp.php:1403`):
```php
private function is_pro_active(): bool {
    if ( defined( 'MCPWP_WPORG_BUILD' ) ) return false;
    return class_exists( 'Mcpwp_License' ) && Mcpwp_License::get_instance()->is_pro();
}
```

## Freemius integration

File: `includes/freemius-init.php`. Loaded only when `defined('MCPWP_FREEMIUS_BUILD')` is true (injected at build time by `scripts/build-freemius.sh`).

| Config key | Value | Meaning |
|-----------|-------|---------|
| `id` | `23824` | Freemius product ID |
| `slug` | `MCPWP` | Must match the Freemius dashboard slug exactly |
| `public_key` | `pk_24f806380f2ccf8a5e3283dac895b` | SDK auth |
| `is_premium` | `true` | This ZIP is the premium source |
| `is_premium_only` | `false` | Free users can install too |
| `has_premium_version` | `true` | Tells Freemius there is a premium upgrade |
| `wp_org_gatekeeper` | `OA7#...` | Authorizes Freemius to auto-generate the free WP.org build |
| `trial.days` | `14` | Trial length (`is_require_payment: true`) |
| `menu.first-path` | `admin.php?page=mcpwp` | Freemius "Get Started" link target |
| `menu.account` / `pricing` | `true` | Renders Freemius account + pricing sub-pages |
| `menu.support` | `false` | Suppressed — link removed |
| `is_live` | `true` | Production mode |

**`mcpwp_fs()`** is a guarded singleton (`global $mcpwp_fs`). Safe to call anywhere post-`plugins_loaded`; returns `null`-safe via `mcpwp_get_fs_instance()`.

**Multisite**: `WP_FS__PRODUCT_23824_MULTISITE` defined to `true` before SDK init to enable network-level licensing.

**`mcpwp_fs_loaded` action** fires after SDK init — hook here for code that needs the SDK ready.

**Uninstall cleanup** (`mcpwp_fs_uninstall_cleanup`): iterates all multisite blogs; deletes all `mcpwp_*` options; drops `wp_mcpwp_activity_log`, `wp_mcpwp_webhooks`, `wp_mcpwp_webhook_logs`, `wp_mcpwp_feedback` tables.

## Build variants

| Variant | Script | `MCPWP_FREEMIUS_BUILD` | `MCPWP_WPORG_BUILD` | Freemius SDK | Pro modules | Self-updater |
|---------|--------|------------------------|---------------------|--------------|-------------|--------------|
| Freemius/Pro | `build-freemius.sh` | injected `true` | absent | included | included | excluded |
| WP.org free | `build-wporg.sh` | absent | injected `true` | stripped | stripped | stripped |
| Self-hosted | `build-selfhosted.sh` | absent | absent | stripped | included | included |

`.distignore` lists `/freemius` and `/includes/freemius-init.php`. `build-freemius.sh` explicitly **skips** those two patterns when applying `.distignore` so the SDK survives. The WP.org build does not skip them — SDK is deleted. Failure to skip the right lines = `fs_sdk_missing` fatal on activation.

Both build scripts run sanity checks (`unzip -Z1` manifest grep) and `exit 1` on violation.

## Pro feature classes

**`Mcpwp_WooCommerce`** — checks `class_exists('WooCommerce') && function_exists('WC')` via `is_active()`. Every method returns safe empty result when WooCommerce is absent — no fatal. Route still registered; handler returns `{'active':false}` on status endpoint.

**`Mcpwp_LearnPress`** — checks `class_exists('LearnPress') || post_type_exists('lp_course')`. Route registration in bootstrap is conditional: `if ( class_exists('LearnPress') || post_type_exists('lp_course') )` before calling `register_routes()`.

**`Mcpwp_Events`** (The Events Calendar `tp_event`) — route registration gated: `if ( post_type_exists('tp_event') )`.

**`Mcpwp_Elementor_Pro`** — no separate plugin check at route registration; Elementor Pro CPT `elementor_snippet` methods degrade silently if CPT absent.

**`Mcpwp_Page_Builder`** — uses `Mcpwp_Elementor_Basic` internally (`get_basic_handler()`). No external plugin required beyond Elementor itself.

**`Mcpwp_Multilang`** — supports WPML (`SitePress`) and Polylang (`pll_*` functions); guards each path with `class_exists`/`function_exists`.

## Conventions

- Every pro handler class exposes `is_active(): bool` (or equivalent guard) that is called at the top of each method body.
- REST controllers receive the handler via constructor injection (`__construct( Mcpwp_WooCommerce $handler )`). Never instantiate the handler inside a controller.
- All pro controllers extend `Mcpwp_REST_API` and use `$this->check_permission()` — same API-key auth as free endpoints.
- No fatals when a third-party plugin (WooCommerce, LearnPress, etc.) is absent. Methods return empty arrays or `{'active':false}` payloads.

## Gotchas

1. **`MCPWP_WPORG_BUILD` hard-false**: any `is_pro()` call while this constant is defined returns `false` immediately regardless of Freemius or stored license. Never define it outside a WP.org build.

2. **Freemius slug must be `MCPWP`** in the dashboard. The old slug was `site-pilot-ai` (SPAI era). If the dashboard slug drifts from `'MCPWP'` in `freemius-init.php`, Freemius will not recognize existing licenses from prior deploys.

3. **SDK stripped from WP.org + self-hosted builds**: `mcpwp_fs()` will not exist in those builds. Always gate Freemius calls with `function_exists('mcpwp_fs')` or use `mcpwp_get_fs_instance()` which returns `null` safely.

4. **Pro bootstrap `require_once` happens inside `mcpwp_register_rest_routes` hook**: pro classes are not available during `plugins_loaded` — only from `rest_api_init` onwards. Do not reference pro class names in early hooks.

5. **Freemius `free` plan slug causes contradiction (GH #319)**: `get_freemius_plan()` treats `'free'` as empty string and falls through to Lemon Squeezy stored license. Do not short-circuit this logic.

6. **`mcpwp_freemius_ensure_http_host()`**: must run before `fs_dynamic_init` to avoid noisy warnings in WP-CLI / cron contexts where `HTTP_HOST` is unset.

## How to add a pro feature

1. Create `includes/pro/core/class-mcpwp-{feature}.php` with class `Mcpwp_{Feature}`. Add an `is_active()` guard.
2. Create `includes/pro/api/class-mcpwp-rest-{feature}.php` extending `Mcpwp_REST_API`. Inject the core handler via constructor.
3. In `Mcpwp_Pro_Bootstrap::register_routes()` (`class-mcpwp-pro-bootstrap.php`): add `require_once` for both files, instantiate both classes, call `register_routes()`. If the feature requires a third-party plugin, wrap the `register_routes()` call in a `class_exists` / `post_type_exists` guard (see LearnPress/Events pattern).
4. If the feature needs a capability flag, add it in `Mcpwp_Pro_Bootstrap::add_pro_capabilities()`.
5. Run `php -l` on both new files. Add phpunit tests under `tests/pro/`.

## Testing

```bash
# Syntax check all pro files
find mcpwp/includes/pro -name '*.php' | xargs php -l

# Unit tests (if phpunit configured)
composer test -- --filter Pro

# Test gating — pro ON (CI/test-only override, issue #863):
# MCPWP_PRO in wp-config.php is NOT honoured by any build. Use this instead,
# and only from a CLI context (PHPUnit bootstrap, wp-cli) — it is a no-op
# under a web/MCP request:
define( 'MCPWP_TESTS', true );  // in phpunit bootstrap only; requires PHP_SAPI === 'cli'

# To simulate the agency build locally instead, drop a real marker file:
#   echo "<?php return 'MCPWP_AGENCY_LICENSE_V1';" > includes/agency-license.php
# (matches Mcpwp_License::AGENCY_MARKER_TOKEN — delete the file afterward)

# Test gating — WP.org build (hard false):
define( 'MCPWP_WPORG_BUILD', true );  // is_pro() returns false regardless

# Verify no pro routes registered on free build:
curl .../wp-json/site-pilot-ai/v1/elementor/templates  # expect 404
```

## Related skills

- [[dev-admin]] — Freemius menu rendering, account/pricing admin pages, opt-in screen
- [[dev-mcp-tools]] — pro-tier tool gating in `Mcpwp_MCP_Pro_Tools` and `is_pro_active()` in MCP dispatch
- [[dev-architecture]] — top-level file map and bootstrap order
