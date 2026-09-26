STATUS: DRAFT — routes through Hadi's Telegram gate before any publish.
Target launch day: [OWNER: pick date]. Nothing here posts automatically.

---

# Launch-Day Social Posts — MCPWP

---

## 1. X / Twitter Thread (5–7 posts)

Platform voice: direct, technical, concrete. Show the thing, don't describe it.
Muted views are high on X — include the demo video and lead with the visual.

Post every 15–30 min through the morning. Do not fire all at once.

---

**Post 1 — The launch post (attach demo video, 1200x630 landscape)**

We just launched MCPWP on Product Hunt.

Install the plugin on any WordPress site. Generate an API key. Paste one config block.

Your AI (Claude, Cursor, or any MCP client) can now operate your entire site — pages,
Elementor, WooCommerce, SEO, menus, media — through 180+ structured tools free (290+
with Pro).

With an opt-in approval gate.

[Product Hunt link — add on launch day]

---

**Post 2 — The governance angle (post ~20 min after launch)**

Everyone building AI-for-WordPress has the same problem.

The AI makes changes directly to the live site. Something breaks. Nobody knows what
changed or how to undo it.

MCPWP's architecture works the other way:

Any write can be staged first.
You approve (or reject).
Full audit log. One-click rollback.

Route it through the gate and nothing touches the live site until you say so. As of
3.13, publishing itself needs an admin-scoped key — a write-scoped agent can draft, not
ship.

---

**Post 3 — The specificity post (post ~40 min after launch)**

What does 180+ free tools (290+ with Pro) actually mean?

Content: create pages, posts, bulk ops, search, clone, template.
Elementor: build full pages, edit sections, patch widgets, find-replace.
WooCommerce: products, orders, categories (Pro).
SEO: audit, issues, autofix, search performance, structured data.
Media: upload file/URL/base64, AI alt text.
Menus, Gutenberg blocks, site memory, blueprints, approvals, webhooks.

All discoverable on connect. All role-scoped.

Compare: the official WordPress MCP adapter exposes three meta-tools by default and
leaves abilities private until you register them. MCPWP works out of the box.

---

**Post 4 — [held]**

The original post here pitched a multi-site agency proxy ("one MCP token, N client
sites"). That is not a purchasable product today — the public agency builds were pulled
(#852). Do not post this until an agency offering actually ships with a real price and
signup path.

---

**Post 5 — Site memory (post ~2 hr after launch)**

One thing that surprised people in beta: the site memory.

The AI remembers your brand rules across every session:

```
wp_remember(namespace: "brand", key: "tone", value: "direct, no jargon")
```

Next session, fresh context window, same site:

```
wp_recall(namespace: "brand", key: "tone")
→ "direct, no jargon"
```

Brand voice, design decisions, client preferences — persistent.

---

**Post 6 — Free + CTA (post ~3 hr after launch, or end-of-day push)**

MCPWP free tier is a direct download: mcpwp.net.

Free includes: 180+ tools, approval gate, audit log, rollback, site memory,
Elementor read/write.

Pro ($59/yr): advanced Elementor builders, WooCommerce, SEO Pro, LMS, Figma.

Install in 5 minutes: mcpwp.net

---

**Optional Post 7 — Affiliate/Gavin angle [TK: activate only after affiliate links are
live per #509 — do not post before affiliate infrastructure is confirmed]**

If you write about AI tools for WordPress builders, we have an affiliate program.

[TK: insert affiliate link and commission rate once #509 is resolved.]

---

## 2. LinkedIn Post (single post, launch day)

Platform voice: professional, founder-led, slightly longer form than X.
Attach the 1200x630 landscape video or the carousel (6 slides).

---

Today we launched MCPWP on Product Hunt.

It turns any WordPress site into an MCP server — so AI agents (Claude, Cursor, or any MCP
client) can operate the site in natural language through 180+ structured tools free
(290+ with Pro).

I want to explain the part we think is most important, because it's different from every
other AI-for-WordPress tool:

The governance layer.

Any change an AI makes can be routed into an approval queue — you choose what gets
staged. A staged change only touches the live site once a human approves it. If you
approve and then change your mind, one click rolls it back. Full audit log with
before/after state on every write, staged or direct. And as of our latest release,
publishing itself needs an admin-scoped key — a write-scoped agent can draft, not ship.

The reason this matters: AI makes mistakes. The question is whether your architecture
assumes that or ignores it. MCPWP assumes it. That's the design choice.

We built this after a year of running AI agents against client WordPress sites for our
agency. The recurring problem wasn't capability — it was control. The agents could do
the work. The risk was that nobody knew what had changed or how to undo it.

This is the tool we built for ourselves. Now it's free to install.

Free is a direct download at mcpwp.net.
Pro from $59/yr.

If you work with WordPress sites and AI agents, I'd love your feedback — especially on
the install experience. We're targeting under 5 minutes from download to first tool call.

[Product Hunt link — add on launch day]
[mcpwp.net]

---

## 3. Show HN — Hacker News

**Post title:**
"Show HN: MCPWP — MCP server for WordPress with 180+ tools, an approval gate, and an audit log"

**Opening comment (the first comment, posted immediately by OP):**

I've been running AI agents against WordPress sites for about a year for client work.
The recurring problem: the agents could do the work (build Elementor pages, run SEO
audits, manage content at scale) but there was no governance layer. Changes landed
directly on the live site. Nothing was auditable. Nothing was reversible.

MCPWP is what we built to fix that.

The core idea: expose WordPress as an MCP server (180+ tools free, 290+ with Pro, across
content, Elementor, WooCommerce, SEO, media, menus, and more) with an opt-in
human-in-the-loop approval gate. Route a write through it and it goes into an approval
queue — you approve, the change applies; you reject, nothing happens. Either way it's in
the log with before/after state and you can roll it back. Writes you don't route through
the gate apply immediately, same as any other plugin, and are still logged. Publishing
itself also needs an admin-scoped key as of our latest release — a write-scoped agent can
draft, not ship.

Technical details:
- Plugin installs on self-hosted WordPress, exposes a Streamable HTTP MCP endpoint at
  `wp-json/mcpwp/v1/mcp`.
- Verified with Claude Desktop, Claude Code, and Cursor; Windsurf passes the MCP protocol
  check. Built on the open MCP standard, so any compliant client can connect.
- OAuth ships in the plugin too — Claude's "Add custom connector" + your WordPress login,
  via a broker at mumcp.com — alongside API-key auth.
- Role-scoped API keys: content agent gets different access than design agent.
- Persistent site memory across sessions (`wp_remember`/`wp_recall`).
- Proactive signals: the site surfaces broken Elementor data, stale content, pending
  updates — without being asked.
- The compare: the official WordPress MCP adapter exposes three meta-tools by default and
  leaves abilities private until you register them. MCPWP works out of the box, with
  Elementor depth, an approval gate, and persistent memory on top.

Free tier is a direct download at mcpwp.net.
Site: mcpwp.net

Happy to answer technical questions — especially on the MCP transport layer, the Elementor
data format, or how the approval gate is implemented.

---

### HN tone notes

- HN rewards specificity and intellectual honesty. The comparison to the official adapter
  must be accurate (verify tool counts before posting).
- If someone asks "why not just use the official WP MCP adapter," the answer is already in
  the comment. Don't be defensive — be specific.
- If someone asks about the governance model: explain the `wp_create_approval_request` →
  `wp_apply_approval` / `wp_rollback_approval` flow.
- If someone pushes back on "180+ tools is too many for LLMs to handle": acknowledge the
  context-window tension and explain tool discovery + role-scoped key scoping reduces the
  effective surface per agent.
- Do not engage with off-topic trolling. Do engage with every sincere technical question.

---

## 4. Affiliate/Gavin Angle Note

[TK: Do not activate until #509 is resolved and affiliate links are generated through
Freemius. Once live, the X Thread Post 7 above can fire, and the LinkedIn post can add
an affiliate partner mention. Gavin's network is the first outreach target once links
exist. Herald will draft the affiliate-partner outreach email as a separate deliverable
once #509 closes.]

---

## 5. Reddit Note (not a launch-day post — slower burn)

Reddit requires the 90/10 rule: 9 contributions before any promo post. Do not post to
r/WordPress or r/ClaudeAI on launch day as a cold account. If Hadi or a team member has
standing in those communities, the appropriate post is a demo comment in a relevant
existing thread (e.g., someone asking "how do I use AI to manage WordPress") — not a
self-post announcement. This is a post-launch, community-warming activity.

r/ClaudeAI (911k members) and r/WordPress (700k) are both viable surfaces but only
through demonstrated value first.
