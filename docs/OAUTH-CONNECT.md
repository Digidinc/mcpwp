# Connecting AI clients to your site (OAuth and API keys)

Applies to builds after v3.14.10 (the 3.14.11 line and later). Version 3.14.10 and earlier shipped an
older, opt-in OAuth server; see [Upgrading from 3.14.10 or earlier](#upgrading-from-31410-or-earlier).

The site is its own OAuth 2.1 authorization server. An MCP client such as the claude.ai web connector
or ChatGPT connects by **signing in to WordPress** instead of pasting an API key. Clients register
themselves (Dynamic Client Registration, RFC 7591), so there is no client id to configure by hand.
API-key authentication (`X-API-Key`, `Authorization: Bearer`, or `?api_key=`) works exactly as before.

## Connect a client

| Client | How | Auth |
|--------|-----|------|
| claude.ai (web, Desktop custom connector) | Add a custom connector with the MCP URL below, then sign in to WordPress when prompted. | OAuth |
| ChatGPT | Add a connector with the MCP URL and OAuth, sign in to WordPress when prompted. Or use the one-link **Add to ChatGPT** on the Setup page (an API key, not OAuth; see below). | OAuth, or scoped key |
| Cursor, Windsurf, Claude Code, any config-file client | Send the key in an `X-API-Key` header (or via the `mcp-remote` bridge). | API key |
| Machine to machine | API key, or the `client_credentials` grant (see below). | API key / OAuth |

Scope of this guide: everything about the site is taken from the plugin code. How claude.ai, ChatGPT
and other clients behave (their menus, what they cache, when they register) is external client
behaviour and can change; the steps for them are the usual ones, not a guarantee.

MCP URL (the protected resource): `https://YOUR-SITE/wp-json/mcpwp/v1/mcp`. On a site without pretty
permalinks it is `https://YOUR-SITE/?rest_route=/mcpwp/v1/mcp`.

To connect with OAuth you do not need to change a setting: OAuth is on by default. The person who
signs in must be a WordPress user at or above the site's minimum role (Editor by default).

What a client discovers on its own (nothing here is typed in by hand):

| URL | What it is |
|-----|------------|
| `/.well-known/oauth-protected-resource` | RFC 9728 metadata naming the MCP endpoint and this site as the authorization server (it names mumcp.com instead when the site is broker-enrolled and local OAuth is off) |
| `/.well-known/oauth-authorization-server` | RFC 8414 metadata listing the endpoints below |
| `/wp-json/mcpwp/v1/oauth/register` | Dynamic Client Registration |
| `/mcpwp-oauth/authorize` | Consent screen (browser) |
| `/wp-json/mcpwp/v1/oauth/token` | Token endpoint |
| `/wp-json/mcpwp/v1/oauth/revoke` | RFC 7009 revocation |

An unauthenticated call to the MCP endpoint answers `401` with a `WWW-Authenticate: Bearer
resource_metadata="..."` header, which is how a client finds the metadata.

## What the consent screen grants

The user sees the app's name (the app's own claim, not a verified identity), the host the approval is
sent to, and what it will be able to do:

- **Read your site's content**: always on, cannot be unticked.
- **Create and edit content** (drafts, pages, posts, media, SEO, Elementor): shown only when the
  requested scope includes `write` and the user can `edit_posts`; the user can untick it.
- A fixed note: never administrator access. Settings, API keys and approvals stay out of reach.

The token runs **as the user who approved it**, so WordPress's own capability and per-post checks apply
on top of the scope. Anything that needs the `admin` scope stays on a deliberately created API key.
**Deny** returns the client `access_denied`. A user below the minimum role gets a plain refusal page
instead of a consent screen.

## See and revoke connections

- **A user, for their own apps:** WP Admin -> Users -> Profile -> **Connected AI apps**. Each app shows
  its access (Read only, or Read and write), when it connected and when it was last used. **Revoke**
  cuts one app off at once. An administrator can do the same on another user's profile.
- **An administrator, for one user:** on that user's profile, **Disconnect all AI apps for this user**
  cuts all of that user's apps (the button is not shown to non-administrators).
- **An administrator, for the whole site:** MCPWP -> Setup -> **Advanced** -> **AI apps**. Every
  registered app, with its active token count. **Remove** stops that app for every user.
- **The app itself** can call `/oauth/revoke` (RFC 7009).

A revoked app has to be connected again (it signs in again; no site change is needed).

## Reconnecting an existing ChatGPT connector

The discovery documents advertise only the `read` and `write` scopes; `admin` is never offered over OAuth
(admin-level actions stay on the deliberate `X-API-Key` path). A connector added before that was the
case, or one that was set up against the mumcp.com broker, may hold a cached registration or scope
request and can fail at sign-in with `invalid_scope`.

After updating MCPWP, remove the connector in ChatGPT and add it again. It fetches the current
metadata and registers fresh. Nothing needs changing on the site. API keys are unaffected. A site
address change also needs this, because tokens are bound to the MCP URL.

## Troubleshooting

The errors below are the ones this plugin emits. OAuth errors are JSON `{"error": "...",
"error_description": "..."}` unless noted.

| Symptom or code | Cause | Fix |
|-----------------|-------|-----|
| `invalid_scope` at sign-in (claude.ai or ChatGPT), mentioning `admin` | Not emitted by this plugin: the site narrows an `admin` request to `write` and ignores unknown scopes. It comes from the **mumcp.com broker** when a client requests `admin`. The broker-side clamp fix is merged; whether it is deployed is outside the plugin and cannot be checked from the site. | Remove the connector and add it again after updating MCPWP so discovery points at the site (the site is the authorization server unless the broker is enrolled and local OAuth is off). If it persists on a broker-enrolled site, use the local server (turn it on under Setup -> Advanced) or an API key. |
| `501 server_error`, "OAuth is not enabled on this site." | The Setup -> Advanced switch is off. | Turn it on. Nothing was revoked; unexpired tokens work again. |
| `invalid_client`, "Unknown or missing client_id." (consent page), or `401 invalid_client`, "Invalid client_id." (token/revoke) | The `client_id` was never registered, was removed under AI apps, or was registered but never authorized and has since been purged (an unused registration older than 1 hour is purged lazily, when another registration runs). | The client registers again. If it caches a registration, clear it (remove and re-add the connector). |
| `invalid_request`, "redirect_uri is not registered for this client." | The `redirect_uri` is not an exact match of one (port ignored for `http` loopback) registered for that client. | Register the exact callback URL (no wildcards). Re-add the connector. |
| `invalid_redirect_uri` (HTTP 400 from `/oauth/register`) | For example: not `https` (or `http` on `localhost`, `127.0.0.1`, `[::1]`), a fragment or user info, non-printable-ASCII characters, over 2000 characters, not an absolute URL with a host, an invalid host or port, characters WordPress would rewrite, `redirect_uris` empty or not a JSON array of strings, or more than 5. The `error_description` names which. | Fix the client's callback URL list. |
| `invalid_client_metadata` (400 from `/oauth/register`) | For example: the body is not a JSON object, or `token_endpoint_auth_method`, `grant_types`, `response_types` or `client_name` is malformed (grant types must include `authorization_code` and may add only `refresh_token`; response types only `code`). The `error_description` names which. | Fix the registration request. |
| `429 temporarily_unavailable` from `/oauth/register`, "Too many app registrations right now" | A per-address, per-site or per-minute registration limit. Honour `Retry-After`. | Wait, or register as an administrator (exempt). |
| `429`, "This site has reached its limit of registered apps." | 500 stored apps; no `Retry-After`. | An administrator removes unused apps under Setup -> Advanced -> AI apps. |
| `503 temporarily_unavailable` ("busy" or "could not be saved") | Store lock contention. | Retry. |
| `invalid_request`, "code_challenge_method must be S256." / "code_challenge is required." / "code_challenge must be 43 to 128 characters of A-Z a-z 0-9 - . _ ~." | PKCE is mandatory, S256 only, RFC 7636 shape. | Fix the client; `plain` is never accepted. |
| `invalid_request`, "code_verifier is required (PKCE mandatory)." | Token request has no verifier. | Send `code_verifier`. |
| `invalid_grant`, "code_verifier must be 43 to 128 characters ..." or "PKCE verification failed." | Verifier has the wrong shape (for example base64 with `+ / =`) or does not match the challenge. | Use a base64url verifier of 43-128 characters, and the one that produced the challenge. |
| `invalid_grant`, "Authorization code is invalid or has expired." / "has expired." / "has been revoked." | Codes last 60 seconds and are single use. A second presentation of the same code also revokes the tokens its first use created. | Start the connection again; do not retry a code. |
| `invalid_grant`, "redirect_uri mismatch." / "client_id mismatch." | The token request differs from the authorize request. | Send the same `redirect_uri` and `client_id`. |
| `invalid_grant`, "The approving user can no longer connect AI apps on this site." | The user was deleted or no longer holds the minimum role. | Restore the role (Setup -> minimum role) or connect as another user. |
| `invalid_grant`, "Refresh token is invalid or has expired." / "has been revoked." / "was issued to a different client." / "issued for a different resource." | Refresh tokens last up to 30 days, rotate on every use and are bound to client and resource. Presenting an already-rotated token revokes the whole family. | Connect again. |
| `invalid_target`, "resource must be this site's MCP endpoint URL." | The `resource` parameter names another URL (for example after a site address change). | Send the current MCP URL, or re-add the connector. |
| `400 invalid_request`, "The consent form was altered or has expired." | The consent page sat open too long, or the form was changed. | Start the connection again. |
| `access_denied` in the redirect | The user pressed Deny. | Approve, if intended. |
| Consent page: "Your WordPress role can't connect AI apps on this site; ask an administrator." | The signed-in user is below the minimum role (Editor by default). | An administrator signs in, or changes the minimum role on the Setup page. |
| `401`, `invalid_api_key` "Invalid API key." on a Bearer call | The access token is unknown, expired (default 1 hour), or **revoked** (app removed, user revoke, revoked refresh family, `/oauth/revoke`), or local OAuth is off. All of these look the same on the wire. | Try the refresh token; if that is refused, reconnect. |
| `401`, `invalid_token`, "This access token has been revoked." | Rarer: the token row is still active but its client or user was revoked at or after it was issued. | Reconnect. |
| `401`, `invalid_token`, "This access token was issued for a different resource." | Token minted for another site address. | Reconnect. |
| `401`, `invalid_token`, "The WordPress user who approved this connection can no longer connect AI apps on this site." | User deleted or demoted. | Restore the user, or connect as someone else. |
| `401 missing_api_key` when sending an OAuth token | Access tokens are accepted only in `Authorization: Bearer`, never in `X-API-Key` or `?api_key=`. | Move it to the Bearer header. |
| `403 insufficient_scope`, "API key lacks required scope: write/admin" | The token was approved as read only (or `write` was unticked), or the tool needs `admin`, which OAuth never grants. | Reconnect and keep "Create and edit content" ticked, or use an Admin-role API key for admin actions. A user token can also be refused on a particular post by WordPress's own capability check. |
| `429` from the token endpoint (`client_credentials` or revoke) | Per-address rate limit. | Back off. |

Behind a proxy or CDN, set `MCPWP_TRUST_PROXY_HEADERS` only when the proxy overwrites the forwarding
headers; otherwise all callers share one address and hit the registration limits sooner.

## Reference

The sections below are the detail behind the steps above.

### Turning it on and off

OAuth is **on by default**: it is on whenever the `oauth_enabled` setting was never stored. The
on/off switch is under **MCPWP → Setup → Advanced**; saving it stores an explicit value. While it is
off, the authorization-server metadata is not served (404), `/oauth/*` REST routes answer `501
server_error`, the front-channel authorize endpoint is not served (the pretty `/mcpwp-oauth/authorize`
URL answers 404; `/?mcpwp_oauth=authorize` falls through to the normal page), and an access token is
not accepted (`401 invalid_api_key`). The protected-resource metadata and the `WWW-Authenticate` header
are still served while the site is enrolled with the mumcp.com broker (they then name the broker); on a
site that is neither enrolled nor local-OAuth-on they are not sent. Turning it off does not revoke anything, so a token that has not
expired works again if OAuth is switched back on.

### Endpoints (reference)

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

### The flow (for client authors)

1. **Registration.** The client registers itself (`POST /oauth/register`) with a name and one to five
   redirect URIs and receives a public `client_id` (prefix `mcpwpc_`). No client secret is ever
   issued. Redirect URIs must be `https`, or `http` on a loopback host, with no fragment and no
   userinfo.
2. **Authorization.** The client sends the user to `/mcpwp-oauth/authorize` with `response_type=code`,
   `client_id`, `redirect_uri` (exact match against the registered list; for an `http` loopback URI on `localhost`, `127.0.0.1` or `[::1]` the port is ignored, RFC 8252), `code_challenge`,
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

### Scope, identity and the minimum role

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
  sent, must name that URL (the bare site origin, the older identifier, is also accepted in the request; a token's stored audience must be the MCP URL). If the site address changes, connected apps must reconnect.

### Token lifetimes

| Token | Lifetime |
|-------|----------|
| Authorization code | 60 seconds, single use |
| Access token | `oauth_token_ttl` setting, default 1 hour, limited to 5 minutes to 24 hours |
| Refresh token | 30 days by default, limited to 14 to 30 days (filter `mcpwp_oauth_refresh_ttl_days`) |

Tokens are stored only as SHA-256 hashes in the `mcpwp_oauth_tokens` table.

### Revocation and reuse detection

A token stops working at once when any of these happens:

- the app is **removed** (MCPWP → Setup → Advanced → AI apps, administrators);
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

What the client sees afterwards: a token revoked this way (app removed, user revoke, revoked refresh
family, `/oauth/revoke`) has its row marked revoked, and the next call with it answers
`401 invalid_api_key` ("Invalid API key.") like an unknown or expired token. The `invalid_token`
("This access token has been revoked.") answer is only reached when the token's row is still active
but a revocation epoch (client or user revoked at or after it was issued) covers it. Either way the
fix is to refresh, or reconnect if refresh fails.

`/oauth/revoke` is rate-limited per address (`429`, checked first). It answers `400 invalid_request`
without `token` or `client_id`, `401 invalid_client` for an unknown client (or a wrong secret), `200`
for a token it does not know from a valid client, and `503 temporarily_unavailable` (with
`Retry-After`) when a revocation could not be saved, so a client can retry.

### Limits on registration

Registration is unauthenticated by design, so it is bounded. Nothing is ever displaced: every
`client_id` a `201` hands out stays valid until it expires.

- A client that is **never used to complete an authorization** expires after **1 hour** and stops
  counting. The expiry is lazy: stale clients are purged only when another registration runs, and a
  lookup does not check age, so an unused `client_id` can keep working past the hour until then. A client that has been authorized is kept until it is deleted.
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

### The `client_credentials` grant

For machine-to-machine use, a site can configure a confidential client in `mcpwp_settings`
(`oauth_client_id`, plus a client secret saved as a hash on the Settings page and optionally
`oauth_redirect_uris` for the interactive flow). `client_credentials` requires both `client_id` and
`client_secret`, has no user, issues no refresh token, and its scope is capped by the site setting
`oauth_client_credentials_max_scope` (default `write`). Only an administrator can raise that ceiling
to `admin`; the request cannot.

### The broker path (mumcp.com)

A site can instead enroll with the mumcp.com broker (MCPWP → Setup → Advanced: OAuth sign-in via mumcp.com → Connect to mumcp.com). The
broker issues opaque Bearer tokens that the plugin validates by cached, authenticated introspection;
the site's local authorization server takes precedence in the protected-resource metadata when both
are on. Broker tokens are mapped to `read` or `write`, never `admin`, and **Disconnect** rejects every
broker token at once without a network call. The design is in
`docs/superpowers/specs/2026-07-20-mumcp-oauth-broker-design.md`.

### "Add to ChatGPT"

The one-link ChatGPT connector on the Setup page is not OAuth: it is an ordinary scoped API key carried
in the URL (`?api_key=`) with ChatGPT's authentication set to "No authentication". Its key is read +
write and never `admin`.

### Security model

- PKCE `S256` is mandatory; the challenge and verifier are shape-checked.
- Authorization codes are single use, short-lived and bound to client, redirect URI, challenge and user.
- Redirect URIs are matched exactly against the client's registered list (the port is ignored only for `http` loopback hosts, RFC 8252).
- Refresh tokens are bound to their client, rotate on use, and are revoked as a family on reuse.
- The metadata origin comes from the site's configured URL, not the `Host` header;
  `X-Forwarded-Proto` is honored only behind `MCPWP_TRUST_PROXY_HEADERS`.
- The public feedback relay (`POST /feedback/relay`) has its own per-address and site-wide limits.

### Compatibility changes (release note)

- A PKCE `code_verifier` that is not 43 to 128 characters of `A-Z a-z 0-9 - . _ ~` is now refused with
  `400 invalid_grant` (RFC 7636 §4.1). Verifiers of 42 or 129 characters, or standard-base64 verifiers
  using `+`, `/` or `=`, were accepted before. The same shape is required of `code_challenge` at
  authorize (`invalid_request`).
- A client that registered but never completed an authorization now expires after **1 hour** (it was
  24 hours). A client that caches its registration and first authorizes more than an hour later gets
  `invalid_client` once the stale client has been purged (purging happens during a later registration, so it is not
  at the one-hour mark exactly) and must register again.
- An OAuth access token is accepted only in `Authorization: Bearer`; in `X-API-Key` or `?api_key=` it
  is refused (`401 missing_api_key`).

### Upgrading from 3.14.10 or earlier

- Tokens issued by the older build are carried over once, for the length of their remaining life (the
  grace window is at most 31 days). One that belongs to a user and was granted `admin` is capped to
  `read write`; one that belongs to no user (a machine token) is not capped by this.
- The older build stored `oauth_enabled`, a single configured client and an allow-list of redirect URIs
  in `mcpwp_settings`. That client still works as a confidential client and for `client_credentials`;
  registered clients are stored separately.
- A stored `oauth_enabled = false` from the older build is removed once on first load of the new build,
  after which the default (on) applies; the Setup → Advanced switch then stores an explicit value.
