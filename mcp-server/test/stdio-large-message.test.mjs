/**
 * Stdio smoke test for the built proxy bundle (#1123, #1176).
 *
 * The SDK's StdioServerTransport caps one inbound line at 10 MiB by default
 * and, on overflow, closes the session without a reply. wp_upload_media_b64
 * accepts files up to 10 MB decoded (~13.3 MB as base64), so the proxy raises
 * the cap to 16 MiB and logs transport errors to stderr.
 *
 * On overflow the SDK also drops its stdin listener and nulls out the
 * server's transport reference, so every later response (including `ping`)
 * went nowhere — the process stayed alive but stopped answering (#1176). The
 * proxy now logs the failure on stderr, writes a JSON-RPC line with id: null
 * (spec-correct for an unparseable request; TS-SDK clients may ignore it),
 * and exits non-zero so the user or harness can restart it.
 *
 * Runs the real bundle (dist/index.js, or $MCPWP_BUNDLE) as a child process
 * against a local fake WordPress MCP endpoint. Build first: `bun run build`.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { createServer } from "node:http";
import { existsSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const bundle = resolve(process.env.MCPWP_BUNDLE || join(here, "..", "dist", "index.js"));
const MIB = 1024 * 1024;
const REPLY_TIMEOUT_MS = 30_000;

/** Fake WP endpoint: answers initialize and tools/call, records body sizes. */
function startFakeWp() {
  const seen = [];
  const server = createServer((req, res) => {
    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => {
      const raw = Buffer.concat(chunks);
      const msg = JSON.parse(raw.toString("utf8"));
      seen.push({ method: msg.method, bytes: raw.length });
      const result =
        msg.method === "initialize"
          ? { protocolVersion: "2025-06-18", capabilities: {}, serverInfo: { name: "fake-wp", version: "0" } }
          : { content: [{ type: "text", text: `fake-wp received ${raw.length} bytes` }] };
      res.setHeader("Content-Type", "application/json");
      res.end(JSON.stringify({ jsonrpc: "2.0", id: msg.id, result }));
    });
  });
  return new Promise((ok) =>
    server.listen(0, "127.0.0.1", () => ok({ server, seen, port: server.address().port }))
  );
}

/** Spawn the bundle on stdio and give back send/await helpers. */
function startProxy(port, home) {
  const child = spawn(process.execPath, [bundle], {
    env: {
      PATH: process.env.PATH,
      HOME: home,
      WP_URL: `http://127.0.0.1:${port}`,
      WP_API_KEY: "test-key",
      WP_SITE_NAME: "smoke",
    },
    stdio: ["pipe", "pipe", "pipe"],
  });
  let stderr = "";
  child.stderr.on("data", (d) => (stderr += d));
  // After an over-cap line the transport closes, and the test then kills the
  // child while that write may still be flushing. The resulting EPIPE is
  // expected; unhandled, it surfaces as an uncaughtException after the test
  // ended and fails the file under load. Any other stdin error stays visible.
  child.stdin.on("error", (err) => {
    if (err.code !== "EPIPE") stderr += `\n[test] stdin error: ${err.message}`;
  });
  const waiters = new Map();
  const messages = [];
  let out = "";
  let stdoutAll = "";
  child.stdout.on("data", (d) => {
    stdoutAll += d;
    out += d;
    let i;
    while ((i = out.indexOf("\n")) !== -1) {
      const line = out.slice(0, i);
      out = out.slice(i + 1);
      if (!line) continue;
      const msg = JSON.parse(line);
      messages.push(msg);
      waiters.get(msg.id)?.(msg);
    }
  });
  return {
    child,
    stdoutSnapshot: () => stdoutAll,
    stderr: () => stderr,
    send(msg) {
      child.stdin.write(JSON.stringify(msg) + "\n");
    },
    reply(id, timeoutMs = REPLY_TIMEOUT_MS) {
      return new Promise((ok, fail) => {
        const already = messages.find((m) => m.id === id);
        if (already) return ok(already);
        const t = setTimeout(() => fail(new Error(`no reply to id ${id} in ${timeoutMs}ms; stderr: ${stderr.slice(-400)}`)), timeoutMs);
        waiters.set(id, (m) => {
          clearTimeout(t);
          ok(m);
        });
      });
    },
    /** Poll already-received + future stdout messages for one matching `predicate`. */
    waitForMessage(predicate, timeoutMs = REPLY_TIMEOUT_MS) {
      return new Promise((ok, fail) => {
        const deadline = Date.now() + timeoutMs;
        const tick = () => {
          const found = messages.find(predicate);
          if (found) return ok(found);
          if (Date.now() >= deadline) {
            return fail(new Error(`no matching stdout message within ${timeoutMs}ms; stderr: ${stderr.slice(-400)}`));
          }
          setTimeout(tick, 100);
        };
        tick();
      });
    },
    /** Resolve once the child process has exited (already-exited is fine too). */
    waitForExit(timeoutMs = REPLY_TIMEOUT_MS) {
      return new Promise((ok, fail) => {
        if (child.exitCode !== null || child.signalCode !== null) {
          return ok({ code: child.exitCode, signal: child.signalCode });
        }
        const t = setTimeout(() => fail(new Error(`process did not exit within ${timeoutMs}ms; stderr: ${stderr.slice(-400)}`)), timeoutMs);
        child.once("exit", (code, signal) => {
          clearTimeout(t);
          ok({ code, signal });
        });
      });
    },
    stop() {
      child.kill("SIGTERM");
    },
  };
}

/** A tools/call whose serialized line is exactly `bytes` long (excluding \n). */
function toolsCallOfSize(id, bytes) {
  const skeleton = { jsonrpc: "2.0", id, method: "tools/call", params: { name: "wp_upload_media_b64", arguments: { data: "" } } };
  const pad = bytes - Buffer.byteLength(JSON.stringify(skeleton));
  skeleton.params.arguments.data = "A".repeat(pad);
  return skeleton;
}

async function handshake(p) {
  p.send({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-06-18", capabilities: {}, clientInfo: { name: "smoke", version: "0" } } });
  const init = await p.reply(1);
  assert.equal(init.result.serverInfo.name, "mcpwp-smoke");
  p.send({ jsonrpc: "2.0", method: "notifications/initialized" });
}

test("bundle exists (run `bun run build` first)", () => {
  assert.ok(existsSync(bundle), `missing bundle: ${bundle}`);
});

for (const mib of [9, 11, 13.5]) {
  test(`${mib} MiB tools/call gets a reply and the session survives`, async () => {
    const wp = await startFakeWp();
    const home = mkdtempSync(join(tmpdir(), "mcpwp-smoke-"));
    const p = startProxy(wp.port, home);
    try {
      await handshake(p);
      const size = Math.round(mib * MIB);
      p.send(toolsCallOfSize(2, size));
      const res = await p.reply(2);
      assert.equal(res.result?.isError, undefined, JSON.stringify(res).slice(0, 300));
      assert.match(res.result.content[0].text, /fake-wp received \d+ bytes/);
      const forwarded = wp.seen.find((s) => s.method === "tools/call");
      assert.ok(forwarded && forwarded.bytes > size - 1024, "large body reached WordPress intact");

      // The session must still be alive after the big message.
      p.send({ jsonrpc: "2.0", id: 3, method: "ping" });
      const ping = await p.reply(3);
      assert.deepEqual(ping.result, {});
    } finally {
      p.stop();
      wp.server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });
}

// The overflow takes ~3s on a quiet box (the SDK concatenates per chunk,
// which is quadratic) and far longer under load, so wait generously before
// anything downstream of it (the stderr log, the JSON-RPC error, the exit).
const OVERFLOW_TIMEOUT_MS = 30_000;

test("a line over the 16 MiB cap is logged to stderr, not dropped silently", async () => {
  const wp = await startFakeWp();
  const home = mkdtempSync(join(tmpdir(), "mcpwp-smoke-"));
  const p = startProxy(wp.port, home);
  try {
    await handshake(p);
    p.send(toolsCallOfSize(2, 17 * MIB));
    const deadline = Date.now() + OVERFLOW_TIMEOUT_MS;
    while (!/maximum size of 16777216 bytes/.test(p.stderr()) && Date.now() < deadline) {
      await new Promise((r) => setTimeout(r, 100));
    }
    assert.match(
      p.stderr(),
      /\[error\] stdio transport error.*maximum size of 16777216 bytes/s,
      `no overflow log on stderr within ${OVERFLOW_TIMEOUT_MS}ms; stderr tail: ${p.stderr().slice(-400)}`
    );
  } finally {
    p.stop();
    wp.server.close();
    rmSync(home, { recursive: true, force: true });
  }
});

test("a line over the 16 MiB cap emits a spec-shaped id:null error on stdout before exiting (#1176)", async () => {
  const wp = await startFakeWp();
  const home = mkdtempSync(join(tmpdir(), "mcpwp-smoke-"));
  const p = startProxy(wp.port, home);
  try {
    await handshake(p);
    const stdoutBefore = p.stdoutSnapshot();
    p.send(toolsCallOfSize(2, 17 * MIB));

    // The rejected line's own id was never parsed, so the proxy writes
    // id: null per JSON-RPC 2.0 §5.1 (TS-SDK clients may discard it).
    const errorReply = await p.waitForMessage((m) => m.id === null && m.error, OVERFLOW_TIMEOUT_MS);
    assert.equal(errorReply.error.code, -32700);
    assert.match(errorReply.error.message, /16777216/);
    assert.equal(p.stdoutSnapshot(), stdoutBefore + JSON.stringify(errorReply) + "\n");
  } finally {
    p.stop();
    wp.server.close();
    rmSync(home, { recursive: true, force: true });
  }
});

test("the proxy exits non-zero after an over-cap line instead of hanging forever (#1176)", async () => {
  const wp = await startFakeWp();
  const home = mkdtempSync(join(tmpdir(), "mcpwp-smoke-"));
  const p = startProxy(wp.port, home);
  try {
    await handshake(p);
    p.send(toolsCallOfSize(2, 17 * MIB));

    const { code, signal } = await p.waitForExit(OVERFLOW_TIMEOUT_MS);
    assert.equal(signal, null, `expected the proxy to exit on its own, not be killed by a signal (got ${signal})`);
    assert.equal(code, 1, "expected a non-zero exit so the proxy can be restarted");
  } finally {
    p.stop();
    wp.server.close();
    rmSync(home, { recursive: true, force: true });
  }
});

test("closing stdin after the handshake exits cleanly with no extra stdout", async () => {
  const wp = await startFakeWp();
  const home = mkdtempSync(join(tmpdir(), "mcpwp-smoke-"));
  const p = startProxy(wp.port, home);
  try {
    await handshake(p);
    const stdoutAfterHandshake = p.stdoutSnapshot();
    assert.ok(stdoutAfterHandshake.trim().length > 0, "initialize response should be on stdout");
    p.child.stdin.end();
    const { code, signal } = await p.waitForExit(REPLY_TIMEOUT_MS);
    assert.equal(signal, null);
    assert.equal(code, 0);
    assert.equal(p.stdoutSnapshot(), stdoutAfterHandshake);
  } finally {
    if (p.child.exitCode === null) p.stop();
    wp.server.close();
    rmSync(home, { recursive: true, force: true });
  }
});
