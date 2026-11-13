import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import app from '../src/index';
import { storeToken } from '../src/auth';
import { encryptForAgency } from '../src/crypto';
import type { Env, SiteEntry } from '../src/types';

afterEach(() => vi.restoreAllMocks());

const KEY = 'a'.repeat(64);
const AGENCY = 'agency-1';
const TOKEN = 'mcpwp_agency_test_token';
const SITE_A = '00000000-0000-0000-0000-000000000001';
const SITE_B = '00000000-0000-0000-0000-000000000002';

function mockKV(): KVNamespace {
  const store: Record<string, string> = {};
  return {
    get: async (k: string) => store[k] ?? null,
    put: async (k: string, v: string) => { store[k] = v; },
    delete: async (k: string) => { delete store[k]; },
    list: async () => ({ keys: [], list_complete: true, cursor: '' }),
    getWithMetadata: async (k: string) => ({ value: store[k] ?? null, metadata: null }),
  } as unknown as KVNamespace;
}

let env: Env;

async function setup(siteIds: string[]): Promise<void> {
  env = {
    AGENCY_KV: mockKV(),
    ENCRYPTION_KEY: KEY,
    ADMIN_SECRET: 'x',
    RATE_LIMITER_MCP: { limit: async () => ({ success: true }) },
    RATE_LIMITER_ADMIN: { limit: async () => ({ success: true }) },
  } as unknown as Env;
  await storeToken(TOKEN, AGENCY, 'Agency', env);
  const sites: SiteEntry[] = [];
  for (const [i, id] of siteIds.entries()) {
    sites.push({
      site_id: id,
      url: `https://client-${i}.com`,
      api_key_enc: await encryptForAgency(`key_${i}`, KEY, AGENCY),
      label: `Client ${i}`,
      added_at: '2026-06-07T00:00:00Z',
    });
  }
  await env.AGENCY_KV.put(`agency:sites:${AGENCY}`, JSON.stringify(sites));
}

function rpc(body: unknown, headers: Record<string, string> = {}): Promise<Response> {
  return Promise.resolve(
    app.fetch(
      new Request('https://gw.example/mcp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${TOKEN}`, ...headers },
        body: JSON.stringify(body),
      }),
      env
    )
  );
}

function upstream(result: unknown): Response {
  return new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

type Call = [string, { headers: Record<string, string>; body: string }];

describe('POST /mcp wiring (#1074)', () => {
  beforeEach(async () => setup([SITE_A, SITE_B]));

  it('initialize: params.protocolVersion negotiates, X-Mcpwp-Site picks the site', async () => {
    const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      upstream({ protocolVersion: '2025-06-18', instructions: 'SITE-B-GUIDE' })
    );
    const res = await rpc(
      { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18' } },
      { 'X-Mcpwp-Site': SITE_B }
    );
    const json = (await res.json()) as { result: { protocolVersion: string; instructions: string } };
    expect(json.result.protocolVersion).toBe('2025-06-18');
    expect(json.result.instructions).toContain('SITE-B-GUIDE');
    expect((spy.mock.calls[0] as Call)[0]).toBe('https://client-1.com/wp-json/mcpwp/v1/mcp');
  });

  it('initialize: params._site picks the site; an initialize ignores a bad header', async () => {
    const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(upstream({ protocolVersion: '2025-11-25', instructions: 'A' }));
    const res = await rpc(
      { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-11-25', _site: SITE_A } },
      { 'MCP-Protocol-Version': 'garbage' }
    );
    expect(res.status).toBe(200);
    expect((spy.mock.calls[0] as Call)[0]).toBe('https://client-0.com/wp-json/mcpwp/v1/mcp');
  });

  it('tools/list and tools/call forward the MCP-Protocol-Version header', async () => {
    const spy = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(upstream({ tools: [] }))
      .mockResolvedValueOnce(upstream({ content: [] }));
    await rpc({ jsonrpc: '2.0', id: 2, method: 'tools/list' }, { 'MCP-Protocol-Version': '2025-11-25', 'X-Mcpwp-Site': SITE_A });
    await rpc(
      { jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'wp_x', arguments: { _site: SITE_A } } },
      { 'MCP-Protocol-Version': '2025-06-18' }
    );
    expect((spy.mock.calls[0] as Call)[1].headers['MCP-Protocol-Version']).toBe('2025-11-25');
    expect((spy.mock.calls[1] as Call)[1].headers['MCP-Protocol-Version']).toBe('2025-06-18');
  });

  it('absent or empty header: accepted, nothing forwarded (upstream assumes 2025-03-26)', async () => {
    const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(upstream({ tools: [] }));
    for (const headers of [{} as Record<string, string>, { 'MCP-Protocol-Version': '' }]) {
      const res = await rpc({ jsonrpc: '2.0', id: 2, method: 'tools/list' }, { 'X-Mcpwp-Site': SITE_A, ...headers });
      expect(res.status).toBe(200);
    }
    for (const call of spy.mock.calls) expect((call as Call)[1].headers['MCP-Protocol-Version']).toBeUndefined();
  });

  it('unsupported or malformed header: 400 JSON-RPC error listing versions, value not echoed, nothing forwarded', async () => {
    const spy = vi.spyOn(globalThis, 'fetch');
    for (const bad of ['1999-01-01', '2025-06-18; 2025-03-26', '2099-01-01,2025-06-18', 'x<script>']) {
      const res = await rpc({ jsonrpc: '2.0', id: 7, method: 'tools/list' }, { 'MCP-Protocol-Version': bad, 'X-Mcpwp-Site': SITE_A });
      expect(res.status).toBe(400);
      const text = await res.text();
      const json = JSON.parse(text) as { id: number; error: { code: number; data: { supported: string[] } } };
      expect(json.id).toBe(7);
      expect(json.error.code).toBe(-32600);
      expect(json.error.data.supported).toContain('2025-11-25');
      expect(text).not.toContain(bad);
    }
    expect(spy).not.toHaveBeenCalled();
  });

  it('the same version repeated is that version', async () => {
    const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(upstream({ tools: [] }));
    const res = await rpc({ jsonrpc: '2.0', id: 2, method: 'tools/list' }, { 'MCP-Protocol-Version': '2025-06-18, 2025-06-18', 'X-Mcpwp-Site': SITE_A });
    expect(res.status).toBe(200);
    expect((spy.mock.calls[0] as Call)[1].headers['MCP-Protocol-Version']).toBe('2025-06-18');
  });
});
