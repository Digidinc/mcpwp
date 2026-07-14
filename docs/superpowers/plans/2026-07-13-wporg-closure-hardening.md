# WordPress.org Closure Hardening Implementation Plan

> **For Codex:** Execute this plan inline with strict RED/GREEN TDD. Do not change production code until every probe below has been added and the focused RED run has been reported.

**Goal:** Close the final WordPress.org approval review gaps without broadening the plugin surface, then reproduce all release gates and commit locally without pushing.

**Architecture:** Keep authorization decisions centralized in the REST controller, make media ingestion share one policy/finalization path, and make JSON-RPC decode raw JSON while preserving object/list shape. Keep migration and rate settings fail-closed by requiring an authenticated administrator owner and canonical persisted schemas. Strengthen the offline artifact verifier with an externally maintained trust set that covers every executable file capable of defining route authorization behavior.

**Tech Stack:** WordPress 6.1+, PHP 7.4+, PHPUnit, shell/TAP integration tests, deterministic ZIP verifier.

---

### Task 1: Encode the migration and canonical-setting invariants

**Files:**
- Modify: `mcpwp/tests/PluginLifecycleTest.php`
- Modify: `mcpwp/tests/BridgeMigrateTest.php`
- Modify: `mcpwp/tests/ApiAuthTest.php`
- Modify: `mcpwp/tests/RateLimiterTest.php`

1. Add the subscriber-then-administrator version-mismatch activation sequence and assert only `manage_options` can seed an owner.
2. Add anonymous legacy-key authentication coverage proving no owner-0 scoped record is persisted.
3. Add normal administrator migration coverage proving pre-existing owner-0 records are repaired without requiring explicit setup.
4. Add read/update tests proving rate settings are projected onto the exact canonical key set and unknown persisted/input fields disappear.
5. Run the focused tests and retain their expected failures.

### Task 2: Encode raw-JSON and resource-order authorization invariants

**Files:**
- Modify: `mcpwp/tests/WporgRouteAuthorizationTest.php`
- Modify: `mcpwp/tests/McpEndpointTest.php`

1. Add direct text/plain raw JSON probes for publish/update/term/assignment dynamic capabilities.
2. Add an MCP text/plain raw JSON probe proving the same dynamic capability checks apply through nested dispatch.
3. Add wrong-controller and wrong-status probes proving existence, type, and draft status return 404 before meta-capability 403.
4. Run the focused tests and retain their expected failures.

### Task 3: Encode shared media policy, rollback, and sanitized-error invariants

**Files:**
- Modify: `mcpwp/tests/MediaSecurityTest.php`
- Modify: `mcpwp/tests/bootstrap.php`
- Modify: `tests/integration/wp61/integration.php`

1. Add multipart probes for executable/SVG/actual-byte mismatch under filtered MIME and `unfiltered_upload` conditions.
2. Add base64 and multipart-equivalent finalization probes for real metadata, alt, and caption failures, plus legitimate no-op semantics.
3. Add REST and MCP probes proving upload/sideload implementation errors never expose filesystem paths and are bounded generic messages.
4. Run unit and real-WordPress probes and retain their expected failures.

### Task 4: Encode strict JSON-RPC limits, shapes, charging, and validation

**Files:**
- Modify: `mcpwp/tests/McpEndpointTest.php`

1. Add category-denial `data.status=403` coverage.
2. Add authenticated ordinary/upload/aggregate body-limit coverage with HTTP 200 JSON-RPC `-32013` and `status=413`.
3. Add bounded `initialize.clientInfo`/`capabilities` coverage and raw object-vs-array rejection for `params:[]` and `arguments:[]`.
4. Add one-unit charging probes for each authenticated top-level invalid request, no-double-charge coverage, and mapped-write charging before argument validation.
5. Run the focused tests and retain their expected failures.

### Task 5: Encode bounded visibility and exact-ZIP trust invariants

**Files:**
- Modify: `mcpwp/tests/VisibilityFilteringTest.php`
- Modify: `mcpwp/tests/WporgArtifactPolicyTest.php`
- Modify: `scripts/verify-wporg-artifact.php`
- Modify: trusted policy fixture(s) under `mcpwp/tests/fixtures/`

1. Add pagination/total probes for sparse authorization over more than one bounded chunk and assert no `posts_per_page=-1` query occurs.
2. Add verifier mutation probes for route policy, authorization traits, REST controllers, and callback/argument definitions.
3. Run focused tests and retain their expected failures.

### Task 6: Implement the minimal fixes

**Files:**
- Modify only the runtime and verifier files implicated by Tasks 1–5.

1. Require `manage_options` for owner seeding and repair owner-0 records during normal administrator migration/authentication.
2. Decode one raw JSON representation for both permissions and handlers; verify resource shape before meta caps.
3. Share media validation/finalization, verify persisted write results, accept exact no-ops, and sanitize upload-library errors.
4. Preserve JSON object/list shapes, enforce limits/bounds, attach status data, and charge the mapped method exactly once.
5. Replace unbounded visibility queries with bounded chunks and accurate authorized pagination totals.
6. Canonicalize rate settings and expand the external artifact trust set.
7. Re-run each focused test after its minimal change until GREEN.

### Task 7: Verify and commit

**Files:**
- Modify: `.superpowers/sdd/task-3-report.md`

1. Run the complete PHPUnit suite.
2. Run the exact WordPress 6.1 gate.
3. Run PHP 7.4 lint over all shipped PHP files.
4. Build the artifact twice, compare hashes, and run the exact-ZIP verifier.
5. Review `git diff`, update the task report with fresh evidence, and create one local commit. Do not push.
