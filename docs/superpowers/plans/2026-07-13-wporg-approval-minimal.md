# Mumega MCP WordPress.org Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a deliberately minimal, secure, network-silent WordPress.org directory edition whose exact artifact uses the assigned `mumega-mcp` identity and is ready for a reviewer to recheck.

**Architecture:** The `wporg` source branch is the physical source of the directory edition; prohibited code is deleted instead of hidden behind runtime constants. One deterministic builder packages that already-minimal source, while artifact-policy tests lock the identity, file allowlist, REST/tool inventory, outbound-host policy, and lifecycle behavior. Internal compatibility identifiers (`mcpwp_*`, `Mcpwp_*`, `MCPWP_*`, stored option names, and `mcpwp/v1`) remain stable unless a retained path cannot be secured without a migration.

**Tech Stack:** WordPress plugin PHP 7.4+, WordPress REST API, JSON-RPC MCP transport, PHPUnit 9, Bash, Docker-based WordPress/PHP matrix, PHPCS with WordPress Coding Standards, and the official Plugin Check plugin.

## Global Constraints

- Work only on `fix/wporg-approval-minimal`, based on `origin/wporg`; target a future PR to `wporg`, never `main`.
- Assigned slug and installed folder: `mumega-mcp`.
- ZIP root: `mumega-mcp/`.
- Main plugin file: `mumega-mcp.php`.
- Public plugin name and readme title: `Mumega MCP` and `=== Mumega MCP ===`.
- Text domain: literal `mumega-mcp` in the plugin header, every shipped gettext call, and `languages/mumega-mcp.pot`.
- Keep internal `mcpwp_*`, `Mcpwp_*`, `MCPWP_*`, stored option identifiers, API-key prefix, and `mcpwp/v1` REST namespace stable.
- Minimum WordPress version: 6.1.
- Minimum PHP version: 7.4; do not use PHP 8-only functions.
- New posts and pages default to `draft`; publishing and deletion require their exact WordPress capabilities.
- The directory edition has no telemetry and no fixed outbound service destination.
- Prohibited functionality must be absent from source and the ZIP, not runtime-disabled.
- No authenticated or sensitive REST route may use `permission_callback => '__return_true'`.
- No directory build may download, install, update, activate, or execute third-party packages.
- Human-readable source must ship in the ZIP or have a public, anonymously reachable source location.
- Do not bump the plugin version; the maintainer handles version bumps.
- Do not push, open a PR, upload to WordPress.org, or send the reviewer email without the user's explicit authorization for that external step.

---

### Task 1: Authoritative builder and exact `mumega-mcp` identity (#605)

**Files:**
- Modify: `mcpwp/scripts/build-wporg.sh`
- Create: `mcpwp/scripts/verify-wporg-artifact.sh`
- Replace with a thin wrapper or delete: `scripts/build-wporg.sh`
- Modify: `mcpwp/mcpwp.php`
- Modify: `mcpwp/readme.txt`
- Rename: `mcpwp/languages/mumcp.pot` to `mcpwp/languages/mumega-mcp.pot`
- Modify: shipped PHP files containing literal gettext domain `mumcp`
- Modify: `SUBMISSION.md`
- Create: `mcpwp/tests/WporgArtifactIdentityTest.php`

**Interfaces:**
- Consumes: the existing source plugin rooted at `mcpwp/` and its version header.
- Produces: `mcpwp/scripts/mumega-mcp-<version>.zip`, rooted at `mumega-mcp/`, plus `verify-wporg-artifact.sh <zip>` as the fail-closed artifact contract used by every later task.

- [ ] **Step 1: Write the failing identity test.**

  Make `WporgArtifactIdentityTest` build or inspect a supplied candidate ZIP and assert all of the following exact values: root `mumega-mcp/`; main file `mumega-mcp/mumega-mcp.php`; `Plugin Name: Mumega MCP`; `Text Domain: mumega-mcp`; readme header `=== Mumega MCP ===`; stable tag equals header/constant version; only `languages/mumega-mcp.pot` is accepted; no shipped PHP gettext domain equals `mumcp` or `mcpwp`.

- [ ] **Step 2: Run the focused test against the current builder and capture RED.**

  Run the environment-approved equivalent of:

  ```bash
  mcpwp/vendor/bin/phpunit --configuration mcpwp/tests/phpunit.xml --filter WporgArtifactIdentityTest
  ```

  Expected: failure naming current `mumcp` folder/main/text-domain identity.

- [ ] **Step 3: Make the source identity exact without renaming compatibility identifiers.**

  Set the public header/readme/POT/gettext domain to `mumega-mcp`, set the public name to `Mumega MCP`, and keep `mcpwp_*`, class names, option names, API key prefix, and `mcpwp/v1` unchanged. Do not perform behavior-changing search-and-replace on internal identifiers.

- [ ] **Step 4: Make one builder authoritative and deterministic.**

  Update `mcpwp/scripts/build-wporg.sh` to use `WPORG_SLUG=mumega-mcp` and `WPORG_MAIN=mumega-mcp.php`. Remove the runtime `MCPWP_WPORG_BUILD` source transformation; the branch itself must be the directory product. Make root `scripts/build-wporg.sh` `exec` the authoritative builder without independent copy/exclusion logic, unless deleting it is proven safe by repository references.

- [ ] **Step 5: Add the artifact verifier.**

  `verify-wporg-artifact.sh` must reject: a wrong/multiple top-level root; wrong main filename; header/readme/text-domain/version drift; a legacy POT; unexpected top-level files; tests/docs/build scripts/private notes/old ZIPs; `.git`; Freemius; `includes/pro`; updater implementation; and non-sorted or duplicate manifest entries. It must print a clear failing rule and exit nonzero.

- [ ] **Step 6: Update submission metadata.**

  Change build commands, support/SVN paths, installed folder, main file, and reviewer notes in `SUBMISSION.md` to `mumega-mcp`. Do not claim that later security/removal gates pass yet.

- [ ] **Step 7: Run GREEN and reproducibility checks.**

  Build twice from the same tree, run the verifier on both, compare sorted `unzip -Z1` manifests, and confirm the exact public identity. ZIP byte equality is desirable only if timestamps are normalized; manifest equality is mandatory in this task.

- [ ] **Step 8: Commit.**

  Suggested commit: `build(wporg): align artifact with mumega-mcp slug`.

---

### Task 2: Remove external, updater, premium, and arbitrary-code surfaces (#606)

**Files:**
- Create: `mcpwp/tests/WporgArtifactPolicyTest.php`
- Modify: `mcpwp/scripts/verify-wporg-artifact.sh`
- Modify: `mcpwp/mcpwp.php`
- Modify: `mcpwp/includes/class-mcpwp-loader.php`
- Modify: `mcpwp/includes/class-mcpwp-activator.php`
- Modify: deactivation/uninstall logic under `mcpwp/`
- Modify: `mcpwp/includes/mcp/class-mcpwp-mcp-free-tools.php` and retained free-tool traits
- Delete: admin Chat partial/trait and its JS/CSS hooks
- Delete: provider, integration, analytics, Figma/OAuth, screenshot, keyword research, webhook, feedback, updater, white-label, remote-media, Freemius/license, Pro registry/trait/stub, arbitrary CSS/JS/PHP/Elementor-write, and generic option implementation files identified by #606

**Interfaces:**
- Consumes: the Task 1 artifact verifier and deterministic `mumega-mcp` builder.
- Produces: a source tree and candidate ZIP with no forbidden files/symbols/hosts and a bootstrap that only requires retained classes.

- [ ] **Step 1: Add failing denylist and inventory tests.**

  Test both source and built ZIP for forbidden filenames, routes, tools, symbols, and hosts. At minimum reject: `set_site_transient`, `Plugin_Upgrader`, `RELAY_URL`, PostHog, `curl_init`, `/feedback/relay`, update/package/manifest paths, `wp_set_custom_css`, `wp_set_elementor_globals`, `elementor_snippet`, Figma/OAuth hooks, provider API hosts, generic option tools/routes, Freemius/license/Pro upgrade strings, raw PHP/JS/CSS insertion, remote media import, screenshot, keyword research, and webhooks.

- [ ] **Step 2: Run the focused policy test and capture RED.**

  Expected: failures identify current shipped external/updater/chat/feedback/Pro/arbitrary-write surfaces.

- [ ] **Step 3: Remove networked and telemetry subsystems physically.**

  Delete Admin Chat, providers/integration manager, PostHog analytics, Figma/OAuth, feedback relay/history/tooling, screenshot/keyword/webhook code, remote-media import, and their admin UI/assets/hooks/options/tables/cron events. No fixed outbound hostname may remain in executable source.

- [ ] **Step 4: Remove update/install/package code physically.**

  Delete the custom updater, update REST controller/tools/UI/options, manifest/package download logic, transient injection, upgrader/installer code, scheduled checks, and bootstrap references.

- [ ] **Step 5: Remove premium/trialware surfaces physically.**

  Delete Freemius, licensing implementation, `includes/pro`, Pro registry/traits/placeholders, upgrade prompts, locked tool names, and activation/deactivation behavior that supports them. The free directory plugin must remain useful on its own.

- [ ] **Step 6: Remove arbitrary-code and broad configuration mutation.**

  Delete custom CSS/JS/PHP insertion, Elementor writes/raw JSON/Custom Code/kit CSS/`elementor_snippet`, white-label injection, plugin/theme file writes, generic `/option`, and `wp_get_option`/`wp_update_option`. Remove menus/Gutenberg too if retaining them would preserve broad option or unsafe raw-HTML mutation.

- [ ] **Step 7: Repair bootstrap and lifecycle residue.**

  Make `mcpwp.php`, the loader, activator, deactivator, uninstall code, free-tool registry, admin menu, assets, migrations, scheduled hooks, category controls, and docs refer only to retained code. Run PHP syntax checks after each subsystem group to catch missing-class residue.

- [ ] **Step 8: Run GREEN against source and exact ZIP.**

  Run focused artifact-policy tests, PHP syntax checks, the builder, and the artifact verifier. Require zero forbidden files/symbols/hosts and no bootstrap fatal.

- [ ] **Step 9: Commit.**

  Suggested commit: `refactor(wporg): remove nonessential review surfaces`.

---

### Task 3: Minimize and harden retained MCP/REST capabilities (#607)

**Files:**
- Modify: `mcpwp/includes/traits/trait-mcpwp-api-auth.php`
- Modify: `mcpwp/includes/traits/trait-mcpwp-sanitization.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-api.php`
- Modify: retained MCP/site-info/content/posts/pages/drafts/media/taxonomy controllers
- Modify: retained posts/pages/media/drafts/taxonomy core classes
- Modify: `mcpwp/includes/api/class-mcpwp-rest-mcp.php`
- Modify: `mcpwp/includes/mcp/class-mcpwp-mcp-free-tools.php` and retained traits
- Create: `mcpwp/tests/WporgRouteAuthorizationTest.php`
- Create: `mcpwp/tests/WporgMaliciousPayloadTest.php`
- Create: `mcpwp/tests/fixtures/wporg-routes.json`
- Create: `mcpwp/tests/fixtures/wporg-tools.json`

**Interfaces:**
- Consumes: the physically reduced Task 2 bootstrap and tool registry.
- Produces: a snapshot-locked route/tool contract where API-key scope and exact WordPress capabilities are both enforced.

- [ ] **Step 1: Define the exact route and tool snapshots.**

  Permit only authenticated MCP `initialize`, `tools/list`, `tools/call`, and `ping`; read-only site information; list/get/create/update posts and pages; list/delete drafts; local media list/get/upload; and category/tag list/create. Menus/Gutenberg are excluded unless they can be retained without broad option/raw-HTML mutation and with the same complete test coverage.

- [ ] **Step 2: Write failing authorization tests and capture RED.**

  Cover missing/invalid key, read key mutation, write key publish/delete/admin attempts, cross-author resource edits, and users lacking each concrete WordPress capability. Each retained mutation class must have at least one insufficient-key-scope and one insufficient-user-capability case.

- [ ] **Step 3: Write failing malicious-payload tests and capture RED.**

  Cover script/style/PHP tags, event handlers, JavaScript URLs, malformed UTF-8, oversized/deep nested JSON, unexpected keys, invalid MIME, path traversal, remote URL upload, and content that exceeds declared bounds.

- [ ] **Step 4: Replace coarse permission callbacks.**

  Authenticate the MCP route in its permission callback while preserving valid JSON-RPC responses for authenticated protocol errors. Require both key scope and exact WordPress capability for every retained route/tool; use resource-specific checks such as `edit_post` and `delete_post` when an object ID exists. Do not use global auth-bypass filters.

- [ ] **Step 5: Enforce safe content and lifecycle defaults.**

  New posts/pages default to `draft`. Published status requires `publish_posts` or `publish_pages`; deletion requires the concrete delete capability. Bound content and process retained safe HTML through WordPress APIs while rejecting script/style/PHP/event-handler/JavaScript-URL payloads.

- [ ] **Step 6: Secure local media and structured arguments.**

  Local upload requires `upload_files`, an accepted extension/MIME pair, bounded size, and no remote URL/path traversal. Validate nested JSON by explicit schema/depth/key/type/range rules before dispatch; do not treat `json_decode()` or recursive `sanitize_text_field()` as schema validation.

- [ ] **Step 7: Remove PHP 8-only calls and lock inventories.**

  Replace or remove `str_ends_with()` and any other PHP 8-only functions. Make route/tool snapshots fail when a route/tool appears, disappears, or changes public mutability without deliberate fixture review.

- [ ] **Step 8: Run GREEN.**

  Run authorization/payload tests, the full PHPUnit suite, PHP syntax checks, the builder, and the artifact verifier.

- [ ] **Step 9: Commit.**

  Suggested commit: `fix(wporg): harden minimal MCP content surface`.

---

### Task 4: Minimal admin UI and accurate directory disclosure (#608)

**Files:**
- Modify: `mcpwp/includes/admin/class-mcpwp-admin.php`
- Modify: retained admin traits and partials under `mcpwp/includes/admin/` and `mcpwp/admin/partials/`
- Modify: `mcpwp/includes/class-mcpwp-loader.php`
- Modify: `mcpwp/admin/js/mcpwp-admin.js`
- Modify: `mcpwp/admin/css/mcpwp-admin.css`
- Modify: `mcpwp/readme.txt`
- Modify: `SUBMISSION.md`
- Create: `mcpwp/tests/WporgAdminSurfaceTest.php`
- Create: `mcpwp/scripts/check-readme-links.sh`

**Interfaces:**
- Consumes: the final route/tool snapshots from Task 3.
- Produces: Setup/Keys, minimal typed Settings, Activity, and optional exact Tool Inventory admin areas, plus public documentation that describes only those retained capabilities.

- [ ] **Step 1: Write failing admin and presentation tests.**

  Snapshot the permitted menu/tabs and fail on Chat, Integrations, Control Room, Library, provider keys, telemetry consent, update controls, feedback, white-label, Pro/upgrade UI, literal `<script>`/`<style>`, inline event handlers, remote JS/CSS, or PostHog strings.

- [ ] **Step 2: Run focused tests and capture RED.**

  Expected: current admin surface and readme claims exceed the retained product.

- [ ] **Step 3: Reduce the admin experience.**

  Keep only API-key setup/management, typed connector settings, local activity log, and an optional inventory generated from the exact retained free-tool snapshot. Every admin mutation must verify a nonce inside its handler and the exact `current_user_can()` capability.

- [ ] **Step 4: Keep presentation local and enqueued.**

  Move retained static presentation/behavior into `mcpwp-admin.css` and `mcpwp-admin.js`, enqueue through WordPress APIs, escape output in context, and localize only small dynamic values.

- [ ] **Step 5: Rewrite `readme.txt` against the artifact.**

  Use `Mumega MCP` / `mumega-mcp`; describe only retained authenticated content operations; state no telemetry and no contact with Mumega; explain user-configured MCP-client/provider data flow; explain key scopes, local logging, draft-by-default, and stronger publish/delete permissions; state that the directory edition cannot write PHP/JavaScript/CSS, edit plugin/theme files, install/update software, or self-update. Remove commercial feature lists and inaccessible repository/changelog/support links.

- [ ] **Step 6: Add anonymous link validation and reviewer notes.**

  `check-readme-links.sh` extracts HTTP(S) links from the readme, checks anonymously reachable final destinations, and reports non-2xx/3xx or unexpected private hosts clearly. Update `SUBMISSION.md` with a concise flag-by-flag remediation summary but no claim of final validation until Task 5.

- [ ] **Step 7: Run GREEN.**

  Run admin tests, presentation scans, link checker, full PHPUnit, builder, and artifact verifier; manually inspect WordPress readme formatting.

- [ ] **Step 8: Commit.**

  Suggested commit: `docs(wporg): align minimal UI and directory listing`.

---

### Task 5: Exact ZIP compliance and clean-install approval gate (#609)

**Files:**
- Extend: `mcpwp/scripts/verify-wporg-artifact.sh`
- Create: `mcpwp/scripts/test-wporg-clean-install.sh`
- Create: `mcpwp/tests/WporgNoOutboundHttpTest.php`
- Create: `mcpwp/tests/WporgLifecycleTest.php`
- Extend: `mcpwp/tests/WporgArtifactPolicyTest.php`
- Create: `docs/wporg/approval-evidence.md`
- Create: `docs/wporg/mumega-mcp-manifest.txt`
- Modify/create: `.github/workflows/wporg-approval.yml`
- Modify: `mcpwp/composer.json` and PHPCS configuration only as needed for reproducible WPCS checks

**Interfaces:**
- Consumes: exact builder/verifier, final minimal bootstrap, route/tool snapshots, and final admin/readme contract.
- Produces: one candidate ZIP, its SHA-256 and sorted manifest, a reproducible evidence record, and CI gates for PRs targeting `wporg`.

- [ ] **Step 1: Add a failing outbound-HTTP spy test.**

  Hook `pre_http_request` and assert zero outbound requests during activation, admin page load, key creation/revocation, site-info reads, and every retained CRUD tool success path. Treat any fixed destination or telemetry request as a failure.

- [ ] **Step 2: Add lifecycle and final artifact-policy tests.**

  Exercise activate, deactivate, reactivate, and uninstall under folder/main basename `mumega-mcp/mumega-mcp.php`. Scan the exact ZIP for forbidden code-execution, package/update/install, remote executable assets, arbitrary CSS/code writers, public mutation routes, hidden external hosts, tests/private files, and identity drift.

- [ ] **Step 3: Run focused tests and capture RED where the gate is incomplete.**

  Expected: new tests initially fail on missing clean-install/evidence/CI integration or any remaining policy violation.

- [ ] **Step 4: Implement the clean-install harness.**

  Install the built ZIP, never a source mount, on WordPress 6.1/PHP 7.4 and current WordPress/PHP 8.3 with `WP_DEBUG=true` and `WP_DEBUG_LOG=true`. Exercise activation, key management, retained tools, insufficient-permission paths, deactivation, reactivation, and uninstall; fail on PHP warnings/notices/fatals or a non-empty debug log.

- [ ] **Step 5: Add PHPCS/WPCS and Plugin Check gates.**

  Run the official Plugin Check against the installed candidate. Run PHPCS with WordPress Coding Standards over shipped first-party source, excluding generated/vendor code. Plugin Check errors fail; warnings and PHPCS findings must be recorded and either fixed or justified specifically in the evidence file.

- [ ] **Step 6: Add targeted CI for `wporg`.**

  On pull requests targeting `wporg` and direct `wporg` updates, install pinned/reproducible dependencies, build the candidate, verify identity/policy, run PHP 7.4 and 8.3 syntax/tests, run WPCS/Plugin Check where CI supports it, and upload the candidate/evidence as workflow artifacts. Do not alter unrelated main-product release workflows.

- [ ] **Step 7: Generate immutable candidate evidence.**

  Rebuild once after all fixes, run the full gate against that exact file, generate `docs/wporg/mumega-mcp-manifest.txt` from sorted `unzip -Z1`, compute SHA-256, and record commands, tool versions, environments, results, warnings, and manual checks in `docs/wporg/approval-evidence.md`.

- [ ] **Step 8: Run final GREEN from a clean state.**

  Require: builder/verifier success; focused and full PHPUnit success; PHP syntax on 7.4/8.3; zero unexpected outbound HTTP; clean lifecycle/debug logs; Plugin Check zero errors with warnings reviewed; PHPCS/WPCS reviewed; anonymous links pass; exact manifest/hash match the tested candidate.

- [ ] **Step 9: Commit.**

  Suggested commit: `test(wporg): gate exact approval candidate artifact`.

---

### Task 6: Independent whole-branch review and submission handoff

**Files:**
- Review: all changes from the `origin/wporg` merge base through Task 5
- Update only if findings require it: implementation/tests/evidence files above
- Do not mutate WordPress.org or Gmail in this task

**Interfaces:**
- Consumes: the complete branch diff and exact candidate evidence.
- Produces: a fresh review verdict, a ready-to-use PR summary for target `wporg`, and a #610 handoff that identifies the exact candidate by path/hash without uploading it.

- [ ] **Step 1: Generate a whole-branch review package from the original merge base to HEAD.**
- [ ] **Step 2: Dispatch a fresh senior reviewer to compare the diff, exact ZIP manifest, evidence, and #604–#609 requirements.**
- [ ] **Step 3: Fix every Critical/Important finding in one coordinated fix wave; re-run covering tests and re-review.**
- [ ] **Step 4: Re-run the entire final gate after the last code/doc change and refresh the candidate hash/manifest/evidence.**
- [ ] **Step 5: Prepare the PR title/body and #610 upload/email checklist, but do not push, open the PR, upload, or send email without explicit user authorization.**
