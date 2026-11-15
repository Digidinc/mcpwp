# GitHub Repo Metadata — recommended values (#514)

> STATUS: DRAFT — Hadi applies these via repo **Settings** (description/topics/website) and the **Sponsor** button. I do not change live repo settings.
>
> **The `Mumega-com/mcpwp` repo is currently private.** Do not link it in any public
> launch copy, and do not use the "Open Source" topic/tag anywhere, until
> [OWNER DECISION: make repo public?] is resolved. Everything below only applies once
> (if) the repo goes public.

## Description (#498 / #650)
Live GitHub About text still shows stale counts from before #650/#498. Checked-in source
of truth: **`docs/tool-counts.json`** (generated from the PHPUnit catalog via
`scripts/gen-tool-counts.php`). Do not hand-edit marketing counts elsewhere without updating
that file and the matching docs.

**Recommended description (single source — copy verbatim from `docs/tool-counts.json` → `github_about.suggested`; marketing phrase: 190+ tools free, 300+ with Pro):**

Same text as `Mcpwp_Tool_Count_Snapshot::GITHUB_ABOUT_SUGGESTED` in
`mcpwp/tests/support/class-mcpwp-tool-count-snapshot.php` (regenerated into the JSON by
`php scripts/gen-tool-counts.php`). Do not hand-edit the About blurb in launch docs.

## Topics (current set is already good — minor adds)
Live: ai, claude, mcp, model-context-protocol, woocommerce, wordpress, wordpress-plugin, elementor.
**Add:** `ai-agents`, `cursor`, `seo`, `model-context-protocol-server`. (GitHub caps at 20; all fit.)
**Do not add** `open-source` — the repo is private.

## Website
Set repo **Website** to `https://mcpwp.net`.

## Sponsor button
`.github/FUNDING.yml` added (custom → mcpwp.net/pricing). Fill in GitHub Sponsors handle when the account exists, or remove.

## License
`LICENSE` was a truncated 18-line GPL (GitHub detected it as "Other"). Replaced with the canonical GPL-2.0 text so GitHub's license detector shows **GPL-2.0**, matching readme.txt's `License: GPLv2 or later`. No action needed from you — it's in the PR.
