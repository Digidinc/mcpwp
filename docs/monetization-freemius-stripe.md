# Monetization runbook — Freemius + Stripe (agents)

> **Status:** investigation snapshot, 2026-09-16. Draft for agents. **Do not merge
> as “first dollar shipped.”** Do not add live Stripe keys. Do not build a
> parallel Stripe Checkout. Hadi gates live prices, public CTAs, and any
> `is_live` / dashboard change.
>
> Related: GH [#501](https://github.com/Mumega-com/mcpwp/issues/501) (Freemius
> single-source), [#512](https://github.com/Mumega-com/mcpwp/issues/512)
> (configure Freemius + connected Stripe), [#579](https://github.com/Mumega-com/mcpwp/issues/579)
> (pricing CTAs still `/contact/`), Linear [MUM-6](https://linear.app/mumega/issue/MUM-6)
> (pricing truth), [MUM-31](https://linear.app/mumega/issue/MUM-31) (Pro/Agency
> launch), [MUM-112](https://linear.app/mumega/issue/MUM-112) (CEO monetization
> loop).

---

## 1. Money rail (decided — do not reopen)

**KEEP Freemius.** It is connected to Hadi’s Stripe → Mercury. That is the
license, tax/MoR, subscription, dunning, refund, affiliate, and free→pro
rail. Stripe-direct (Payment Links / custom key generator) was proposed in
#512 and **dropped** the same day (Hadi comment on #512, 2026-06-11).

#501 already made Freemius the single source of entitlement in the plugin.
#512’s remaining work is **configure** Freemius pricing + prove checkout —
not replace it.

| Layer | Who | What |
|-------|-----|------|
| Checkout + license keys + trial + updater (paid fleet) | Freemius product `23824` | Buyer pays; site gets a license; Pro gates lift |
| Card / bank | Hadi’s Stripe → Mercury | Money lands on the “ring” |
| Tax / MoR | Freemius setting (verify in dashboard) | Open question: connected-Stripe mode — is Freemius still MoR, or does US sales tax become ours? |
| Plugin entitlement | `Mcpwp_License` | Reads Freemius SDK (`can_use_premium_code` / `is_paying` / `is_trial`) |
| Agency ZIP (not first dollar) | Build-time `MCPWP_PRO=true`, no SDK | Token-gated package; **not** a Freemius SKU |

**Do not:** invent Stripe products, commit `sk_live` / `sk_test`, or add a
second checkout. First dollar is a Freemius plan + a working checkout URL.

---

## 2. File map

### Licensing / gates

| File | Role |
|------|------|
| `mcpwp/includes/freemius-init.php` | SDK bootstrap. Product id `23824`, slug `mcpwp`, `has_paid_plans: true`, 14-day trial with `is_require_payment: false`, **`is_live: true`**, menu account + pricing. Loaded only when `MCPWP_FREEMIUS_BUILD` is defined. |
| `mcpwp/includes/class-mcpwp-license.php` | Canonical `is_pro()` / `get_license_info()` / `get_upgrade_url()`. Freemius first; `MCPWP_PRO` / `MCPWP_WPORG_BUILD` overrides; 2.8.x bridge reads **original** `spai_pro_license` / `spai_trial_started` only. |
| `mcpwp/mcpwp.php` | Loads Freemius init iff `MCPWP_FREEMIUS_BUILD`; loads `includes/pro/` iff `is_pro()`. |
| `mcpwp/includes/api/class-mcpwp-rest-api.php` | `is_pro_entitled()` / `check_pro_permission()` / `require_pro()` → 403 `pro_required` + `upgrade_url` → `https://mcpwp.net/pricing/`. |
| `mcpwp/includes/core/class-mcpwp-core.php` | `site-info` + capabilities `plan` / `pro_active` from `get_license_info()`. |
| `mcpwp/includes/pro/class-mcpwp-pro-bootstrap.php` | Registers Pro REST modules when entitled. |
| `mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php` | Pro MCP tools; listed only when Pro. |
| `mcpwp/tests/LicenseCapabilitiesTest.php` | Entitlement matrix (plan / `is_pro` never contradict). |
| `docs/FREE_PRO_SPLIT.md` | Tool-tier source of truth. |

### Builds / paid updater

| File | Role |
|------|------|
| `mcpwp/scripts/build-freemius.sh` | Paid ZIP. Injects `MCPWP_FREEMIUS_BUILD`. Keeps SDK. Excludes self-hosted updater. Unpacks as `mcpwp/` in source; Freemius premium delivery uses slug `mcpwp-premium`. |
| `mcpwp/scripts/build-wporg.sh` | Free WP.org ZIP. Injects `MCPWP_WPORG_BUILD` (hard-false Pro). Strips SDK + `includes/pro/`. |
| `mcpwp/scripts/build-agency.sh` | Agency ZIP. Injects `MCPWP_PRO=true`. **Deletes Freemius.** Self-updater stays. Private R2, token header — not first dollar. |
| `scripts/release_freemius.sh` | Upload paid ZIP to Freemius. Needs `FREEMIUS_BEARER_TOKEN`. Product id default `23824`. |
| `scripts/verify_freemius_channel.sh` | Prove Freemius can deliver a version. Missing token = **fail**, not skip. |
| `.github/workflows/release-please.yml` | `publish-freemius` job. Repo secret `FREEMIUS_BEARER_TOKEN`. |

Two fleets, not one: `updates.mcpwp.net` = self-hosted + agency; Freemius =
paying Pro installs. Publishing one does not ship the other.

### Pricing / GTM docs (claims — not live config)

| File | What it claims | Trust |
|------|----------------|-------|
| `docs/PRICING_TIERS.md` | **Signed T87 (2026-06-10):** Pro $59/yr/1 · $99/yr/3 · $199/yr/25 + lifetime $199/$349/$699. Pot hosting $59/mo incl. 10 sites + $4/site/mo. Checkout rail = Freemius. | Canonical numbers **until Hadi revises**. |
| `docs/squad/WORK-PRICING.md` | Agent-labor / service pricing. Separate from the plugin toll. | Not the first-dollar SKU. |
| `mcpwp/docs/superpowers/specs/2026-05-30-freemius-setup.md` | Pro $79/yr, trial requires payment, Agency $249 hold. | **STALE.** Do not implement these numbers. |
| `mcpwp/docs/skills/dev-pro.md` | Still mentions Lemon Squeezy + `is_require_payment: true` + slug `MCPWP`. | **STALE** vs current `class-mcpwp-license.php` / `freemius-init.php`. |
| `docs/website-gtm/offer-site/pricing.jsx` | Core / Builder / Agency, no dollar amounts. | **STALE** marketing scaffold. |
| `docs/MCPWP-NET-PRICING-FIX.md` | Page 502 Elementor hijack; CTAs were `#freemius-pro` placeholders. | Historical. Live page now shows numbers (see §4). |
| `docs/FREE_PRO_SPLIT.md` | Free = read+write WP data; Pro = write-heavy / external APIs. Agency = Freemius Agency license + unlimited sites. | Capability truth. Agency “unlimited” ≠ live page “20 sites”. |

Public product id (not a secret): **`23824`**. Dashboard (Hadi-only):
`https://dashboard.freemius.com/developer/products/23824/`.

SDK `public_key` and `wp_org_gatekeeper` already live in
`freemius-init.php`. **Do not copy them into new files. Do not invent a
secret key, plan id, or Stripe price id.** If a value is not in repo or
the Freemius dashboard, it does not exist yet.

---

## 3. How entitlement actually works

Order in `Mcpwp_License::is_pro()` (first hard-false / first truthy wins):

1. `MCPWP_WPORG_BUILD` defined → **false** (WP.org free build).
2. `MCPWP_PRO` true → **true** (dev / agency ZIP).
3. Freemius `can_use_premium_code()` / `is_paying()` / `is_trial()` → true.
4. Bridge only: original `spai_pro_license` valid, or `spai_trial_started` < 14 days.
5. Else false.

`get_license_info()` guarantees: not pro ⇒ `plan=unlicensed`; pro ⇒ plan is
never `free`/`unlicensed`. Plans the code understands: `unlicensed`, `trial`,
`pro`, `agency` (Freemius plan name/title/slug contains `agency`).

**Feature gates are binary Pro/not-Pro.** `is_agency()` is reported on
site-info / capabilities. It does **not** unlock extra tools. Agency vs Pro
in the paid SDK path is a Freemius *plan slug*, not a second code path.

Upgrade URL prefers Freemius install-linked checkout (`get_upgrade_url()` /
reconnect if anonymous or pending). Fallback: `https://mcpwp.net/pricing/`.
A marketing-page purchase with no `install_id` does **not** activate the
site that started the upgrade (this is why in-plugin Freemius checkout
matters more than a naked Payment Link).

Connect-screen soft-gate: premium ZIP can skip license (`require_license=false`)
so Free tools work immediately. “Manage pricing and license” reconnect must
**not** get that soft-gate (see comments in `freemius-init.php`).

---

## 4. Free vs Pro vs Agency — code / docs vs public

Observed 2026-09-16.

| Claim | Code | Signed T87 (`PRICING_TIERS.md`) | Live `https://mcpwp.net/pricing/` | Stale specs |
|-------|------|----------------------------------|-----------------------------------|-------------|
| Free | Install + API key; no Freemius account required. WP.org build forces unlicensed. | $0, WP.org, full safety layer, BYOK | $0 forever · 1 site | — |
| Pro (1 site) | Freemius paid/trial **or** `MCPWP_PRO` | **$59/yr** + lifetime $199 | **$59/year** · or $199 lifetime | $79/yr (May 30 spec) |
| Pro (3 sites) | Freemius license quantity (dashboard), not a code branch | **$99/yr** + lifetime $349 | **$99/year** · or **$350** lifetime | — |
| Pro / Agency high seat | Plan slug / license seats | **$199/yr / 25 sites** + lifetime $699 | **Agency $199/year · 20 sites** · or $599 lifetime | Agency $249/yr unlimited (hold) |
| Agency product | (a) Freemius plan name contains `agency`; (b) agency ZIP with `MCPWP_PRO=true`, no SDK, private R2 | Pot hosting **$59/mo + 10 sites + $4/site** (different SKU) | Yearly 20-site license, “one-key gateway” | Core/Builder/Agency names in GTM jsx |
| Checkout CTA | In-plugin Freemius pricing/account; REST upsell → `/pricing/` | Freemius for the toll | **#579 still open:** Pro buttons were `/contact/` (“checkout opens shortly”) as of 2026-06-19. Re-verify before treating the page as buyable. | `#freemius-pro` placeholders in the 502 fix doc |

**Capability split (code, not marketing):**

- **Free:** pages/posts/media/menus/blocks, basic Elementor, approvals, webhooks,
  integrations *config*, SEO read/audit subset. See `docs/FREE_PRO_SPLIT.md`.
- **Pro:** Elementor Pro/templates/theme builder, Woo, LMS, Figma *use*,
  DataForSEO visibility/opportunities, cache flush, Google Indexing, etc.
  403 `pro_required` on Free.
- **Agency (code):** same Pro tools. Multi-site is the **agency ZIP + proxy**
  (`docs/AGENCY-GATEWAY.md`), not extra MCP tools. Public “20 sites / gateway”
  is a commercial claim, not an `is_agency()` gate.

**WP.org:** `readme.txt` points at `wordpress.org/plugins/mcpwp`. Public API
`plugin_information?slug=mcpwp` returned **404 Plugin not found** on
2026-09-16. Free→pro funnel via WP.org is not a live install path yet
(ROADMAP-4.0 D1 still “land current review”). First dollar does **not**
wait on WP.org approval if checkout works from a Freemius/self-hosted
install.

Self-hosted channel (2026-09-16): `updates.mcpwp.net/version.json` serves
**3.11.1** (free/self-hosted ZIP). Paid fleet version is Freemius-only —
this environment could not read it (no `FREEMIUS_BEARER_TOKEN`).

---

## 5. Gaps to first paid Pro (ranked by time-to-cash)

Agents cannot close items marked **Hadi / dashboard**. Do not pretend they
are done because the SDK exists.

| Rank | Gap | Who | Why it blocks cash |
|------|-----|-----|--------------------|
| 1 | **Freemius plans at T87 prices** (and matching license quantities 1 / 3 / 25). Confirm they exist; do not assume the May 30 $79 spec. | Hadi, Freemius dashboard | Checkout with no plan = no charge. Public API `/v1/plugins/23824/pricing.json` is 401 without Freemius auth — Cursor cloud **cannot** verify this. |
| 2 | **Stripe connection + tax/MoR mode** (already claimed 2026-06-11 — re-verify). | Hadi | Money must land Stripe→Mercury; confirm who files US sales tax. |
| 3 | **Test-mode purchase** on a Freemius-build install: skip → upgrade → sandbox/test license → `is_pro()===true` → one Pro tool works → reconnect after skip still shows license field. | Agent on a **local/staging** Freemius ZIP + Hadi-issued test license | Proves the rail without live cards. |
| 4 | **Public CTA** on mcpwp.net/pricing (page 502): replace `/contact/` or placeholder hashes with Freemius checkout / in-plugin upgrade. #579. | Hadi-gated site edit | Page can show prices and still collect zero dollars. |
| 5 | **Reconcile public numbers vs T87** (lifetime $350 vs $349; Agency 20 vs 25; Agency yearly vs $59/mo pot rent). MUM-6. | Hadi | Wrong public price before first sale is a claims bug, not a code bug. |
| 6 | **`FREEMIUS_BEARER_TOKEN` in GitHub Actions** (already referenced). Cursor cloud does **not** have it. | Hadi / repo secrets | Needed to *publish* paid ZIPs, not to take the first checkout if a released build already exists. |
| 7 | WP.org listing live + readme upsell. | Later | Funnel, not first charge. |
| 8 | Agency Freemius plan + public Agency SKU + token-gated ZIP policy (#852). | Later (MUM-31) | Do not block Pro 1-site. |
| 9 | Affiliates (#509), marketplace Stripe Connect (T87 stream #1), metered energy (#449). | Later | Not first dollar. |

Code that is **already shipped** and must not be rebuilt: SDK init, license
accessor, Pro tool/REST gates, install-linked upgrade URL, Freemius connect
soft-gate, paid-channel publish/verify scripts, `pro_required` telemetry.

---

## 6. Test-mode runbook (agents)

Use this on a **local or staging** WordPress with the **Freemius-build ZIP**
(`mcpwp/scripts/build-freemius.sh`), never by flipping production
`is_live` in a committed PR.

1. Build and install the Freemius ZIP. Confirm `MCPWP_FREEMIUS_BUILD` is
   defined and `mcpwp_fs()` exists. Self-hosted / agency / WP.org ZIPs will
   lie: they have no SDK or they force Pro.
2. In WP Admin → MCPWP, skip opt-in if offered. Free tools must work.
   `get_license_info()` → `plan=unlicensed`, `reason` one of `anonymous` /
   `no_active_license` / `pending_activation`.
3. Click **Upgrade** / **Manage pricing and license**. You should get
   Freemius checkout or the license field — not a dead skip loop
   (the reconnect-intent fix in `freemius-init.php`).
4. Ask Hadi for a **Freemius test/sandbox license** (dashboard). Do not
   generate keys. Do not put live card data in logs.
5. Activate the test license. `wp_site_info` / capabilities must show
   `is_pro: true` and a non-free `plan`. Call one Pro-only tool
   (e.g. a Figma or SEO-market route) and expect **not** `pro_required`.
6. Do **not** set `is_live: false` on `main`. If a sandbox SDK is required,
   use a local-only override and say so in the PR. Shipping `is_live: false`
   points production sites at Freemius sandbox.
7. Stop. Write the receipt (license activated, Pro tool succeeded). Hadi
   gates any live price, public CTA, or production dashboard change.

**Never** commit: Stripe secret keys, Freemius secret/bearer tokens, test
credit cards, customer PII, or invented plan/price ids.

---

## 7. Hadi live gates (do not self-clear)

- Creating or changing Freemius **live** plans / prices / license quantities.
- Connecting or reconnecting Stripe, changing MoR/tax mode.
- Replacing mcpwp.net/pricing CTAs with live checkout.
- Publishing a new Freemius “released” build (`release_freemius.sh` /
  `publish-freemius`).
- Flipping `is_live` or adding a second money rail.
- Agency public launch (MUM-31) and any WP.org listing claims.

Autonomy for this workstream is **draft**. A human takes it from here.

---

## 8. What Cursor cloud can and cannot see

This environment (2026-09-16, Cursor cloud on `Mumega-com/mcpwp`):

| Surface | Visible? |
|---------|----------|
| This private repo (`Mumega-com/mcpwp`) | Yes — full clone, issues, Actions YAML |
| Other `Mumega-com` **public** repos | Yes via `gh` |
| Other **private** org repos | **No.** `gh repo list` showed only `mcpwp` as private. `Mumega-com/mcpwp-operations` and `Mumega-com/mumcp-broker` **do not resolve** (missing or invisible). |
| Freemius dashboard / plan list / Stripe connection | **No.** Public Freemius API returns 401. No `FREEMIUS_BEARER_TOKEN` in the cloud env. |
| Stripe / Mercury | **No.** Correct: no live Stripe keys injected. Cloud secrets present: `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` only. |
| mcpwp.net/pricing (public HTML) | Yes |
| `updates.mcpwp.net/version.json` | Yes (self-hosted 3.11.1) |
| WordPress.org slug `mcpwp` | Yes — **not listed** (404) |
| Linear MUM-6 / MUM-31 / MUM-112 | Yes (titles + bodies) |

**Cursor cloud does not have full private Mumega-com access.** It has this
plugin repo plus public org repos and public web. It cannot certify that
Freemius plans or Stripe-connect are actually configured. That proof is
Hadi + dashboard, or a `FREEMIUS_BEARER_TOKEN` used only in CI — never
pasted into docs.

---

## 9. Recommended next Cursor cloud coding task

Wire mcpwp.net/pricing Pro CTAs to install-linked Freemius checkout (or
document the exact dashboard checkout URL Hadi supplies) and add a
phpunit/staging assertion that `get_upgrade_url()` is Freemius, not
`/contact/` — still no Stripe-direct, still Hadi-gated before any
production page write.
