# MCPWP 3.11 — Truth & Reliability

Status: **3.11.0 shipped 2026-09-10.** Remaining scope ships as 3.11.1, 3.12 and 3.13, below. Precedes 4.0 (`ROADMAP-4.0.md`). GitHub milestone 14 tracks every item.

## Why 3.11 before 4.0

The September 2026 dogfood audit (#797–#831), the silent-success class (#785) and the bulk-reliability report (#802) all describe one defect: the plugin reports success that did not happen, and bulk work breaks under load. 4.0's pipeline and gateway work builds on this surface. A surface that lies is not a foundation.

House rule for every item below: **an unavailable source and an empty result must never produce the same output.**

## Shipped in 3.11.0

| Change | Issue | PR |
|---|---|---|
| Batch weight: a call carrying N operations is charged N; diagnostic bucket for `initialize`, status and site info; one auth verdict per request | #802 | #833 |
| A second installed MCPWP copy is refused and the double load is reported | #802 | #834 |
| Idempotent tool calls through `params._meta.request_id` | #802 | #835 |
| Find/replace scopes, bulk update shapes, wide-run admin gate | #803 | #836 |
| Terms and meta on create/update; featured image for any thumbnail-capable type | #797 | #837, #840 |
| Option access floor, secret-preserving writes, redacted logs | #800 | #838 |
| Destructive operations need approval by default | #536 | #839 |

Every PR passed an adversarial review loop before merge. #838 took nine rounds and #839 eight, and each round found a real defect: a stored-XSS widening, a root-scalar credential wipe, a gate keyed on the force flag while WordPress deletes non-post types outright, and a deferral that turned a capability refusal into an admin-applied delete. Suite at release: 1502 tests.

Behaviour changes are listed under **Upgrade Notes** in `mcpwp/CHANGELOG.md` and reach both update screens.

## Principle for what remains: wrap before build

Where WordPress core, Elementor or a common plugin already does the job, MCPWP exposes it instead of building a second copy. A second copy drifts, and drift is how the plugin ends up reporting things that are not true.

| Issue | Build that was planned | What exists to wrap |
|---|---|---|
| #820 | Snapshot store for Elementor writes | WordPress revisions, which Elementor already uses for `_elementor_data`; the kit is a post too |
| #824 | Hand-maintained widget control registry | Elementor's live control definitions per widget, including repeater fields |
| #568 | Cache layer | Purge hooks of whatever is installed: Elementor files manager, LiteSpeed, WP Rocket, W3 Total Cache, Cloudflare |
| #804 | Screenshot via mshots | The Screenshot Worker integration (Cloudflare Browser Rendering) |
| #801 | Curated meta blocklist | `register_meta` with `show_in_rest` as the signal that a key is meant to be read |
| #799, #830, #831 | New list and projection conventions | WordPress REST names: `per_page`, `page`, `_fields`, total counts |
| #564 | Custom encryption scheme | PHP's built-in sodium secretbox with a key generated at activation |

The issues genuinely MCPWP's own are #802's remaining gaps, #825, #826, #828, #829 and #752.

## Release plan

### 3.11.1 — security and debt

- **#564 P0.** Harden the REST Pro gate. Stored keys are encrypted with a key derived from `AUTH_SALT`, so rotating salts makes them unreadable; move to sodium with a dedicated key. Adversarial review required.
- **#752.** Residual findings F6, F7, F9–F12 from the #744 review: non-atomic relay counters, and a spend bound for nested fan-out.
- **#802 remainder.** Plugin version in `initialize`; a batch path for bulk work. Close or split once verified against main.
- **Reviewer follow-ups from 3.11.0:** the webhook delivery log stores payloads unredacted; `wp_bulk_find_replace` and `wp_delete_all_drafts` are charged one rate-limit slot; other settings checkboxes flip off when the form omits them.
- **Freemius verification downloads the premium ZIP**, not only the release flag.

### 3.12 — Elementor you can trust

- **#820** Revision list and `wp_revert_elementor` over WordPress revisions, for pages, templates, kit CSS and globals.
- **#824** Validate setting keys against Elementor's live controls; the static registry stays only as an offline fallback. `widget_help` correct both ways.
- **#825** `wp_patch_elementor` documents its op cap, adds `unset`, honours `parent_id`.
- **#826** A second `wp_apply_elementor_part` regenerates element ids and inserts.
- **#828** `dry_run` exercises the save path without persisting; a failed theme-template create leaves no orphan.
- **#829** `storage_repaired` reported only when a repair happened.
- **#568** One purge tool fanning out to the installed caches; `render_verified` false on placeholders.
- **#804** Complete rendered HTML with an explicit `truncated` flag; Screenshot Worker by default; effective-template report.

### 3.13 — consistent responses and data model

- **#799** One list envelope `{items, total, page, per_page, pages}`, slug search, `ids=` partial handling, unknown-field reporting, entity-decoded titles.
- **#830** `fields` projection on the large reads: onboard, widget schema, summary, get_elementor, introspect, blocks.
- **#831** Aliases for the 24 inconsistent parameter names with a `deprecated_params` warning, implemented once at the REST layer.
- **#801** `keys[]` batch read; readable when registered for REST; core `_wp_*` and `_elementor_*` readable where safe.

## Deferred to 4.0

#651 progressive tool discovery, #811 native record types, #639/#643 visual QA, gateway epics #538/#553/#663, WP 7.0 items #524–#530.

## Housekeeping

- Re-milestone or close the v2.8.49-era launch tasks (#378–#423).
- #650 tool-count drift, #498 repo About text.

## Definition of done

- Every item has a test shown to fail under the old behaviour and pass under the new.
- Checked over real HTTP on the local rig, not only PHPUnit.
- PHP 7.4 lint clean; suite green in declaration and random order.
- Auth, credential, write and external surfaces pass adversarial review before merge.
- A behaviour change carries an `### Upgrade Notes` bullet in `mcpwp/CHANGELOG.md`.
- Released by merging the release-please PR; the release fails unless `updates.mcpwp.net` and Freemius both serve the new version.
