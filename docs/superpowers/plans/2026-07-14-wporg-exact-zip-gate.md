# WordPress.org Exact-ZIP Approval Gate Plan

**Goal:** Produce one immutable `mumega-mcp-3.4.3.zip` and prove that exact byte stream passes clean install, lifecycle, no-outbound, Plugin Check, WPCS, identity/policy, compatibility, and evidence gates.

**Scope guard:** This task adds release tests and tooling plus only the product fixes those tests expose. It does not add MCP tools, REST routes, providers, commercial code, remote calls, editors, or updater behavior.

## Frozen release contract

- Build twice from a clean tracked tree; require byte-identical ZIPs, then freeze one candidate path and SHA-256.
- Every later gate consumes `MCPWP_WPORG_ZIP` and must not rebuild or replace the frozen candidate.
- Install the ZIP, never a source mount, under `mumega-mcp/mumega-mcp.php` on WordPress 6.1.1/PHP 7.4 and WordPress 7.0.1/PHP 8.3.
- Preserve exactly 17 MCP tools, 19 REST route patterns, four admin pages, and the exclusive runtime manifest.
- Register an outbound-HTTP spy before activation and require zero attempts through activation, admin, key lifecycle, site-info, all 17 successful tools, denial paths, deactivation, reactivation, and uninstall.
- Deactivation preserves owned state; uninstall removes all current/legacy owned options, tables, schedules, the `mcpwp_api_agent` role, and administrator `mcpwp_manage_settings` capability across an unbounded multisite network while preserving unrelated content/state.
- Plugin Check 2.0.0 runs against the ZIP-installed candidate on WordPress 7.0.1. Errors are zero; warnings are fixed or itemized.
- WPCS 3.3.0/PHPCS 3.13.5 runs over extracted shipped first-party files. Findings are fixed or narrowly justified; no blanket suppression.
- A dedicated SHA-pinned `wporg-approval.yml` builds once and fans out the same uploaded candidate to every job.

## TDD sequence

1. Freeze missing gate/lifecycle/outbound requirements in failing tests and capture RED.
2. Fix role/capability and unbounded multisite uninstall behavior with focused GREEN.
3. Implement one exact-ZIP clean-install harness with pre-activation MU outbound spy and strict zero-byte plugin-phase debug logs.
4. Extend the verifier with mutated-artifact tests for remaining archive, network, execution, writer, install/update, permission, and inventory near misses.
5. Add locked WPCS tooling/ruleset; run PHPCBF only for reviewed mechanical formatting, then review/fix security/database findings and record any justified warnings.
6. Run pinned Plugin Check 2.0.0 on the ZIP-installed candidate; parse strict JSON explicitly and fix all errors.
7. Add targeted immutable-artifact CI, then generate the pure manifest and complete evidence record.
8. From a clean tracked tree, build/freeze once and run every gate against that SHA. Any shipped-file change invalidates the evidence and restarts this step.

## Required evidence

- focused/full PHPUnit totals; PHP 7.4/8.3 syntax counts; POT/link results;
- both clean-install/lifecycle lane TAP results and zero-byte debug logs;
- zero outbound attempts and exact context/tool coverage;
- Plugin Check version, download SHA, command, error/warning dispositions;
- WPCS/PHPCS locked versions, command, totals, complete dispositions;
- exact ZIP byte size/SHA, 62-or-final reviewed entry count, sorted manifest hash, deterministic double-build proof;
- workflow/action/image pins and manual admin/readme checks.

No push, PR, release, SVN write, or WordPress.org upload is part of this plan.
