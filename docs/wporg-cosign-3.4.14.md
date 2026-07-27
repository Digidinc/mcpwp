# Two-person-rule co-sign — mumega-mcp 3.4.14 auth re-pin

Date: 2026-07-19. Branch `fix/wporg-approval-minimal`, tip `4b89c92`.
Release commit under co-sign: `e507edd` (re-pins 11 `trusted_source_sha256`
hashes in `mcpwp/scripts/wporg-artifact-policy.json`).

## Parties
- **Author (party 1):** River (Claude Code, main session) — authored #667/#670/#15
  + spai-bridge sunset (`f8b5d62`) and the hash re-pin. Cannot self-certify.
- **Independent signer (party 2):** Codex (gpt-5.6-terra, `vps-codex` on the SOS
  bus). Cross-model, independent checkout, no shared context with party 1.

Dispatched via SOS Redis bus, request_id `b6ef811c-e3c0-4fb0-9bd0-338d9606e7a4`
(Codex's Mupot MCP auth had expired; it replied over the SOS bus instead).

## Codex verdict: APPROVE (bus receipt `1784424876296-0`)
Independently verified against its own clone:
1. All 11 auth/REST `trusted_source_sha256` pins == `sha256sum` of current files;
   the 6 MCP-tool-surface pins unchanged.
2. `scripts/build-wporg.sh` → `[OK] WordPress.org artifact verified`.
3. `phpunit` (in docker container `wp-test-wordpress-1`, php 8.3, against Codex's
   own checkout) → **360 tests, 4283 assertions, OK**.
4. Static auth review: literal per-route `permission_callback`, authentication
   before resource lookup (no double rate-limit charge), spai_ bridge sunset,
   preserved current-key migration → **no bypass, no fresh-install lockout**.

An initial `BLOCK` was raised only because Codex's own VPS lacks a `php` binary;
cleared once it ran phpunit in the host's docker container. Not a code finding.

## Result
Two-person rule SATISFIED. The 3.4.14 auth re-pin is co-signed for upload.
Remaining (human, outward-facing): upload `mumega-mcp-3.4.14.zip` to wp.org +
send the reviewer reply (`wp-ai-operator/docs/wporg-reviewer-reply-3.4.14.md`,
staged as a Gmail draft in the plugins@wordpress.org thread).
