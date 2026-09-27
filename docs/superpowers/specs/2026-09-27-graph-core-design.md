# Site graph core: design (#943)

Status: proposed. Epic #942. Milestone 3.14.5, "Agent-native".
Feeds #944 (capabilities), #945 (PostHog signals), #946 (think queries), #919 (entity graph), #922 (clusters), and wp_seo_flags `untargeted`.

## Problem

MCPWP already has a content graph (`class-mcpwp-rest-content-graph.php`). It is rebuilt from scratch on every request, capped at 500 posts, and forgotten afterwards. Search performance rows live in two autoloaded-off options (`class-mcpwp-search-performance.php`), which don't scale and can't be joined to pages.

That makes three things impossible:

- **Memory:** the site can't remember what it looked like last week. Signals have no history.
- **Scale:** a site with 5k posts gets a truncated graph and wrong orphan counts.
- **Joins:** "pages with impressions but no inbound links" means joining graph data to signals. Today that needs two calls and client-side work.

The graph is the site's memory. Signals are its senses. Both need to persist in WordPress.

## Decisions

1. **Three custom tables**, created with `dbDelta` in `Mcpwp_Activator`, on the existing `mcpwp_db_version` upgrade path. Every release bumps the version, so upgrades create the tables.
2. **Nodes are keyed by a stable string**, not by post ID alone, so non-post things (queries, terms, external URLs, brand facts) sit in the same graph.
3. **The build is incremental plus a backfill.** Save hooks mark nodes dirty. A bounded rebuild runs at `shutdown`. The initial backfill runs in WP-Cron batches with a cursor. Action Scheduler is used when present (WooCommerce ships it), because WP-Cron is traffic-dependent. #948 later adds a Cloudflare cron trigger.
4. **The graph read tools are Free.** The owner decided that the self-improving loop is free. Paid tiers get MCPWP running the loop itself (#955), not access to the data.
5. **Honest freshness.** Every read says how complete and how fresh the graph is (`coverage`, `built_at`, `dirty_count`, `backfill: running|done`). It is never silently partial.
6. **Signals are aggregate-only.** No visitor-level data, no PII. Each is a metric per node per period, from a named source.

## Schema

`{prefix}mcpwp_graph_nodes`

| column | type | notes |
|---|---|---|
| id | bigint unsigned AI PK | |
| node_key | varchar(191) UNIQUE | `post:123`, `term:category:5`, `media:9`, `query:<sha1(lowercased q)>`, `url:<sha1(normalized url)>`, `fact:<slug>` |
| kind | varchar(32) | post, page, product, `<cpt>`, term, media, query, url, fact |
| ref_id | bigint unsigned NULL | WP object ID where one exists |
| label | varchar(255) | title / term name / query text |
| url | varchar(2048) | |
| status | varchar(20) | publish, draft, … ('' for non-posts) |
| content_hash | char(40) | sha1 of normalized text. This lets "changed?" skip a rebuild. |
| word_count | int unsigned | |
| props | longtext | JSON, a small bag: template, builder (elementor/gutenberg/classic), lang, parent, modified_gmt |
| updated_at | datetime | |

Keys: `kind_status (kind, status)`, `ref (kind, ref_id)`.

`{prefix}mcpwp_graph_edges`

| column | type | notes |
|---|---|---|
| id | bigint unsigned AI PK | |
| from_key / to_key | varchar(191) | |
| type | varchar(32) | links_to, in_term, parent_of, uses_media, translation_of, targets_query, derived_from |
| weight | double | links_to: count of links; targets_query: clicks (impressions go in props) |
| source | varchar(32) | builder, gsc, bing, agent, manual |
| props | text | JSON: anchor texts (≤5), location (content/elementor/menu) |
| updated_at | datetime | |

Keys: `UNIQUE edge (from_key(120), to_key(120), type)`, `to_type (to_key(120), type)` for inbound lookups.

`{prefix}mcpwp_graph_signals`

| column | type | notes |
|---|---|---|
| id | bigint unsigned AI PK | |
| node_key | varchar(191) | |
| metric | varchar(64) | pageviews, visitors, bounce_rate, scroll_depth, conversions, clicks, impressions, ctr, position, ai_crawler_hits, lcp_ms |
| value | double | |
| period_start / period_end | date | daily rows by default; imports may carry ranges |
| source | varchar(32) | posthog, gsc, bing, cloudflare, ga4, clarity, manual |
| fetched_at | datetime | |

Keys: `UNIQUE sig (node_key(120), metric, source, period_start, period_end)` (re-imports upsert, never duplicate) and `metric_period (metric, period_start)`.

Retention: a daily cron prunes signals older than 400 days (a filter can change this).

Uninstall: drop all three tables in the same place the existing tables are dropped (`freemius-init.php` uninstall routine). Deactivation keeps the data.

## Build

A new class, `Mcpwp_Graph_Builder` (core, Free), holds the extraction logic. The logic moves out of the REST controller: link parsing, Elementor `_elementor_data` link and text extraction, taxonomy, parent and media. `class-mcpwp-rest-content-graph.php` becomes a thin reader. When the graph is built (backfill done) it reads the tables; otherwise it falls back to today's live build. Existing response shapes stay compatible.

- **Incremental:**
  - `save_post`, `deleted_post`, `set_object_terms`, `edited_term` and `delete_term` add the node key to a request-local dirty set.
  - At `shutdown`, up to 25 dirty nodes are rebuilt: upsert the node, then replace its outgoing builder-sourced edges. Only the builder's own edges are replaced; gsc and agent edges are left alone.
  - Anything left over goes to the `mcpwp_graph_dirty` option, and the next cron batch drains it.
  - Rebuild is skipped when `content_hash` is unchanged and terms are unchanged.
  - Inbound edges need no fan-out, because they are stored once, as someone else's outgoing edges.
- **Backfill:**
  - `mcpwp_graph_backfill` runs 50 posts per run, ordered by ID, with a cursor option.
  - It is scheduled on upgrade and self-reschedules until done, then marks `backfill: done`.
  - It uses Action Scheduler (`as_enqueue_async_action`) when the function exists, and WP-Cron single events otherwise.
  - Batches have a time budget (≤10 s per run) in the style of `Mcpwp_Signals`.
- **Deleted objects:** delete the node and all edges from or to it.
- **Queries:**
  - `Mcpwp_Search_Performance::import_rows` also writes `query:` nodes, `targets_query` edges (page→query, weight=clicks, props impressions/position), and signals (clicks/impressions/ctr/position per page per period).
  - A one-time migration folds the existing option rows into the tables. The options stay readable for one release, then are removed in 3.14.9.
- **Scale target:** 20k posts and 200k edges. That requires indexed lookups only, no full-table PHP loops in request paths, and pagination everywhere.

## Read surface (MCP + REST)

Every tool is read-only, runs at read scope, and returns `graph_status` (coverage, built_at, backfill, dirty_count).

- **Resource `mcpwp://graph`:** a summary for first contact. Node counts by kind, edge counts by type, signal sources with latest `period_end`, top 10 orphans (published, no inbound links_to), freshness. It must stay under 2k tokens.
- **`wp_graph_node(node_key | post_id)`:** node, props, inbound/outbound counts by type, latest value per metric per source, and a 28-day trend (sum or avg) per metric.
- **`wp_graph_neighbors(node_key, direction=out|in|both, types[], depth=1..2, limit≤100, cursor)`:** edges plus minimal neighbor nodes. Depth 2 is capped at 200 nodes total.
- **`wp_graph_query(kind[], status, filters, order_by, limit≤200, cursor)`:** filters are a fixed, allowlisted set, never raw SQL.
  - `orphan: true`
  - `min_inbound` / `max_inbound`
  - `has_metric: <m>`
  - `metric: {name, source?, op: gt|lt, value, window_days}`
  - `no_edge_type: targets_query` (untargeted)
  - `modified_before`
  - `word_count_lt`

  `order_by` is one of label, modified, inbound, or a metric. This is the primitive #946 builds on.
- **REST:** `/graph`, `/graph/node`, `/graph/neighbors`, `/graph/query` mirror the tools. `/graph/rebuild` is a POST at admin scope that reschedules the backfill.

## Security and limits

- All queries use `$wpdb->prepare`. Filter names and ops map through an allowlist to fixed SQL fragments.
- A node's visibility follows the caller: drafts and private posts appear only if the key's scope/role could read them. Post-backed nodes are filtered through the existing read-capability check, and `url:`/`query:` nodes are site-level data at read scope.
- Nothing from signals is exposed publicly. The WebMCP visitor tools (#982) must not read these tables.
- Each response has a hard size cap. Cursors are opaque: base64 of the last id and the order value.

## Out of scope here

Capability registry (#944). PostHog/Cloudflare/GA4 fetchers (#945, #947, #956): they write into `mcpwp_graph_signals` through one `Mcpwp_Graph_Signals::upsert(array $rows, string $source)` entry point defined here. Think queries and findings (#946). Entity @graph (#919). Clusters (#922).

## Slices (each one a PR, each shippable)

1. **S1: storage and builder.** Tables, `Mcpwp_Graph_Builder`, incremental hooks, backfill, uninstall, `Mcpwp_Graph_Signals::upsert`. Tests: builder extraction (content links, Elementor links, terms, media), incremental dirty handling, backfill cursor, idempotent upsert. Rig: 300 posts backfilled, edit one post, and its edges update.
2. **S2: read surface.** Resource, 3 tools, REST routes, `graph_status`, capability filtering. Tests per filter, pagination, a draft leak test (an author-scope key must not see another user's draft). This one gets an adversarial review, because it is a data-egress surface.
3. **S3: search-performance fold-in and content-graph REST on tables.** Import writes nodes, edges and signals. One-time migration. `/content-graph` reads the tables with compatible output. Enables wp_seo_flags `untargeted`.

## Acceptance (#943 closes when)

- On a 1k-post rig, after backfill, `wp_graph_query {orphan:true}` matches a brute-force orphan count exactly.
- Editing a post changes its edges within the same request (≤25 dirty) or the next cron run.
- A search-performance import shows up as `targets_query` edges and signals, and `wp_graph_node` shows the page's clicks trend.
- Deactivating and reactivating keeps the data. Uninstall removes the tables.
- The full suite is green, and the adversarial review of S2 is clean.
