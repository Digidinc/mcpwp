STATUS: DRAFT — routes through Hadi's Telegram gate before any publish.
This spec is written for the Remotion agent/builder using digidinc/media/.
Do not rebuild the renderer — write compositions against the existing pipeline.

---

# Remotion Build Spec — MCPWP Launch Video + Social Carousel

---

## 1. Outputs Required

| Asset | Dimensions | FPS | Duration | Format | Use |
|-------|-----------|-----|----------|--------|-----|
| `demo-video-vertical` | 1080 x 1920 | 30 | 60s (1800 frames) | MP4 H.264 | PH mobile, Reels, TikTok |
| `demo-video-landscape` | 1200 x 630 | 30 | 60s (1800 frames) | MP4 H.264 | PH hero, Twitter/X card, LinkedIn |
| `carousel-slide-{1..6}` | 1080 x 1080 | 30 | static (1 frame) | PNG | Instagram, LinkedIn, X cards |

Remotion `renderMedia` calls: render vertical first; crop/letterbox the timeline for landscape.

---

## 2. Brand Tokens (source: docs/BRAND_KIT.md + docs/brand-tokens.css)

### Colors

```
Ink950:     #0B1220   (primary background — all dark surfaces)
Ink900:     #121A2B   (alternate dark surface)
Blue600:    #1B4DFF   (primary accent — CTAs, highlights, category names)
Blue100:    #DCE5FF   (tint panels, badges)
White:      #FFFFFF   (primary text on dark)
Slate600:   #5F6C86   (secondary body text)
Slate200:   #D9E2F2   (borders on dark)
Success:    #12B981
Danger:     #E5484D
```

### Gradients

```
PrimaryGradient:  linear-gradient(135deg, #0B1220 0%, #1B4DFF 50%, #0B1220 100%)
AccentGradient:   linear-gradient(135deg, #1B4DFF 0%, #5B7CFF 100%)
```

### Typography

```
Display:  Space Grotesk, 700 weight
Body:     Instrument Sans, 400–500 weight
Mono:     IBM Plex Mono, 400–500 weight
```

Font loading: use Google Fonts CDN within the Remotion composition, or pre-load via
`@remotion/google-fonts`. If unavailable, fallback: `Segoe UI` (display), `Arial` (body),
`Consolas` (mono).

---

## 3. Demo Video — Scene Timing and Composition Details

Reference the full narrative in DEMO-VIDEO-SCRIPT.md. This section gives the Remotion
builder exact frame counts and composition names.

**Total: 1800 frames at 30fps = 60 seconds.**

### Composition: `DemoVideoVertical` / `DemoVideoLandscape`

| Scene | Start frame | End frame | Duration | Type |
|-------|------------|-----------|----------|------|
| 1 — Problem statement | 0 | 210 | 7s | MotionText |
| 2 — Install | 210 | 450 | 8s | ScreenCapture + Overlay |
| 3 — The prompt | 450 | 720 | 9s | ScreenCapture |
| 4 — Aha moment | 720 | 1170 | 15s | ScreenCapture + ControlRoomOverlay |
| 5 — Approval | 1170 | 1410 | 8s | ScreenCapture |
| 6 — Surface grid | 1410 | 1650 | 8s | MotionGrid |
| 7 — CTA | 1650 | 1800 | 5s | BrandLockup |

### Scene 1 — MotionText component

Three text lines, staggered reveal. Each line uses `spring()` interpolation.
- Line 1: "WordPress runs 43% of the web." — enters at frame 0, hold 60 frames.
- Line 2: "AI agents can now do real work." — enters at frame 60, hold 60 frames.
- Line 3: "The problem is the connection between them." — enters at frame 120, hold 90 frames.
- Font: Space Grotesk 700, 52px (vertical), 36px (landscape).
- Color: White. Background: Ink950 solid.
- Easing: `spring({ frame, fps: 30, config: { damping: 20 } })`.
- No logo, no background imagery.

### Scene 2 — ScreenCapture + Overlay

Three sub-scenes using `<Sequence>` with crossfade (`opacity` interpolation over 6 frames).
- Sub-scene A (8s / 240 frames): WP Admin Plugins screen, MCPWP row highlighted in Blue100
  with a Blue600 ring. Pre-recorded or static PNG.
- Sub-scene B (90 frames): API key generation field. The generated key appears character by
  character using `useCurrentFrame()` string slice. Font: IBM Plex Mono 14px, White on Ink900
  panel.
- Sub-scene C (90 frames): `claude_desktop_config.json` snippet. Syntax-highlighted code
  block. Background: Ink900 panel with 12px rounded corners. Blue600 for JSON keys,
  White for strings.

Lower-third caption bar: Instrument Sans 500, 16px, White on Ink900 semi-transparent bar
(`rgba(18, 26, 43, 0.85)`), bottom of frame.

### Scene 3 — ScreenCapture (Claude Desktop chat)

Static screen recording import or a faithful recreation using:
- Chat window: Ink900 panel, 16px radius.
- User message bubble: Blue100 background, Ink950 text.
- Agent typing indicator: three pulsing dots, `useCurrentFrame()` driven.
- The prompt text types in character-by-character over 120 frames (4s), then holds 30 frames.
- Lower-third: "You describe what you want. The agent does the work."

### Scene 4 — Aha Moment (hero scene — most important)

Three sub-layers composited:

**Layer A — Screen capture background:**
Tool call stream: IBM Plex Mono text stream, green Success color, showing:
```
wp_create_page("Dental Clinic — Home")  ✓
wp_set_elementor(id: 42, sections: [...])  ✓
```
Text streams in from top, 2 lines, over 90 frames.

**Layer B — Page preview panel (enters at frame 819 / 0:27.3):**
Right-side panel sliding in from right (`translateX` spring). Shows a rendered Elementor
page screenshot: hero section (dark navy, white headline "Expert Dental Care"), three
service cards below (white background, Blue600 icon chips), contact form section at bottom.
Panel has dark frame with Blue600 highlight border. Soft shadow.
[TK: provide actual screen recording from dev rig once built. Placeholder: a static mockup
that matches the described layout is acceptable for the draft video.]

**Layer C — Control Room approval overlay (enters at frame 990 / 0:33):**
Bottom-sheet modal slides up from bottom (`translateY` spring). Content:
- "Approval Request" header, Blue600 accent.
- Card: "Build dental clinic landing page" — status chip: "Staged" (Blue100 background,
  Blue600 text).
- Two buttons side by side: "Approve" (Blue600 fill, White text) and "Rollback" (Danger
  border, Danger text).
- Sub-text: "Not live. Waiting for your review."

This three-layer composition is the aha moment. Hold it for 15 frames (0.5s) before
transitioning to Scene 5.

### Scene 5 — Approval

Continuation of Layer C from Scene 4. The "Approve" button receives a `scale(0.95)` press
animation at frame 1170 + 15 frames. After press:
- Status chip changes to "Approved" (Success background).
- A brief confetti-free success flash (Ink900 background with Success glow border, 200ms).
- Cut to the frontend WordPress page — now live. Same screenshot as Layer B but without
  the approval overlay.
- Then: a second approval card for a different entry shows "Rollback" being pressed — page
  reverts. Show the before-state page screenshot replacing the after-state.

Lower-third: "Approve to publish. Rollback to undo. Every change is logged."

### Scene 6 — Category Grid (MotionGrid)

3-column grid of tool category cards. Each card: Ink900 background, 12px radius, Blue600
category name (Space Grotesk 500 14px), White count label.

Categories in order of appearance (staggered entry, 8 cards visible at once):
Content, Elementor, Elementor Build, Media, SEO, Menus, WooCommerce, Site Memory,
Blueprints, Approvals, Webhooks, Gutenberg, Taxonomy, LMS, Analytics, Admin.

Entry animation: cards fade + translateY(+20px) → (0px) with 6-frame stagger between
cards. All cards visible by frame 1590 (this scene shows a representative subset of the
21 total categories, not all of them).

Lower-third: "180+ tools free, 300+ with Pro, covering every surface of WordPress."

### Scene 7 — Brand Lockup (BrandLockup)

Full-bleed PrimaryGradient background. Centered:
- MCPWP compass mark SVG (from `mcpwp/assets/icon.svg`) — 96px, white fill, subtle
  blue glow pulse (`opacity` spring, 0.7 → 1.0 → 0.7 over 30 frames).
- "MCPWP" wordmark, Space Grotesk 700, 64px, White.
- "Free direct download" — Instrument Sans 500, 24px, Blue100.
- "mcpwp.net" — IBM Plex Mono 400, 18px, Slate200.

Hold 5 seconds. Fade to black in last 15 frames.

---

## 4. Social Carousel — 6 Slides (1080 x 1080)

Each slide is a static Remotion frame (renderStill). Composition: `CarouselSlide{N}`.

### Slide 1 — Hero / Product Claim

**Background:** PrimaryGradient
**Content:**
- MCPWP compass mark, 80px, top-left with 40px padding.
- Main headline (Space Grotesk 700, 52px, White, centered):
  "Your WordPress site, operated by AI."
- Sub-line (Instrument Sans 400, 20px, Blue100):
  "180+ tools free. Approval-gated. Self-hosted."
- Bottom-right corner: "mcpwp.net" (IBM Plex Mono 14px, Slate200).

### Slide 2 — The AI prompt loop

**Background:** Ink950 solid
**Content:**
- Top label (Instrument Sans 500 12px, Blue600 uppercase tracking): "HOW IT WORKS"
- Large prompt bubble (Ink900 panel, 14px radius):
  User: "Build a landing page for a dental clinic with hero + services + contact."
  Agent: `wp_create_page` ✓ · `wp_set_elementor` ✓ · Staged for approval.
- Caption below (Slate200, 14px): "Natural language in. Structured WordPress changes out."

### Slide 3 — Governance angle

**Background:** Ink900 solid
**Content:**
- Three-column icon row (geometric icons, Blue600):
  Audit Log | Approval Gate | One-Click Rollback
- Under each icon, 2 lines of Instrument Sans 400 16px White text:
  "Every write logged" | "Staged before it's live" | "Undo any change"
- Main headline above the row (Space Grotesk 700, 40px, White):
  "AI power. Human control."
- Sub: "Any change can be staged for approval before it touches your site."

### Slide 4 — Tool surface

**Background:** Ink950
**Content:**
- 4x4 grid of category chips (Blue100 background, Blue600 text, 8px radius, Instrument
  Sans 500 13px): Content, Elementor, WooCommerce, SEO, Media, Menus, LMS, Memory,
  Blueprints, Approvals, Webhooks, Gutenberg, Taxonomy, Analytics, Figma, Admin.
- Headline above grid (Space Grotesk 700, 40px, White): "180+ tools free. 300+ with Pro."
- Sub below (Slate200 16px): "Every surface of WordPress. One MCP server."

### Slide 5 — Site memory

**Background:** Ink950
**Content:**
- Two code panels side by side (Ink900, 12px radius, IBM Plex Mono 13px).
  Left panel — Session 1:
  ```
  wp_remember(
    namespace: "brand",
    key: "tone",
    value: "direct, no jargon"
  )
  → saved
  ```
  Right panel — Session 2 (different session, labeled with a chip "New session"):
  ```
  wp_recall(
    namespace: "brand",
    key: "tone"
  )
  → "direct, no jargon"
  ```
- Headline above (Space Grotesk 700, 36px, White): "The AI remembers your site."

### Slide 6 — CTA / Free

**Background:** PrimaryGradient
**Content:**
- MCPWP compass mark, 96px, centered top half.
- Headline (Space Grotesk 700, 56px, White): "Free direct download"
- Sub (Instrument Sans 500, 20px, Blue100): "Pro from $59/yr. Install in 5 minutes."
- URL (IBM Plex Mono 400, 18px, White): "mcpwp.net"
- Small print bottom-center (Slate200, 12px): "Verified with Claude Desktop, Claude Code, and Cursor."

---

## 5. Audio

- Demo video: optional. If included, use a dry ambient electronic track (no lyrics, no
  melody that competes with reading). Recommended: royalty-free via Pixabay or Freesound.
  If in doubt, silence + good captions beats bad audio.
- Social carousel: no audio (static images).
- Impact sound cues: one soft chime (440Hz, 80ms attack) on the "Staged" approval card
  appearing (Scene 4, frame 990). One confirmation tone on "Approve" click (Scene 5,
  frame 1185). Both optional — easy to drop.

---

## 6. Caption Track

Burn captions into the video (not as a separate sidecar file). Every scene's voiceover
line must appear as a lower-third caption bar. Spec:
- Background: `rgba(11, 18, 32, 0.85)` (Ink950 80% opacity)
- Text: Instrument Sans 500, 16px, White
- Width: 90% of frame width, centered, bottom 8% of frame
- Padding: 10px top/bottom, 16px left/right, 8px radius
- Entry: fade in over 8 frames; exit: fade out over 8 frames

---

## 7. Screen Recordings Required (from dev rig)

The Remotion builder cannot fake these. Herald flags these as [TK: record from rig]:

1. [TK: record] WP Admin Plugins screen with MCPWP active.
2. [TK: record] API key generation in MCPWP Setup page.
3. [TK: record] Claude Desktop with `wp_create_page` + `wp_set_elementor` tool calls streaming.
4. [TK: record] The resulting Elementor page rendered in WordPress frontend.
5. [TK: record] MCPWP Control Room approval queue — a pending card, Approve click, success state.
6. [TK: record] Rollback demonstration on a different entry.

Until recordings are available, use static PNGs or wireframe placeholders. The composition
architecture should be built around `<Img>` swaps so real recordings slot in without
structural changes.

---

## 8. File Output Path Convention

```
digidinc/media/compositions/mcpwp-launch/
  DemoVideoVertical.tsx
  DemoVideoLandscape.tsx
  CarouselSlide1.tsx  ... CarouselSlide6.tsx
  components/
    MotionText.tsx
    ScreenCapturePlaceholder.tsx
    CategoryGrid.tsx
    BrandLockup.tsx
    ControlRoomOverlay.tsx
    CaptionBar.tsx
  assets/
    mcpwp-icon.svg          (copy from mcpwp/assets/icon.svg)
    [screen-recordings/]    (TK — slot in when rig recordings exist)
```
