import { describe, it, expect, vi, afterEach } from 'vitest';
import { handleInitialize, handleToolsList, handleToolsCall } from '../src/mcp';
import type { Env, SiteEntry } from '../src/types';

afterEach(() => vi.restoreAllMocks());

const TEST_KEY = 'a'.repeat(64);
const AGENCY_ID = 'agency-1';
const SITE_ID_A = '00000000-0000-0000-0000-000000000001';
const SITE_ID_B = '00000000-0000-0000-0000-000000000002';

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
  const { encryptForAgency } = await import('../src/crypto');
  const api_key_enc = await encryptForAgency('spai_real_key', TEST_KEY, AGENCY_ID);
  const site: SiteEntry = {
    site_id: SITE_ID_A,
    url: 'https://client-a.com',
    api_key_enc,
    label: 'Client A',
    added_at: '2026-06-07T00:00:00Z',
  };
  const kv = mockKV({
    [`agency:sites:${AGENCY_ID}`]: JSON.stringify([site]),
  });
  const env = { AGENCY_KV: kv, ENCRYPTION_KEY: TEST_KEY } as unknown as Env;
  return { env, site };
}

async function makeEnvWithTwoSites(): Promise<{ env: Env; sites: SiteEntry[] }> {
  const { encryptForAgency } = await import('../src/crypto');
  const sites: SiteEntry[] = [
    {
      site_id: SITE_ID_A,
      url: 'https://client-a.com',
      api_key_enc: await encryptForAgency('spai_key_a', TEST_KEY, AGENCY_ID),
      label: 'Client A',
      added_at: '2026-06-07T00:00:00Z',
    },
    {
      site_id: SITE_ID_B,
      url: 'https://client-b.com',
      api_key_enc: await encryptForAgency('spai_key_b', TEST_KEY, AGENCY_ID),
      label: 'Client B',
      added_at: '2026-06-07T00:00:00Z',
    },
  ];
  const kv = mockKV({
    [`agency:sites:${AGENCY_ID}`]: JSON.stringify(sites),
  });
  const env = { AGENCY_KV: kv, ENCRYPTION_KEY: TEST_KEY } as unknown as Env;
  return { env, sites };
}

const UPSTREAM_INSTRUCTIONS = '# MCPWP\n\n## Finding Tools\n\nUse `mcpwp_find_tools` then `mcpwp_call`.';

function initResponse(result: Record<string, unknown>): Response {
  return new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

type InitResult = { result: { protocolVersion: string; instructions?: string; serverInfo: { name: string } } };

describe('handleInitialize', () => {
  it('returns MCP capabilities', async () => {
    const result = (await handleInitialize(1)) as InitResult;
    expect(result.result.protocolVersion).toBe('2025-11-25');
    expect(result.result.serverInfo.name).toBe('mcpwp-agency-proxy');
  });

  it('negotiates a supported client version and falls back to the latest (#1074)', async () => {
    const ok = (await handleInitialize(1, undefined, undefined, undefined, '2025-03-26')) as InitResult;
    expect(ok.result.protocolVersion).toBe('2025-03-26');
    const unknown = (await handleInitialize(1, undefined, undefined, undefined, '1999-01-01')) as InitResult;
    expect(unknown.result.protocolVersion).toBe('2025-11-25');
  });

  it('single site: forwards that site\'s instructions and negotiated version upstream (#1074)', async () => {
    const { env } = await makeEnvWithSite();
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      initResponse({ protocolVersion: '2025-06-18', instructions: UPSTREAM_INSTRUCTIONS })
    );
    const result = (await handleInitialize(1, AGENCY_ID, env, undefined, '2025-06-18')) as InitResult;
    expect(result.result.instructions).toContain(UPSTREAM_INSTRUCTIONS);
    expect(result.result.instructions).toContain('_site');
    expect(result.result.protocolVersion).toBe('2025-06-18');
    const [, init] = fetchSpy.mock.calls[0] as [string, { body: string }];
    expect(JSON.parse(init.body).params.protocolVersion).toBe('2025-06-18');
  });

  it('reports the version an older plugin answered with (#1074)', async () => {
    const { env } = await makeEnvWithSite();
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      initResponse({ protocolVersion: '2025-03-26', instructions: UPSTREAM_INSTRUCTIONS })
    );
    const result = (await handleInitialize(1, AGENCY_ID, env, undefined, '2025-11-25')) as InitResult;
    expect(result.result.protocolVersion).toBe('2025-03-26');
  });

  it('multiple sites, no hint: generic instructions and no upstream call (#1074)', async () => {
    const { env } = await makeEnvWithTwoSites();
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const result = (await handleInitialize(1, AGENCY_ID, env)) as InitResult;
    expect(result.result.instructions).toContain('proxy_list_sites');
    expect(result.result.instructions).toContain('mcpwp_find_tools');
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('multiple sites with a hint: uses the hinted site\'s instructions (#1074)', async () => {
    const { env } = await makeEnvWithTwoSites();
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      initResponse({ protocolVersion: '2025-11-25', instructions: UPSTREAM_INSTRUCTIONS })
    );
    const result = (await handleInitialize(1, AGENCY_ID, env, SITE_ID_B, '2025-11-25')) as InitResult;
    expect(result.result.instructions).toContain(UPSTREAM_INSTRUCTIONS);
    expect((vi.mocked(globalThis.fetch).mock.calls[0] as [string])[0]).toBe('https://client-b.com/wp-json/mcpwp/v1/mcp');
  });

  it('falls back to generic instructions when the site is unreachable (#1074)', async () => {
    const { env } = await makeEnvWithSite();
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('network error'));
    const result = (await handleInitialize(1, AGENCY_ID, env)) as InitResult;
    expect(result.result.instructions).toContain('proxy_list_sites');
    expect(result.result.protocolVersion).toBe('2025-11-25');
  });
});

describe('handleInitialize hardening (#1074 review)', () => {
  it('a site never raises the version the client asked for', async () => {
    const { env } = await makeEnvWithSite();
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      initResponse({ protocolVersion: '2025-11-25', instructions: UPSTREAM_INSTRUCTIONS })
    );
    const result = (await handleInitialize(1, AGENCY_ID, env, undefined, '2025-03-26')) as InitResult;
    expect(result.result.protocolVersion).toBe('2025-03-26');
  });

  it('a registry (KV) failure falls back to generic instructions instead of throwing', async () => {
    const kv = { get: async () => { throw new Error('KV down'); } } as unknown as KVNamespace;
    const env = { AGENCY_KV: kv, ENCRYPTION_KEY: TEST_KEY } as unknown as Env;
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const result = (await handleInitialize(1, AGENCY_ID, env, undefined, '2025-06-18')) as InitResult;
    expect(result.result.protocolVersion).toBe('2025-06-18');
    expect(result.result.instructions).toContain('proxy_list_sites');
  });
});

describe('MCP-Protocol-Version forwarding (#1074)', () => {
  it('tools/list sends the client\'s version upstream', async () => {
    const { env } = await makeEnvWithSite();
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(initResponse({ tools: [] }));
    await handleToolsList(1, AGENCY_ID, env, undefined, '2025-11-25');
    const [, init] = fetchSpy.mock.calls[0] as [string, { headers: Record<string, string> }];
    expect(init.headers['MCP-Protocol-Version']).toBe('2025-11-25');
  });

  it('tools/call sends the client\'s version upstream', async () => {
    const { env } = await makeEnvWithSite();
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(initResponse({ content: [] }));
    await handleToolsCall(1, { name: 'wp_list_pages', arguments: { _site: SITE_ID_A } }, AGENCY_ID, env, '2025-06-18');
    const [, init] = fetchSpy.mock.calls[0] as [string, { headers: Record<string, string> }];
    expect(init.headers['MCP-Protocol-Version']).toBe('2025-06-18');
  });

  it('sends no header without a client version, and never an unsupported one', async () => {
    const { env } = await makeEnvWithSite();
    const fetchSpy = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(initResponse({ tools: [] }))
      .mockResolvedValueOnce(initResponse({ tools: [] }));
    await handleToolsList(1, AGENCY_ID, env);
    await handleToolsList(1, AGENCY_ID, env, undefined, 'bogus\r\nX-Evil: 1');
    for (const call of fetchSpy.mock.calls) {
      expect((call[1] as { headers: Record<string, string> }).headers['MCP-Protocol-Version']).toBeUndefined();
    }
  });
});

describe('handleToolsList', () => {
  it('returns proxy-native tools when no sites registered', async () => {
    const kv = mockKV({});
    const env = { AGENCY_KV: kv, ENCRYPTION_KEY: TEST_KEY } as unknown as Env;
    const result = await handleToolsList(1, AGENCY_ID, env) as { result: { tools: Array<{ name: string }> } };
    const toolNames = result.result.tools.map((t) => t.name);
    expect(toolNames).toContain('proxy_list_sites');
    expect(toolNames).toContain('proxy_site_health');
  });

  it('single site: injects _site param into forwarded tools', async () => {
    const { env } = await makeEnvWithSite();
    const mockTools = [{ name: 'wp_list_pages', description: 'List pages', inputSchema: { type: 'object', properties: {}, required: [] } }];
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: mockTools } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );
    const result = await handleToolsList(1, AGENCY_ID, env) as { result: { tools: Array<{ name: string; inputSchema: { properties: Record<string, { enum?: string[] }>; required: string[] } }> } };
    const wpTool = result.result.tools.find((t) => t.name === 'wp_list_pages');
    expect(wpTool).toBeDefined();
    expect(wpTool!.inputSchema.properties._site).toBeDefined();
    expect(wpTool!.inputSchema.required).toContain('_site');
    expect(wpTool!.inputSchema.properties._site.enum).toEqual([SITE_ID_A]);
  });

  // #542: multi-site without hint — must NOT cross-advertise one site's schema
  it('multi-site without _site hint: returns guide tools, not upstream schema (#542)', async () => {
    const { env } = await makeEnvWithTwoSites();
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const result = await handleToolsList(1, AGENCY_ID, env) as { result: { tools: Array<{ name: string }> } };
    const toolNames = result.result.tools.map((t) => t.name);
    // Must NOT advertise any upstream tools
    expect(toolNames).not.toContain('wp_list_pages');
    expect(toolNames).not.toContain('wp_create_post');
    // Must include proxy-native + guide tool
    expect(toolNames).toContain('proxy_list_sites');
    expect(toolNames).toContain('proxy_site_health');
    expect(toolNames).toContain('proxy_select_site');
    // No upstream fetch should have occurred
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  // #542: with _site hint, fetches ONLY that site's tools
  it('multi-site with valid _site hint: returns only that site\'s tools (#542)', async () => {
    const { env } = await makeEnvWithTwoSites();
    const siteATools = [{ name: 'wp_create_post', description: 'Create post', inputSchema: { type: 'object', properties: {}, required: [] } }];
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: siteATools } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );
    const result = await handleToolsList(1, AGENCY_ID, env, SITE_ID_A) as { result: { tools: Array<{ name: string; inputSchema?: { properties?: Record<string, { enum?: string[] }> } }> } };
    const toolNames = result.result.tools.map((t) => t.name);
    expect(toolNames).toContain('wp_create_post');
    // _site enum must cover all agency sites (for the caller to know valid values)
    const wpTool = result.result.tools.find((t) => t.name === 'wp_create_post');
    expect(wpTool!.inputSchema!.properties!._site.enum).toContain(SITE_ID_A);
    expect(wpTool!.inputSchema!.properties!._site.enum).toContain(SITE_ID_B);
    // Only one fetch — not both sites
    expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledTimes(1);
  });

  // #542: unknown _site hint → guide response, not upstream fetch
  it('multi-site with unknown _site hint: returns guide, no upstream fetch (#542)', async () => {
    const { env } = await makeEnvWithTwoSites();
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const result = await handleToolsList(1, AGENCY_ID, env, 'not-a-registered-site') as { result: { tools: Array<{ name: string }> } };
    expect(result.result.tools.map((t) => t.name)).toContain('proxy_select_site');
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  // #542: heterogeneous sites — site-B tools never appear in site-A response
  it('heterogeneous sites: site-B schema does not cross-advertise into site-A selection (#542)', async () => {
    const { env } = await makeEnvWithTwoSites();
    const siteATools = [{ name: 'wp_list_pages', description: 'Pages only', inputSchema: { type: 'object', properties: {}, required: [] } }];
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: siteATools } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );
    const result = await handleToolsList(1, AGENCY_ID, env, SITE_ID_A) as { result: { tools: Array<{ name: string }> } };
    expect(result.result.tools.map((t) => t.name)).toContain('wp_list_pages');
    // Only one upstream fetch, not two
    expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledTimes(1);
  });

  it('falls back to proxy-only tools when single site fetch fails', async () => {
    const { env } = await makeEnvWithSite();
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('client-a unreachable'));
    const result = await handleToolsList(1, AGENCY_ID, env) as { result: { tools: Array<{ name: string }> } };
    const toolNames = result.result.tools.map((t) => t.name);
    expect(toolNames).toContain('proxy_list_sites');
    expect(toolNames).not.toContain('wp_list_pages');
  });
});

describe('handleToolsCall — proxy-native', () => {
  it('proxy_list_sites returns registered sites', async () => {
    const { env } = await makeEnvWithSite();
    const result = await handleToolsCall(1, { name: 'proxy_list_sites', arguments: {} }, AGENCY_ID, env) as { result: { content: Array<{ text: string }> } };
    const text = result.result.content[0].text;
    const sites = JSON.parse(text) as Array<{ site_id: string }>;
    expect(sites[0].site_id).toBe(SITE_ID_A);
  });
});

describe('handleToolsCall — proxy_site_health', () => {
  it('returns ok for responsive site using initialize probe', async () => {
    const { env } = await makeEnvWithSite();
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2024-11-05' } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );
    const result = await handleToolsCall(1, { name: 'proxy_site_health', arguments: {} }, AGENCY_ID, env) as { result: { content: Array<{ text: string }> } };
    const statuses = JSON.parse(result.result.content[0].text) as Array<{ site_id: string; status: string }>;
    expect(statuses[0]).toEqual({ site_id: SITE_ID_A, status: 'ok' });

    const fetchCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0] as [string, { body: string }];
    const body = JSON.parse(fetchCall[1].body) as { method: string };
    expect(body.method).toBe('initialize');
  });

  it('returns unreachable when fetch throws', async () => {
    const { env } = await makeEnvWithSite();
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('network error'));
    const result = await handleToolsCall(1, { name: 'proxy_site_health', arguments: {} }, AGENCY_ID, env) as { result: { content: Array<{ text: string }> } };
    const statuses = JSON.parse(result.result.content[0].text) as Array<{ site_id: string; status: string }>;
    expect(statuses[0]).toEqual({ site_id: SITE_ID_A, status: 'unreachable' });
  });
});

describe('handleToolsCall — forwarding', () => {
  it('missing _site returns JSON-RPC error', async () => {
    const { env } = await makeEnvWithSite();
    const result = await handleToolsCall(1, { name: 'wp_list_pages', arguments: {} }, AGENCY_ID, env) as { error: { code: number; message: string } };
    expect(result.error.code).toBe(-32602);
    expect(result.error.message).toMatch(/_site/);
  });

  it('unknown _site returns JSON-RPC error', async () => {
    const { env } = await makeEnvWithSite();
    const result = await handleToolsCall(1, { name: 'wp_list_pages', arguments: { _site: 'unknown-uuid' } }, AGENCY_ID, env) as { error: { code: number; message: string } };
    expect(result.error.code).toBe(-32602);
    expect(result.error.message).toMatch(/unknown/i);
  });

  it('forwards call to correct upstream site', async () => {
    const { env } = await makeEnvWithSite();
    const upstream = { jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: '[]' }] } };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response(JSON.stringify(upstream), { status: 200, headers: { 'Content-Type': 'application/json' } })
    );
    const result = await handleToolsCall(1, { name: 'wp_list_pages', arguments: { _site: SITE_ID_A, status: 'draft' } }, AGENCY_ID, env);
    expect(result).toEqual(upstream);
  });
});
