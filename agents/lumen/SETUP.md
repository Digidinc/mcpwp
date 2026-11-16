# Lumen Setup Receipt

## Completed on 2026-07-30 UTC

- [x] Created the Lumen Mupot identity.
- [x] Bound the identity to MCPWP Core with member capability.
- [x] Minted a dedicated agent-bound credential through the signed-in owner
      interface.
- [x] Stored the credential outside the repository.
- [x] Verified the authenticated principal is Lumen.
- [x] Registered `codex-desktop-lumen` presence in the MCPWP project.
- [x] Declared five bounded production capabilities.
- [x] Created Mupot task `3a66a38f-fc26-4dfa-a31a-18c3bc61309b`.
- [x] Opened draft [MCPWP PR #742](https://github.com/Mumega-com/mcpwp/pull/742).
- [x] Moved the task through `open`, `in_progress`, and `review`.
- [x] Assigned the existing `gate:kasra-core` review gate.

No credential value is recorded in this receipt.

## Known evidence

The Mupot task mirror routed to
[`Mumega-com/mumega-com#576`](https://github.com/Mumega-com/mumega-com/issues/576)
instead of MCPWP. The regression is tracked in
[Mupot #619](https://github.com/Mumega-com/mupot/issues/619).

Presence became offline after the implicit heartbeat lease elapsed and returned
online after an explicit heartbeat. The client-facing lease gap is tracked in
[Mupot #620](https://github.com/Mumega-com/mupot/issues/620).

## Repeat before each work session

1. Confirm the configured credential still resolves to Lumen.
2. Confirm MCPWP project and MCPWP Core access.
3. Send a presence heartbeat and read the returned state.
4. Open or resume a bounded Mupot task.
5. Check the repository branch and unrelated worktree changes.
6. Work only within the approved scope.
7. Attach fresh validation before requesting review.
