# WordPress.org Retained Surface Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:test-driven-development and superpowers:systematic-debugging task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the exclusive Task 2 REST/MCP surface so every request is bound to one explicit WordPress user and passes exact key-scope, category, capability, schema, lifecycle, and local-media checks.

**Architecture:** Keep the existing retained controllers and 17-tool registry, but make `Mcpwp_Api_Auth` establish the immutable stored `user_id` and make `Mcpwp_REST_API` provide reusable authorization, category, body-limit, and strict validation primitives. Each route registers explicit public argument schemas and a route-specific permission callback; core content/media classes receive already validated values and retain relationship/file cleanup defenses. Snapshot fixtures lock the routes and tools independently of the Task 2 trusted source hashes.

**Tech Stack:** PHP 7.4, WordPress 6.1 REST API and capability mapping, PHPUnit 9, JSON fixtures, Docker, Bash/Python artifact verifier.

## Global Constraints

- Retain exactly the Task 2 routes and exactly 17 MCP tools; add no remote input, generic option, raw-code, or configuration mutation surface.
- Accept credentials only through `X-API-Key`; never accept query/body keys, Bearer/OAuth credentials, ambient cookie identity, first-role users, hidden users, user `0`, or administrator fallback.
- Every usable key record has a positive immutable `user_id`, a non-empty subset of `read|write|admin`, and zero or more unique categories from `site|content|media|taxonomy`.
- Every request requires both the exact hierarchical key scope and the exact WordPress primitive/meta capability; direct REST and nested MCP enforce the same category controls.
- Mutations validate raw body size, UTF-8, depth, keys, strict types, bounds, relationships, status, and HTML before any post/term/option/transient/meta/file/log write.
- Ordinary JSON is at most 1 MiB; media/base64 MCP aggregate input is at most 15 MiB; decoded/file media is non-empty and at most 10 MiB.
- Runtime remains compatible with PHP 7.4 and WordPress 6.1.
- `.superpowers/sdd/task-3-acceptance-oracle.md` and `.superpowers/sdd/task-3-brief.md` are read-only; update only the ignored Task 3 report after implementation.

---

### Task 1: Authoritative Route and Tool Snapshots

**Files:**
- Create: `mcpwp/tests/fixtures/wporg-routes.json`
- Create: `mcpwp/tests/fixtures/wporg-tools.json`
- Create: `mcpwp/tests/WporgRouteAuthorizationTest.php`
- Modify: `mcpwp/tests/McpFreeToolsTest.php`
- Modify: `mcpwp/tests/bootstrap.php`

**Interfaces:**
- Consumes: runtime `register_rest_route()` registrations and `Mcpwp_MCP_Free_Tools::get_tools()`/`get_tool_map()`.
- Produces: exact bidirectional route and tool contracts used by every later checkpoint.

- [ ] Encode every retained route/method in `wporg-routes.json` with route regex, method, baseline/dynamic scope, callback identity, category, WordPress capability/object rule, mutation flag, and exact argument schema from oracle sections 2, 4, and 5.
- [ ] Encode all 17 tools in `wporg-tools.json` with method/route, category, annotations, exact description, required capabilities, complete JSON Schema bounds/enums/defaults/items, `required`, and `additionalProperties: false`.
- [ ] Add route registration helpers to the test bootstrap that preserve callbacks and `args`, support request body/file/raw-body inspection, model explicit users/capabilities/object IDs, and never grant missing capabilities by default in the new test classes.
- [ ] Add snapshot tests that compare runtime routes and runtime `tools/list` to both fixtures in both directions and mutation-oracle tests that alter a method, permission callback, bound, capability, schema, annotation, map entry, and trusted registry byte.
- [ ] Run `WporgRouteAuthorizationTest.php` and `McpFreeToolsTest.php`; record RED for missing route args/callback specificity, weak schemas/annotations, absent capabilities, and fixture drift.

### Task 2: Explicit Key Owner, Scope, Category, and Limiter Context

**Files:**
- Modify: `mcpwp/includes/traits/trait-mcpwp-api-auth.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-api.php`
- Modify: `mcpwp/includes/class-mcpwp-activator.php`
- Modify: `mcpwp/includes/class-mcpwp-migrate.php`
- Modify: retained REST controllers under `mcpwp/includes/api/`
- Modify: `mcpwp/tests/ApiAuthTest.php`
- Modify: `mcpwp/tests/BridgeAuthTest.php`
- Modify: `mcpwp/tests/BridgeMigrateTest.php`
- Modify: `mcpwp/tests/DisabledToolCategoriesTest.php`
- Modify: `mcpwp/tests/RateLimiterTest.php`
- Modify: `mcpwp/tests/WporgRouteAuthorizationTest.php`

**Interfaces:**
- Consumes: stored key records `{id,hash,user_id,scopes,tool_categories,created_at,last_used_at,revoked_at}` and route policy metadata.
- Produces: `verify_api_key()` that installs only the stored owner, plus reusable `authorize_request($request,$scope,$capability,$object_id,$category)` behavior and limiter calls carrying method and key record.

- [ ] Add RED identity tests for missing/invalid/query/body/Bearer/revoked keys, empty/unknown scopes, unbound legacy owner, deleted owner, ambient-cookie mismatch, and two keys whose distinct stored owners produce distinct object-capability results.
- [ ] Add RED route tests proving read keys cannot call any mutation, write keys cannot publish/private/edit public objects/delete drafts/use admin foundations, and missing `read`, `edit_posts`, `edit_pages`, `publish_posts`, `publish_pages`, `upload_files`, `manage_categories`, or `mcpwp_manage_settings` fails.
- [ ] Add RED category tests proving site-disabled and per-key-disabled categories reject direct REST and nested MCP identically, including reads; add rate tests proving the limiter receives the actual HTTP method and full matched key record.
- [ ] Require an exact positive `user_id`, resolve it with `get_user_by('id',...)`, call `wp_set_current_user()` before category/capability checks, return `api_key_owner_setup_required` or `api_key_owner_unavailable` with status 500, and remove first-role/ambient/admin fallbacks.
- [ ] Make scope parsing reject empty, unknown, duplicate, or non-array input instead of defaulting; preserve hierarchical comparison without treating an absent scope as read; ignore stored legacy `role` for authorization.
- [ ] Bind admin-created keys to the authenticated administrator and REST-created keys to the calling key owner; never accept `user_id` or `role` from REST. Migrate legacy keys only when an explicit authenticated migration owner is available.
- [ ] Pass request method and matched key record to `Mcpwp_Rate_Limiter::check_limit()`; disallow caller-controlled per-key rate override fields in key creation while retaining reviewed stored-record overrides.
- [ ] Add explicit route-specific permission callbacks/policies for scope, category, capability, object type/ID, current status, and requested status; make rate-limit GET admin plus `mcpwp_manage_settings` like the other admin foundations.
- [ ] Run the focused auth/category/rate/route suites; expect GREEN without weakening any RED assertion.

### Task 3: Strict REST Schemas, Body Semantics, and Safe Content

**Files:**
- Modify: `mcpwp/includes/traits/trait-mcpwp-sanitization.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-api.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-posts.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-pages.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-site-taxonomy.php`
- Modify: `mcpwp/includes/core/class-mcpwp-posts.php`
- Modify: `mcpwp/includes/core/class-mcpwp-pages.php`
- Modify: `mcpwp/includes/core/class-mcpwp-drafts.php`
- Create: `mcpwp/tests/WporgMaliciousPayloadTest.php`
- Modify: `mcpwp/tests/RestPagesTest.php`
- Modify: `mcpwp/tests/SanitizationWrapperTagsTest.php`

**Interfaces:**
- Consumes: strict route-specific schema definitions and JSON-only mutation bodies.
- Produces: validated canonical arrays or bounded `WP_Error` values before any mutation.

- [ ] Add RED tests for unknown top-level/nested keys; null/numeric-string/boolean-string confusion; invalid UTF-8; depth 9; 33-key objects; 101-item arrays; duplicate IDs/tags; out-of-range IDs/pagination/menu order; empty required values; and query parameters attempting to override post type/status/bounds.
- [ ] Add boundary RED tests at below/exact/above byte limits for title/name/slug/content/excerpt/search/term/media text; invalid canonical slug/template; and create/update bodies with missing required or no mutable fields.
- [ ] Add unsafe HTML RED cases for script/style/PHP/wrapper/mixed-case tags, encoded or whitespace event handlers, JavaScript/unsafe data URLs, disallowed elements/attributes, and unsafe nested strings; assert rejection rather than stripped storage.
- [ ] Implement PHP 7.4-compatible validation helpers for exact keys, types, UTF-8, byte lengths, enums, integers, unique arrays, depth/key counts, and ordinary/media raw body limits. Mutation controllers read JSON only, except the reviewed multipart fields; GET query schemas cannot override internal post type/status constraints.
- [ ] Validate post/page lifecycle exactly: default draft; accept only draft/pending/publish/private; require admin scope plus publish capability for publish/private; require admin scope to edit existing publish/private; reject trash/future/empty/unknown; make PUT/PATCH identical non-empty partial updates.
- [ ] Validate HTML by rejecting PHP/wrapper tags and requiring byte equality with `wp_kses_post`; validate category existence/taxonomy/assign capability, tag bounds, featured-image attachment/image/non-SVG/read capability, page parent readability/self/cycle, and exact installed template keys before mutation.
- [ ] Constrain list/read visibility to publish by default and caller-owned non-public objects without `edit_others_*`; require `read_private_*`; enforce wrong-type 404 and object `read_post`/`edit_post`/`delete_post`; omit `edit_url` without `edit_post`.
- [ ] Make taxonomy list routes apply exact pagination/search/order args and return bounded errors rather than converting `get_terms()` failures to empty success.
- [ ] Replace PHP 7.4-incompatible arrow functions and run the focused payload/content/controller suites to GREEN.

### Task 4: Equivalent Multipart and Base64 Local Media

**Files:**
- Modify: `mcpwp/includes/api/class-mcpwp-rest-media.php`
- Modify: `mcpwp/includes/core/class-mcpwp-media.php`
- Modify: `mcpwp/tests/MediaSecurityTest.php`
- Modify: `mcpwp/tests/WporgMaliciousPayloadTest.php`
- Modify: `mcpwp/tests/bootstrap.php`

**Interfaces:**
- Consumes: exactly one multipart `file` plus title/alt/caption, or strict Base64/data URI plus filename/MIME/text metadata.
- Produces: one equivalent validated local-file policy and complete temp/orphan cleanup on every error.

- [ ] Add RED cases for missing/extra file parts, upload errors, non-upload temp paths, path traversal, controls/NUL, missing extension, double executable extensions, SVG, disallowed/mismatched extension/client/data-URI/detected MIME, malformed/wrapped Base64, remote URL/path keys, zero bytes, encoded/raw/decoded size overflow, and metadata text bounds.
- [ ] Add failure-injection RED cases for temp creation/write, upload-dir, filesystem initialization/move/stat/chmod, `wp_insert_attachment()` returning `WP_Error` or `0`, metadata generation/update, caption/meta update, and assert every temp/orphaned file and attachment is cleaned.
- [ ] Build one shared validation path using allowed WordPress MIME types plus `wp_check_filetype()` and `wp_check_filetype_and_ext()` actual bytes; require exact filename sanitization and MIME agreement; always reject SVG/executable segments and `unfiltered_upload` bypass.
- [ ] Keep multipart restricted to genuine uploaded files and base64 strict/unbroken with one exact optional data URI; reject remote URLs, filesystem paths, stream wrappers, arbitrary post data, parents, and extra keys before temp writes.
- [ ] Treat attachment ID `0` as insertion failure, clean the moved file; clean attachment/file on any metadata/meta/caption failure; return 413 for size and 415 for media mismatches.
- [ ] Run media and malicious-payload suites to GREEN and verify both upload paths return equivalent results/errors.

### Task 5: Administrative Validation, Site Counts, and Rate Reset

**Files:**
- Modify: `mcpwp/includes/api/class-mcpwp-rest-site-settings.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-rate-limit.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-api-keys.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-site-info.php`
- Modify: `mcpwp/includes/class-mcpwp-rate-limiter.php`
- Modify: `mcpwp/tests/WporgRouteAuthorizationTest.php`
- Modify: `mcpwp/tests/WporgMaliciousPayloadTest.php`
- Modify: `mcpwp/tests/RateLimiterTest.php`

**Interfaces:**
- Consumes: exact direct-REST-only schemas from oracle section 4.3.
- Produces: strict curated settings/key/rate responses with no stale or privilege-bearing fields.

- [ ] Add RED tests proving API-key create accepts only label/scopes/tool_categories, binds caller owner, rejects role/user_id/rate/capability/metadata, lists immutable `user_id` without hashes/plaintext, strictly parses `include_revoked`, and returns 404 for missing/already revoked IDs.
- [ ] Add RED tests proving plugin settings accept only at least one strict boolean/integer field, preserve explicit false, remove stale option keys from reads/writes, and reject coercion/unknown/empty bodies.
- [ ] Add RED tests proving rate settings enforce strict types/ranges/unique bounded whitelist and `burst_limit <= requests_per_minute` without clamping; reset accepts only canonical `key:<stored-id>` identifiers and cannot select arbitrary transients.
- [ ] Add RED tests proving site info calls `wp_count_posts($type,'readable')` and returns numeric readable counts rather than raw status-count objects.
- [ ] Implement exact admin schemas and canonical stored objects; validate before option/transient writes; remove stale keys from response and storage; make limiter setting updates return errors on invalid combinations instead of sanitizing/clamping.
- [ ] Run focused administrative/site/rate suites to GREEN.

### Task 6: Strict JSON-RPC and Tool/REST Parity

**Files:**
- Modify: `mcpwp/includes/api/class-mcpwp-rest-mcp.php`
- Modify: `mcpwp/includes/mcp/class-mcpwp-mcp-tool-registry.php`
- Modify: `mcpwp/includes/mcp/class-mcpwp-mcp-free-tools.php`
- Modify: `mcpwp/tests/McpEndpointTest.php`
- Modify: `mcpwp/tests/McpFreeToolsTest.php`
- Modify: `mcpwp/tests/WporgRouteAuthorizationTest.php`
- Modify: `mcpwp/tests/fixtures/wporg-tools.json`
- Modify: `mcpwp/scripts/wporg-artifact-policy.json`

**Interfaces:**
- Consumes: exact route schemas/policies and 17-tool fixture.
- Produces: protocol `2024-11-05`, strict tool schemas, ordered notification-aware batches, safe JSON-RPC error mapping, and the same nested authorization/result as direct REST.

- [ ] Add RED protocol cases for missing/wrong jsonrpc, invalid method/id/params, unknown method/tool, extra params, unsupported initialize version, empty/11-item/non-object batch, notifications, notification-only 204, mixed ordering, invalid arguments, aggregate 15 MiB media batch, and safe error-data mapping.
- [ ] Add a 17-tool parity matrix proving every tool executes a real nested permission callback and matches direct REST under allowed and denied category/scope/capability/object cases; prove a denied first batch item cannot authorize the second and nested callers cannot forge the limiter credit.
- [ ] Validate envelopes/items and exact per-method params before dispatch; preserve notification semantics/order; reject unknown arguments against the tool schema; never sanitize a tool name/ID/param into validity.
- [ ] Map 403/404/413/415/429 and stable retained errors to the exact oracle JSON-RPC codes/data while exposing no stack, path, SQL, key hash, body, or stored content.
- [ ] Generate complete JSON Schemas with bounds/enums/defaults/items/required/`additionalProperties:false`; set annotations exactly; make publishing requirements explicit in descriptions; keep only draft delete destructive.
- [ ] Update the six Task 2 trusted source SHA-256 values after deliberate registry/trait changes and prove source plus ZIP hashes; run MCP/tool/snapshot suites to GREEN.

### Task 7: WordPress 6.1 and PHP 7.4 Integration Gate

**Files:**
- Create: `mcpwp/tests/integration/wporg-hardening.php`
- Create: `mcpwp/scripts/test-wp61-hardening.sh`
- Modify: `mcpwp/tests/WporgRouteAuthorizationTest.php`
- Modify: `mcpwp/tests/WporgMaliciousPayloadTest.php`

**Interfaces:**
- Consumes: exact built ZIP and a disposable WordPress 6.1/PHP 7.4 environment.
- Produces: real `map_meta_cap`, REST validation, KSES, upload, ownership, cleanup, and compatibility evidence.

- [ ] Install the exact built ZIP into disposable WordPress 6.1/PHP 7.4 plus MariaDB; create two users with intentionally different caps, bind separate keys, and exercise own/other draft/private reads, edits, deletion, category assignment, KSES rejection, and both local uploads through actual REST dispatch.
- [ ] Assert unbound/deleted owners fail closed, no cookie identity leak occurs, REST unknown args/types/bounds fail, upload temp/orphan cleanup holds, and `WP_DEBUG_LOG` contains no warning/notice/fatal.
- [ ] Lint every shipped/test PHP file with PHP 7.4 and scan runtime for PHP 8-only constructs/functions listed in the oracle.
- [ ] Run the exact route/tool snapshots, authorization, payload, JSON-RPC, existing full PHPUnit suite, and the real WordPress 6.1 integration script.

### Task 8: Artifact, Report, and Commit

**Files:**
- Modify: `.superpowers/sdd/task-3-report.md` (ignored evidence file only)
- Modify: Task 3 tracked files from Tasks 1–7

**Interfaces:**
- Consumes: all fresh GREEN outputs and trusted hashes.
- Produces: deterministic verified WordPress.org artifact and one reviewed local commit.

- [ ] Build twice with `mcpwp/scripts/build-wporg.sh`, run `verify-wporg-artifact.sh` after each, and require byte-identical SHA-256 values.
- [ ] Run `git diff --check`, JSON parsing for both fixtures/policy, PHP 7.4 lint, exact trusted source/ZIP hash comparison, and inspect the complete scoped diff for unrelated changes.
- [ ] Update only `.superpowers/sdd/task-3-report.md` with exact RED/GREEN commands, counts, WordPress 6.1 evidence, artifact hash, and any explicitly justified tracked deviation.
- [ ] Commit the reviewed Task 3 scope as `fix(wporg): harden minimal MCP content surface`; do not push.
