STATUS: DRAFT — routes through Hadi's Telegram gate before any publish.
Target: 45–75 second launch video. Platform: Product Hunt hero + social (vertical 1080x1920 + landscape 1200x630).
Production path: Remotion pipeline at digidinc/media/. Full build spec in REMOTION-SPEC.md.

---

# MCPWP Launch Demo Video Script

---

## Concept and Aha Moment

**The single aha moment:** an AI agent builds a real Elementor landing page through a
natural-language prompt, the result appears in WordPress, and the approval gate catches
it before it touches the live site — showing that AI power and human control are not
opposites.

The video does not explain what MCP is. It shows the loop: prompt in, page out, human
approves, done.

---

## Script — Scene by Scene

**Total runtime: 60 seconds (target). Hard cap: 75 seconds.**

---

### Scene 1 — The problem statement (0:00 – 0:07)

**Duration:** 7 seconds
**Type:** Motion graphic / text on dark background

**On-screen text (center, large):**
"WordPress runs 43% of the web."
[beat — 1.5s]
"AI agents can now do real work."
[beat — 1.5s]
"The problem is the connection between them."

**Voiceover / caption:** None — text carries it. Subtle ambient sound (no music yet or
very soft low drone begins).

**Visual:** Ink 950 background. White text, staggered reveal. No stock imagery. No logos.

---

### Scene 2 — The install (0:07 – 0:15)

**Duration:** 8 seconds
**Type:** Screen capture (WP Admin) + motion graphic overlay

**On-screen text (lower-third overlay):**
"Install MCPWP. Generate a key."

**Visual:** Compressed screen recording — WP Admin Plugins page, MCPWP listed as Active.
Cut to the API key generation field, a key being generated (mcpwp_****). Cut to a
claude_desktop_config.json with the one-block MCP config snippet. The sequence is fast —
one second per step.

**Voiceover / caption:**
"One plugin. One API key. One config block. Your AI is connected."

---

### Scene 3 — The prompt (0:15 – 0:24)

**Duration:** 9 seconds
**Type:** Screen capture (Claude Desktop or terminal chat)

**On-screen text (overlay at top):**
"Natural language in"

**Visual:** Claude Desktop chat window. User types:
"Build a landing page for a dental clinic. Hero section, three service cards, a contact
section at the bottom. Professional, clean."

The send action is visible.

**Voiceover / caption:**
"You describe what you want. The agent does the work."

---

### Scene 4 — THE AHA MOMENT — AI builds the page (0:24 – 0:39)

**Duration:** 15 seconds
**Type:** Screen capture (MCPWP Control Room + Elementor preview) — this is the hero scene

**On-screen text (large, center overlay, appears at 0:28):**
"AI builds the page."

**Visual sequence:**
1. (0:24) The Claude/agent response begins streaming — tool calls visible:
   `wp_create_page`, `wp_set_elementor` with section data.
2. (0:29) Cut to the WordPress frontend preview loading — the Elementor page renders:
   hero section, three service cards, contact section. Clean, styled.
3. (0:33) The MCPWP Control Room "Approvals" panel slides in from the right as an
   overlay — a pending approval card: "Build dental clinic landing page — staged, awaiting
   review." A green "Approve" button and a red "Rollback" button are clearly visible.

**On-screen text (lower third, at 0:33):**
"Staged for approval. Waiting for you."

**Voiceover / caption:**
"The AI builds the full Elementor page. It's not live yet — it's in the approval queue."

**This is the moment the screen holds longest. The contrast between "AI did complex work"
and "you still control what ships" is the entire product pitch.**

---

### Scene 5 — The approval (0:39 – 0:47)

**Duration:** 8 seconds
**Type:** Screen capture (Control Room)

**On-screen text (lower third):**
"One click to publish. One click to undo."

**Visual:** The "Approve" button is clicked. A brief success state. Cut to the live
WordPress frontend — the page is now published. Then the "Rollback" button on a different
entry is shown, clicked, and the page reverts. Clean, fast.

**Voiceover / caption:**
"Approve to publish. Rollback to undo. Every change is logged."

---

### Scene 6 — The surface (0:47 – 0:55)

**Duration:** 8 seconds
**Type:** Motion graphic — category grid

**On-screen text:**
"180+ tools free. 300+ with Pro. Content. Elementor. WooCommerce. SEO. Media. Menus."
(categories appear one by one in a 3-column grid, blue accent on category name)

**Voiceover / caption:**
"180+ tools free, 300+ with Pro, covering every surface of WordPress. Works with Claude
and Cursor — and any MCP client."

---

### Scene 7 — CTA (0:55 – 1:00)

**Duration:** 5 seconds
**Type:** Motion graphic — brand lockup + CTA

**On-screen text (large, centered):**
"Free direct download"
[sub-line, smaller:]
"mcpwp.net"

**Visual:** MCPWP compass mark + wordmark on Ink 950 background with Blue 600 glow.
Primary gradient field. Clean, confident, no animation noise.

**Voiceover / caption:**
"Free direct download. Install in five minutes."

---

## Pacing Notes

- No music with lyrics — use a clean, dry ambient electronic track or silence with impact
  sound design (one soft chime on the "Staged" moment, one on "Approve").
- Captions are mandatory — most PH and social views are muted.
- The approval-gate scene (Scene 4–5) must be the longest and most legible. Do not rush it.
- Total caption reading burden: every on-screen text line must be readable in the time
  allocated. No text that flashes and disappears.

---

## What This Video Does NOT Do

- Does not explain what MCP is.
- Does not list every tool category.
- Does not show pricing.
- Does not use the word "AI-powered" — it shows AI power instead.
- Does not use the word "revolutionary."

---

## Files to Produce

1. 1080x1920 vertical (Reels/TikTok/PH mobile) — primary export
2. 1200x630 landscape (PH hero, Twitter/X card, LinkedIn) — secondary export
3. No audio version (captions-only) for social auto-play
