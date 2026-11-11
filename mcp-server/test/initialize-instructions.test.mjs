/**
 * Stdio smoke test (#1074): the proxy must hand the host the `instructions`
 * WordPress sends in its `initialize` result (the "Finding Tools" guidance for
 * the `core` tool list), and speak the host's protocol version to WordPress.
 *
 * Runs the real bundle (dist/index.js, or $MCPWP_BUNDLE) against a local fake
 * WordPress MCP endpoint. Build first: `bun run build`, then
 * `node --test test/initialize-instructions.test.mjs`.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { createServer } from "node:http";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const bundle = resolve(process.env.MCPWP_BUNDLE || join(here, "..", "dist", "index.js"));
const INSTRUCTIONS = "# MCPWP\n\n## Finding Tools\n\nUse `mcpwp_find_tools` then `mcpwp_call`.";

function startFakeWp({ instructions = INSTRUCTIONS } = {}) {
  const seen = [];
  const server = createServer((req, res) => {
    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => {
      const msg = JSON.parse(Buffer.concat(chunks).toString("utf8"));
      seen.push({ method: msg.method, version: req.headers["mcp-protocol-version"] ?? null });
      const result =
        msg.method === "initialize"
          ? {
              protocolVersion: msg.params.protocolVersion,
              capabilities: {},
              serverInfo: { name: "fake-wp", version: "0" },
              ...(instructions === null ? {} : { instructions }),
            }
          : { tools: [] };
      res.setHeader("Content-Type", "application/json");
      res.end(JSON.stringify({ jsonrpc: "2.0", id: msg.id, result }));
    });
  });
  return new Promise((ok) =>
    server.listen(0, "127.0.0.1", () => ok({ server, seen, port: server.address().port }))
  );
}

async function handshake(wp, home, protocolVersion) {
  const child = spawn(process.execPath, [bundle], {
    env: { PATH: process.env.PATH, HOME: home, WP_URL: `http://127.0.0.1:${wp.port}`, WP_API_KEY: "k", WP_SITE_NAME: "smoke" },
    stdio: ["pipe", "pipe", "pipe"],
  });
  try {
    const reply = new Promise((ok, fail) => {
      const t = setTimeout(() => fail(new Error("no initialize reply")), 15_000);
      let out = "";
      child.stdout.on("data", (d) => {
        out += d;
        const i = out.indexOf("\n");
        if (i !== -1) {
          clearTimeout(t);
          ok(JSON.parse(out.slice(0, i)));
        }
      });
    });
    child.stdin.write(
      JSON.stringify({
        jsonrpc: "2.0",
        id: 1,
        method: "initialize",
        params: { protocolVersion, capabilities: {}, clientInfo: { name: "t", version: "1" } },
      }) + "\n"
    );
    return await reply;
  } finally {
    child.kill();
  }
}

test("initialize carries WordPress's instructions and the negotiated version", async () => {
  const home = mkdtempSync(join(tmpdir(), "mcpwp-1074-"));
  const wp = await startFakeWp();
  try {
    const response = await handshake(wp, home, "2025-06-18");
    assert.equal(response.result.protocolVersion, "2025-06-18");
    assert.equal(response.result.instructions, INSTRUCTIONS);
    const upstream = wp.seen.find((r) => r.method === "initialize");
    assert.ok(upstream, "proxy ran its own initialize against WordPress");
  } finally {
    wp.server.close();
    rmSync(home, { recursive: true, force: true });
  }
});

test("initialize still succeeds, without instructions, when WordPress sends none", async () => {
  const home = mkdtempSync(join(tmpdir(), "mcpwp-1074-"));
  const wp = await startFakeWp({ instructions: null });
  try {
    const response = await handshake(wp, home, "2025-03-26");
    assert.equal(response.result.protocolVersion, "2025-03-26");
    assert.equal("instructions" in response.result, false);
  } finally {
    wp.server.close();
    rmSync(home, { recursive: true, force: true });
  }
});
