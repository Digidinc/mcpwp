# Cairn Setup Receipt

## Refreshed on 2026-08-25 UTC

- [x] Resolved the current active Cairn identity as
      `b5279f2f-011e-4a66-9fe2-44c58851724c`.
- [x] Confirmed the July identity
      `aee0117b-7634-4f9c-8460-479b518b2fa3` no longer resolves.
- [x] Confirmed the current placement is Core Platform squad `squad-core`.
- [x] Confirmed the MCPWP project
      `09796d70-1d87-46d7-ac48-adc0551abab7` still exists but is archived.
- [x] Confirmed the previous local Cairn credential was invalid.
- [x] Minted a dedicated 30-day, member-scoped, agent-bound replacement after
      Hadi's explicit instruction to update and register Cairn.
- [x] Stored the replacement outside Git with owner-only file permissions.
- [x] Verified `boot_context` resolves the credential to the current Cairn ID.
- [x] Completed a non-debounced check-in for seat `cairn-codex-desktop`.
- [x] Registered `codex-desktop-cairn` presence as module
      `41d403a0-5433-4260-ad4c-011c8b28d037`.
- [x] Verified the presence reports online with the five approved declared
      capabilities and runtime model `gpt-5.6-sol`.
- [ ] Assign a bounded current Mupot task before starting a new mission.
- [ ] Decide whether to reopen the archived MCPWP project or establish its
      successor; presence registration alone does not make the project active.

No credential value is recorded in this receipt.

## Observed on 2026-07-30 UTC

- [x] Owner approved the stable name, slug, role, and bounded capabilities.
- [x] Owner created Mupot identity
      `aee0117b-7634-4f9c-8460-479b518b2fa3`.
- [x] Verified display name `Cairn`, slug `cairn`, role, and model
      `gpt-5.6-sol` on the returned profile.
- [x] Verified placement in MCPWP project
      `09796d70-1d87-46d7-ac48-adc0551abab7` and MCPWP Core squad
      `c0e87a6d-77de-4cf8-89a3-79e5843cdd30`.
- [x] Created Mupot task `fee9a613-e5cc-478d-8d77-b7dfb6db9d80`;
      project activity reports `review`.
- [x] Observed Mupot's automatic `gate:agent-self-completion` assignment.
- [ ] Replaced the automatic self-completion gate with a named human gate.
- [ ] Attached the exact branch, commit, PR, and validation receipt to the
      Mupot task. The connected Mumega task-update path returned unavailable
      during this setup session, so the draft PR remains the canonical
      evidence handoff.
- [ ] Owner reviewed the inherited MCPWP project access reported as `admin`.
- [ ] Minted a dedicated Cairn credential.
- [ ] Stored the credential outside the repository through a private handoff.
- [ ] Verified an authenticated principal resolves to Cairn.
- [ ] Registered `codex-desktop-cairn` presence.
- [ ] Verified the registered capabilities match the approved five-item list.
- [x] Opened stacked draft
      [MCPWP PR #743](https://github.com/Mumega-com/mcpwp/pull/743).
- [ ] Assigned and passed a named human review gate.

No credential value is recorded in this receipt.

## Authority observation

Adding Cairn to MCPWP Core caused Mupot to report `Project access: admin`.
This is inherited live state, not authority requested by
`mupot-agent.yaml`. The credential and runtime steps remain incomplete until
the owner explicitly accepts or narrows that access.

Mupot also retained an earlier unscoped duplicate task
`77154bfa-7508-4f36-9b43-d6f317da7d90`. It is not the MCPWP project task and
must not be used as the onboarding receipt.

## Repeat before each work session

1. Confirm the configured credential resolves to Cairn.
2. Confirm current MCPWP project and squad access.
3. Confirm the live grants do not exceed the approved charter.
4. Send a presence heartbeat and read the returned state.
5. Open or resume a bounded Mupot task.
6. Inspect the branch and unrelated worktree changes.
7. Attach fresh validation before requesting review.

## Failure handling

Stop without privileged mutation if the credential belongs to another
principal, project or squad placement is ambiguous, live authority exceeds the
approved charter, a capability is unapproved, or a credential appears in
output. The owner must correct or rotate affected access through a secure
flow.
