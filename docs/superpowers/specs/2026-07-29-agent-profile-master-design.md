# MCPWP Agent Profile Master Design

## Purpose

Make Lumen the reference MCPWP agent home while leaving a safe, repeatable
setup path for every future agent.

## Repository structure

The repository owns two complementary surfaces:

- `agents/README.md` explains the shared onboarding and governance contract.
- `agents/_template/` is copied when a new durable agent home is proposed.
- `agents/lumen/` is the first complete reference implementation.
- `content/video/` remains the operational production surface for briefs,
  scripts, Remotion, OBS, distribution, and evidence receipts.

An agent home contains identity, charter, setup instructions, a declarative
Mupot import proposal, public memory rules, and canonical visual assets. It
does not duplicate operational content.

## Agent setup contract

1. Read `TRUTH.md`, `agents/README.md`, and the project operating rules.
2. Choose a stable lowercase slug, display name, role, and bounded
   capabilities.
3. Have a project owner create or approve the Mupot identity and project or
   squad membership.
4. Mint an agent-bound MCP credential through an owner-controlled flow and
   store it outside the repository.
5. Verify the authenticated identity and project access before mutation.
6. Register project presence with a unique adapter and explicit capabilities.
7. Copy `agents/_template/` to `agents/<slug>/` and complete every field.
8. Open a bounded Mupot task and an isolated draft GitHub pull request.
9. Attach validation and evidence to the task before requesting review.
10. Keep publishing, outreach, spending, production control, permissions, and
    commercial claims behind human gates.

## Manifest authority boundary

`mupot-agent.yaml` is a proposal. It may describe identity, role, documentation
paths, runtime kind, squad hint, and requested capabilities. It cannot grant
RBAC, connector access, credentials, gates, production authority, publishing
authority, or spending authority.

Generated `.github/agents/**` execution profiles are outside this change.
Mupot issue #605 defines those as Mupot-owned exports.

## Lumen profile master

Lumen's home records:

- display name: Lumen
- stable slug: `lumen-video-director`
- role: MCPWP AI Video Director
- Mupot agent ID: `fd1ded67-9c03-42e5-946e-72609e2ad059`
- MCPWP project ID: `09796d70-1d87-46d7-ac48-adc0551abab7`
- MCPWP Core squad ID: `c0e87a6d-77de-4cf8-89a3-79e5843cdd30`
- owned operational surface: `content/video/`
- approval boundary: humans approve publication, outreach, spending, live OBS
  control, permissions, and product or commercial claims

## Canonical visual assets

The Lumen home stores three generated PNG files:

- `lumen-profile-office-square.png`: square avatar and profile image
- `lumen-turnaround-six-view.png`: white-background animation model sheet
- `lumen-video-director-key-art.png`: full-body video-director identity art

`assets/README.md` identifies each file's intended use, generation date, visual
contract, and source provenance. Images contain no credentials or operational
evidence.

## Mupot issue evidence

The implementation records live issues in `Mumega-com/mupot`:

- a regression report for project tasks mirroring into the wrong repository,
  referencing closed issue #319 and project-binding issue #605;
- onboarding evidence on open issue #528 for the browser and token-binding
  round trip;
- a presence lease issue for implicit heartbeat expiry and missing lease
  metadata;
- an image-support enhancement for agents, tasks, project memory, messages,
  and evidence receipts.

Each report separates observed behavior from expected behavior and avoids
including credentials.

## Validation

- every Markdown and YAML reference resolves to a repository path;
- the template contains no Lumen-specific IDs;
- the Lumen manifest contains no credential or self-granted authority;
- exactly three canonical PNG assets exist and can be decoded;
- secret and placeholder scans pass;
- the existing unrelated `.github/PULL_REQUEST_TEMPLATE.md` modification
  remains outside the branch diff.
