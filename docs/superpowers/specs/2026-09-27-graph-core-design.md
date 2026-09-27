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

1. **Four custom tables**, created with `dbDelta` in `Mcpwp_Activator`, on the existing `mcpwp_db_version` upgrade path. Every release bumps the version, so upgrades create the tables. A readiness flag (`mcpwp_graph_ready`), set only after `SHOW TABLES` confirms all four exist, gates every hook: while it is not true, marking a node is a no-op (zero queries) instead of a hook that can error a REST save.
2. **Nodes are keyed by a stable string**, not by post ID alone, so non-post things (queries, terms, external URLs, brand facts) sit in the same graph.
3. **The build is incremental plus a backfill, and the save path only ever marks.** A hook (`save_post`, post-meta changes, term changes, trash/delete) does one `INSERT IGNORE` into a queue table and returns — it never rebuilds inline, so a 400-post bulk edit stays fast regardless of graph size. An async worker (Action Scheduler when present, else WP-Cron 60s out) drains the queue, time-budgeted, and self-reschedules while work remains. The one exception: inside an MCPWP-authenticated request, up to 5 of that request's own marked nodes are rebuilt at `shutdown` within a 1s budget, so the agent that made the edit sees its own edges in the same response — every other caller (wp-admin, a concurrent edit, an import) only ever queues. The initial backfill runs the same way, in batches with a cursor, and a reconcile pass catches up anything that changed while the plugin was deactivated. #948 later adds a Cloudflare cron trigger.
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
| status | varchar(20) | publish, draft, trash, … ('' for non-posts). Trashing a post updates ONLY this column — its edges are kept (readers filter by status), never deleted. Only a permanent delete removes the node and its edges. |
| content_hash | char(40) | sha1 over **everything the rebuild writes**: post_content raw, `_elementor_data` raw, title, status, slug, parent, `_thumbnail_id`, `_wp_page_template`, menu_order, and the sorted term ids. This lets "changed?" skip a rebuild with one comparison, not a separate hash per field. |
| word_count | int unsigned | |
| props | longtext | JSON, a small bag: template, builder (elementor/gutenberg/classic), lang, parent, modified_gmt |
| updated_at | datetime | set by the writer in PHP, not a column default — see the queue table note below. |

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
| updated_at | datetime | set by the writer in PHP. |

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

`{prefix}mcpwp_graph_queue`

| column | type | notes |
|---|---|---|
| node_key | varchar(191) PK | one row per pending node — `mark()` is `INSERT IGNORE`, atomic and duplicate-proof (two concurrent saves marking the same node race on the same primary key, not on a read-modify-write). |
| reason | varchar(32) | bookkeeping only: which hook queued it. |
| queued_at | datetime | set by the writer in PHP; also the worker's drain order (oldest first). |

Replaces an earlier `mcpwp_graph_dirty` option design, which had two problems on real traffic: a read-modify-write option update loses entries when two saves race, and it needs a separate multisite branch. The queue table's `INSERT IGNORE` uses `$wpdb->prefix` at mark time, so it is naturally per-blog under `switch_to_blog()`.

None of the four tables' `CREATE TABLE` statements use `IF NOT EXISTS`, and no timestamp column carries `DEFAULT CURRENT_TIMESTAMP` — both would fight `dbDelta`'s own diff/ALTER path on a later release that changes a column; every writer stamps its own timestamp in PHP instead.

Uninstall: `mcpwp/uninstall.php` (ships in every build — Free, Agency, Freemius) drops all four tables and their options/cron schedules, for the current site and, on multisite, every blog in the network. Deactivation keeps the data. The plugin's OLDER tables (activity log, webhooks, feedback, action log) are a separate, pre-existing gap — see the filed issue — and this file does not touch them.

## Build

A new class, `Mcpwp_Graph_Builder` (core, Free), holds the extraction logic. The logic moves out of the REST controller: link parsing, Elementor `_elementor_data` link and text extraction, taxonomy, parent and media. `class-mcpwp-rest-content-graph.php` becomes a thin reader (delegates to the builder's static methods; its own request/response shape is unchanged — S3 is what points it at the tables). Existing response shapes stay compatible.

- **Readiness gates everything:** `Mcpwp_Graph_Builder::ready()` reads the `mcpwp_graph_ready` option (autoloaded, so this is free — not a per-hook query). Every hook handler checks it first and returns immediately when false. A daily cron (`mcpwp_graph_tables_retry`) retries table creation and re-verifies while it is not yet true.
- **Scope is filtered before marking, not at rebuild:** `is_covered_post_type()` excludes attachments and revisions outright and requires public/queryable, and every hook applies it to the object BEFORE calling `mark()` — a term change on a non-covered post type, or a save on an attachment, never reaches the queue at all.
- **Incremental — mark only, never rebuild inline:**
  - `save_post`, `deleted_post`, `untrashed_post`, `set_object_terms`, `edited_term`, `delete_term`, and `updated_post_meta`/`added_post_meta`/`deleted_post_meta` (scoped to `_elementor_data`, `_thumbnail_id`, `_wp_page_template` — writers like `wp_set_elementor`, find-replace, rollback and "set featured image" update these directly and never fire `save_post`) each do at most one `mark()` call: an `INSERT IGNORE` into `mcpwp_graph_queue`, then schedule the worker. A request that edits 400 posts writes 400 queue rows and returns; it never touches the graph tables.
  - **The one exception:** inside an MCPWP-authenticated request (flagged by `Mcpwp_REST_API::check_permission()` the instant a request passes API-key auth — the chokepoint every route's permission_callback runs through, direct HTTP/`/batch`/`/mcp` alike), `on_shutdown()` rebuilds up to 5 of THAT request's own marked nodes within a 1s budget, so the agent that made the edit sees its own edges in the same response.
  - Everything else is async: `run_worker_batch()` (an Action Scheduler async action when present, else a WP-Cron single event 60s out) pops up to 25 node keys off the queue per pass, rebuilds each through `rebuild_node_safely()` (catches and logs, never fatals), and self-reschedules while the queue is non-empty, bounded to a 5s budget per pass.
  - A rebuild upserts the node, then replaces its outgoing builder-sourced edges (and the one `parent_of` edge it is authoritative for). Only the builder's own edges are replaced; gsc and agent edges are left alone.
  - Rebuild is skipped when `content_hash` is unchanged — one comparison, covering content, Elementor data, status, slug, parent, thumbnail, page template, menu order and terms together (see Schema).
  - Trash is not delete: a post moving to `trash` gets a narrow status-only update, keeping every edge. Untrash marks it for a normal rebuild. A **permanent** delete first re-marks every inbound `links_to` source and every `parent_of` child, THEN deletes the node and its edges — so dependents get reconsidered once the target is actually gone.
  - Inbound edges need no fan-out, because they are stored once, as someone else's outgoing edges.
- **Batched link resolution:** every href on a post is resolved in one query, not one query per link — direct `?p=`/`?page_id=` ids and same-host path slugs are collected first, then looked up together (`ID IN (...) OR post_name IN (...)`), with a per-run cache so repeated hrefs across posts in the same worker pass are free. Anything still unresolved falls back to `url_to_postid()`, capped at 10 calls per post (a documented limit against custom rewrite structures a plain slug/ID match cannot cover).
- **Backfill:**
  - `mcpwp_graph_backfill` runs 50 posts per run, ordered by ID, with a cursor option saved after **every** post (not once per batch), so a killed process loses at most one post's progress.
  - Each post goes through `rebuild_node_safely()` — a throwing filter skips that one post and the batch continues.
  - It is scheduled on upgrade and self-reschedules unconditionally while not done. Duplicate-schedule guards check for a PENDING Action Scheduler action specifically (`as_get_scheduled_actions(status: pending)`), not `as_has_scheduled_action()`, which kept matching a long-finished action's record and would otherwise permanently block every batch after the first.
  - It uses Action Scheduler (`as_enqueue_async_action`) when the function exists, and WP-Cron single events otherwise.
  - Batches have a time budget (≤10 s per run) in the style of `Mcpwp_Signals`. Once done, `mcpwp_graph_built_at` records when.
- **Reconcile (stale after deactivation):** on activation/upgrade, if the backfill is already `done`, every covered post modified after `built_at` is queued directly, and a cheap hash-comparison sweep (batches of 200, run by the worker once the queue is empty) walks every covered post looking for a `content_hash` mismatch — a plugin deactivated for weeks while content kept changing (theme code, WP-CLI, another plugin) had nothing marking it.
- **Deleted objects:** delete the node and all edges from or to it (after the dependent-requeue above).
- **DB errors never surface in a REST response:** every graph query runs through a helper that suppresses `$wpdb` errors, restores the previous suppression state, and logs at most once per request — a dropped/broken graph table degrades a save to "no graph update", not a failure.
- **Queries:**
  - `Mcpwp_Search_Performance::import_rows` also writes `query:` nodes, `targets_query` edges (page→query, weight=clicks, props impressions/position), and signals (clicks/impressions/ctr/position per page per period).
  - A one-time migration folds the existing option rows into the tables. The options stay readable for one release, then are removed in 3.14.9.
- **Scale target:** 20k posts and 200k edges. That requires indexed lookups only, no full-table PHP loops in request paths, and pagination everywhere. Measured on S1's rig: a single post with 200 distinct-target links (`?p=` hrefs) rebuilds in 0.092s with 10 total queries (batched resolver + one multi-row edge insert, not one query per link).
- **Bulk marking is batched too:** `mark()` only buffers in memory; the whole request's marks are flushed as one (or one per <=500-row chunk) `INSERT IGNORE` at `shutdown`, not one query per mark(). Measured on the rig: 400 individual per-call inserts cost ~10ms each (~4.05s total, dominated by this container's MySQL commit latency) before this batching; buffered, 400 `mark()` calls cost ~0.001s and still land all 400 rows once the request ends.

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

1. **S1: storage and builder.** Tables (incl. the queue table), `Mcpwp_Graph_Builder`, mark-only incremental hooks + async worker, backfill, reconcile, `uninstall.php`, `Mcpwp_Graph_Signals::upsert`. Tests: builder extraction (content links, Elementor links, terms, media), mark/queue/worker handling, trash-keeps-edges, permanent-delete requeues dependents, backfill through the real scheduler path (Action Scheduler present and absent), a throwing filter not stalling the backfill, idempotent signal upsert. Rig: 300+ posts backfilled, edit one post over REST and its edges update in the same response, a 400-page bulk edit finishes in seconds with the queue draining after, batched link resolution measured on a 200-link post.
2. **S2: read surface.** Resource, 3 tools, REST routes, `graph_status`, capability filtering. Tests per filter, pagination, a draft leak test (an author-scope key must not see another user's draft). This one gets an adversarial review, because it is a data-egress surface.
3. **S3: search-performance fold-in and content-graph REST on tables.** Import writes nodes, edges and signals. One-time migration. `/content-graph` reads the tables with compatible output. Enables wp_seo_flags `untargeted`.

## Acceptance (#943 closes when)

- On a 1k-post rig, after backfill, `wp_graph_query {orphan:true}` matches a brute-force orphan count exactly.
- Edges reflect an edit by the next worker run (≤2 min), or the same request for MCPWP-made edits.
- Trashing a post keeps its inbound edges; restoring it changes nothing about them; permanently deleting it requeues every post that linked to it and every child.
- A search-performance import shows up as `targets_query` edges and signals, and `wp_graph_node` shows the page's clicks trend.
- Deactivating and reactivating keeps the data and reconciles anything that changed while inactive. `uninstall.php` removes the four graph tables, on every build.
- A large bulk edit (hundreds of posts in one request) stays fast — it only writes queue rows — and the queue drains afterward.
- The full suite is green, and the adversarial review of S2 is clean.
