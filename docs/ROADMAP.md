# MCPWP Roadmap — near term

Written 2026-09-05, the day 3.10.4 shipped. Updated 2026-09-10, the day 3.11.0 and 3.11.1 shipped.
Covers **3.10.x → 3.11 → 4.0**.

The long-form 4.0 architecture plan lives in [ROADMAP-4.0.md](ROADMAP-4.0.md) and is
still the authority on workstreams A–F and T. This document is the shorter question:
what ships next, in which version, and what has to be true before 4.0 is cut.

---

## Where we are

**3.11.1** (2026-09-10) is live on `updates.mcpwp.net`, the legacy `mumega.com` routes and
Freemius, verified on all three after the release job. It follows 3.11.0 the same day and
completes the **Truth & Reliability** security and debt work: 7-character Elementor ids,
`wp_batch` with a per-request spend budget, atomic feedback-relay admission, encryption key
integrity, Pro gating for Figma and SEO market data, and explicit analytics consent on Free.
Suite on main: 1895 tests.

What remains is planned in [ROADMAP-3.11.md](ROADMAP-3.11.md): **3.12** makes Elementor
edits reversible and validated (in progress), **3.13** makes responses consistent.

The history below is kept because the lessons still apply.

### 3.10.4 (2026-09-05)

Live on the canonical and legacy channels and published to Freemius. It carried three things:

| | |
|---|---|
| Eight adversarial-review follow-ups from #744 | #754 |
| Elementor `Document::save()` Throwable guard | #760 |
| Kit CSS byte-for-byte integrity | #761 |

930 tests, 4852 assertions, green on PHP 8.3 and 8.4, production files parse on 7.4.

---

## What the last two releases actually taught us

Worth writing down, because it changes what 3.11 should contain more than any feature
request does.

### One defect class produced most of the damage

> **An unavailable source and an empty result must never produce the same output.**

Every one of these was the same shape, found in one week:

- the relay throttle read `(int) get_transient()`, so "backend down" and "zero requests
  so far" were both `0` — and the output was permission to spend the site's GitHub token
- a GitHub `403` was indistinguishable from "no duplicate exists", so a rate-limited
  relay silently became a duplicate-issue generator
- a batch route that could not be normalised fell through to being dispatched raw
- `ci-watch` returned exit 0 having observed two checks and neither publish job
- a shell pipeline reported a clean residue sweep because a filter errored mid-pipe and
  the final `grep` read empty input as "nothing found"

The last two are not plugin code, which is the point: **this is a habit of mind, not a
module.** It shows up wherever a failure and an absence share a return value.

### Corruption that protects itself is the expensive kind

The Kit CSS defect stored escaped bytes while keeping the marker hash of the *correct*
bytes. So the operator's natural response — re-upsert the same CSS — returned `skipped`
and left it broken. A bug that makes the obvious fix a no-op costs far more than a bug
that simply fails.

### Tests can pass for the wrong reason

Three separate cases: a test asserting three constants equalled the literals declared
beside them; a batch denial test that could not observe a dispatch because the stub took
no arguments; and a write-failure test whose selector matched a newly-added prefix and
so stopped exercising the path it was named for. All green. All proving nothing.

**Consequence for 3.11: a new test must be shown to fail against unpatched source
before it counts.** That is now the standing rule, not a nicety.

### The environment is part of the diagnosis

Two staging defects were invisible locally until the environment matched: one needed
PHP 8.4 *plus* a handler promoting warnings to exceptions; the other needed a request
whose user lacks `unfiltered_html`. A local rig that differs from production in those
axes will keep producing confident false negatives.

---

## 3.10.6 — shipped 2026-09-06

Everything below landed on `main` on 2026-09-06. 3.10.7 and 3.10.8 followed within two days.

| | |
|---|---|
| `'tag' => true` KSES allow-list no longer fatals a write; a dead render is reported | #765 → #766, #768 |
| Repeat part-insert: `isInner` materialised as `false` no longer reads as corruption | #769 → #770 |
| Suite is order-independent; CI runs it `--order-by=random` | #757 → #773 |
| `css_id` → `_element_id` normalised at every `_elementor_data` write, not just the validated ones | #737 → #774 |
| Internal-link issues name the stored copy they came from | #728 → #775 |

**Merged in 3.10.6 after adversarial review:** #772, which stops Elementor rewriting the tree it is
asked to render (the remaining half of #769). It grants a scoped `unfiltered_html`
and suspends Elementor's content-sanitizer for our own `Document::save()` calls, so
it is an adversarial-gate surface and does not merge on CI alone.

### What #769 turned out to be

Worth recording, because two separate reviews read it as a shape normalisation and
it is not. Elementor rewrites the **content** during the HTML-cache rebuild, by two
paths that both run for an API-key request and neither of which runs for an admin in
the browser:

- `Document::save()` runs the payload through `Utils::kses_post_deep()` whenever the
  user lacks `unfiltered_html` — which the `mcpwp_api_agent` role never has
- the content-sanitizer rewrites every heading `title` through `wp_kses()`, and its
  own attribute filter is inverted (`! substr( $a, 0, 5 ) === 'data-'` parses as
  `( ! substr(...) ) === 'data-'`, always false), so **every attribute is stripped
  from every tag**

Neither was protecting anything: MCPWP already stores the caller's raw bytes and
Elementor's frontend renders from that meta. They only ever reached the render cache
— the one copy that has to agree with what we stored. The `manage_options` gate on
that sanitizer is the same asymmetry behind #759 and #765, and is now three defects
deep; it belongs at the top of the upstream report.

## 3.10.5 — shipped 2026-09-05

Backlog cleanup, no new surface:

- **#762** Kit CSS blocks corrupted before 3.10.4 stay corrupted, and idempotency hides
  them. Detection (`hash_mismatch` per block) plus making a corrupt block un-skippable,
  so the retry an operator would naturally attempt becomes the repair.
- **#755** Relay dedupe marker skipped when GitHub reports a duplicate with no
  `html_url` — the fourth state #748 did not classify. Plus escaping the embedded error
  text that crosses into a GitHub issue body.
- **#756** Custom-tool dispatch ignores placeholder substitution when `rest_path` is
  set, so every such tool is silently broken with a misleading `rest_no_route`.

## 3.11 — as first proposed

**Superseded by [ROADMAP-3.11.md](ROADMAP-3.11.md)**, which took this list plus the September
dogfood audit. Status of each item is marked.

Theme: **make the failure modes above structurally impossible, and finish the debt that
3.10.x deliberately deferred.**

1. **Fail-closed audit (#752 F6, F9).** *Scheduled for 3.11.1.* The relay counters are still a non-atomic
   read-modify-write across three ceilings, and one rate-counted request can still drive
   ~12,500 writes through nested fan-out. F6 needs a storage decision (`wp_cache_incr`
   does not compose cleanly with the transient API); F9 needs a spend bound, not a
   sub-request count bound.
2. ~~**Test-order integrity (#757).**~~ **Done (#773).** Four live couplings, not one:
   a leaked `ELEMENTOR_VERSION`, a leaked `update_option()` sabotage switch, a leaked
   cron-mode flag, and six classes leaking `pre_http_request` mocks. Three of the four
   were the same mistake — state cleaned up on the way *in* is not state cleaned up
   after the last test that set it. CI now runs `--order-by=random` as a second pass,
   so the next one fails here rather than months later somewhere unrelated.
3. **Upstream Elementor defects.** #759 (`content-sanitizer/module.php:45` reads
   `widgetType` unguarded) is a one-line fix on their side that affects every plugin
   writing Elementor data as a non-admin. Report it; keep our guard regardless.
4. **Environment parity for the local rig.** PHP 8.4 and an Elementor install are now
   available locally; make that the default test target rather than an ad-hoc container,
   and add a non-admin request path to the harness.
5. ~~**Release-verification honesty.**~~ **Done.** The release job now fails unless
   `verify_freemius_channel.sh` confirms Freemius serves the new version. Originally: `verify_freemius_channel.sh` cannot run without a
   token and correctly exits 1 — but the release currently completes without it. Wire
   the token into the release environment so all three channels are *verified*, not two
   verified and one asserted by a workflow's own exit code.

Explicitly **not** in 3.11: new tool surface. The tool count is not the constraint.

## 4.0 — when, and what gates it

**Target: when workstreams A and C are done, not on a date.** Best estimate on current
velocity is **Q4 2026**, and that estimate is only honest if the two gates below hold.

4.0 is a major version because it changes contracts, not because it adds features
([ROADMAP-4.0.md §Why 4.0](ROADMAP-4.0.md)):

| Gate | Epic | Why it is a 4.0 blocker |
|---|---|---|
| **A — Release engineering** | #661 | Channel divergence is hand-assembled today. A packaging bug of the 3.5.x class must become structurally impossible before the extension contract changes underneath it. |
| **C — Gateway architecture** | #663 | ADR 0001's Gateway + Modular Monolith changes internal structure and the addon contract. That *is* the major-version event. |

Already delivered and no longer gating: **B — Zero-Touch Connect** (#662) landed as a
hosted broker rather than per-site OAuth.

Adjacent but not gating 4.0: **T — Mumega Motion** (#664), **wp.org round 5** (#665),
**agency gateway custody** (#549), **accessibility** (#671).

### The honest risk

The near-term backlog is small and well-specified; the 4.0 gates are large and mostly
unstarted. The failure mode is spending every cycle on tractable 3.10.x items — which
feel productive and are individually justified — while A and C never start. If two more
patch releases ship without a commit against #661 or #663, that is the signal, and the
right response is to stop taking backlog items for one cycle.

---

## Working rules earned the hard way

These are not style preferences. Each one is here because its absence cost real time.

1. **A new test must be shown to fail against unpatched source.** Green proves nothing
   on its own.
2. **A caught error is reported, never swallowed.** An API that could not be called and
   one that worked must not produce the same response.
3. **Adversarial review runs in parallel** on auth, rate-limit, credential-spending and
   external-facing surfaces — 3.10.1 shipped two P0s past four self-review passes.
4. **Never `git add -A`.** Path-scoped commits only; repo-root state has reached a
   published artifact before.
5. **Never rewrite shared history to fix your own mistake.** Fix forward. A `reset` in a
   shared tree can destroy someone else's commit.
6. **Parallel agents in one working tree share an index.** Path-scoped commits protect
   against foreign *files*, not foreign *edits to your own file* — `git commit -- path`
   commits that file's current on-disk content. Give agents disjoint files, and never
   make a temporary edit to a file you do not own.
7. **Re-verify CI against the live API before an irreversible action.** A watcher's exit
   code is a wake-up, not authority.
