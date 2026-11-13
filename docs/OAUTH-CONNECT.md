# OAuth connector sign-in

This describes what the plugin does today on `main` (unreleased builds after v3.14.10). Version
3.14.10 and earlier shipped an older, opt-in OAuth server; the differences are called out under
[Upgrading from 3.14.10 or earlier](#upgrading-from-31410-or-earlier).

The site is its own OAuth 2.1 authorization server. An MCP client such as the claude.ai web
connector or ChatGPT connects by **signing in** to WordPress instead of pasting an API key. API-key
authentication (`X-API-Key`, `Authorization: Bearer`, or `?api_key=`) still works exactly as before
for config-file clients (Claude Desktop, Cursor, Windsurf, Claude Code).

## Turning it on and off

OAuth is **on by default**: it is on whenever the `oauth_enabled` setting was never stored. The
on/off switch is under **MCPWP → Setup → Advanced**; saving it stores an explicit value. While it is
off the authorization-server and protected-resource metadata are not served (404) and the
`WWW-Authenticate` header is not sent, `/oauth/*` REST routes answer `501 server_error`, the
front-channel `/mcpwp-oauth/authorize` answers 404, and an access token is not accepted. Turning it off does not revoke anything, so a token that has not
expired works again if OAuth is switched back on.

## Endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET /.well-known/oauth-protected-resource` | RFC 9728 metadata. Also served at the path-insertion URL `/.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp`. The protected resource is the MCP endpoint, `…/wp-json/mcpwp/v1/mcp`. |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 metadata: endpoints, `S256` PKCE, grant types, public clients (`token_endpoint_auth_methods_supported: ["none"]`), `scopes_supported: ["read","write"]`. |
| `POST /wp-json/mcpwp/v1/oauth/register` | RFC 7591 Dynamic Client Registration. Unauthenticated. |
| `GET/POST /mcpwp-oauth/authorize` | The consent screen (also `?mcpwp_oauth=authorize` on a site without pretty permalinks). It is not a REST route: a browser redirect cannot carry the REST nonce, which used to trap users in a login loop. `GET /wp-json/mcpwp/v1/oauth/authorize` only redirects here. |
| `POST /wp-json/mcpwp/v1/oauth/token` | `authorization_code`, `refresh_token` and `client_credentials`. |
| `POST /wp-json/mcpwp/v1/oauth/revoke` | RFC 7009 revocation. |

An unauthenticated request to `/mcp` answers `401` with
`WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp"`
(the path-insertion URL of the MCP endpoint), so a client
discovers the flow on its own. The protected-resource document advertises
`bearer_methods_supported: ["header"]`.

## The flow

1. **Registration.** The client registers itself (`POST /oauth/register`) with a name and one to five
   redirect URIs and receives a public `client_id` (prefix `mcpwpc_`). No client secret is ever
   issued. Redirect URIs must be `https`, or `http` on a loopback host, with no fragment and no
   userinfo.
2. **Authorization.** The client sends the user to `/mcpwp-oauth/authorize` with `response_type=code`,
   `client_id`, `redirect_uri` (exact match against the registered list), `code_challenge`,
   `code_challenge_method=S256`, and optionally `state` (echoed back byte for byte when sent), `scope` and
   `resource`.
   - PKCE is mandatory: `plain` and a missing challenge are refused. The challenge must be 43 to 128
     characters of `A-Z a-z 0-9 - . _ ~` (RFC 7636).
   - A user who is not logged in is sent to the WordPress login and back.
   - The consent screen shows the app name, the redirect host the code will be sent to, and the
     scope. Approving issues a **single-use code valid for 60 seconds**, bound to the client, the
     redirect URI, the PKCE challenge and the user.
3. **Token exchange.** `POST /oauth/token` with `grant_type=authorization_code`, `code`,
   `redirect_uri`, `client_id` and `code_verifier` (43 to 128 unreserved characters). A client secret
   is optional and not needed. The response holds an access token (`mcpwp_at_…`) and a refresh token
   (`mcpwp_rt_…`).
4. **Calling the MCP endpoint.** `Authorization: Bearer <access token>`. An access token is accepted
   **only** in that header, never in `X-API-Key` or `?api_key=`.
5. **Refresh.** `grant_type=refresh_token` with `refresh_token` and `client_id`. The refresh token
   rotates on every use.
6. **Revocation.** `POST /oauth/revoke` with `token` and `client_id` (RFC 7009).

Every token-endpoint answer, success or error, carries `Cache-Control: no-store` and
`Pragma: no-cache`.

## Scope, identity and the minimum role

- **A token that belongs to a user is never `admin`.** The scopes of the interactive flow are `read` and
  `write`. (The machine-to-machine `client_credentials` grant has no user; its ceiling is a site setting
  an administrator can raise, see below.) A request for `admin`
  is narrowed to what the user may hold (at most `write`); an absent scope means `read write`; unknown scopes are ignored. Anything
  that needs the `admin` scope stays on a deliberately created API key.
- **Scope is clamped to the user's capabilities** at consent, at code redemption and on every
  refresh: a user who cannot `edit_posts` gets `read` only.
- **A token acts as the user who approved it**, not as a service account, so WordPress's own
  capability and per-post checks apply, and the scope is a further ceiling on top. A token that
  belongs to a user is never `admin`, even when read from storage: a record carried over from the
  3.14.10 build with `admin` in it is capped to `read write` when it is carried over and again each
  time it authenticates. A record that belongs to no user (a machine token) is not capped by this.
- **Minimum role.** The approving user must hold the site's minimum role for AI connections
  (**Editor** by default; Author or Administrator can be chosen under Setup). The check runs at
  consent, at code redemption, at every refresh and on every request, so deleting or demoting the
  user stops their tokens.
- **Audience.** Tokens are bound to the MCP endpoint URL (RFC 8707). A `resource` parameter, when
  sent, must name that URL. If the site address changes, connected apps must reconnect.

## Token lifetimes

| Token | Lifetime |
|-------|----------|
| Authorization code | 60 seconds, single use |
| Access token | `oauth_token_ttl` setting, default 1 hour, limited to 5 minutes to 24 hours |
| Refresh token | 30 days by default, limited to 14 to 30 days (filter `mcpwp_oauth_refresh_ttl_days`) |

Tokens are stored only as SHA-256 hashes in the `mcpwp_oauth_tokens` table.

## Revocation and reuse detection

A token stops working at once when any of these happens:

- the app is **deleted** (MCPWP → Setup → Advanced → AI apps, administrators);
- the user **revokes** it from their profile (**Connected AI apps**), or an administrator disconnects
  all of a user's apps;
- the user is deleted, loses the minimum role, or an administrator revokes the user's apps;
- the client calls `/oauth/revoke` for a refresh token (its whole family goes: every rotation and every
  access token issued alongside) or an access token;
- a **rotated refresh token is presented again**: the whole family is revoked and the request is refused
  with `invalid_grant`;
- an **authorization code is presented a second time**: the tokens its first redemption created are
  revoked (RFC 6749 §4.1.2) and the request is refused with `invalid_grant`. This happens only when
  the presenter authenticates as the client the code was redeemed for: the same client id and
  redirect URI and, for the site's configured confidential client, its correct client secret (omitting
  the secret, or sending a wrong one, revokes nothing; a registered public client has no secret, so
  its client id and exact redirect URI are what is checked), only within one day of the redemption, and not
  within 10 seconds of it: a client that submits its callback twice in parallel gets `invalid_grant` for
  the loser and keeps the winner's tokens.

`/oauth/revoke` answers `200` for any token it does not know, and `503 temporarily_unavailable` (with
`Retry-After`) when a revocation could not be saved, so a client can retry.

## Connected apps screen

- **Profile → Connected AI apps**: the apps holding live tokens for that user, each with a Revoke
  (a user may revoke their own; an administrator anyone's).
- **MCPWP → Setup → Advanced → AI apps**: every registered app, with Delete (administrators).

App names come from registration, which is anonymous, so they are shown escaped and should be read as
the app's claim, not a verified identity.

## Limits on registration

Registration is unauthenticated by design, so it is bounded. Nothing is ever displaced: every
`client_id` a `201` hands out stays valid until it expires.

- A client that is **never used to complete an authorization** expires after **1 hour** and stops
  counting. A client that has been authorized is kept until it is deleted.
- Per address (IPv4, or an IPv6 /64): at most **20** such pending registrations at once and 10 per hour
  per set of redirect hosts. The address is the plugin's trusted resolution: `REMOTE_ADDR`, and the
  `CF-Connecting-IP`, `X-Forwarded-For` and `X-Real-IP` headers only when `MCPWP_TRUST_PROXY_HEADERS`
  is defined true. Set that constant when the site sits behind a trusted proxy that overwrites those
  headers; without it every caller behind the proxy shares one address.
- Site-wide: at most **100** pending registrations at once, and **60 registrations per minute**
  whatever the number of addresses (this bounds the writes an anonymous flood can cause).
- A per-address, per-site or per-minute limit that is hit answers `429` with a `Retry-After`: the moment
  the oldest pending registration (or the minute, or the hour) expires.
- **Residual risk:** five addresses (5 x 20) can hold all 100 pending slots, and anyone with 60
  registrations a minute can keep refilling them, so a determined flood from many addresses can shut
  anonymous registration for as long as it lasts (each registration lasts at most one hour). It
  cannot displace an existing registration, and an authenticated administrator is exempt.
- An **authenticated administrator** (a logged-in cookie with the REST nonce, or an administrator's
  application password) is exempt from all of these limits. Not exempt: a cookie without the nonce, a
  non-administrator, an API key, or forwarding headers. A client an administrator registers counts
  against no limit and is not purged for age; it is kept until it is used or deleted. (The 500-client
  store cap below still applies.)
- At most 500 clients are stored. Pending ones expire, so what is left at the cap is authorized or
  administrator-registered clients, which never expire: the newcomer (an administrator too) is refused
  with `429` and a message, with no `Retry-After`, because it does not clear by itself. An administrator
  makes room by deleting apps under **MCPWP → Setup → Advanced → AI apps** (one at a time; there is no
  bulk delete).
- The limits are checked twice: first without the lock (a request it refuses never touches the lock and
  counts nothing), then again under the lock on counts read fresh from the store, never from a cached
  copy, so parallel requests cannot overrun the per-minute or per-hour limit. A request that passed the
  first check and fails the second did take the lock; it still counts nothing. Under heavy contention a
  request within the limits can wait about a second for the lock and then get `503` (retry).

`client_credentials` and `/oauth/revoke` are rate-limited per address; the limiter's answer is an OAuth
error body (`429`).

## The `client_credentials` grant

For machine-to-machine use, a site can configure a confidential client in `mcpwp_settings`
(`oauth_client_id`, plus a client secret saved as a hash on the Settings page and optionally
`oauth_redirect_uris` for the interactive flow). `client_credentials` requires both `client_id` and
`client_secret`, has no user, issues no refresh token, and its scope is capped by the site setting
`oauth_client_credentials_max_scope` (default `write`). Only an administrator can raise that ceiling
to `admin`; the request cannot.

## The broker path (mumcp.com)

A site can instead enroll with the mumcp.com broker (MCPWP → Setup → Advanced: OAuth sign-in via mumcp.com → Connect to mumcp.com). The
broker issues opaque Bearer tokens that the plugin validates by cached, authenticated introspection;
the site's local authorization server takes precedence in the protected-resource metadata when both
are on. Broker tokens are mapped to `read` or `write`, never `admin`, and **Disconnect** rejects every
broker token at once without a network call. The design is in
`docs/superpowers/specs/2026-07-20-mumcp-oauth-broker-design.md`.

## "Add to ChatGPT"

The one-link ChatGPT connector on the Setup page is not OAuth: it is an ordinary scoped API key carried
in the URL (`?api_key=`) with ChatGPT's authentication set to "No authentication". Its key is read +
write and never `admin`.

## Security model

- PKCE `S256` is mandatory; the challenge and verifier are shape-checked.
- Authorization codes are single use, short-lived and bound to client, redirect URI, challenge and user.
- Redirect URIs are matched exactly against the client's registered list.
- Refresh tokens are bound to their client, rotate on use, and are revoked as a family on reuse.
- The metadata origin comes from the site's configured URL, not the `Host` header;
  `X-Forwarded-Proto` is honored only behind `MCPWP_TRUST_PROXY_HEADERS`.
- The public feedback relay (`POST /feedback/relay`) has its own per-address and site-wide limits.

## Which auth to use

| Client | Auth |
|--------|------|
| Claude Desktop / Code / Cursor / Windsurf | `X-API-Key` header (config file), the simplest |
| claude.ai web | OAuth |
| ChatGPT | OAuth, or the "Add to ChatGPT" link |
| Machine-to-machine | `client_credentials`, or an API key |

## Compatibility changes (release note)

- A PKCE `code_verifier` that is not 43 to 128 characters of `A-Z a-z 0-9 - . _ ~` is now refused with
  `400 invalid_grant` (RFC 7636 §4.1). Verifiers of 42 or 129 characters, or standard-base64 verifiers
  using `+`, `/` or `=`, were accepted before. The same shape is required of `code_challenge` at
  authorize (`invalid_request`).
- A client that registered but never completed an authorization now expires after **1 hour** (it was
  24 hours). A client that caches its registration and first authorizes more than an hour later gets
  `invalid_client` and must register again.
- An OAuth access token is accepted only in `Authorization: Bearer`; in `X-API-Key` or `?api_key=` it
  is refused (`401 missing_api_key`).

## Upgrading from 3.14.10 or earlier

- Tokens issued by the older build are carried over once, for the length of their remaining life (the
  grace window is at most 31 days). One that belongs to a user and was granted `admin` is capped to
  `read write`; one that belongs to no user (a machine token) is not capped by this.
- The older build stored `oauth_enabled`, a single configured client and an allow-list of redirect URIs
  in `mcpwp_settings`. That client still works as a confidential client and for `client_credentials`;
  registered clients are stored separately.
- A stored `oauth_enabled = false` from the older build is removed once on first load of the new build,
  after which the default (on) applies; the Setup → Advanced switch then stores an explicit value.
