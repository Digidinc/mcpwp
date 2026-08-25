# MCPWP Agent Homes

This directory contains durable, reviewable homes for agents that contribute to
MCPWP. An agent home explains who the agent is, what it owns, how it connects to
Mupot, and where its public evidence belongs.

Agent homes do not contain credentials, private prompts, customer data, stream
keys, or self-granted authority.

The current four-role team and its live Mupot registration evidence are in
[`MCPWP-SQUAD.md`](./MCPWP-SQUAD.md).

## Setup a new agent

1. Read [`TRUTH.md`](../TRUTH.md) and the project's operating documentation.
2. Choose a stable lowercase slug using letters, numbers, and hyphens.
3. Copy [`_template/`](./_template/) to `agents/<slug>/`.
4. Complete the identity, charter, setup, asset, memory, and manifest files.
5. Have a project owner create or approve the Mupot identity.
6. Have the owner approve project and squad membership plus every requested
   capability.
7. Mint an agent-bound MCP credential through an owner-controlled flow. Store
   it outside Git and never paste it into issues, tasks, logs, screenshots, or
   project memory.
8. Verify the authenticated identity and project scope before mutation.
9. Register project presence with a unique adapter name and bounded capability
   list.
10. Create a Mupot task for the first contribution, work from an isolated Git
    branch, and open a draft pull request with validation evidence.
11. Move the task through the required review gate. The agent cannot approve
    its own work.

## Authority boundary

The `mupot-agent.yaml` file in each home is a declarative import proposal. It
can request identity, project placement, and capabilities. It cannot grant:

- RBAC or squad membership;
- connector or credential access;
- approval-gate authority;
- publishing or outreach authority;
- spending authority;
- production or live-control authority.

Generated `.github/agents/**` execution profiles remain Mupot-owned outputs and
are not created by this template. See
[Mupot #605](https://github.com/Mumega-com/mupot/issues/605).

## Human gates

Humans approve publication, outreach, spending, permissions, live production
control, and product or commercial claims. An agent may prepare evidence and
request review, but it cannot treat a draft, test, or local render as published
truth.

## Reference implementation

[Lumen](./lumen/) is the first complete profile master. It demonstrates a
verified Mupot identity, bounded charter, completed setup receipt, canonical
image set, public-memory rules, and links to its operational video surface.
