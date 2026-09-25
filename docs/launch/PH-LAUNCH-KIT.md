STATUS: DRAFT — routes through Hadi's Telegram gate before any publish.
Target launch: [OWNER: pick date]. Slot: 00:01 PT (or owner's choice).

---

# Product Hunt Launch Kit — MCPWP

---

## Pre-launch checklist

Every claim below has been checked against `origin/main` (3.13.0) and live surfaces as of
2026-09-25. Nothing below should ship until this checklist clears.

### Owner decisions (blocking)

| Item | Status |
|---|---|
| Pick launch date | OPEN |
| Confirm a test purchase end-to-end on mcpwp.net/pricing (Freemius checkout loads; purchase flow itself not yet verified) | OPEN |
| Make the `Mumega-com/mcpwp` repo public? Currently private — PH "Open Source" topic and repo link are held out of public copy until this is decided | OPEN |
| MCPWP vs mumcp naming (see #688) | OPEN |
| Secure a hunter with PH standing in AI-tools / developer-tools | OPEN |
| Produce gallery + demo-video assets (real screen recordings from the dev rig — see DEMO-VIDEO-SCRIPT.md §7 and REMOTION-SPEC.md §7) | OPEN |

### Engineering status

| Item | Status |
|---|---|
| Release pipeline blocked on GitHub Actions billing — #878/#879/#808/#828 and the Elementor-mode fix are merged to `main` but unreleased until this clears | BLOCKED |
| Test purchase (Freemius) | NOT DONE |
| Repo visibility decision | PENDING OWNER |
| Naming decision (#688) | PENDING OWNER |
| Hunter | NOT SECURED |
| Gallery/video assets | NOT PRODUCED |

Do not quote a version number, tool count, or feature as shipped in any public copy without
checking it against this table first.

---

## 1. Tagline Options (≤60 chars, the PH "tagline" field)

Five options ranked by clarity + differentiation signal. The PH tagline is the hook
that determines whether someone clicks through.

| # | Tagline | Char count | Notes |
|---|---------|-----------|-------|
| **A (recommended)** | **Your WordPress site, operated by AI agents** | 46 | Specific, owned-surface framing, no hype verb |
| B | MCP server for WordPress — 180+ tools, approval-gated | 55 | Tool count is a differentiator; "approval-gated" separates from autonomous slop |
| C | Give Claude and Cursor the keys to WordPress | 46 | Names verified AI clients directly — relevant to PH audience |
| D | The governance layer for AI-operated WordPress sites | 52 | Positions the moat (governance) but "governance" may feel dry to general PH |
| E | WordPress MCP server. AI builds pages. You approve. | 51 | Verb-driven; the three-beat rhythm works for skimmers |

**Recommendation: Option A as primary, Option E as fallback if A reads too abstract on PH.**

---

## 2. Product Description

### Short (≤260 chars — the PH "tagline/description" slot)

MCPWP turns any WordPress site into an MCP server. AI agents (Claude, Cursor, and any MCP
client) manage content, Elementor layouts, SEO, and media through 180+ tools — with an
opt-in approval gate and full audit log. Free direct download; Pro from $59/yr.

**Char count: 256**

### Long version (the PH product description body)

WordPress runs a huge share of the web. AI agents can now do real work. The missing piece
is a structured, safe surface between the two — not a chatbot, not browser automation, not
cloud-only SaaS.

MCPWP is that surface.

Install the plugin on your WordPress site. Generate an API key, or turn on OAuth and sign
in with your WordPress login. Add one config block to your AI client. Your AI — Claude
Desktop, Claude Code, Cursor, or any MCP-compatible client — can now operate your site in
natural language: build Elementor pages, publish posts, run SEO audits, manage menus,
upload media, and more.

**180+ tools free, 300+ with Pro, across 21 categories.** Free covers content, Elementor
read/write, media, SEO, menus, Gutenberg blocks, taxonomy, webhooks, and admin — plus a
set of AI/stock-photo tools that work with your own OpenAI, Gemini, ElevenLabs, or Pexels
key. Pro adds advanced Elementor builders and templates, theme builder, WooCommerce, SEO
Pro, LearnPress LMS, translation, staging-environment pushes, and Figma.

**An approval gate you opt into.** Any write can be staged as an approval request, held
for human review, applied with one click, and rolled back with one click from Control
Room. You choose what gets staged — a direct write applies immediately, same as any other
WordPress plugin, unless you route it through the gate. Every write, staged or direct, is
logged with before/after state.

**Publishing needs an admin key.** As of 3.13, a write-scoped API key can create and edit
drafts but cannot publish — only an admin-scoped key can. Hand a content or design agent a
write key and it can work all day without being able to ship anything without you.

**Site memory across sessions.** The agent remembers your brand voice, your design
decisions, your client's preferences. `wp_remember(namespace: "brand", key: "tone",
value: "direct, no jargon")` — and it holds across every future session.

**Proactive signals.** The site tells the agent what's wrong without being asked: broken
Elementor data, stale content, missing featured images, pending plugin updates. The agent
operates from a live briefing, not a blank context window.

**Works with the AI clients you already have.** Verified with Claude Desktop, Claude Code,
and Cursor; Windsurf passes the MCP protocol check. Built on the open MCP standard, so any
compliant client can connect. The first call is always `wp_onboard` — it returns a full
site briefing so any agent can orient in seconds.

**Free is a direct download. Pro via Freemius.** Free: `wp plugin install
https://updates.mcpwp.net/mcpwp-latest.zip --activate` — includes the approval gate, audit
log, rollback, and site memory; these aren't Pro-gated. (A separate, reduced build is also
listed on WordPress.org as "Mumega MCP.") Pro starts at $59/yr for one site ($199
lifetime) or $99/yr for three sites ($350 lifetime).

---

## 3. First Maker Comment (pinned — the comment that launches the conversation)

**Recommendation: this is the version to use.**

---

Hey Product Hunt.

I'm Hadi, founder of Mumega. We've been running AI agents against WordPress sites for
clients for the past year and kept hitting the same wall: the tools were fragile, the
AI had no memory, and nothing was auditable. When a client's homepage changed at 2 AM,
nobody could say what happened or undo it.

That's what MCPWP is built to solve.

The idea is simple: WordPress is already an API. MCP is already a protocol. The plugin
connects them properly — 180+ structured tools free (300+ with Pro), an opt-in approval
gate with rollback, a full audit log, and persistent memory across sessions. Your AI agent
can build an Elementor landing page, run an SEO audit on 50 pages, reorganize a menu, and
queue a change for your approval — all in one session, all reversible.

What makes this different from the other WP+AI tools:

- The official WordPress MCP adapter is a thin bridge to WordPress's Abilities API — by
  default it exposes three meta-tools, and abilities are private until you register them.
  MCPWP works out of the box: Elementor, SEO, media, and 180+ other tools, no setup beyond
  an API key.
- MCPWP is self-hosted — your site, your data, no cloud dependency.
- The approval gate and rollback are built into the architecture, not bolted on. Stage a
  change, review it, apply it or reject it, undo it later if you change your mind.
- The agent remembers your brand voice, your design rules, your decisions — across every
  future session.

Free is a direct download from mcpwp.net — not a crippled demo; the approval gate and
audit log are in it. Pro is $59/yr for one site, $99/yr for three (lifetime options
available).

I'd love to hear: which AI client are you running? What would you build on a governed WP
surface? What would make the install experience faster?

We're building in public — honest feedback is the best kind.

— Hadi

---

### Comment-section prep notes (for Hadi's reference, not published)

- Post the maker comment within 60 seconds of launch going live.
- Respond to every comment in the first 2 hours — engagement drives ranking more than upvotes.
- Pin a follow-up comment at hour 2 with the install link + one "what I just built with it"
  screenshot if available.

---

## 4. Gallery Plan (spec for visual production)

PH gallery: 1270×760px recommended. Each slide below: headline, what the visual shows,
one-line caption for the image alt/overlay text.

### Slide 1 — The aha moment
**Headline:** "AI builds the page. You approve it."
**Visual:** Split screen. Left: Claude Desktop chat window showing a natural-language
prompt ("Build a landing page for a dental clinic with hero + 3 services + contact
section"). Right: the resulting Elementor page rendered in WordPress, with the MCPWP
Control Room approval panel visible below it.
**Caption:** "Any change can be staged for approval. Approved changes apply with one
click; applied changes roll back with one click."

### Slide 2 — The tool surface
**Headline:** "180+ tools free. 300+ with Pro."
**Visual:** Dark background (Ink 950). A clean category grid showing the 21 categories
(10 free, 11 Pro) with tool counts — styled like a terminal output or structured card
grid. Blue 600 accents on category names.
**Caption:** "Content, Elementor, WooCommerce, SEO, media, menus, LMS, and more —
all discoverable by the AI on connect."

### Slide 3 — Governance and audit
**Headline:** "Full audit log. One-click rollback."
**Visual:** MCPWP Control Room admin screen showing the approval queue with
pending/approved/rejected states. Show a "Rollback" button clearly visible next to an
applied change.
**Caption:** "Every write is logged with before/after state."

### Slide 4 — [held]
**Note:** The original Slide 4 pitched "one token, N client sites" (a multi-site agency
proxy). That build is not a purchasable product today — the public agency ZIPs were
pulled (#852) and there is no live SKU for it. Do not run this slide until an agency
offering actually ships; if it ships before launch, reintroduce it here clearly labelled
with what's actually included, not as a roadmap teaser.

### Slide 5 — Site memory
**Headline:** "The AI remembers your site."
**Visual:** Two terminal panels side by side. Left: a `wp_remember` call saving brand
tone and color palette. Right: a new session, same site — `wp_recall` returning those
values. A label between them: "Different session. Same memory."
**Caption:** "Brand rules, design decisions, and client preferences persist across
every session."

### Optional Slide 6 (if gallery allows) — Connect in under 10 minutes
**Headline:** "Install. Generate key. Paste config. Done."
**Visual:** Three-step sequence on dark background. Step 1: WP Admin plugin screen with
MCPWP active. Step 2: the API key generation field. Step 3: the claude_desktop_config.json
snippet with the one-block connection config.
**Caption:** "Verified with Claude Desktop, Claude Code, and Cursor. Built on the open MCP
standard — works with any compliant client."

---

## 5. Topics / Tags

File MCPWP under these PH topics (select up to 5):

1. Artificial Intelligence
2. WordPress
3. Developer Tools
4. Productivity
5. Automation

Do not use "Open Source" — the `Mumega-com/mcpwp` repo is private (see checklist above).

**Hunter note:** [OWNER: identify a hunter with PH standing in the AI-tools /
developer-tools category. A recognized hunter materially improves day-1 ranking. Reach
through Gavin / the affiliate network (#509) or direct outreach. Target: secured before
the launch date is set.]

**Maker note:** Hadi Servat as maker. Mumega as the maker company. Link to mcpwp.net.

---

## 6. Tough Questions + Crisp Answers (comment-section playbook)

### Q1: "The official WordPress MCP adapter exists. Why pay for this?"
The official adapter bridges WordPress's Abilities API — by default it exposes three
meta-tools (discover / get-ability-info / execute-ability), and abilities are private
unless a site owner registers them. MCPWP ships a working surface out of the box:
Elementor page building, an opt-in approval gate with rollback, persistent site memory,
SEO tooling, and 180+ free tools with no extra configuration. Free is functional on its
own; Pro adds the deeper Elementor build tools, WooCommerce, SEO Pro, LMS, and
translation.

### Q2: "This is autonomous AI touching a live website. How do I stop it from breaking things?"
Any write can be staged as an approval request and held for human review — you choose
which tools or requests go through the gate. A staged request goes live only after you
approve it, and an applied change rolls back in one click from Control Room. Writes you
don't stage apply immediately, same as any other WordPress plugin, and are logged either
way. On top of that, publishing itself needs an admin-scoped API key as of 3.13 — a
content or design agent holding a write-scoped key can create and edit drafts but cannot
publish. You decide which agents can ship, and you decide which changes need a second
look.

### Q3: "Claude already has a Connector directory. Why isn't MCPWP there?"
It can connect today: turn on OAuth in the plugin's settings, use Claude's "Add custom
connector," and Claude opens your WordPress login to authorize — no API key stored in the
client. The OAuth broker runs at mumcp.com. What we haven't done is submit MCPWP to
Claude's public connector directory listing; that's a separate step we haven't completed.
API-key auth also works in Claude Desktop, Claude Code, and Cursor via manual MCP config.

### Q4: "What's the difference between Free and Pro?"
Free (self-hosted, direct download): 180+ tools — content, Elementor read/write, media,
SEO, menus, Gutenberg, taxonomy, webhooks, admin, plus AI/stock-photo tools that use your
own provider key. The approval gate, audit log, rollback, and site memory are all in Free
— they aren't Pro-gated. Pro ($59/yr for one site, $199 lifetime; $99/yr for three sites,
$350 lifetime) adds 120+ more tools: advanced Elementor builders and templates, theme
builder, WooCommerce, SEO Pro, LearnPress LMS, translation, staging pushes, and Figma. The
free tier is genuinely useful — not a crippled demo.

### Q5: "What happens to my site if I uninstall?"
Nothing. MCPWP is read/write through WordPress's own REST API and data layer. Every
change it makes is a normal WordPress change — posts, pages, options, Elementor data.
Uninstall removes the plugin; the site stays exactly as the AI left it (or as you rolled
it back). No proprietary data format, no lock-in.
