# Issue Intake

Every place a problem or request can enter — user feedback, the WordPress.org forum,
Dependabot, the update-channel canary, PostHog error codes, Freemius — used to land in a
different place with a different amount of visibility. `scripts/intake_triage.py` pulls all
of it onto one board, triaged, so nothing sits unseen until someone happens to look in the
right place. Built for #940.

## Sources

| # | Source | Credentials | Behaviour with none |
|---|--------|-------------|----------------------|
| 1 | GitHub issues on `Digidinc/mcpwp` not yet on org project #2 | `gh auth login` (already required) | n/a — always runs |
| 2 | Open Dependabot alerts, counted by severity | `gh` with repo security-events read access | Reported as "unavailable", run continues |
| 3 | Latest **Update Channel Canary** workflow conclusion | `gh` (public Actions read) | Reported as "unavailable", run continues |
| 4 | PostHog `mcp_tool_called` error-code counts (`scope_denied`, `pro_required`, `tool_error`, last 7 days) | `POSTHOG_API_KEY` + `POSTHOG_PROJECT_ID` env (optional `POSTHOG_HOST`) | Skipped, clearly labelled — no query is attempted |
| 5 | WordPress.org support forum RSS for `mumega-mcp` (public feed, no auth) | none | Always attempted; a fetch failure is reported, not fatal |
| 6 | Freemius support tickets | `FREEMIUS_BEARER_TOKEN` + `FREEMIUS_PRODUCT_ID` env | Skipped, clearly labelled. The ticket endpoint this calls is a best-effort probe — confirm the real shape against Freemius's docs before relying on it. |

Source 1 is where in-plugin feedback already lands (relay → GitHub `user-feedback` issues) —
this script is what gets those, and any other open issue, onto the board with a category and
a priority instead of sitting in the repo's issue list unseen.

## Classification

Each new GitHub issue is classified into one of **setup / bug / feature / duplicate /
security** by keyword + label rules in `classify_rules()` (see the source for the exact
keyword lists) plus a duplicate check: `difflib.SequenceMatcher` title similarity ≥ 0.85
against every other open issue. A label (`security`, `bug`, `enhancement`/`feature`) wins
over keyword-sniffing when present; a near-duplicate title wins over everything, because
that is the action a human triager actually takes on it.

**The rules run unconditionally and are the whole classifier by default.** Passing
`--use-jev` additionally lets [Jev](/home/mumega/scripts/jev/jev.mjs) (TypeSafe's evaluation
model) answer a `choice` question over the same five categories; its answer only overrides
the rules-based one when Jev is confident (probability ≥ 0.6) and `TYPESAFE_API_KEY` is set.
With no key, or no `--use-jev`, or a `node`/script problem, `jev_classify()` returns `None`
and the rules-based category stands — nothing about the tool depends on Jev being reachable.

## Fields written to the board (`--apply` only)

Resolved dynamically every run via `gh project field-list 2 --owner Mumega-com --format
json` — field and option IDs are never hardcoded, because they are workspace-specific and
not guaranteed stable across projects.

| Category | Status | Priority | Track | Version |
|---|---|---|---|---|
| security | Todo | P1 | Reliability | 3.14 Launch |
| bug | Todo | P1 | Reliability | 3.14 Launch |
| feature | Todo | P2 | — | 3.14 Launch |
| setup | Todo | P2 | — | 3.14 Launch |
| duplicate | Todo | P2 | — | 3.14 Launch |

A `duplicate` classification is informational (see it in the summary as "Dup of #N") — the
tool does not close or comment on the issue itself. A human decides whether it really is one.

## Digest issue (Dependabot + forum only)

Dependabot alert counts and new forum topics are bundled into **at most one digest issue per
run**, labelled `intake`, titled `Intake digest: YYYY-MM-DD` — never one issue per forum post
or per alert. It is only opened when something is actually new relative to the last run
(alert count grew for some severity, or the forum has topics newer than the last one seen).
On a cold run (no state file yet) nothing is "new" by definition, so no digest fires; the
summary still prints the current counts.

The canary's own conclusion is reported in the summary/digest for visibility, but this script
never files or closes a canary issue itself — `update-channel-canary.yml` already owns that.

## Running it

```bash
# Dry run against the real repo — prints the Markdown summary, writes nothing.
python3 scripts/intake_triage.py

# Only issues since a specific point in time.
python3 scripts/intake_triage.py --since 2026-09-01T00:00:00Z

# Actually add new issues to the board and (if warranted) open the digest issue.
python3 scripts/intake_triage.py --apply

# Let Jev override the rules-based category when confident.
TYPESAFE_API_KEY=... python3 scripts/intake_triage.py --apply --use-jev
```

State (last run time, last Dependabot counts, last forum topic seen, last canary
conclusion) is kept in `~/.cache/mcpwp-intake/state.json`, written only on `--apply` runs.
Delete it to force a cold run.

### Cron (suggested — daily, offset off the hour like the canary)

```cron
17 7 * * * cd /path/to/wp-ai-operator && /usr/bin/python3 scripts/intake_triage.py --apply >> ~/.cache/mcpwp-intake/run.log 2>&1
```

Pipe stdout to wherever the Monday brief is assembled from — the printed summary is already
Markdown ready to paste in.

## Owner-only step

**Enable the project's built-in "Auto-add to project" workflow** for labels
`user-feedback`, `bug`, `security`:

> GitHub → org `Mumega-com` → Project #2 (MCPWP) → **Workflows** (top-right ⋯ menu) →
> **Auto-add to project** → add a filter for `is:issue label:user-feedback,bug,security` →
> enable it.

This is a per-project setting with no CLI/API equivalent exposed to a repo-scoped token, so
it has to be flipped by a human with access to the organization's project settings. Once on,
newly-labelled issues land on the board immediately instead of waiting for this script's next
run — the script still classifies and prioritizes them, and still catches anything the
auto-add workflow's label filter doesn't (an issue filed without one of those three labels).

## Privacy notes

- No secrets are read from anywhere but the environment (`POSTHOG_API_KEY`,
  `POSTHOG_PROJECT_ID`, `POSTHOG_HOST`, `FREEMIUS_BEARER_TOKEN`, `FREEMIUS_PRODUCT_ID`,
  `TYPESAFE_API_KEY`) — none are ever written to the state file, the printed summary, or a
  digest issue body.
- The PostHog query only aggregates counts by `error_code`; it never asks for or prints
  per-site UUIDs, IPs, or any other identifying field from `mcp_tool_called`.
- The Jev call sends only an issue's public title and body (already visible to anyone with
  repo read access) as `state`. Per `jev.mjs`'s own README, assume request content may be
  retained by TypeSafe — this is the same exposure as the issue already being public on
  GitHub, not a new one.
- The forum source only ever reads the public WordPress.org RSS feed; nothing is posted
  there.
