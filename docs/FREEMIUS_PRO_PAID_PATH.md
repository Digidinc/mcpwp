# Freemius Pro paid-path checklist (MUM-113)

> **Status:** draft, 2026-09-16. **Do not merge as “first dollar shipped.”**
> No live secrets. Do not flip `is_live`. Do not write production pricing
> Elementor without a Hadi gate. KEEP Freemius MoR → Hadi Stripe → Mercury.
> Do **not** build Stripe-direct / custom Checkout for licenses.
>
> Companion map: draft PR [#865](https://github.com/Mumega-com/mcpwp/pull/865)
> (`docs/monetization-freemius-stripe.md`). This file is the coding follow-up:
> dashboard checklist + the upgrade-URL contract agents actually follow.
>
> Related: GH [#501](https://github.com/Mumega-com/mcpwp/issues/501),
> [#512](https://github.com/Mumega-com/mcpwp/issues/512),
> [#579](https://github.com/Mumega-com/mcpwp/issues/579),
> Linear [MUM-113](https://linear.app/mumega/issue/MUM-113),
> [MUM-6](https://linear.app/mumega/issue/MUM-6),
> [MUM-31](https://linear.app/mumega/issue/MUM-31).

---

## 1. What already ships (do not rebuild)

| Piece | File / evidence | Notes |
|-------|-----------------|-------|
| Freemius product | `mcpwp/includes/freemius-init.php` | `id => '23824'`, slug `mcpwp`, `has_paid_plans => true`, 14-day trial `is_require_payment => false`, **`is_live => true`**. Dashboard (Hadi-only): `https://dashboard.freemius.com/developer/products/23824/`. |
| Entitlement | `mcpwp/includes/class-mcpwp-license.php` | Binary Pro/not-Pro. `FREEMIUS_PRODUCT_ID = '23824'`. |
| In-plugin upgrade URL | `Mcpwp_License::get_upgrade_url()` | Prefers install-linked Freemius checkout / reconnect. Rejects `/contact/` and `/contact-us`. Fallback: `https://mcpwp.net/pricing/`. |
| REST / MCP / Figma upsell | `get_pro_upgrade_url()` / `pro_required` `upgrade_url` | Same accessor. Agents must not be sent `/contact/`. |
| Paid ZIP + publish | `mcpwp/scripts/build-freemius.sh`, `scripts/release_freemius.sh` | Needs repo secret `FREEMIUS_BEARER_TOKEN`. **Cursor cloud does not have this token — this run did not verify the Freemius dashboard or the paid channel.** |
| Signed prices | `docs/PRICING_TIERS.md` (T87, 2026-06-10) | Pro **$59/yr/1 · $99/yr/3 · $199/yr/25** + lifetime **$199 / $349 / $699**. Checkout rail = Freemius. |

**Do not invent** Freemius plan IDs, Stripe price IDs, or secret keys. Product
`23824` is in-repo. Plan IDs below are **observed on the public HTML**, not
confirmed in the dashboard.

---

## 2. Public pricing CTAs (page 502 — lives outside plugin PHP)

The live page is WordPress/Elementor on mcpwp.net (page **502**), not a file
this plugin deploys. GH [#579](https://github.com/Mumega-com/mcpwp/issues/579)
(2026-06-19) said Pro buttons were `/contact/` (“Checkout opens shortly”).

**Re-fetched 2026-09-16** (`curl -sL https://mcpwp.net/pricing/`): Pro/Agency
**tier CTAs are already Freemius checkout**, not `/contact/`. Footer still has
a Contact nav link — that is correct.

| Public card | Observed price | Observed CTA |
|-------------|----------------|--------------|
| Pro | $59/year · 1 site · or $199 lifetime | `https://checkout.freemius.com/mode/dialog/plugin/23824/plan/39779/` |
| Pro · 3 sites | $99/year · 3 sites · or **$350** lifetime | `https://checkout.freemius.com/mode/dialog/plugin/23824/plan/39779/?licenses=3` |
| Agency | $199/year · **20 sites** · or **$599** lifetime | `https://checkout.freemius.com/mode/dialog/plugin/23824/plan/39780/` |

**These plan IDs (`39779`, `39780`) are from public HTML only.** They are not
dashboard-verified (no `FREEMIUS_BEARER_TOKEN` in this environment). Hadi must
confirm they exist, are **live**, and charge T87 amounts.

### T87 vs public mismatches (do not “fix” live without a Hadi gate)

| Claim | T87 (`docs/PRICING_TIERS.md`) | Live page 2026-09-16 |
|-------|-------------------------------|----------------------|
| 3-site lifetime | **$349** | **$350** |
| High-seat count | **25 sites** | **20 sites** |
| Agency lifetime | **$699** | **$599** |

A Hadi-gated Elementor edit (wp-admin `post.php?post=502&action=elementor`,
historical widget `nrajt9wv` / section `kf68bg8r` from #579) can align copy.
**This PR does not write production.** Stale in-repo GTM scaffolds
(`docs/website-gtm/offer-site/pricing.jsx`, `deploy-pricing-draft.mjs`) must
not be deployed over the live page.

### Marketing checkout vs install-linked checkout

Public `checkout.freemius.com/...` URLs have **no `install_id`**. A purchase
there creates a Freemius license the buyer then activates in WP Admin. The
path that activates **this** site in one hop is in-plugin
`get_upgrade_url()` (Freemius pricing/account). First cash can happen on
either; in-plugin is the one that does not produce “I paid and nothing
happened.”

---

## 3. Hadi-only Freemius dashboard checklist

Cursor cloud **cannot** see the dashboard or call Freemius with a bearer.
Tick these in `https://dashboard.freemius.com/developer/products/23824/`.

### A. Plans (create or confirm — T87)

- [ ] A paid plan exists for **1 site** at **$59/year** and lifetime **$199**
- [ ] The same (or a sibling) plan sells **3 licenses** at **$99/year** and lifetime **$349** (not $350 unless Hadi revises T87)
- [ ] A high-seat plan sells **25 sites** at **$199/year** and lifetime **$699** — **or** Hadi explicitly keeps the public **20-site / $599** card and updates T87
- [ ] Public CTAs `plan/39779` and `plan/39780` resolve to those live plans (or replace the public hrefs). Do not invent replacements in the repo
- [ ] Plans are **released / live**, not draft / pending / sandbox-only
- [ ] Trial is 14 days and does **not** require a card (`is_require_payment: false` already in `freemius-init.php`)

### B. Stripe + MoR / tax

- [ ] Freemius is connected to **Hadi’s Stripe** (payouts → Mercury)
- [ ] Confirm who is merchant of record and who files **US sales tax** (open question from #865 — do not assume)
- [ ] Test-mode vs live-mode Stripe is the one you intend. Do not paste keys into git

### C. Test license (staging / local Freemius ZIP only)

- [ ] Issue a **test/sandbox** license from the dashboard (do not generate keys in chat)
- [ ] On a **Freemius-build** install (`MCPWP_FREEMIUS_BUILD`): Skip → Free tools work → Upgrade / Manage license → checkout or license field (not a skip loop)
- [ ] Activate the test license → `get_license_info()` `is_pro: true` and a non-free `plan`
- [ ] One Pro tool (e.g. Figma or a SEO-market route) is **not** `pro_required`
- [ ] Do **not** set `is_live: false` on `main`

### D. Public page + #579

- [ ] Click each live Get Pro / Get Agency button; confirm Freemius dialog charges the intended plan
- [ ] Close or update [#579](https://github.com/Mumega-com/mcpwp/issues/579) after confirming CTAs are buyable (HTML no longer points Pro cards at `/contact/`)
- [ ] Decide T87 vs public number drift (MUM-6) before advertising a sale

### E. Not first dollar (do not block Pro 1-site)

- [ ] `FREEMIUS_BEARER_TOKEN` present in GitHub Actions (publish paid ZIPs)
- [ ] WP.org slug `mcpwp` listing live (public API was **404** on 2026-09-16)
- [ ] Agency ZIP policy (#852) / MUM-31 public Agency launch
- [ ] Affiliates (#509), marketplace Stripe Connect, metered energy (#449)

---

## 4. Plugin contract this PR asserts

```
Mcpwp_License::get_upgrade_url()
  1. anonymous / pending activation → Freemius reconnect URL
  2. else Freemius get_upgrade_url() if non-empty and not /contact|/contact-us
  3. else https://mcpwp.net/pricing/
```

Callers now share that URL:

| Surface | Method |
|---------|--------|
| Admin Setup / Integrations | already `get_upgrade_url()` |
| REST `require_pro()` | `upgrade_url` on the WP_Error **data** (agents can follow it) |
| MCP `pro_required` | `upgrade_url` in JSON-RPC error data |
| Figma Free refusal | `upgrade_url` on the WP_Error data |

Tests: `mcpwp/tests/UpgradeUrlTest.php`.

---

## 5. Linear MUM-113 comment (paste-ready)

```markdown
## MUM-113 — Freemius Pro paid-path (draft)

Money rail stays **Freemius product 23824 → Hadi Stripe → Mercury**. No Stripe-direct Checkout. No live keys. Draft PR only.

### Already true in this repo
- SDK: `mcpwp/includes/freemius-init.php` (`id` 23824, slug `mcpwp`, `is_live: true`).
- Gates: `Mcpwp_License` binary Pro. `get_upgrade_url()` prefers install-linked Freemius checkout; **rejects `/contact/`**; fallback `https://mcpwp.net/pricing/`.
- REST / MCP / Figma `pro_required.upgrade_url` now use that accessor (so agents are not sent a contact form).
- Signed prices: `docs/PRICING_TIERS.md` T87 — $59/1, $99/3, $199/25 + lifetime $199/$349/$699.

### Public page (mcpwp.net/pricing, page 502) — outside plugin PHP
- #579 (2026-06-19) said CTAs were `/contact/`.
- Re-fetched 2026-09-16: Pro/Agency **buttons are Freemius checkout**:
  - Pro 1: `checkout.freemius.com/.../plugin/23824/plan/39779/`
  - Pro 3: same plan + `?licenses=3`
  - Agency: `.../plan/39780/`
- Plan IDs are **from public HTML only** — cloud has no `FREEMIUS_BEARER_TOKEN` / dashboard access. Confirm in Freemius before treating them as truth.
- Copy drift vs T87: lifetime 3-site **$350 vs $349**; Agency **20 vs 25** sites; Agency lifetime **$599 vs $699**. Hadi-gated page edit if we align.
- Footer `/contact/` is fine (nav). Do not deploy stale `docs/website-gtm/offer-site/*` over 502.

### Hadi-only remaining (cannot self-clear)
1. Dashboard: create/confirm T87 plans (1 / 3 / 25) as **live**, and that public `39779` / `39780` match — or replace public hrefs.
2. Stripe connected; confirm MoR / US sales-tax mode.
3. Staging Freemius ZIP: test license → `is_pro()===true` → one Pro tool works. Do not flip `is_live` on main.
4. Click live Get Pro; then close/update #579.
5. Later (not first dollar): WP.org slug `mcpwp` (still 404), paid-channel bearer in Actions, Agency launch (MUM-31).

Autonomy is **draft**. A human takes it from here.
```
