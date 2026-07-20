# mumcp Broker Plugin (Plan 2) — SDD Progress Ledger
Repo: Mumega-com/mcpwp (wp-ai-operator) · branch feat/mumcp-broker-plugin (from main 3.6.4)
Plan: docs/superpowers/plans/2026-07-20-mumcp-broker-plugin-plan2.md
Grounding: scratchpad/plan2-grounding.md
Baseline: 502 phpunit tests. Test cmd: cd mcpwp && docker run --rm -v "$(pwd):/app" -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml

## Tasks
- [ ] T1: .well-known/oauth-protected-resource → mumcp.com when enrolled
- [ ] T2: enrollment endpoints /broker/enroll-callback + /broker/enroll-confirm
- [ ] T3: cached introspection validator Mcpwp_Broker_Auth (fail-closed, audience, kill-switch)
- [ ] T4: auth integration — Bearer branch in verify_api_key()
- [ ] T5: enrollment initiation + Connections admin UI + Disconnect
- [ ] T6: version bump 3.7.0 + changelog + build

## Notes
- Hook: verify_api_key() after looks_like_oauth_access_token() (~line 171), before find_scoped_api_key().
- Broker live at mumcp.com (deployed 2026-07-20 v5b6a865d). Introspection Basic auth base64(site_id:site_credential).
- Doc drift: mcpwp_bypass_api_key_check filter is DEAD; class-mcpwp-rest-site.php split into site-info + siblings.

## Log
