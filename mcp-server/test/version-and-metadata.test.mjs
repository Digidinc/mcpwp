/**
 * Verifies runtime version parity, provenance metadata, MCP Registry server.json,
 * and root glama.json alignment for @mcpwp.net/mcpwp.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync, spawn } from "node:child_process";
import { createServer } from "node:http";
import { copyFileSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const pkgDir = resolve(here, "..");
const repoRoot = resolve(pkgDir, "..");
const bundle = resolve(process.env.MCPWP_BUNDLE || join(pkgDir, "dist", "index.js"));
const pkg = JSON.parse(readFileSync(join(pkgDir, "package.json"), "utf8"));
const serverJson = JSON.parse(readFileSync(join(pkgDir, "server.json"), "utf8"));
const glamaJson = JSON.parse(readFileSync(join(repoRoot, "glama.json"), "utf8"));

test("CLI --version matches package.json version", () => {
  const res = spawnSync(process.execPath, [bundle, "--version"], { encoding: "utf8" });
  assert.equal(res.status, 0);
  assert.equal(res.stdout.trim(), `mcpwp v${pkg.version}`);
});

test("standalone bundle without adjacent package.json falls back to matching version", () => {
  const isolated = mkdtempSync(join(tmpdir(), "mcpwp-standalone-"));
  try {
    const isolatedBundle = join(isolated, "index.js");
    copyFileSync(bundle, isolatedBundle);
    const res = spawnSync(process.execPath, [isolatedBundle, "--version"], { encoding: "utf8" });
    assert.equal(res.status, 0);
    assert.equal(res.stdout.trim(), `mcpwp v${pkg.version}`);
  } finally {
    rmSync(isolated, { recursive: true, force: true });
  }
});

test("initialize serverInfo.version matches package.json version", async () => {
  const home = mkdtempSync(join(tmpdir(), "mcpwp-ver-"));
  const wp = createServer((req, res) => {
    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => {
      const msg = JSON.parse(Buffer.concat(chunks).toString("utf8"));
      res.setHeader("Content-Type", "application/json");
      res.end(
        JSON.stringify({
          jsonrpc: "2.0",
          id: msg.id,
          result: {
            protocolVersion: "2025-06-18",
            capabilities: {},
            serverInfo: { name: "fake-wp", version: "0" },
          },
        })
      );
    });
  });
  await new Promise((ok) => wp.listen(0, "127.0.0.1", ok));
  const child = spawn(process.execPath, [bundle], {
    env: {
      PATH: process.env.PATH,
      HOME: home,
      WP_URL: `http://127.0.0.1:${wp.address().port}`,
      WP_API_KEY: "k",
      WP_SITE_NAME: "smoke",
    },
    stdio: ["pipe", "pipe", "pipe"],
  });
  try {
    const reply = await new Promise((ok, fail) => {
      const t = setTimeout(() => fail(new Error("timeout")), 10_000);
      let out = "";
      child.stdout.on("data", (d) => {
        out += d;
        const idx = out.indexOf("\n");
        if (idx !== -1) {
          clearTimeout(t);
          ok(JSON.parse(out.slice(0, idx)));
        }
      });
      child.stdin.write(
        JSON.stringify({
          jsonrpc: "2.0",
          id: 1,
          method: "initialize",
          params: { protocolVersion: "2025-06-18", capabilities: {}, clientInfo: { name: "t", version: "1" } },
        }) + "\n"
      );
    });
    assert.equal(reply.result.serverInfo.version, pkg.version);
  } finally {
    child.kill();
    wp.close();
    rmSync(home, { recursive: true, force: true });
  }
});

test("package.json, server.json, and glama.json metadata align with Digidinc/mcpwp", () => {
  assert.equal(pkg.homepage, "https://mcpwp.net");
  assert.equal(pkg.bugs?.url, "https://github.com/Digidinc/mcpwp/issues");
  assert.equal(pkg.repository?.directory, "mcp-server");
  assert.match(pkg.repository?.url ?? "", /github\.com\/Digidinc\/mcpwp\.git$/);

  assert.equal(serverJson.name, pkg.mcpName);
  assert.equal(serverJson.version, pkg.version);
  assert.equal(serverJson.packages[0].identifier, pkg.name);
  assert.equal(serverJson.packages[0].version, pkg.version);
  assert.equal(serverJson.repository.url, "https://github.com/Digidinc/mcpwp");
  assert.equal(serverJson.repository.subfolder, "mcp-server");
  assert.ok(serverJson.description.length >= 1 && serverJson.description.length <= 100);

  assert.equal(glamaJson.$schema, "https://glama.ai/mcp/schemas/server.json");
  assert.ok(Array.isArray(glamaJson.maintainers) && glamaJson.maintainers.length > 0);
});
