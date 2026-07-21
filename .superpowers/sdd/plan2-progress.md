# mumcp Broker Plugin (Plan 2) — SDD Progress Ledger
Repo: Mumega-com/mcpwp (wp-ai-operator) · branch feat/mumcp-broker-plugin (from main 3.6.4)
Plan: docs/superpowers/plans/2026-07-20-mumcp-broker-plugin-plan2.md
Grounding: scratchpad/plan2-grounding.md
Baseline: 502 phpunit tests. Test cmd: cd mcpwp && docker run --rm -v "$(pwd):/app" -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml

## Tasks
- [x] T1: .well-known → mumcp.com when enrolled (commit 0147df0, suite green). NOTE→T4: add_www_authenticate_header() still gates on legacy oauth_enabled only — extend to fire the 401 Bearer discovery header when broker-enrolled.

- [x] T3: cached introspection validator Mcpwp_Broker_Auth (commit 5b526a7, 532 tests, fail-closed/audience/kill-switch all TDD-covered)

(see REMAINING below)


## Notes
- Hook: verify_api_key() after looks_like_oauth_access_token() (~line 171), before find_scoped_api_key().
- Broker live at mumcp.com (deployed 2026-07-20 v5b6a865d). Introspection Basic auth base64(site_id:site_credential).
- Doc drift: mcpwp_bypass_api_key_check filter is DEAD; class-mcpwp-rest-site.php split into site-info + siblings.

## Log

## T3 result
- [x] T3: Mcpwp_Broker_Auth (commit 5b526a7). New file `includes/class-mcpwp-broker-auth.php`, tests `tests/BrokerAuthTest.php` (17 tests, 56 assertions), required from `mcpwp.php` alongside the broker enrollment endpoints. Full suite: 532 tests / 1857 assertions / 1 skipped, OK (baseline 515 + 17 new).
- Kill-switch verified with an ArrayObject call-count assertion on the `pre_http_request` mock — 0 calls when unenrolled or enrollment array is empty.
- Fail-closed covered for: WP_Error transport failure, HTTP 500, `active:false`, malformed JSON body — all 401, none cached.
- Audience/site_id mismatch → 403, not cached (an active-but-foreign token must not pass).
- Positive cache: keyed `mcpwp_bt_<sha256(token)>`, TTL = `max(1, min(60, exp-now))`; verified cache-hit skips the second network call, TTL never exceeds 60s even with a far-future `exp`, and never goes below 1s with a past/near `exp`.
- `scope_to_capability()` maps only `write`→write, everything else (including a scope array containing `admin`)→read.
- NOTE→T4: `validate_token()` returns a plain associative array (`scope`/`sub`/`site_id`/`aud`/`exp`), not the `$key_record` shape (`id`/`type`/`scopes`) used elsewhere in `trait-mcpwp-api-auth.php`. T4's Bearer branch must translate: mint `id` as `'broker:' . $result['sub']` (or hash of sub) for the self-approval identity invariant, `type => 'broker'`, `scopes => sanitize via scope_to_capability()` then expand to the read/write pair consistent with `key_has_scope()`'s existing normalization (write implies read).

## T2 result
- [x] T2: enrollment endpoints (commit dc171b5, 515 tests). Admin-gate + single-use + return-allowlist CONFIRMED correct (opus adversarial).
- **REQUIRED PRE-MERGE (CSRF, cross-cutting) — enrollment takeover:** attacker's broker session + CSRF'd victim admin hitting /enroll-callback binds victim's site to attacker's account. FIX = plugin-generated single-use `state`: Task5 initiation mints `state` (transient) → passes to broker → broker echoes `state` into the enroll-callback redirect (BROKER PATCH + REDEPLOY needed) → Task2 callback verifies `state` (else 403). Implement with Task 5 + broker patch.
- MINOR→fix with CSRF batch: tighten nonce regex to `{8,150}` (transient-key 191-char truncation). enroll-confirm public log-write (rate-limit/sample if abused).

## T3 result
- [x] T3: Mcpwp_Broker_Auth validator (commit 5b526a7, 532 tests). All 6 security props CONFIRMED (kill-switch no-network, fail-closed all paths, audience+site_id binding, positive-only cache, credential safety, no-admin). Reviewer re-ran 17 tests.
- MINOR→fix batch: fold site_id|audience into cache key (`sha256(token.'|'.site_id.'|'.audience)`) so re-enroll mid-window invalidates cached positives. Note: broker must return site_id/aud as strings (strict ===).
- T4 must translate validate_token() array → key_record shape (id='broker:'.sub for #683 self-approval identity invariant).

## T4 result
- [x] T4: Bearer auth integration (commits 2355944 + 8396f62, 549 tests). All invariants CONFIRMED (admin-impossible, shape-parity, #683 identity, read/write visibility, kill-switch, fail-closed). FIXED: local key resolves BEFORE broker introspection (no API-key secret egress to broker; Bearer-header key auth preserved) + IP rate-limit before introspection (anti-DoS) + aligned is_enrolled predicates. 401 WWW-Authenticate discovery header now fires when broker-enrolled.

## REMAINING (T5, T6) + open design decision
(see REMAINING below)
  - CSRF fix (from T2 review): plugin mints single-use `state` at initiation → broker must echo `state` into the enroll-callback redirect (BROKER PATCH + REDEPLOY) → callback verifies `state`.
  - OPEN DESIGN: how the enrollment payload (esp. site_credential) reaches the plugin after broker /enroll/complete. Broker currently MINTS the credential + returns it to the browser. Secure options: (a) broker adds a final S2S POST `<site>/wp-json/mcpwp/v1/broker/enroll-store {nonce,site_id,audience,site_credential}` after mint (credential never in browser) — RECOMMENDED; (b) plugin generates its own credential + sends hash to broker at enroll (broker change: store-hash-not-mint). Both need a broker patch + redeploy.

- Batched minors: T2 nonce regex {8,150}; T3 cache key fold site_id|audience.
- Then: final adversarial+correctness whole-branch review; PR (do NOT auto-release).

## T5 result
- [x] T5: enrollment lifecycle (commits fd13941 + 38d8f4c, 573 tests). CSRF `state` gate, confirm→store state machine, enroll-store credential receiver (encrypted, log-leak fixed), Disconnect kill-switch, Connections UI. All load-bearing props CONFIRMED. FIXED: host-lock enroll-store issuer/introspection_endpoint to mumcp.com (credential-exfil poisoning). Batched minors done (nonce {8,150}, cache key fold).
- Broker patch built (mumcp-broker 1eca586, 110 tests): state relay + enroll-store S2S push + rollback. NOT YET DEPLOYED (plugin is hard dep — deploy broker only when plugin ships).

## FINAL (2026-07-21)
- [x] T6: bump 3.7.0 (4 files) + changelog.
- Final adversarial gate (opus): CLEAR-AFTER → all fixes landed (commit 274d209): enroll-callback public+state-gated (fixed broken happy path — REST cookie-nonce 403), audience pinned to home_url(), introspection_endpoint pinned to full URL, no public log-amplification.
- 580 phpunit tests green. All core security props CONFIRMED (admin-never-via-broker, fail-closed, kill-switch, credential-at-rest, CSRF state, host-locks).
- Broker patch (mumcp-broker 1eca586): DEPLOY coordinated with plugin.
