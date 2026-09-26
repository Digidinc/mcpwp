# Changelog

All notable changes to MCPWP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.13.1](https://github.com/Mumega-com/mcpwp/compare/v3.13.0...v3.13.1) (2026-09-26)


### Bug Fixes

* **admin:** clean first-run debug log, canonical update channel, Free plan label ([#888](https://github.com/Mumega-com/mcpwp/issues/888)) ([14aef59](https://github.com/Mumega-com/mcpwp/commit/14aef59f9f58a8dffa9b0a4c6e6253561145e0b1))
* **content:** strip script/style bodies with their tags and report removals ([#808](https://github.com/Mumega-com/mcpwp/issues/808)) ([#885](https://github.com/Mumega-com/mcpwp/issues/885)) ([22bccaf](https://github.com/Mumega-com/mcpwp/commit/22bccaf508b7d61789501f6e4a54795dac094824))
* **elementor:** detect default-on Container experiment and honour convert on set_elementor ([#887](https://github.com/Mumega-com/mcpwp/issues/887)) ([8f2eb1a](https://github.com/Mumega-com/mcpwp/commit/8f2eb1a1bceaa70d0174ab20a3e206a45a54bffe))
* **elementor:** invalidate Elementor caches after find/replace and price it by scope ([#891](https://github.com/Mumega-com/mcpwp/issues/891)) ([#892](https://github.com/Mumega-com/mcpwp/issues/892)) ([f847141](https://github.com/Mumega-com/mcpwp/commit/f8471412493e6582ffe989142c809e7393b0465e))
* **elementor:** recommend container widgets on atomic-mode sites until atomic writes render ([#889](https://github.com/Mumega-com/mcpwp/issues/889)) ([ac5548e](https://github.com/Mumega-com/mcpwp/commit/ac5548ec12eec4a2371bdbdae11767ee7d109ce2))
* **release:** reject mismatched package versions and runtime requirements ([#882](https://github.com/Mumega-com/mcpwp/issues/882)) ([cadc7d9](https://github.com/Mumega-com/mcpwp/commit/cadc7d9dc6ac8cbda2ccf41832211979fc8c93d6))
* **theme-builder:** publish templates only after their Elementor save succeeds ([#828](https://github.com/Mumega-com/mcpwp/issues/828)) ([#886](https://github.com/Mumega-com/mcpwp/issues/886)) ([e96264b](https://github.com/Mumega-com/mcpwp/commit/e96264bc3bac36e3c76d329f9e331a7f1fcc8f85))
* **woocommerce:** archetype PUT drops stored fields ([#879](https://github.com/Mumega-com/mcpwp/issues/879)), apply unpublishes live products ([#878](https://github.com/Mumega-com/mcpwp/issues/878)) ([bae4e71](https://github.com/Mumega-com/mcpwp/commit/bae4e71db5612bfdf1adbe98f8e34a88d03c691d))

## [3.13.0](https://github.com/Mumega-com/mcpwp/compare/v3.12.0...v3.13.0) (2026-09-19)


### ⚠️ Breaking: a write-scoped API key can no longer publish

Publishing now requires an **admin-scoped** key. An integration that sets
`status=publish` (or `private`, or `future`) with a write-scoped key will receive
`403 insufficient_scope` after this upgrade. **Mint an admin-scoped key for those
automations.**

This breaks working setups, deliberately. The previous behaviour was not a
considered grant: no publish capability check existed anywhere, and adding one
would have been meaningless, because every API key authenticates as the *same
shared* `mcpwp_api_agent` WordPress user, which holds `publish_posts` and
`publish_pages` unconditionally. The key's scope tier was the only real boundary,
and `write` cleared it.

Now admin-scoped on every surface that can publish: `/posts`, `/pages` and their
clone and translation paths; `/woocommerce/products` and
`/woocommerce/archetypes/{id}/apply`; all of `/learnpress` (courses, lessons,
quizzes); `/events`; and the Elementor page-creation routes (`/elementor/page`,
`/elementor/build-page`, `/elementor/landing-page`, `/elementor/clone`).

Two of those published with **no caller input at all**: LearnPress
`create_lesson()` and `create_quiz()` defaulted `post_status` to `publish`, so a
zero-argument call from a write-scoped key produced a live, enrollable lesson. A
WooCommerce archetype can carry its own stored `publish` default, so applying one
with no status override put a live product on the storefront.

### Security

* **The agent role held `mcpwp_manage_settings`**
  ([#669](https://github.com/Mumega-com/mcpwp/issues/669)). Because every key
  authenticates as that one shared user, `current_user_can('mcpwp_manage_settings')`
  was trivially true for *every* authenticated key — collapsing the capability half
  of the gate on `/api-keys` (list, create, revoke), `/rate-limit` (settings and
  reset) and the plugin self-update trigger. Only the key's own scope stood in the
  way. The capability is now administrator-only, and the API-key routes check the
  matched key's own scope instead. A one-time migration strips it from existing
  installs.
* **`/openapi.json` disclosed the exact plugin version to anonymous callers**
  ([#607](https://github.com/Mumega-com/mcpwp/issues/607)) — pre-auth
  fingerprinting for CVE targeting. It no longer does.
* **The admin Chat tool-execution bridge was failing 100% of the time** (#607) —
  its action allowlist and nonce name matched nothing actually registered. Fixed,
  and a vestigial `mcpwp_bypass_api_key_check` filter removed. That filter has no
  consumer anywhere, but code that reads as though a global authorization bypass
  is still wired is an invitation to re-wire one.
* All ten routes using `permission_callback => '__return_true'` were audited
  individually rather than accepted as "deliberately public". All ten are sound.

### Verified — no code change needed

* **[#670](https://github.com/Mumega-com/mcpwp/issues/670)** (pre-auth enumeration
  oracle) is present on neither `main` nor the wp.org branch, contrary to the
  issue's own text. All 228 `permission_callback` registrations across 34
  controllers were audited. The standing guard was hardened instead: it covered 2
  controllers — a list predating most of the API — and now covers 20 controllers
  and 92 (route, method) pairs, mutation-verified.
* **[#547](https://github.com/Mumega-com/mcpwp/issues/547)** (dead white-label
  hostname routing) was already removed in `b7148ee`. Verified by exhaustive
  search: zero matches for any of the shapes it describes.

### Upgrade notes

* Existing WooCommerce archetypes that stored a `future` status are now refused
  for write-scoped keys and resolve to `draft` even for an admin key.
* No other behaviour changes. Draft creation, content editing, media, SEO and
  read operations are unaffected at write scope.

## [3.12.0](https://github.com/Mumega-com/mcpwp/compare/v3.11.1...v3.12.0) (2026-09-19)

### Security

**Upgrade promptly if you issue role-scoped API keys.** Both issues below are
privilege escalation *within* an already-issued credential — neither is remotely
exploitable without a valid API key.

* **Role-scoped API keys were not enforced outside `/mcp`** ([#875](https://github.com/Mumega-com/mcpwp/issues/875)).
  A key's role — admin, designer, author, editor — was checked only inside MCP
  tool dispatch. Direct REST calls and `/batch` operations skipped the check
  entirely, so a key deliberately narrowed to one area could reach any tool
  category by calling the route directly. The restriction is now enforced on all
  four authentication paths (API key, batch sub-request, OAuth, broker bearer).
  If you have issued narrow keys to contractors, agencies, or integrations,
  assume those keys had full reach before this release.
* **Free and Freemius builds granted Pro** when `wp-config.php` defined
  `MCPWP_PRO` ([#863](https://github.com/Mumega-com/mcpwp/issues/863)). One line
  in a config file unlocked every Pro surface. The constant no longer grants
  anything; legitimate agency builds carry a marker file instead, and all four
  build scripts now fail if the old constant survives into a shipped artifact.
* **59 tools were classified below the scope their own route required**
  ([#874](https://github.com/Mumega-com/mcpwp/issues/874)). Not exploitable on
  its own — the route gate still held — but the two tables are now asserted
  equal across all 295 route-mapped tools so they cannot drift apart again.

### Upgrade notes

* The `mcpwp_api_agent` role gains `edit_published_posts` and
  `edit_published_pages` on upgrade ([#861](https://github.com/Mumega-com/mcpwp/issues/861)).
  The plugin already wrote to published pages by bypassing WordPress's denial
  with a raw metadata write; the role now says so openly, so those writes run
  through Elementor's real save pipeline and create revisions. If you
  deliberately removed either capability, your choice is preserved — the
  migration runs once and never re-grants.
* Existing Custom Code snippets are repaired automatically on the next page
  load, in batches with a durable cursor.

### Bug Fixes

* **Elementor Custom Code was completely non-functional.** Snippets were stored
  with a location value Elementor's own hooks never query for, and the metadata
  Elementor actually renders from was never written at all
  ([#871](https://github.com/Mumega-com/mcpwp/issues/871)). Separately,
  `<script>` was stripped from every snippet, making GA4/GTM tags — the feature's
  main use — silently inert ([#873](https://github.com/Mumega-com/mcpwp/issues/873)).
* **Elementor writes on published pages reported success when the save had
  failed** ([#861](https://github.com/Mumega-com/mcpwp/issues/861)). The receipt
  now reflects what Elementor actually returned.
* **Unknown Elementor template types were silently rewritten to `page`**
  ([#827](https://github.com/Mumega-com/mcpwp/issues/827)). Unrecognised types are
  now refused, listing the types this site actually has.
* **Large read responses can now be narrowed** ([#830](https://github.com/Mumega-com/mcpwp/issues/830)).
  Opt-in projection parameters take a widget schema from ~120KB to ~3KB and
  `wp_onboard` from ~71KB to ~1KB. Responses are unchanged unless you ask.
* **Parameter names made consistent across sibling tools**
  ([#831](https://github.com/Mumega-com/mcpwp/issues/831)). Old names keep
  working as deprecated aliases. Also fixes `wp_get_elementor_widgets`, whose
  documented filter parameter was never read, so filtering returned everything.
* **The Custom Code repair migration could never finish on a large site.** It
  scanned every snippet on each request and only recorded completion at the very
  end, so a site big enough to hit `max_execution_time` restarted the scan
  forever. Now batched behind a lock with a checkpointed cursor.

Full detail for all of the above, including the verification runs, is in commit
[88cb2d4](https://github.com/Mumega-com/mcpwp/commit/88cb2d4303763788801e605e751efe3dc6fbd1db).
* array-stored Elementor data through one reader, provider saves by compare-and-swap, Figma onboarding on Pro only ([#845](https://github.com/Mumega-com/mcpwp/issues/845)) ([#857](https://github.com/Mumega-com/mcpwp/issues/857)) ([c6cd59f](https://github.com/Mumega-com/mcpwp/commit/c6cd59f8d651f5225df386bfa3770205c18d0f5b))
* **release:** publish agency builds under the token-gated agency/ prefix ([#852](https://github.com/Mumega-com/mcpwp/issues/852)) ([#855](https://github.com/Mumega-com/mcpwp/issues/855)) ([6c935d5](https://github.com/Mumega-com/mcpwp/commit/6c935d56a1cf33cace33b4c7028e4ba3b209bc6a))

## [3.11.1](https://github.com/Mumega-com/mcpwp/compare/v3.11.0...v3.11.1) (2026-09-10)


### Features

* **mcp:** wp_batch, per-request spend budget, fixed bulk weights, plugin_version in _meta ([#802](https://github.com/Mumega-com/mcpwp/issues/802), [#752](https://github.com/Mumega-com/mcpwp/issues/752) F9) ([#848](https://github.com/Mumega-com/mcpwp/issues/848)) ([bcc16a5](https://github.com/Mumega-com/mcpwp/commit/bcc16a53479d9a1dd36615a7b3df049f9ad59f41))


### Bug Fixes

* 3.11.1 follow-ups — WooCommerce product facts, webhook log redaction, settings checkboxes, Freemius ZIP verify ([#846](https://github.com/Mumega-com/mcpwp/issues/846)) ([9aa6465](https://github.com/Mumega-com/mcpwp/commit/9aa64658d23f65cd911e5014ae481c1f3b19ba98))
* **elementor:** 7-character element ids, warn on the rest, opt-in repair ([#842](https://github.com/Mumega-com/mcpwp/issues/842)) ([#847](https://github.com/Mumega-com/mcpwp/issues/847)) ([29bc110](https://github.com/Mumega-com/mcpwp/commit/29bc110db172d6c8941ee672c955b926b214d935))
* **feedback:** atomic relay admission on a persistent object cache, mode reported ([#752](https://github.com/Mumega-com/mcpwp/issues/752) F6) ([#844](https://github.com/Mumega-com/mcpwp/issues/844)) ([2e7ba33](https://github.com/Mumega-com/mcpwp/commit/2e7ba33ee205f3b0e73ad17b4915662e8c5c6518))
* **security:** encryption key never regenerated, versioned blobs, migration lock; Pro gate on Figma and SEO market data ([#564](https://github.com/Mumega-com/mcpwp/issues/564)) ([#849](https://github.com/Mumega-com/mcpwp/issues/849)) ([7fcf9d3](https://github.com/Mumega-com/mcpwp/commit/7fcf9d35598a0f5f2128f700f4af6a977b361a35))


### Documentation

* how to pin a release version, and the squash-body trap ([#854](https://github.com/Mumega-com/mcpwp/issues/854)) ([42e2a26](https://github.com/Mumega-com/mcpwp/commit/42e2a2609e72c4b9aec32776a5a94199c201da65))


### Upgrade Notes

* **Stored provider keys use a new format.** Keys saved or re-saved by 3.11.1 cannot be read by 3.11.0 or earlier. If you downgrade, re-enter your provider keys in MCPWP Integrations.
* **A weak `MCPWP_ENCRYPTION_KEY` is now refused.** If wp-config.php defines it as empty, very short or trivially repetitive, encryption stops and an admin notice explains why. Set a strong value (32 random bytes or a long passphrase) and re-enter the affected keys.
* **Figma and SEO market data are Pro everywhere.** The Figma design-token sync no longer runs on Free sites, including self-hosted installs; a companion theme keeps the last tokens it received. The DataForSEO visibility and opportunities routes now require Pro over REST as well as MCP.
* **Element ids are 7 characters.** Elementor Pro's live search, taxonomy filter and loop widgets only accept 7-character ids. MCPWP now generates 7-character ids and warns about other lengths on save; pass `repair_ids: true` to `wp_patch_elementor` or `wp_set_elementor` to rewrite them. Update any custom CSS that names an old id.
* **Batches have a spend budget.** One request, including everything it fans out to, may spend 1000 units: an ordinary operation costs 1, a route that spends a site credential or reaches a caller-chosen URL costs 25 per item. A batch priced over the budget is refused whole with `spend_budget_exceeded` before anything runs; split it. `wp_bulk_find_replace` and `wp_delete_all_drafts` now count as 10 operations against the rate limit.
* **Analytics on Free needs an explicit choice.** A Free site that opted in before 3.11.1 stops sending anonymous usage data until an administrator confirms the setting under MCPWP Settings; a one-time notice asks. Paid sites are unchanged.

## [3.11.0](https://github.com/Mumega-com/mcpwp/compare/v3.10.8...v3.11.0) (2026-09-10)


### Features

* **approvals:** destructive operations need approval by default ([#536](https://github.com/Mumega-com/mcpwp/issues/536)) ([#839](https://github.com/Mumega-com/mcpwp/issues/839)) ([a64d4fd](https://github.com/Mumega-com/mcpwp/commit/a64d4fd1d06115b17fb2341efdafa1f96faa4c49))
* carry Elementor page settings through templates and archetypes ([#638](https://github.com/Mumega-com/mcpwp/issues/638)) ([#794](https://github.com/Mumega-com/mcpwp/issues/794)) ([13b9606](https://github.com/Mumega-com/mcpwp/commit/13b9606af2c91d170853188738da59f808eeaec2))
* **mcp:** idempotent tool calls via _meta.request_id ([#802](https://github.com/Mumega-com/mcpwp/issues/802)) ([#835](https://github.com/Mumega-com/mcpwp/issues/835)) ([665a276](https://github.com/Mumega-com/mcpwp/commit/665a2763bc4dc7f143465171b6e15e37c821e1d5))
* paginate Elementor library lists and summarise items in place ([#635](https://github.com/Mumega-com/mcpwp/issues/635)) ([#795](https://github.com/Mumega-com/mcpwp/issues/795)) ([b0d327c](https://github.com/Mumega-com/mcpwp/commit/b0d327c939ff2c425ec67e9c498505321b84e929))
* **posts:** terms and meta on create/update, featured image for any thumbnail-capable type ([#797](https://github.com/Mumega-com/mcpwp/issues/797)) ([#837](https://github.com/Mumega-com/mcpwp/issues/837)) ([6c7f1b4](https://github.com/Mumega-com/mcpwp/commit/6c7f1b492bca24ce5a3124f59fbaae3339542ae1))
* tell the caller when a rendered page is not what a visitor sees ([#693](https://github.com/Mumega-com/mcpwp/issues/693)) ([#796](https://github.com/Mumega-com/mcpwp/issues/796)) ([b9cd06c](https://github.com/Mumega-com/mcpwp/commit/b9cd06c999f043a15b3059a50f2a21e9f7b3c0af))


### Bug Fixes

* **elementor:** bulk shapes — find/replace scopes, bulk updates alias, wide-run gate ([#803](https://github.com/Mumega-com/mcpwp/issues/803)) ([#836](https://github.com/Mumega-com/mcpwp/issues/836)) ([b1d98b8](https://github.com/Mumega-com/mcpwp/commit/b1d98b8b0587e872af6bb24ca542feca041d5ece))
* **mcp:** batch weight, diagnostic bucket, per-request verdict memo ([#802](https://github.com/Mumega-com/mcpwp/issues/802)) ([#833](https://github.com/Mumega-com/mcpwp/issues/833)) ([3b6eb1d](https://github.com/Mumega-com/mcpwp/commit/3b6eb1d6b9933fdb63b31c4549b3d60c2856e4bf))
* **options:** option access floor, secret-preserving writes, redacted logs ([#800](https://github.com/Mumega-com/mcpwp/issues/800)) ([#838](https://github.com/Mumega-com/mcpwp/issues/838)) ([76faea6](https://github.com/Mumega-com/mcpwp/commit/76faea66d844a3981760f5fcfddd9fcec4a475e3))
* **posts:** meta detector stands alone, meta reads hide bearers by name and shape ([#797](https://github.com/Mumega-com/mcpwp/issues/797) follow-up) ([#840](https://github.com/Mumega-com/mcpwp/issues/840)) ([a97e36e](https://github.com/Mumega-com/mcpwp/commit/a97e36ef02867142e3e69420d442e62c3ed398e2))
* refuse a second installed MCPWP copy, surface double load ([#802](https://github.com/Mumega-com/mcpwp/issues/802)) ([#834](https://github.com/Mumega-com/mcpwp/issues/834)) ([335e13a](https://github.com/Mumega-com/mcpwp/commit/335e13a192084c57e620fa89d09395893fc8626a))
* say what changed and why when Elementor alters a tree it rendered ([#612](https://github.com/Mumega-com/mcpwp/issues/612)) ([#793](https://github.com/Mumega-com/mcpwp/issues/793)) ([f97523d](https://github.com/Mumega-com/mcpwp/commit/f97523d7f7459efdd908eb89ebe88a0080e22b5e))
* stop two more silent Elementor failures ([#789](https://github.com/Mumega-com/mcpwp/issues/789), [#788](https://github.com/Mumega-com/mcpwp/issues/788)) ([#791](https://github.com/Mumega-com/mcpwp/issues/791)) ([7223768](https://github.com/Mumega-com/mcpwp/commit/72237688768bcb01bc7a515dcbff2e269abc03df))

### Upgrade Notes

* **Permanent deletes now wait for approval.** A delete with no undo returns `202` with `approval_required: true` and an `approval_id`, and nothing is removed until a different reviewer, or a WordPress administrator in the Control Room, approves and applies it. That covers `force=true`, deleting a post already in the trash, media, Elementor templates, widgets, users, course categories and custom post types, and purging all drafts with `force=true`. Moving a post, page, product or draft to the trash still runs immediately. An administrator can turn the default off in MCPWP Settings, or exempt a single key in wp-admin; neither can be done through the API.
* **The option API follows one policy.** Salts, user roles, active plugins, cron, transients and MCPWP's own state are never reachable. Secret-shaped values read back as `***`, and overwriting a stored secret needs `allow_secrets: true`. `widget_*`, `sidebars_*` and `auto_update_*` options can no longer be written through it. A refusal is `403 forbidden_option` and names the rule that refused it.
* **Post meta refuses secret-shaped keys on write and masks credentials on read**, by key name and by value shape. Keys with a leading underscore are written through `wp_set_post_meta` only.
* **Batch-shaped calls are charged one rate-limit slot per operation.** At the default burst of 30, that is the largest batch one call can carry; raise `burst_limit` for larger ones.

## [3.10.8](https://github.com/Mumega-com/mcpwp/compare/v3.10.7...v3.10.8) (2026-09-07)


### Bug Fixes

* close the silent-success class reported in [#785](https://github.com/Mumega-com/mcpwp/issues/785) and [#784](https://github.com/Mumega-com/mcpwp/issues/784) ([#787](https://github.com/Mumega-com/mcpwp/issues/787)) ([44d223e](https://github.com/Mumega-com/mcpwp/commit/44d223e09077801ee6dc186fd72ced2a0ee256a8))
* **elementor:** accept empty children on strict leaf widgets ([#777](https://github.com/Mumega-com/mcpwp/issues/777)) ([9a54c79](https://github.com/Mumega-com/mcpwp/commit/9a54c79db5b2a1a579e308a3cb36b33fc50970a7))
* stop the CSS loopbacks from hard-disabling TLS verification ([#786](https://github.com/Mumega-com/mcpwp/issues/786)) ([e18e3e1](https://github.com/Mumega-com/mcpwp/commit/e18e3e1104ef8eed27a7100a710ab39d04e6eae8))

## [3.10.7](https://github.com/Mumega-com/mcpwp/compare/v3.10.6...v3.10.7) (2026-09-06)


### Bug Fixes

* close the batch route-terminator gap and the scripts credential-commit path ([#778](https://github.com/Mumega-com/mcpwp/issues/778)) ([571a862](https://github.com/Mumega-com/mcpwp/commit/571a86257990e7327755b205e8423ffbf18cd807))
* make a second plugin copy a no-op instead of corrupting every REST response ([#782](https://github.com/Mumega-com/mcpwp/issues/782)) ([139ae42](https://github.com/Mumega-com/mcpwp/commit/139ae4299794a144cc515e37cd93c316202fb82c))

## [3.10.6](https://github.com/Mumega-com/mcpwp/compare/v3.10.5...v3.10.6) (2026-09-06)


### Bug Fixes

* **elementor:** close remaining render verdict gaps ([#768](https://github.com/Mumega-com/mcpwp/issues/768)) ([c946dd1](https://github.com/Mumega-com/mcpwp/commit/c946dd159f0767478cf03201ba886b948be724e9))
* **elementor:** normalise element-id keys at every _elementor_data write ([#774](https://github.com/Mumega-com/mcpwp/issues/774)) ([466e97b](https://github.com/Mumega-com/mcpwp/commit/466e97b1ed3cb9b60457b1a11269b9fc637d0bf8))
* **elementor:** preserve repeat-insert renders ([#770](https://github.com/Mumega-com/mcpwp/issues/770)) ([6d2ec13](https://github.com/Mumega-com/mcpwp/commit/6d2ec133966011b750c5fbf686372fb86f94da48))
* **elementor:** stop Elementor rewriting the tree it is asked to render ([#772](https://github.com/Mumega-com/mcpwp/issues/772)) ([16474e9](https://github.com/Mumega-com/mcpwp/commit/16474e9cb9d7a11271e4e10953e23b0f63bdfdc1))
* **elementor:** survive a `'tag' => true` KSES allow-list, and report a dead render ([#765](https://github.com/Mumega-com/mcpwp/issues/765)) ([#766](https://github.com/Mumega-com/mcpwp/issues/766)) ([85eb3c9](https://github.com/Mumega-com/mcpwp/commit/85eb3c9297e126db33ff770afc485b3554117355))
* **seo:** say which stored copy a stale internal link came from ([#775](https://github.com/Mumega-com/mcpwp/issues/775)) ([a891fd2](https://github.com/Mumega-com/mcpwp/commit/a891fd283f57c20cc0b5f12340f47cd4698694b5))

## [3.10.5](https://github.com/Mumega-com/mcpwp/compare/v3.10.4...v3.10.5) (2026-09-05)


### Bug Fixes

* backlog cleanup for 3.10.5 ([#762](https://github.com/Mumega-com/mcpwp/issues/762), [#755](https://github.com/Mumega-com/mcpwp/issues/755), [#756](https://github.com/Mumega-com/mcpwp/issues/756)) + near-term roadmap ([#763](https://github.com/Mumega-com/mcpwp/issues/763)) ([26e5cee](https://github.com/Mumega-com/mcpwp/commit/26e5cee1bc003c98a57ed1b66e9419cc044e995c))

## [3.10.4](https://github.com/Mumega-com/mcpwp/compare/v3.10.3...v3.10.4) (2026-09-05)


### Bug Fixes

* close the eight adversarial-review follow-ups from [#744](https://github.com/Mumega-com/mcpwp/issues/744) ([#746](https://github.com/Mumega-com/mcpwp/issues/746)-[#753](https://github.com/Mumega-com/mcpwp/issues/753)) ([#754](https://github.com/Mumega-com/mcpwp/issues/754)) ([b2b3c3f](https://github.com/Mumega-com/mcpwp/commit/b2b3c3f76b0fcf809c66c014ad31a8de9c134596))
* **elementor:** store Kit CSS byte-for-byte, repairing a host that escapes it ([#761](https://github.com/Mumega-com/mcpwp/issues/761)) ([73f8f12](https://github.com/Mumega-com/mcpwp/commit/73f8f12c319c95e67d0dab40a91e2b4eded9e3bc))
* **elementor:** survive a Throwable from Elementor's own Document::save() ([#760](https://github.com/Mumega-com/mcpwp/issues/760)) ([e855060](https://github.com/Mumega-com/mcpwp/commit/e855060bfadbff9b68017dc618da1d01b246b882))

## [3.10.3](https://github.com/Mumega-com/mcpwp/compare/v3.10.2...v3.10.3) (2026-09-04)


### Bug Fixes

* **security:** bind relay throttle to a trusted client IP and block self-recursive batch operations ([#744](https://github.com/Mumega-com/mcpwp/issues/744)) ([1931f92](https://github.com/Mumega-com/mcpwp/commit/1931f92863f517bf9262e6ace620e40091bbba60))

## [3.10.2](https://github.com/Mumega-com/mcpwp/compare/v3.10.1...v3.10.2) (2026-07-27)


### Bug Fixes

* adversarial review findings — two defects already live in 3.10.1 ([#736](https://github.com/Mumega-com/mcpwp/issues/736)) ([c30edc1](https://github.com/Mumega-com/mcpwp/commit/c30edc1b2321e930f34dc6284b1acb5986509d2a))

## [3.10.1](https://github.com/Mumega-com/mcpwp/compare/v3.10.0...v3.10.1) (2026-07-27)


### Bug Fixes

* clear the open bug backlog — [#717](https://github.com/Mumega-com/mcpwp/issues/717), [#720](https://github.com/Mumega-com/mcpwp/issues/720), [#678](https://github.com/Mumega-com/mcpwp/issues/678), and the honesty half of [#729](https://github.com/Mumega-com/mcpwp/issues/729) ([#734](https://github.com/Mumega-com/mcpwp/issues/734)) ([b10b2f7](https://github.com/Mumega-com/mcpwp/commit/b10b2f7a1e740255562ec7a819ff324536b5a8b6))

## [3.10.0](https://github.com/Mumega-com/mcpwp/compare/v3.9.3...v3.10.0) (2026-07-27)


### Features

* **seo:** market data via DataForSEO — visibility, opportunities, and an agent loop ([#730](https://github.com/Mumega-com/mcpwp/issues/730)) ([b126bf7](https://github.com/Mumega-com/mcpwp/commit/b126bf7933165cb7075a76c81975f2afba000342))

## [3.9.3](https://github.com/Mumega-com/mcpwp/compare/v3.9.2...v3.9.3) (2026-07-27)


### Bug Fixes

* **security:** granular tool-category gate on all auth paths, fail-closed OAuth scopes ([#700](https://github.com/Mumega-com/mcpwp/issues/700)) ([8694169](https://github.com/Mumega-com/mcpwp/commit/86941696481ab28375992d6d04a38eaf4a06ac9e))


### Upgrade Notes

* AI image and alt-text generation is now governed by the **AI** tool toggle rather than **Admin**. If you disabled Admin specifically to block `/integrations/*` generation, it becomes reachable again on upgrade — AI is on by default. Disable the AI toggle to restore that block. This aligns the REST routes with the tool taxonomy the MCP layer has always used.
* Tool-category toggles now hold on the REST entry point as well as the MCP one. A toggle you previously set expecting MCP-only enforcement — WooCommerce, LearnPress, Multisite, Staging, Forms, Elementor Theme — will now also block the matching REST routes.

## [3.9.2](https://github.com/Mumega-com/mcpwp/compare/v3.9.1...v3.9.2) (2026-07-27)


### Bug Fixes

* **release:** publish and watch the Freemius channel like the self-hosted one ([#726](https://github.com/Mumega-com/mcpwp/issues/726)) ([6cb37d2](https://github.com/Mumega-com/mcpwp/commit/6cb37d2923300c1a9a9ac4739229f44465747842))

## [3.9.1](https://github.com/Mumega-com/mcpwp/compare/v3.9.0...v3.9.1) (2026-07-27)


### Bug Fixes

* **update:** guard Mcpwp_Updater use on builds that prune it ([#724](https://github.com/Mumega-com/mcpwp/issues/724)) ([564bc9a](https://github.com/Mumega-com/mcpwp/commit/564bc9a8d9858563099a0424a7168b14ef545c19))

## [3.9.0](https://github.com/Mumega-com/mcpwp/compare/v3.8.0...v3.9.0) (2026-07-27)


### Features

* **mupot:** read-first WordPress connector — status + task board in Control Room ([#715](https://github.com/Mumega-com/mcpwp/issues/715)) ([8a06c11](https://github.com/Mumega-com/mcpwp/commit/8a06c11593f62c7de1b873d4d79ed09f014ed71c)), closes [#689](https://github.com/Mumega-com/mcpwp/issues/689)

## [3.8.0](https://github.com/Mumega-com/mcpwp/compare/v3.7.2...v3.8.0) (2026-07-25)


### Features

* **updater:** make update-channel failures loud at every layer ([#719](https://github.com/Mumega-com/mcpwp/issues/719)) ([b961485](https://github.com/Mumega-com/mcpwp/commit/b961485c3c1e62679c0243ea703ecec53ebeeac6))


### Bug Fixes

* tool schema item types ([#707](https://github.com/Mumega-com/mcpwp/issues/707)) and staging environment detection ([#710](https://github.com/Mumega-com/mcpwp/issues/710)) ([#713](https://github.com/Mumega-com/mcpwp/issues/713)) ([483e3af](https://github.com/Mumega-com/mcpwp/commit/483e3aff567e381c4019056ce62085ce68a09ada))
* **updater:** resolve updates from the plugin manifest, not the WP.org transient ([#718](https://github.com/Mumega-com/mcpwp/issues/718)) ([dfe007f](https://github.com/Mumega-com/mcpwp/commit/dfe007f3aa130163887091cf4166989c7b58db03))

## [3.7.2](https://github.com/Mumega-com/mcpwp/compare/v3.7.1...v3.7.2) (2026-07-25)


### Bug Fixes

* **security:** fold route case before every authorization decision ([#711](https://github.com/Mumega-com/mcpwp/issues/711)) ([57482e5](https://github.com/Mumega-com/mcpwp/commit/57482e5e58106385658922d271ed9c478d6dfdf8))

## [3.7.1](https://github.com/Mumega-com/mcpwp/compare/v3.7.0...v3.7.1) (2026-07-22)


### Features

* **admin:** redesign admin UI to Claude Design Control Room mockup (3.6.5) ([0612fda](https://github.com/Mumega-com/mcpwp/commit/0612fdaa6fe8eda05c74e77cbabf95d1d8bcc018))
* allow extensions to raise custom MCP tool scope ([#654](https://github.com/Mumega-com/mcpwp/issues/654)) ([bbae9aa](https://github.com/Mumega-com/mcpwp/commit/bbae9aaba28220b002069584f7ca352bf6d2175e))
* **broker-plugin:** cached introspection validator (fail-closed, audience-checked, kill-switch) ([5b526a7](https://github.com/Mumega-com/mcpwp/commit/5b526a7793dd4a4b2ce57449b2282da1a1f1e46d))
* **broker-plugin:** claude.ai/ChatGPT OAuth sign-in via mumcp.com broker (v3.7.0) ([c523253](https://github.com/Mumega-com/mcpwp/commit/c523253063c378963591098f36e9458281b837a4))
* **broker-plugin:** enrollment callback + confirm endpoints (site-control proof) ([dc171b5](https://github.com/Mumega-com/mcpwp/commit/dc171b578384ccefa228a3daccdbdb45d6232cf8))
* **broker-plugin:** enrollment lifecycle — connect/state/confirm/store/disconnect + Connections UI ([fd13941](https://github.com/Mumega-com/mcpwp/commit/fd1394160812946a395b2a031dc4be91b451a42c))
* **broker-plugin:** point .well-known/oauth-protected-resource at mumcp.com when enrolled ([0147df0](https://github.com/Mumega-com/mcpwp/commit/0147df0e4ef7dfb5c5eaba0fdcd8c61aea11aeb6))
* **broker-plugin:** validate broker Bearer tokens in verify_api_key() (read|write, never admin) + 401 discovery header ([2355944](https://github.com/Mumega-com/mcpwp/commit/2355944a10a6c2c290212c2bcf848a29a39b215b))
* **cloudflare:** cloudflare integration — bot-fix, cache purge, speed/CWV signals ([#632](https://github.com/Mumega-com/mcpwp/issues/632)) ([f2d9f72](https://github.com/Mumega-com/mcpwp/commit/f2d9f7289ba2d590c9e02563f689f53dafbd0a45))
* **elementor:** idempotent, named Kit CSS blocks ([#645](https://github.com/Mumega-com/mcpwp/issues/645)) ([b3b5519](https://github.com/Mumega-com/mcpwp/commit/b3b551932cffce0fcf5d2b5eb630b0990df40c70))
* **elementor:** validate Atomic Elements props against real schema + document the feature ([1ffbff9](https://github.com/Mumega-com/mcpwp/commit/1ffbff916c50af63384371c0b7994cb12da311c7))
* **integrations:** self-service Cloudflare Bot Fight Mode fix in wp-admin ([ddb4e6b](https://github.com/Mumega-com/mcpwp/commit/ddb4e6b79ab700d6b17b40429d276f1813d44b5f))
* **seo:** classify deprecated schema.org rich-result types ([#615](https://github.com/Mumega-com/mcpwp/issues/615)) ([b16ccfb](https://github.com/Mumega-com/mcpwp/commit/b16ccfbcd4329cf589c2e90ead3f3903682d382c))
* **seo:** experimental opt-in GEO/AI-citability score ([#617](https://github.com/Mumega-com/mcpwp/issues/617)) ([21bc3ee](https://github.com/Mumega-com/mcpwp/commit/21bc3ee3e5d090eb5e50dd301f6912a12bf29c4d))
* **seo:** schema-placement check + explicit E-E-A-T rubric ([#616](https://github.com/Mumega-com/mcpwp/issues/616)) ([bd98a7d](https://github.com/Mumega-com/mcpwp/commit/bd98a7df3eae0d2ac2127da4d160ce85ed73bca8))
* support trusted custom tool callbacks ([#657](https://github.com/Mumega-com/mcpwp/issues/657)) ([9503424](https://github.com/Mumega-com/mcpwp/commit/95034246c02deff858761eaa08661be60e3f728b))


### Bug Fixes

* **broker-plugin:** host-lock enroll-store issuer/introspection_endpoint to mumcp.com (prevent credential-exfil poisoning) ([38d8f4c](https://github.com/Mumega-com/mcpwp/commit/38d8f4c0f90dd6a2e96715b369f0f71cbe290f67))
* **broker-plugin:** introspect only as fallback after local key + rate-limit anonymous bearer attempts (no key egress, no DoS) ([8396f62](https://github.com/Mumega-com/mcpwp/commit/8396f62a3304c759bb60c9285d9f9932ac84bfb2))
* **broker-plugin:** public state-gated enroll-callback + pin audience to home_url + pin introspection URL + no public log-amplification ([274d209](https://github.com/Mumega-com/mcpwp/commit/274d209afe4396d65a84ed737c22c0d84ef21106))
* **ci:** scope proxy-worker audit to runtime deps + bump hono ([#692](https://github.com/Mumega-com/mcpwp/issues/692)) ([9894fef](https://github.com/Mumega-com/mcpwp/commit/9894fef6ec775220705b3fe7013735713afd4b16))
* **elementor:** accept create-side param names on archetype/part list tools ([#640](https://github.com/Mumega-com/mcpwp/issues/640)) ([5de3176](https://github.com/Mumega-com/mcpwp/commit/5de3176583b459b4b03f8be5ea116a6f928caf2d))
* **elementor:** correct destructive annotations and elementor_data round-trip typing ([#642](https://github.com/Mumega-com/mcpwp/issues/642)) ([55252ec](https://github.com/Mumega-com/mcpwp/commit/55252ec251968065117f3e4f65747ff3c7233fab))
* **elementor:** stop silent data loss on section/container/atomic layout mismatches ([3356719](https://github.com/Mumega-com/mcpwp/commit/33567199ca9b4e92dffee6582ed1d08ecd430e39))
* **freemius:** soft-gate first activation instead of hard license wall ([#646](https://github.com/Mumega-com/mcpwp/issues/646)) ([3ad18bc](https://github.com/Mumega-com/mcpwp/commit/3ad18bc5ab8a7725da86a4a7e40f2b8da338d643))
* **license:** close reconnect-link render-time gap + notify on blocked updates ([589fb36](https://github.com/Mumega-com/mcpwp/commit/589fb361cb8bc3fea1aaf2df21c19caba3e964e7))
* **license:** detect wrong-package downgrade + warn loudly ([#656](https://github.com/Mumega-com/mcpwp/issues/656)) ([c2e9492](https://github.com/Mumega-com/mcpwp/commit/c2e9492849365239743fdc100db871de8db29068))
* **license:** reconnect flow no longer forces skip-mode (found the actual bug) ([00a5e8a](https://github.com/Mumega-com/mcpwp/commit/00a5e8aaf851949c3c0aa9f47adf404bfc076b0d))
* **license:** reconnect Freemius when a site is stuck anonymous/pending ([#633](https://github.com/Mumega-com/mcpwp/issues/633)) ([3fe334a](https://github.com/Mumega-com/mcpwp/commit/3fe334aaa7c12bd53406665d51372a50eb8afc9b))
* **license:** update vendored Freemius SDK 2.13.0 -&gt; 2.13.4 ([d3fb7cf](https://github.com/Mumega-com/mcpwp/commit/d3fb7cf2f6a8fb7d46579255ae8c77045ca9677f))
* **license:** use Freemius's install-linked checkout URL, not marketing site ([9e7b070](https://github.com/Mumega-com/mcpwp/commit/9e7b070de59920e2411186b4308b515c07038323))
* **mcp:** draft rendered-copy 404/502 + expose unlicensed-reason code ([4b7f8ef](https://github.com/Mumega-com/mcpwp/commit/4b7f8ef637f0d82f81926674402715430ae5ffd5))
* menu taxonomy contract, AIOSEO persistence, draft rendered-copy (3.6.2) ([#682](https://github.com/Mumega-com/mcpwp/issues/682)) ([528d096](https://github.com/Mumega-com/mcpwp/commit/528d096a824aa8d4c51bcbe78a546d716617d0c6))
* **oauth:** discovery docs broken on subdirectory installs + widen CF fix ([57bf027](https://github.com/Mumega-com/mcpwp/commit/57bf027c41970d0eafbbd88c9ef61cf9bfb4b9fa))
* **oauth:** serve consent screen off the REST API so browser sign-in works ([#695](https://github.com/Mumega-com/mcpwp/issues/695)) ([ca61c68](https://github.com/Mumega-com/mcpwp/commit/ca61c68b798e5ca982ec1bb03deeba2a0b832480))
* **pages:** support excerpt on wp_create_page/wp_update_page ([#697](https://github.com/Mumega-com/mcpwp/issues/697)) ([6a5efa5](https://github.com/Mumega-com/mcpwp/commit/6a5efa577e367a828727af37c33ba1accbf55f10))
* **release:** release_freemius.sh never defined MCPWP_FREEMIUS_BUILD ([673f943](https://github.com/Mumega-com/mcpwp/commit/673f94384723c07e38144e4660fd8657811341d8))
* **rendered-html:** render draft/private Elementor content in-process, not via loopback ([#644](https://github.com/Mumega-com/mcpwp/issues/644)) ([7b94091](https://github.com/Mumega-com/mcpwp/commit/7b940919e30561987c19aebadc7a05c1231509cf))
* **security:** block secret option stores from /option and admin-gate /plugin-settings ([094e472](https://github.com/Mumega-com/mcpwp/commit/094e472e3fe3f0bf7467869d4ad1e4b2d11d9178))
* **security:** block secret option stores from /option and admin-gate /plugin-settings ([3b30a37](https://github.com/Mumega-com/mcpwp/commit/3b30a37960769cb6c2ba31c94d215314758ea107))
* **security:** close draft/private content disclosure to read-scoped API keys ([#683](https://github.com/Mumega-com/mcpwp/issues/683)) ([e6051c9](https://github.com/Mumega-com/mcpwp/commit/e6051c90585d2aeae6ec4340c36c0c9b1f915805))
* **security:** harden feedback-relay rate limiting and Figma OAuth callback ([#649](https://github.com/Mumega-com/mcpwp/issues/649)) ([a1040c9](https://github.com/Mumega-com/mcpwp/commit/a1040c959d8a8a07352cd67034d4a56c176de55d))
* **security:** harden REST/MCP scope matrix and self-approval gate ([3959a13](https://github.com/Mumega-com/mcpwp/commit/3959a13ec7304d8cba0003e87627ba66279f1549))
* **security:** harden REST/MCP scope matrix and self-approval gate ([aadd238](https://github.com/Mumega-com/mcpwp/commit/aadd2388dfd1eeff0f806696205cc18b6f8e94e5))
* **security:** require admin scope to bypass content-approval gate ([172fd28](https://github.com/Mumega-com/mcpwp/commit/172fd2803cfa0fa48c7907e4b8c6e2c08cb01f4b))
* **security:** require admin scope to bypass content-approval gate ([b27591b](https://github.com/Mumega-com/mcpwp/commit/b27591bb83de625eea5f916b834140dc7a34eb81))
* **security:** restrict Elementor globals writes to an allowlist and admin-gate kit CSS ([#603](https://github.com/Mumega-com/mcpwp/issues/603)) ([01d79f6](https://github.com/Mumega-com/mcpwp/commit/01d79f6d3942d50addf9494038b2aaf99f148ed3))
* **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601)) ([ea27690](https://github.com/Mumega-com/mcpwp/commit/ea2769065bb8c1430ac9f78de1490087abb737bd))
* **seo:** 3.6.4 — readiness false-positives, screenshot save_to_media, setup-page connect clarity ([#685](https://github.com/Mumega-com/mcpwp/issues/685)) ([f975375](https://github.com/Mumega-com/mcpwp/commit/f975375b3b996d067528f5c7fb3a21579e53d1c5))
* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([9a60782](https://github.com/Mumega-com/mcpwp/commit/9a607829f60cfe0d73a30336b135754b4c0706be))
* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([c4c4ec5](https://github.com/Mumega-com/mcpwp/commit/c4c4ec55d94fa8fcda75289ec1923faf0f6bc3da))


### Miscellaneous Chores

* record 3.7.0 as released and cut the next release as 3.7.1 ([ccc86dd](https://github.com/Mumega-com/mcpwp/commit/ccc86dd60160b9b117c87006aa1678efedfe3d25))

## [3.6.0](https://github.com/Mumega-com/mcpwp/compare/v3.5.0...v3.6.0) (2026-07-17)


### Features

* allow extensions to raise custom MCP tool scope ([#654](https://github.com/Mumega-com/mcpwp/issues/654)) ([bbae9aa](https://github.com/Mumega-com/mcpwp/commit/bbae9aaba28220b002069584f7ca352bf6d2175e))
* **cloudflare:** cloudflare integration — bot-fix, cache purge, speed/CWV signals ([#632](https://github.com/Mumega-com/mcpwp/issues/632)) ([f2d9f72](https://github.com/Mumega-com/mcpwp/commit/f2d9f7289ba2d590c9e02563f689f53dafbd0a45))
* **integrations:** self-service Cloudflare Bot Fight Mode fix in wp-admin ([ddb4e6b](https://github.com/Mumega-com/mcpwp/commit/ddb4e6b79ab700d6b17b40429d276f1813d44b5f))
* support trusted custom tool callbacks ([#657](https://github.com/Mumega-com/mcpwp/issues/657)) ([9503424](https://github.com/Mumega-com/mcpwp/commit/95034246c02deff858761eaa08661be60e3f728b))


### Bug Fixes

* **freemius:** soft-gate first activation instead of hard license wall ([#646](https://github.com/Mumega-com/mcpwp/issues/646)) ([3ad18bc](https://github.com/Mumega-com/mcpwp/commit/3ad18bc5ab8a7725da86a4a7e40f2b8da338d643))
* **license:** close reconnect-link render-time gap + notify on blocked updates ([589fb36](https://github.com/Mumega-com/mcpwp/commit/589fb361cb8bc3fea1aaf2df21c19caba3e964e7))
* **license:** detect wrong-package downgrade + warn loudly ([#656](https://github.com/Mumega-com/mcpwp/issues/656)) ([c2e9492](https://github.com/Mumega-com/mcpwp/commit/c2e9492849365239743fdc100db871de8db29068))
* **license:** reconnect flow no longer forces skip-mode (found the actual bug) ([00a5e8a](https://github.com/Mumega-com/mcpwp/commit/00a5e8aaf851949c3c0aa9f47adf404bfc076b0d))
* **license:** update vendored Freemius SDK 2.13.0 -&gt; 2.13.4 ([d3fb7cf](https://github.com/Mumega-com/mcpwp/commit/d3fb7cf2f6a8fb7d46579255ae8c77045ca9677f))
* **license:** use Freemius's install-linked checkout URL, not marketing site ([9e7b070](https://github.com/Mumega-com/mcpwp/commit/9e7b070de59920e2411186b4308b515c07038323))
* **mcp:** draft rendered-copy 404/502 + expose unlicensed-reason code ([4b7f8ef](https://github.com/Mumega-com/mcpwp/commit/4b7f8ef637f0d82f81926674402715430ae5ffd5))
* **release:** release_freemius.sh never defined MCPWP_FREEMIUS_BUILD ([673f943](https://github.com/Mumega-com/mcpwp/commit/673f94384723c07e38144e4660fd8657811341d8))
* **security:** harden feedback-relay rate limiting and Figma OAuth callback ([#649](https://github.com/Mumega-com/mcpwp/issues/649)) ([a1040c9](https://github.com/Mumega-com/mcpwp/commit/a1040c959d8a8a07352cd67034d4a56c176de55d))

## [3.5.0](https://github.com/Mumega-com/mcpwp/compare/v3.4.17...v3.5.0) (2026-07-15)


### Features

* **elementor:** idempotent, named Kit CSS blocks ([#645](https://github.com/Mumega-com/mcpwp/issues/645)) ([b3b5519](https://github.com/Mumega-com/mcpwp/commit/b3b551932cffce0fcf5d2b5eb630b0990df40c70))
* **elementor:** validate Atomic Elements props against real schema + document the feature ([1ffbff9](https://github.com/Mumega-com/mcpwp/commit/1ffbff916c50af63384371c0b7994cb12da311c7))
* **seo:** classify deprecated schema.org rich-result types ([#615](https://github.com/Mumega-com/mcpwp/issues/615)) ([b16ccfb](https://github.com/Mumega-com/mcpwp/commit/b16ccfbcd4329cf589c2e90ead3f3903682d382c))
* **seo:** experimental opt-in GEO/AI-citability score ([#617](https://github.com/Mumega-com/mcpwp/issues/617)) ([21bc3ee](https://github.com/Mumega-com/mcpwp/commit/21bc3ee3e5d090eb5e50dd301f6912a12bf29c4d))
* **seo:** schema-placement check + explicit E-E-A-T rubric ([#616](https://github.com/Mumega-com/mcpwp/issues/616)) ([bd98a7d](https://github.com/Mumega-com/mcpwp/commit/bd98a7df3eae0d2ac2127da4d160ce85ed73bca8))


### Bug Fixes

* **elementor:** accept create-side param names on archetype/part list tools ([#640](https://github.com/Mumega-com/mcpwp/issues/640)) ([5de3176](https://github.com/Mumega-com/mcpwp/commit/5de3176583b459b4b03f8be5ea116a6f928caf2d))
* **elementor:** correct destructive annotations and elementor_data round-trip typing ([#642](https://github.com/Mumega-com/mcpwp/issues/642)) ([55252ec](https://github.com/Mumega-com/mcpwp/commit/55252ec251968065117f3e4f65747ff3c7233fab))
* **elementor:** stop silent data loss on section/container/atomic layout mismatches ([3356719](https://github.com/Mumega-com/mcpwp/commit/33567199ca9b4e92dffee6582ed1d08ecd430e39))
* **license:** reconnect Freemius when a site is stuck anonymous/pending ([#633](https://github.com/Mumega-com/mcpwp/issues/633)) ([3fe334a](https://github.com/Mumega-com/mcpwp/commit/3fe334aaa7c12bd53406665d51372a50eb8afc9b))
* **rendered-html:** render draft/private Elementor content in-process, not via loopback ([#644](https://github.com/Mumega-com/mcpwp/issues/644)) ([7b94091](https://github.com/Mumega-com/mcpwp/commit/7b940919e30561987c19aebadc7a05c1231509cf))
* **security:** block secret option stores from /option and admin-gate /plugin-settings ([094e472](https://github.com/Mumega-com/mcpwp/commit/094e472e3fe3f0bf7467869d4ad1e4b2d11d9178))
* **security:** block secret option stores from /option and admin-gate /plugin-settings ([3b30a37](https://github.com/Mumega-com/mcpwp/commit/3b30a37960769cb6c2ba31c94d215314758ea107))
* **security:** harden REST/MCP scope matrix and self-approval gate ([3959a13](https://github.com/Mumega-com/mcpwp/commit/3959a13ec7304d8cba0003e87627ba66279f1549))
* **security:** harden REST/MCP scope matrix and self-approval gate ([aadd238](https://github.com/Mumega-com/mcpwp/commit/aadd2388dfd1eeff0f806696205cc18b6f8e94e5))
* **security:** require admin scope to bypass content-approval gate ([172fd28](https://github.com/Mumega-com/mcpwp/commit/172fd2803cfa0fa48c7907e4b8c6e2c08cb01f4b))
* **security:** require admin scope to bypass content-approval gate ([b27591b](https://github.com/Mumega-com/mcpwp/commit/b27591bb83de625eea5f916b834140dc7a34eb81))
* **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601)) ([ea27690](https://github.com/Mumega-com/mcpwp/commit/ea2769065bb8c1430ac9f78de1490087abb737bd))
* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([9a60782](https://github.com/Mumega-com/mcpwp/commit/9a607829f60cfe0d73a30336b135754b4c0706be))
* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([c4c4ec5](https://github.com/Mumega-com/mcpwp/commit/c4c4ec55d94fa8fcda75289ec1923faf0f6bc3da))

## [3.4.6](https://github.com/Mumega-com/mcpwp/compare/v3.4.5...v3.4.6) (2026-07-09)


### Bug Fixes

* **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601)) ([ea27690](https://github.com/Mumega-com/mcpwp/commit/ea2769065bb8c1430ac9f78de1490087abb737bd))

## [3.4.5](https://github.com/Mumega-com/mcpwp/compare/v3.4.4...v3.4.5) (2026-07-09)


### Bug Fixes

* **security:** require admin scope to bypass content-approval gate ([172fd28](https://github.com/Mumega-com/mcpwp/commit/172fd2803cfa0fa48c7907e4b8c6e2c08cb01f4b))
* **security:** require admin scope to bypass content-approval gate ([b27591b](https://github.com/Mumega-com/mcpwp/commit/b27591bb83de625eea5f916b834140dc7a34eb81))

## [3.4.4](https://github.com/Mumega-com/mcpwp/compare/v3.4.3...v3.4.4) (2026-07-08)


### Bug Fixes

* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([9a60782](https://github.com/Mumega-com/mcpwp/commit/9a607829f60cfe0d73a30336b135754b4c0706be))
* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([c4c4ec5](https://github.com/Mumega-com/mcpwp/commit/c4c4ec55d94fa8fcda75289ec1923faf0f6bc3da))

## [3.4.3](https://github.com/Mumega-com/mcpwp/compare/v3.4.2...v3.4.3) (2026-07-07)


### Bug Fixes

* **security:** harden REST/MCP scope matrix and self-approval gate ([3959a13](https://github.com/Mumega-com/mcpwp/commit/3959a13ec7304d8cba0003e87627ba66279f1549))

## [3.4.2](https://github.com/Mumega-com/mcpwp/compare/v3.4.1...v3.4.2) (2026-07-05)


### Bug Fixes

* **security:** block secret option stores from /option and admin-gate /plugin-settings ([094e472](https://github.com/Mumega-com/mcpwp/commit/094e472e3fe3f0bf7467869d4ad1e4b2d11d9178))
* **security:** block secret option stores from /option and admin-gate /plugin-settings ([3b30a37](https://github.com/Mumega-com/mcpwp/commit/3b30a37960769cb6c2ba31c94d215314758ea107))

## [Unreleased]

## [3.4.1]

### Security
- require admin scope for plugin self-update and update-control options; restrict update package URLs to HTTPS + trusted hosts (fixes write-scope→RCE chain)

## [3.4.0]

### Added
- GET /openapi.json — generates OpenAPI 3.0 schema from free-tools registry; enables ChatGPT GPT Actions + any OpenAPI consumer

## [3.3.9]

### Fixed
- staging tools (wp_get_staging_info etc.) now appear in tools/list on all plans — non-Pro calls return a clear upgrade prompt instead of silently being absent
- MCP server instructions include a Pro-features-not-active section when site is unlicensed, listing staging/SEO/WooCommerce as gated features with upgrade URL

## [3.3.8]

### Added
- Pro staging feature — WP Engine, Kinsta, Pressable adapters + Generic WP-CLI fallback
- REST endpoints GET /staging, POST /staging/push, GET /staging/status/{id}
- 4 MCP tools: wp_get_staging_info, wp_push_to_staging, wp_push_to_live, wp_get_staging_status
- push-to-live gates via approval request; actual push fires only after wp_apply_approval
- three integration providers added to Integration Manager (wpengine_staging, kinsta_staging, pressable_staging)

### Security
- SSRF mitigated — all host API calls use redirection=0; env IDs validated against adapter list_environments() before use; shell exec uses allowlisted binary path + escapeshellarg()

## [3.3.7]

### Added
- MCP resources/list returns 6 real site resources (site-info, site-context, pages, posts, design-references, elementor-status); resources/read proxies to internal mcpwp/v1 REST endpoints with API key forwarding
- MCP prompts/list returns 5 named prompt templates (audit-seo, build-page, refresh-content, onboard, design-match); prompts/get fills in arguments and returns messages[] array; both declared in initialize capabilities

## [3.3.6]

### Added
- wp_list_media gains search param (filter by title/filename); tool description updated to guide image-reuse workflow (check before uploading)

### Fixed
- wp_upload_media_b64 tool schema now exposes mime_type param (REST already accepted it; MCP tools/list was missing it)
- wp_update_menu_item tool description and error messages now include actionable hints — get menu_id from wp_list_menus, item_id from wp_list_menu_items; errors name the missing ID and the tool to call
- wp_upload_design_reference tool description and core missing_image error now include retry guidance — if image_url/image_base64 fails, upload separately then pass media_id

## [3.3.5]

### Added
- before/after change summary on wp_update_page and wp_update_post — response now includes changes[] (field, before, after) and unchanged[] so agents know exactly what shifted without a follow-up fetch
- structural_changes on wp_set_elementor — sections_before/after and widgets_before/after in every save response

### Security
- sslverify=false in Elementor CSS-prime loopback request fixed to respect WP_DEBUG

## [3.3.4]

### Security
- SSRF redirect bypass in wp_get_rendered_copy fetcher — redirection=0 was missing from the get_rendered_copy wp_remote_get call; redirect chains could bypass the same-host allowlist

## [3.3.3]

### Added
- mutation tool schema docs — wp_create_page/wp_update_page now document parent, template, menu_order; wp_create_post/wp_update_post now document categories and tags; all descriptions clarify Elementor vs Gutenberg usage (#569)

### Security
- SSRF redirect bypass — wp_get_rendered_copy, wp_get_rendered_html, and SEO validator fetchers now pass redirection=0 to prevent redirect chains from bypassing the same-host allowlist

## [3.3.2]

### Added
- wp_get_rendered_copy MCP tool — returns structured visible text (headings, paragraphs, button labels, list items, link text, image alts) from the live rendered page; ground-truth answer to "what does the page actually say?" (#569)

### Security
- sslverify=false in wp_get_rendered_html fixed — TLS enforced in production, relaxed only when WP_DEBUG is true

## [3.3.1]

### Added
- wp_flush_cache MCP tool flushes WordPress page cache after bulk edits; supports W3 Total Cache, WP Super Cache, LiteSpeed, WP Rocket, Cache Enabler, Breeze, and Cloudflare (#568)
- Elementor write tools (add_section, remove_section, replace_section, patch_elementor) now surface validation_warnings in response — matches existing set_elementor convention (#571)
- /onboard response includes suggested_first_tools context-aware list (Elementor vs Gutenberg path) and quick_start guidance string (#574)

### Security
- batch sub-request rate-limit bypass replaced — X-SPAI-Batch-Sub-Request HTTP header (externally spoofable) replaced with Mcpwp_REST_Batch::$is_batch_sub_request static property (#573)

## [3.3.0]

### Added
- render-truth across all validators — every MCPWP validator now reports validation_basis and validation_note so agents know whether issues were verified against rendered HTML or stored data (#569)
- validate_structured_data fetches live rendered page for published posts; renders JSON-LD injected by SEO plugins (Yoast/AIOSEO/@graph); suppresses false "no_structured_data" when plugin handles schema (#569)
- validate_internal_links now walks Elementor widget data (_elementor_data) alongside post_content — links in buttons, icon-boxes, and text-editor widgets are validated for broken targets, self-links, and non-canonical URLs; anchor-quality checks skip Elementor button links (#569)
- audit_seo_site exposes validation_basis per URL and a site-level validation_note counting how many URLs were render-verified (#569)
- validate_blocks response includes validation_basis: stored_block_data + note that dynamic/server-side blocks may render differently (#569)

### Fixed
- apply_internal_link duplicate-check now includes Elementor data — prevents false "duplicate link" skips on Elementor pages where post_content is empty (#569)

## [3.2.9]

### Added
- validate_seo_readiness now fetches the actual rendered <head> for published pages and reconciles issues against ground truth — false "missing meta description" and "noindex" issues caused by SEO plugin template rendering are suppressed (#569)
- rendered_head field in readiness response returns actual title, description, canonical, robots, og:title, og:description from the live page (#569)
- validation_basis field ("rendered_html" vs "stored_meta") and validation_note explain whether issues were verified against rendered output or stored meta (#569)
- rendered_noindex_detected issue fires when <meta name="robots"> in live <head> contains noindex even when stored meta doesn't (#569)

## [3.2.8]

### Fixed
- SEO readiness meta-description check now detects AIOSEO's excerpt fallback — pages with no stored per-post description no longer incorrectly warn "missing meta description" when AIOSEO renders one from the excerpt (#569)
- meta-description validation skips length check when value is an unrendered template string (Yoast/AIOSEO template tags); meta_description_source field added to readiness response (#569)
- apply_internal_link now checks Elementor widget data for existing links before inserting — prevents duplicate-link approval requests on Elementor pages where post_content is empty (#569)

## [3.2.7]

### Added
- wp_bulk_upload_media tool — import up to 20 images from URLs in one request; use instead of looping wp_upload_media_from_url for migrations (#566)

### Fixed
- wp_upload_media_from_url description now advises using bulk tool for 5+ images to stay under burst rate limit (#566)

## [3.2.6]

### Fixed
- REST Pro gate now enforces real license check — no more stub bypass (#564)
- remove dead hostname routing from agency proxy (latent Host-spoof surface) (#547)
- wp_rate_limit_status now shows real usage for API-key-authenticated requests (#533)

## [3.2.5]

### Fixed
- deduplicate Elementor element IDs on save to prevent CSS bleed across pages (#565)
- MCP internal tool dispatch no longer double-counts rate limit, fixing concurrency errors (#566)
- custom CSS set_custom_css warns on HTML-escaped operators, returns compact response, clarifies verification semantics (#567)

## [3.2.4]

### Security
- Encryption key is now stored in a dedicated WordPress option (mcpwp_encryption_key) instead of being derived from AUTH_SALT. AUTH_SALT rotation no longer silently corrupts stored provider credentials. A one-time non-destructive migration re-encrypts existing blobs on first load.
- Admin AJAX pro-gate bypass is now restricted to verified admin users with activate_plugins capability, confirmed nonce, and an explicit action allowlist.

## [3.2.3]

### Changed
- screenshots now save to the media library and return a URL by default — inline base64 (500KB+) overflowed AI-client contexts. Pass `inline=true` for raw base64.
- `wp_get_elementor` no longer duplicates the page data as a raw JSON string (`elementor_json`) — payload halved on large pages. Pass `include_raw=true` if you need the raw string.

## [3.2.2]

### Added
- Calling a Pro tool on a Free install now returns a clear upgrade-path error (JSON-RPC -32004 `pro_required` with plan and pricing link) instead of "unknown tool".

### Changed
- `/introspect` is compact by default (tool name + summary, ~80% smaller) so AI clients no longer overflow their context; pass `?full=true` for the legacy payload with full schemas.

### Fixed
- `/option` now refuses to overwrite an array/object option with a plain string (decodes valid JSON; `force_type_change=true` to override) — prevents accidental destruction of structured options such as Elementor theme-builder conditions.
- numeric values in API responses are returned as JSON numbers (analytics counts, Elementor `page_id`) for typed clients.

## [3.2.1]

### Changed
- Internal rebrand cleanup (spai→mcpwp) — cron label, code comments, and admin JS object renamed. Webhook deliveries now also emit `X-MCPWP-Signature`, `X-MCPWP-Event`, and `X-MCPWP-Webhook-ID` headers alongside the existing `X-SPAI-*` headers (both sets sent; `X-SPAI-*` retained for backward-compat). MCP tools/list response now also includes an `x_mcpwp` envelope key alongside `x_spai`. Bridge migration of legacy sites is unchanged.

## [3.2.0]

### Added
- Minimal OAuth 2.1 authorization server — MCP clients (Claude Desktop, ChatGPT) can now authenticate via browser sign-in instead of API keys. Adds RFC 9728 protected-resource metadata, RFC 8414 authorization-server metadata, PKCE S256 authorize endpoint, and token exchange (authorization_code + refresh_token). X-API-Key authentication is unchanged and unaffected. Requires `oauth_enabled` setting + allow-listed redirect URIs to activate.

### Fixed
- `wp_recall` tool PHP 8.x compatibility (#534).

## [3.1.0]

### Added
- Seamless migration bridge — sites upgrading from the earlier `site-pilot-ai` build carry over all content, API keys, OAuth access tokens, plugin settings, and licensing entitlement automatically. Existing API keys keep authenticating during and after the change; migration is idempotent and non-destructive (original data is left intact for rollback).
- Dual-prefix authentication — legacy keys and OAuth tokens continue to work alongside new `mcpwp_` credentials, with original scopes preserved.
- `Mcpwp_Migrate`: idempotent one-time option migration that copies all known `spai_*` options to their `mcpwp_*` equivalents on `plugins_loaded` and on activation. Non-destructive (originals kept), non-overwriting (skips existing `mcpwp_*` values), guarded by `mcpwp_migrated_from_spai` flag. Includes encryption sanity-check for `spai_integrations` blob with admin-visible warning if `AUTH_SALT` changed (provider keys must be re-entered).
- `find_legacy_spai_key()` in `Mcpwp_Api_Auth` trait: dual-prefix API key auth that accepts keys stored under `spai_api_keys` / `spai_api_key` so existing AI connections continue to work through the slug-rename cutover without re-keying. Uses the same `wp_check_password()` / `hash_equals()` path as the existing key lookup — no timing difference.
- `docs/BRIDGE-505-DESIGN.md`: slug-handover option analysis (Option A/B/C) with recommendation for architect + mumcp-warden review.
- `BridgeMigrateTest.php` / `BridgeAuthTest.php`: phpunit coverage for both.

### Changed
- WordPress.org Plugin Check clean (0 errors) — the WordPress.org build makes no third-party network calls and ships under GPL-2.0.
- per-site cutover runbook and verified MCP client connect matrix added.

## [3.0.1]

### Fixed
- signals fatal on every compute — `compute_seo_issues()` was calling the private `get_issues()` method and filtering on severity `critical` (which the store never writes). Now uses the public `list_issues(status=open, severity=error)`.
- `GET /signals` now lazily computes on first read — closes the permanently-empty feed on hosts where WP-Cron never fires (the mcpwp.net symptom). Response includes `last_computed`, `partial`, and `skipped_types`.
- `compute_pending_updates()` no longer calls `wp_update_plugins()` (remote requests to wordpress.org per plugin) on web requests — reads cached transient instead; network refresh is cron-only.
- Elementor broken-page checker fetches IDs only, then reads `_elementor_data` blobs one at a time — prevents large memory spikes.

### Changed
- `compute()` accepts a time budget; over-budget signal types are skipped with their stored values preserved and reported via `mcpwp_signals_meta` (`last_computed`, `skipped_types`, `partial`).
- Freemius is now the single source of truth for entitlement — removed legacy Lemon Squeezy store and self-started 14-day local trial that could independently grant Pro. New canonical `get_license_info()` accessor guarantees `plan` and `is_pro` never contradict.
- split four monolithic classes (REST Site 4531 lines, free/pro tool files, Admin 3491 lines, Elementor Basic 3299 lines) into focused per-surface controllers and trait groups — no behavior change.

### Security
- gateway tool-registration contract enforced — unknown tools rejected at `tools/call`, escalation vectors closed.

## [3.0.0]

### Changed
- Rebrand: the plugin is now **MCPWP** throughout — folder, main file, class names, function/option prefixes, constants, text domain, and REST namespace (`mcpwp/v1`). A clean reinstall is recommended; settings and API keys are re-created fresh.

## [2.8.31] - 2026-05-20

### Fixed
- Register Freemius update refresh hooks through WordPress directly so plugin and update-core screens clear stale update checks.
- Provide a CLI-safe host fallback before Freemius SDK initialization to avoid `HTTP_HOST` warnings during WP-CLI activation and update checks.

## [2.2.2] - 2026-04-01

### Changed
- Admin Library copy now makes the storage model explicit: SPAI archetypes and reusable parts are Elementor templates with SPAI metadata layered on top.

### Fixed
- Preserve existing archetype metadata on partial updates when only Elementor content changes.
- Preserve existing reusable-part metadata on partial updates when only Elementor content changes.
- Carry forward the `2.2.1` shared-host-safe Elementor mutation path and operator workflow improvements.

## [2.2.1] - 2026-04-01
### Added
- Shared-host-safe `elementor_data_base64` support for pro Elementor template, archetype, and part create/update routes.

### Changed
- API and guide docs now describe HostGator / ModSecurity-safe Elementor mutation patterns using form-encoded and base64 payloads.

### Fixed
- Reduce shared-host request-body incompatibilities that blocked archetype and part management on live sites protected by WAF rules.

## [2.2.0] - 2026-04-01

### Added
- Operator-focused admin polish with onboarding, update recovery, Library health, lineage counts, and drill-down asset views.
- Design-reference-driven draft generation from the admin Library, including automatic reusable-part creation and linkage.

### Changed
- README and plugin readme now position MCPWP as a reusable AI production system for WordPress operators.
- Library workflow guidance now better reflects site character, archetypes, parts, and design-reference usage.

### Fixed
- Carry forward the `2.1.0` feature set, including design references, structured archetypes, guided site character, Figma integration, updater hardening, and Elementor 4 save persistence verification.

## [2.1.0] - 2026-04-01

### Added
- Image-based design references so uploaded screenshots and mockups can be stored as reusable site assets.
- MCP and REST tools for storing, listing, reading, and updating design references.
- A structured `build_from_design_reference` workflow for turning uploaded design images into local pages, archetypes, and reusable parts.

### Changed
- Media and onboarding guidance now tells models to preserve approved screenshots as design references before building.

### Fixed
- Carry forward the `2.0.0` feature set, including archetypes, guided site character, Figma integration, updater hardening, and Elementor 4 save persistence verification.

## [2.0.0] - 2026-04-01

### Added
- Structured site-building primitives with reusable Elementor parts, page archetypes, and WooCommerce product archetypes.
- Guided site character authoring, public `llms.txt` output, and AI-facing context inheritance for page and product archetypes.
- Figma integration with personal token and OAuth configuration, plus REST and MCP design intake tools.
- Library and Integrations admin improvements for curating archetypes, parts, and design connections.

### Fixed
- Carry forward updater hardening and Elementor 4 save persistence verification from the `1.8.x` series.

## [1.8.6] - 2026-03-31

### Changed
- Test release to validate live auto-update from `1.8.5` with the filesystem-aware updater.

### Fixed
- Carry forward updater filesystem initialization, diagnostics, manifest hardening, and Elementor 4 save verification from `1.8.5`.

## [1.8.5] - 2026-03-31

### Fixed
- Initialize the WordPress filesystem before self-updates and surface actionable upgrader errors when plugin installs fail.
- Carry forward updater manifest selection hardening and Elementor 4 save persistence verification from `1.8.4`.

## [1.8.4] - 2026-03-31

### Changed
- Test release to validate end-to-end WordPress self-update from the canonical `mumega.com` manifest and ZIP.

### Fixed
- Carry forward updater hardening and Elementor 4 save persistence verification from `1.8.3`.

## [1.0.43] - 2026-02-07

### Fixed
- Pro MCP tools now unlock based on active license (single-plugin distribution), not a separate Pro add-on.

## [1.0.42] - 2026-02-07

### Added
- MCP tools for Elementor Theme Builder templates (get/create/update/delete).

## [1.0.41] - 2026-02-07

### Added
- MCP tools for Elementor Theme Builder templates (get/create/update/delete).

## [1.0.40] - 2026-02-07

### Added
- `wp_introspect` MCP tool and `/introspect` REST endpoint so AI clients can discover tools, auth, and capabilities automatically.

### Changed
- Use `activate_plugins` for WP Admin access checks so all administrators can access the plugin screens on managed hosts.

## [1.0.39] - 2026-02-07

### Changed
- Switch Freemius integration to single-plugin distribution (no premium zip required after checkout).
- Allow Freemius `Account`/`Pricing` pages for administrators even if `manage_options` is restricted by the host.

## [1.0.38] - 2026-02-07

### Fixed
- Ensure Freemius `Account` and `Pricing` admin pages are registered under the plugin menu to prevent "Sorry, you are not allowed to access this page." on managed hosts.

## [1.0.37] - 2026-02-07

### Added
- Include license status in the `/site-info` API response so clients can detect Pro state.

## [1.0.36] - 2026-02-07

### Fixed
- Treat Freemius plan names case-insensitively so Agency/Pro plans correctly mark the site as Pro.
- Hide upgrade/Go Pro CTAs in plugin action links when Pro is active (including Freemius-injected links).

## [1.0.35] - 2026-02-07

### Added
- Alerting via existing SPAI webhooks on error spikes (5xx, 401/403).
- Privacy controls for logging: disable storing `response_data`, configurable redaction keys.
- Dashboard widget showing recent API activity.

## [1.0.34] - 2026-02-07

### Added
- WP Admin `Activity Log` page for traceability (request actions, endpoints, status codes, redacted details).

## [1.0.33] - 2026-02-07

### Fixed
- Improve RankMath detection and report LearnPress capability.

### Added
- Admin-scoped MCP tools for reading options (homepage), menus, generic content listing, and CPT deletion.

## [1.0.32] - 2026-02-07

### Changed
- Release automation update.

## [1.0.31] - 2026-02-07

### Changed
- Release automation update.

## [1.0.30] - 2026-02-06

### Changed
- Switched to single-plugin distribution (no separate `mcpwp-premium` packaging). Pro stays license-gated.

## [1.0.29] - 2026-02-06

### Fixed
- Ensure Freemius updates include Pro module files by shipping `includes/pro/**` in both free + premium zips and gating Pro loading by license.

## [1.0.28] - 2026-02-06

### Fixed
- Correctly detect premium vs free mode in Freemius SDK (`is_premium`) so premium installs receive premium updates (and Pro modules ship).

## [1.0.27] - 2026-02-06

### Added
- Bundled all Pro endpoints into the premium package (SEO, Forms, Themes, Users, Widgets, WooCommerce, Multilang) with no separate Pro plugin install.

## [1.0.26] - 2026-02-06

### Added
- Bundled Elementor Pro endpoints into the premium package (no separate Pro plugin install).

## [1.0.25] - 2026-02-06

### Fixed
- Prevented premium activation fatal when both `mcpwp` and `mcpwp-premium` are installed at the same time.
- Restored Freemius premium-version configuration and detect premium/free mode from the plugin directory name.

## [1.0.24] - 2026-02-06

### Fixed
- Disabled Freemius premium-version auto swap to prevent checkout activation crashes on installs that use separate Pro plugin packaging.

## [1.0.23] - 2026-02-06

### Fixed
- Switched Freemius product configuration to premium-version mode (`has_premium_version: true`, `has_addons: false`) to match non-add-on distribution.

## [1.0.22] - 2026-02-06

### Fixed
- Prevented activation/deletion fatals when `mcpwp_fs()` exists but does not return a valid Freemius instance.
- Guarded Freemius SDK hook registration (`add_filter`/`add_action`) behind runtime instance checks.
- Hardened license helper methods to safely handle unavailable Freemius instance methods.

## [1.0.21] - 2026-02-06

### Fixed
- Prevented activation/deletion fatals when `mcpwp_fs()` exists but does not return a valid Freemius instance.
- Guarded Freemius SDK hook registration (`add_filter`/`add_action`) behind runtime instance checks.
- Hardened license helper methods to safely handle unavailable Freemius instance methods.

## [1.0.20] - 2026-02-06

### Added
- MCP tool annotations for safety hints: `readOnlyHint`, `openWorldHint`, `destructiveHint`
- New MCP tools: `wp_search` and `wp_fetch`
- New REST endpoints: `/search`, `/fetch`, and `/oauth/token` (client credentials grant)
- OAuth settings in admin: enable toggle, client ID, client secret (hashed), token TTL
- ChatGPT readiness documentation:
  - `docs/CHATGPT_CONFORMANCE.md`
  - `docs/CHATGPT_APP_SUBMISSION.md`
- ChatGPT/MCP conformance script: `tests/test-chatgpt-conformance.sh`

### Changed
- Cloudflare MCP transport adapter now supports configurable response mode (`auto`, `json`, `sse`)
- MCP notification handling in worker transport now returns `204` with empty body

### Security
- OAuth bearer tokens now enforce existing read/write/admin scope checks through API auth flow

## [1.0.18] - 2026-02-06

### Added
- Scoped API key lifecycle management: create, list, and revoke keys with metadata (`created_at`, `last_used_at`, `revoked_at`)
- API key scope enforcement (`read`, `write`, `admin`) across REST routes and MCP tool calls
- MCP tools for API key operations: `wp_list_api_keys`, `wp_create_api_key`, `wp_revoke_api_key`
- Development quality pipeline with CI checks for PHP syntax lint, coding standards (tests), and PHPUnit
- PHPUnit scaffolding and baseline tests for auth, rate limiter, and MCP endpoint behavior

### Security
- Legacy plaintext API keys are now force-hashed during migration into scoped key storage
- API key regeneration now revokes previous active scoped keys before issuing a new primary key

## [1.0.17] - 2026-02-06

### Security
- Identity-aware rate limiting: valid keys get `key:<hash>` bucket, invalid attempts get `invalid:<IP>` bucket
- Removed admin user fallback in `set_api_user_context()` — returns 500 if `mcpwp_api_agent` user missing instead of silently operating as admin
- Removed `?api_key=` query parameter authentication — prevents key leakage in URLs, server logs, and Referer headers
- SSRF protection added to media upload-by-URL endpoint via `Mcpwp_Security::validate_external_url()`
- Webhook URLs re-validated at send time to defend against DNS rebinding attacks
- Webhook delivery: timeout reduced to 15s, redirects disabled (`redirection => 0`), SSL enforced

### Fixed
- Rate limiter sliding window bug: `set_transient()` TTL now uses remaining window time instead of full 60/3600s (was extending window on every request)
- Rate limiter `remaining` count prevented from going negative with `max(0, ...)`
- Rate limiter window data properly validated and reset when expired or malformed

### Added
- `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers on all SPAI REST responses
- `Retry-After` header on 429 rate-limit responses
- MCP `resources/list` handler (returns empty resources array)
- MCP `resources/read` handler (returns resource-not-found error)
- MCP `resources` capability advertised in `initialize` response
- `is_api_key_match()` extracted method for cleaner auth validation

## [1.0.16] - 2026-02-06

### Fixed
- Freemius premium activation fatal error — switched from `has_premium_version` to `has_addons` architecture
- Test Connection button now works reliably — bypasses internal REST dispatch which doesn't carry API key headers
- Pro plugin admin hook corrected from `tools_page_mcpwp` to `toplevel_page_mcpwp`

### Changed
- Tested up to WordPress 6.9.1

## [1.0.15] - 2026-02-06

### Security
- Removed `manage_options` and `edit_theme_options` from `mcpwp_api_agent` role (principle of least privilege)
- Added SSRF protection: `Mcpwp_Security::validate_external_url()` blocks private/reserved IPs on webhooks and media upload
- API keys now generated with `bin2hex(random_bytes(24))` — 192 bits of cryptographic randomness
- MCP batch requests capped at 10 per call (was unlimited)
- CORS now respects `allowed_origins` setting; wildcard only when unconfigured
- Webhook delivery: explicit `sslverify => true`, redirects disabled (`redirection => 0`)
- Elementor data validated for max size (5MB) and nesting depth (30 levels)
- Migration: existing installs auto-strip `manage_options` from API role on activation

### Added
- New `Mcpwp_Security` utility class for SSRF and payload validation
- Native MCP endpoint (`POST /wp-json/mcpwp/v1/mcp`) — direct Claude Desktop/Code connection
- Top-level admin menu with SVG icon (moved from buried Tools submenu)
- Tabbed admin interface: Setup, Connect AI, Settings, Advanced
- One-click Test Connection button with AJAX site-info check
- Copy-paste AI config guides: Claude Desktop, Claude Code, ChatGPT
- First-activation welcome banner with visible API key and copy button
- License/Upgrade card on Settings tab for Freemius Pro activation
- `do_action('mcpwp_admin_settings_cards')` hook for Pro extensions
- ChatGPT OpenAPI spec (`docs/openapi-chatgpt.yaml`, 17 endpoints)
- Cloudflare Worker MCP handler for remote MCP connections
- npm MCP server package with `--setup`, `--version`, `--test` CLI flags

### Fixed
- Fatal error: `log_activity()` access level conflict in MCP class (private vs protected inheritance)
- MCP activity logging: corrected column names to match `wp_mcpwp_activity_log` schema
- Freemius `first-path` aligned with new top-level menu (`admin.php?page=mcpwp`)
- MCP namespace consistency: all components use `mcpwp/v1`
- Admin page hook: `toplevel_page_mcpwp` (was `tools_page_...`)
- MCP tool count: 30 tools (removed 6 non-existent endpoint mappings)

## [1.0.14] - 2026-02-05

### Security
- API keys now hashed using `wp_hash_password()` instead of plain text storage
- New `mcpwp_api_agent` role with limited capabilities (not full admin)
- New `mcpwp_bot` service account for handling API requests
- API key shown only once after regeneration, then masked in UI
- Legacy plain-text keys auto-migrate to hashed on first API request
- Freemius SDK private method calls wrapped in try-catch

### Fixed
- Corrected `is_premium` flag to `false` for free version
- Improved backward compatibility for existing installations

## [1.0.13] - 2026-02-05

### Changed
- Switched plugin updates from GitHub to Freemius
- Removed custom GitHub updater class
- Removed `uninstall.php` (using Freemius `after_uninstall` hook instead)

### Added
- Transient and cron cleanup in Freemius uninstall hook

### Removed
- `class-mcpwp-updater.php`
- GitHub token settings from admin

## [1.0.12] - 2026-02-04

### Changed
- Updated Freemius SDK integration with correct configuration
- Renamed `mcpwp_fs()` to `mcpwp_fs()` per Freemius conventions
- Added multisite network support (`WP_FS__PRODUCT_23824_MULTISITE`)

### Added
- 14-day trial configuration with payment requirement
- Custom connect message for opt-in screen

## [1.0.11] - 2026-02-04

### Added
- Freemius SDK integration for licensing and updates
- License management abstraction layer (`Mcpwp_License` class)
- Upgrade banner in admin dashboard
- Support for Pro, Agency plans via Freemius

### Changed
- License checking now uses unified interface (supports Freemius or custom backend)

## [1.0.0] - 2024-01-01

### Added
- Initial release
- REST API with 14 endpoints
- Posts CRUD operations (create, read, update, delete)
- Pages CRUD operations
- Media upload (file and URL)
- Draft management (list and bulk delete)
- Basic Elementor support (get/set page data, create Elementor page)
- API key authentication
- Activity logging with configurable retention
- Admin settings page
- Plugin detection for Elementor, SEO plugins, form plugins, WooCommerce
- WordPress.org compliant readme.txt
- Internationalization support
- Clean uninstall

### Security
- CSRF protection with nonces
- Capability checks on all admin actions
- Input sanitization using WordPress functions
- Output escaping
- Secure API key generation
- Rate limiting ready architecture
