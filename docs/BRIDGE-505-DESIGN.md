# Bridge #505 — Slug Handover Design Note

**Status: UPDATED (round 2) — data migration hardened for ~10 live paying sites.  Handover
automation still requires architect + mumcp-warden review before shipping.**

**What this doc covers:** the one part of the #505 bridge that is NOT safe to improvise —
how to move the active-plugin record from `site-pilot-ai/site-pilot-ai.php` to
`mcpwp/mcpwp.php` without deactivating the plugin and killing REST/MCP on customer sites.

The data migration (`Mcpwp_Migrate`) and one-time credential adoption
(`Mcpwp_Activator::adopt_spai_credentials()`, #1098 / #1120) are already implemented and
tested — request auth no longer reads `spai_api_key` / `spai_api_keys` directly.  The slug
handover is the remaining piece.  It is deliberately NOT implemented here; this note
surfaces the options for review.

---

## The problem

WordPress identifies a plugin by its basename: `folder/main-file.php`.  It stores the active
list in the `active_plugins` option as an array of basenames.  When the folder is renamed,
`site-pilot-ai/site-pilot-ai.php` disappears from disk.  WordPress sees it missing on the
next load, silently removes it from the active list, and stops calling its hooks.  Result:
REST endpoints de-registered, auth gone, MCP dead.  No error is shown; the site just stops
responding to AI requests.

---

## Options

### Option A — Migration release in the OLD folder (recommended)

Ship a 2.8.57 release that still lives in `site-pilot-ai/` but:

1. Includes `Mcpwp_Migrate` (already written) and runs it on activation/plugins_loaded.
2. Installs a lightweight `upgrader_post_install` hook that, after any plugin install/update,
   detects if `mcpwp/mcpwp.php` is on disk and calls `activate_plugin( 'mcpwp/mcpwp.php' )`
   (and optionally `deactivate_plugins( 'site-pilot-ai/site-pilot-ai.php' )`).
3. Ships its own update (2.8.57) through the existing pinned channel so all 2.8.56 sites pull
   it automatically.

Then the operator (or a separate MCP call) installs the `mcpwp` folder alongside. The hook fires,
activates MCPWP, and the rename is live.  The 2.8.57 stub can then self-deactivate.

**Pros:** no manual WP admin action; works on all sites without SSH; migration runs before MCPWP
first activates so keys/data are already in place; the existing update channel delivers it.

**Cons:** requires shipping through the pinned channel — that is the two-person rule boundary
(see squad charter).  Needs warden sign-off.  Also requires the operator to push the `mcpwp`
folder to each site before triggering the handover.

### Option B — mu-plugin shim

Install a single-file mu-plugin (`wp-content/mu-plugins/mcpwp-bridge.php`) on each site that:
- Requires `mcpwp/mcpwp.php` directly if `site-pilot-ai/site-pilot-ai.php` is still active.
- Calls `deactivate_plugins('site-pilot-ai/site-pilot-ai.php')` and
  `activate_plugin('mcpwp/mcpwp.php')` once on the next request.

**Pros:** no update channel involvement; can be deployed via SSH/WP-CLI in seconds.

**Cons:** requires server access to each site; mu-plugins are not cleaned up automatically;
leaves a file artifact that could confuse future maintainers.  For 2 controlled sites this is
the fastest manual path.

### Option C — Manual WP admin re-activation

1. SSH/WP-CLI: upload the `mcpwp/` folder alongside the existing `site-pilot-ai/` folder.
2. Run migration: `wp eval 'Mcpwp_Migrate::run();'` (or activate MCPWP from admin — migration
   fires on `plugins_loaded` before the old plugin's routes are torn down).
3. Activate `mcpwp/mcpwp.php` from WP Admin > Plugins.
4. Deactivate `site-pilot-ai/site-pilot-ai.php`.

**Pros:** no code beyond what is already written; zero risk of self-bricking; fully auditable.

**Cons:** requires manual action on each site; brief window where both plugins are active
(mitigated: they use different namespaces and option prefixes, so co-existence is safe for
the few seconds between steps 3 and 4).

---

## Recommendation

The install base has grown to approximately 10 live paying sites (updated from the original
"≤ 2 controlled sites" premise in round 1).

**For now, continue using Option C (manual re-activation)** on a site-by-site basis.  At ~10
sites this is still practical (5–10 minutes per site via WP-CLI).  The migration routine
(`Mcpwp_Migrate`) is now hardened for this fleet size:
- All operational option keys are migrated (approval_requests, recent_events, site_memory,
  blueprints, white_label, signals, search_performance).
- DB tables (webhooks, webhook_logs, action_log, activity_log, feedback) are migrated
  row-by-row with schema parity checks.
- spai_api_keys are appended into mcpwp_api_keys without clobbering existing v3 keys.
- spai_at_ OAuth tokens continue to work via the legacy transient lookup.
- Both call sites are wrapped in try/catch — a migration error cannot fatal the site.

**Upgrade to Option A (automated channel delivery)** if the install base grows beyond
~15–20 sites.  That implementation must:
- Pass the two-person rule for shipping through the pinned 2.8.56 channel.
- Include integration tests on the M3 rig showing activation-→-handover-→-deactivation
  completes without REST downtime.
- Be reviewed by mumcp-warden for the auth surface (the `activate_plugin()` call is
  effectively a privilege escalation path if triggered by untrusted input).

---

## What was deliberately NOT implemented

- `upgrader_post_install` handover hook (Option A) — needs warden review + two-person rule.
- mu-plugin shim (Option B) — not needed given the site count; avoids leaving artifact files.
- Any code that auto-deactivates `site-pilot-ai/site-pilot-ai.php` from within MCPWP itself —
  this is a nuclear operation that can permanently disable the wrong plugin on a multisite.
  It must be an explicit, human-triggered action.

---

## Safe co-existence window

If both `site-pilot-ai/` and `mcpwp/` are active simultaneously (Option C step 3→4), they
are safe:
- Different REST namespaces (`site-pilot-ai/v1` vs `mcpwp/v1`).
- Different option prefixes (`spai_*` vs `mcpwp_*`).
- `Mcpwp_Migrate` is idempotent — running it while the old plugin is still active is safe.
- The dual-key auth in MCPWP will already accept `spai_` keys, so an AI client holding an
  old key can authenticate to either endpoint during the window.

Maximum recommended co-existence time: < 60 seconds (the time to click Deactivate in WP Admin).

---

*Drafted by mumcp-smith on feat/505-bridge. Requires architect + mumcp-warden review before
any handover automation code is written or any update-channel artifact is shipped.*
