# MCPWP 3.11 — Truth & Reliability

Status: proposed 2026-09-10. Precedes 4.0 (`ROADMAP-4.0.md`).

## Why 3.11 before 4.0

The September 2026 dogfood audit (#797–#831), the silent-success class (#785) and the bulk-reliability report (#802) all describe one defect: the plugin reports success that did not happen, and bulk work breaks under load. 4.0's pipeline and gateway work builds on this surface. A surface that lies is not a foundation.

House rule for every item below: **an unavailable source and an empty result must never produce the same output.**

## Scope, in execution order

### 1. Bulk reliability — #802, #803, #752
- Rate limiter returns `Retry-After` and remaining budget; `initialize` and `status` are never rate-limited.
- No empty `200`. A write that did not land returns an error naming why.
- `initialize` reports plugin version.
- `wp_bulk_update_pages` / `wp_bulk_find_replace` / `wp_batch_update` accept the shapes their names promise; taxonomy writes echo what was assigned.
- One chokepoint: rate limiter + batch dispatcher. No per-tool patches.

### 2. Security — #564, #800, #536
- #564 P0: harden REST Pro gate; decouple stored-key encryption from `AUTH_SALT`.
- #800: option allowlist exact-match both directions; payment-gateway and SMTP secrets masked on read.
- #536: force delete and bulk deletes go through the approval gate by default.
- Adversarial review runs in parallel with correctness review (agent-comms.md rule).

### 3. Elementor write contract — #820, #824, #825, #826, #828, #829
- #820 first: write snapshots + `wp_revert_elementor` for pages, templates, kit CSS, globals. Makes every later change reversible.
- #824: validate setting keys against the live control schema where it is trustworthy; repeater sub-fields; `widget_help` list correct both ways.
- #825: `wp_patch_elementor` documents its op cap, adds `unset`, honours `parent_id`.
- #826: second `wp_apply_elementor_part` regenerates element IDs and inserts.
- #828: `dry_run` exercises the save path without persisting; failed theme-template create leaves no orphan.
- #829: `storage_repaired` reported only when a repair happened.

### 4. Response contract — #799, #830, #831
- `fields` projection on the large reads (onboard, widget schema, summary, get_elementor, introspect, blocks).
- One list envelope: `{items, total, page, per_page, pages}` (the #635 shape).
- Parameter aliases for the 24 inconsistent names, with a `deprecated_params` warning in the response. Implemented once at the REST layer.
- Slug search, `ids=` partial handling, unknown-field reporting, entity-decoded titles.

### 5. Data model — #797, #801
- CPT create accepts meta and taxonomy terms; featured image works for CPTs; meta values are not sanitised as text.
- Post meta: `keys[]` batch read; blocklist is exact-match; core `_wp_*` / `_elementor_*` readable.

### 6. Verification surface — #804, #568
- Rendered HTML complete (head/footer) with a documented size limit and an explicit `truncated` flag.
- Cache purge tool; `render_verified` false on placeholders.
- Effective-template report.

## Deferred to 4.0
#651 progressive tool discovery, #811 native record types, #639/#643 visual QA, gateway epics #538/#553/#663, WP 7.0 items #524–#530.

## Housekeeping
- Re-milestone or close the v2.8.49-era launch tasks (#378–#423).
- #650 tool-count drift, #498 repo About text.

## Definition of done
- Every item has a test that fails under the old behaviour and passes under the new.
- Rig-tested on the local Elementor 4.2.4 instance, not only PHPUnit.
- PHP 7.4 lint clean; suite green in declaration and random order.
- Released through the standard 3-file bump and `publish_update_release.sh`.
