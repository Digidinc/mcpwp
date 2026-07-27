# WORK BRIEF — wp.org submission build (from Kasra)
request_id: 37cd5782-6923-490a-b971-6df5b8b46802 (ACK on bus once SOS wired; until then reply via this file's `## ACK` section)

**Trigger order: mumcp builds → mumcp reports → Hadi uploads → Bardiya reviews follow-ups.**

DECISION (Hadi, 2026-07-08): public name = **mumcp** (byline "by Mumega"). Slug `mumcp`. wp.org rejected the old mcpwp name (wp mark).

BRANCH MODEL (binding): `main` belongs to Bardiya (hrahimi270) — active security/CI work; do NOT rebase/rewrite over his flow. Create long-lived branch **`wporg`** cut from tag v3.4.3. Shared fixes: cherry-pick main→wporg only.

DELIVERABLE (Hadi uploads manually):
1. Submission-ready zip from `wporg` branch: display name "mumcp", slug `mumcp`, readme.txt wp.org format (stable tag, tested-up-to, GPL-2.0+; description capturing "Mumega MCP" search equity: "mumcp — Model Context Protocol for WordPress, by Mumega").
2. KEEP internal `mcpwp_*` prefixes — no data migration for live installs.
3. **Build with MCPWP_WPORG_BUILD flag** (free-lock exists in Mcpwp_License) + **strip/disable self-updater** — wp.org forbids external update engines. Updater stays agency-channel only. Do NOT weaken Bardiya's hardening (PRs #586/#588/#589).
4. Compliance pass: PostHog telemetry must be opt-IN for the wp.org build (guideline: no phoning home without consent), GPL-compatible deps only, no obfuscation, Freemius kept OUT of wp.org build unless going the freemius-wp.org route — flag this decision to Hadi: options are (a) clean free build + upsell link to mcpwp.net, or (b) Freemius-integrated wp.org build (allowed but heavier review).
5. Assets: icon 256x256, banner 1544x500, 2-3 screenshots.
6. SUBMISSION.md: exact upload steps + reviewer-response tips (incl. legacy-prefix answer).

Free tier ONLY in wp.org build (161 free tools; 117 pro stay gated out). Pro/agency = direct channel.

## ACK
**mumcp, 2026-07-08:** {ack_for:37cd5782-6923-490a-b971-6df5b8b46802, ok:true} — also sent on bus (msg 1783478949887-0). Brief understood. Executing on `wporg` branch cut from v3.4.3 tag; main untouched.

**ETA:** ~3-4h for zip + SUBMISSION.md + assets.

**Decisions taken (flag to Hadi):**
1. Freemius: proceeding with option **(a)** clean free build + upsell link — Freemius SDK stripped from wp.org zip. Override to (b) if Hadi wants Freemius-integrated review path.
2. Upsell link target: mcpwp.net (question open — new mumcp domain?)
3. Assets: generating from mcpwp.net brand palette; if a design source (Figma) exists, point me at it.

## DONE (mumcp, 2026-07-08)
- **Zip:** `/mnt/HC_Volume_104325311/projects/sitepilotai/wporg-build/mcpwp/scripts/mumcp-3.4.3.zip` (552K, 167 files)
- **SHA256:** `2725b769528a4e2d8a8870d0e26d8a61c6fcaf83dce545101038e4277a5f3611`
- **Branch:** `wporg` pushed to origin, 5 commits on tag v3.4.3 (6fd3c4a rebrand, 0949d8c hide update tools, c6f9f1e Plugin Check fixes, 3ee122d assets, d04fda3 SUBMISSION.md)
- **Verification:** plugin-check 2.0.0 → 0 errors; phpunit 283 pass (1 pre-existing skip); live rig: MCP handshake OK, 171 free tools, update/custom-css tools absent, Pro tools return pro_required upsell; free-lock injected; no Freemius/pro/updater in zip
- **Assets:** `mcpwp/assets/` on wporg branch — icon 128/256/300, banners, 4 screenshots, mumcp-branded
- **Docs:** `SUBMISSION.md` at wporg branch root — upload steps + reviewer Q&A
- Kasra gates zip (bus msg 1783480413917-0), Hadi uploads. Dev rig restored, main untouched.
