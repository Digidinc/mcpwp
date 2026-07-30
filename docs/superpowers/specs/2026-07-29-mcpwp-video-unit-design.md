# MCPWP Video Marketing Unit Design

## Decision

Create one operational surface at `content/video/` inside `Mumega-com/mcpwp`.
It owns video briefs, scripts, production instructions, approved asset
references, distribution plans, and evidence receipts. It does not own product
truth, WordPress plugin code, credentials, OBS user profiles, or publishing
authority.

## Alternatives considered

1. Put everything under `docs/video-marketing/`. This is simple, but treats
   recurring production work like static product documentation.
2. Create `content/video/` in MCPWP. This keeps the work close to the product
   while separating it from runtime code. This is the selected approach.
3. Create a separate video-production repository. This gives maximum isolation
   but adds unnecessary coordination before the first verified production loop.

## Identity and governance

Lumen is the MCPWP AI Video Director registered in Mupot as
`lumen-video-director`, agent `fd1ded67-9c03-42e5-946e-72609e2ad059`.
Lumen is a draft-only member of the `MCPWP Core` squad.

Lumen may:

- prepare briefs, scripts, storyboards, Remotion compositions, and OBS scene
  specifications;
- assemble evidence and propose distribution packages;
- register project presence and maintain task status in Mupot.

Lumen may not publish, send outreach, spend money, control a live recording, or
make unsupported product claims without an explicit human gate.

## Repository structure

```text
content/video/
├── README.md
├── agents/
│   └── LUMEN.md
├── briefs/
│   └── TEMPLATE.md
├── scripts/
│   └── TEMPLATE.md
├── remotion/
│   └── README.md
├── obs/
│   └── README.md
├── assets/
│   └── README.md
├── distribution/
│   └── README.md
└── evidence/
    └── RECEIPT-TEMPLATE.md
```

Each directory has one responsibility and can evolve independently.

## Production flow

1. A Mupot task supplies the objective, audience, acceptance criteria, and
   accountable reviewer.
2. A brief records the verified source material and prohibited claims.
3. A script and shot list distinguish captured evidence from planned visuals.
4. Remotion or OBS production follows the approved script.
5. A human reviews the rendered output and channel-specific copy.
6. Distribution remains gated.
7. An evidence receipt records the source commit, render, review, publication
   destination, and measured outcome.

## Source and claim rules

- Current release behavior, release notes, WordPress.org material, and verified
  demonstrations outrank older marketing copy.
- Planned, beta, and unavailable features must be labeled explicitly.
- Tokens, cookies, API keys, customer data, private dashboards, and raw account
  identifiers must never appear in source assets or renders.
- A video cannot claim completion solely from a green render; the receipt must
  identify the human reviewer and publication decision.

## Validation

The initial change is documentation-only. Validation requires:

- every designed directory is represented by a tracked Markdown file;
- repository Markdown contains no placeholders such as `TBD` or `TODO`;
- no credentials or binary media are added;
- only the intended `content/video/` and design/plan files are staged;
- a draft pull request is opened for human review.
