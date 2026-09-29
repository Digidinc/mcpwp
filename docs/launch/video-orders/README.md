# Video orders for the video team

These are the orders to file in **Linear** for the owner's video team, one Linear issue per
order. If the operating agent has Linear access, it files them directly. If not, copy each
section below into a new Linear issue. After filing, record the Linear issue ID next to the
heading.

Shared for every order:
- **Brand:** `docs/BRAND_KIT.md`, tokens `docs/brand-tokens.css`. Seller name on end cards:
  **MCPWP by Digid**.
- **Existing footage and assets:** `docs/launch/video/mcpwp-demo-1080p.mp4` (the current 60s demo),
  `docs/launch/video/poster.png`, `docs/launch/gallery/01-…05-*.png`, and the Remotion source in
  `docs/launch/video/src/`. GitHub links:
  https://github.com/Mumega-com/mcpwp/tree/main/docs/launch/video and
  https://github.com/Mumega-com/mcpwp/tree/main/docs/launch/gallery
- **Deliverables:** 1920×1080 MP4 (H.264) plus a 1080×1920 vertical cut, burned-in captions,
  an SRT file, and a poster frame PNG.
- **Approver:** the owner. **Where it goes:** mcpwp.net homepage and changelog, directory
  listings, social (`docs/launch/LAUNCH-SOCIAL.md`).
- **Screen recordings:** the operating agent can set up a clean demo WordPress site with MCPWP
  3.14.8+ and record any flow on request. Ask in the Linear issue.

---

## Order 1: "What's new in MCPWP 3.14" (60s)  · Linear: _pending_

**Goal:** show existing and returning users that 3.14 makes the AI safer and more capable.
**Audience:** WordPress site owners and agencies who already use Claude or ChatGPT.

| time | picture | on-screen text / voice-over |
|---|---|---|
| 0:00–0:06 | MCPWP logo on brand background | "MCPWP 3.14: your AI, safely inside WordPress." |
| 0:06–0:20 | wp-admin in Chrome; the browser's AI panel lists MCPWP tools; the agent drafts a page | "New: WebMCP. The AI built into your browser can work on your site, using your own login. It creates drafts and proposes changes. Nothing goes live without you." |
| 0:20–0:32 | Control Room: a proposed change, Approve button, then Rollback | "Approvals you can trust: every change applies exactly once, and any change can be rolled back." |
| 0:32–0:44 | API key screen with categories; a refused request | "Keys that do only their job. A key for your blog writer can't touch your store, your templates, or your settings." |
| 0:44–0:54 | Site graph view (`mcpwp://graph`): pages and links | "The AI sees how your site fits together, so it can find orphan pages and missing links." |
| 0:54–1:00 | End card: mcpwp.net, "MCPWP by Digid" | "Free to start. mcpwp.net" |

---

## Order 2: "Watch the agent work" (60s)  · Linear: _pending_

**Goal:** the core aha moment: an AI builds a real page, and the owner stays in control.
**Audience:** new visitors, directory listings, Product Hunt.
Base it on `docs/launch/DEMO-VIDEO-SCRIPT.md` (same concept), updated to the 3.14 UI.

| time | picture | on-screen text / voice-over |
|---|---|---|
| 0:00–0:07 | Text on dark: "WordPress runs 43% of the web. AI agents can do real work." | Same |
| 0:07–0:15 | Claude chat: "Build a landing page for our spring sale, in our brand colours." | "Just ask." |
| 0:15–0:35 | Split screen: Claude calling tools on the left; the Elementor page appearing section by section on the right (speed-ramped) | "MCPWP gives the AI 150+ real WordPress tools: Elementor, SEO, WooCommerce, media." |
| 0:35–0:48 | Claude: "Publish it and change the homepage banner." → Control Room shows an approval request → the owner clicks Approve | "Risky changes wait for you." |
| 0:48–0:55 | Page live; Action Log entry; Rollback button | "Everything is logged. Everything can be undone." |
| 0:55–1:00 | End card | "Connect your AI in 2 minutes. mcpwp.net" |

---

## Order 3: "Connect in 2 minutes" (30s, vertical first)  · Linear: _pending_

**Goal:** remove the "is this hard to set up?" doubt. **Audience:** social and short-form.

| time | picture | on-screen text |
|---|---|---|
| 0:00–0:04 | Hook | "Give Claude your WordPress site in 2 minutes" |
| 0:04–0:10 | WP admin → Plugins → Add New → upload MCPWP → Activate | "1. Install MCPWP" |
| 0:10–0:17 | MCPWP → Setup → Create key → Copy | "2. Copy your key" |
| 0:17–0:24 | Claude → Connectors → add MCPWP URL and key (or "Sign in with MCPWP") | "3. Connect Claude" |
| 0:24–0:30 | Claude: "What pages does my site have?" → answer listing pages | "Done. Now just ask. mcpwp.net" |
