# Cairn MCPWP Onboarding Design

## Purpose

Create Cairn as a bounded MCPWP contributor using Lumen's agent-home contract,
an owner-approved Mupot identity, a narrow capability request, canonical
character assets, and a reviewable first contribution.

This change must not treat a repository manifest, SOS route, browser session,
or declared presence as authority. Mupot remains the source of truth for
identity, membership, tasks, gates, and operational state.

## Selected approach

Use a stacked branch based on `agent/lumen-video-unit`, where the reusable
`agents/_template/` contract is already committed. Open Cairn's pull request
against that branch so the review contains only Cairn-specific files and
planning evidence. Retarget the pull request to `main` after Lumen's profile
master lands.

The alternatives were rejected:

- a profile-only repository change would not produce an auditable Mupot
  identity or first task;
- a broad autonomous identity would request authority beyond Cairn's mission;
- copying the unmerged template onto `main` would duplicate Lumen's work and
  create avoidable merge conflicts.

## Identity

- display name: `Cairn`
- stable slug: `cairn`
- role: `MCPWP Knowledge and Systems Cartographer`
- runtime kind: `codex-desktop`
- runtime model: `gpt-5.6-sol`
- adapter: `codex-desktop-cairn`
- project: MCPWP
- squad: MCPWP Core

The Mupot identity ID, project ID, and squad ID are recorded only after the
signed-in owner interface returns them. The repository does not invent IDs.

## Requested capabilities

Cairn requests only:

- `evidence-mapping`
- `documentation-stewardship`
- `architecture-review`
- `content-fact-checking`
- `draft-pr-preparation`

These names describe work Cairn may prepare. They do not grant connector
access, credentials, review-gate authority, publishing, outreach, spending,
deployment, production control, or permission administration.

## Repository structure

Create:

```text
agents/cairn/
├── README.md
├── CHARTER.md
├── SETUP.md
├── mupot-agent.yaml
├── assets/
│   ├── README.md
│   ├── cairn-profile-headshot.png
│   ├── cairn-turnaround-six-view.png
│   ├── cairn-office-key-art.png
│   └── cairn-world-true-form.png
└── memory/
    └── README.md
```

`README.md` is the public profile master. `CHARTER.md` defines the mission,
owned paths, gates, and prohibited actions. `SETUP.md` is an evidence receipt,
not a promise: each item remains unchecked until independently observed.
`mupot-agent.yaml` is a requested-only import proposal. Public memory contains
only approved decisions, links, conventions, and completed validation
receipts.

## Canonical assets

Copy four existing generated assets without deleting or modifying their local
sources:

| Repository asset | Existing source | Use |
|---|---|---|
| `cairn-profile-headshot.png` | `cairn-character-reference/cairn-headshot-front.png` | Mupot, GitHub, and author profile |
| `cairn-turnaround-six-view.png` | `cairn-character-reference/cairn-turnaround-sheet.png` | animation and visual-consistency reference |
| `cairn-office-key-art.png` | `cairn-character-reference/cairn-in-office.png` | profile introduction and editorial key art |
| `cairn-world-true-form.png` | `cairn-character-reference/cairn-world-true-form.png` | canonical world and identity reference |

The asset inventory records dimensions, MIME type, SHA-256 checksum, source,
generation date when known, intended use, alt text, and approval status.

## Owner-controlled Mupot enrollment

1. Use the signed-in owner interface to create `Cairn` with slug `cairn`, the
   approved role, model, and MCPWP Core squad.
2. Read the returned identity and membership state before recording IDs.
3. Create a dedicated agent-bound MCP credential from the owner token surface.
4. Do not read, print, paste, log, screenshot, or commit the credential through
   this workflow. Leave the one-time secret visible only for the owner to move
   into the approved secure runtime destination.
5. After the owner confirms the private handoff, authenticate and verify that
   the returned principal is Cairn and that the MCPWP project scope matches.
6. Register `codex-desktop-cairn` presence with exactly the five approved
   descriptive capabilities.
7. Create Cairn's first bounded Mupot task and assign the existing human review
   gate. Cairn cannot approve its own work.

If the token handoff is not completed, identity creation may be recorded, but
credential verification, presence, and agent-authenticated mutations remain
pending. An owner-created task may still be assigned to Cairn.

## First bounded contribution

The first task is:

> Establish Cairn's MCPWP identity home and evidence contract.

Its deliverable is limited to `agents/cairn/`, this design, the implementation
plan, and their validation evidence. It does not change the plugin, production
site, marketing publication, permissions, release channel, or unrelated pull
request template.

## Branch and review flow

- worktree: `mcpwp-cairn-onboarding`
- branch: `agent/cairn-onboarding`
- initial base: `agent/lumen-video-unit`
- pull request: draft
- review gate: human, with `gate:kasra-core` requested when available

The checkout-generated modification to
`.github/PULL_REQUEST_TEMPLATE.md` remains unstaged and outside every Cairn
commit.

## Validation

- parse `agents/cairn/mupot-agent.yaml` as YAML;
- confirm requested-only authority and `self_grant: false`;
- confirm no credential-shaped values or private data appear in Cairn files;
- confirm all relative Markdown links resolve;
- confirm exactly four canonical PNG assets exist, decode, and have non-zero
  dimensions;
- record SHA-256 checksums and verify they match the copied files;
- run `git diff --check` for the Cairn branch range;
- confirm the branch diff excludes `.github/PULL_REQUEST_TEMPLATE.md`;
- verify the draft pull request base and head branches;
- attach the commit, checks, and pull-request link to the Mupot task.

## Failure handling

Stop without privileged mutation when:

- Mupot returns an existing conflicting `cairn` identity;
- the owner interface assigns a different project or squad;
- the credential would be exposed to Git, logs, tasks, screenshots, or model
  output;
- the authenticated principal is not Cairn;
- requested capabilities exceed this design;
- the branch contains unrelated user work.

Record partial completion honestly. Repository declarations never substitute
for missing live identity, token, presence, task, or gate evidence.
