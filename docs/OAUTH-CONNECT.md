# OAuth connector sign-in (MCPWP 3.2.0+)

MCPWP 3.2.0 adds a minimal **OAuth 2.1** authorization server so MCP clients can connect by **signing in** instead of pasting an API key. This is what the **claude.ai web** connector and **ChatGPT** connectors require (they don't expose an API-key field). API-key auth (`X-API-Key`) still works unchanged for config-file clients (Claude Desktop, Cursor, Windsurf).

## What it adds

| Endpoint | Purpose |
|----------|---------|
| `GET /.well-known/oauth-protected-resource` | RFC 9728 — points clients at the authorization server |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 — advertises endpoints + `S256` PKCE |
| `GET/POST /wp-json/mcpwp/v1/oauth/authorize` | consent screen (logged-in WP user), PKCE |
| `POST /wp-json/mcpwp/v1/oauth/token` | token exchange — `authorization_code`, `refresh_token`, `client_credentials` |

When a request hits `/mcp` without a valid credential and OAuth is enabled, the response carries `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource"` so clients discover the flow automatically.

No Dynamic Client Registration (the MCP spec makes it optional); use a pre-configured `client_id`.

## Enabling it

OAuth is **opt-in**. In `mcpwp_settings`:
- `oauth_enabled` → `true`
- `oauth_client_id` → your client id
- `oauth_redirect_uris` → an **exact-match allow-list** of the connector callback URLs (no wildcards; empty = reject all)
- (optional) `oauth_client_secret_hash` for confidential clients; public PKCE clients need no secret

The `mcpwp_at_` bearer tokens issued carry only the granted scopes.

## Security model

- **PKCE S256 mandatory** — `plain` and missing challenge rejected.
- **Scope clamped to the WP user's capability** — a Subscriber consenting cannot mint an admin-scoped token (admin→`manage_options`, write→`edit_posts`, else `read`); an absent scope defaults to `read`, never admin.
- **Broker-enrolled sites advertise `read` and `write` only** — the protected-resource metadata of a site whose authorization server is the mumcp.com broker lists exactly the scopes the broker grants. Advertising `admin` there made clients request it and the broker refuse with `invalid_scope`. Admin stays on the `X-API-Key` path.
- **Single-use auth codes**, 60s TTL, bound to client_id + redirect_uri + PKCE challenge + user; replay rejected.
- **redirect_uri exact-match allow-list** — no open redirect.
- **Refresh tokens** rotate on use and are user-bound.
- **Metadata origin** comes from `get_site_url()` (not a spoofable `Host` header); `X-Forwarded-Proto` honored only behind `MCPWP_TRUST_PROXY_HEADERS`.
- **Client-IP resolution** for rate limiting, the auth-failure throttle and the public feedback relay uses `REMOTE_ADDR` by default; proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`) are trusted only behind the same `MCPWP_TRUST_PROXY_HEADERS` opt-in, so a caller can't spoof them to evade the throttle or forge a whitelisted IP. A resolved value must parse as an IP address; anything else is discarded rather than used as a throttle key. Set the constant only when the site sits behind a trusted proxy that overwrites these headers.
- **Public feedback relay** (`POST /feedback/relay`) carries a second, site-wide ceiling on top of its per-IP limit, because a per-IP throttle bounds one source but not a flood spread across many genuine addresses.

## Which auth to use

| Client | Auth |
|--------|------|
| Claude Desktop / Code / Cursor / Windsurf | `X-API-Key` header (config file) — simplest |
| **claude.ai web** | **OAuth** (no key field in the web UI) |
| **ChatGPT** | **OAuth** |
| Machine-to-machine | `client_credentials` grant |
