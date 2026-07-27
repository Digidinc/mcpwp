# mumega-mcp 3.4.13 — Security & Stability Audit + Change Plan

Date: 2026-07-18. Trigger: WordPress.org review round 4 (Review ID
R mumega-mcp/mumega/24Feb26/T11 18Jul26/4.2A1). Audit method: 27-agent workflow
(5 audit dimensions, every finding adversarially verified against the exact
uploaded 3.4.13 zip). 17 findings confirmed, 5 refuted.

Target branch for fixes: `fix/wporg-approval-minimal` → next upload **3.4.14**.
Column "Main?" = probably also present in the full plugin (main branch); verify
and backport separately.

---

## 1. The two reviewer findings — resolution

### Finding A: "dead require of wp-admin/includes/media.php" (media.php:82, :280)

Reviewer is right that no media.php function is called *directly* — and wrong
that the require is removable. Adversarial verification found it is
**transitively load-bearing**: both upload paths call
`wp_generate_attachment_metadata()` (image.php), which for video/audio
attachments calls `wp_read_video_metadata()` / `wp_read_audio_metadata()` —
defined only in `wp-admin/includes/media.php` (unguarded in image.php:590/593).
The media validation is a denylist, so mp4/mp3 uploads are reachable. Deleting
the require = fatal error on audio/video upload.

**Chosen fix (satisfies the reviewer's "use a function from that file
immediately" exception AND simplifies code):** refactor the two methods to use
the canonical media.php entry points instead of the manual
`wp_handle_upload` + `wp_insert_attachment` + metadata assembly:

- `upload_file()` → keep the custom pre-validations, then delegate to
  `media_handle_upload()` (media.php).
- `upload_from_base64()` → materialize temp file (keep validations), then
  delegate to `media_handle_sideload()` (media.php).
- `upload_from_url()` already uses `media_handle_sideload()` — untouched.

media.php is then loaded and immediately used in every path that requires it.

### Finding B: "missing permission_callback" on 11 routes

**Static-analysis false positive, but a real approval blocker.** Every one of
the 19 `register_rest_route()` call sites gets a real
`'permission_callback' => array( $this, $permission )` from the
`route_definition()` helper (class-mcpwp-rest-api.php:388); all 18 referenced
`check_*` method names verified to resolve to real public methods. But the
literal key appears exactly once in the whole plugin — inside the helper — so
Plugin Check / reviewer grep reports every call site as missing it.

**Chosen fix:** make the key statically visible at each call site without
letting inline and helper values drift:

```php
$definition = $this->route_definition( ... );
register_rest_route( $this->namespace, '/pages', array(
    'methods'             => $definition['methods'],
    'callback'            => $definition['callback'],
    'permission_callback' => $definition['permission_callback'],
    // ...
) );
```

or simpler: `route_definition()` keeps building everything, and each call site
overwrites with an explicit literal:
`array_merge( $def, array( 'permission_callback' => array( $this, 'check_x_permission' ) ) )`.
Decision at implementation time; requirement is a literal
`'permission_callback' =>` at every `register_rest_route()` call site.

---

## 2. Confirmed audit findings — fix in 3.4.14 upload

| # | Sev | Finding | File | Fix | Main? |
|---|-----|---------|------|-----|-------|
| 1 | P1 | permission_callback invisible to static analysis (Finding B) | class-mcpwp-rest-api.php:388 | literal key per call site (§1B) | no (main has no route_definition) |
| 2 | P2 | media.php require + manual attachment path (Finding A) | class-mcpwp-media.php:82,280 | delegate to media_handle_upload/sideload (§1A) | check |
| 3 | P2 | **Pre-auth enumeration oracle**: permission callbacks run `get_post()` existence/type/status checks BEFORE `verify_api_key()`; anonymous caller gets 404 vs 401 → enumerate valid IDs, distinguish post/page/attachment, detect drafts | class-mcpwp-rest-api.php:483, 360, 206 | auth first, resource checks after | **yes — verify & backport** |
| 4 | P3 | `mcpwp_api_agent` role granted `mcpwp_manage_settings` → admin routes gated by scope only for agent-bound keys | class-mcpwp-activator.php:35 | drop cap from agent role | **yes — verify** |
| 5 | P3 | Legacy `spai_*` plaintext keys accepted with implicit full scope | trait-mcpwp-api-auth.php:122 | drop spai_* lookup from wp.org build entirely (no wp.org install has them) | main keeps migration, but re-hash on sight |
| 6 | P3 | `api_not_configured` returned as HTTP 500 — discloses key-store state pre-auth, misuses 5xx | trait-mcpwp-api-auth.php:52 | generic 401 invalid_api_key either way | **yes — verify** |
| 7 | P3 | `wp_set_current_user()` elevates before scope check passes | trait-mcpwp-api-auth.php:70 | scope check before user elevation | **yes — verify** |
| 8 | P3 | Dead generic `check_permission()` router with substring route matching | class-mcpwp-rest-api.php:33 | delete from wp.org build | check |
| 9 | P3 | Rate limiter trusts spoofable CF/XFF/X-Real-IP headers, no trusted-proxy validation | class-mcpwp-rate-limiter.php:297 | default REMOTE_ADDR; forwarded headers behind explicit trusted-proxy setting | **yes — verify** |
| 10 | P3 | Legacy plaintext keys persist as plaintext until first successful use | trait-mcpwp-api-auth.php:126 | re-hash at migration time, not first-use | main (wp.org build drops legacy path per #5) |
| 11 | P3 | `file_put_contents` return unchecked in base64 upload → truncated file proceeds | class-mcpwp-media.php:354 | check written bytes, delete + error on mismatch (may vanish with §1A refactor) | check |
| 12 | P3 | i18n `plugins_loaded` hook registration never executes (dead code) | class-mcpwp-loader.php:37 | remove (wp.org language packs auto-load since WP 4.6) | check |
| 13 | P3 | `Mcpwp_Migrate::run()` forces current-user determination on every request pre-init | class-mcpwp-migrate.php:20 | gate to admin/REST contexts; `(int) $owner_id > 0` not `is_int()` | wp.org-specific |
| 14 | P3 | Burst-limit default mismatch: limiter falls back to 30, activator/UI say 10 | class-mcpwp-rate-limiter.php:65 | single source of truth (10) | **yes — verify** |
| 15 | P3 | GET /rate-limit status shows base limits while GET enforcement uses 2x → impossible "used 15 / limit 10" display | class-mcpwp-rest-rate-limit.php:60 | report effective method-aware limits | **yes — verify** |
| 16 | P3 | Draft/update permission callbacks 404-before-401 (same class as #3) | class-mcpwp-rest-api.php:203 | folded into #3 fix | yes (with #3) |
| 17 | P3 | Reviewer flag documentation duplicate of #1 | — | folded into #1 | — |

## 3. Refuted findings (adversarial pass killed these — do NOT act on them)

- "Remove media.php require entirely" — transitively load-bearing (see §1A).
  This includes the reviewer's literal suggestion.
- "Tested up to: 7.0 exceeds stable" — WP 7.0 stable since 2026-05-20; correct.
- "spai_* prefix reads are a violation" — read-only migration compat, hash-verified,
  documented in code; only the wp.org-build removal (#5) is worth doing, for review
  optics not security.
- "textdomain notice on upgrade path" — gettext call is inside
  `if ( ! get_role(...) )`, role persists in wp_user_roles; branch never runs on
  upgrade.

## 4. Reviewer reply plan (with the 3.4.14 upload)

1. State both flagged issues addressed: media paths now use
   `media_handle_upload()` / `media_handle_sideload()` immediately after the
   require (their documented exception); every `register_rest_route()` call site
   now carries a literal `permission_callback`.
2. One line on the auth model: all routes authenticated via hashed-API-key
   verification with per-key scopes; intentionally-public routes: none.
3. Do NOT enumerate the 15 self-found fixes (their checklist explicitly asks not
   to list all changes) — changelog covers it.
4. Run Plugin Check + clean-install WP_DEBUG pass before upload (their checklist),
   using the A2-style smoke gate.

## 5. Sequencing

1. Branch `fix/wporg-approval-minimal`: implement §2 (#1–#16), bump 3.4.14,
   Plugin Check + phpunit + WP_DEBUG smoke in docker rig.
2. Upload to wp.org, reply per §4.
3. Separately on `main`: verify the "Main?" column items (esp. #3 enumeration
   oracle, #4 role caps, #6, #7, #9, #14, #15) and fix in next 3.6.x patch —
   these are live-production concerns independent of wp.org.
4. Feed A2 gate list (ROADMAP-4.0 Workstream A): this audit's classes of bug
   (pre-auth oracles, default mismatches, dead hooks) become standing checks.
