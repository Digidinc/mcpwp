# Example Agent Setup

## Owner enrollment

- [ ] Confirm the stable slug and role.
- [ ] Create or select the Mupot agent identity.
- [ ] Approve the intended project and squad membership.
- [ ] Review every requested capability.
- [ ] Mint an agent-bound credential through a secure one-time handoff.
- [ ] Record a revocation or rotation handle without recording the secret.

## Agent verification

- [ ] Authenticate and confirm the returned agent identity.
- [ ] Confirm project visibility and squad capability.
- [ ] Register a unique project-presence adapter.
- [ ] Confirm the declared capabilities match the approved list.
- [ ] Create a bounded first task.
- [ ] Open an isolated draft pull request.
- [ ] Attach validation evidence and request a named review gate.

## Failure handling

Stop without mutation if the configured credential belongs to another
principal, the project is ambiguous, the requested capability exceeds the
charter, or a credential appears in output. Ask the owner to correct or rotate
the affected credential through a secure flow.
