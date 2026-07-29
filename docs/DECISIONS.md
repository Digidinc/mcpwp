# Decisions (ADR log)

> Why things are the way they are. Append-only: supersede, don't rewrite history.
> Referenced by [`/TRUTH.md`](../TRUTH.md). One entry = one decision, dated, with what it supersedes.

---

## ADR-003 — Single place of truth (discipline)
**Date:** 2026-07-22 · **Status:** accepted

**Context.** Project facts were spread across STRATEGY.md, REBRAND-PLAN.md, NAMING.md, squad docs, mupot docs, the live brand-crystal, agent memory, and the pot API. Every session re-derived the picture and drifted (the MCPWP-vs-mumcp naming confusion was live drift). No canonical entrypoint existed.

**Decision.** Establish `/TRUTH.md` as the single entrypoint + a per-topic canonical-source table. Truth lives once; everything else links. Two docs disagreeing on one fact is a bug — delete the copy, point at the source.

**Consequences.** New agents read TRUTH.md first. Later: index TRUTH.md into mupot squad memory for `recall` (once mupot's Postgres memory backend is restored — flagged down in mupot `STATE-AND-STRATEGY.md`). Tracking: `Mumega-com/mcpwp#687`.

---

## ADR-002 — WordPress as a mupot control-surface, not a second brain
**Date:** 2026-07-22 · **Status:** accepted (design; build under #689)

**Context.** We want mcpwp.net to be run by governed AI agents as a live showcase. Naive approach: build the PM/RBAC/task system inside WordPress. mupot's own doctrine forbids this: *"the runtime governs; the tool stays a tool — don't build the OS into the tool"* — and it duplicates mupot's in-flight `v0.24.0 Project Operations`.

**Decision.** WordPress becomes a **mupot connector that happens to be a full site** — a web-login control surface, peer to Telegram(Hermes) and the console (mupot docs already name "a web login" as such a peer). Two planes:
- **mumcp plugin** = the hands (239+ MCP tools, control *of* WordPress).
- **mupot connector** = the authority (identity, capabilities, gates, receipts).

Flow: mupot capability check → authorizes → mumcp tool executes → receipt. The connector **routes to** mumcp tools through the gate; never reimplements them. Identity server-derived; no escalation.

**Guardrails.** WordPress holds **no** task-of-record and **no** capability-of-record — it reads/writes mupot. Drift into duplicating RBAC/tasks in WP = forked the brain = the failure mode. Ops surface on the public site stays behind WP auth + mupot capability gate.

**Consequences.** First slice is read-first (task board in wp-admin). Connector likely lives in the mupot repo (`connectors/` / runtime-adapter). Needs Athena on the adapter contract. Tracking: `Mumega-com/mcpwp#689`.

---

## ADR-001 — Project + brand split: mumcp (product) vs mcpwp (media)
**Date:** 2026-07-22 · **Status:** accepted · **Supersedes:** the "everything → MCPWP" direction of `REBRAND-PLAN.md` (2026-06-09, shipped v3.0.0)

**Context.** `REBRAND-PLAN.md` hard-renamed everything → MCPWP on one stated premise: *"there is NO external contract — only our own worker + sites consume the surface."* That premise **expired**: wp.org subsequently accepted the free plugin under permanent slug **`mumega-mcp`** (display `mumcp`, donate → mumcp.com), and Google brand equity accrued to "mumega mcp." A wp.org slug is a permanent, hard external contract that did not exist when v3.0.0 was decided. Meanwhile "MCPWP" ambiguously named both a plugin and a media domain (mcpwp.net).

**Decision.**
1. **mumcp** = the MCP product/company (WordPress plugin + broker + future platforms), brand `mumcp` / formal `mumega-mcp`, home mumcp.com.
2. **mcpwp** = the media publication, home mcpwp.net. Separate project, separate repos.
3. **Move only the PUBLIC brand → mumcp** (readme H1, Plugin Name header, display strings, Freemius slug, marketing). **FREEZE the internal wire contract** — `Mcpwp_` classes, `mcpwp/v1` REST namespace, `mcpwp_` prefix, DB tables stay. wp.org polices only slug/text-domain/readme, not these; re-churning a live client contract for zero brand gain violates durability-over-churn.

**Consequences.** ~95% of brand consolidation for ~5% of churn. Repo rename `Mumega-com/mcpwp` → `mumcp` pending; dedicated repo for mcpwp.net media pending. Superseding note to be appended to `REBRAND-PLAN.md`. Full scope + out-of-scope: `Mumega-com/mcpwp#688`.

---

## ADR-004 — One MCPWP publication entrypoint in the theme repository
**Date:** 2026-07-29 · **Status:** accepted · **Supersedes:** ADR-001's
undecided “content/ops own repo pending” destination

**Context.** The MCPWP publication already has its reusable theme, homepage
designs, strict editorial contract, formats, roles, rules, and generated agent
context in `Mumega-com/mumega-motion-theme`. Public content and revisions live
in WordPress; task state and approvals belong in Mupot. Creating another
documentation repository would add a fourth copy of the same strategy and
recreate the drift ADR-003 prohibited.

**Decision.** `Mumega-com/mumega-motion-theme/TRUTH.md` is the single entrypoint
for the MCPWP publication. Its canonical content operating system owns the
market loop, content graph, partnerships, conversion paths, cadence, and
measurement design. The theme repository continues to own the reusable
editorial contract and presentation implementation. WordPress remains the
public-content source of truth; Mupot remains the work and approval
source-of-truth. This MumCP product repository links to that entrypoint and does
not mirror the publication strategy.

**Consequences.** Agents can enter the publication from one graph without
confusing MCPWP media work with MumCP product/release work. A future repository
split may move the entrypoint only through a new ADR and link-preserving
migration; it must not create a second active copy.
