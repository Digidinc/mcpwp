#!/usr/bin/env node
/**
 * MCPWP - MCP Server (Proxy Mode)
 *
 * Thin stdio-to-HTTP proxy: forwards all MCP requests to the PHP plugin's
 * /wp-json/mcpwp/v1/mcp endpoint. Tools are always in sync with
 * the WordPress plugin — zero local tool definitions needed.
 */

import { randomBytes } from "crypto";
import { appendFileSync } from "fs";
import { homedir } from "os";
import { join } from "path";

import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  LATEST_PROTOCOL_VERSION,
  SUPPORTED_PROTOCOL_VERSIONS,
  ListToolsRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";

import { loadConfig, getActiveSite } from "./config.js";
import { withUpstreamInstructions } from "./initialize.js";
import { McpProxy } from "./proxy.js";
import { runSetup } from "./setup.js";

const VERSION = "3.0.0";

function log(level: string, message: string, data?: any): void {
  const ts = new Date().toISOString();
  if (data !== undefined) {
    console.error(`[${ts}] [${level}] ${message}`, data);
  } else {
    console.error(`[${ts}] [${level}] ${message}`);
  }
}

// ─── CLI argument handling ───────────────────────────────────────────

const args = process.argv.slice(2);

if (args.includes("--version") || args.includes("-v")) {
  console.log(`mcpwp v${VERSION}`);
  process.exit(0);
}

if (args.includes("--help") || args.includes("-h")) {
  console.log(`
mcpwp - MCPWP Server for WordPress (proxy mode)

Usage:
  mcpwp              Start MCP server (stdio transport)
  mcpwp --setup      Interactive setup wizard
  mcpwp --test       Test WordPress connection
  mcpwp --version    Show version

Environment Variables:
  WP_URL        WordPress site URL
  WP_API_KEY    MCPWP API key
  WP_SITE_NAME  Site name (for multi-site configs)

Config File:
  ~/.mcpwp/config.json

Documentation:
  https://github.com/Mumega-com/mcpwp
`);
  process.exit(0);
}

if (args.includes("--setup")) {
  await runSetup();
  process.exit(0);
}

if (args.includes("--test")) {
  // Load config (env vars + file)
  const config = loadConfig();
  let site;
  try {
    site = getActiveSite(config);
  } catch {
    console.log("❌ No configuration found. Run: mcpwp --setup");
    process.exit(1);
  }

  const baseUrl = site.url.replace(/\/+$/, "");
  console.log(`🔍 Testing connection to ${baseUrl}...`);
  try {
    const response = await fetch(
      `${baseUrl}/wp-json/mcpwp/v1/site-info`,
      { headers: { "X-API-Key": site.apiKey } }
    );
    if (response.ok) {
      const data = (await response.json()) as any;
      const cap = data.capabilities || {};
      console.log(`✅ Connected! ${data.name} (WordPress ${data.wp_version})`);
      console.log(`   Plugin:      MCPWP v${data.plugin?.version}`);
      console.log(`   Theme:       ${data.theme?.name || "unknown"} ${data.theme?.version || ""}`);
      console.log(`   Plan:        ${cap.plan || data.license?.plan || "unlicensed"}${cap.pro_active ? " (licensed features active)" : ""}`);
      console.log(`   Elementor:   ${cap.elementor ? "yes" : "no"}${cap.elementor_pro ? " + Pro" : ""}${cap.elementor_layout_mode ? ` (${cap.elementor_layout_mode})` : ""}`);
      const extras: string[] = [];
      if (cap.woocommerce) extras.push("WooCommerce");
      if (cap.learnpress) extras.push("LearnPress");
      if (cap.rankmath) extras.push("RankMath");
      if (cap.yoast) extras.push("Yoast");
      if (cap.aioseo) extras.push("AIOSEO");
      if (cap.cf7) extras.push("CF7");
      if (cap.wpforms) extras.push("WPForms");
      if (extras.length) console.log(`   Integrations: ${extras.join(", ")}`);
      // Count tools
      try {
        const toolsRes = await fetch(`${baseUrl}/wp-json/mcpwp/v1/mcp`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-API-Key": site.apiKey },
          body: JSON.stringify({ jsonrpc: "2.0", method: "tools/list", id: 1 }),
        });
        if (toolsRes.ok) {
          const toolsData = (await toolsRes.json()) as any;
          const tools = toolsData?.result?.tools ?? [];
          console.log(`   MCP Tools:   ${tools.length} available`);
        }
      } catch {}
    } else {
      console.log(`❌ HTTP ${response.status}: Check your API key`);
      if (response.status === 401) console.log("   Regenerate your API key in WP Admin > MCPWP");
      if (response.status === 404) console.log("   Make sure the MCPWP plugin is activated");
    }
  } catch (e: any) {
    console.log(`❌ Connection failed: ${e.message}`);
    console.log("   Check that your WordPress site is accessible");
  }
  process.exit(0);
}

// ─── MCP Server (proxy mode) ────────────────────────────────────────

const config = loadConfig();
const site = getActiveSite(config);

// Session ID propagated to WP as X-SPAI-Session-ID for audit trail
const sessionId = randomBytes(8).toString("hex");

// When WP_URL is set via env, site switching is disabled
const envLocked = !!(process.env.WP_URL && process.env.WP_API_KEY);

// Module M — mutable site state
let currentSiteKey = site._key;
let activeProxy = new McpProxy(site, sessionId);

// Two-step switch confirmation state (prevents prompt injection)
let pendingSwitch: { siteKey: string; token: string; expiresAt: number } | null = null;

function auditLog(event: string, data: Record<string, unknown>): void {
  try {
    const line = JSON.stringify({ ts: new Date().toISOString(), session: sessionId, event, ...data });
    const logDir = join(homedir(), ".mcpwp");
    appendFileSync(join(logDir, `audit-${sessionId}.jsonl`), line + "\n");
  } catch {}
}

// Module M tool definitions injected into tools/list
const MODULE_M_TOOLS = [
  {
    name: "wp_list_sites",
    description: "List all configured WordPress sites. Never exposes API keys.",
    inputSchema: { type: "object", properties: {}, required: [] },
  },
  {
    name: "wp_switch_site",
    description: envLocked
      ? "Site switching is disabled when WP_URL is set via environment variable."
      : "Switch the active WordPress site. Two-step: call with site_name to get a token, then call again with site_name + confirm_token to apply.",
    inputSchema: {
      type: "object",
      properties: {
        site_name: { type: "string", description: "Key of the site to switch to (from wp_list_sites)" },
        confirm_token: { type: "string", description: "Confirmation token from step 1 (step 2 only)" },
      },
      required: ["site_name"],
    },
  },
];

function handleWpListSites(): object {
  const freshConfig = loadConfig();
  const sites = Object.entries(freshConfig.sites).map(([key, s]) => ({
    key,
    url: s.url,
    name: s.name || key,
    active: key === currentSiteKey,
  }));
  return {
    content: [{ type: "text", text: JSON.stringify({ sites, active_site: currentSiteKey }, null, 2) }],
  };
}

function handleWpSwitchSite(args: Record<string, unknown>): object {
  if (envLocked) {
    return {
      content: [{ type: "text", text: "Site switching is disabled: WP_URL is set via environment variable." }],
      isError: true,
    };
  }

  const siteName = String(args.site_name ?? "");
  const confirmToken = args.confirm_token ? String(args.confirm_token) : undefined;
  const freshConfig = loadConfig();

  if (!freshConfig.sites[siteName]) {
    return {
      content: [{ type: "text", text: `Unknown site "${siteName}". Available: ${Object.keys(freshConfig.sites).join(", ")}` }],
      isError: true,
    };
  }

  if (siteName === currentSiteKey) {
    return { content: [{ type: "text", text: `Already connected to "${siteName}".` }] };
  }

  // Step 2: validate token and apply switch
  if (confirmToken) {
    if (!pendingSwitch || pendingSwitch.token !== confirmToken || pendingSwitch.siteKey !== siteName || Date.now() > pendingSwitch.expiresAt) {
      pendingSwitch = null;
      return {
        content: [{ type: "text", text: "Invalid or expired confirmation token. Start over with wp_switch_site." }],
        isError: true,
      };
    }

    const prevKey = currentSiteKey;
    currentSiteKey = siteName;
    activeProxy = new McpProxy({ ...freshConfig.sites[siteName] }, sessionId);
    activeProxy.setRequestedProtocolVersion(hostProtocolVersion);
    pendingSwitch = null;
    auditLog("site_switch", { from: prevKey, to: siteName });

    return {
      content: [{
        type: "text",
        text: `Switched to "${siteName}" (${freshConfig.sites[siteName].url}).\n⚠️  Context notice: data from "${prevKey}" is still in your context window — do not apply it to this site.`,
      }],
    };
  }

  // Step 1: issue confirmation token (60s TTL)
  const token = randomBytes(4).toString("hex").toUpperCase();
  pendingSwitch = { siteKey: siteName, token, expiresAt: Date.now() + 60_000 };
  auditLog("site_switch_requested", { from: currentSiteKey, to: siteName });

  return {
    content: [{
      type: "text",
      text: `To switch to "${siteName}", call wp_switch_site again with:\n  site_name: "${siteName}"\n  confirm_token: "${token}"\n\nToken expires in 60 seconds.`,
    }],
  };
}

// Derive server name: "mcpwp-<sitename>" (slug from site name, key, or URL hostname).
function deriveServerName(site: { name?: string; _key: string; url: string }): string {
  const raw = site.name || (site._key !== "default" ? site._key : "");
  if (raw) {
    const slug = raw.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    if (slug) return `mcpwp-${slug}`;
  }
  // Fallback: use hostname from URL.
  try {
    const hostname = new URL(site.url).hostname.replace(/\./g, "-");
    return `mcpwp-${hostname}`;
  } catch {
    return "mcpwp";
  }
}

const serverName = deriveServerName(site);
log("info", `Session ID: ${sessionId} | Active site: ${currentSiteKey}${envLocked ? " (env-locked)" : ""}`);

const server = new Server(
  { name: serverName, version: VERSION },
  { capabilities: { tools: {}, resources: {} } }
);

// Host protocol version (#906). The SDK answers the host's `initialize` itself;
// this derives the version it answered with the same rule, from the SDK's
// public constants — the requested version if the SDK supports it, else its
// latest — so WordPress is spoken to in the same version.
let hostProtocolVersion: string | null = null;

function noteHostInitialize(message: unknown): void {
  const msg = message as { method?: unknown; params?: { protocolVersion?: unknown } } | null;
  if (!msg || msg.method !== "initialize") return;
  const requested = msg.params?.protocolVersion;
  hostProtocolVersion =
    typeof requested === "string" && SUPPORTED_PROTOCOL_VERSIONS.includes(requested)
      ? requested
      : LATEST_PROTOCOL_VERSION;
  activeProxy.setRequestedProtocolVersion(hostProtocolVersion);
}

// tools/list → proxy + inject Module M tools
server.setRequestHandler(ListToolsRequestSchema, async () => {
  try {
    const result = await activeProxy.call("tools/list");
    const wpTools = result?.tools ?? [];
    return { tools: [...wpTools, ...MODULE_M_TOOLS] };
  } catch (error: any) {
    log("error", "tools/list failed", error.message);
    return { tools: [...MODULE_M_TOOLS] };
  }
});

// tools/call → intercept Module M tools, proxy everything else
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: toolArgs } = request.params;

  // Module M — handled locally, never forwarded to WordPress
  if (name === "wp_list_sites") return handleWpListSites();
  if (name === "wp_switch_site") return handleWpSwitchSite((toolArgs ?? {}) as Record<string, unknown>);

  try {
    const result = await activeProxy.call("tools/call", { name, arguments: toolArgs ?? {} });
    if (result?.content) {
      return result;
    }
    return {
      content: [{ type: "text", text: JSON.stringify(result, null, 2) }],
    };
  } catch (error: any) {
    log("error", `tools/call ${name} failed`, error.message);
    return {
      content: [{ type: "text", text: `Error: ${error.message}` }],
      isError: true,
    };
  }
});

// resources/list → proxy
server.setRequestHandler(ListResourcesRequestSchema, async () => {
  try {
    const result = await activeProxy.call("resources/list");
    return { resources: result?.resources ?? [] };
  } catch (error: any) {
    log("error", "resources/list failed", error.message);
    return { resources: [] };
  }
});

// resources/read → proxy
server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
  const { uri } = request.params;

  try {
    const result = await activeProxy.call("resources/read", { uri });
    if (result?.contents) {
      return result;
    }
    return {
      contents: [
        { uri, mimeType: "application/json", text: JSON.stringify(result, null, 2) },
      ],
    };
  } catch (error: any) {
    throw new Error(`Failed to read resource ${uri}: ${error.message}`);
  }
});

// ─── Start ───────────────────────────────────────────────────────────

async function main() {
  try {
    const transport = new StdioServerTransport();
    await server.connect(transport);
    // Observe (never alter) inbound messages through the public Transport
    // interface, to learn the host's requested protocol version.
    const deliver = transport.onmessage;
    let initializeId: unknown;
    transport.onmessage = (message) => {
      noteHostInitialize(message);
      if ((message as { method?: unknown }).method === "initialize") {
        initializeId = (message as { id?: unknown }).id;
      }
      deliver?.(message);
    };
    // #1074: the SDK answers `initialize` without instructions. Add the ones
    // WordPress sent (the "Finding Tools" guidance) to that one response.
    const deliverOut = transport.send.bind(transport);
    transport.send = async (message, options) => {
      const id = (message as { id?: unknown }).id;
      if (initializeId !== undefined && id === initializeId && "result" in message) {
        initializeId = undefined;
        return deliverOut(withUpstreamInstructions(message, await activeProxy.getInstructions()), options);
      }
      return deliverOut(message, options);
    };
    log("info", `MCPWP Server v${VERSION} running as "${serverName}" (proxy mode)`);
    log("info", `Proxying to: ${site.url} | session: ${sessionId}`);
  } catch (error: any) {
    console.error("Failed to start server:", error.message);
    process.exit(1);
  }
}

main();
