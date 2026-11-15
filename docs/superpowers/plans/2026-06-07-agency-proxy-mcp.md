# Agency Proxy MCP Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Cloudflare Worker at `proxy.mcpwp.net` that lets an agency operator connect Claude once and control any of their registered WordPress sites (each running MCPWP).

**Architecture:** New Cloudflare Worker (`spai-proxy-worker/`) using Hono for routing. Site registry stored in KV — agency token → list of `{site_id, url, encrypted_api_key}`. MCP endpoint (`POST /mcp`) handles `initialize`, `tools/list` (proxy-native tools + forwarded tools with injected `_site` param), and `tools/call` (routes to correct upstream site). REST endpoints (`/api/accounts`, `/api/sites`) allow agencies to register sites.

**Tech Stack:** Hono 4.x, TypeScript, Cloudflare Workers, KV, Web Crypto API (AES-GCM), Vitest

---

## File Structure

```
spai-proxy-worker/
├── wrangler.toml           # CF config: KV binding, route, account_id
├── package.json
├── tsconfig.json
├── vitest.config.ts
├── src/
│   ├── types.ts            # Env interface, SiteEntry, AgencyAccount
│   ├── crypto.ts           # AES-GCM encrypt/decrypt (Web Crypto only)
│   ├── auth.ts             # Token generation, SHA-256 hashing, KV lookup
│   ├── registry.ts         # Site CRUD on KV
│   ├── proxy.ts            # Fetch upstream tools/list + forward tools/call
│   ├── mcp.ts              # MCP handlers: initialize, tools/list, tools/call
│   └── index.ts            # Hono app, route registration
└── test/
    ├── crypto.test.ts
    ├── auth.test.ts
    ├── registry.test.ts
    ├── proxy.test.ts
    └── mcp.test.ts
```

**KV key schema:**
```
agency:token:{sha256_hex}      → agency_id (string)
agency:account:{agency_id}     → JSON AgencyAccount
agency:sites:{agency_id}       → JSON SiteEntry[]
```

---

## Task 1: Scaffold project

**Files:**
- Create: `spai-proxy-worker/wrangler.toml`
- Create: `spai-proxy-worker/package.json`
- Create: `spai-proxy-worker/tsconfig.json`
- Create: `spai-proxy-worker/vitest.config.ts`
- Create: `spai-proxy-worker/src/types.ts`
- Create: `spai-proxy-worker/src/index.ts` (skeleton)

- [ ] **Step 1: Create directory and wrangler.toml**

```bash
mkdir -p spai-proxy-worker/src spai-proxy-worker/test
```

`spai-proxy-worker/wrangler.toml`:
```toml
name = "mcpwp-agency-proxy"
main = "src/index.ts"
compatibility_date = "2024-11-05"
account_id = "<CLOUDFLARE_ACCOUNT_ID>"

routes = [
  { pattern = "proxy.mcpwp.net/*", zone_name = "mcpwp.net" }
]

[[kv_namespaces]]
binding = "AGENCY_KV"
id = "FILL_AFTER_wrangler_kv_create"
preview_id = "FILL_AFTER_wrangler_kv_create"

# Secrets (set via wrangler secret put):
# ENCRYPTION_KEY — 64-char hex (32 bytes)
```

- [ ] **Step 2: Create package.json**

`spai-proxy-worker/package.json`:
```json
{
  "name": "mcpwp-agency-proxy",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "dev": "wrangler dev",
    "deploy": "wrangler deploy",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "dependencies": {
    "hono": "^4.6.0"
  },
  "devDependencies": {
    "@cloudflare/workers-types": "^4.20241127.0",
    "typescript": "^5.6.0",
    "vitest": "^2.1.0",
    "wrangler": "^3.91.0"
  }
}
```

- [ ] **Step 3: Create tsconfig.json**

`spai-proxy-worker/tsconfig.json`:
```json
{
  "compilerOptions": {
    "target": "ES2022",
    "lib": ["ES2022"],
    "module": "ES2022",
    "moduleResolution": "bundler",
    "strict": true,
    "noEmit": true,
    "types": ["@cloudflare/workers-types"]
  },
  "include": ["src/**/*", "test/**/*"]
}
```

- [ ] **Step 4: Create vitest.config.ts**

`spai-proxy-worker/vitest.config.ts`:
```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
  },
});
```

- [ ] **Step 5: Create src/types.ts**

`spai-proxy-worker/src/types.ts`:
```typescript
export interface Env {
  AGENCY_KV: KVNamespace;
  ENCRYPTION_KEY: string; // 64-char hex = 32-byte AES-GCM key
}

export interface SiteEntry {
  site_id: string;       // slug, e.g. "client-a"
  url: string;           // e.g. "https://client-a.com"
  api_key_enc: string;   // AES-GCM encrypted, base64
  label: string;         // human-readable name
  added_at: string;      // ISO timestamp
}

export interface AgencyAccount {
  id: string;
  name: string;
  created_at: string;
}
```

- [ ] **Step 6: Create src/index.ts skeleton**

`spai-proxy-worker/src/index.ts`:
```typescript
import { Hono } from 'hono';
import type { Env } from './types';

const app = new Hono<{ Bindings: Env }>();

app.get('/', (c) => c.json({ service: 'mcpwp-agency-proxy', version: '1.0.0' }));

export default { fetch: app.fetch };
```

- [ ] **Step 7: Install dependencies**

```bash
cd spai-proxy-worker && npm install
```

Expected: `node_modules/` created, no errors.

- [ ] **Step 8: Verify TypeScript compiles**

```bash
cd spai-proxy-worker && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 9: Commit scaffold**

```bash
git add spai-proxy-worker/
git commit -m "feat: scaffold mcpwp-agency-proxy worker"
```

---

## Task 2: Encryption utility (crypto.ts)

**Files:**
- Create: `spai-proxy-worker/src/crypto.ts`
- Create: `spai-proxy-worker/test/crypto.test.ts`

- [ ] **Step 1: Write failing test**

`spai-proxy-worker/test/crypto.test.ts`:
```typescript
import { describe, it, expect } from 'vitest';
import { encrypt, decrypt } from '../src/crypto';

const TEST_KEY = 'a'.repeat(64); // 32 bytes as hex

describe('crypto', () => {
  it('encrypt then decrypt returns original', async () => {
    const original = 'spai_abc123secretkey';
    const ciphertext = await encrypt(original, TEST_KEY);
    expect(ciphertext).not.toBe(original);
    const decrypted = await decrypt(ciphertext, TEST_KEY);
    expect(decrypted).toBe(original);
  });

  it('each encrypt call produces different ciphertext (random IV)', async () => {
    const c1 = await encrypt('hello', TEST_KEY);
    const c2 = await encrypt('hello', TEST_KEY);
    expect(c1).not.toBe(c2);
  });

  it('decrypt with wrong key throws', async () => {
    const c = await encrypt('hello', TEST_KEY);
    const wrongKey = 'b'.repeat(64);
    await expect(decrypt(c, wrongKey)).rejects.toThrow();
  });
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd spai-proxy-worker && npx vitest run test/crypto.test.ts
```

Expected: FAIL — `Cannot find module '../src/crypto'`

- [ ] **Step 3: Implement src/crypto.ts**

`spai-proxy-worker/src/crypto.ts`:
```typescript
function hexToBytes(hex: string): Uint8Array {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
  }
  return bytes;
}

async function importKey(keyHex: string, usage: KeyUsage[]): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', hexToBytes(keyHex), { name: 'AES-GCM' }, false, usage);
}

export async function encrypt(plaintext: string, keyHex: string): Promise<string> {
  const key = await importKey(keyHex, ['encrypt']);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const encoded = new TextEncoder().encode(plaintext);
  const enc = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, encoded);
  const combined = new Uint8Array(iv.byteLength + enc.byteLength);
  combined.set(iv, 0);
  combined.set(new Uint8Array(enc), 12);
  return btoa(String.fromCharCode(...combined));
}

export async function decrypt(ciphertext: string, keyHex: string): Promise<string> {
  const combined = Uint8Array.from(atob(ciphertext), (c) => c.charCodeAt(0));
  const iv = combined.slice(0, 12);
  const data = combined.slice(12);
  const key = await importKey(keyHex, ['decrypt']);
  const dec = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, data);
  return new TextDecoder().decode(dec);
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd spai-proxy-worker && npx vitest run test/crypto.test.ts
```

Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add spai-proxy-worker/src/crypto.ts spai-proxy-worker/test/crypto.test.ts
git commit -m "feat: AES-GCM encrypt/decrypt for API key storage"
```

---

## Task 3: Auth (token generation and validation)

**Files:**
- Create: `spai-proxy-worker/src/auth.ts`
- Create: `spai-proxy-worker/test/auth.test.ts`

Requires a KV mock helper. We create it inline in the test.

- [ ] **Step 1: Write failing test**

`spai-proxy-worker/test/auth.test.ts`:
```typescript
import { describe, it, expect } from 'vitest';
import { generateToken, hashToken, validateToken } from '../src/auth';
import type { Env } from '../src/types';

function mockKV(data: Record<string, string> = {}): KVNamespace {
  const store = { ...data };
  return {
    get: async (key: string) => store[key] ?? null,
    put: async (key: string, value: string) => { store[key] = value; },
    delete: async (key: string) => { delete store[key]; },
    list: async () => ({ keys: [], list_complete: true, cursor: '' }),
    getWithMetadata: async (key: string) => ({ value: store[key] ?? null, metadata: null }),
  } as unknown as KVNamespace;
}

describe('auth', () => {
  it('generateToken starts with mcpwp_agency_', () => {
    const token = generateToken();
    expect(token.startsWith('mcpwp_agency_')).toBe(true);
    expect(token.length).toBeGreaterThan(20);
  });

  it('hashToken is deterministic', async () => {
    const h1 = await hashToken('abc');
    const h2 = await hashToken('abc');
    expect(h1).toBe(h2);
    expect(h1).toMatch(/^[0-9a-f]{64}$/);
  });

  it('validateToken returns agencyId for known token', async () => {
    const token = 'mcpwp_agency_testtoken';
    const hash = await hashToken(token);
    const kv = mockKV({ [`agency:token:${hash}`]: 'agency-123' });
    const env = { AGENCY_KV: kv } as unknown as Env;
    const result = await validateToken(token, env);
    expect(result).toBe('agency-123');
  });

  it('validateToken returns null for unknown token', async () => {
    const kv = mockKV({});
    const env = { AGENCY_KV: kv } as unknown as Env;
    const result = await validateToken('mcpwp_agency_unknown', env);
    expect(result).toBeNull();
  });
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd spai-proxy-worker && npx vitest run test/auth.test.ts
```

Expected: FAIL — `Cannot find module '../src/auth'`

- [ ] **Step 3: Implement src/auth.ts**

`spai-proxy-worker/src/auth.ts`:
```typescript
import type { Env } from './types';

export function generateToken(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(32));
  const b64 = btoa(String.fromCharCode(...bytes))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=/g, '');
  return `mcpwp_agency_${b64}`;
}

export async function hashToken(token: string): Promise<string> {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(token));
  return Array.from(new Uint8Array(buf))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

export async function validateToken(token: string, env: Env): Promise<string | null> {
  const hash = await hashToken(token);
  return env.AGENCY_KV.get(`agency:token:${hash}`);
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd spai-proxy-worker && npx vitest run test/auth.test.ts
```

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add spai-proxy-worker/src/auth.ts spai-proxy-worker/test/auth.test.ts
git commit -m "feat: agency token generation and KV-backed validation"
```

---

## Task 4: Site registry (KV CRUD)

**Files:**
- Create: `spai-proxy-worker/src/registry.ts`
- Create: `spai-proxy-worker/test/registry.test.ts`

- [ ] **Step 1: Write failing test**

`spai-proxy-worker/test/registry.test.ts`:
```typescript
import { describe, it, expect } from 'vitest';
import { getSites, addSite, removeSite } from '../src/registry';
import type { Env, SiteEntry } from '../src/types';

function mockKV(data: Record<string, string> = {}): KVNamespace {
  const store: Record<string, string> = { ...data };
  return {
    get: async (key: string) => store[key] ?? null,
    put: async (key: string, value: string) => { store[key] = value; },
    delete: async (key: string) => { delete store[key]; },
    list: async () => ({ keys: [], list_complete: true, cursor: '' }),
    getWithMetadata: async (key: string) => ({ value: store[key] ?? null, metadata: null }),
  } as unknown as KVNamespace;
}

function makeEnv(kv: KVNamespace): Env {
  return { AGENCY_KV: kv, ENCRYPTION_KEY: 'a'.repeat(64) } as unknown as Env;
}

const siteA: SiteEntry = {
  site_id: 'client-a',
  url: 'https://client-a.com',
  api_key_enc: 'encrypted_key_a',
  label: 'Client A',
  added_at: '2026-06-07T00:00:00Z',
};

describe('registry', () => {
  it('getSites returns empty array when no sites registered', async () => {
    const env = makeEnv(mockKV());
    expect(await getSites('agency-1', env)).toEqual([]);
  });

  it('addSite stores the site and getSites returns it', async () => {
    const env = makeEnv(mockKV());
    await addSite('agency-1', siteA, env);
    const sites = await getSites('agency-1', env);
    expect(sites).toHaveLength(1);
    expect(sites[0].site_id).toBe('client-a');
  });

  it('addSite with same site_id updates in-place', async () => {
    const env = makeEnv(mockKV());
    await addSite('agency-1', siteA, env);
    await addSite('agency-1', { ...siteA, label: 'Updated A' }, env);
    const sites = await getSites('agency-1', env);
    expect(sites).toHaveLength(1);
    expect(sites[0].label).toBe('Updated A');
  });

  it('removeSite removes the site and returns true', async () => {
    const env = makeEnv(mockKV());
    await addSite('agency-1', siteA, env);
    const removed = await removeSite('agency-1', 'client-a', env);
    expect(removed).toBe(true);
    expect(await getSites('agency-1', env)).toHaveLength(0);
  });

  it('removeSite returns false when site not found', async () => {
    const env = makeEnv(mockKV());
    const removed = await removeSite('agency-1', 'nonexistent', env);
    expect(removed).toBe(false);
  });
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd spai-proxy-worker && npx vitest run test/registry.test.ts
```

Expected: FAIL — `Cannot find module '../src/registry'`

- [ ] **Step 3: Implement src/registry.ts**

`spai-proxy-worker/src/registry.ts`:
```typescript
import type { Env, SiteEntry } from './types';

export async function getSites(agencyId: string, env: Env): Promise<SiteEntry[]> {
  const raw = await env.AGENCY_KV.get(`agency:sites:${agencyId}`);
  return raw ? (JSON.parse(raw) as SiteEntry[]) : [];
}

export async function addSite(agencyId: string, entry: SiteEntry, env: Env): Promise<void> {
  const sites = await getSites(agencyId, env);
  const idx = sites.findIndex((s) => s.site_id === entry.site_id);
  if (idx >= 0) {
    sites[idx] = entry;
  } else {
    sites.push(entry);
  }
  await env.AGENCY_KV.put(`agency:sites:${agencyId}`, JSON.stringify(sites));
}

export async function removeSite(agencyId: string, siteId: string, env: Env): Promise<boolean> {
  const sites = await getSites(agencyId, env);
  const filtered = sites.filter((s) => s.site_id !== siteId);
  if (filtered.length === sites.length) return false;
  await env.AGENCY_KV.put(`agency:sites:${agencyId}`, JSON.stringify(filtered));
  return true;
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd spai-proxy-worker && npx vitest run test/registry.test.ts
```

Expected: 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add spai-proxy-worker/src/registry.ts spai-proxy-worker/test/registry.test.ts
git commit -m "feat: site registry CRUD backed by KV"
```

---

## Task 5: Upstream proxy (fetch tools/list + forward calls)

**Files:**
- Create: `spai-proxy-worker/src/proxy.ts`
- Create: `spai-proxy-worker/test/proxy.test.ts`

- [ ] **Step 1: Write failing test**

`spai-proxy-worker/test/proxy.test.ts`:
```typescript
import { describe, it, expect, vi, afterEach } from 'vitest';
import { fetchToolsList, forwardToolCall, siteUrl } from '../src/proxy';
import type { SiteEntry } from '../src/types';

const TEST_KEY = 'a'.repeat(64);

const site: SiteEntry = {
  site_id: 'test-site',
  url: 'https://test-site.com',
  api_key_enc: '', // set in beforeEach via encrypt
  label: 'Test',
  added_at: '2026-06-07T00:00:00Z',
};

afterEach(() => vi.restoreAllMocks());

describe('siteUrl', () => {
  it('appends WP REST MCP path', () => {
    expect(siteUrl('https://example.com')).toBe('https://example.com/wp-json/site-pilot-ai/v1/mcp');
    expect(siteUrl('https://example.com/')).toBe('https://example.com/wp-json/site-pilot-ai/v1/mcp');
  });
});

describe('fetchToolsList', () => {
  it('returns tools array from upstream', async () => {
    const { encrypt } = await import('../src/crypto');
    const encKey = await encrypt('spai_test_api_key', TEST_KEY);
    const testSite = { ...site, api_key_enc: encKey };

    const mockTools = [{ name: 'wp_list_pages', description: 'List pages', inputSchema: { type: 'object', properties: {}, required: [] } }];
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: mockTools } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );

    const tools = await fetchToolsList(testSite, TEST_KEY);
    expect(tools).toHaveLength(1);
    expect(tools[0].name).toBe('wp_list_pages');
  });

  it('throws on upstream non-200', async () => {
    const { encrypt } = await import('../src/crypto');
    const encKey = await encrypt('spai_key', TEST_KEY);
    const testSite = { ...site, api_key_enc: encKey };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response('', { status: 500 }));
    await expect(fetchToolsList(testSite, TEST_KEY)).rejects.toThrow('Upstream');
  });
});

describe('forwardToolCall', () => {
  it('returns upstream result verbatim', async () => {
    const { encrypt } = await import('../src/crypto');
    const encKey = await encrypt('spai_key', TEST_KEY);
    const testSite = { ...site, api_key_enc: encKey };
    const upstreamResult = { jsonrpc: '2.0', id: 42, result: { content: [{ type: 'text', text: '[]' }] } };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify(upstreamResult), { status: 200, headers: { 'Content-Type': 'application/json' } })
    );

    const result = await forwardToolCall(testSite, 'wp_list_pages', { status: 'draft' }, TEST_KEY, 42);
    expect(result).toEqual(upstreamResult);
  });

  it('returns JSON-RPC error on upstream non-200', async () => {
    const { encrypt } = await import('../src/crypto');
    const encKey = await encrypt('spai_key', TEST_KEY);
    const testSite = { ...site, api_key_enc: encKey };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response('', { status: 503 }));

    const result = await forwardToolCall(testSite, 'wp_list_pages', {}, TEST_KEY, 1) as any;
    expect(result.error).toBeDefined();
    expect(result.error.code).toBe(-32000);
  });
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd spai-proxy-worker && npx vitest run test/proxy.test.ts
```

Expected: FAIL — `Cannot find module '../src/proxy'`

- [ ] **Step 3: Implement src/proxy.ts**

`spai-proxy-worker/src/proxy.ts`:
```typescript
import { decrypt } from './crypto';
import type { SiteEntry } from './types';

export function siteUrl(baseUrl: string): string {
  return baseUrl.replace(/\/$/, '') + '/wp-json/site-pilot-ai/v1/mcp';
}

export async function fetchToolsList(site: SiteEntry, encKey: string): Promise<unknown[]> {
  const apiKey = await decrypt(site.api_key_enc, encKey);
  const resp = await fetch(siteUrl(site.url), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }),
  });
  if (!resp.ok) {
    throw new Error(`Upstream ${site.url} returned ${resp.status}`);
  }
  const data = (await resp.json()) as { result?: { tools?: unknown[] } };
  return data.result?.tools ?? [];
}

export async function forwardToolCall(
  site: SiteEntry,
  toolName: string,
  args: Record<string, unknown>,
  encKey: string,
  reqId: unknown
): Promise<unknown> {
  const apiKey = await decrypt(site.api_key_enc, encKey);
  const resp = await fetch(siteUrl(site.url), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
    body: JSON.stringify({
      jsonrpc: '2.0',
      id: reqId,
      method: 'tools/call',
      params: { name: toolName, arguments: args },
    }),
  });
  if (!resp.ok) {
    return {
      jsonrpc: '2.0',
      id: reqId,
      error: { code: -32000, message: `Upstream error: ${resp.status} from ${site.url}` },
    };
  }
  return resp.json();
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd spai-proxy-worker && npx vitest run test/proxy.test.ts
```

Expected: 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add spai-proxy-worker/src/proxy.ts spai-proxy-worker/test/proxy.test.ts
git commit -m "feat: upstream proxy — fetch tools/list and forward tools/call"
```

---

## Task 6: MCP handlers (initialize, tools/list, tools/call)

**Files:**
- Create: `spai-proxy-worker/src/mcp.ts`
- Create: `spai-proxy-worker/test/mcp.test.ts`

- [ ] **Step 1: Write failing test**

`spai-proxy-worker/test/mcp.test.ts`:
```typescript
import { describe, it, expect, vi, afterEach } from 'vitest';
import { handleInitialize, handleToolsList, handleToolsCall } from '../src/mcp';
import type { Env, SiteEntry } from '../src/types';

afterEach(() => vi.restoreAllMocks());

const TEST_KEY = 'a'.repeat(64);

function mockKV(data: Record<string, string> = {}): KVNamespace {
  const store: Record<string, string> = { ...data };
  return {
    get: async (key: string) => store[key] ?? null,
    put: async (key: string, value: string) => { store[key] = value; },
    delete: async (key: string) => { delete store[key]; },
    list: async () => ({ keys: [], list_complete: true, cursor: '' }),
    getWithMetadata: async (key: string) => ({ value: store[key] ?? null, metadata: null }),
  } as unknown as KVNamespace;
}

async function makeEnvWithSite(): Promise<{ env: Env; site: SiteEntry }> {
  const { encrypt } = await import('../src/crypto');
  const api_key_enc = await encrypt('spai_real_key', TEST_KEY);
  const site: SiteEntry = {
    site_id: 'client-a',
    url: 'https://client-a.com',
    api_key_enc,
    label: 'Client A',
    added_at: '2026-06-07T00:00:00Z',
  };
  const kv = mockKV({
    'agency:sites:agency-1': JSON.stringify([site]),
  });
  const env = { AGENCY_KV: kv, ENCRYPTION_KEY: TEST_KEY } as unknown as Env;
  return { env, site };
}

describe('handleInitialize', () => {
  it('returns MCP capabilities', () => {
    const result = handleInitialize(1) as any;
    expect(result.result.protocolVersion).toBe('2024-11-05');
    expect(result.result.serverInfo.name).toBe('mcpwp-agency-proxy');
  });
});

describe('handleToolsList', () => {
  it('returns proxy-native tools when no sites registered', async () => {
    const kv = mockKV({});
    const env = { AGENCY_KV: kv, ENCRYPTION_KEY: TEST_KEY } as unknown as Env;
    const result = await handleToolsList(1, 'agency-1', env) as any;
    const toolNames = result.result.tools.map((t: any) => t.name);
    expect(toolNames).toContain('proxy_list_sites');
    expect(toolNames).toContain('proxy_site_health');
  });

  it('injects _site param with site enum into forwarded tools', async () => {
    const { env } = await makeEnvWithSite();
    const mockTools = [{ name: 'wp_list_pages', description: 'List pages', inputSchema: { type: 'object', properties: {}, required: [] } }];
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: mockTools } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );
    const result = await handleToolsList(1, 'agency-1', env) as any;
    const wpTool = result.result.tools.find((t: any) => t.name === 'wp_list_pages');
    expect(wpTool).toBeDefined();
    expect(wpTool.inputSchema.properties._site).toBeDefined();
    expect(wpTool.inputSchema.required).toContain('_site');
    expect(wpTool.inputSchema.properties._site.enum).toEqual(['client-a']);
  });
});

describe('handleToolsCall — proxy-native', () => {
  it('proxy_list_sites returns registered sites', async () => {
    const { env } = await makeEnvWithSite();
    const result = await handleToolsCall(1, { name: 'proxy_list_sites', arguments: {} }, 'agency-1', env) as any;
    const text = result.result.content[0].text;
    const sites = JSON.parse(text);
    expect(sites[0].site_id).toBe('client-a');
  });
});

describe('handleToolsCall — forwarding', () => {
  it('missing _site returns JSON-RPC error', async () => {
    const { env } = await makeEnvWithSite();
    const result = await handleToolsCall(1, { name: 'wp_list_pages', arguments: {} }, 'agency-1', env) as any;
    expect(result.error.code).toBe(-32602);
    expect(result.error.message).toMatch(/_site/);
  });

  it('unknown _site returns JSON-RPC error', async () => {
    const { env } = await makeEnvWithSite();
    const result = await handleToolsCall(1, { name: 'wp_list_pages', arguments: { _site: 'unknown' } }, 'agency-1', env) as any;
    expect(result.error.code).toBe(-32602);
    expect(result.error.message).toMatch(/unknown/i);
  });

  it('forwards call to correct upstream site', async () => {
    const { env } = await makeEnvWithSite();
    const upstream = { jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: '[]' }] } };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify(upstream), { status: 200, headers: { 'Content-Type': 'application/json' } })
    );
    const result = await handleToolsCall(1, { name: 'wp_list_pages', arguments: { _site: 'client-a', status: 'draft' } }, 'agency-1', env);
    expect(result).toEqual(upstream);
  });
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd spai-proxy-worker && npx vitest run test/mcp.test.ts
```

Expected: FAIL — `Cannot find module '../src/mcp'`

- [ ] **Step 3: Implement src/mcp.ts**

`spai-proxy-worker/src/mcp.ts`:
```typescript
import { getSites } from './registry';
import { decrypt } from './crypto';
import { fetchToolsList, forwardToolCall, siteUrl } from './proxy';
import type { Env } from './types';

const PROXY_TOOLS = [
  {
    name: 'proxy_list_sites',
    description: 'List all WordPress sites registered to this agency account.',
    inputSchema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'proxy_site_health',
    description: 'Check connectivity to all registered sites. Returns status for each.',
    inputSchema: { type: 'object', properties: {}, required: [] },
  },
];

export function handleInitialize(id: unknown): unknown {
  return {
    jsonrpc: '2.0',
    id,
    result: {
      protocolVersion: '2024-11-05',
      capabilities: { tools: {} },
      serverInfo: { name: 'mcpwp-agency-proxy', version: '1.0.0' },
    },
  };
}

export async function handleToolsList(id: unknown, agencyId: string, env: Env): Promise<unknown> {
  const sites = await getSites(agencyId, env);

  if (sites.length === 0) {
    return { jsonrpc: '2.0', id, result: { tools: PROXY_TOOLS } };
  }

  let upstreamTools: unknown[] = [];
  try {
    upstreamTools = await fetchToolsList(sites[0], env.ENCRYPTION_KEY);
  } catch {
    return { jsonrpc: '2.0', id, result: { tools: PROXY_TOOLS } };
  }

  const siteIds = sites.map((s) => s.site_id);
  const forwardedTools = (upstreamTools as Array<{
    name: string;
    description: string;
    inputSchema: { type: string; properties: Record<string, unknown>; required: string[] };
  }>).map((tool) => ({
    ...tool,
    inputSchema: {
      ...tool.inputSchema,
      properties: {
        _site: {
          type: 'string',
          enum: siteIds,
          description: `Target WordPress site. Registered: ${siteIds.join(', ')}`,
        },
        ...(tool.inputSchema?.properties ?? {}),
      },
      required: ['_site', ...(tool.inputSchema?.required ?? [])],
    },
  }));

  return { jsonrpc: '2.0', id, result: { tools: [...PROXY_TOOLS, ...forwardedTools] } };
}

export async function handleToolsCall(
  id: unknown,
  params: { name: string; arguments: Record<string, unknown> },
  agencyId: string,
  env: Env
): Promise<unknown> {
  const { name, arguments: args = {} } = params;

  if (name === 'proxy_list_sites') {
    const sites = await getSites(agencyId, env);
    const list = sites.map((s) => ({ site_id: s.site_id, url: s.url, label: s.label }));
    return {
      jsonrpc: '2.0',
      id,
      result: { content: [{ type: 'text', text: JSON.stringify(list, null, 2) }] },
    };
  }

  if (name === 'proxy_site_health') {
    const sites = await getSites(agencyId, env);
    const checks = await Promise.allSettled(
      sites.map(async (s) => {
        const apiKey = await decrypt(s.api_key_enc, env.ENCRYPTION_KEY);
        const resp = await fetch(siteUrl(s.url), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
          body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'ping', params: {} }),
          signal: AbortSignal.timeout(5000),
        });
        return { site_id: s.site_id, status: resp.ok ? 'ok' : `error:${resp.status}` };
      })
    );
    const results = checks.map((r, i) =>
      r.status === 'fulfilled' ? r.value : { site_id: sites[i].site_id, status: 'unreachable' }
    );
    return {
      jsonrpc: '2.0',
      id,
      result: { content: [{ type: 'text', text: JSON.stringify(results, null, 2) }] },
    };
  }

  const { _site, ...cleanArgs } = args as { _site?: string } & Record<string, unknown>;

  if (!_site) {
    return {
      jsonrpc: '2.0',
      id,
      error: {
        code: -32602,
        message: 'Missing required param _site. Call proxy_list_sites to see registered sites.',
      },
    };
  }

  const sites = await getSites(agencyId, env);
  const site = sites.find((s) => s.site_id === _site);
  if (!site) {
    return {
      jsonrpc: '2.0',
      id,
      error: {
        code: -32602,
        message: `Unknown site '${_site}'. Call proxy_list_sites to see registered sites.`,
      },
    };
  }

  return forwardToolCall(site, name, cleanArgs, env.ENCRYPTION_KEY, id);
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
cd spai-proxy-worker && npx vitest run test/mcp.test.ts
```

Expected: 8 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add spai-proxy-worker/src/mcp.ts spai-proxy-worker/test/mcp.test.ts
git commit -m "feat: MCP handlers — initialize, tools/list with _site injection, tools/call routing"
```

---

## Task 7: Wire Hono app (index.ts)

**Files:**
- Modify: `spai-proxy-worker/src/index.ts`

- [ ] **Step 1: Replace index.ts skeleton with full app**

`spai-proxy-worker/src/index.ts`:
```typescript
import { Hono } from 'hono';
import type { Env } from './types';
import { handleInitialize, handleToolsList, handleToolsCall } from './mcp';
import { validateToken, generateToken, hashToken } from './auth';
import { getSites, addSite, removeSite } from './registry';
import { encrypt } from './crypto';

type Variables = { agencyId: string };
const app = new Hono<{ Bindings: Env; Variables: Variables }>();

// Health check
app.get('/', (c) => c.json({ service: 'mcpwp-agency-proxy', version: '1.0.0' }));

// Auth middleware factory
async function requireAgencyToken(c: any, next: () => Promise<void>) {
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
}

async function requireApiToken(c: any, next: () => Promise<void>) {
  const auth = c.req.header('Authorization') ?? '';
  const token = auth.startsWith('Bearer ') ? auth.slice(7) : '';
  if (!token) return c.json({ error: 'Missing Authorization: Bearer <agency_token>' }, 401);
  const agencyId = await validateToken(token, c.env);
  if (!agencyId) return c.json({ error: 'Invalid token' }, 401);
  c.set('agencyId', agencyId);
  await next();
}

// MCP endpoint
app.post('/mcp', requireAgencyToken, async (c) => {
  let body: { method?: string; id?: unknown; params?: unknown };
  try {
    body = await c.req.json();
  } catch {
    return c.json({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error: invalid JSON' } }, 400);
  }

  const { method, id, params } = body;
  const agencyId = c.get('agencyId');

  if (method === 'initialize') return c.json(handleInitialize(id));
  if (method === 'notifications/initialized') return c.json({ jsonrpc: '2.0', id, result: {} });
  if (method === 'ping') return c.json({ jsonrpc: '2.0', id, result: {} });
  if (method === 'tools/list') return c.json(await handleToolsList(id, agencyId, c.env));
  if (method === 'tools/call') {
    return c.json(await handleToolsCall(id, params as any, agencyId, c.env));
  }

  return c.json({ jsonrpc: '2.0', id, error: { code: -32601, message: `Unknown method: ${method}` } });
});

// Account creation — returns one-time agency token
app.post('/api/accounts', async (c) => {
  let name = 'My Agency';
  try {
    const body = await c.req.json();
    if (typeof body.name === 'string') name = body.name;
  } catch { /* name stays default */ }

  const token = generateToken();
  const hash = await hashToken(token);
  const agencyId = crypto.randomUUID();

  await c.env.AGENCY_KV.put(`agency:token:${hash}`, agencyId);
  await c.env.AGENCY_KV.put(
    `agency:account:${agencyId}`,
    JSON.stringify({ id: agencyId, name, created_at: new Date().toISOString() })
  );

  return c.json(
    { agency_id: agencyId, token, warning: 'Store this token securely — it cannot be retrieved again.' },
    201
  );
});

// List sites
app.get('/api/sites', requireApiToken, async (c) => {
  const sites = await getSites(c.get('agencyId'), c.env);
  return c.json(sites.map((s) => ({ site_id: s.site_id, url: s.url, label: s.label, added_at: s.added_at })));
});

// Add site
app.post('/api/sites', requireApiToken, async (c) => {
  let body: { url?: string; api_key?: string; label?: string; site_id?: string };
  try {
    body = await c.req.json();
  } catch {
    return c.json({ error: 'Invalid JSON body' }, 400);
  }

  const { url, api_key, label, site_id: providedId } = body;
  if (!url || !api_key) return c.json({ error: 'url and api_key are required' }, 400);

  let parsedUrl: URL;
  try {
    parsedUrl = new URL(url);
  } catch {
    return c.json({ error: 'url is not a valid URL' }, 400);
  }

  const hostname = parsedUrl.hostname.replace(/\./g, '-');
  const site_id = providedId ?? (label ? label.toLowerCase().replace(/[^a-z0-9-]/g, '-') : hostname);
  const api_key_enc = await encrypt(api_key, c.env.ENCRYPTION_KEY);

  await addSite(
    c.get('agencyId'),
    { site_id, url, api_key_enc, label: label ?? hostname, added_at: new Date().toISOString() },
    c.env
  );

  return c.json({ site_id, status: 'registered' }, 201);
});

// Remove site
app.delete('/api/sites/:siteId', requireApiToken, async (c) => {
  const removed = await removeSite(c.get('agencyId'), c.req.param('siteId'), c.env);
  return removed ? c.json({ status: 'removed' }) : c.json({ error: 'Site not found' }, 404);
});

export default { fetch: app.fetch };
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd spai-proxy-worker && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Run all tests**

```bash
cd spai-proxy-worker && npx vitest run
```

Expected: all tests PASS (no regressions from wiring).

- [ ] **Step 4: Commit**

```bash
git add spai-proxy-worker/src/index.ts
git commit -m "feat: wire Hono app — /mcp endpoint + /api/accounts + /api/sites CRUD"
```

---

## Task 8: Cloudflare setup (KV, secrets, DNS)

**Files:**
- Modify: `spai-proxy-worker/wrangler.toml` (fill in KV IDs)

- [ ] **Step 1: Create KV namespace**

```bash
cd spai-proxy-worker && npx wrangler kv namespace create AGENCY_KV
```

Expected output:
```
✅ Successfully created KV namespace
id = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

Also create preview namespace:
```bash
npx wrangler kv namespace create AGENCY_KV --preview
```

Expected:
```
preview_id = "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"
```

- [ ] **Step 2: Update wrangler.toml with real KV IDs**

Replace `FILL_AFTER_wrangler_kv_create` in `wrangler.toml` with the IDs from Step 1:

```toml
[[kv_namespaces]]
binding = "AGENCY_KV"
id = "<id from step 1>"
preview_id = "<preview_id from step 1>"
```

- [ ] **Step 3: Generate and set ENCRYPTION_KEY secret**

```bash
# Generate a random 32-byte hex key
python3 -c "import secrets; print(secrets.token_hex(32))"
# Copy the output, then:
cd spai-proxy-worker && npx wrangler secret put ENCRYPTION_KEY
# Paste the hex key when prompted
```

- [ ] **Step 4: Verify DNS — proxy.mcpwp.net must exist as a CF zone**

```bash
npx wrangler whoami
# Confirm account_id matches wrangler.toml
```

If `proxy.mcpwp.net` subdomain doesn't exist yet, add a DNS record in Cloudflare dashboard:
- Type: `AAAA`, Name: `proxy`, Content: `100::` (proxied, routes to Worker)

- [ ] **Step 5: Commit wrangler.toml with KV IDs**

```bash
git add spai-proxy-worker/wrangler.toml
git commit -m "chore: add KV namespace IDs to wrangler.toml"
```

---

## Task 9: Deploy and smoke test

- [ ] **Step 1: Deploy to Cloudflare**

```bash
cd spai-proxy-worker && npx wrangler deploy
```

Expected:
```
Deployed mcpwp-agency-proxy triggers (X sec)
  https://proxy.mcpwp.net/*
```

- [ ] **Step 2: Health check**

```bash
curl https://proxy.mcpwp.net/
```

Expected:
```json
{"service":"mcpwp-agency-proxy","version":"1.0.0"}
```

- [ ] **Step 3: Create an agency account**

```bash
curl -s -X POST https://proxy.mcpwp.net/api/accounts \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Agency"}' | jq
```

Expected:
```json
{
  "agency_id": "...",
  "token": "mcpwp_agency_...",
  "warning": "Store this token securely — it cannot be retrieved again."
}
```

Save the token:
```bash
AGENCY_TOKEN="mcpwp_agency_..."
```

- [ ] **Step 4: Register a site**

```bash
# Replace with your actual MCPWP site URL and API key
SITE_URL="https://your-wp-site.com"
SITE_KEY="spai_..."

curl -s -X POST https://proxy.mcpwp.net/api/sites \
  -H "Authorization: Bearer $AGENCY_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"url\":\"$SITE_URL\",\"api_key\":\"$SITE_KEY\",\"label\":\"My Site\"}" | jq
```

Expected:
```json
{"site_id":"my-site","status":"registered"}
```

- [ ] **Step 5: Test MCP initialize**

```bash
curl -s -X POST https://proxy.mcpwp.net/mcp \
  -H "Authorization: Bearer $AGENCY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | jq .result.serverInfo
```

Expected:
```json
{"name":"mcpwp-agency-proxy","version":"1.0.0"}
```

- [ ] **Step 6: Test tools/list returns tools with _site param**

```bash
curl -s -X POST https://proxy.mcpwp.net/mcp \
  -H "Authorization: Bearer $AGENCY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | jq '.result.tools[0:3]'
```

Expected: first tools include `proxy_list_sites`, then forwarded tools each with `_site` in `inputSchema.properties`.

- [ ] **Step 7: Test proxy_list_sites**

```bash
curl -s -X POST https://proxy.mcpwp.net/mcp \
  -H "Authorization: Bearer $AGENCY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"proxy_list_sites","arguments":{}}}' | jq
```

Expected: result containing your registered site.

- [ ] **Step 8: Test forwarded tool call**

```bash
SITE_ID="my-site"  # from step 4
curl -s -X POST https://proxy.mcpwp.net/mcp \
  -H "Authorization: Bearer $AGENCY_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_site_info\",\"arguments\":{\"_site\":\"$SITE_ID\"}}}" | jq
```

Expected: site info from your WordPress site proxied through the worker.

- [ ] **Step 9: Commit final state and close GH issue**

```bash
git add -A
git commit -m "feat: mcpwp-agency-proxy v1.0.0 — ship #360

One CF Worker lets an agency connect Claude once and operate any
registered WordPress site. KV-backed site registry, AES-GCM encrypted
API keys, hybrid tool protocol (_site param injection).

Closes #360"

git push origin main
```

Then close issue #360 on GitHub:
```bash
gh issue close 360 --repo Mumega-com/mcpwp --comment "Agency proxy v1.0.0 deployed to proxy.mcpwp.net. See commit for full implementation."
```

---

## Self-Review

**Spec coverage check:**
- ✅ Agency registers account (POST /api/accounts → one-time token)
- ✅ Add/list/remove sites (GET/POST/DELETE /api/sites)
- ✅ Single MCP endpoint routes to correct site (/mcp with _site param)
- ✅ tools/list returns all tools prefixed/extended with _site
- ✅ proxy-native tools (proxy_list_sites, proxy_site_health)
- ✅ Agency token auth separate from per-site API keys
- ✅ Works with Claude Desktop / Claude Code (standard JSON-RPC 2.0 over HTTPS)
- ✅ Per-site keys AES-GCM encrypted in KV
- ✅ Out of scope: dashboard (#366), white-label (#367), billing, audit log (#361)

**Placeholder scan:** No TBD/TODO/placeholder patterns found.

**Type consistency:** `SiteEntry`, `Env`, `AgencyAccount` defined once in `types.ts` and imported everywhere.
