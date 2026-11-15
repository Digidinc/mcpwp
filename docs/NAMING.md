# MCPWP Naming Reference

Canonical brand and code name: **MCPWP** (mcpwp.net).

As of **v3.0.0** (2026-06-09) the plugin was hard-renamed. There is **one** name everywhere —
no `spai_` / `site-pilot-ai` / `mumcp` / `mumega-mcp` aliases, no backward-compat dual naming.
Those prefixes are dead in new code.

**Exception — the migration bridge (v3.0+, #505).** Once the bridge shipped to migrate
existing 2.8.56 `site-pilot-ai` installs, a small, deliberate set of `spai_*` reads became
**intentional backward-compat** and must NOT be "fixed":
- `class-mcpwp-migrate.php` — reads the old `spai_*` options (the `OPTION_MAP` source side).
- `class-mcpwp-license.php` — reads the **un-forgeable** `spai_pro_license` / `spai_trial_started`
  originals (entitlement source of truth — see [[feedback-entitlement-unforgeable-source]]).
- `trait-mcpwp-api-auth.php` — recognizes the legacy `spai_at_` OAuth token prefix +
  `find_legacy_spai_key()`.

These are the ONLY allowed `spai_` references in live code. Outside them, a `spai_`/`site-pilot-ai`
in live plugin code is still a bug — fix it. Wire contracts `X-SPAI-*` headers and the `x_spai`
MCP envelope key are **dual-emitted** alongside `X-MCPWP-*` / `x_mcpwp` for external-subscriber
backward-compat (retire in a future major).

## Canonical values (the only correct forms)

| Surface | Value |
|---------|-------|
| Plugin folder + main file | `mcpwp/` · `mcpwp.php` |
| Plugin Name header | `MCPWP` |
| PHP classes | `Mcpwp_*` |
| Functions / hooks / option keys | `mcpwp_*` |
| PHP constants | `MCPWP_*` (e.g. `MCPWP_VERSION`) |
| `@package` docblock | `MCPWP` |
| REST namespace | `mcpwp/v1` |
| API key prefix | `mcpwp_` |
| Text domain | `mcpwp` |
| HTTP headers | `X-MCPWP-*` |
| npm package | `@mcpwp.net/mcpwp` |
| MCP registry id | `io.github.mumega848/mcpwp` |
| CLI binary | `mcpwp` |
| version.json `name` | `MCPWP` |
| README H1 | `MCPWP` |
| Freemius slug | `MCPWP` |

## No migration

User is the only operator and reinstalls fresh on the two sites (mcpwp.net, crophelp.ai).
No stored-option migration, no client-config compat shim, no deprecation window. The rebrand
was clean precisely because there was no external contract to preserve — only our own
Cloudflare worker and our own MCP client configs consume the surface, and both we control.

## Known stale references (documentation only — fix on sight)

These are docs/records, not live code. Some are *frozen historical records that should keep the
old names* — do not "fix" those.

| File | Status |
|------|--------|
| `docs/REBRAND-PLAN.md` | **keep** — documents the rename; old names are the subject matter |
| `docs/superpowers/plans/*` | **keep** — frozen plan records |
| `docs/website-gtm/**/backups/*.json` | **keep** — frozen Elementor snapshots |
| `docs/V3_PLAN.md`, `docs/COMPATIBILITY.md`, `docs/KNOWN_ISSUES.md` | update or archive (pre-rebrand) |
| `docs/blog-launch-post.md` | update to MCPWP + paid copy |
| `mcp-server/src/{index,config,setup}.ts` | live code — config paths `~/.mumega-mcp` / `~/.wp-ai-operator` → `~/.mcpwp` (separate npm artifact; own change) |
| `SECURITY.md`, `CONTRIBUTING.md` | contributor-facing — update when convenient |
| `.github/PULL_REQUEST_TEMPLATE.md` | dev checklist — update when convenient |

## Cross-repo / infra renames

Done 2026-06-11 (spai/mumega → mcpwp infra pass):

| Item | Status |
|------|--------|
| `spai-proxy-worker/` → `Digidinc/mcpwp-cloud` | **done** — extracted out of the public GPL repo into private cloud repo |
| worker `spai-screenshot` → `mcpwp-screenshot` | **done** — redeployed, AUTH_TOKEN re-set, mcpwp.net integration re-pointed, old deleted |
| dir `spai-updates-worker/` → `mcpwp-updates-worker/` (sitepilotai repo) | **done** |
| dir `spai-gh-webhook/` → `mcpwp-gh-webhook/` (sitepilotai repo, not deployed) | **done** |

Still pending (needs operator DNS action):

| Item | Why |
|------|-----|
| worker `spai-updates` → `mcpwp-updates` + channel → `updates.mcpwp.net` | live update channel; staged dual-serve migration, needs Custom Domain `updates.mcpwp.net` + per-site `mcpwp_version_url` repoint. Legacy `mumega.com/mc-updates` + `/spai-updates/` kept until all sites cut over |
| repo/dir `wp-ai-operator` → `mcpwp` | filesystem rename; breaks active worktrees mid-session |
