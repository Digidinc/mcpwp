# WordPress.org Fail-Closed Review Wave Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the final Task 2 review gaps with fail-closed authentication, structural artifact extraction, policy matching, and accurate public documentation.

**Architecture:** Keep the outer MCP request as the sole rate-limit boundary while nested REST dispatch retains API-key permission and scope checks. Strengthen the shared JSON-driven PHPUnit and shell-verifier policy by parsing every retained runtime file, normalizing narrowly supported static PHP string expressions, and comparing independently extracted advertised tools, maps, routes, and exact file inventories.

**Tech Stack:** PHP 7.4-compatible WordPress plugin code, PHPUnit 9, Bash, Python 3 ZIP verifier, Docker.

## Global Constraints

- The MCP inventory is exactly 17 tools; multipart upload is REST-only.
- Source and ZIP inventory checks are exclusive and fail closed.
- Every behavior change follows a witnessed RED then GREEN cycle.
- No push.

---

### Task 1: Single-Charge Nested MCP Authentication

**Files:**
- Modify: `mcpwp/tests/McpEndpointTest.php`
- Modify: `mcpwp/tests/bootstrap.php`
- Modify: `mcpwp/includes/traits/trait-mcpwp-api-auth.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-mcp.php`

**Interfaces:**
- Consumes: outer `verify_api_key(WP_REST_Request)` and nested `rest_do_request()`.
- Produces: an internal request marker that skips only the second rate-limit charge after full nested key/scope authentication.

- [ ] Add a router regression with rate limiting enabled that asserts one limiter call, real nested permission execution, and preserved API-key identity.
- [ ] Run `McpEndpointTest.php`; expect the limiter-call count to fail at two.
- [ ] Add a private internal marker and narrowly skip only the nested limiter invocation after successful key verification.
- [ ] Re-run the focused test; expect GREEN.

### Task 2: Fail-Closed Artifact Structure and Policy Matching

**Files:**
- Modify: `mcpwp/tests/WporgArtifactPolicyTest.php`
- Modify: `mcpwp/scripts/verify-wporg-artifact.sh`
- Modify: `mcpwp/scripts/wporg-artifact-policy.json`

**Interfaces:**
- Consumes: `allowed_runtime_files`, `retained_tool_map`, and `retained_rest_routes` from the shared policy JSON.
- Produces: independent advertised-tool, tool-map, REST-route, and exact source/ZIP inventory comparisons.

- [ ] Add mutation regressions for an advertised map-less tool, short-array map alias, route registration in a non-controller runtime file, extensionless runtime file, cleanup suffix near misses, and statically composed fixed/wildcard hosts.
- [ ] Run `WporgArtifactPolicyTest.php`; expect each new mutation to be accepted incorrectly and fail its assertion.
- [ ] Parse tool definitions and maps independently, including short arrays; inspect route registrations in every retained PHP runtime file; include extensionless runtime files in exact inventory; boundary-match cleanup exceptions; normalize narrowly supported concatenated literal strings before host checks.
- [ ] Re-run policy and identity tests; expect GREEN.

### Task 3: Acceptance Oracle and Readme Accuracy

**Files:**
- Modify: `mcpwp/tests/WporgArtifactPolicyTest.php`
- Modify: `mcpwp/scripts/wporg-artifact-policy.json`
- Modify: `mcpwp/readme.txt`

**Interfaces:**
- Produces: exact 17-tool oracle and metadata-only logging copy.

- [ ] Add assertions that multipart upload is absent from MCP and present only as REST, and that the readme contains metadata-only logging language without configurable-redaction claims.
- [ ] Run the focused tests and observe RED if stale wording/oracle remains.
- [ ] Update only the acceptance fixture/readme text needed by the regressions.
- [ ] Re-run the focused tests; expect GREEN.

### Task 4: Full Verification and Commit

**Files:**
- Modify: `.superpowers/sdd/task-2-report.md`

**Interfaces:**
- Produces: final RED/GREEN evidence and commit `fix(wporg): make approval policy fail closed`.

- [ ] Run focused MCP, policy, and identity suites.
- [ ] Run full PHPUnit, PHP 7.4 syntax, deterministic build twice with hash comparison, exact verifier, and `git diff --check`.
- [ ] Append exact outcomes to the Task 2 report.
- [ ] Commit all scoped tracked changes without pushing.

### Task 5: Final Closure — Batch Credits and Parser Evasion

**Files:**
- Modify: `mcpwp/tests/McpEndpointTest.php`
- Modify: `mcpwp/tests/WporgArtifactPolicyTest.php`
- Modify: `mcpwp/includes/api/class-mcpwp-rest-mcp.php`
- Modify: `mcpwp/includes/traits/trait-mcpwp-api-auth.php`
- Modify: `mcpwp/scripts/verify-wporg-artifact.sh`
- Modify: `.superpowers/sdd/task-2-acceptance-oracle.md`
- Modify: `.superpowers/sdd/task-2-report.md`

**Interfaces:**
- Consumes: authenticated JSON-RPC single/batch requests, the shared exact policy fixture, and the retained runtime allowlist.
- Produces: one outer rate-limit credit consumed by the first nested tool and one unit per additional batch tool, plus fail-closed parsing of dynamic advertisements, cleanup expressions, comment-separated hosts, and extensionless paths.

- [ ] Add single-call and two-tool JSON-RPC batch regressions that execute real nested permission callbacks and assert limiter counts of one and two respectively.
- [ ] Run `McpEndpointTest.php`; expect the batch count to remain one because every tool call currently creates a fresh bypass marker.
- [ ] Add ZIP mutations for `$inventory[]`/`$descriptions[...]` dynamic tool advertisements, comma-separated cleanup-table near misses, comment-separated literal hosts, and top-level/nested extensionless runtime files; add matching source-scanner assertions.
- [ ] Run `WporgArtifactPolicyTest.php`; expect the new evasions to be accepted and fail their assertions.
- [ ] Replace the per-tool boolean bypass with an outer-request credit consumed only by the first nested tool, deny dynamic advertisement mutation, constrain cleanup masking to the exact table expression, fold comments only between literal concatenation tokens, and treat every non-dev file path as an inventory candidate.
- [ ] Update the ignored Task 2 oracle to exactly 17 MCP tools, multipart REST-only, and metadata-only logging without request/response storage or redaction claims; keep the tracked plan and JSON fixture aligned.
- [ ] Run focused and full GREEN verification, PHP 7.4 lint, two deterministic builds, the exact verifier, and `git diff --check`; refresh report counts and commit without pushing.
