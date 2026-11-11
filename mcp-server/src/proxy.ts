/**
 * MCPWP - MCP Proxy
 * Forwards JSON-RPC requests from stdio to the PHP MCP endpoint over HTTP.
 */

import type { SiteConfig } from "./config.js";

export interface JsonRpcRequest {
  jsonrpc: "2.0";
  id?: string | number;
  method: string;
  params?: Record<string, any>;
}

export interface JsonRpcResponse {
  jsonrpc: "2.0";
  id?: string | number;
  result?: any;
  error?: { code: number; message: string; data?: any };
}

/** A 400 from the plugin's MCP-Protocol-Version check (#906). */
export function isUnsupportedVersionRefusal(error: any): boolean {
  const body = error?.body;
  return (
    error?.status === 400 &&
    body?.error?.code === -32600 &&
    Array.isArray(body?.error?.data?.supported)
  );
}

export class McpProxy {
  private endpoint: string;
  private apiKey: string;
  private sessionId: string;

  /**
   * MCP protocol version (#906). The host (Claude Desktop, Cursor…) negotiates
   * a version with this proxy over stdio; the proxy then runs its own
   * `initialize` against WordPress with that version, and sends the version
   * WordPress answered as `MCP-Protocol-Version` on every later request — as
   * the spec requires. Without it, WordPress assumes 2025-03-26 and answers
   * without `outputSchema` / `structuredContent`.
   */
  private requestedProtocolVersion: string | null = null;
  private protocolVersion: string | null = null;
  private negotiation: Promise<void> | null = null;
  /**
   * `instructions` from WordPress's `initialize` result (#1074): the "Finding
   * Tools" guidance that explains the `core` tool list. The host's own
   * `initialize` is answered by the SDK, so it is carried over separately.
   */
  private instructions: string | null = null;

  constructor(site: SiteConfig, sessionId = "") {
    const base = site.url.replace(/\/+$/, "");
    this.endpoint = `${base}/wp-json/mcpwp/v1/mcp`;
    this.apiKey = site.apiKey;
    this.sessionId = sessionId;
  }

  /**
   * Record the version the host negotiated with this proxy. WordPress is
   * (re)negotiated lazily, before the next forwarded request.
   */
  setRequestedProtocolVersion(version: string | null): void {
    this.requestedProtocolVersion = version;
    this.protocolVersion = null;
    this.instructions = null;
    this.negotiation = null;
  }

  /**
   * The upstream `initialize` instructions, negotiating first if needed.
   * Null when WordPress sent none or could not be reached; never throws, and
   * gives up after `timeoutMs` so a slow site cannot stall the host handshake.
   */
  async getInstructions(timeoutMs = 5000): Promise<string | null> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<void>((resolve) => {
      timer = setTimeout(resolve, timeoutMs);
    });
    try {
      await Promise.race([this.negotiate(), timeout]);
    } finally {
      clearTimeout(timer);
    }
    return this.instructions;
  }

  /** The version WordPress agreed to, once negotiated. */
  getProtocolVersion(): string | null {
    return this.protocolVersion;
  }

  private negotiate(): Promise<void> {
    if (!this.requestedProtocolVersion) return Promise.resolve();
    if (!this.negotiation) {
      this.negotiation = this.post({
        jsonrpc: "2.0",
        id: 0,
        method: "initialize",
        params: {
          protocolVersion: this.requestedProtocolVersion,
          capabilities: {},
          clientInfo: { name: "mcpwp-proxy", version: "1" },
        },
      }, null)
        .then((response) => {
          const version = response?.result?.protocolVersion;
          this.protocolVersion = typeof version === "string" ? version : null;
          const instructions = response?.result?.instructions;
          this.instructions =
            typeof instructions === "string" && instructions !== "" ? instructions : null;
        })
        .catch(() => {
          // Unreachable or a pre-#906 plugin: go on without the header and
          // try again before the next request.
          this.protocolVersion = null;
          this.negotiation = null;
        });
    }
    return this.negotiation;
  }

  private async post(request: JsonRpcRequest, protocolVersion: string | null): Promise<any> {
    const headers: Record<string, string> = {
      "Content-Type": "application/json",
      "X-API-Key": this.apiKey,
    };
    if (this.sessionId) headers["X-SPAI-Session-ID"] = this.sessionId;
    if (protocolVersion) headers["MCP-Protocol-Version"] = protocolVersion;

    return fetch(this.endpoint, {
      method: "POST",
      headers,
      body: JSON.stringify(request),
    }).then(async (response) => {
      if (!response.ok) {
        const text = await response.text().catch(() => "");
        const error = new Error(
          `HTTP ${response.status} from WordPress MCP endpoint: ${text.slice(0, 200)}`
        );
        (error as any).status = response.status;
        try {
          (error as any).body = JSON.parse(text);
        } catch {}
        throw error;
      }
      return (await response.json()) as any;
    });
  }

  /**
   * Forward a JSON-RPC request to the PHP MCP endpoint.
   */
  async forward(request: JsonRpcRequest): Promise<JsonRpcResponse> {
    if (request.method === "initialize") {
      return (await this.post(request, null)) as unknown as JsonRpcResponse;
    }

    await this.negotiate();
    const version = this.protocolVersion;
    try {
      return (await this.post(request, version)) as unknown as JsonRpcResponse;
    } catch (error: any) {
      // The plugin was swapped mid-session for one that no longer speaks
      // the negotiated version: renegotiate once and retry — only for that
      // refusal (400, JSON-RPC -32600 carrying the supported-version list),
      // never for any other 400.
      if (version && isUnsupportedVersionRefusal(error)) {
        this.setRequestedProtocolVersion(this.requestedProtocolVersion);
        await this.negotiate();
        return (await this.post(request, this.protocolVersion)) as unknown as JsonRpcResponse;
      }
      throw error;
    }
  }

  /**
   * Convenience: send a JSON-RPC call and return just the result.
   * Throws on JSON-RPC errors.
   */
  async call(method: string, params?: Record<string, any>): Promise<any> {
    const rpcRequest: JsonRpcRequest = {
      jsonrpc: "2.0",
      id: 1,
      method,
      params: params ?? {},
    };

    const rpcResponse = await this.forward(rpcRequest);

    if (rpcResponse.error) {
      const err = new Error(rpcResponse.error.message);
      (err as any).code = rpcResponse.error.code;
      (err as any).data = rpcResponse.error.data;
      throw err;
    }

    return rpcResponse.result;
  }
}
