# mumcp.com Auth Broker — Design Spec

**Date:** 2026-07-20 (rev. 2026-07-20 — build on `cloudflare/workers-oauth-provider`)
**Status:** Draft (awaiting user review)
**Supersedes:** PR #598 (per-site OAuth authorization server) — stripped to a token-validator; see §9.

## 1. Problem

claude.ai web and ChatGPT connectors have no API-key field — they connect by **signing in** over OAuth 2.1. Today that fails against an MCPWP site:

1. mcpwp.net has `oauth_enabled` off, so `.well-known` discovery 404s → "Couldn't reach."
2. Even enabled, 3.6.2 has **no Dynamic Client Registration (DCR)** endpoint — claude.ai self-registers before authorizing and aborts without a `registration_endpoint`.
3. The per-site authorize endpoint lives under `/wp-json`, where WordPress treats a cookie-authenticated browser as logged-out and bounces to `wp-login` (a consent login-loop).

PR #598 tried to fix all three inside the WordPress plugin (~899 lines of PHP OAuth server). That puts a security-critical, browser-driven OAuth authorization server on **every** customer site — high blast radius, the login-loop is inherent to `/wp-json`, and each site must be independently secured and maintained.

## 2. Goals / Non-Goals

**Goals**
- claude.ai / ChatGPT connect to an MCPWP site by signing in, with the hard OAuth+DCR+browser-authorize logic hosted **once** on mumcp.com (Cloudflare), not per-site.
- The OAuth 2.1 engine (metadata, DCR, token exchange, refresh rotation, token storage, PKCE) is **not hand-rolled** — it is the maintained `cloudflare/workers-oauth-provider` library. We build only the pieces the library leaves to the application: accounts, enrollment, the consent UI, and the site↔broker token-validation contract.
- The WordPress plugin shrinks to a **stateless-ish token validator** (a cached introspection client) + a `.well-known` pointer + a one-time enrollment client.
- mumcp.com is **not** in the MCP request data path — it mints and validates tokens; MCP payloads go claude.ai → site directly.
- Claude Desktop / Cursor / Windsurf keep working unchanged via `X-API-Key`.

**Non-Goals (this spec)**
- Full Agency Console UI (multi-site management, sub-users) — separate later spec (#553).
- A gateway that proxies MCP requests (the "full gateway" option was rejected in favor of auth-only).
- Migrating existing `X-API-Key` users — the key path is untouched.

## 3. Architecture Decisions (resolved forks)

| Decision | Choice | Rationale |
|---|---|---|
| **OAuth engine** | **`cloudflare/workers-oauth-provider` (npm)** — not hand-rolled | Library is the Worker entrypoint; auto-implements RFC 8414 metadata, RFC 7591 DCR, `/oauth/token` (code + refresh rotation), PKCE-S256, and KV token storage. Collapses the risky crypto/protocol surface to config. We keep only accounts, enrollment, consent UI, and validation. |
| Broker shape | **Auth-only** (mint + validate tokens; not in MCP data path) | No data-plane SPOF, no payload latency hop, site keeps control; RFC 9728 supports AS ≠ resource. |
| Identity/trust | **mumcp.com accounts + one-time site enrollment** | Central place to manage/revoke grants; aligns with Agency Console (#553). |
| **Token mechanism** | **Opaque bearer tokens issued by the library, validated via broker introspection (RFC 7662) with short-TTL plugin-side caching** | The library issues opaque tokens (`userId:grantId:randomSecret`, KV-hashed, props encrypted) — **there is no JWT and no JWKS**, so offline signature validation is impossible. The plugin validates by calling the broker's introspection endpoint (which wraps `unwrapToken`) and caches the result briefly. See §5.3 / §12. |
| Admin scope over OAuth | **Capped at read/write; admin stays API-key-only** | The #683 review found OAuth-admin weakens approval separation-of-duties (self-approval class). Admin capability stays on the deliberate key-paste path (Claude Desktop). |
| Revocation | **Short access-token TTL + server-side `revokeGrant` + local kill-switch** | Instant local rejection on disconnect; library rotates/expires refresh tokens; no per-request revocation lookup beyond the cached introspection. |
| Account login (v1) | **Email magic-link** | Passwordless; no password hashing/breach surface on the Worker. |

## 4. The Two Paths

- **Web (claude.ai / ChatGPT):** OAuth sign-in via mumcp.com → opaque bearer token → site validates by introspection. This spec.
- **Direct (Claude Desktop / Cursor / Windsurf):** `X-API-Key` header straight to `site/wp-json/mcpwp/v1/mcp`. Already works; documented alongside as the "direct" path. Verified live as part of this work.

## 5. Components & Flow

### 5.1 Enrollment (one-time, per site)
1. Admin: WP-Admin → MCPWP → Connections → **Connect to mumcp.com**.
2. Browser redirects to mumcp.com; user signs into (or creates) their mumcp account via magic-link, consents "add this site."
3. mumcp.com records the site (site_id, canonical URL, owning account), mints a **per-site introspection credential** (a site secret used to authenticate future introspection calls), and redirects back to the plugin with an enrollment payload:
   ```json
   {
     "issuer": "https://mumcp.com",
     "site_id": "<opaque>",
     "audience": "https://<site-canonical-url>",
     "introspection_endpoint": "https://mumcp.com/oauth/introspect",
     "site_credential": "<per-site secret used to auth introspection>"
   }
   ```
4. Plugin stores it in `mcpwp_broker_enrollment` (wp_option). The site now trusts mumcp.com as its authorization server and holds the credential to validate tokens against it.

Trust is anchored on the **enrollment handshake + per-site introspection credential**: the plugin proves control of the site during enrollment (nonce round-trip, §8), and the broker binds each grant to a `site_id`/`canonical_url` so a token minted for one site cannot be introspected or accepted by another. (Note: the library's tokens are opaque, so there is no public key to publish — validation is by authenticated introspection, not JWKS.)

### 5.2 Discovery chain (when claude.ai connects)
1. User adds the site URL to claude.ai.
2. claude.ai `GET <site>/.well-known/oauth-protected-resource` → `{ "resource": "<site>", "authorization_servers": ["https://mumcp.com"] }` — **plugin serves this** (RFC 9728, tiny; only when enrolled). The library's own RFC 9728 support protects an MCP server *on the worker*; our resource is the remote WP site, so the plugin serves this pointer itself.
3. claude.ai `GET https://mumcp.com/.well-known/oauth-authorization-server` → advertises `authorization_endpoint`, `token_endpoint`, `registration_endpoint`, `introspection_endpoint`, `code_challenge_methods_supported: [S256]` — **`OAuthProvider` serves this automatically** from its config.
4. claude.ai **DCR** `POST https://mumcp.com/oauth/register` (RFC 7591) → `{ client_id }` (public client + PKCE; no secret) — **library-implemented**; no pre-registered client needed.
5. claude.ai `authorize` → `https://mumcp.com/authorize?...&resource=<site>&code_challenge=...` → **our consent handler** (`parseAuthRequest` → render consent → `completeAuthorization`) → user signs into mumcp account, consents (site + scope) → redirect back with `code`.
6. claude.ai `token` → `POST https://mumcp.com/oauth/token` (code + PKCE verifier) → **opaque access token** (grant carries `props={site_id, canonical_url, account_id}`, `scope`) + refresh token — **library-implemented**.

### 5.3 Runtime (every MCP call)
claude.ai → `<site>/wp-json/mcpwp/v1/mcp` with `Authorization: Bearer <opaque-token>`. Plugin validates:
- **Kill-switch first:** if `mcpwp_broker_enrollment` is off, reject immediately, no network.
- **Cache hit:** if a positive introspection result for `hash(token)` is cached (short TTL, ≤60s, bounded by the token's `exp`), accept without a network call.
- **Cache miss:** plugin `POST https://mumcp.com/oauth/introspect` authenticated with its per-site `site_credential`, body `{token}`. Broker calls `env.OAUTH_PROVIDER.unwrapToken(token)` and returns `{active, scope, sub, site_id, aud (=canonical_url), exp}`. Plugin then:
  - rejects if `active !== true`,
  - rejects if `aud`/`site_id` ≠ this site's canonical URL / site_id (audience-confusion defense — a token minted for site A is refused by site B),
  - caches the positive result (≤60s), and
  - maps `scope` → MCPWP capabilities (same scope model as role-scoped API keys).

The broker is on the *validation* control-plane (cached, ≤1 hit per token per window), **never** on the MCP data path — it does not see or proxy MCP payloads.

### 5.4 Token / scope / revocation
- Access token TTL **~1h** (`accessTokenTTL`); refresh token (30d default, **rotated** on use by the library) held by claude.ai, exchanged at mumcp.com.
- Scope: `read | write` over OAuth — clamped in our consent handler and mapped to MCPWP's existing scope model. **`admin` is NOT grantable over OAuth in v1** (stays `X-API-Key`-only). See §7.
- Revocation:
  1. short access-token TTL + short introspection-cache TTL bound exposure;
  2. admin **Disconnect** in WP-Admin flips `mcpwp_broker_enrollment` off → plugin rejects **all** broker tokens instantly, no network;
  3. broker `revokeGrant(grantId, userId)` + site `active=false` → subsequent introspection returns `active:false` and refresh/token grants stop.

## 6. Implementation Targets

### 6.1 Plugin (PHP — small)
- **Keep/repoint:** `.well-known/oauth-protected-resource` points at mumcp.com (reuse #598's well-known scaffolding).
- **New:** a **cached introspection client** (POST token to broker with `site_credential`, cache positive result, check `aud`/`site_id`, map scope→capability); enrollment client + Connections settings UI; `Disconnect` kill-switch.
- **Drop (from #598):** per-site `/oauth/authorize`, `/oauth/token`, per-site DCR, the consent screen and its `/wp-json` login-loop workarounds. (No JWKS validator either — never needed; tokens are opaque.)
- Net: ~899 lines → a few hundred.

### 6.2 Broker Worker (mumcp.com — Cloudflare)
- The Worker's default export **is** `new OAuthProvider({...})`. It wraps the existing landing worker: non-API, non-OAuth requests (including `/`) fall through to our `defaultHandler`, which keeps serving the landing page.
- **Library config provides for free:** RFC 8414 metadata, RFC 7591 DCR (`clientRegistrationEndpoint`), `/oauth/token` code-exchange + refresh rotation (`tokenEndpoint`), PKCE-S256 (`allowPlainPKCE: false`), `accessTokenTTL: 3600`, `refreshTokenTTL` 30d, `scopesSupported: ['read','write']`, opaque-token KV storage with encrypted props.
- **We build (in `defaultHandler` + one KV):** magic-link account auth; `/enroll/*` handshake + per-site credential; the `/authorize` consent UI (`parseAuthRequest` → `lookupClient` → consent → `completeAuthorization({userId, scope, props})`); `POST /oauth/introspect` (wraps `unwrapToken`, per-site auth, `site_id` match); `/enroll/disconnect` + revocation (`revokeGrant`).
- **KV namespaces:** the library's own token/grant/client store (its binding) + our `KV_ACCOUNTS` and `KV_SITES`. No hand-rolled `auth_codes`/`refresh_tokens`/`oauth_clients` KV — the library owns those.
- **Bindings:** `OAUTH_PROVIDER` (OAuthHelpers, injected by the library into `env`), our KV, `SESSION_SECRET` (magic-link/session HMAC). **No ES256 signing key** — there are no JWTs to sign.
- Follows Cloudflare rules: KV for our state, `crypto.subtle` for magic-link/session HMAC, typed `Env`, secrets via `wrangler secret`.

## 7. Admin scope over OAuth — deferred (v1 caps at read/write)

`admin` scope is **not** grantable over web-OAuth in v1; it stays on the `X-API-Key` path. Our consent handler clamps `scope` to `read|write` before `completeAuthorization`, so an `admin` request can never mint an admin grant. This sidesteps the class the #683 adversarial review found: an OAuth-admin principal (or one actor holding both a user grant and client-credentials) could defeat push-to-live separation of duties by self-approving.

If admin-over-OAuth is ever added later, it is gated on hardening the approval floor first:
1. **Bind approval identity to the underlying WP user across grant types** — an approval created under one grant of a user cannot be approved under another grant of the same user.
2. **Clamp client-credentials scope** — a client-credentials token cannot silently obtain `admin`.
3. Centralize the existing `requester_is_oauth()` exclusion (shipped in #683) in one `requester_can_bypass_approval()` helper so future approval sites can't forget it.

These are tracked independently of this spec (they harden the existing approval system regardless of OAuth-admin).

## 8. Security considerations (adversarial gate required)

This is a new external-facing OAuth authorization server → per the agent-comms **adversarial-as-parallel-gate** rule, the implementation runs the adversarial security gate in parallel with correctness review before ship. Building on the library shrinks *our* attack surface to the pieces below (the library owns token crypto, storage, PKCE, and refresh rotation — reviewed upstream, but our config still gated):
- **Audience confusion:** token for site A must never validate on site B — grant `props` carries `site_id`/`canonical_url`; introspection returns them; plugin rejects mismatch.
- **Introspection endpoint auth:** `/oauth/introspect` must require the caller's per-site `site_credential` and only reveal a token's status to the site it is bound to (constant-time credential compare; reject cross-site introspection).
- **PKCE mandatory** (S256): set `allowPlainPKCE: false` so the library rejects/omits `plain`.
- **DCR abuse:** rate-limit in front of the library's `/oauth/register`; keep `disallowPublicClientRegistration: false` (claude.ai needs public-client DCR) but bound registrations per IP.
- **Redirect-URI allow-list:** the library validates redirect URIs against the registered client; our consent handler must not introduce an open redirect (exact-match on `redirect_uri` from `parseAuthRequest`).
- **Magic-link security:** single-use, short-TTL, constant-time token compare, bound to email; rate-limited.
- **Scope clamp:** consent handler must clamp to `read|write`; `admin` never reaches `completeAuthorization`.
- **Enrollment authenticity:** the enroll handshake must prove the admin controls the WP site (signed nonce round-trip to the plugin) so an attacker can't enroll someone else's site under their account.
- **Introspection-cache poisoning / TTL:** cache keyed by `hash(token)`, TTL bounded by `exp`, positive-only; never cache a negative as positive.

## 9. Relationship to PR #598
Cherry-pick the `.well-known/oauth-protected-resource` scaffolding and any reusable enrollment/settings-UI helpers; discard the per-site authorization server, token endpoint, DCR, and consent UI. Close #598 with a pointer to this spec. (Note: #598's JWT/JWKS ambitions are moot — the library's tokens are opaque and validated by introspection.)

## 10. Testing strategy
- **Worker:** unit tests for OUR pieces — magic-link issue/verify (single-use, TTL), enrollment handshake (nonce proof, per-site credential, duplicate-site 409), the `/authorize` consent handler (scope clamp to read|write, `resource`→owned-site check, redirect-URI exact match, PKCE present), and `/oauth/introspect` (valid token → `active` + correct `site_id`/scope; wrong `site_credential` → 401; cross-site introspection refused; revoked grant → `active:false`). The library's own DCR/token/refresh/PKCE paths are exercised via integration but not re-implemented/re-tested at unit level.
- **Plugin:** validator unit tests — valid introspection accepted + cached; `active:false`/wrong `aud`/`site_id` rejected; kill-switch rejects instantly with no network; cache respects TTL/`exp`; scope→capability mapping; `.well-known` served only when enrolled.
- **End-to-end:** a scripted DCR→authorize→token→MCP-call against a test site; disconnect → immediate rejection.
- (Only if admin-over-OAuth is later added per §7:) #683-style negative tests extended to cross-grant self-approval and client-credentials scope clamp. Not required for v1's read/write cap.

## 11. Out of scope
- Full Agency Console UI (#553).
- Gateway request-proxy model (rejected).
- Migrating `X-API-Key` users (untouched).

## 12. Open questions & resolved notes

**RESOLVED — token-validation mechanism (the crux).** `cloudflare/workers-oauth-provider` issues **opaque** access tokens (`userId:grantId:randomSecret`, hashed in KV, props AES-GCM-encrypted). There is **no JWT and no JWKS**, so the plugin cannot validate offline against a public key. Resolved contract: the WP plugin validates a broker token by calling the broker's **RFC 7662 introspection endpoint** (`POST /oauth/introspect`, a thin route wrapping `env.OAUTH_PROVIDER.unwrapToken(token)`), authenticated with the **per-site credential** minted at enrollment; the broker binds each grant to a `site_id`/`canonical_url` (via `props`) so a token minted for one site is refused by another. The plugin **caches a positive result** in a short-TTL transient (≤60s, bounded by `exp`) keyed by `hash(token)`, so the broker is hit at most once per token per window — never on every MCP call — and a local **kill-switch** (`mcpwp_broker_enrollment` off) rejects all broker tokens instantly with no network. MCP payloads still flow claude.ai → site directly; the broker is on the cached validation control-plane only, never the data path. This is the deliberate trade for offloading token machinery to the maintained library (vs. the earlier hand-rolled JWT/JWKS idea): introspection couples validation availability to the broker on cache-miss, mitigated by caching + kill-switch.

**OPEN (non-blocking) — outage behavior.** On cache-miss with the broker unreachable, fail-closed (reject) vs. bounded fail-open (accept a previously-seen token until its known `exp`). Recommend fail-closed default. To be pinned in the plugin plan (Plan 2).

**OPEN (non-blocking) — enrollment nonce round-trip** authenticity mechanism (§8) to be pinned in the worker plan.
