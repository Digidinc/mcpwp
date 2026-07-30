# MCPWP Video Marketing Unit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a governed, repository-native operating surface for MCPWP video production.

**Architecture:** Store production artifacts under `content/video/`, separated
by responsibility. Keep product code, credentials, live OBS state, and
publishing authority outside this surface.

**Tech Stack:** Markdown, Git, Mupot MCP, Remotion conventions, OBS scene specifications

## Global Constraints

- Lumen remains draft-only.
- Publishing, outreach, spending, live recording control, and product claims require human approval.
- No credentials, customer data, OBS profiles, or binary media enter this change.
- Do not stage the unrelated `.github/PULL_REQUEST_TEMPLATE.md` modification.

---

### Task 1: Create the operating surface

**Files:**
- Create: `content/video/README.md`
- Create: `content/video/agents/LUMEN.md`
- Create: `content/video/briefs/TEMPLATE.md`
- Create: `content/video/scripts/TEMPLATE.md`
- Create: `content/video/remotion/README.md`
- Create: `content/video/obs/README.md`
- Create: `content/video/assets/README.md`
- Create: `content/video/distribution/README.md`
- Create: `content/video/evidence/RECEIPT-TEMPLATE.md`

**Interfaces:**
- Consumes: a Mupot task with project, squad, owner, acceptance criteria, and gate.
- Produces: a brief-to-evidence workflow that later Remotion and OBS work can follow.

- [ ] **Step 1: Add the directory README and templates**

Create the nine files listed above. Each file must state its owner, inputs,
outputs, and approval boundary.

- [ ] **Step 2: Verify the structure**

Run:

```bash
find content/video -type f | sort
```

Expected: exactly the nine intended Markdown files.

- [ ] **Step 3: Scan for unsafe placeholders and credentials**

Run:

```bash
rg -n 'TBD|TODO|Bearer |X-API-Key|mcpwp_[a-f0-9]{24,}' content/video
```

Expected: no matches.

- [ ] **Step 4: Review the staged scope**

Run:

```bash
git status --short
git diff --check
git diff --cached --stat
```

Expected: only `content/video/` files are staged for the implementation commit;
the existing pull-request template modification remains unstaged.

- [ ] **Step 5: Commit**

```bash
git add content/video
git commit -m "docs: establish MCPWP video unit"
```

### Task 2: Publish governed review artifacts

**Files:**
- Modify: no source files

**Interfaces:**
- Consumes: the committed branch and Mupot task `3a66a38f-fc26-4dfa-a31a-18c3bc61309b`.
- Produces: a pushed branch, draft pull request, and Mupot task update containing the PR URL.

- [ ] **Step 1: Push the isolated branch**

```bash
git push -u origin agent/lumen-video-unit
```

- [ ] **Step 2: Open a draft pull request**

Target `Mumega-com/mcpwp:main`. The body must describe the new operating
surface, governance boundary, Mupot identity, validation, and the known Mupot
task-mirroring defect.

- [ ] **Step 3: Update Mupot**

Use Mupot MCP `task_update` to move the task to `review` and include the draft
pull-request URL in the task body. Register a project memory containing the
branch, commit, PR, and validation result.

