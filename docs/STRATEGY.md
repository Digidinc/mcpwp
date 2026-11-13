# MCPWP / mupot — Strategy (canonical single source)

> **The one place the whole picture lives.** `BACKLOG.md` is the task tracker; `docs/PRICING_TIERS.md` is
> the taxation/numbers detail; the [mupot repo](https://github.com/Mumega-com/mupot) is the substrate code.
> When those disagree with this doc, this doc is the intent.
>
> **Last updated:** 2026-07-21 (§12 added — see below; OAuth keystone status corrected).

---

## 0. The frame: it's an economy, not a product

We are not selling software. **We are designing and operating an economy**, and taking a cut of its flows.
The mistake in every earlier draft was pricing a *tool*. The right object is a **game economy** whose
currency is **coherence** and whose territory is **AI-attention**. We set the rules, we observe the whole
board, we tax the flows. The players, the agents, and the marketplace generate the value.

This reframe resolves everything we struggled with: the fork problem, the moat, the pricing. In an economy,
value concentrates in the *market + the attractor + the network + the observability* — **not in the code**.
So GPL forkability stops mattering: fork the code and you are still a player in our economy, or you are
outside it with no marketplace, no distribution gravity, no cross-site learning.

---

## 1. The board

| Player | Role | Pays in | Wins by |
|--------|------|---------|---------|
| **Business owners / teams** | consumers | money | revenue, growth, a business that runs itself |
| **Builders / devs** | producers | effort | addon + snapshot adoption and revenue |
| **Agents** (Hermes · openclaw · Codex · Claude · ChatGPT) | labor | compute | hitting KPIs |
| **Us (mumega)** | game master / operator | infra + curation | a cut of the flows + the deepening attractor |

Target the **owners**, not the agencies. Agencies fork and wrap their own CX — fine; in an economy a forking
agency is just a big player who still needs the market, the distribution gravity, and the learning. We make
the economy the place value concentrates, so we never have to lock the code.

---

## 2. The currency stack

- **Compute / tokens** = *energy* — the **sink**. Metered + hard-capped (mupot `cap_micro_usd` blocks before
  any model call would breach budget; nothing runs away).
- **Conversions / revenue** = *score* — the **faucet** that justifies the energy spend. On a Woo store this
  is literal dollars.
- **Coherence** = *level* AND *monetary policy*. Coherent basins get distributed; incoherent slop gets
  penalized. The originality/approval/coherence gate is the economy's **anti-inflation sink** — it burns junk
  so the currency holds value. (See §5 — it is also Google's safe harbor.)
- **Distribution / attention** = *territory* — Google News/Discover/Search real estate you capture.
- **E-E-A-T / reputation** = *trust gate* — earned, gates the territory.
- **Addons / skills** = *items* — atomic capabilities, third-party, unbounded.
- **Snapshots** = *recipes / loadouts* — curated **mixes of addons + skills + config** that form a deployable
  vertical. Tradeable as meta-items. **Performance-ranked by our observability** (see §4, §6).

---

## 3. The components (what the economy is made of)

| Component | In game terms | What it is |
|-----------|---------------|------------|
| **MCPWP** | the **basin** | The WordPress surface — the hands that make changes on the site. The biggest-market on-ramp. |
| **mupot** | the **rules engine** | Sovereign agent org on the customer's own Cloudflare: departments→squads→agents+humans, capability RBAC, and the metered/capped/**gated** loop runtime. |
| **Addons / skills** | **items** | Third-party capabilities — Telegram, WhatsApp, service bindings, tools. **Unlimited.** We curate + tax, we don't build them all. Primitives already exist: `mcpwp_register_tools` (MCPWP), `register_skill`/`list_skills`/`invoke_skill` (bus), squad-packs (mupot). |
| **Snapshots** | **recipes / loadouts** | A curated **mix of addons + skills + config** = a complete playable vertical (dropship store, news publisher, local-business). Tradeable; performance-ranked. |
| **The fleet** | **controllers / avatars** | Any BYO agent runtime the customer already pays for, joined to a pot over MCP. The funnel surface. |
| **Observability (PostHog per project)** | the **game-master's screen** | We alone see the whole board — every basin's state, every loop's performance, the aggregate. |

---

## 4. The flagship loop: the self-growing, self-converting site

The first vertical and the proof. A site that **acquires its own traffic and converts it**, governed. Two
coupled loops at different clock speeds:

```
LOOP A — DISTRIBUTION (acquire)     feedback: Search Console (Search/Discover/News)   clock: days–weeks
  publish → Google News/Discover/SEO picks it up → traffic in → what got picked up shapes next publish

LOOP B — CONVERSION (monetize)      feedback: PostHog + Woo wc-analytics + Clarity     clock: hours–days
  land traffic → A/B test (Cloudflare edge) → winner → more sales → what converts shapes next test
```

**Distribution access is open, not gated.** Google News is now **automatic** (no application since 2025) —
admission is a *compliance* problem, not a permission problem. And the compliance bar is exactly our design:

- The load-bearing risk is **scaled content abuse** (Google's #1 2026 enforcement priority; 50–80% traffic
  collapse). The safe harbor is "AI accelerating human expertise, never AI generating pages at scale."
- **Our governance IS the safe harbor:** `/approvals` = the editorial-oversight signal; `wp_audit_content_quality`
  + coherence = the originality gate (make it **blocking**); brand-crystal = niche coherence; cadence governor
  = no floods. **Coherence is the admission ticket AND the penalty shield.**

### Build list (the four missing pieces — most plumbing already exists)
- **(a) Distribution-eligible:** 48h rolling news sitemap · NewsArticle JSON-LD (offset-aware dates, headline
  ≤110, article-owned image, `author.url` to a real bio) · `max-image-preview:large` site-wide · 1200px 16:9
  lead images · author entities · HTTPS.
- **(b) Policy-safe:** hard non-bypassable originality gate · approval-first (have it) · niche + cadence governor.
- **(c) Close the loops:** GSC ingest (Search full dims; Discover/News = clicks/impressions/CTR by page only)
  · PostHog Query API (funnels) + Woo `wc-analytics` + Clarity export · **Cloudflare Worker edge A/B** →
  PostHog significance → promote winner via `/approvals`.
- **Plan around the gaps:** AI Overviews/AI-Mode performance is UI-only + impressions-only (no API yet);
  Discover/News have no query/position; Clarity API is 10 req/day, 3-day window. Don't architect dependencies
  on these.

### The two dogfoods (our niche is MCP · WordPress · WP plugins)
- **mcpwp.net — content + courses (do this FIRST, it's the safest).** The system publishes in our own lane
  (MCP/WP/plugins) where *we are the domain expert* → real E-E-A-T, **zero slop risk** (the #1 distribution
  risk disappears when you actually know the subject). It also hosts **LearnPress video courses teaching
  MCPWP** — dogfooding LearnPress, doubling as onboarding (cuts support, lifts activation), and feeding the
  funnel. "MCPWP runs MCPWP's own education + marketing site" is the cleanest possible proof of the
  distribution loop.
- **A Woo dropship/arbitrage store — the commerce dogfood.** Proves the *conversion* loop in dollars, under
  caps, our-risk. Second, because commerce content carries more slop/policy risk than our own expertise.

---

## 4b. Cadence + agent representation (the governance layer)

**Two-speed agents — the energy discipline.** The economy runs the energy sink on two clocks:
- **Continuous + cheap:** small agents (Haiku-class) run frequently on the interval — *perceive* (pull
  analytics + Search Console), do small tasks, and **log what they did**.
- **Periodic + expensive:** one expensive agent (Opus-class) runs **once per interval** — reads the data
  gathered *and* what the small agents already did → makes the strategic decision → dispatches the next work,
  then sleeps. A warm-cache burst, not an always-on drain.

Why: Opus-continuous bleeds the sink. **Cheap-continuous perception + expensive-periodic decision** is the
cost-optimal shape of the coherence loop and the only way a loop stays net-positive (faucet > sink). This is
mupot's flight/loop split, made explicit.

**Agent representation — declared identity, scope, and goal.** When an agent connects over MCP it **declares
itself** through a short start-up questionnaire returning `{ identity, represents, scope_of_work, goal }`. We
accept the agent's representation and bind it. Three payoffs:
- **Owner-facing Fleet page (MCPWP admin):** the owner sees *every* connected agent — identity, declared
  scope, goal, status (active/idle), and what it has done. The site is never operated by anonymous access.
- **Second security layer:** the declared scope becomes an **enforcement boundary on top of the API key** — an
  agent may act only within what it declared; deviation is flagged + gated. Intent is on record → a real
  audit story (EU AI Act). *Auth answers "allowed in?"; representation answers "who are you, what will you do,
  did you stay in bounds?"*
- Maps to mupot's `boot_context` + `check_in` + capability RBAC — brought into MCPWP itself so it works even
  without a full pot.

---

## 5. The moat: three compounding network effects + the all-seeing screen

1. **Marketplace:** more builders ↔ more owners (two-sided).
2. **Attractor (the basin):** more coherent sites → stronger signal in the AI training field → models get
   fluent at the pattern → the platform works better → more sites. **Data-gravity. Non-forkable** — you can
   fork code, not the gravity well the models converged on.
3. **Learning:** more loops → more "what converts / what gets distributed" data → smarter optimization →
   better results → more loops.
4. **Observability:** PostHog per project means we hold the **aggregate** no single player has — the balancing
   lever (curate, surface, police abuse) and an informational moat (we can performance-rank every addon-mix).

---

## 6. Revenue = taxing the economy (not selling software)

Platform-shaped, not vendor-shaped. Detail + numbers in `docs/PRICING_TIERS.md`.

- **Marketplace cut** on third-party **addons + snapshots** (App-Store model). Scalable — we don't build them.
- **Performance-ranked snapshot premium** — data-validated loadouts ("converts at X% across N stores") sell
  for more; we take a larger cut on the proven recipes only our observability can certify.
- **Metered energy** — compute passes through at-cost / BYOK, hard-capped; we never profit on tokens.
- **Infra rent** — hosted bus, distribution pipes, observability.
- **The on-ramp toll** — MCPWP per-site license (cheap, adoption-priced; AI Engine $59/yr precedent) + official
  build + support. The wedge is cheap *so the economy can be valuable.*

Marginal COGS per tenant ≈ $0 (their Cloudflare, their tokens). This stays a **software/IP-margin economy**:
sell flows + recipes + rent, never compute.

---

## 7. Failure modes (economy-design risks) + mitigations

| Risk | Failure | Mitigation |
|------|---------|-----------|
| **Inflation / spam** | unlimited addons + auto-content → junk + Google penalty | **Coherence gate = anti-inflation sink.** Quality is the currency-burn. (This is *why* the originality/approval gate is monetary policy, not overhead.) |
| **Net-negative loops** | compute cost > value created → players quit | Meter + value-measurement keep loops faucet > sink. The economy grows only if players profit. |
| **Cold-start** (two-sided liquidity) | no builders without owners, no owners without addons | **Be the first player:** our own addons + our own dogfood store. "Build our store, let it go crazy" *is* the bootstrap. |
| **Balance / abuse** | bad actors game the economy | Observability (PostHog everywhere) makes balancing possible; capability RBAC + gates constrain. |

---

## 8. Strategic objectives (must-be-trues)

1. **Design an economy, tax the flows** — never price the tool.
2. **Coherence is the currency and the anti-inflation mechanism** — it admits us to distribution and shields
   us from penalty. Hold it or the economy inflates into slop.
3. **The marketplace scales the capability set** — addons + snapshots others build; we curate + rank + tax.
4. **Observability is the edge** — PostHog per project; we hold the aggregate.
5. **Stay software/IP-margin** — BYOK + caps; token risk on the tenant; never a compute reseller.
6. **Bootstrap by being the first player** — our own dogfood store solves cold-start AND proves the loop.
7. **Validate before widening** — one loop lifting one real number on one real (our) site, first.

---

## 9. Roadmap (re-sorted to the economy)

| # | Version | Milestone | What it does for the economy |
|---|---------|-----------|------------------------------|
| **P0a** | current builds | **mcpwp.net dogfood — content + courses** | Our-niche (MCP/WP/plugins) content engine + LearnPress video courses teaching MCPWP. Safest first dogfood (real E-E-A-T, zero slop risk); proves the distribution loop + onboarding + cold-start. **Full execution spec: `docs/DOGFOOD-MCPWP-NET.md`.** |
| **P0b** | current builds | **Woo store dogfood — commerce loop** | Dropship/arbitrage store, governed auto-publish + edge A/B under caps → make real dollars. Proves the conversion loop. |
| **M1** | v2.8.x | **On-ramp launch** | Free plugin + cheap Pro (adoption toll). Admin UI ✅. Gates on Hadi: pricing (T87), privacy, install test, WAF. |
| **M1.3** | v2.9 | **Agent representation & Fleet** | Connect-time MCP questionnaire (`identity·represents·scope·goal`) → owner-facing admin Fleet page → scope-as-enforcement **second security layer** + audit. (§4b — sensitive surface: adversarial-gate the build.) |
| **M1.5** | v2.9 | **Distribution + feedback build** | News sitemap + NewsArticle schema + image/E-E-A-T emitters; GSC/PostHog/Woo ingestion; Cloudflare edge A/B engine; blocking originality gate; two-speed agent cadence (§4b). |
| **M2** | v2.9 | **Auth keystone** (broker OAuth, not per-site DCR; superseded by #1099, see §12 note) | Opens the OAuth fleet — ChatGPT Business + Claude Managed. Bearer fleet (Hermes/openclaw/Codex) connects now. **Built, not yet shipped** — see §12. |
| **M3** | v2.9 | **Distribution channels** | WP.org · MCP registries · ChatGPT GPT/App · Claude Connector. |
| **M4** | v3.x | **The marketplace** | Storefront for addons + snapshots; the cut; performance-ranking from observability; `mcpwp_register_tools` + `register_skill` made first-class. **This is the revenue engine.** |
| **M5** | v4.0 | **Composition layer** | Snapshots as curated addon-mixes; the recipe builder; data-validated loadouts. |
| **M6** | v5.0 | **Platform hardening** | `spai_`→`mcpwp_` rebrand + microkernel. |
| **M7+** | v6.x | **Scale** | More verticals (LearnPress, local-business), more channels, the full self-operating economy. |

**Sequencing — now / next / later:**
- **NOW:** the **dogfood store + flagship loop** (P0). Be the first player. Make a number move.
- **NEXT (v2.9):** distribution+feedback build + OAuth keystone + distribution channels.
- **LATER:** the marketplace (the revenue engine) → composition layer → scale.

**Binding constraint:** not engine width — it is **one loop lifting one real conversion number on our own
store.** Everything else is supporting cast until that happens.

---

## 10. Build-state discipline (sell only what's built)

| Capability | State | Earliest |
|-----------|-------|----------|
| On-brand content, SEO, content-graph, images, approvals/rollback, brand-crystal, Woo SEO, blueprints, keyword research, PostHog (outbound) | ✅ built | now |
| Metered/capped/gated loop, squad-packs, harness-pack contract, MCP ResourceRef seam, `mcpwp_register_tools`, bus `register_skill` | ✅ built | now |
| News sitemap + NewsArticle schema, image/E-E-A-T emitters, GSC/PostHog ingestion, edge A/B, blocking originality gate | 🔶 to build | v2.9 |
| Broker OAuth sign-in (claude.ai/ChatGPT → mumcp.com → any site), streamable-http on the bus | ✅ shipped (3.7.0); the site is also its own OAuth server since #1099 | now (pending human ship) |
| Marketplace storefront + cut + performance-ranking · snapshot composition layer | 🔶 primitives exist, not productized | v3.x–v4.0 |

Launch each promise on what is built. Later-phase items are "included as they ship," never live today.

---

## 11. The 2026-07-16 reframe — WordPress as fortress, mupot as the running business

Session-long strategy conversation (Hadi, 2026-07-16), extending §0's "we're an economy, not a product" one level further. Recorded here in full so it survives a context compact.

**The sharpest version of the reframe so far:** don't see WordPress. See an online engine that moves the needle in a real business, on any workflow that business already has — because the business can *expand from here*, instead of us making a plugin for WordPress. WordPress's actual moat was never pages — it's the thing everyone forgets it already solved: real, battle-tested **RBAC** and **60,000+ plugins** of payments/LMS/memberships/forms already built. Obsidian/flat-file/md-based tools never solved that (no roles, no capability system). So mupot doesn't rebuild that stack from scratch on a custom substrate — it runs *on top of* WordPress and activates what's already sitting there dormant. WordPress = **fortress**, not product.

**The sellable object changes shape.** Not "software you rent." A mupot pot navigating a real WooCommerce store + PostHog + ad optimization *is an actual running online business* — that's an **acquisition thesis**, not a SaaS pitch: sell the business itself to someone with capital who can't build it themselves. Binding constraint carries over unchanged from §9: this only means something once there's a **provable number** (real revenue, real ROAS, real growth) on one actual running loop — "acquisition target" is a story until then, not an asset.

**Distribution is one brain, many doors — not "post to the WP blog."** Same content fans out to every free/owned channel at once: social feed, podcast (NotebookLM → Spotify/YouTube), video, research papers, Android/iOS apps. No single channel is the product; WP is one execution surface among several the same brain feeds.

**Audience refinement:** people who already built their own second brain (Obsidian/Andrej-Karpathy-style personal knowledge systems) don't need another content tool — they need the engine that turns that brain into a running business. Sharpest underserved buyer identified so far.

### mumcp.com — MCP shop pivot (separate from the WP-specific play)

`mumcp.com` (domain bought for the wp.org `mumega-mcp` free listing) discussed as a broader MCP-server marketplace, not WP-only: mcpwp.net stays the WordPress-specific authority/content hub, mumcp.com becomes the storefront for MCP servers across tools (Strapi, others). Bardia's FormFlow-for-Strapi (existing traction) floated as first anchor listing — real proof-of-demand beats an empty shelf. **Open, unresolved:** revenue-share/listing terms with Bardia and any other third party, before the shelf has more than one listing.

**Competitive research (full detail + sourcing in BACKLOG.md "MCP marketplace distribution" entry):** of four adjacent agentic products researched (Codex, Claude Cowork, Microsoft Copilot Cowork, Perplexity/Comet), **Claude Cowork is the real competitive pressure** — same non-technical business-owner persona, same jobs-to-be-done (delegate business ops to AI) MCPWP already targets. Either MCPWP becomes a listed Cowork connector (ride Anthropic's distribution) or an independent MCP shop gets squeezed into a thin reseller layer if Anthropic's own directory becomes the default store for that buyer. Concretely reachable now, confirmed against our own code: MCPWP already has OAuth 2.1 authorize+token + well-known discovery (v3.2.0+); Dynamic Client Registration is genuinely missing but **not required** for either Claude Cowork Connectors Directory (static `oauth_anthropic_creds`) or OpenAI's Codex/ChatGPT plugin marketplace (predefined OAuth client) — both reachable without building DCR first. Custom-connector-by-URL in Claude already works today with zero backend changes.

### Hermes-as-admin — design sketch for the next real project

Handed a concrete project (2026-07-16): one domain fully on Cloudflare (Inkwell candidate, connects to mupot) + one WordPress domain ("our art canvas"), already wired to Freemius and Stripe. Design intent for the admin agent, if built as a Hermes flock member:

- **Scoped capability tokens per surface**, not one master credential — WP via MCPWP's existing API-key scopes, Cloudflare via the provider class already built, Freemius/Stripe read-only until a specific write is explicitly approved.
- **Approval gate on anything touching money or customer data** — reuse MCPWP's existing approval/rollback system, don't build a parallel one.
- **Audit trail on every action** across all four surfaces (WP, Cloudflare, Freemius, Stripe) in one place, extending the existing activity-log/event-store pattern.
- **Adversarial review in parallel** on sensitive surfaces (pricing, refunds, customer PII), per the existing agent-comms convention — not sequential, not skipped.
- **One literal admin agent, not a swarm** — avoids the exact coordination-deadlock failure mode the agent-comms doc already learned the hard way.

**Blocking, unresolved as of this writing:** no concrete access yet (domain names, Cloudflare zone ID/token, WP admin URL, Freemius product ID, Stripe account) — nothing above is executable until that's handed over. **"Inkwell" itself is still ambiguous** — is it a real, currently-running WordPress/Cloudflare instance to activate mumcp on, or the not-yet-built brand-brain product referenced in `docs/squad/GTM-DESIGN-THINKING.md` (`inkwell↔MCPWP SYNC` — brand brain → WP publish, separate MCP-to-MCP track)? Resolve before acting.

---

## 12. The M2 auth keystone — resolved as a broker, not per-site DCR (2026-07-21)

> **Superseded (#1099):** the plugin is now its own OAuth authorization server with Dynamic Client Registration, on by default; the mumcp.com broker is an optional second path. Current behaviour: `docs/OAUTH-CONNECT.md`. Statements below that say DCR is not built or that OAuth is broker-only describe the earlier state.

§11's open DCR question is answered by **not building DCR at all.** Instead: one OAuth broker
Worker on `mumcp.com` (repo `Mumega-com/mumcp-broker`) issues tokens; every MCPWP site validates
them via RFC 7662 introspection (`.well-known/oauth-protected-resource`, RFC 9728) instead of
running its own per-site OAuth server. This is a straight instance of §3's "mumcp.com = broader
MCP-shop pivot" — the broker makes mumcp.com the **identity layer** for the whole fleet, not just
a future storefront.

**Design constraints that came out of adversarial review (parallel gate per §4b's governance
pattern, not sequential):** a broker token is capped at read/write — **`admin` scope is never
grantable over OAuth**, only via a local API key. Local API keys resolve before the broker is
ever consulted (no key egress). Introspection is cached ≤60s, positive-only, and **fails closed**
on any broker error. Tokens are audience-bound to the enrolling site (`home_url()`) — a token
minted for site A is refused by site B. A local kill-switch (Disconnect) rejects all broker
tokens instantly with no network round-trip. Enrollment credentials are delivered server-to-server
(nonce-authenticated `POST /broker/enroll-store`), never through a browser URL, and stored
encrypted.

**Current state (accurate as of 2026-07-21, corrects §9/§10 above):**
- Broker Worker: **deployed and live** on mumcp.com.
- Plugin-side integration (`Mcpwp_Broker_Auth`, enrollment UI, bearer-auth path): **built, TDD'd
  (580 phpunit), adversarially reviewed clear (no P0), smoke-tested against a real WordPress
  site** — but shipped as **PR #686 only, deliberately not auto-merged/released** because it
  touches the live auth path. `main` is still at 3.6.5; the broker-enabled build is 3.7.0 and
  needs an explicit human merge + `release_freemius.sh` + `publish_update_release.sh` pass before
  any site actually gets it.
- Outstanding before this can carry real traffic: the broker's magic-link account path
  (`sendMagicLink`) is still a console stub — needs a real email provider wired in before
  non-technical owners can complete enrollment unassisted.

**How to apply:** don't describe OAuth/DCR as "to build" anywhere in positioning — it has shipped
(broker in 3.7.0; the plugin's own authorization server with DCR in #1099, on by default).
