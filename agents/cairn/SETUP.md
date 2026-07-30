# Cairn Setup Receipt

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
      project activity reports `in_progress`.
- [ ] Owner reviewed the inherited MCPWP project access reported as `admin`.
- [ ] Minted a dedicated Cairn credential.
- [ ] Stored the credential outside the repository through a private handoff.
- [ ] Verified an authenticated principal resolves to Cairn.
- [ ] Registered `codex-desktop-cairn` presence.
- [ ] Verified the registered capabilities match the approved five-item list.
- [ ] Opened and linked the stacked draft pull request.
- [ ] Assigned and passed a named human review gate.

No credential value is recorded in this receipt.

## Authority observation

Adding Cairn to MCPWP Core caused Mupot to report `Project access: admin`.
This is inherited live state, not authority requested by
`mupot-agent.yaml`. The credential and runtime steps remain incomplete until
the owner explicitly accepts or narrows that access.

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
