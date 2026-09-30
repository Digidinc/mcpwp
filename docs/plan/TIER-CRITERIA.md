# Free vs Pro: the criteria

**Status: PROPOSED 2026-09-30.** Owner approves the rules and the changes in the last section
before any tier moves. Nothing in this file changes behaviour by itself. Measured on the live
3.14.9 site: 311 tools, 184 Free, 127 Pro. Full per-tool map: [tier-map.csv](tier-map.csv)
(regenerate from source with the script noted there; counts in README and readme.txt must come
from that map, not be typed by hand).

## The one-sentence rule

**Free runs one ordinary site safely, end to end. Pro multiplies it: more sites, more money, more
plugins, more builders.**

Gate by **job**, never by tool count or by "we had to write a lot of code for it".

## The rules

1. **Never gate safety.** Approvals, rollback, scoped keys, the action log, webhooks, and every
   `*_status` / audit / read tool stay Free. Safety is why people trust an agent on their site.
2. **Never gate discovery or the first win.** Onboarding, introspection, `mcpwp_find_tools`,
   `mcpwp_call`, and everything a new single-site owner needs for a first real result (write and
   publish a page, add media, set SEO fields, edit a menu, build one Elementor page) is Free.
3. **Free can build one page; Pro can build a system.** Free: write content, edit Elementor data,
   build a page from blueprints. Pro: the reusable system around it (template, archetype and part
   libraries, Theme Builder with conditions, Custom Code, global design tokens, landing-page
   factory).
4. **Pro is leverage.** It covers anything that touches money (WooCommerce), domain plugins
   (LearnPress, forms, events, multilingual), moving work between environments (staging), other
   people's sites (multi-site, agency, white-label), and paid provider convenience (image, voice,
   Figma).
5. **Tier by category, not by tool.** One category is entirely Free or entirely Pro, so docs,
   job presets (#1048) and the pricing page can name it in one phrase. Exceptions need a line in
   this file saying why.
6. **Free never depends on Pro, and Pro never locks a Free read of the owner's own data.**
7. **One source of truth for numbers.** Counts on the README, readme.txt, mcpwp.net and the
   pricing page come from `tier-map.csv`. A CI check fails when they drift.

## Today, by category

| category | Free | Pro | verdict |
|---|---:|---:|---|
| site | 54 | 19 | menus (3), Figma (5), widgets and sidebars (10), cache (1) are Pro. Menus are core WordPress: fix |
| content | 32 | 4 | multilingual (4) Pro: correct (domain plugin) |
| seo | 17 | 13 | **basic SEO fields (`wp_get_seo`, `wp_set_seo`, `wp_set_noindex`) are Pro: fix.** Audit and report tools Pro: keep, they are the Yoast/RankMath bridge |
| admin | 27 | 0 | correct |
| elementor | 18 | 0 | correct (edit, patch, widgets, Kit CSS) |
| elementor-info | 5 | 0 | correct |
| elementor-build | 0 | 8 | **`wp_build_page` and the two blueprint readers must be Free: fix.** Landing-page factory, clone, section-to-template stay Pro |
| elementor-templates | 0 | 15 | correct (the library system) |
| elementor-theme | 0 | 13 | correct (Theme Builder, Custom Code, globals) |
| media | 8 | 0 | correct |
| taxonomy, gutenberg, webhooks | 19 | 0 | correct |
| ai | 0 | 8 | provider-backed. Stock photos (Pexels) and alt text are documented as Free in CLAUDE.md but shipped Pro: fix. Image generation, voice, excerpt, describe stay Pro |
| woocommerce | 0 | 21 | correct, the clearest paid value |
| learnpress | 0 | 18 | correct, niche |
| forms | 0 | 4 | correct |
| staging | 0 | 4 | correct |
| meta (`mcpwp_find_tools`, `mcpwp_call`), addon | 4 | 0 | correct |

## Proposed changes (12 tools move Pro → Free)

| tools | why |
|---|---|
| `wp_get_menu`, `wp_create_menu`, `wp_update_menu` | 10 sibling menu tools are already Free; a Free agent can add items to a menu but can't create one. Rule 5 |
| `wp_get_seo`, `wp_set_seo`, `wp_set_noindex` | Setting a title and description is the first SEO job of any site. Rule 2 |
| `wp_build_page`, `wp_list_blueprints`, `wp_get_blueprint` | The README's own demo is "Build a landing page with a hero…", a Pro tool. Rule 3 |
| `wp_search_stock_photos`, `wp_download_stock_photo`, `wp_generate_alt_text` | Pexels is documented Free; alt text is an accessibility basic |

Result: Free 196, Pro 115. The Free tool list grows by 12 (about 1,400 tokens in `full`), so the
Free `full` budget in `ToolListBudgetTest` moves with it; `core` is unaffected.

**What stays Pro, and why it is enough to pay for:** WooCommerce (21), Theme Builder and Custom
Code (13), template, archetype and part libraries (15), landing-page factory (5), LearnPress (18),
forms, multilingual, staging, widgets and sidebars, Figma, image generation and voice, plus
multi-site and agency features (#1062 hub when built), updates and support.

## Also to decide (not in the 12)

- **Pro trial:** 14 days, no card, through Freemius. Check whether it is enabled.
- **Widgets and sidebars (10 tools):** classic-widget editing is a legacy surface. Keep Pro, or
  drop from the catalog in the #1047 audit.
- **Pro message:** name Pro by job ("stores and agencies"), not by tool count.

## How a change ships

One PR per batch: tier edit in the registries, `tier-map.csv`, README / readme.txt / `FREE_PRO_SPLIT.md`
counts, the budget test, and a changelog line under "Changed for existing API keys" if a key's
visible list grows. Moving Pro → Free is never breaking for paying sites. Review: normal (no auth
change), but the Free build must be smoke-tested with the new tools on a fresh Free install.
