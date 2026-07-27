# mumcp.com OAuth Broker — Worker Implementation Plan (Plan 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Revised 2026-07-20:** this plan now **builds on `@cloudflare/workers-oauth-provider`** instead of hand-rolling the OAuth 2.1 server. The library is the Worker entrypoint and auto-implements RFC 8414 metadata, RFC 7591 DCR, `/oauth/token` (code exchange + refresh rotation), PKCE-S256, and opaque-token KV storage. We build only: accounts, enrollment, the consent UI, and the introspection endpoint. **Task count dropped 10 → 7.** (Old hand-rolled tasks — JWKS, AS-metadata, DCR, token/JWT, and most of revocation — are now library config.)

**Goal:** Build the mumcp.com Cloudflare Worker so claude.ai/ChatGPT can sign in and receive a token that MCPWP sites validate. The OAuth engine is the library; we supply the application layer (magic-link accounts, one-time site enrollment, consent UI) and the site↔broker token-validation contract (introspection).

**Architecture:** `export default new OAuthProvider({...})` wraps the existing landing worker. Non-API/non-OAuth requests (including `/`) fall through to our `defaultHandler`. Our application state (accounts, sites) lives in Workers KV; the library owns its own token/grant/client KV. Tokens are **opaque** (library-issued); sites validate them by calling our `/oauth/introspect` route (which wraps `env.OAUTH_PROVIDER.unwrapToken`). mumcp.com is auth-only — never in the MCP request data path.

**Tech Stack:** Cloudflare Workers, `@cloudflare/workers-oauth-provider`, Workers KV, Web Crypto (`crypto.subtle` — magic-link/session HMAC only), TypeScript, Vitest + `@cloudflare/vitest-pool-workers`, Wrangler.

## Global Constraints

- Runtime is Cloudflare Workers — Web APIs only. No `node:*` built-ins.
- **The library is the entrypoint:** `export default new OAuthProvider({ apiRoute?, defaultHandler, authorizeEndpoint, tokenEndpoint, clientRegistrationEndpoint, ... })`. Our routes live in `defaultHandler` (a plain `{ fetch }` object or `WorkerEntrypoint`). Do NOT hand-roll the token/DCR/metadata endpoints — configure them.
- **No JWT, no JWKS, no ES256 signing key.** The library issues opaque tokens (`userId:grantId:randomSecret`, KV-hashed, props encrypted). The only crypto WE do is HMAC for magic-link/session cookies (`SESSION_SECRET`).
- State: our KV (`KV_ACCOUNTS`, `KV_SITES`) + the library's KV binding. Bindings typed in an `Env` interface, passed explicitly.
- Secrets via `wrangler secret put` — `SESSION_SECRET` (and email-provider key at deploy). Never in `wrangler.toml`.
- Zod validates every external input we parse (magic-link email, enrollment body, introspection body). The library validates OAuth params itself.
- **`OAuthProvider` config (the contract Plan 2 depends on):**
  - `authorizeEndpoint: '/authorize'`, `tokenEndpoint: '/oauth/token'`, `clientRegistrationEndpoint: '/oauth/register'`.
  - `accessTokenTTL: 3600` (1h); `refreshTokenTTL: 2592000` (30d, rotated by the library).
  - `allowPlainPKCE: false` (S256 only, advertised in metadata).
  - `scopesSupported: ['read', 'write']` (**`admin` is never offered or issued over OAuth in v1**).
  - `disallowPublicClientRegistration: false` (claude.ai self-registers as a public client via DCR — no secret).
- **Grant/props contract (what our consent handler puts in every grant):** `userId: 'mumcp-user:<account_id>'`, `scope: ['read'] | ['write']` (clamped), `props: { site_id, canonical_url, account_id }`. `unwrapToken` returns these; introspection surfaces them.
- **Token-validation contract (introspection):** `POST /oauth/introspect` (RFC 7662), authenticated with the caller's per-site credential, returns `{ active, scope, sub, site_id, aud (=canonical_url), exp }`. A token is only introspectable by the site it is bound to.
- Landing page at `/` and `www` redirect preserved (served by `defaultHandler`).
- Keep the worker under 1MB compressed.

---

### Task 1: Worker scaffold — wire `OAuthProvider`, Env, KV, landing passthrough, endpoint config

**Files:**
- Modify: `mumcp-landing/wrangler.toml` (add the library's KV binding + `KV_ACCOUNTS`/`KV_SITES`; keep custom-domain routes)
- Modify: `mumcp-landing/src/index.js` → `mumcp-landing/src/index.ts` (`export default new OAuthProvider({...})`)
- Create: `mumcp-landing/src/env.ts` (Env interface incl. `OAUTH_PROVIDER: OAuthHelpers`)
- Create: `mumcp-landing/src/default-handler.ts` (the `{ fetch }` object; `/` serves existing landing HTML, `/healthz`)
- Create: `mumcp-landing/vitest.config.ts`, `mumcp-landing/test/health.test.ts`
- Modify: `mumcp-landing/package.json` (add `@cloudflare/workers-oauth-provider`, zod, vitest, `@cloudflare/vitest-pool-workers`, wrangler; `test`+`deploy` scripts)

**Interfaces:**
- Produces: the default-export `OAuthProvider` instance; `Env` with `OAUTH_PROVIDER` (OAuthHelpers), `KV_ACCOUNTS`, `KV_SITES` (KVNamespace), `SESSION_SECRET` (secret), `ISSUER` (var). Configured endpoints per Global Constraints.

- [ ] **Step 1: Write the failing test**
```ts
// test/health.test.ts
import { SELF } from 'cloudflare:test'
import { describe, it, expect } from 'vitest'
describe('worker', () => {
  it('serves the landing page at /', async () => {
    const res = await SELF.fetch('https://mumcp.com/')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('text/html')
  })
  it('serves RFC 8414 metadata from the library', async () => {
    const res = await SELF.fetch('https://mumcp.com/.well-known/oauth-authorization-server')
    expect(res.status).toBe(200)
    const m = await res.json() as any
    expect(m.registration_endpoint).toContain('/oauth/register')
    expect(m.token_endpoint).toContain('/oauth/token')
    expect(m.code_challenge_methods_supported).toEqual(['S256'])
  })
})
```
- [ ] **Step 2: Run** — `cd mumcp-landing && npm test` → FAIL.
- [ ] **Step 3: Implement** — `default-handler.ts` with `GET /` (existing landing HTML moved to a `landing.ts` string export) + `GET /healthz`. `index.ts`: `export default new OAuthProvider({ defaultHandler, authorizeEndpoint:'/authorize', tokenEndpoint:'/oauth/token', clientRegistrationEndpoint:'/oauth/register', accessTokenTTL:3600, refreshTokenTTL:2592000, allowPlainPKCE:false, scopesSupported:['read','write'] })`. Add the library's KV binding + `KV_ACCOUNTS`/`KV_SITES` to `wrangler.toml` (via `wrangler kv namespace create`). The metadata endpoint is served by the library automatically — assert it, don't implement it.
- [ ] **Step 4: Run** — `npm test` → PASS.
- [ ] **Step 5: Commit** — `feat(broker): wire OAuthProvider entrypoint, Env, KV, landing passthrough`

---

### Task 2: Magic-link account auth

**Files:**
- Create: `mumcp-landing/src/accounts.ts` (request link, verify, session cookie)
- Create: `mumcp-landing/test/accounts.test.ts`
- Modify: `mumcp-landing/src/default-handler.ts` (`POST /account/magic-link`, `GET /account/verify`)

**Interfaces:**
- Produces: `requireSession(request, env): Promise<{ account_id: string } | null>`; account records in `KV_ACCOUNTS` keyed `account:<id>` and `email:<email>`; magic tokens in `KV_ACCOUNTS` keyed `magic:<token>` TTL 600s single-use.

- [ ] **Step 1: Test** — (a) `POST /account/magic-link {email}` → 200, stores a single-use token (assert via KV); (b) `GET /account/verify?token=<t>` → 302 + `Set-Cookie` session, creates/loads the account; (c) reusing the same token → 400 (single-use); (d) unknown token → 400; (e) session cookie → `requireSession` resolves the account.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — token = base64url(32 random bytes) via `crypto.getRandomValues`; store `{email, exp}` TTL 600; verify deletes on use, upserts account, sets HttpOnly/Secure/SameSite=Lax session cookie signed with HMAC(`SESSION_SECRET`); `requireSession` verifies the cookie MAC. `sendMagicLink(env, email, url)` abstracts email delivery (log/queue for unit; real provider at deploy). Rate-limit `POST /account/magic-link` per email+IP (KV counter).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(broker): magic-link account auth`

---

### Task 3: Site enrollment handshake + per-site introspection credential

**Files:**
- Create: `mumcp-landing/src/enroll.ts`
- Create: `mumcp-landing/test/enroll.test.ts`
- Modify: `mumcp-landing/src/default-handler.ts` (`GET /enroll/start`, `POST /enroll/complete`)

**Interfaces:**
- Produces: site records in `KV_SITES` keyed `site:<site_id>` → `{ site_id, canonical_url, account_id, site_credential_hash, enrolled_at, active }` and `siteurl:<canonical_url>` → `site_id`; `getSite(env, site_id)`, `verifySiteCredential(env, site_id, cred)`.

- [ ] **Step 1: Test** — (a) `GET /enroll/start?site_url=…` while signed in → renders consent bound to the account; not-signed-in → 401; (b) `POST /enroll/complete` with a valid session + a proof the caller controls the site (nonce the plugin echoes from a plugin-side callback) → creates the site record, mints a per-site credential, returns the enrollment payload `{issuer, site_id, audience, introspection_endpoint, site_credential}` with `audience==canonical_url`; (c) enrolling a `canonical_url` already owned by another account → 409; (d) `verifySiteCredential` accepts the minted credential (constant-time) and rejects a wrong one.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — `enroll/start` requires `requireSession`, issues a one-time `enroll_nonce` (KV TTL 300), directs the browser to the plugin callback carrying the nonce; `enroll/complete` verifies the echoed nonce (site control proof), normalizes `canonical_url` (scheme+host, no trailing slash), mints `site_credential` = base64url(32 random bytes) and stores only its hash, writes the site record bound to `account_id`, returns the payload. Reject duplicate `siteurl:` owned by a different account.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(broker): site enrollment handshake + per-site introspection credential`

---

### Task 4: Authorize / consent handler (library helpers)

**Files:**
- Create: `mumcp-landing/src/authorize.ts`
- Create: `mumcp-landing/test/authorize.test.ts`
- Modify: `mumcp-landing/src/default-handler.ts` (`GET /authorize`, `POST /authorize`)

**Interfaces:**
- Consumes: `env.OAUTH_PROVIDER.parseAuthRequest`, `lookupClient`, `completeAuthorization`. Produces: a 302 to the client's `redirect_uri` with the library-issued `code`.

- [ ] **Step 1: Test** — (a) `GET /authorize` with a DCR-registered `client_id`, `resource=<site canonical_url>`, `code_challenge`+`S256`, `scope=read`, signed-in, site owned by the account → renders consent naming site + scope; unauthenticated → redirect to magic-link login then back; (b) `POST /authorize` (consent granted) → 302 to `redirect_uri?code=…&state=…` (the `redirectTo` from `completeAuthorization`), and the grant carries `userId='mumcp-user:<id>'`, `scope=['read']`, `props={site_id,canonical_url,account_id}`; (c) `scope=admin` → **clamped to reject** (never reaches `completeAuthorization` with admin); (d) requesting a `resource` the signed-in account did not enroll → 403; (e) `redirect_uri` mismatch surfaced by `parseAuthRequest`/`lookupClient` → 400, no redirect.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — `parseAuthRequest(request)` → `lookupClient(clientId)`; require `requireSession` (else bounce to magic-link with return-to); resolve `resource` → a site owned by the account (else 403); clamp `scope` to `read|write` (drop/deny `admin`); render consent (GET) then on POST call `completeAuthorization({ request: oauthReqInfo, userId:'mumcp-user:'+account_id, scope:[clampedScope], metadata:{site_id}, props:{site_id, canonical_url, account_id} })` and 302 to `redirectTo`. The library handles PKCE storage, code issuance, and redirect-URI validation against the registered client.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(broker): authorize + consent handler via OAuthProvider helpers`

---

### Task 5: Token-introspection endpoint (the validation contract)

**Files:**
- Create: `mumcp-landing/src/introspect.ts`
- Create: `mumcp-landing/test/introspect.test.ts`
- Modify: `mumcp-landing/src/default-handler.ts` (`POST /oauth/introspect`)

**Interfaces:**
- Consumes: `env.OAUTH_PROVIDER.unwrapToken(token)`, `verifySiteCredential`. Produces: RFC 7662 JSON `{ active, scope, sub, site_id, aud, exp }`.

- [ ] **Step 1: Test** — (a) `POST /oauth/introspect` with a valid `site_credential` header + a token whose grant `props.site_id` matches the caller → `{active:true, scope, sub, site_id, aud==canonical_url, exp}`; (b) missing/invalid `site_credential` → 401; (c) a token bound to a **different** site than the caller → `{active:false}` (cross-site introspection refused, no props leaked); (d) an unknown/garbage token (`unwrapToken` → null) → `{active:false}`; (e) after `revokeGrant`, the token → `{active:false}`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — authenticate the caller via `verifySiteCredential` (constant-time; identify the calling `site_id`); `const s = await env.OAUTH_PROVIDER.unwrapToken(token)`; if `!s` or `s.grant.props.site_id !== callerSiteId` → `{active:false}` (do not leak other-site props); else return `{active:true, scope:s.scope, sub:s.userId, site_id:s.grant.props.site_id, aud:s.grant.props.canonical_url, exp:s.expiresAt}`. Rate-limit per site.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(broker): RFC 7662 introspection endpoint (site↔broker token validation)`

---

### Task 6: Disconnect + revocation

**Files:**
- Create: `mumcp-landing/test/revocation.test.ts`
- Modify: `mumcp-landing/src/enroll.ts` (deactivate site), `mumcp-landing/src/default-handler.ts` (`POST /enroll/disconnect`)

**Interfaces:**
- Consumes: `env.OAUTH_PROVIDER.listUserGrants`, `revokeGrant`. Modifies: site `active=false`.

- [ ] **Step 1: Test** — (a) `POST /enroll/disconnect` (signed-in owner) → sets the site `active=false` and revokes the account's grants for that site (`listUserGrants`→`revokeGrant`); subsequent introspection of a token for that site → `{active:false}`; (b) disconnect by a non-owner → 403. (Note: the plugin's local kill-switch — Plan 2 — is what rejects instantly with no network; the server side ensures introspection returns `active:false` and the library stops refresh/token issuance for revoked grants. Document this boundary.)
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — `disconnect` requires owner session, flips `active`, enumerates the account's grants (`listUserGrants(userId)`), calls `revokeGrant(grantId, userId)` for those bound to the site. `getSite`/introspection already gate on `active`. Add a code comment: access-token *immediacy* is the plugin's responsibility (short introspection-cache TTL + enrollment kill-switch).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(broker): site disconnect + grant revocation`

---

### Task 7: Security hardening + adversarial-gate prep

**Files:**
- Create: `mumcp-landing/test/security.test.ts`
- Modify: relevant handlers per findings

- [ ] **Step 1: Tests (negative)** — our surfaces only (the library owns token crypto/PKCE/refresh): authorize open-redirect attempt (redirect_uri mutation) rejected via `lookupClient` exact-match; DCR flood → 429 (rate-limit in front of the library's `/oauth/register`); magic-link brute-force → 429 + constant-time token compare; introspection with wrong `site_credential` → 401 (constant-time); cross-site introspection never leaks props; enroll nonce single-use under concurrent replay; scope can never resolve to `admin` at `completeAuthorization`; `aud`/`site_id` in introspection come only from the grant's stored `props`, never a request param.
- [ ] **Step 2: Run** → FAIL where gaps exist.
- [ ] **Step 3: Implement** — close each gap; centralize constant-time compare for `site_credential`/magic-link/nonce; ensure all random tokens use `crypto.getRandomValues`; rate-limit DCR + introspection + magic-link.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `hardening(broker): negative-path security tests`
- [ ] **Step 6: Adversarial gate** — before deploy, run the external-facing-surface adversarial security review (per agent-comms rule) in parallel with correctness review. Do NOT deploy on a live-flip until both clear. Scope the review to OUR pieces (accounts, enrollment, consent, introspection) plus the `OAuthProvider` config (TTLs, `allowPlainPKCE:false`, scope clamp) — the library internals are reviewed upstream.

---

## Self-Review

- **Spec coverage:** OAuth engine = library (T1 config), enrollment + per-site credential (T3), discovery chain (T1 metadata/DCR/token via library + plugin `.well-known` in Plan 2), consent + scope clamp read/write (T4), **token-validation contract = introspection** (T5), revocation short-TTL + server kill (T6; local kill-switch is Plan 2), magic-link (T2), security gate (T7). Data-path exclusion is inherent (worker never proxies MCP; introspection is a cached control-plane call). Covered.
- **What the library owns (NOT re-implemented):** RFC 8414 metadata, RFC 7591 DCR, `/oauth/token` code-exchange + refresh rotation, PKCE-S256 enforcement, opaque-token KV storage + props encryption, `accessTokenTTL`/`refreshTokenTTL`. Verified against context7 docs (see revision report).
- **Placeholder scan:** email delivery in T2 abstracted behind `sendMagicLink` with a real provider wired at deploy — flagged, not a silent TODO. No other placeholders.
- **Type consistency:** `Env` (incl. `OAUTH_PROVIDER: OAuthHelpers`), the grant/props contract (`userId/scope/props{site_id,canonical_url,account_id}`), and the introspection response shape (`active/scope/sub/site_id/aud/exp`) are consistent across tasks and match the contract Plan 2 consumes.
- **Task count:** 7 (was 10). Removed hand-rolled JWKS, AS-metadata, DCR, and token/JWT tasks (now library config); added the introspection task (the honest consequence of opaque tokens).

## Deferred to Plan 2 (plugin)
`.well-known/oauth-protected-resource` pointer, the **cached introspection client** (validate against `/oauth/introspect` with the per-site credential, cache positive result ≤60s, check `aud`/`site_id`, map scope→capability), the enrollment client + Connections UI, the local kill-switch, the fail-closed-vs-fail-open outage decision (spec §12 open question), and the plugin version bump. **No JWKS validator** — tokens are opaque.
