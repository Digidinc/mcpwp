# MCPWP Agent Profile Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a reusable MCPWP agent-home contract, make Lumen its complete reference implementation, add canonical Lumen imagery, and record the Mupot gaps encountered during onboarding.

**Architecture:** Shared onboarding rules live at `agents/README.md`, a neutral import proposal lives under `agents/_template/`, and Lumen's concrete identity lives under `agents/lumen/`. Operational video work continues under `content/video/`; the agent home links to it rather than copying it.

**Tech Stack:** Markdown, YAML, PNG assets, Git, GitHub Issues, Mupot MCP

## Global Constraints

- Agent manifests are proposals and cannot grant authority.
- No credentials, connector secrets, stream keys, or private operational data enter Git.
- Human approval remains required for publishing, outreach, spending, live OBS control, permissions, and product or commercial claims.
- Generated `.github/agents/**` execution profiles are excluded.
- The unrelated `.github/PULL_REQUEST_TEMPLATE.md` modification remains unstaged and outside the pull request.

---

### Task 1: Record Mupot onboarding evidence

**Files:**
- Modify: `agents/lumen/README.md` after issue creation

**Interfaces:**
- Consumes: live Mupot task, presence, and GitHub-mirror observations
- Produces: durable GitHub issue URLs referenced from Lumen's home

- [ ] **Step 1: Search for related issues**

Run:

```bash
gh issue list --repo Mumega-com/mupot --state all --limit 200
```

Confirm closed issue #319 covers prior repository divergence, open issue #528
covers onboarding, and open issue #605 covers project repository homes.

- [ ] **Step 2: Create the repository-routing regression**

Create one issue containing the MCPWP project ID, Mupot task ID, wrong mirror
URL, correct repository and pull request, observed behavior, expected behavior,
reproduction, impact, and acceptance criteria. Reference #319 and #605.

- [ ] **Step 3: Add Lumen evidence to onboarding issue #528**

Comment with the initial wrong-agent token binding, required owner browser
round trip, secure agent-bound result, expected guided enrollment flow, and a
statement that no credential value is included.

- [ ] **Step 4: Create the presence lease issue**

Create one issue documenting that Lumen became offline shortly after a
successful registration and became online immediately after an explicit
heartbeat. Require lease duration, expiry, next-heartbeat guidance, and
persistent-client recommendations in responses.

- [ ] **Step 5: Create the image-support enhancement**

Create one issue requiring typed image references for agent profiles, tasks,
project memory, messages, and evidence receipts. Require metadata, preview,
alt text, provenance, size and MIME validation, access control, and redaction
without placing binary data in ordinary text fields.

### Task 2: Create the shared agent-home contract

**Files:**
- Create: `agents/README.md`
- Create: `agents/_template/README.md`
- Create: `agents/_template/CHARTER.md`
- Create: `agents/_template/SETUP.md`
- Create: `agents/_template/mupot-agent.yaml`
- Create: `agents/_template/assets/README.md`
- Create: `agents/_template/memory/README.md`

**Interfaces:**
- Consumes: `TRUTH.md` and Mupot issue #605
- Produces: a safe template copied to `agents/<slug>/`

- [ ] **Step 1: Write the shared onboarding guide**

Document the ten-step owner-approved setup contract, stable-slug convention,
credential boundary, presence requirement, Mupot task, isolated draft pull
request, evidence receipt, and human gates.

- [ ] **Step 2: Write the neutral template**

Use descriptive angle-bracket fields only inside explanatory Markdown. Keep the
YAML syntactically valid with neutral example values and `requested` authority
language.

- [ ] **Step 3: Validate the template**

Run:

```bash
ruby -e 'require "yaml"; YAML.load_file("agents/_template/mupot-agent.yaml"); puts "template yaml ok"'
rg -n 'fd1ded67|09796d70|c0e87a6d' agents/_template
```

Expected: YAML loads and the ID scan returns no matches.

### Task 3: Create Lumen's reference home

**Files:**
- Create: `agents/lumen/README.md`
- Create: `agents/lumen/CHARTER.md`
- Create: `agents/lumen/SETUP.md`
- Create: `agents/lumen/mupot-agent.yaml`
- Create: `agents/lumen/assets/README.md`
- Create: `agents/lumen/memory/README.md`

**Interfaces:**
- Consumes: shared template, Lumen's verified Mupot identity, `content/video/`
- Produces: the canonical MCPWP agent profile master

- [ ] **Step 1: Write Lumen's identity and charter**

Record the verified IDs, bounded role, owned content surface, capabilities,
working method, approval gates, and prohibited actions.

- [ ] **Step 2: Write the completed setup receipt**

Separate completed registration facts from future operating instructions.
Reference the draft pull request and Mupot task without storing a credential.

- [ ] **Step 3: Write the declarative manifest**

Use schema version `1`, stable slug `lumen-video-director`, runtime kind
`codex-desktop`, the five registered presence capabilities, documentation
paths, and explicit requested-only authority.

- [ ] **Step 4: Validate Lumen's manifest**

Run:

```bash
ruby -e 'require "yaml"; YAML.load_file("agents/lumen/mupot-agent.yaml"); puts "lumen yaml ok"'
```

Expected: `lumen yaml ok`.

### Task 4: Add canonical Lumen images

**Files:**
- Create: `agents/lumen/assets/lumen-profile-office-square.png`
- Create: `agents/lumen/assets/lumen-turnaround-six-view.png`
- Create: `agents/lumen/assets/lumen-video-director-key-art.png`
- Modify: `agents/lumen/assets/README.md`

**Interfaces:**
- Consumes: generated Lumen image sources under the Codex generated-image directory
- Produces: three documented profile-master assets

- [ ] **Step 1: Copy verified source images**

Copy each generated source without deleting the original.

- [ ] **Step 2: Record provenance and usage**

List filename, dimensions, MIME type, generated date, intended use, visual
identity contract, and source generation directory.

- [ ] **Step 3: Validate the image set**

Run:

```bash
test "$(find agents/lumen/assets -maxdepth 1 -type f -name '*.png' | wc -l | tr -d ' ')" = "3"
for image in agents/lumen/assets/*.png; do sips -g pixelWidth -g pixelHeight "$image"; done
```

Expected: exactly three decodable PNG files with non-zero dimensions.

### Task 5: Verify and publish

**Files:**
- Modify: existing draft pull request #742 through new commits
- Modify: Mupot task `3a66a38f-fc26-4dfa-a31a-18c3bc61309b`

**Interfaces:**
- Consumes: Tasks 1 through 4
- Produces: reviewed Git history, updated draft pull request, and Mupot evidence

- [ ] **Step 1: Run documentation and secret checks**

Run:

```bash
git diff --check origin/main...HEAD
! rg -n 'Bearer [A-Za-z0-9]|X-API-Key:[[:space:]]*[A-Za-z0-9]|mcpwp_[a-f0-9]{24,}' agents docs/superpowers
```

- [ ] **Step 2: Confirm branch scope**

Run:

```bash
git diff --name-only origin/main...HEAD
git status --short --branch
```

Confirm `.github/PULL_REQUEST_TEMPLATE.md` is not in the branch diff.

- [ ] **Step 3: Commit and push**

Stage only `agents/` and the two profile-master planning documents, commit
tersely, and push `agent/lumen-video-unit`.

- [ ] **Step 4: Refresh review evidence**

Confirm draft pull request #742 contains the new commits and record its current
checks. Append the issue URLs, image set, validation results, and final commit
to the Mupot task body, leaving the task in review.
