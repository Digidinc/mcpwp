# Cutover Runbook — site-pilot-ai → MCPWP (issue #505)

> **Turnkey checklist for the per-site manual cutover of the ~10 customer fleet.**
> Prereqs before ANY site: PR #515 merged + Athena correctness gate GREEN + Hadi go.
> This runbook is the *only* sanctioned cutover procedure. Do NOT `wp_trigger_update`
> across the slug. The update channel stays pinned at 2.8.56 throughout — cutover is
> manual per site, never a fleet-wide channel flip.

## Why manual (the slug-trap, one paragraph)
Old plugin = folder `site-pilot-ai/`, basename `site-pilot-ai/site-pilot-ai.php`, options `spai_*`. New = folder `mcpwp/`, basename `mcpwp/mcpwp.php`, options `mcpwp_*`. An in-place WP update across that folder rename **deactivates the plugin** (WP keys the active plugin by the now-vanished basename) and orphans `spai_*` data → MCP/REST dead, no remote recovery. The bridge (PR #515) makes the data/auth/entitlement survive; this runbook does the activation safely.

## Handover strategy: Option C (validated, fatal-safe)
Activate `mcpwp/` **alongside** the still-active `site-pilot-ai/`. The migration auto-fires on `plugins_loaded` (before any REST teardown), copying all `spai_*` → `mcpwp_*`. Old `spai_` API keys + `spai_at_` OAuth tokens keep authenticating during the overlap window. Then deactivate the old plugin. Co-existence verified: no class/constant/REST-namespace collision (`Spai_*`/`site-pilot-ai/v1` vs `Mcpwp_*`/`mcpwp/v1`).

## Order of operations (fleet sequencing)
1. **Dogfood first:** `mcpwp.net`, then `crophelp.ai`. Watch for 24h.
2. **Then the fleet**, lowest-traffic / most-forgiving customer first, one at a time. Never batch.
3. A site only proceeds once the prior site is verified GREEN at every step below.

---

## PER-SITE PROCEDURE

### 0. Pre-flight (read-only — abort if any fails)
- [ ] Confirm current state: `wp plugin list | grep site-pilot-ai` → active, 2.8.56.
- [ ] **Full backup** (DB + files). Non-negotiable. This is the rollback floor.
- [ ] Note the connection method: does this site use **OAuth** (Claude Desktop custom connector / ChatGPT) or **API key**? If OAuth, see §5 — token TTL means re-auth may be needed.
- [ ] Confirm `AUTH_SALT` is defined in `wp-config.php` (`wp config get AUTH_SALT`). If undefined, the encrypted integrations blob won't decrypt post-migration — plan to re-enter provider keys (§4).
- [ ] Confirm Pro entitlement source: is this site Freemius-paying, or was it on a **local** 2.8.x license/trial? (The bridge carries local entitlement forward, but verify the customer keeps Pro post-cutover in §4.)
- [ ] Record the current `spai_` API key(s) the customer's AI client uses (you'll confirm they still work in §3).

### 1. Stage the new plugin (no activation yet)
- [ ] Upload the **MCPWP v3** plugin folder `mcpwp/` to `wp-content/plugins/` (via SFTP or wp-admin "Add Plugin → Upload" — but **do not activate via the updater that deletes the old folder**). Both `site-pilot-ai/` and `mcpwp/` now exist on disk; only `site-pilot-ai/` is active.

### 2. Activate MCPWP (migration auto-fires)
- [ ] WP Admin → Plugins → activate **MCPWP** (`mcpwp/mcpwp.php`). Both plugins now active (the safe overlap window).
- [ ] Immediately check the **migration log**: `wp option get mcpwp_migration_log --format=json`.
  - [ ] All expected options show in `copied`.
  - [ ] `tables` → every table `status: migrated` (no `error`). If any `error`: STOP, do not deactivate the old plugin, check `wp option get mcpwp_migration_incomplete`, resolve, the migration retries on next request.
  - [ ] `encryption_warning` is `false`. If `true` → provider keys need re-entry (§4).
- [ ] Confirm `wp option get mcpwp_migrated_from_spai` = `1`.

### 3. Verify BEFORE deactivating the old plugin
- [ ] Front page loads (HTTP 200), no PHP fatal (check `debug.log` / server error log).
- [ ] New endpoint answers with the **customer's existing key**: `curl https://SITE/wp-json/mcpwp/v1/site-info -H "X-API-Key: <their spai_ key>"` → 200.
- [ ] A garbage key → 401 (sanity).
- [ ] If OAuth: the customer's live `spai_at_` token hits `mcpwp/v1/site-info` → 200 (only within the token's TTL; see §5).
- [ ] Spot-check migrated data: `wp option get mcpwp_site_context`, webhooks (`wp db query "SELECT COUNT(*) FROM {prefix}mcpwp_webhooks"`), white-label, approvals — match the old values.

### 4. Entitlement + integrations check
- [ ] Pro features available? `wp eval 'echo Mcpwp_License::get_instance()->is_pro() ? "PRO" : "FREE";'` — must match the customer's prior entitlement. (Bridge fallback honors a migrated valid local license/trial when Freemius isn't paying.)
- [ ] Integrations decrypt: WP Admin → MCPWP → Integrations → each configured provider reads back / test-connection passes. If `encryption_warning` was true or `AUTH_SALT` was undefined → re-enter the provider API keys here.

### 5. OAuth note (only if the site uses OAuth)
- [ ] `wp option get mcpwp_settings --format=json` → `oauth_enabled: true`, or no `oauth_enabled` key at all (OAuth is on whenever the setting was never stored). If it reads false, OAuth was switched off on the old site — re-enable under Setup → Advanced.
- [ ] Migrated `spai_at_` tokens authenticate only within their original TTL (default 1h, up to 24h). For a cutover that outlasts the TTL, tell the customer to **re-connect** their Claude Desktop / ChatGPT OAuth connection after cutover.

### 6. Deactivate the old plugin
- [ ] WP Admin → Plugins → **deactivate** `site-pilot-ai` (do not delete yet — keep it as the rollback path).
- [ ] Re-verify: front page 200; `mcpwp/v1/site-info` 200 with the customer key; an MCP `tools/call` round-trips (`/mcpwp/v1/mcp`).
- [ ] Confirm `spai/v1` (actually `site-pilot-ai/v1`) namespace is gone and `mcpwp/v1` is the live surface.

### 7. Clean up channel state (so future updates work)
- [ ] Ensure no stale override blocks v3 updates: `wp option get mcpwp_update_info` should be empty; `wp option update mcpwp_version_url https://mumega.com/mcp-updates/version.json`.
- [ ] Leave the legacy `spai_*` options and `site-pilot-ai/` folder in place for the rollback window (see §9). Do **not** run an option-cleanup script while any customer key still depends on the legacy store (dual-key reads it; the bridge also appended keys into `mcpwp_api_keys`, but keep the source until the window closes).

### 8. Post-cutover monitoring
- [ ] Watch the site for 24h: error log, customer's AI connection, webhook deliveries firing again.
- [ ] PostHog: first `mcp_tool_called` from the site on v3 confirms the live path.

### 9. Rollback (if anything in §3–§6 fails)
Because the procedure is non-destructive, rollback is clean:
1. Re-activate `site-pilot-ai` (still on disk, 2.8.56, data intact in `spai_*`).
2. Deactivate + delete `mcpwp/` and its `mcpwp_*` options/tables (they were copies; the `spai_*` originals are untouched).
3. The site is exactly as before. Diagnose on the rig, not in production.
Keep the old plugin + `spai_*` data for **at least 2 weeks** after a successful cutover before any cleanup.

---

## Fleet tracking
| Site | Backup | Activated | Migration log clean | Verified | Old deactivated | 24h watch |
|---|---|---|---|---|---|---|
| mcpwp.net | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| crophelp.ai | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| (fleet site 3) | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| … | | | | | | |

> Add the remaining fleet rows from `~/.mumega/organisms/*.yaml`. Customer names stay OUT of the public repo — keep this table's real names in a private note, not in a committed copy.
