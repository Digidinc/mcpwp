# Brief: #1042 Approvals store moves to its own table

**Model:** Opus builder (a multi-file migration on a write path). **Review:** adversarial, in
parallel, repeated until clean. **Decisions below are binding.** If code contradicts one, stop
and report rather than improvising.

Read GitHub issue #1042 first: `env -u GITHUB_TOKEN gh issue view 1042 --repo Mumega-com/mcpwp`.

## Pins
Use the pins from `docs/plan/OPERATING-RULES.md` §2 verbatim: repo root, worktree `../wt-1042`
on branch `fix/1042-approvals-table` from `origin/main`, `env -u GITHUB_TOKEN gh`, never touch
the shared rig, own containers removed by exact name, never prune, full phpunit suite green, no
version or changelog edits, conventional PR title `fix(approvals): …`, `Closes #1042`, don't
merge, and a reply with a receipt.

## Why
All approvals live in one serialized option, `mcpwp_approval_requests`
(`Mcpwp_Approvals::OPTION_NAME`), which is rewritten whole on every change. That forced a
site-wide `GET_LOCK` (`mutate()`), whole-store byte budgets (`store_max_bytes()`,
`evict_closed_by_bytes()`) and `max_allowed_packet` handling. It's also why history had to be
evicted after 7 days.

## Design (binding)

### Table
`{$wpdb->prefix}mcpwp_approvals`, created through dbDelta. Add its `CREATE TABLE` SQL as a
factored function next to `action_log_create_sql()` in `includes/class-mcpwp-activator.php`, and
call it from `create_tables()`:

| column | type | notes |
|---|---|---|
| `id` | varchar(64) PK | the existing approval id string, unchanged |
| `status` | varchar(20), indexed | same values as today (`pending`, `approved`, `applying`, `applied`, `apply_failed`, `rolling_back`, `rolled_back`, `rejected`, `expired`, …; use whatever the code defines) |
| `action` | varchar(64) | |
| `requested_by_key_id` | varchar(191), indexed | requester principal (key id, `webmcp:user:N`, OAuth/broker prefix) |
| `created_at` | datetime (UTC), indexed | |
| `updated_at` | datetime (UTC) | |
| `version` | bigint unsigned, default 0 | optimistic-concurrency counter |
| `record` | longtext | the full record array, JSON-encoded (`wp_json_encode`), exactly as the option held it |

Store the whole record in `record` so every existing reader keeps working unchanged. The other
columns are copies used for filtering, ordering and counting. A single private `row_from_record()`
writes them, so they can't drift.

### Concurrency: optimistic, per row
- New private `update_one( $id, callable $change )`: read the row (`record`, `version`), call
  `$change( array $record ): array|WP_Error`, then
  `UPDATE … SET record=%s, status=%s, …, version=version+1 WHERE id=%s AND version=%d`.
  0 rows affected means someone else won. Retry the read-modify-write up to 5 times, then return
  `approvals_busy` (503), the existing error. A `$wpdb` failure (false) returns
  `approvals_store_failed` (503). A success is never reported without the row being written.
- **Status transitions** (approve, reject, claim for apply, apply result, rollback, resolve_stale)
  all go through `update_one`. The claim (`approved` → `applying`) must stay exactly-once: two
  concurrent applies must leave one winner and one refusal. Test it.
- **Create:** the per-requester pending quota (`MAX_PENDING_CONTENT_PER_REQUESTER`), the total
  (`MAX_PENDING_CONTENT_TOTAL`) and `MAX_ITEMS` are checked with `SELECT COUNT(*)` under a
  short named lock held only for check-plus-INSERT (`GET_LOCK('mcpwp_approvals_create_'.prefix,
  5)`). No other operation takes a global lock.
- **Expiry** (`PENDING_CONTENT_TTL`) is one SQL
  `UPDATE … SET status='expired' … WHERE status='pending' AND created_at < %s`, plus updating
  the JSON `record` for those rows (select the ids, then `update_one` each). Run it lazily at list
  time, at most once per request.

### What goes away
`store_max_bytes()`, `evict_closed_by_bytes()`, `STORE_MAX_BYTES`, `PACKET_MARGIN_BYTES`, the
whole-store budget in `mutate()`, and the 7-day eviction of closed records. Keep the **per-record**
limits (`MAX_PROPOSED_CONTENT_BYTES*`, `MAX_ROLLBACK_INLINE_BYTES` with the revision-reference
fallback) and the per-requester quotas.

**Retention:** closed records are kept 90 days (filter `mcpwp_approvals_closed_retention_days`),
then deleted by a daily cron in batches of 500. Each deletion still writes the
`approval_record_removed` audit row to the action log first, as today.

### `mutate()` callers
`mutate( callable(array $items) )` hands out the whole store and can't survive. Replace it:
- `includes/core/class-mcpwp-content-guardrails.php` `record_apply_result()` → `update_one()`
  (make it public as `Mcpwp_Approvals::update_request( $id, callable $change )`).
- `includes/pro/api/class-mcpwp-rest-staging.php` (~line 359) → the same.
- `includes/class-mcpwp-activator.php` `migrate_identity_less_approvals()` → run it against the
  table after migration (SQL select of actionable rows with empty requester, then delete).
- Delete `mutate()`. Grep for every `OPTION_NAME` / `mcpwp_approval_requests` use and convert
  it. The only reader of the old option left afterwards is the migration.

### Listing and visibility
`list_requests()` uses SQL for status filter, order (date / triage priority) and a paged fetch.
**Visibility stays in PHP** through the existing chokepoint `is_approval_visible_to()`. Don't
re-implement category logic in SQL; a second implementation is how #1025-class leaks happen.
Because visibility filtering runs after SQL, fetch in batches (for example 200 rows) until
`$limit` visible records are collected or rows run out, so a restricted key still gets full
pages. `get_request()` / `not_found_error()` parity (#1026) must be unchanged: a hidden id
answers byte-identically to a missing id.

### Migration (runs once, on upgrade)
- Gate on its own flag `mcpwp_approvals_table_migrated`, not only on `mcpwp_db_version` (see the
  activator comments at ~193, 364 and 483 for why).
- Take `acquire_migration_lock()` (the activator's existing pattern). While another request holds
  it, approval reads and writes return `approvals_busy` (503, retry_after 2). No dual-read path:
  the store holds at most `MAX_ITEMS` records, so the copy finishes within one request.
- Copy every record from the option with `INSERT IGNORE` by id (idempotent, so a crashed
  half-migration just re-runs). Verify the table count ≥ the option's record count. Only then set
  the flag.
- **Don't delete the old option in this release.** Rename it to
  `mcpwp_approval_requests_pre_3149` (autoload off), so a downgrade or a bad migration can be
  recovered by hand. A later release removes it (open a follow-up issue).
- `uninstall.php` drops the table (self-hosted/agency builds). Check `.distignore` / the Freemius
  build: uninstall.php must NOT ship in the Freemius build (it disables Freemius's uninstall
  hook; see #993).

### Public API that must not change
`create_post_content_request`, `create_destructive_request`, `create_bulk_publish_request`,
`list_requests` (same signature and return shape), `get_request`, `approve_request`,
`reject_request`, `apply_request`, `rollback_request`, `set_triage`, `resolve_stale`,
`purge_closed`, `rollback_available`, `redact_source_for_unprivileged`,
`compute_visibility_categories`, `categories_for_approval`, `is_approval_visible_to`,
`not_found_error`, `content_limits`, `open_statuses`, `requester_byte_quota` (may now return a
constant). REST responses for `/approvals*`, the MCP approval tools and WebMCP
`wp_propose_content_change` must be byte-identical for the same data. Run the existing approvals
tests unchanged first; they are the contract.

## Tests (add, and show each new one fails on origin/main first)
1. Migration: option with N records (mixed statuses, one over 128 KB with a rollback revision
   reference) → table with N rows, records deep-equal, flag set, option renamed. Re-running is a
   no-op.
2. Concurrent apply: two `apply_request()` calls on one approved id → exactly one applies, one
   refused. Simulate by forcing a version bump between read and write.
3. 10 concurrent creates (simulated via the create lock) → 10 rows.
4. Quotas: the 21st pending content proposal from one requester is refused. Total cap refused.
5. Expiry: a 31-day-old pending request becomes `expired` and can't be approved.
6. Visibility paging: 50 records, 45 hidden from a restricted key, `limit=5` → exactly 5 visible
   records returned.
7. Parity: hidden id vs missing id `get_request` → identical error.
8. Retention cron deletes 91-day-old closed rows, writes the audit row first, never touches open
   rows.
9. A `$wpdb` write failure on update → `approvals_store_failed`, and the record is unchanged.

## Rig proof (own containers, real HTTP)
Install the live 3.14.8 ZIP, create approvals of every kind (post content through the API,
destructive delete, bulk publish, WebMCP proposal as an editor via cookie plus nonce), approve
and apply one, and leave one pending. Upgrade to your build. Then show:
- `/approvals` lists identical records before and after (diff the JSON)
- the pending one can be approved, applied and rolled back
- the migration flag is set and the option is renamed
- `scripts/release_smoke.sh` passes against your build:
  `bash mcpwp/scripts/build-selfhosted.sh && bash scripts/release_smoke.sh --candidate mcpwp/scripts/mcpwp-selfhosted-<ver>.zip --name smk1042 --port 9142`

## Adversarial review focus (for the reviewer brief)
Lost updates and double-apply under concurrency; the migration racing a write; a restricted key
seeing a hidden approval through list paging, counts, `/events`, `/site-state` or Control Room;
not-found parity; the WebMCP quota bypass; uninstall.php in the Freemius ZIP; rollback of an
approval migrated from the option.
