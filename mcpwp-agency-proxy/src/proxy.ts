// #545: SSRF guard on site.url
// Rejects: IP-literal hosts, RFC1918 (10/8, 172.16/12, 192.168/16),
//           link-local (169.254/16, fe80::), loopback (127/8, ::1, localhost),
//           .internal/.local suffixes. Enforced at add-site time and re-checked
//           on every forward (DNS rebinding defense).
//
// Blocker A fix: all upstream fetch() calls use redirect:'manual' and treat
// 3xx as an error — prevents a redirect-follow SSRF bypass where an allowed
// host returns 302 → metadata/RFC1918 endpoint.

import { decryptForAgency } from './crypto';
import type { SiteEntry } from './types';

export function siteUrl(baseUrl: string): string {
  return baseUrl.replace(/\/$/, '') + '/wp-json/mcpwp/v1/mcp';
}

/**
 * SSRF guard: validates that a URL is safe to forward to.
 * Returns null on success, or an error string describing the rejection.
 * Checked at registration and on each forward request.
 */
export function checkSsrfUrl(url: string): string | null {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return 'url is not a valid URL';
  }

  if (parsed.protocol !== 'https:') {
    return 'url must use HTTPS';
  }

  // Strip a single trailing dot (FQDN root) — 'localhost.' resolves to loopback.
  let host = parsed.hostname.toLowerCase();
  if (host.endsWith('.')) host = host.slice(0, -1);

  // Block IP-literal IPv6 (e.g. [::1], [fe80::1], [10.0.0.1])
  if (host.startsWith('[')) {
    const addr = host.slice(1, host.endsWith(']') ? host.length - 1 : host.length);
    if (isBlockedIpv6(addr)) {
      return `IP address '${host}' is not allowed (private/loopback/link-local)`;
    }
    return null; // public IPv6 literal — allowed
  }

  // Block IPv4 literals
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(host)) {
    if (isBlockedIpv4(host)) {
      return `IP address '${host}' is not allowed (private/loopback/link-local)`;
    }
    return null; // public IPv4 literal — allowed
  }

  // Fail closed on non-canonical numeric IP forms that bypass the strict
  // dotted-quad check above: decimal int (2130706433), hex int (0x7f000001),
  // octal int (017700000001), and short dotted forms (127.1, 10.0.1).
  // A legitimate DNS hostname is never one of these (TLDs are never all-numeric).
  if (/^\d+$/.test(host) || /^0x[0-9a-f]+$/.test(host) || /^0[0-7]+$/.test(host)) {
    return `host '${host}' is a numeric IP form — not allowed`;
  }
  if (/^\d{1,3}(\.\d{1,3}){1,2}$/.test(host)) {
    return `host '${host}' is a non-canonical IPv4 form — not allowed`;
  }

  // Block loopback hostname
  if (host === 'localhost') {
    return "hostname 'localhost' is not allowed";
  }

  // Block .internal and .local suffixes (RFC 6762 / private DNS)
  if (host.endsWith('.internal') || host.endsWith('.local')) {
    return `hostname '${host}' uses a blocked suffix (.internal/.local)`;
  }

  // NOTE: this is a string-level guard. A public hostname that RESOLVES to a
  // private IP (DNS rebinding) is not caught here — Cloudflare Workers cannot
  // resolve + pin at the app layer. Residual risk is documented; re-validation
  // on every forward (below) limits the window. Network-layer egress controls
  // are the complete fix if/when available.
  return null; // passes
}

function isBlockedIpv4(ip: string): boolean {
  const parts = ip.split('.').map(Number);
  if (parts.length !== 4 || parts.some((p) => isNaN(p) || p < 0 || p > 255)) return false;
  const [a, b] = parts;
  // 0.0.0.0/8 — "this host" / unspecified (0.0.0.0 routes to loopback on many stacks)
  if (a === 0) return true;
  // 127.0.0.0/8 — loopback
  if (a === 127) return true;
  // 10.0.0.0/8 — RFC1918
  if (a === 10) return true;
  // 172.16.0.0/12 — RFC1918 (172.16 – 172.31)
  if (a === 172 && b >= 16 && b <= 31) return true;
  // 192.168.0.0/16 — RFC1918
  if (a === 192 && b === 168) return true;
  // 169.254.0.0/16 — link-local
  if (a === 169 && b === 254) return true;
  return false;
}

function isBlockedIpv6(addr: string): boolean {
  const lower = addr.toLowerCase();
  // :: unspecified, ::1 loopback
  if (lower === '::' || lower === '::1' || lower === '0:0:0:0:0:0:0:0' || lower === '0:0:0:0:0:0:0:1') return true;
  // fe80::/10 link-local
  if (lower.startsWith('fe8') || lower.startsWith('fe9') ||
      lower.startsWith('fea') || lower.startsWith('feb')) return true;
  // fc00::/7 unique local (fd00::/8, fc00::/8)
  if (lower.startsWith('fc') || lower.startsWith('fd')) return true;
  // ::ffff:0:0/96 IPv4-mapped — block if the mapped address is private, in
  // BOTH the dotted-decimal (::ffff:127.0.0.1) and hex-hextet (::ffff:7f00:1)
  // forms. The hex form was a loopback bypass before this guard.
  if (lower.startsWith('::ffff:')) {
    const mapped = lower.slice(7);
    if (/^\d{1,3}(\.\d{1,3}){3}$/.test(mapped)) {
      return isBlockedIpv4(mapped);
    }
    const hx = mapped.match(/^([0-9a-f]{1,4}):([0-9a-f]{1,4})$/);
    if (hx) {
      const hi = parseInt(hx[1], 16);
      const lo = parseInt(hx[2], 16);
      const dotted = [(hi >> 8) & 0xff, hi & 0xff, (lo >> 8) & 0xff, lo & 0xff].join('.');
      return isBlockedIpv4(dotted);
    }
    // Unrecognized mapped form → fail closed.
    return true;
  }
  return false;
}

// ─── Redirect-safe fetch helper (Blocker A) ──────────────────────────────────
//
// Uses redirect:'manual' so the Worker never follows a 3xx returned by an
// upstream site. MCPWP endpoints never legitimately redirect; following would
// let an allowed host 302 → cloud-metadata / RFC1918 and bypass the SSRF guard.
//
// Returns the Response on 1xx/2xx/4xx/5xx.
// Throws an Error with `status` for 3xx — callers convert to appropriate errors.

export async function fetchNoRedirect(
  url: string,
  init: RequestInit
): Promise<Response> {
  const resp = await fetch(url, { ...init, redirect: 'manual' });
  if (resp.status >= 300 && resp.status < 400) {
    throw new Error(`Upstream returned redirect ${resp.status} — not followed (SSRF guard)`);
  }
  return resp;
}

// ─── MCP protocol version (#1074) ────────────────────────────────────────────
//
// WordPress negotiates the MCP version per request (#906): no
// `MCP-Protocol-Version` header means 2025-03-26, which keeps
// `annotations.title` and drops `outputSchema`/`structuredContent`. The
// gateway is stateless, so it forwards the version its client negotiated
// (initialize) or sent (every later request) on each upstream call.

export const SUPPORTED_PROTOCOL_VERSIONS = [
  '2025-11-25',
  '2025-06-18',
  '2025-03-26',
  '2024-11-05',
] as const;
export const LATEST_PROTOCOL_VERSION = SUPPORTED_PROTOCOL_VERSIONS[0];

/** A version this gateway knows, or null — a bad header is never echoed upstream. */
export function supportedProtocolVersion(value: unknown): string | null {
  return typeof value === 'string' && (SUPPORTED_PROTOCOL_VERSIONS as readonly string[]).includes(value)
    ? value
    : null;
}

/**
 * Resolve an inbound `MCP-Protocol-Version` header the way the plugin does
 * (CLAUDE.md, MCP protocol negotiation): absent or empty means 2025-03-26, which
 * is what upstream assumes with no header, so null is returned and none is sent;
 * a supported version is returned; anything else (including two different
 * values, which is ambiguous) is `invalid` and refused with 400, never forwarded.
 */
export function resolveHeaderVersion(raw: string | null | undefined): { version: string | null } | { invalid: true } {
  const header = (raw ?? '').trim();
  if (header === '') return { version: null };
  const values = [...new Set(header.split(',').map((v) => v.trim()))];
  const version = values.length === 1 ? supportedProtocolVersion(values[0]) : null;
  return version ? { version } : { invalid: true };
}

function upstreamHeaders(apiKey: string, protocolVersion?: string | null): Record<string, string> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', 'X-API-Key': apiKey };
  const version = supportedProtocolVersion(protocolVersion);
  if (version) headers['MCP-Protocol-Version'] = version;
  return headers;
}

// ─── Fetch helpers that use decryptForAgency (#546) ─────────────────────────

/**
 * Run `initialize` against one site and return its result (protocolVersion,
 * instructions). Null on any failure: the gateway then falls back to generic
 * instructions rather than failing the client's handshake.
 */
export async function fetchInitialize(
  site: SiteEntry,
  encKey: string,
  agencyId: string,
  protocolVersion: string
): Promise<{ protocolVersion?: string; instructions?: string } | null> {
  if (checkSsrfUrl(site.url)) return null;
  try {
    const { plaintext: apiKey } = await decryptForAgency(site.api_key_enc, encKey, agencyId);
    const resp = await fetchNoRedirect(siteUrl(site.url), {
      method: 'POST',
      headers: upstreamHeaders(apiKey),
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
          protocolVersion,
          capabilities: {},
          clientInfo: { name: 'mcpwp-agency-proxy', version: '1.0.0' },
        },
      }),
      signal: AbortSignal.timeout(5000),
    });
    if (!resp.ok) return null;
    const data = (await resp.json()) as { result?: { protocolVersion?: unknown; instructions?: unknown } };
    const result = data.result;
    if (!result) return null;
    return {
      protocolVersion: typeof result.protocolVersion === 'string' ? result.protocolVersion : undefined,
      instructions:
        typeof result.instructions === 'string' && result.instructions !== '' ? result.instructions : undefined,
    };
  } catch {
    return null;
  }
}

export async function fetchToolsList(
  site: SiteEntry,
  encKey: string,
  agencyId: string,
  protocolVersion?: string | null
): Promise<unknown[]> {
  // #545: re-validate URL on every forward (DNS rebinding defense)
  const ssrfErr = checkSsrfUrl(site.url);
  if (ssrfErr) throw new Error(`SSRF guard: ${ssrfErr}`);

  const { plaintext: apiKey } = await decryptForAgency(site.api_key_enc, encKey, agencyId);
  // Blocker A: redirect:'manual' via fetchNoRedirect — 3xx throws rather than follows.
  const resp = await fetchNoRedirect(siteUrl(site.url), {
    method: 'POST',
    headers: upstreamHeaders(apiKey, protocolVersion),
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }),
    signal: AbortSignal.timeout(30000),
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
  reqId: unknown,
  agencyId: string,
  protocolVersion?: string | null
): Promise<unknown> {
  // #545: re-validate URL on every forward
  const ssrfErr = checkSsrfUrl(site.url);
  if (ssrfErr) {
    return {
      jsonrpc: '2.0',
      id: reqId,
      error: { code: -32000, message: `SSRF guard: ${ssrfErr}` },
    };
  }

  const { plaintext: apiKey } = await decryptForAgency(site.api_key_enc, encKey, agencyId);
  let resp: Response;
  try {
    // Blocker A: redirect:'manual' via fetchNoRedirect — 3xx throws rather than follows.
    resp = await fetchNoRedirect(siteUrl(site.url), {
      method: 'POST',
      headers: upstreamHeaders(apiKey, protocolVersion),
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: reqId,
        method: 'tools/call',
        params: { name: toolName, arguments: args },
      }),
      signal: AbortSignal.timeout(30000),
    });
  } catch (err) {
    // Covers redirect-refusal errors and network failures.
    return {
      jsonrpc: '2.0',
      id: reqId,
      error: { code: -32000, message: `Upstream fetch error: ${String(err)}` },
    };
  }
  if (!resp.ok) {
    return {
      jsonrpc: '2.0',
      id: reqId,
      error: { code: -32000, message: `Upstream error: ${resp.status} from ${site.url}` },
    };
  }
  return resp.json();
}
