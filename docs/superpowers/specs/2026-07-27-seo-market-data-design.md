# SEO Market Data — Design

**Date:** 2026-07-27
**Status:** Approved for planning
**Slice:** 1 of 3 (see Sequencing)

## Goal

Give MCPWP real external SEO data — keyword volume/difficulty and search visibility — so a
customer's AI agent can decide what to work on next, and so the Control Room SEO tab stops being
half-empty. Sold as part of the Pro/Agency monthly subscription; the customer supplies their own
DataForSEO credentials.

## Why now

The SEO surface today knows everything about the site and nothing about the market:

| Component | State |
|---|---|
| `Mcpwp_SEO_Audit_Store` | **Working.** Crawls own pages, stores issues, renders in Control Room |
| `Mcpwp_Keyword_Research` | **Half-built.** Scrapes Google autocomplete. No volume, CPC, or difficulty |
| `Mcpwp_Search_Performance` | **Empty shelf.** `import_rows()` works; nothing anywhere fetches. No Search Console integration exists |

`Mcpwp_Keyword_Research::research()` ships this string in every response:

> `Volume and competition data (CPC, search volume, difficulty) require a provider integration such
> as DataForSEO. This tool returns Google autocomplete signals only.`

This spec deletes that apology.

## The product is the agent loop, not the dashboard

The customer's agent mounts MCPWP over MCP and works the site's SEO. The loop:

1. Agent asks what is worth doing → MCPWP fuses site audit + visibility + market volume into a
   ranked opportunity list with reasons
2. Agent writes or edits → through existing approval-gated content tools
3. Agent re-checks → visibility refresh shows movement

The Control Room renders the same data for the human. It is the second consumer, not the first.

**This inherits the existing autofix posture.** `Mcpwp_SEO_Autofix` deliberately refuses to
auto-mutate search-facing copy — every strategy carries a `reason` explaining why a human approves
it. Nothing in this spec changes that. The agent receives *evidence and a plan*; mutations continue
to route through the approval system.

## Architecture

### Two nouns, two stores

The central modelling decision. An early draft fed DataForSEO into `Mcpwp_Search_Performance` to
reuse its report surface. That is wrong, and the field shapes are what proved it:

- **Performance** — traffic actually received. First-party, time series, `clicks`/`impressions`/`ctr`.
  Only Google knows it. `normalize_row()` is literally a Search Console export shape.
- **Visibility** — where the site stands in the market right now. Snapshot, `position` +
  `search_volume` + `difficulty`. DataForSEO knows it; Search Console largely does not.

DataForSEO cannot supply clicks or impressions. Mapping it into the performance store writes
`clicks: 0` on every row, and the Control Room then renders a report showing zero traffic forever —
which reads as "your SEO is dead" when it means "we did not ask a source that knows." A missing
value and a real zero must never render identically.

Worse, `position` is not the same measurement in each: Search Console reports an impression-weighted
average position, DataForSEO reports a scraped SERP rank. Sharing one column produces a number
nobody can interpret, and it looks authoritative while being meaningless.

So:

- **New** `Mcpwp_SEO_Visibility` — DataForSEO ranked-keyword snapshots
- **Untouched** `Mcpwp_Search_Performance` — reserved for true performance data; its
  `import_rows()` already works and a Search Console fetcher slots in later with no changes

Rendering patterns and the Control Room card layout are reused. Storage is not.

### Components

| Unit | Responsibility | Depends on |
|---|---|---|
| `Mcpwp_Provider_Dataforseo` | HTTP client: auth, request, error mapping, response shape | `Mcpwp_Integration_Manager` |
| `Mcpwp_SEO_Visibility` | Store + query ranked-keyword snapshots | — |
| `Mcpwp_SEO_Opportunities` | Fuse audit issues + visibility + volume into a ranked plan | audit store, visibility store |
| `Mcpwp_Keyword_Research` (existing) | Gains enrichment; keeps autocomplete as idea generator | provider |

Each is independently testable: the provider against captured fixtures, the stores against arrays,
the opportunity ranker as a pure function over both.

## Provider integration

`dataforseo` joins the registry in `class-mcpwp-integration-manager.php`, same shape as `figma` and
`posthog`:

```php
'dataforseo' => array(
    'tier'   => 'pro',
    'fields' => array(
        'login'    => array( 'label' => 'API Login' ),
        'password' => array( 'label' => 'API Password' ),
    ),
),
```

DataForSEO uses HTTP Basic auth, not a bearer token — hence two fields rather than one `api_key`.
Credentials are stored encrypted in `mcpwp_integrations` like every other provider.

`/integrations/test` calls `/v3/appendix/user_data` and returns the account's **remaining credit
balance**, so "Test connection" doubles as the customer seeing what they have left to spend.

## Data flows

### Keyword research enrichment

`Mcpwp_Keyword_Research::research()` keeps autocomplete expansion as the idea generator, then
enriches through DataForSEO Labs with `search_volume`, `cpc`, `competition`, `keyword_difficulty`.

**With no credentials configured it returns today's behaviour unchanged** — autocomplete keywords
and questions. The free tier degrades, it does not break.

### Visibility snapshot

DataForSEO Labs "ranked keywords" for the site's domain → `Mcpwp_SEO_Visibility`. Row shape:

```
keyword, position, search_volume, keyword_difficulty, ranking_url, captured_at, provider
```

No `clicks`, no `impressions`, no `ctr` — the store does not have those columns, so nothing can
render a fabricated zero into them.

### Opportunities

`Mcpwp_SEO_Opportunities` ranks work by fusing all three sources. Each opportunity carries the
evidence that produced it and a `reason`, matching the autofix convention.

**The ranker must work across site maturity.** An early draft used *striking distance*
(positions 11–20 × volume) as the entry condition. Testing against a real domain falsified it: a
young site ranked for six keywords, all at positions 64–91, so a striking-distance filter returned
an empty list — reporting "no opportunities" to precisely the site with the most work available.
Most installs will be sites with thin or zero rankings. Handling them is the primary case, not a
fallback.

The ranker therefore selects its signal from what the site actually has:

| Site state | Signal | Opportunity reads as |
|---|---|---|
| No / very few rankings | Keyword research + existing content + audit issues | "You have a page about X; these are winnable terms for it" |
| Some rankings, deep positions | `search_volume ÷ keyword_difficulty`, weighted by having any position at all | "You are on the board for X at 75 and difficulty is 8 — push it" |
| Mature, page-2 positions | Striking distance as a bonus multiplier | "Page 2, nudge it to page 1" |

Striking distance survives as a *multiplier*, never as a gate. A site with no rankings must still
receive a ranked, actionable list.

**An empty opportunity list means "nothing to do," and must be distinguishable from "we could not
compute one."** Same rule as everywhere else in this spec.

## Cost safety

Measured against the live API: **$0.012 per request + $0.00012 per result.** A 50-keyword
enrichment costs under 2¢, and a ranked-keywords fetch cost $0.0127.

So no single call is expensive. The risk is an unbounded agent loop making ten thousand cheap
calls against a customer's account. The caps below exist for that, not because any one lookup
is costly — sizing them as if calls were expensive would cripple the feature for no benefit.

- Results cached in transients keyed by seed + locale, 24h TTL
- At most **50 keywords** sent for enrichment per request, batched into a single DataForSEO call.
  The autocomplete stage already caps itself at `MAX_EXPANSIONS = 60` / `MAX_HTTP_CALLS = 25`;
  enrichment gets its own explicit ceiling so a wider autocomplete cap can never silently multiply
  paid calls
- **No cron, no automatic fetching in this slice.** Every call is an explicit user or agent action
- Credit balance surfaced in Integrations
- A failed lookup is cached briefly as a negative result, so a broken key cannot be retried in a
  hot loop

Scheduled refresh is deliberately deferred to slice 3, when real usage and spend patterns are known.

## Error handling

| Condition | Behaviour |
|---|---|
| No credentials | Keyword research degrades to autocomplete; visibility returns empty with an explicit `reason` |
| Auth rejected | Surfaced as a distinct `reason`, never as "no data" |
| Out of credits | Distinct `reason` naming the balance; not a generic failure |
| Rate limited / timeout | Cached negative, distinct `reason`, no retry storm |
| Malformed response | Rejected at the client boundary; store never receives partial rows |

The through-line: **an unavailable source and an empty result must never produce the same output.**
That equivalence is what made #716 and the Freemius drift invisible for days.

## Surfaces

No new REST routes and no new MCP tool names for enrichment — `wp_keyword_research` and
`/seo/search-performance` return richer payloads. New surfaces are limited to visibility and
opportunities:

- `wp_seo_visibility` / `GET /seo/visibility`
- `wp_seo_opportunities` / `GET /seo/opportunities`
- New agent playbook `improve_search_visibility` in `Mcpwp_Agent_Playbooks`, following the existing
  `read_first` / `required_tools` / `steps` / `validation_gates` / `approval_gates` /
  `stop_conditions` / `rollback` contract

Control Room SEO tab renders the visibility snapshot and the opportunity list beside the existing
audit issues.

## Testing

- Provider client against **captured DataForSEO fixtures**; no live API calls in CI
- A provider that cannot supply clicks/impressions never renders a zero into them
- Missing credentials degrade to autocomplete rather than erroring
- Each error condition produces its own distinct `reason`
- Opportunity ranking as a pure function: known inputs → known order
- Cache prevents a second identical lookup from spending a second call

## Out of scope

- **DataForSEO OnPage API** — duplicates the working `Mcpwp_SEO_Audit_Store`
- **Backlinks** — different product, own spec
- **Rank tracking over time** — slice 3
- **Reselling credits / metering** — customer brings their own key; no billing surface here

## Sequencing

1. **This spec** — DataForSEO provider, keyword enrichment, visibility snapshot, opportunities.
   No cron, no recurring spend.
2. **Next** — Google Search Console into the existing performance store. Free, first-party, and the
   half DataForSEO structurally cannot provide. Together they are a complete picture.
3. **Later** — rank tracking over time, once real usage and spend patterns are known.
