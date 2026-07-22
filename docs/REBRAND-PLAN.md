# Rebrand Game Plan — everything → MCPWP

> ⚠️ **SUPERSEDED (2026-07-22) by [`DECISIONS.md`](DECISIONS.md) ADR-001.** The "one name everywhere → MCPWP"
> direction below rested on *"there is NO external contract."* That premise expired: wp.org accepted the
> free plugin under the permanent slug `mumega-mcp`, creating a real external contract. Current direction:
> **mumcp** = the product brand (public surfaces only), **mcpwp** = the media site; the internal `mcpwp/v1`
> wire contract is **frozen, not re-churned.** Read ADR-001 before acting on anything below. The mechanical
> rename history here is still useful reference; the *goal* is not current.

> **Goal:** one name everywhere — **MCPWP**. Eliminate `site-pilot-ai`, `spai`/`Spai_`/`SPAI_`,
> `mumcp`, `mumega-mcp`, `MumegaMCP`, and the repo/dir name `wp-ai-operator`. Forcing function: the
> Freemius slug is now `MCPWP`.
> **KEY SIMPLIFIER (Hadi, 2026-06-09):** there is **NO external contract** — the only consumers of the REST/
> MCP surface are *our own* Cloudflare worker + our own sites + our own MCP configs, all of which we update.
> So **hard-rename everything** (REST namespace, API key prefix, hooks, options) and update our own
> consumers. No backward-compat aliasing needed.
> **Cardinal rule (revised):** rename freely; just (a) update our own consumers, and (b) don't lose our own
> sites' data on upgrade (or re-set them up — only 2 sites).
> **Last updated:** 2026-06-09. Status: PLAN (awaiting Hadi's naming + sequencing decisions).

---

## 0. Why now

Blast radius is at its minimum: **~2 sites we control** (mcpwp.net, crophelp) and **no paid install base**
(unlicensed). The plugin-folder rename + DB migration get harder with every new install. The Freemius
slug change (`MCPWP`) already forces it. Original roadmap had this at v5.0/M6 — pull it forward to **now**,
done safely. This ships as **v3.0.0** (a major bump signals the change and carries the migration).

---

## 1. Canonical names (DECISIONS NEEDED — recommendations in bold)

| Thing | From | To (recommended) |
|---|---|---|
| Plugin folder | `site-pilot-ai/` | **`mcpwp/`** |
| Main file | `site-pilot-ai.php` | **`mcpwp.php`** |
| Class prefix | `Spai_` | **`Mcpwp_`** |
| Function/hook/option prefix | `spai_` / `spa_fs_` | **`mcpwp_`** |
| Constant prefix | `SPAI_` | **`MCPWP_`** (`MUMCP_PRO` → `MCPWP_PRO`) |
| Text domain | `mumega-mcp` | **`mcpwp`** |
| `@package` | `MumegaMCP` | **`MCPWP`** |
| Freemius slug | `site-pilot-ai` | **`MCPWP`** (done in `freemius-init.php`) |
| REST namespace | `site-pilot-ai/v1` | **`mcpwp/v1`** primary, **keep `site-pilot-ai/v1` as a permanent alias** |
| API key prefix | `spai_` | **`mcpwp_`** for new keys, **keep accepting `spai_`** |
| DB tables | `{prefix}spai_*` | **`{prefix}mcpwp_*`** (migrate) |
| Options | `spai_*` | **`mcpwp_*`** (migrate, dual-read) |

---

## 2. How each surface is handled (simplified — no external contract)

### Internal identifiers — rename freely
Classes (117), functions, constants (10), `@package`, text domain (1,964), asset handles, admin slugs,
**hooks** (`spai_tool_called`, `spai_register_tools`, … — no third parties consume them), the **REST
namespace** (`site-pilot-ai/v1` → `mcpwp/v1`, one name, no alias), and the **API key prefix** (`spai_` →
`mcpwp_`). **Mechanical find-replace** across the plugin — *excluding* `freemius/` (the SDK) and `vendor/`.
Verified by the test suite + PHP lint.

### Our own consumers — update them in lockstep
The only things calling the renamed surface are ours:
- **The Cloudflare update/proxy worker** (`spai-updates-worker`) — update any `site-pilot-ai/v1` paths.
- **Our MCP client configs** — `~/.claude.json` (`mumega-mcp-mcpwp`), crophelp's connection → point at
  `mcpwp/v1`.
- **mcpwp.net + crophelp** API keys — regenerate as `mcpwp_…` (or migrate; see below).

### Our own sites' data — migrate OR re-set up (only 2 sites)
Two options, pick per-site at cutover:
- **Migrate** (cleaner, no manual work): idempotent upgrade routine copies `spai_*` options → `mcpwp_*`
  and `RENAME TABLE {prefix}spai_x TO {prefix}mcpwp_x`, guarded by `mcpwp_db_version`. Tables:
  `spai_activity_log`, `spai_action_log`, `spai_webhooks`, `spai_webhook_logs`, `spai_feedback`. Options:
  `spai_api_keys`, `spai_settings`, `spai_site_context`, `spai_integrations`, `spai_disabled_tool_categories`,
  `spai_rate_limit_settings`, `spai_site_uuid`, `spai_first_activation`, …
- **Re-set up** (simplest given 2 sites we control): fresh install of `mcpwp`, regenerate keys, re-apply the
  brand-crystal + integration keys. Trivial for 2 sites; zero migration risk.

Recommendation: **write the migration anyway** (it's the right thing for any future install + it's cheap),
but keep re-setup as the fallback. Migration is still the most test-critical piece — but the stakes drop
from "every external user locked out" to "our 2 sites, which we can re-onboard in minutes."

---

## 3. The hard one — plugin folder + slug + Freemius continuity

WordPress identifies a plugin by `folder/main-file.php`. Renaming `site-pilot-ai/site-pilot-ai.php` →
`mcpwp/mcpwp.php` makes WP treat it as a **new plugin** (the old one orphans). For a large install base this
is painful; **for our ~2 controlled sites it's trivial** — which is the whole argument for doing it now.

**Approach:**
- **New installs (wp.org/Freemius free, fresh):** clean `mcpwp/` from day one.
- **Our existing sites (mcpwp.net, crophelp):** the v3.0.0 upgrade runs the data migration (Tier C) so all
  options/tables move to `mcpwp_*` *before* the folder switch; then we install the `mcpwp` build and
  deactivate the old folder. Because the data already migrated, the new plugin reads it seamlessly. Done
  manually + verified on these two sites (no automation risk for a 2-site cutover).
- **Freemius:** slug `MCPWP` is set. Freemius delivers premium under the new slug; the free wp.org build is
  auto-generated (`wp_org_gatekeeper`). Confirm with Freemius that the product slug rename is registered so
  update delivery maps correctly.

---

## 4. Phased execution (within v3.0.0, on a branch)

1. **Freeze + branch** `feat/rebrand-mcpwp` from main.
2. **Tier A mechanical rename** — classes/funcs/constants/textdomain/package, excluding `freemius/`,
   `vendor/`. Rename class *files* (`class-spai-*.php` → `class-mcpwp-*.php`) + update includes/autoload.
3. **Tier B aliases** — dual REST namespace; dual-fire hooks; dual API-key-prefix acceptance.
4. **Tier C migration** — write the idempotent upgrade routine (options copy + table rename + db_version) +
   dual-read accessors. **Heaviest test coverage here.**
5. **Folder/main-file rename** → `mcpwp/mcpwp.php`; header (Name, Text Domain, slug); constants.
6. **Build scripts + distribution** — `build-freemius.sh`, `build-wporg.sh` (or retire it for Freemius
   auto-free), `release_freemius.sh`, `publish_update_release.sh`, zip names, `version.json` (already
   "MCPWP"), `readme.txt`.
7. **Docs + specs** — `CLAUDE.md`, `docs/*`, `openapi*.yaml`, `BACKLOG.md`, `STRATEGY.md`.
8. **Test gauntlet** — full PHPUnit; a dedicated **migration test** (seed `spai_*` data → upgrade → assert
   `mcpwp_*` present + values intact + old aliases still resolve); PHP lint all; manual smoke on a throwaway
   WP.
9. **Adversarial review** — auth/key-prefix + migration are sensitive surfaces → adversarial-gate.
10. **Roll out** — crophelp first (lower stakes), verify front-end + MCP + keys + tools; then mcpwp.net.

---

## 5. Cross-repo + repo/dir surface (beyond the plugin code)

The rebrand isn't only the plugin's identifiers:
- **Repo / working dir `wp-ai-operator`** → `mcpwp`. The GitHub repo is already `Mumega-com/mcpwp`; the
  local submodule/working-dir name `wp-ai-operator` and any path references go to `mcpwp`.
- **`Mumega-com/mumcp-claude-plugin`** — the Claude skills plugin (`mumcp:*` skills, marketplace.json) → `mcpwp`.
- **Update worker / R2** — `spai-updates-worker`, R2 bucket `mumcp-updates`, zip names. (Channel host stays mumega.com.)
- **Bus skills / connectors** — `mumcp` references in mupot packs + bus `register_skill`.
- **mcpwp.net content/courses** — already "MCPWP" branded; verify no "site-pilot-ai" leaks in public copy.

Separate PRs in their own repos; sequence after the plugin lands so the names line up.

---

## 5b. Microkernel — DON'T bundle it with the rebrand (recommended)

Hadi asked: while touching everything, also make the backend a microkernel / better-managed?

**Recommendation: rebrand first (mechanical), microkernel as the focused next project.** Why:
- The **rename is mechanical and safe** (find-replace + tests). A **microkernel is design + behavior
  change** (risk). Bundling them means a failure can't be isolated — you'd debug a redesign through the
  noise of a 117-class rename.
- The full microkernel (minimal core + everything-as-registered-module) is what makes the **marketplace
  clean** (addons register into a kernel). That deserves its own design pass (brainstorm → spec → plan),
  not a side-effect of a rename. It is still **M6** on the roadmap — now de-risked because the rebrand
  gives it a clean, consistently-named base to build on.
- **Capture the free wins during the rename, not the redesign:** while we touch every file we *do* fix
  fragile patterns (the conditional-class-at-load bug that just downed the site), standardize the
  tool-registry boundary, and impose a consistent module file layout. That sets up the microkernel without
  committing to it mid-rename.

**Sequence:** v3.0.0 rebrand (clean base) → then the microkernel project with proper design.

---

## 6. Execution method

Large + mechanical + correctness-critical → **subagent-driven** (Sonnet implementers, per-phase, with the
spec-then-quality review loop). The migration + alias phases get an **adversarial review** (sensitive
surface). Mechanical rename is scriptable (sed across plugin, excluding SDK) but **every external-contract
string is reviewed by hand** before commit. Nothing merges without the full test gauntlet green.

---

## 7. Rollback

- It's one branch + one major version. If the migration misbehaves in testing, fix forward on the branch.
- On the live cutover: the migration **copies** (doesn't destroy) `spai_*` first, so the old plugin still
  works if we revert the folder switch. Keep `spai_*` data for 1–2 versions before deleting.
- DB table rename is the only destructive step — take a DB snapshot of mcpwp.net/crophelp before cutover.

---

## 8. Decisions needed from Hadi

1. **Identifier casing:** `Mcpwp_` classes / `mcpwp_` funcs+opts / `MCPWP_` constants — OK? (or all-caps `MCPWP_` classes?)
2. **Version:** ship as **v3.0.0**? (major signals the rebrand.)
3. **Data at cutover:** migrate `spai_*` → `mcpwp_*` on upgrade, OR just re-set up our 2 sites fresh? (Recommend: write the migration, keep re-setup as fallback.)
4. **Retire self-hosted + wp.org manual builds** for Freemius free+premium (the earlier "only Freemius" call)? Confirms `build-selfhosted.sh`/`build-wporg.sh` go away.
5. **Freemius:** confirm dashboard product slug is `MCPWP` and update delivery is mapped (so it doesn't strand the 2.8.56 already deployed under `site-pilot-ai`).
6. **Microkernel:** agree to defer to its own project after the rebrand (§5b)?

RESOLVED: no external contract → REST namespace, hooks, API key prefix all **hard-rename** (no aliases).
Repo/dir `wp-ai-operator` → `mcpwp`.

Once 1–2 are set, I branch and execute §4 phase by phase.
