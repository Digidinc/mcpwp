# TRUTH — read this first

> The single entrypoint for this project. If you are an agent (or a human) starting work,
> read this before spelunking. **Truth lives once per topic; everything else links here, never copies.**
> Drift between two docs on the same fact is a **bug** — fix it by deleting the copy and pointing at the source.
>
> **Last updated:** 2026-07-22 · maintained under [`Digidinc/mcpwp#687`](https://github.com/Digidinc/mcpwp/issues/687)

---

## The two projects (do not confuse them)

| | **mumcp** | **mcpwp** |
|---|---|---|
| Is | the MCP **product/company** — "MCP for platforms" (WordPress first) | the **media publication** — MCP × WordPress × AI |
| Domain | mumcp.com | mcpwp.net |
| This repo? | **yes** (`Digidinc/mcpwp` — the product plugin; repo rename → `mumcp` pending) | no → [`Mumega-com/mumega-motion-theme`](https://github.com/Mumega-com/mumega-motion-theme) |

Full map: **[`docs/PROJECT-TOPOLOGY.md`](docs/PROJECT-TOPOLOGY.md)**. The plugin historically named "MCPWP" **is** mumcp.

---

## Where each truth lives (canonical source per topic)

| Topic | Canonical source |
|---|---|
| Project topology (products, domains, repos, roles) | [`docs/PROJECT-TOPOLOGY.md`](docs/PROJECT-TOPOLOGY.md) |
| Decisions / why things are the way they are | [`docs/DECISIONS.md`](docs/DECISIONS.md) (ADR log) |
| Naming (canonical identifiers, wire contract) | [`docs/NAMING.md`](docs/NAMING.md) + DECISIONS ADR-001 |
| Strategy (the economy, currency, roadmap) | [`docs/STRATEGY.md`](docs/STRATEGY.md) |
| Architecture (extension model) | [`docs/adr/0001-extension-architecture.md`](docs/adr/0001-extension-architecture.md) |
| Plugin internals (endpoints, hooks, options, arch) | root [`CLAUDE.md`](CLAUDE.md) |
| Ops / tasks / who-owns-next (governance spine) | **mupot** (`mupot.mumega.com/mcp`) — not a doc |
| Editorial content brief (the media site) | live WP option `mcpwp_site_context` on mcpwp.net |
| OAuth / connecting AI clients (what the plugin does today) | [`docs/OAUTH-CONNECT.md`](docs/OAUTH-CONNECT.md) |
| OAuth broker (mumcp.com sign-in) | [`docs/superpowers/specs/2026-07-20-mumcp-oauth-broker-design.md`](docs/superpowers/specs/2026-07-20-mumcp-oauth-broker-design.md) |

If a topic is not listed, it does not yet have a canonical home — **give it one here before writing about it twice.**

---

## Three kinds of truth, three homes

- **Product / project** (naming, topology, strategy, architecture, decisions) → **git** (this table).
- **Ops / tasks** (work state, ownership) → **mupot** (already canonical; do not mirror task-of-record into git or WordPress).
- **Editorial content** (the media KB) → **WordPress** categories on mcpwp.net.

Do not conflate them. A task's state belongs in mupot, not a markdown checklist. A naming fact belongs here, not in five docs.

---

## Discipline

1. One canonical source per topic. Add it to the table above.
2. Everywhere else, **link** — never paste a copy that will drift.
3. Two docs disagreeing on one fact = a bug. Delete the stale copy, point at the source.
4. New agents: read this file, then the one doc your task names. Do not reconstruct the picture from scratch.
