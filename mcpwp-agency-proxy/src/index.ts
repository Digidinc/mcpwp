import { Hono } from 'hono';
import type { MiddlewareHandler } from 'hono';
import type { Env } from './types';
import { handleInitialize, handleToolsList, handleToolsCall } from './mcp';
import {
  validateToken,
  generateToken,
  storeToken,
  revokeToken,
  rotateToken,
} from './auth';
import { getSites, addSite, removeSite } from './registry';
import { encryptForAgency, decryptForAgency } from './crypto';
import { checkSsrfUrl, fetchNoRedirect } from './proxy';
import { DASHBOARD_HTML } from './dashboard';

type Variables = { agencyId: string };
type AppMiddleware = MiddlewareHandler<{ Bindings: Env; Variables: Variables }>;
const app = new Hono<{ Bindings: Env; Variables: Variables }>();

// T97: timing-safe string comparison via HMAC — prevents secret enumeration via timing
async function timingSafeEqual(a: string, b: string): Promise<boolean> {
  const enc = new TextEncoder();
  const keyData = enc.encode('mcpwp-proxy-compare-v1');
  const key = await crypto.subtle.importKey('raw', keyData, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const [aHash, bHash] = await Promise.all([
    crypto.subtle.sign('HMAC', key, enc.encode(a)),
    crypto.subtle.sign('HMAC', key, enc.encode(b)),
  ]);
  const aArr = new Uint8Array(aHash);
  const bArr = new Uint8Array(bHash);
  let diff = 0;
  for (let i = 0; i < aArr.length; i++) diff |= aArr[i] ^ bArr[i];
  return diff === 0;
}

// T94: enforce Content-Type + body size on POST routes
function assertJsonPost(c: Parameters<AppMiddleware>[0]): Response | null {
  const ct = c.req.header('content-type') ?? '';
  if (!ct.includes('application/json')) {
    return c.json({ error: 'Content-Type must be application/json' }, 415) as unknown as Response;
  }
  const len = Number(c.req.header('content-length') ?? 0);
  if (len > 1_048_576) {
    return c.json({ error: 'Payload too large' }, 413) as unknown as Response;
  }
  return null;
}

// T95: security headers on all responses
app.use('*', async (c, next) => {
  await next();
  c.header('X-Content-Type-Options', 'nosniff');
  c.header('X-Frame-Options', 'DENY');
  c.header('Referrer-Policy', 'no-referrer');
  if (c.req.path === '/dashboard') {
    c.header('Content-Security-Policy', "default-src 'self'; script-src 'none'; object-src 'none'");
  }
});

// Health check — no version leak
app.get('/', (c) => c.json({ status: 'ok' }));

// Agency dashboard — HTML UI, protected by Basic Auth (password = ADMIN_SECRET)
app.get('/dashboard', async (c) => {
  const auth = c.req.header('Authorization') ?? '';
  let authed = false;

  if (auth.startsWith('Basic ')) {
    try {
      const decoded = atob(auth.slice(6));
      const password = decoded.includes(':') ? decoded.slice(decoded.indexOf(':') + 1) : decoded;
      authed = await timingSafeEqual(password, c.env.ADMIN_SECRET); // T97
    } catch { /* ignore decode errors */ }
  }

  if (!authed) {
    return c.text('Unauthorized — use HTTP Basic Auth (any username, password = ADMIN_SECRET)', 401, {
      'WWW-Authenticate': 'Basic realm="MCPWP Agency Dashboard"',
    });
  }

  return c.html(DASHBOARD_HTML);
});

// Auth middleware factory
const requireAgencyToken: AppMiddleware = async (c, next) => {
  const auth = c.req.header('Authorization') ?? '';
  const token = auth.startsWith('Bearer ') ? auth.slice(7) : '';
  if (!token) {
    return c.json(
      { jsonrpc: '2.0', id: null, error: { code: -32000, message: 'Missing Authorization: Bearer <agency_token>' } },
      401
    );
  }
  const agencyId = await validateToken(token, c.env);
  if (!agencyId) {
    return c.json(
      { jsonrpc: '2.0', id: null, error: { code: -32000, message: 'Invalid agency token' } },
      401
    );
  }
  c.set('agencyId', agencyId);
  await next();
};

const requireApiToken: AppMiddleware = async (c, next) => {
  const auth = c.req.header('Authorization') ?? '';
  const token = auth.startsWith('Bearer ') ? auth.slice(7) : '';
  if (!token) return c.json({ error: 'Missing Authorization: Bearer <agency_token>' }, 401);
  const agencyId = await validateToken(token, c.env);
  if (!agencyId) return c.json({ error: 'Invalid token' }, 401);
  c.set('agencyId', agencyId);
  await next();
};

// MCP endpoint
app.post('/mcp', requireAgencyToken, async (c) => {
  // T96: rate limit per IP — blocks token brute-force
  const ip = c.req.header('cf-connecting-ip') ?? 'unknown';
  const { success: rateLimitOk } = await c.env.RATE_LIMITER_MCP.limit({ key: ip });
  if (!rateLimitOk) {
    return c.json({ jsonrpc: '2.0', id: null, error: { code: -32000, message: 'Rate limit exceeded' } }, 429);
  }

  // T94: Content-Type + body size enforcement
  const badRequest = assertJsonPost(c);
  if (badRequest) return badRequest;

  let body: { method?: string; id?: unknown; params?: unknown };
  try {
    body = await c.req.json();
  } catch {
    return c.json({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error: invalid JSON' } }, 400);
  }

  const { method, id, params } = body;
  const agencyId = c.get('agencyId');

  // #1074: the version the client negotiated, forwarded to the site (header on
  // every request after initialize; params.protocolVersion on initialize itself).
  const headerVersion = c.req.header('MCP-Protocol-Version');

  if (method === 'initialize') {
    const initParams = params && typeof params === 'object' ? (params as Record<string, unknown>) : {};
    const initSite = (typeof initParams._site === 'string' ? initParams._site : '') || c.req.header('X-Mcpwp-Site') || undefined;
    return c.json(await handleInitialize(id, agencyId, c.env, initSite, initParams.protocolVersion));
  }
  if (method === 'notifications/initialized') return c.json({ jsonrpc: '2.0', id, result: {} });
  if (method === 'ping') return c.json({ jsonrpc: '2.0', id, result: {} });

  if (method === 'tools/list') {
    // #542: pass _site hint from params or X-Mcpwp-Site header
    const paramSite =
      params && typeof params === 'object' && '_site' in params
        ? String((params as Record<string, unknown>)._site ?? '')
        : undefined;
    const headerSite = c.req.header('X-Mcpwp-Site') ?? undefined;
    const siteHint = paramSite || headerSite;
    return c.json(await handleToolsList(id, agencyId, c.env, siteHint, headerVersion));
  }

  if (method === 'tools/call') {
    if (!params || typeof params !== 'object') {
      return c.json({ jsonrpc: '2.0', id, error: { code: -32602, message: 'params required for tools/call' } }, 400);
    }
    return c.json(await handleToolsCall(id, params as { name: string; arguments: Record<string, unknown> }, agencyId, c.env, headerVersion));
  }

  return c.json({ jsonrpc: '2.0', id, error: { code: -32601, message: `Unknown method: ${method}` } });
});

// Account creation — returns one-time agency token
app.post('/api/accounts', async (c) => {
  // T96: strict rate limit — account creation is admin-only, 10 req/min max
  const ip = c.req.header('cf-connecting-ip') ?? 'unknown';
  const { success: rateLimitOk } = await c.env.RATE_LIMITER_ADMIN.limit({ key: ip });
  if (!rateLimitOk) {
    return c.json({ error: 'Rate limit exceeded' }, 429);
  }

  // T94: Content-Type + body size
  const badRequest = assertJsonPost(c);
  if (badRequest) return badRequest;

  const adminSecret = c.req.header('X-Admin-Secret') ?? '';
  if (!adminSecret || !(await timingSafeEqual(adminSecret, c.env.ADMIN_SECRET))) { // T97
    return c.json({ error: 'Forbidden: X-Admin-Secret required' }, 403);
  }

  let name = 'My Agency';
  try {
    const body = await c.req.json();
    if (typeof body.name === 'string') name = body.name;
  } catch { /* name stays default */ }

  const token = generateToken();
  const agencyId = crypto.randomUUID();

  // #543: storeToken writes HMAC KV entry + records token_hash on account
  await storeToken(token, agencyId, name, c.env);

  return c.json(
    { agency_id: agencyId, token, warning: 'Store this token securely — it cannot be retrieved again.' },
    201
  );
});

// #543: Revoke token — admin-gated, deletes the token KV entry
app.delete('/api/accounts/:id/token', async (c) => {
  const adminSecret = c.req.header('X-Admin-Secret') ?? '';
  if (!adminSecret || !(await timingSafeEqual(adminSecret, c.env.ADMIN_SECRET))) {
    return c.json({ error: 'Forbidden: X-Admin-Secret required' }, 403);
  }

  const agencyId = c.req.param('id');
  const revoked = await revokeToken(agencyId, c.env);
  if (!revoked) {
    return c.json({ error: 'Agency not found or no active token' }, 404);
  }
  return c.json({ status: 'revoked', agency_id: agencyId });
});

// #543: Rotate token — admin-gated, issues new token and invalidates old
app.post('/api/accounts/:id/token/rotate', async (c) => {
  const adminSecret = c.req.header('X-Admin-Secret') ?? '';
  if (!adminSecret || !(await timingSafeEqual(adminSecret, c.env.ADMIN_SECRET))) {
    return c.json({ error: 'Forbidden: X-Admin-Secret required' }, 403);
  }

  const agencyId = c.req.param('id');
  const newToken = await rotateToken(agencyId, c.env);
  if (!newToken) {
    return c.json({ error: 'Agency not found' }, 404);
  }
  return c.json(
    { agency_id: agencyId, token: newToken, warning: 'Store this token securely — it cannot be retrieved again.' },
    201
  );
});

// List sites
app.get('/api/sites', requireApiToken, async (c) => {
  const sites = await getSites(c.get('agencyId'), c.env);
  return c.json(sites.map((s) => ({ site_id: s.site_id, url: s.url, label: s.label, added_at: s.added_at })));
});

// Health check for all registered sites — probes each site's MCP initialize endpoint
app.get('/api/sites/health', requireApiToken, async (c) => {
  const agencyId = c.get('agencyId');
  const sites = await getSites(agencyId, c.env);

  const checks = await Promise.all(
    sites.map(async (site) => {
      try {
        const { plaintext: apiKey } = await decryptForAgency(
          site.api_key_enc,
          c.env.ENCRYPTION_KEY,
          agencyId
        );
        const mcpUrl = site.url.replace(/\/$/, '') + '/wp-json/mcpwp/v1/mcp';
        // Blocker A: refuse redirects — a legitimate WP endpoint never redirects here.
        const resp = await fetchNoRedirect(mcpUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
          body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'mcpwp-proxy-health', version: '1.0.0' },
          }}),
          signal: AbortSignal.timeout(8000),
        });
        return { site_id: site.site_id, url: site.url, label: site.label, online: resp.ok };
      } catch {
        return { site_id: site.site_id, url: site.url, label: site.label, online: false };
      }
    })
  );

  return c.json(checks);
});

// Add site
app.post('/api/sites', requireApiToken, async (c) => {
  const badRequest = assertJsonPost(c); // T94
  if (badRequest) return badRequest;

  let body: { url?: string; api_key?: string; label?: string; update?: boolean };
  try {
    body = await c.req.json();
  } catch {
    return c.json({ error: 'Invalid JSON body' }, 400);
  }

  const { url, api_key, label, update } = body;
  if (!url || !api_key) return c.json({ error: 'url and api_key are required' }, 400);

  // #545: SSRF guard at registration time
  const ssrfErr = checkSsrfUrl(url);
  if (ssrfErr) return c.json({ error: ssrfErr }, 400);

  // Parse URL for display label derivation (protocol already validated by checkSsrfUrl)
  const parsedUrl = new URL(url);
  const displayLabel = label ?? parsedUrl.hostname.replace(/\./g, '-');

  // #544: always generate UUID site_id server-side — slug is display-only
  const site_id = crypto.randomUUID();

  const agencyId = c.get('agencyId');

  // #546: encrypt site key under per-agency HKDF-derived key
  const api_key_enc = await encryptForAgency(api_key, c.env.ENCRYPTION_KEY, agencyId);

  const result = await addSite(
    agencyId,
    {
      site_id,
      url,
      api_key_enc,
      label: displayLabel,
      added_at: new Date().toISOString(),
    },
    c.env,
    { update: update === true }
  );

  if (!result.ok) {
    // #544: conflict on explicit site_id collision — should not happen with UUID generation
    // but guard the contract nonetheless
    return c.json({ error: `site_id '${site_id}' already exists. Pass update:true to overwrite.` }, 409);
  }

  return c.json({ site_id, label: displayLabel, status: 'registered' }, 201);
});

// Remove site
app.delete('/api/sites/:siteId', requireApiToken, async (c) => {
  const removed = await removeSite(c.get('agencyId'), c.req.param('siteId'), c.env);
  return removed ? c.json({ status: 'removed' }) : c.json({ error: 'Site not found' }, 404);
});

export default { fetch: app.fetch };
