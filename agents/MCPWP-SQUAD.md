# MCPWP Squad Onboarding Receipt

## Verified on 2026-08-25 UTC

The MCPWP squad uses four distinct Mupot identities in the Core Platform
squad. Each identity has one dedicated 30-day, member-scoped credential stored
outside Git with owner-only permissions. Every runtime verified its own
`boot_context` binding before check-in and registration.

| Agent | Live Mupot identity | Responsibility | Presence module | Adapter |
| --- | --- | --- | --- | --- |
| Cairn | `b5279f2f-011e-4a66-9fe2-44c58851724c` | Editorial systems direction and evidence mapping | `41d403a0-5433-4260-ad4c-011c8b28d037` | `codex-desktop-cairn` |
| Aster | `3f3169ba-265a-464a-9b48-30b87ec532a2` | Customer and market evidence | `29f15ee7-88e9-4c32-af36-f71efdae51df` | `codex-desktop-aster` |
| Lumen | `d1c00311-558a-4dae-b1bb-b98beda5394a` | Multimedia and visual production | `ebd47961-f940-468e-ae08-463f4735960c` | `codex-desktop-lumen` |
| MUMCP | `3070ddc1-10c8-4ac7-881d-c8a3760b4024` | Plugin and theme technical build | `fb2b9603-218d-4fa0-b914-4d74cee81b8f` | `codex-desktop-mumcp` |

## Evidence boundary

- All four exact token bindings were independently verified.
- All four check-ins were accepted from their named Codex Desktop seats.
- All four project-scoped presence modules were registered with bounded,
  role-specific declared capabilities.
- All four runtimes recorded an idle activity heartbeat after onboarding.
- Presence status is ephemeral. An idle Codex task stops heartbeating and may
  appear offline even though its registration and last activity receipt remain
  valid. Offline is not identity failure and online is not execution authority.
- No credential value, response header, or secret fingerprint belongs in Git.

## Current project boundary

The linked Mupot project
`09796d70-1d87-46d7-ac48-adc0551abab7` is archived. Squad onboarding did not
reopen it, create work, or authorize publishing, outreach, spending,
permissions, deployment, release, or merge.

Before starting a mission, Hadi must choose whether to reopen that project or
establish a successor, assign a bounded task, and retain the applicable human
gate.

## Runtime contract

1. Load only the credential for the named agent.
2. Call `boot_context` and stop on any bound-ID mismatch.
3. Call `orient`, then check in from the named seat.
4. Register or refresh only the agent's own adapter and declared capabilities.
5. Heartbeat while active; record `idle`, `blocked`, or `done` honestly.
6. Never infer work authority from identity, registration, presence, squad
   membership, or project visibility.

## Known Mupot issue

Two attempts to store this roster through `squad_remember` returned
`internal_error`, including one through Cairn's correctly bound Core Platform
member credential. The live profiles, token records, presence modules,
check-ins, and heartbeat receipts succeeded. This Git receipt is the durable
fallback until shared squad memory accepts writes. The sanitized reproduction
and acceptance test are tracked in
[Mupot #1204](https://github.com/Mumega-com/mupot/issues/1204).
