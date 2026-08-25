# Cairn MCPWP Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create Cairn's owner-approved Mupot identity, repository profile,
canonical asset set, first bounded task, and stacked draft pull request without
exposing credentials or granting authority through Git.

**Architecture:** Mupot owns live identity, membership, tasks, gates, and
presence. Git owns Cairn's public profile, requested-only manifest, charter,
asset provenance, and reviewable evidence. The branch is stacked on
`agent/lumen-video-unit` so Cairn consumes the committed agent-home template
without duplicating it.

**Tech Stack:** Mupot owner interface, Markdown, YAML, PNG, Ruby YAML parser,
macOS `sips`, Git, GitHub CLI

## Global Constraints

- Work only in `/Users/hadi/dev/mumega/mumcp/mcpwp-cairn-onboarding`.
- Branch is `agent/cairn-onboarding`; pull-request base is
  `agent/lumen-video-unit`.
- The checkout-generated `.github/PULL_REQUEST_TEMPLATE.md` modification is
  never staged, committed, or included in the pull request.
- The Mupot manifest requests capabilities and never grants authority.
- No credential value enters Git, terminal output, browser snapshots, task
  text, issue text, model output, or project memory.
- Persistent token creation requires confirmation immediately before the
  action and a private owner handoff.
- Publishing, outreach, spending, permission administration, production
  control, merging, and product claims remain human-gated.
- Do not change plugin code, production WordPress, releases, pricing, or the
  MCPWP media publication.

---

### Task 1: Create the owner-approved Mupot identity

**Files:**
- Modify later from returned evidence:
  `agents/cairn/mupot-agent.yaml`
- Modify later from returned evidence: `agents/cairn/README.md`
- Modify later from returned evidence: `agents/cairn/SETUP.md`

**Interfaces:**
- Consumes: the signed-in Mupot owner session and the approved design
- Produces: `cairn_agent_id`, `cairn_agent_url`, `mcpwp_project_id`, and
  `mcpwp_core_squad_id`

- [ ] **Step 1: Reopen and verify the Add agent form**

Confirm the visible owner form contains unique fields for Name, Slug, Role,
Model, and Squad. Stop if an existing `cairn` identity is returned by search.

- [ ] **Step 2: Enter the approved identity**

Use these exact values:

```text
Name: Cairn
Slug: cairn
Role: MCPWP Knowledge and Systems Cartographer
Model: gpt-5.6-sol
Squad: MCPWP / MCPWP Core
```

- [ ] **Step 3: Submit and verify the returned identity**

Submit `Add agent` once. Confirm the resulting profile shows `Cairn`, slug
`cairn`, the approved role, model, and MCPWP Core membership. Record the agent
UUID from the returned URL. Read the project and squad UUIDs from the verified
MCPWP placement rather than inventing them.

- [ ] **Step 4: Create the first bounded task as owner**

Create and assign:

```text
Title: Establish Cairn's MCPWP identity home and evidence contract
Priority: medium
Assignee: Cairn
Description: Create agents/cairn from the approved profile-master contract, add canonical Cairn assets and provenance, validate requested-only authority and secret-free evidence, then open a stacked draft PR against agent/lumen-video-unit. No plugin, production, publishing, spending, outreach, permission, or release changes.
```

Record the returned task UUID and URL. Leave the task open until repository
implementation begins.

- [ ] **Step 5: Stop at the credential action gate**

Navigate to the owner token surface and prepare an agent-bound token named
`cairn-codex-desktop`. Immediately before the create action, request owner
confirmation. Do not inspect or transmit the one-time secret. The owner moves
it privately to the approved runtime destination.

### Task 2: Create Cairn's repository home

**Files:**
- Create: `agents/cairn/README.md`
- Create: `agents/cairn/CHARTER.md`
- Create: `agents/cairn/SETUP.md`
- Create: `agents/cairn/mupot-agent.yaml`
- Create: `agents/cairn/assets/README.md`
- Create: `agents/cairn/memory/README.md`

**Interfaces:**
- Consumes: Task 1's verified Mupot IDs and task URL
- Produces: Cairn's public profile master and requested-only Mupot proposal

- [ ] **Step 1: Write the public profile**

Record the verified identity, MCPWP placement, adapter
`codex-desktop-cairn`, approved role, five bounded capabilities, owned path
`agents/cairn/`, task evidence, authority boundary, and links to the charter,
setup receipt, manifest, assets, and public memory.

- [ ] **Step 2: Write the charter**

Set Cairn's mission to maintaining evidence-backed maps between product truth,
architecture, documentation, market claims, and reviewable implementation.
Limit owned paths to `agents/cairn/` and the planning documents created by this
onboarding change. Require human approval for publishing, outreach, spending,
permissions, production, merging, and commercial claims.

- [ ] **Step 3: Write the setup receipt**

Mark identity creation, role, and MCPWP Core placement complete only when Task
1 verifies them. Mark token verification, presence, and agent-authenticated
mutation incomplete until the private handoff is confirmed. Record the Mupot
task and draft PR only after each exists.

- [ ] **Step 4: Write the manifest**

Use this schema with the exact IDs returned by Task 1:

```yaml
schema_version: 1
kind: mupot_agent_proposal

identity:
  id: task_1.cairn_agent_id
  slug: cairn
  display_name: Cairn
  role: MCPWP Knowledge and Systems Cartographer

runtime:
  kind: codex-desktop
  model: gpt-5.6-sol
  adapter: codex-desktop-cairn

placement:
  project_id: task_1.mcpwp_project_id
  project_slug: mcpwp
  squad_id: task_1.mcpwp_core_squad_id
  squad_hint: mcpwp-core

requested_capabilities:
  - evidence-mapping
  - documentation-stewardship
  - architecture-review
  - content-fact-checking
  - draft-pr-preparation

authority:
  mode: requested_only
  self_grant: false
  publishing: human_approval_required
  outreach: human_approval_required
  spending: human_approval_required
  production_control: human_approval_required
  product_claims: human_approval_required
  current_review_gate: gate:kasra-core

paths:
  home: agents/cairn
  charter: agents/cairn/CHARTER.md
  setup: agents/cairn/SETUP.md
  assets: agents/cairn/assets/README.md
  memory: agents/cairn/memory/README.md
```

The `task_1.*` expressions above are plan-time interface references. Replace
them with the observed UUID strings; no interface references remain in the
implementation.

- [ ] **Step 5: Write public-memory rules**

Allow approved decisions, public issue and pull-request links, non-secret
conventions, evidence maps, and completed receipts. Prohibit credentials,
private configuration, customer data, hidden reasoning, unverified claims, and
transient runtime state presented as permanent truth.

### Task 3: Add the canonical Cairn assets

**Files:**
- Create: `agents/cairn/assets/cairn-profile-headshot.png`
- Create: `agents/cairn/assets/cairn-turnaround-six-view.png`
- Create: `agents/cairn/assets/cairn-office-key-art.png`
- Create: `agents/cairn/assets/cairn-world-true-form.png`
- Modify: `agents/cairn/assets/README.md`

**Interfaces:**
- Consumes:
  `/Users/hadi/dev/mumega/mumcp/cairn-character-reference/`
- Produces: four canonical, checksummed profile assets

- [ ] **Step 1: Copy the four approved binaries**

Run:

```bash
cp /Users/hadi/dev/mumega/mumcp/cairn-character-reference/cairn-headshot-front.png agents/cairn/assets/cairn-profile-headshot.png
cp /Users/hadi/dev/mumega/mumcp/cairn-character-reference/cairn-turnaround-sheet.png agents/cairn/assets/cairn-turnaround-six-view.png
cp /Users/hadi/dev/mumega/mumcp/cairn-character-reference/cairn-in-office.png agents/cairn/assets/cairn-office-key-art.png
cp /Users/hadi/dev/mumega/mumcp/cairn-character-reference/cairn-world-true-form.png agents/cairn/assets/cairn-world-true-form.png
```

- [ ] **Step 2: Inspect dimensions, type, and checksums**

Run:

```bash
file agents/cairn/assets/*.png
sips -g pixelWidth -g pixelHeight agents/cairn/assets/*.png
shasum -a 256 agents/cairn/assets/*.png
```

Record the exact outputs in `agents/cairn/assets/README.md` with intended use,
alt text, Codex-generated source provenance, and owner-approved status.

- [ ] **Step 3: Confirm the binary set**

Run:

```bash
test "$(find agents/cairn/assets -maxdepth 1 -type f -name '*.png' | wc -l | tr -d ' ')" = "4"
```

Expected: exit `0`.

### Task 4: Validate the profile and evidence boundary

**Files:**
- Test: `agents/cairn/**`
- Test:
  `docs/superpowers/specs/2026-07-30-cairn-onboarding-design.md`
- Test: `docs/superpowers/plans/2026-07-30-cairn-onboarding.md`

**Interfaces:**
- Consumes: Tasks 1 through 3
- Produces: fresh validation evidence suitable for the Mupot task and PR

- [ ] **Step 1: Parse the YAML**

Run:

```bash
ruby -e 'require "yaml"; YAML.load_file("agents/cairn/mupot-agent.yaml"); puts "cairn yaml ok"'
```

Expected: `cairn yaml ok`.

- [ ] **Step 2: Check authority invariants**

Run:

```bash
rg -n 'mode: requested_only|self_grant: false|human_approval_required' agents/cairn
```

Expected: the manifest contains requested-only mode, no self-grant, and all
five human-gated authority fields.

- [ ] **Step 3: Scan for credentials and unfinished values**

Run:

```bash
! rg -n 'Bearer [A-Za-z0-9]|X-API-Key:[[:space:]]*[A-Za-z0-9]|mcpwp_[a-f0-9]{24,}|sk-[A-Za-z0-9_-]{20,}|task_1\.(cairn_agent_id|mcpwp_project_id|mcpwp_core_squad_id)|TODO|TBD' agents/cairn docs/superpowers/specs/2026-07-30-cairn-onboarding-design.md
```

Expected: exit `0` with no matches.

- [ ] **Step 4: Check Markdown links and branch scope**

Resolve every relative Markdown target under `agents/cairn/`. Then run:

```bash
git diff --check agent/lumen-video-unit...HEAD
git status --short --branch
git diff --name-only agent/lumen-video-unit...HEAD
```

Expected: no whitespace errors; only Cairn files plus the design and plan are
in the branch range; `.github/PULL_REQUEST_TEMPLATE.md` remains an unstaged
working-tree modification and is absent from the branch diff.

### Task 5: Commit, push, and open the stacked draft pull request

**Files:**
- Commit: `agents/cairn/**`
- Commit:
  `docs/superpowers/plans/2026-07-30-cairn-onboarding.md`
- Existing commit:
  `docs/superpowers/specs/2026-07-30-cairn-onboarding-design.md`

**Interfaces:**
- Consumes: Task 4 validation
- Produces: pushed branch, draft PR URL, and review receipt

- [ ] **Step 1: Stage only Cairn scope**

Run:

```bash
git add agents/cairn docs/superpowers/plans/2026-07-30-cairn-onboarding.md
git diff --cached --check
git diff --cached --name-only
```

Confirm `.github/PULL_REQUEST_TEMPLATE.md` is absent.

- [ ] **Step 2: Commit**

Run:

```bash
git commit -m "docs: onboard Cairn for MCPWP"
```

- [ ] **Step 3: Re-run final validation**

Repeat Task 4 against the committed branch. Do not claim completion from the
pre-commit run.

- [ ] **Step 4: Push**

Run:

```bash
git push -u origin agent/cairn-onboarding
```

- [ ] **Step 5: Open a stacked draft PR**

Create a draft pull request with:

```text
Base: agent/lumen-video-unit
Head: agent/cairn-onboarding
Title: docs: onboard Cairn for MCPWP
```

The body explains what changed, why the branch is stacked, the human authority
boundary, partial token/presence state when applicable, and exact validation.

### Task 6: Attach evidence and request review

**Files:**
- Modify: `agents/cairn/README.md` only if the PR or task link was not known
  before commit
- Modify: `agents/cairn/SETUP.md` only to record independently verified state

**Interfaces:**
- Consumes: Mupot task URL, pushed commit, draft PR URL, final validation
- Produces: Mupot review state and named human gate

- [ ] **Step 1: Update the Mupot task**

Attach branch, commit, draft PR, YAML result, asset count, checksum evidence,
secret-scan result, link check, and explicit incomplete credential or presence
steps. Move the task to `in_progress` during implementation and `review` only
after the pushed evidence exists.

- [ ] **Step 2: Request the review gate**

Request `gate:kasra-core` when available. Do not approve or merge the work.

- [ ] **Step 3: Verify external state**

Confirm the draft PR base/head, the Mupot task status, the assigned Cairn
identity, and the named gate from their authoritative pages. Report any
unverified or blocked credential/presence step separately.
