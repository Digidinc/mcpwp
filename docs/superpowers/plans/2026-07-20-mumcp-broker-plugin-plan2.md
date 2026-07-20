# mumcp Broker — Plugin Integration (Plan 2 of 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. TDD, one task per implementer, review between tasks.

**Goal:** Make the MCPWP plugin (a) advertise mumcp.com as its OAuth authorization server, (b) enroll once with the broker, and (c) validate broker-issued opaque Bearer tokens via cached introspection — as an ALTERNATIVE to `X-API-Key`, so claude.ai/ChatGPT can sign in. The broker Worker is already built + deployed at https://mumcp.com.

**Architecture:** The plugin gains a small introspection *client* (not an OAuth server). A Bearer token on an MCP request is validated by POSTing mumcp.com's `/oauth/introspect` with the site's per-site credential, caching the positive result ≤60s. A local kill-switch (`mcpwp_broker_enrollment` empty) rejects all Bearer tokens with no network. The existing `X-API-Key` path is untouched.

**Tech Stack:** PHP 7.4+/8.3, WordPress REST, PHPUnit (Docker `php:8.3-cli`), `Mcpwp_Encryption` (sodium), WP transients.

## Global Constraints
- Ground truth: `/tmp/claude-1002/-mnt-HC-Volume-104325311-projects-sitepilotai/0a12ed7a-981e-4f23-b6ed-4d080b996b4b/scratchpad/plan2-grounding.md` — exact file:line hook points. Every task reads it.
- **Broker contract (live, do not change):**
  - Discovery: broker serves `https://mumcp.com/.well-known/oauth-authorization-server`.
  - Enrollment: broker 302s browser to `<site>/wp-json/mcpwp/v1/broker/enroll-callback?nonce=<n>&return=https://mumcp.com/enroll/complete`; then broker POSTs `<site>/wp-json/mcpwp/v1/broker/enroll-confirm {nonce}` server-to-server, expecting `200 {confirmed:true}` ONLY if the site holds that nonce as a pending admin-initiated enrollment (single-use). Final payload stored: `{issuer:"https://mumcp.com", site_id, audience(=site canonical url), introspection_endpoint:"https://mumcp.com/oauth/introspect", site_credential}`.
  - Introspection: `POST https://mumcp.com/oauth/introspect`, HTTP Basic `base64(site_id ':' site_credential)`, body `token=<opaque>` (form). Response `{active, scope, sub, site_id, aud, exp}` or `{active:false}`.
  - Scope model: broker only issues `read`|`write` (never `admin`). Map: `read`→read scope, `write`→write scope. **`admin` capability is NEVER reachable via a broker token.**
- **Security musts:**
  - Kill-switch: `mcpwp_broker_enrollment` empty/absent → reject Bearer instantly, no network.
  - Audience check: reject unless introspection `aud`/`site_id` == this site's stored `audience`/`site_id`.
  - Cache: positive results only, keyed `hash(token)` (sha256), TTL = min(60, exp-now); never cache negative as positive.
  - **Fail-closed** on broker unreachable / non-200 / malformed (reject the request).
  - `site_credential` stored ENCRYPTED via `Mcpwp_Encryption` (like provider keys), never returned in any API response or logged.
  - `enroll-callback` + `enroll-confirm` gated: callback requires `current_user_can('manage_options')` (admin-initiated); confirm verifies a single-use pending nonce. The broker's whole site-control proof depends on this.
- **Version:** current 3.6.4. This ships as **3.7.0** (new feature surface). Bump `mcpwp.php` (header + `MCPWP_VERSION`), `readme.txt` (Stable tag + changelog), `version.json`, `mcpwp/VERSION`.
- Test command: `cd mcpwp && docker run --rm -v "$(pwd):/app" -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml` (baseline 502 tests pass). Run the FULL suite each task.
- Follow existing plugin conventions (class naming `class-mcpwp-*.php`, REST registration, `Mcpwp_` prefix). No new external deps.

---

### Task 1: `.well-known/oauth-protected-resource` → point at mumcp.com when broker-enrolled
**Files:** modify `mcpwp/includes/class-mcpwp-oauth-well-known.php` (`serve_protected_resource_metadata()`); test `mcpwp/tests/BrokerWellKnownTest.php` (new).
- When `mcpwp_broker_enrollment` is set, serve `{ resource:<site url>, authorization_servers:["https://mumcp.com"] }` and gate serving on enrollment (independent of the legacy `oauth_enabled` setting — if broker-enrolled, serve regardless; if only legacy AS enabled, keep prior behavior).
- Tests: enrolled → RFC 9728 doc names mumcp.com; not enrolled + legacy off → prior behavior (404/legacy); the `resource` equals the site's canonical URL.

### Task 2: Enrollment endpoints — `/broker/enroll-callback` + `/broker/enroll-confirm`
**Files:** new `mcpwp/includes/api/class-mcpwp-rest-broker.php` (REST controller); register in the plugin's route registration; test `mcpwp/tests/BrokerEnrollTest.php` (new).
- `GET/POST /wp-json/mcpwp/v1/broker/enroll-callback` — **`permission_callback` = `current_user_can('manage_options')`**. Reads `nonce` + `return`; stores the nonce as a PENDING enrollment (transient `mcpwp_broker_pending_<nonce>`, TTL 300, single-use marker); redirects the browser to the validated `return` URL (must be `https://mumcp.com/...` — reject other hosts).
- `POST /wp-json/mcpwp/v1/broker/enroll-confirm` — **public** `permission_callback` (the broker calls it server-to-server, no WP auth), body `{nonce}`. Returns `200 {confirmed:true}` ONLY if a pending nonce transient exists; **delete it (single-use)** before responding; else `{confirmed:false}` (or 404). Constant-time nonce handling not required (transient key lookup), but the pending marker MUST be single-use and admin-created.
- Tests: callback without admin → 401/403, no pending nonce stored; callback with admin → stores pending + redirects only to mumcp.com (reject `return=https://evil.com`); confirm with a pending nonce → `{confirmed:true}` + nonce consumed; confirm again → `{confirmed:false}`; confirm unknown nonce → `{confirmed:false}`.

### Task 3: Cached introspection validator — `Mcpwp_Broker_Auth`
**Files:** new `mcpwp/includes/class-mcpwp-broker-auth.php`; test `mcpwp/tests/BrokerAuthTest.php` (new).
- `is_enrolled(): bool` — true iff `mcpwp_broker_enrollment` has `{site_id, site_credential(enc), audience}`.
- `validate_token(string $token): array|WP_Error` —
  1. Kill-switch: `!is_enrolled()` → `WP_Error('broker_not_enrolled', 401)`, NO network.
  2. Cache: `get_transient('mcpwp_bt_'.hash('sha256',$token))` → if hit, return cached `{scope, sub, site_id, aud, exp}`.
  3. Miss: `wp_remote_post('https://mumcp.com/oauth/introspect', [Authorization: Basic base64(site_id:site_credential), body: token=...])`. Decrypt the stored `site_credential` for the header.
  4. Fail-closed: WP_Error / non-200 / not-JSON / `active!==true` → `WP_Error(...,401)` (do NOT cache).
  5. Audience: reject unless `resp.aud === audience` AND `resp.site_id === site_id` → `WP_Error('broker_audience_mismatch',403)`.
  6. Cache positive: `set_transient('mcpwp_bt_'.hash(...), $resp, min(60, exp-time()))`.
  7. Return `{scope:['read'|'write'], sub, site_id, aud, exp}`.
- `scope_to_capability(array $scope): string` — `['write']`→write, else read. Never admin.
- Tests (mock `wp_remote_post` via bootstrap stub): not-enrolled → error no network; active token → returns scope + caches (second call makes NO network call — assert); `active:false` → 401; broker 500 / WP_Error → 401 fail-closed; `aud` mismatch → 403; cache respects TTL; scope maps write/read; `admin` in scope (shouldn't happen) never yields admin cap.

### Task 4: Auth integration — Bearer branch in `verify_api_key()`
**Files:** modify `mcpwp/includes/traits/trait-mcpwp-api-auth.php` (`verify_api_key()`, at the hook point after `looks_like_oauth_access_token()` ~line 171, before `find_scoped_api_key()`); test `mcpwp/tests/BrokerAuthIntegrationTest.php` (new).
- If the request has `Authorization: Bearer <token>` (and no `X-API-Key`), and `Mcpwp_Broker_Auth::is_enrolled()`, call `validate_token()`. On success, construct the SAME `$requester`/scope context an API key produces (a synthetic requester with scope read|write mapped from the token; `requester_can_see_non_public()` == write; **admin capability false**). On failure, return the WP_Error (fail-closed). If not enrolled or no Bearer → fall through untouched to the existing key path (kill-switch: no network when not enrolled).
- Tests: valid write token → request authenticates with write scope (can see non-public); valid read token → read scope (cannot see non-public — ties into the #683 model); no enrollment + Bearer → falls through / 401, no network; a broker token can NEVER perform an admin-scoped action (assert an admin-only endpoint rejects a broker token); X-API-Key still works unchanged.

### Task 5: Enrollment initiation + Connections admin UI + Disconnect
**Files:** modify `mcpwp/includes/admin/class-mcpwp-integrations-admin.php` (or the setup partial `mcpwp/admin/partials/mcpwp-setup-display.php:491-501` "Coming soon" card) to add a real "Connect to mumcp.com" button + Disconnect; an `admin_post_` handler that redirects the browser to `https://mumcp.com/enroll/start?site_url=<home_url>`; the landing-back handler storing the returned enrollment payload (encrypt `site_credential`) into `mcpwp_broker_enrollment`; a Disconnect handler that clears `mcpwp_broker_enrollment` (local kill-switch) and optionally calls the broker `/enroll/disconnect`. Reuse the Figma admin-post OAuth redirect pattern.
- Tests (where unit-testable): storing the payload encrypts the credential + sets enrollment; Disconnect clears it; the connect button targets the correct broker URL with the site's home_url.
- (UI rendering is admin-side; keep logic in testable methods.)

### Task 6: Version bump 3.7.0 + changelog + build
**Files:** `mcpwp/mcpwp.php`, `mcpwp/readme.txt`, `version.json`, `mcpwp/VERSION`.
- Bump to 3.7.0; changelog entry describing broker OAuth connect (claude.ai/ChatGPT sign-in via mumcp.com). Run `bash mcpwp/scripts/build-all.sh` to confirm it builds. Do NOT release to Freemius/R2 in this plan — leave as a reviewed PR for human ship.

## Self-Review
- Coverage: well-known pointer (T1), enrollment handshake incl. the broker's confirm dependency (T2), introspection validator with fail-closed + audience + cache + kill-switch (T3), auth integration with admin-never-via-broker (T4), admin connect/disconnect UI (T5), version (T6).
- The security-critical items (T2 admin-gating of confirm, T3 fail-closed + audience, T4 admin exclusion) get adversarial review at the end (external-facing auth surface).
- Deferred: fail-open outage mode (we chose fail-closed); multi-site agency console (#553).
