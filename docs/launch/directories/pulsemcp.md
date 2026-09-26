# PulseMCP — no separate submission

Issue: #960.

Sources checked 2026-09-26: [PulseMCP servers](https://www.pulsemcp.com/servers) and
third-party guides confirming PulseMCP auto-ingests from the official MCP Registry rather than
running its own intake form (matches the existing note in `docs/launch/MCP-REGISTRY-SUBMISSION.md`:
"PulseMCP / Glama auto-ingest within their refresh windows — no separate submit needed").

## Status: NO ACTION HERE — depends entirely on `docs/launch/directories/mcp-registry.md`

PulseMCP does not have a distinct submission form for this package to fill in. Once the
official-registry duplicate-listing decision in `mcp-registry.md` is resolved and a correct,
current entry is published, PulseMCP should pick it up automatically on its next refresh —
no owner action beyond what's already required for the registry itself.

## Owner actions
None beyond completing `docs/launch/directories/mcp-registry.md`. After that publishes,
periodically check `pulsemcp.com/servers` (search "mcpwp") to confirm the listing appeared and
reflects the corrected entry, not the stale `io.github.mumega848/mcpwp` one if that's still
live under the "leave old entry, publish new name" branch of that decision.

**Est. owner time: 0 min of submission work; a few minutes later to verify ingestion.**
