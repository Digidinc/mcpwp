# WordPress.org Minimal Admin and Disclosure Plan

**Goal:** Make the source tree and exact WordPress.org ZIP describe one minimal, local Mumega MCP product: 17 retained MCP tools, 19 retained REST route patterns, four administration pages, and accurate owner-controlled data-flow disclosures.

**Scope guard:** This task does not add routes, tools, providers, remote requests, commercial features, editors, or software-management capabilities. Legacy names may survive only in destructive upgrade-cleanup code with an explanatory comment.

## Acceptance surface

- Register exactly Setup & Keys (`mcpwp`), Tool Inventory (`mcpwp-tools`), Settings (`mcpwp-settings`), and Activity (`mcpwp-activity-log`), all gated by `mcpwp_manage_settings` at registration and rendering.
- Make Tool Inventory read-only; remove the category-toggle and connection-test AJAX surface and its unused localized values.
- Let the current explicit WordPress user create a key only after selecting non-empty scopes/categories, show plaintext once, list safe metadata, and revoke by ID. Each mutation verifies its own nonce and `mcpwp_manage_settings`.
- Save logging, retention, enabled rate limiting, per-minute/hour limits, burst limit, and whitelist without erasing retained values. Enforce bounds and `burst_limit <= requests_per_minute`; support users holding the custom capability without requiring `manage_options`.
- Keep Activity read-only and limited to local metadata; localize all headings and remove stale nonexistent-column queries.
- Ship only local assets enqueued through WordPress APIs. Remove inline scripts/styles/handlers, remote assets, and obsolete localized data.
- Rebuild `languages/mumega-mcp.pot` reproducibly from shipped source with the `mumega-mcp` domain.
- Remove the overbroad Plugin URI unless it describes the exact directory edition.
- Rewrite `readme.txt` to disclose no plugin-initiated outbound request/telemetry/Mumega contact, owner-configured client/provider data flow, explicit-user key binding/scopes, logging/retention, draft defaults, stronger publish/private/delete authority, and the edition's explicit inability to edit code/plugins/themes or self-update.
- Add an anonymous readme-link checker that follows redirects, rejects unreachable links and unexpected private hosts, and reports the failing URL.
- Rewrite `SUBMISSION.md` as current reviewer notes, including the 17-tool/19-route boundary and the fact that final validation belongs to Task 5.

## TDD checkpoints

### RED: freeze current violations

Add `tests/WporgAdminSurfaceTest.php` covering the exact menu/capability contract, dead AJAX surface, key scope/revocation controls, complete settings fields, local presentation, localization coverage, plugin metadata, readme disclosures, link checker, and stale submission claims. Run only this file and preserve the genuine failures before editing runtime files.

### GREEN 1: authorization and mutations

Refactor setup/settings handlers so every mutation checks `mcpwp_manage_settings` and its own nonce. Add least-privilege key creation and revocation tests plus full/partial/checkbox/invalid-nonce/unauthorized/stored-XSS fixtures.

### GREEN 2: exact read-only admin and local presentation

Remove dead AJAX registrations/methods/localization, make Tool Inventory and Activity read-only, eliminate stale schema queries, localize retained output, and keep CSS/JS local and enqueued.

### GREEN 3: directory truth

Regenerate the POT, update plugin metadata, rewrite `readme.txt` and `SUBMISSION.md`, and add `scripts/check-readme-links.sh`.

## Verification

Run focused admin/readme/POT tests and the link checker, then full PHPUnit, PHP 7.4 lint, `git diff --check`, exact builder/verifier, source/ZIP policy parity, and two byte-identical builds. Retain the WordPress 6.1.1/PHP 7.4 compatibility floor and add real WordPress 7.0.1 activation/admin/deactivation smoke evidence for the `Tested up to: 7.0` claim. Task 4 is complete only after two independent reviews; final release validation remains Task 5.
