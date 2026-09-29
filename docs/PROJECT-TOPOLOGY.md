# Project topology

> Canonical map of what is what. Referenced by [`/TRUTH.md`](../TRUTH.md).
> **Last updated:** 2026-07-22 · decision recorded in [`DECISIONS.md`](DECISIONS.md) ADR-001.

## The two projects

There are **two separate projects** that accidentally shared the name "MCPWP." They are now split.

| | **mumcp** | **mcpwp** |
|---|---|---|
| **What it is** | the MCP **product / company** — "MCP for platforms" (WordPress is the first platform; others can follow) | the **media publication** — the intersection of MCP, WordPress, and AI |
| **Domain** | mumcp.com — product, docs, sell, OAuth broker | mcpwp.net — editorial (V2 / ASTER) |
| **Primary repos** | `Digidinc/mcpwp` (the product plugin — rename → `mumcp` pending), `Mumega-com/mumcp-broker`, future `mumcp-<platform>` | `Mumega-com/mumega-motion-theme` (theme) + mcpwp.net content/ops (own repo) |
| **GitHub** | its own repos | its own repos — **not shared with mumcp** |
| **pot project** | existing project (slug `mumcp`) — rescope to the product line | **new** media project (pending; needs admin capability) |

The WordPress plugin historically branded "MCPWP" **is mumcp** (the product). The site "mcpwp.net" **is mcpwp** (the media).

## Naming reality (see NAMING.md + ADR-001)

- **Public product brand:** `mumcp` (formal wp.org slug `mumega-mcp`; short brand `mumcp`; Google equity on "mumega mcp").
- **Internal code (frozen, do NOT re-churn):** `Mcpwp_` classes, `mcpwp/v1` REST namespace, `mcpwp_` hooks/options/prefix, DB tables. These are a live wire contract to connected clients + our worker; internal codename ≠ public brand is normal and intentional.
- **Media brand:** `mcpwp` (domain mcpwp.net).

## The dogfood relationship

mcpwp.net (media) runs on WordPress, **operated by the mumcp plugin** (installed on it), **governed by mupot**. So the media site is itself the live demo:

```
mcpwp.net (the operated WordPress media site)
   ├── mumcp plugin  → the HANDS (239+ MCP tools, deep control of WordPress)
   └── mupot         → the AUTHORITY (identity, capabilities, gates, receipts)
```

This proves both theses at once: **MCP gives WordPress new power** (the site runs itself via MCP) and **WordPress can be governed/secure** (the RBAC + approval + audit layer is visible). Architecture for the governed WordPress control-surface: [`DECISIONS.md`](DECISIONS.md) ADR-002 + [`Digidinc/mcpwp#689`](https://github.com/Digidinc/mcpwp/issues/689).

## Related systems (not products)

- **mupot** — the AI operations layer / governed runtime (`/home/mumega/mupot`, deployed `mupot.mumega.com`). The governance spine for all of the above. Canonical for ops/tasks/identity.
- **Hermes** — IM (Telegram) relay connector into mupot; the "mouth & ears."
- **mumega-motion** — the reusable editorial WordPress theme powering mcpwp.net (generic; site-specific content lives in WP, not the theme).
