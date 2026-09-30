#!/usr/bin/env node
// Generate the docs.mcpwp.net tool and error reference from a live MCPWP site (#1065).
//
// Hand-written per-category tool pages drifted from the code. This script reads the
// catalog from a running site instead, so a release's docs describe that release.
//
// Two steps, because Free and Pro need two runs against the same site (with and
// without the agency marker):
//
//   fetch   Calls the site's MCP endpoint and writes a JSON snapshot. Never writes the key.
//             env  MCPWP_SITE_URL   e.g. http://localhost:9196
//                  MCPWP_API_KEY    an admin-role, admin-scope key (read from env only)
//             --edition free|pro    label for this snapshot
//             --snapshot FILE       where to write it
//             --source introspect|tools-list|find-tools   (default introspect)
//
//   render  Merges a Free and a Pro snapshot with facts parsed statically from the plugin
//           source (role → category map, scope table, error hints) and writes Markdown.
//             --free FILE --pro FILE
//             --plugin-dir DIR      the plugin's mcpwp/ directory (default: ../mcpwp)
//             --out DIR             the docs repo's src/content/docs directory
//
// Sources (all read the FULL description and input schema, not a trimmed one):
//   introspect  tools/call wp_introspect {"full": true}: full descriptions, input schemas,
//               annotations and each tool's REST route. Falls back to {"detail": "full"}
//               if the site refuses "full" (#1064 may rename it). Default.
//   tools-list  tools/list on the endpoint with ?profile=full. Correct on 3.14.9. After
//               #1064 trims tools/list descriptions (≤300 chars), do not use this one.
//   find-tools  tools/list for the names, then mcpwp_find_tools per tool for the entry.
//               Use this if #1064 moves the rich descriptions/examples into find-tools
//               results and wp_introspect no longer carries them.
//
// Nothing here needs PHP: Mcpwp_Error_Hints has no REST endpoint, so its map is parsed
// from the PHP source, and so are the role and scope tables. The generated pages say so.
//
// Usage (see docs/RELEASE_CHECKLIST.md step 11, and scripts/gen-docs-reference.sh which
// stands up the throwaway site and runs both steps):
//   MCPWP_SITE_URL=http://localhost:9196 MCPWP_API_KEY=... \
//     node scripts/gen-docs-reference.mjs fetch --edition free --snapshot /tmp/free.json
//   node scripts/gen-docs-reference.mjs render --free /tmp/free.json --pro /tmp/pro.json \
//     --out ../mcpwp-docs/src/content/docs

import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

function arg(name, fallback = undefined) {
  const i = process.argv.indexOf(name);
  return i === -1 ? fallback : process.argv[i + 1];
}

function die(msg, code = 2) {
  process.stderr.write(`gen-docs-reference: ${msg}\n`);
  process.exit(code);
}

// ---------------------------------------------------------------------------------------
// fetch
// ---------------------------------------------------------------------------------------

async function rpc(url, key, method, params, id = 1) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      'X-API-Key': key,
      'MCP-Protocol-Version': '2025-11-25',
    },
    body: JSON.stringify({ jsonrpc: '2.0', id, method, params }),
  });
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    throw new Error(`${method}: HTTP ${res.status}, not JSON: ${text.slice(0, 200)}`);
  }
  if (body.error) {
    throw new Error(`${method}: ${body.error.code} ${body.error.message}`);
  }
  return body.result;
}

function toolText(result) {
  const text = result?.content?.[0]?.text;
  if (result?.isError) {
    const err = new Error(text || 'tool error');
    err.structured = result.structuredContent;
    throw err;
  }
  return JSON.parse(text);
}

function pickTool(t) {
  const r0 = t.x_mcpwp || t.x_spai || null;
  const route = r0 && r0.route ? r0 : null;
  return {
    name: t.name,
    title: t.title || t.annotations?.title || '',
    description: t.description || '',
    inputSchema: t.inputSchema || { type: 'object', properties: {} },
    annotations: t.annotations || {},
    category: t.annotations?.category || t.category || '',
    route: route ? { method: route.method, route: route.route } : null,
  };
}

async function fetchSnapshot() {
  const edition = arg('--edition');
  const out = arg('--snapshot');
  const source = arg('--source', 'introspect');
  const site = process.env.MCPWP_SITE_URL;
  const key = process.env.MCPWP_API_KEY;
  if (!['free', 'pro'].includes(edition)) die('--edition free|pro is required');
  if (!out) die('--snapshot FILE is required');
  if (!site || !key) die('set MCPWP_SITE_URL and MCPWP_API_KEY in the environment');
  if (!['introspect', 'tools-list', 'find-tools'].includes(source)) die(`unknown --source ${source}`);

  const base = site.replace(/\/+$/, '');
  const endpoint = `${base}/wp-json/mcpwp/v1/mcp`;
  const fullEndpoint = `${endpoint}?profile=full`;

  const init = await rpc(fullEndpoint, key, 'initialize', {
    protocolVersion: '2025-11-25',
    capabilities: {},
    clientInfo: { name: 'gen-docs-reference', version: '1' },
  });
  const pluginVersion = init?.serverInfo?.plugin_version || init?.serverInfo?.version || '';

  const listed = (await rpc(fullEndpoint, key, 'tools/list', {})).tools || [];
  let tools;

  if (source === 'tools-list') {
    tools = listed.map(pickTool);
  } else if (source === 'introspect') {
    let data;
    try {
      data = toolText(await rpc(endpoint, key, 'tools/call', { name: 'wp_introspect', arguments: { full: true } }));
    } catch (e) {
      data = toolText(await rpc(endpoint, key, 'tools/call', { name: 'wp_introspect', arguments: { detail: 'full' } }));
    }
    if (!Array.isArray(data.tools) || !data.tools.length || !data.tools[0].inputSchema) {
      die('wp_introspect returned no full tool entries; try --source find-tools');
    }
    const listedByName = new Map(listed.map((t) => [t.name, t]));
    tools = data.tools.map((t) => {
      const picked = pickTool(t);
      // wp_introspect leaves the meta tools' category empty; tools/list has it.
      const l = listedByName.get(t.name);
      if (!picked.category && l?.annotations?.category) {
        picked.category = l.annotations.category;
        picked.annotations = { ...picked.annotations, category: l.annotations.category };
      }
      return picked;
    });
    // wp_introspect lists the registries; tools/list is what a caller actually sees
    // (the meta tools are only in tools/list). Keep the union, introspect wins.
    const have = new Set(tools.map((t) => t.name));
    for (const t of listed) if (!have.has(t.name)) tools.push(pickTool(t));
  } else {
    tools = [];
    for (const t of listed) {
      const r = toolText(
        await rpc(endpoint, key, 'tools/call', { name: 'mcpwp_find_tools', arguments: { query: t.name, limit: 20 } })
      );
      const hit = (r.matches || []).find((m) => m.name === t.name);
      tools.push(pickTool(hit ? { ...t, ...hit, annotations: hit.annotations || t.annotations } : t));
    }
  }

  const resources = (await rpc(endpoint, key, 'resources/list', {}).catch(() => ({}))).resources || [];
  const templates =
    (await rpc(endpoint, key, 'resources/templates/list', {}).catch(() => ({}))).resourceTemplates || [];
  const prompts = (await rpc(endpoint, key, 'prompts/list', {}).catch(() => ({}))).prompts || [];

  // A site URL inside a resource URI is this throwaway site's, not the reader's.
  const scrub = (s) => (typeof s === 'string' ? s.split(base).join('https://YOUR-SITE.com') : s);

  const snapshot = {
    edition,
    source,
    plugin_version: pluginVersion,
    fetched_at: new Date().toISOString(),
    tool_count: tools.length,
    tools: tools.sort((a, b) => a.name.localeCompare(b.name)),
    resources: resources.map((r) => ({ uri: scrub(r.uri), name: r.name, description: r.description, mimeType: r.mimeType })),
    resource_templates: templates.map((r) => ({
      uriTemplate: r.uriTemplate,
      name: r.name,
      description: r.description,
      mimeType: r.mimeType,
    })),
    prompts: prompts.map((p) => ({ name: p.name, description: p.description, arguments: p.arguments || [] })),
  };
  const json = JSON.stringify(snapshot, null, 2);
  if (json.includes(key)) die('refusing to write a snapshot that contains the API key');
  writeFileSync(out, json + '\n');
  process.stdout.write(`${edition}: ${tools.length} tools, ${prompts.length} prompts, ${resources.length}+${templates.length} resources, plugin ${pluginVersion} (source ${source}) -> ${out}\n`);
}

// ---------------------------------------------------------------------------------------
// Static facts from the plugin source
// ---------------------------------------------------------------------------------------

function read(p) {
  return readFileSync(p, 'utf8');
}

// Body of a PHP function, by brace matching from its declaration.
function phpFunctionBody(src, name) {
  const re = new RegExp(`function\\s+${name}\\s*\\(`);
  const m = re.exec(src);
  if (!m) return null;
  let i = src.indexOf('{', m.index);
  let depth = 0;
  const start = i;
  for (; i < src.length; i++) {
    const c = src[i];
    if (c === "'" || c === '"') {
      // skip string literal
      const q = c;
      i++;
      while (i < src.length && src[i] !== q) {
        if (src[i] === '\\') i++;
        i++;
      }
      continue;
    }
    if (c === '/' && src[i + 1] === '/') {
      i = src.indexOf('\n', i);
      continue;
    }
    if (c === '/' && src[i + 1] === '*') {
      i = src.indexOf('*/', i) + 1;
      continue;
    }
    if (c === '{') depth++;
    if (c === '}') {
      depth--;
      if (depth === 0) return src.slice(start, i + 1);
    }
  }
  return null;
}

function stripComments(s) {
  return s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"])\/\/.*$/gm, '$1');
}

function phpStrings(s) {
  return [...s.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1].replace(/\\'/g, "'"));
}

function staticFacts(pluginDir) {
  const auth = read(join(pluginDir, 'includes/traits/trait-mcpwp-api-auth.php'));

  // Roles → categories.
  const rolesBody = stripComments(phpFunctionBody(auth, 'get_role_definitions') || '');
  const roles = {};
  for (const m of rolesBody.matchAll(/'([a-z_-]+)'\s*=>\s*array\(\s*'label'([\s\S]*?)'categories'\s*=>\s*array\(([^)]*)\)/g)) {
    const desc = /'description'\s*=>\s*__\(\s*'((?:[^'\\]|\\.)*)'/.exec(m[2]);
    roles[m[1]] = { description: desc ? desc[1] : '', categories: phpStrings(m[3]) };
  }
  if (!roles.admin || !roles.author) die('could not parse get_role_definitions()');

  // Category labels.
  const labelsBody = stripComments(phpFunctionBody(auth, 'get_all_tool_category_labels') || '');
  const categoryLabels = {};
  for (const m of labelsBody.matchAll(/'([a-z-]+)'\s*=>\s*__\(\s*'((?:[^'\\]|\\.)*)'/g)) categoryLabels[m[1]] = m[2];

  // Category descriptions (Tools admin page).
  const toolsAdmin = read(join(pluginDir, 'includes/admin/class-mcpwp-tools-admin.php'));
  const metaBody = phpFunctionBody(toolsAdmin, 'get_category_meta') || '';
  const categoryDescriptions = {};
  for (const m of metaBody.matchAll(/'([a-z-]+)'\s*=>\s*array\([\s\S]*?'description'\s*=>\s*__\(\s*'((?:[^'\\]|\\.)*)'/g)) {
    categoryDescriptions[m[1]] = m[2].replace(/\s*\(#\d+\)/g, '');
  }

  // Discovery tools every key reaches.
  const discBody = phpFunctionBody(auth, 'get_discovery_allowlist') || '';
  const discovery = [...discBody.matchAll(/'(wp_[a-z_]+)'\s*=>/g)].map((m) => m[1]);

  // Tool-name scope table.
  const scopeBody = stripComments(phpFunctionBody(auth, 'get_required_scope_for_tool_name') || '');
  const adminList = /\$admin_tools\s*=\s*array\(([\s\S]*?)\);/.exec(scopeBody);
  const writeList = /\$write_tools\s*=\s*array\(([\s\S]*?)\);/.exec(scopeBody);
  const verbRe = /preg_match\(\s*'\/(\^wp_\([a-z|]+\))\/'/.exec(scopeBody);
  if (!adminList || !writeList || !verbRe) die('could not parse get_required_scope_for_tool_name()');
  const adminTools = new Set(phpStrings(adminList[1]));
  const writeTools = new Set(phpStrings(writeList[1]));
  const writeVerb = new RegExp(verbRe[1]);

  // Routes that need admin scope when the request sets a publishing status.
  const reqBody = stripComments(phpFunctionBody(auth, 'get_required_scope_for_request') || '');
  const pub = /\$publish_gated_routes\s*=\s*array\(([\s\S]*?)\);/.exec(reqBody);
  const publishGated = pub ? phpStrings(pub[1]).map((r) => r.replace('/mcpwp/v1', '')) : [];

  // Error hints.
  const hintsSrc = read(join(pluginDir, 'includes/class-mcpwp-error-hints.php'));
  const mapBody = phpFunctionBody(hintsSrc, 'get_hint_map') || '';
  const hints = [];
  let section = '';
  const lines = mapBody.split('\n');
  const joined = mapBody;
  for (const line of lines) {
    const c = /^\s*\/\/\s*(.+)$/.exec(line);
    if (c) section = c[1].replace(/\.$/, '');
  }
  // Entries: 'code' => array( 'hint' => <expr>, 'guide' => <expr> ),
  const entryRe = /\n\s*\/\/\s*([^\n]+)|'([a-z_]+)'\s*=>\s*array\(\s*'hint'\s*=>\s*([\s\S]*?),\s*'guide'\s*=>\s*([\s\S]*?),?\s*\),/g;
  section = '';
  for (const m of joined.matchAll(entryRe)) {
    if (m[1]) {
      section = m[1].replace(/\.$/, '');
      continue;
    }
    hints.push({ code: m[2], section, hint: phpExprToText(m[3]), guide: phpExprToText(m[4]) });
  }
  if (hints.length < 10) die('could not parse Mcpwp_Error_Hints::get_hint_map()');

  // Every other error code raised in the plugin, with its first literal message.
  const codes = scanErrorCodes(join(pluginDir, 'includes'), pluginDir);

  // Pro tools a Free install still lists (the call answers with the upgrade path).
  let listedOnFree = [];
  try {
    const st = read(join(pluginDir, 'includes/mcp/traits/trait-mcpwp-pro-tools-staging.php'));
    const body = phpFunctionBody(st, 'get_staging_pro_tools') || '';
    listedOnFree = [...new Set([...body.matchAll(/'(wp_[a-z_]+)'/g)].map((m) => m[1]))];
  } catch {
    listedOnFree = [];
  }

  // Tool name → plugin capability it needs (tools/list hides the tool without it).
  const requires = {};
  for (const f of ['includes/mcp/class-mcpwp-mcp-free-tools.php', 'includes/mcp/class-mcpwp-mcp-pro-tools.php']) {
    const body = phpFunctionBody(read(join(pluginDir, f)), 'get_required_capabilities') || '';
    for (const m of body.matchAll(/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/g)) requires[m[1]] = m[2];
  }

  const version = (/Version:\s*([0-9.]+)/.exec(read(join(pluginDir, 'mcpwp.php'))) || [])[1] || '';

  return {
    roles,
    categoryLabels,
    categoryDescriptions,
    discovery,
    adminTools,
    writeTools,
    writeVerb,
    publishGated,
    hints,
    codes,
    version,
    listedOnFree: new Set(listedOnFree),
    requires,
  };
}

// Render a PHP string expression from the hint map as readable text: string literals are
// kept, sprintf placeholders and runtime values become {placeholders}.
function phpExprToText(expr) {
  let e = expr.trim();
  if (e === "''" || e === '""') return '';
  const sprintf = /^sprintf\(\s*([\s\S]*)\)$/.exec(e);
  if (sprintf) {
    const parts = splitTopLevelArgs(sprintf[1]);
    let fmt = phpLiteral(parts[0]) ?? '';
    const args = parts.slice(1).map((a) => {
      const lit = phpLiteral(a);
      if (lit !== null) return lit;
      const v = /\$([a-z_]+)/.exec(a);
      const name = v ? v[1] : 'value';
      return {
        id: '{id}',
        param: '{param}',
        key: '{key}',
        field: '{field}',
        layout_mode: '{layout mode}',
        element_type: '{section or container}',
        seo_plugin: '{active SEO plugin}',
      }[name] || `{${name}}`;
    });
    let n = 0;
    fmt = fmt.replace(/%s/g, () => args[n++] ?? '{value}');
    return fmt;
  }
  const lit = phpLiteral(e);
  return lit === null ? e : lit;
}

function phpLiteral(s) {
  const t = s.trim();
  const m = /^'((?:[^'\\]|\\.)*)'$/.exec(t);
  if (m) return m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
  const d = /^"((?:[^"\\]|\\.)*)"$/.exec(t);
  if (d) return d[1].replace(/\\"/g, '"');
  return null;
}

function splitTopLevelArgs(s) {
  const out = [];
  let depth = 0;
  let cur = '';
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (c === "'" || c === '"') {
      const q = c;
      let j = i + 1;
      while (j < s.length && s[j] !== q) {
        if (s[j] === '\\') j++;
        j++;
      }
      cur += s.slice(i, j + 1);
      i = j;
      continue;
    }
    if (c === '(') depth++;
    if (c === ')') depth--;
    if (c === ',' && depth === 0) {
      out.push(cur);
      cur = '';
      continue;
    }
    cur += c;
  }
  if (cur.trim()) out.push(cur);
  return out;
}

function walkPhp(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    const st = statSync(p);
    if (st.isDirectory()) out.push(...walkPhp(p));
    else if (name.endsWith('.php')) out.push(p);
  }
  return out;
}

function scanErrorCodes(dir, pluginDir) {
  const codes = new Map();
  // new WP_Error( 'code', <msg> | error_response( 'code', <msg> | *_error_result( $id, 'code', <msg>
  const re =
    /(?:new\s+WP_Error|error_response|create_error|enhanced_error_response|tool_error_result|oauth_error_response)\s*\(\s*(?:\$[a-z_]+\s*,\s*)?'([a-z][a-z0-9_]+)'\s*,\s*(?:(?:sprintf|esc_html__|__|esc_html)\s*\(\s*)*(?:(?:sprintf|__)\s*\(\s*)?('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")?/g;
  for (const file of walkPhp(dir)) {
    const src = stripComments(read(file));
    for (const m of src.matchAll(re)) {
      const code = m[1];
      const msg = m[2] ? phpLiteral(m[2]) : '';
      const rel = relative(pluginDir, file);
      const entry = codes.get(code) || { code, messages: [], files: new Set() };
      if (msg && !entry.messages.includes(msg)) entry.messages.push(msg);
      entry.files.add(rel);
      codes.set(code, entry);
    }
  }
  return [...codes.values()].sort((a, b) => a.code.localeCompare(b.code));
}

// ---------------------------------------------------------------------------------------
// render
// ---------------------------------------------------------------------------------------

// Escape text for Markdown outside code spans: angle brackets (raw HTML) and, in tables,
// pipes. Code spans are left alone.
function md(text, { table = false } = {}) {
  const s = String(text ?? '');
  return s
    .split(/(`[^`]*`)/)
    .map((part, i) => {
      if (i % 2 === 1) return table ? part.replace(/\|/g, '\\|') : part;
      // Only a tag-like "<x" or "</" can become raw HTML in Markdown.
      let p = part.replace(/<(?=[A-Za-z\/!?])/g, '&lt;');
      if (table) p = p.replace(/\|/g, '\\|').replace(/\r?\n+/g, ' ');
      return p;
    })
    .join('');
}

function schemaType(s) {
  if (!s || typeof s !== 'object') return '';
  let t = Array.isArray(s.type) ? s.type.join(' or ') : s.type || '';
  if (!t && s.oneOf) t = s.oneOf.map(schemaType).join(' or ');
  if (!t && s.anyOf) t = s.anyOf.map(schemaType).join(' or ');
  if (t === 'array' && s.items) {
    const it = schemaType(s.items);
    t = it ? `array of ${it}` : 'array';
  }
  return t || 'any';
}

function paramNotes(s) {
  const notes = [];
  if (Array.isArray(s.enum)) notes.push(`One of: ${s.enum.map((v) => '`' + JSON.stringify(v).replace(/^"|"$/g, '') + '`').join(', ')}.`);
  if (s.default !== undefined) notes.push(`Default \`${JSON.stringify(s.default)}\`.`);
  if (s.minimum !== undefined || s.maximum !== undefined) {
    notes.push(`Range ${s.minimum ?? '…'}–${s.maximum ?? '…'}.`);
  }
  if (s.maxLength !== undefined) notes.push(`Max length ${s.maxLength}.`);
  if (s.maxItems !== undefined) notes.push(`Max ${s.maxItems} items.`);
  return notes.join(' ');
}

function toolScope(facts, name) {
  // mcpwp_call is listed as admin only for a wrapper the server cannot resolve; a valid
  // call is checked against the scope of the tool it names.
  if (name === 'mcpwp_call') return 'of the called tool';
  if (facts.adminTools.has(name)) return 'admin';
  if (facts.writeTools.has(name) || facts.writeVerb.test(name)) return 'write';
  return 'read';
}

function publishesByRoute(facts, tool) {
  if (!tool.route) return false;
  const r = tool.route.route.replace(/\{[^}]+\}/g, 'x');
  return tool.route.method !== 'GET' && facts.publishGated.some((p) => r === p || r.startsWith(p + '/'));
}

function rolesFor(facts, tool) {
  if (facts.discovery.includes(tool.name)) return 'every key (discovery tool)';
  const out = [];
  for (const [role, def] of Object.entries(facts.roles)) {
    if (role === 'custom') continue;
    if (role === 'admin' || def.categories.includes(tool.category)) out.push(role);
  }
  out.push(`custom with \`${tool.category}\``);
  return out.map((r) => (r.startsWith('custom') ? r : `\`${r}\``)).join(', ');
}

function annotationSummary(a) {
  const bits = [];
  bits.push(a.readOnlyHint ? 'read-only' : 'writes');
  if (!a.readOnlyHint) bits.push(a.destructiveHint ? 'destructive' : 'non-destructive');
  bits.push(a.idempotentHint ? 'idempotent' : 'not idempotent');
  bits.push(a.openWorldHint ? 'calls external services' : 'this site only');
  return bits.join(', ');
}

const GENERATED_NOTE = (facts, free, pro, extra = '') =>
  `<!-- GENERATED by scripts/gen-docs-reference.mjs in Digidinc/mcpwp from MCPWP ${facts.version || pro.plugin_version}. Do not edit by hand: re-run the generator (docs/RELEASE_CHECKLIST.md step 11). ${extra}-->`;

function renderToolsPages(facts, free, pro, outDir) {
  const freeNames = new Set(free.tools.map((t) => t.name));
  const byName = new Map();
  const tierOf = (name) => (facts.listedOnFree.has(name) ? 'Pro' : freeNames.has(name) ? 'Free' : 'Pro');
  for (const t of pro.tools) byName.set(t.name, { ...t, tier: tierOf(t.name) });
  for (const t of free.tools) if (!byName.has(t.name)) byName.set(t.name, { ...t, tier: 'Free' });
  const tools = [...byName.values()].sort((a, b) => a.name.localeCompare(b.name));

  const cats = new Map();
  for (const t of tools) {
    const c = t.category || 'uncategorised';
    if (!cats.has(c)) cats.set(c, []);
    cats.get(c).push(t);
  }
  const catOrder = [...cats.keys()].sort((a, b) => {
    const ka = Object.keys(facts.categoryLabels).indexOf(a);
    const kb = Object.keys(facts.categoryLabels).indexOf(b);
    return (ka === -1 ? 99 : ka) - (kb === -1 ? 99 : kb) || a.localeCompare(b);
  });

  const toolsDir = join(outDir, 'tools');
  // Replace every previously generated page; leave hand-written ones alone.
  mkdirSync(toolsDir, { recursive: true });
  for (const f of readdirSync(toolsDir)) {
    const p = join(toolsDir, f);
    if (f.endsWith('.md') && read(p).includes('GENERATED by scripts/gen-docs-reference.mjs')) rmSync(p);
  }

  const catLink = (c) => `/tools/${c}/`;
  const toolLink = (t) => `/tools/${t.category}/#${t.name}`;

  for (const c of catOrder) {
    const list = cats.get(c);
    const label = facts.categoryLabels[c] || c;
    const desc = facts.categoryDescriptions[c] || '';
    const nFree = list.filter((t) => t.tier === 'Free').length;
    const roleList = Object.entries(facts.roles)
      .filter(([r, d]) => r !== 'custom' && (r === 'admin' || d.categories.includes(c)))
      .map(([r]) => `\`${r}\``);
    const L = [];
    L.push('---');
    L.push(`title: "${label} tools"`);
    const roleWords = [...roleList.map((r) => r.replace(/`/g, '')), `custom with ${c}`].join(', ');
    L.push(`description: "The ${list.length} MCP tools in the ${c} category${desc ? ` (${desc.replace(/"/g, "'")})` : ''}, ${nFree} Free and ${list.length - nFree} Pro. Key roles that can call them: ${roleWords}. Each tool's parameters, annotations and REST route."`);
    L.push('sidebar:');
    L.push(`  label: "${label}"`);
    L.push(`  order: ${catOrder.indexOf(c) + 1}`);
    L.push('---');
    L.push('');
    L.push(GENERATED_NOTE(facts, free, pro));
    L.push('');
    L.push(`Category \`${c}\`${desc ? `: ${md(desc)}` : ''}. ${list.length} tools (${nFree} Free, ${list.length - nFree} Pro), generated from MCPWP ${pro.plugin_version}.`);
    L.push('');
    L.push(`**Key roles that reach this category:** ${roleList.join(', ')}, and a \`custom\` key granted \`${c}\`. A site admin can switch the whole category off in **WP Admin → MCPWP → Tools**, which hides it from every key. See [Keys, roles and scopes](/concepts/access/).`);
    L.push('');
    L.push('| Tool | Title | Tier | Min. scope | Read-only |');
    L.push('|------|-------|------|------------|-----------|');
    for (const t of list) {
      L.push(`| [\`${t.name}\`](#${t.name}) | ${md(t.title, { table: true })} | ${t.tier} | ${toolScope(facts, t.name)} | ${t.annotations.readOnlyHint ? 'yes' : 'no'} |`);
    }
    L.push('');
    for (const t of list) {
      L.push(`## ${t.name}`);
      L.push('');
      const scope = toolScope(facts, t.name);
      const facts1 = [
        `**${md(t.title)}**`,
        t.tier,
        `category \`${c}\``,
        `scope \`${scope}\`${publishesByRoute(facts, t) ? ' (`admin` when it sets status `publish`, `private` or `future`)' : ''}`,
      ];
      if (t.route) facts1.push(`REST \`${t.route.method} /wp-json/mcpwp/v1${t.route.route}\``);
      L.push(facts1.join(' · '));
      L.push('');
      L.push(md(t.description));
      L.push('');
      L.push(`- **Annotations:** ${annotationSummary(t.annotations)}.`);
      L.push(`- **Key roles:** ${rolesFor(facts, t)}.`);
      const props = t.inputSchema?.properties || {};
      const req = new Set(t.inputSchema?.required || []);
      const names = Object.keys(props);
      if (!names.length) {
        L.push('- **Parameters:** none.');
        L.push('');
      } else {
        L.push('');
        L.push('| Parameter | Type | Required | Meaning |');
        L.push('|-----------|------|----------|---------|');
        const ordered = [...names.filter((n) => req.has(n)), ...names.filter((n) => !req.has(n))];
        for (const n of ordered) {
          const s = props[n] || {};
          const meaning = [s.description || '', paramNotes(s)].filter(Boolean).join(' ');
          L.push(`| \`${n}\` | ${md(schemaType(s), { table: true })} | ${req.has(n) ? 'yes' : 'no'} | ${md(meaning, { table: true })} |`);
        }
        L.push('');
      }
      const ex = Array.isArray(t.inputSchema?.examples) ? t.inputSchema.examples[0] : null;
      if (ex) {
        L.push('Example arguments:');
        L.push('');
        L.push('```json');
        L.push(JSON.stringify(ex, null, 2));
        L.push('```');
        L.push('');
      }
    }
    writeFileSync(join(toolsDir, `${c}.md`), L.join('\n'));
  }

  // Overview.
  const nFreeAll = tools.filter((t) => t.tier === 'Free').length;
  const O = [];
  O.push('---');
  O.push('title: Tools Reference');
  O.push(`description: "Every MCPWP MCP tool (${tools.length} in ${pro.plugin_version}: ${nFreeAll} Free, ${tools.length - nFreeAll} Pro) by category, with tier, minimum key scope and read-only flag."`);
  O.push('---');
  O.push('');
  O.push(GENERATED_NOTE(facts, free, pro));
  O.push('');
  O.push(`MCPWP ${pro.plugin_version} has **${tools.length} tools**: ${nFreeAll} Free and ${tools.length - nFreeAll} Pro. A Free install lists the Free tools plus the ${facts.listedOnFree.size} staging tools (${[...facts.listedOnFree].map((n) => '`' + n + '`').join(', ')}), which answer with the upgrade path until Pro is active. Counts are from a test site with Elementor, Elementor Pro, WooCommerce, Yoast SEO, Contact Form 7, LearnPress and WP Events Manager active. Tools for a plugin that is not active are hidden from \`tools/list\`, and so are Pro tools on a Free install, so your site may show fewer.${missingNote(facts, tools)}`);
  O.push('');
  O.push('Tools are grouped by **category**. The category decides which key roles can call a tool ([Keys, roles and scopes](/concepts/access/)); the **scope** column is the minimum key scope (`read` < `write` < `admin`). A tool that publishes needs `admin` when it sets status `publish`, `private` or `future`.');
  O.push('');
  O.push('Not every tool is in the default list an agent sees. Under the `core` [tool profile](/concepts/tool-profiles/), an agent reaches the rest with `mcpwp_find_tools` and `mcpwp_call`.');
  O.push('');
  O.push('## Categories');
  O.push('');
  O.push('| Category | Tools | Free | Pro | Roles (besides `admin`) | What it covers |');
  O.push('|----------|------:|-----:|----:|-------------------------|----------------|');
  for (const c of catOrder) {
    const list = cats.get(c);
    const f = list.filter((t) => t.tier === 'Free').length;
    const roles = Object.entries(facts.roles)
      .filter(([r, d]) => r !== 'custom' && r !== 'admin' && d.categories.includes(c))
      .map(([r]) => r);
    O.push(`| [${facts.categoryLabels[c] || c}](${catLink(c)}) \`${c}\` | ${list.length} | ${f} | ${list.length - f} | ${roles.join(', ') || '—'} | ${md(facts.categoryDescriptions[c] || '', { table: true })} |`);
  }
  O.push('');
  O.push(`Every key, whatever its role, can call ${facts.discovery.map((d) => `\`${d}\``).join(', ')} (\`wp_get_site_context\` read-only).`);
  O.push('');
  O.push('## All tools');
  O.push('');
  O.push('| Tool | Category | Tier | Min. scope | Read-only |');
  O.push('|------|----------|------|------------|-----------|');
  for (const t of tools) {
    O.push(`| [\`${t.name}\`](${toolLink(t)}) | \`${t.category}\` | ${t.tier} | ${toolScope(facts, t.name)} | ${t.annotations.readOnlyHint ? 'yes' : 'no'} |`);
  }
  O.push('');
  writeFileSync(join(outDir, 'tools.md'), O.join('\n'));

  return { total: tools.length, free: nFreeAll, pro: tools.length - nFreeAll, categories: catOrder.map((c) => [c, cats.get(c).length]) };
}

function missingNote(facts, tools) {
  const have = new Set(tools.map((t) => t.name));
  const missing = Object.entries(facts.requires).filter(([n]) => !have.has(n));
  if (!missing.length) return '';
  return ` Not captured on that site, because their plugin or setup was absent: ${missing
    .map(([n, c]) => `\`${n}\` (needs \`${c}\`)`)
    .join(', ')}.`;
}

function renderErrors(facts, free, pro, outDir) {
  const hinted = new Set(facts.hints.map((h) => h.code));
  const E = [];
  E.push('---');
  E.push('title: Error codes');
  E.push(`description: "Every MCPWP error code: the ${facts.hints.length} codes with a built-in hint (meaning and fix), then every other code the plugin can return, with its message."`);
  E.push('---');
  E.push('');
  E.push(GENERATED_NOTE(facts, free, pro, 'Parsed statically from the PHP source: Mcpwp_Error_Hints has no REST endpoint. '));
  E.push('');
  E.push('## Where the code is');
  E.push('');
  E.push('- **REST:** the JSON body is `{"code": "...", "message": "...", "data": {"status": 403, "hint": "...", "guide": "..."}}`.');
  E.push('- **MCP `tools/call`:** a failed tool returns a result with `isError: true`. Read the code from `result.structuredContent.error.code`, not from the text. Errors in the JSON-RPC envelope itself (bad method, unsupported `MCP-Protocol-Version`) are JSON-RPC errors.');
  E.push('- `data.hint` tells an agent what to do next; `data.guide` names a `wp_get_guide` topic.');
  E.push('');
  E.push('Codes that concern access, approvals and limits are explained in context in [Keys, roles and scopes](/concepts/access/), [Approvals](/concepts/approvals/) and [Limits](/concepts/limits/).');
  E.push('');
  E.push('## Codes with a built-in hint');
  E.push('');
  E.push('These come from `Mcpwp_Error_Hints`. The hint is added to `data.hint` automatically. `{id}`, `{param}` and similar are filled in from the request.');
  E.push('');
  let section = null;
  for (const h of facts.hints) {
    if (h.section !== section) {
      section = h.section;
      E.push(`### ${section}`);
      E.push('');
    }
    E.push(`#### ${h.code}`);
    E.push('');
    const msgs = facts.codes.find((c) => c.code === h.code)?.messages || [];
    if (msgs.length === 1) E.push(`Message: "${md(msgs[0])}"`, '');
    if (msgs.length > 1) E.push(`Message: varies by route (${msgs.length} variants), for example "${md(msgs[0])}"`, '');
    E.push(`Fix: ${md(h.hint)}${h.guide ? ` Guide: \`${h.guide}\`.` : ''}`);
    E.push('');
  }
  E.push('## All other codes');
  E.push('');
  E.push('Every other error code raised in the plugin source, with the literal message it is raised with (a code raised in several places may have other messages too). Codes are stable; messages may change.');
  E.push('');
  E.push('| Code | Message | Raised in |');
  E.push('|------|---------|-----------|');
  for (const c of facts.codes) {
    if (hinted.has(c.code)) continue;
    const files = [...c.files].map((f) => f.replace(/^includes\//, '').replace(/\.php$/, '').replace(/class-mcpwp-|trait-mcpwp-/g, ''));
    const where = files.slice(0, 3).join(', ') + (files.length > 3 ? ` +${files.length - 3}` : '');
    const m = c.messages.length ? c.messages.slice(0, 3).map((x) => `"${x}"`).join(' / ') : '(built at runtime)';
    const more = c.messages.length > 3 ? ` (+${c.messages.length - 3} more)` : '';
    E.push(`| \`${c.code}\` | ${md(m, { table: true })}${more} | ${md(where, { table: true })} |`);
  }
  E.push('');
  mkdirSync(join(outDir, 'reference'), { recursive: true });
  writeFileSync(join(outDir, 'reference', 'errors.md'), E.join('\n'));
  return { hinted: facts.hints.length, total: facts.codes.length + facts.hints.filter((h) => !facts.codes.some((c) => c.code === h.code)).length };
}

function renderMcpSurface(facts, free, pro, outDir) {
  const R = [];
  R.push('---');
  R.push('title: MCP resources and prompts (generated)');
  R.push('description: "The MCP resources, resource templates and prompts an MCPWP site advertises, as listed by resources/list, resources/templates/list and prompts/list."');
  R.push('---');
  R.push('');
  R.push(GENERATED_NOTE(facts, free, pro));
  R.push('');
  R.push(`As advertised by MCPWP ${pro.plugin_version}. For what they are for, see [Resources and prompts](/concepts/resources-prompts/).`);
  R.push('');
  R.push('## Resources (`resources/list`)');
  R.push('');
  R.push('| URI | Name | Type | Description |');
  R.push('|-----|------|------|-------------|');
  for (const r of pro.resources) R.push(`| \`${r.uri}\` | ${md(r.name, { table: true })} | ${r.mimeType || ''} | ${md(r.description, { table: true })} |`);
  R.push('');
  R.push('## Resource templates (`resources/templates/list`)');
  R.push('');
  R.push('| URI template | Name | Type | Description |');
  R.push('|--------------|------|------|-------------|');
  for (const r of pro.resource_templates) R.push(`| \`${r.uriTemplate}\` | ${md(r.name, { table: true })} | ${r.mimeType || ''} | ${md(r.description, { table: true })} |`);
  R.push('');
  R.push('## Prompts (`prompts/list`)');
  R.push('');
  R.push('| Prompt | Arguments | Description |');
  R.push('|--------|-----------|-------------|');
  for (const p of pro.prompts) {
    const args = (p.arguments || []).map((a) => `\`${a.name}\`${a.required ? ' (required)' : ''}`).join(', ') || '—';
    R.push(`| \`${p.name}\` | ${args} | ${md(p.description, { table: true })} |`);
  }
  R.push('');
  writeFileSync(join(outDir, 'reference', 'mcp-surface.md'), R.join('\n'));
}

// REST routes that no MCP tool maps to. Kept here (not scraped) because they are few
// and each needs a sentence; the table below them is generated from the tool routes.
const NON_TOOL_ROUTES = [
  ['POST', '/mcp', 'MCP endpoint: JSON-RPC 2.0 (initialize, tools/list, tools/call, resources/*, prompts/*, ping). `?profile=core|full`.', 'per method'],
  ['POST', '/batch', 'Run up to 100 REST operations in one request; each is checked on its own.', 'write'],
  ['GET', '/openapi.json', 'OpenAPI 3.0 schema of the Free tools. Public: no key.', 'none'],
  ['POST', '/webmcp/call', 'WebMCP dispatch for the browser agent in wp-admin. Cookie + nonce only; refuses API keys.', 'WordPress login'],
  ['POST', '/approvals', 'Create an approval request directly.', 'write'],
  ['POST', '/approvals/{id}/resolve', 'Resolve an apply or rollback stuck in progress (`outcome`: completed / not_completed).', 'admin'],
  ['POST', '/approvals/purge-closed', 'Delete every closed approval now, with an audit entry each.', 'admin'],
  ['GET', '/action-log', 'The action log (writes made through MCPWP), filtered by the key\'s categories.', 'read'],
  ['POST', '/action-log/{log_id}/rollback', 'Undo one logged write from its snapshot.', 'admin'],
  ['POST', '/graph/rebuild', 'Restart the site-graph backfill.', 'admin'],
];

// The changelog page, from mcpwp/CHANGELOG.md, so it cannot lag the release.
function renderChangelog(facts, free, pro, pluginDir, outDir) {
  const src = read(join(pluginDir, 'CHANGELOG.md'));
  const lines = src.split('\n');
  const start = lines.findIndex((l) => /^## \[?\d/.test(l));
  const body = [];
  let fence = false;
  for (const l of lines.slice(start)) {
    if (/^```/.test(l)) fence = !fence;
    // Releases are already H2 and their sections H3, under the page's H1 title.
    body.push(fence ? l : md(l));
  }
  const releases = body.filter((l) => /^## \[?\d/.test(l)).length;
  const C = [];
  C.push('---');
  C.push('title: Changelog');
  C.push(`description: "MCPWP release notes, newest first (${releases} releases, latest ${pro.plugin_version}), including the Changed for existing API keys and Upgrade Notes sections."`);
  C.push('---');
  C.push('');
  C.push(GENERATED_NOTE(facts, free, pro, 'Copied from mcpwp/CHANGELOG.md. '));
  C.push('');
  C.push('Sections called **Changed for existing API keys** list what an existing key may now do differently; read them before updating a site with automations. This page is generated from the plugin\'s `mcpwp/CHANGELOG.md`.');
  C.push('');
  C.push(...body);
  writeFileSync(join(outDir, 'reference', 'changelog.md'), C.join('\n'));
  return releases;
}

function renderEndpoints(facts, free, pro, outDir) {
  const freeNames = new Set(free.tools.map((t) => t.name));
  const rows = new Map();
  for (const t of pro.tools) {
    if (!t.route) continue;
    const k = `${t.route.method} ${t.route.route}`;
    if (!rows.has(k)) rows.set(k, { method: t.route.method, route: t.route.route, tools: [] });
    rows.get(k).tools.push(t);
  }
  const groups = new Map();
  for (const r of rows.values()) {
    const g = r.route.split('/')[1] || '/';
    if (!groups.has(g)) groups.set(g, []);
    groups.get(g).push(r);
  }
  const L = [];
  L.push('---');
  L.push('title: All Endpoints');
  L.push(`description: "Every MCPWP REST route under /wp-json/mcpwp/v1 that an MCP tool maps to (${rows.size} in ${pro.plugin_version}), grouped by path, with the tool, tier and minimum scope, plus the routes no tool maps to."`);
  L.push('---');
  L.push('');
  L.push(GENERATED_NOTE(facts, free, pro));
  L.push('');
  L.push('Base URL: `https://YOUR-SITE.com/wp-json/mcpwp/v1`. Every route needs a credential ([Authentication](/reference/auth/)) except `/openapi.json`. The same role, category and scope checks apply over REST as over MCP ([Keys, roles and scopes](/concepts/access/)): the scope shown is the minimum, and writes that set status `publish`, `private` or `future` need `admin`.');
  L.push('');
  L.push('## Routes without a tool');
  L.push('');
  L.push('| Method | Route | Purpose | Scope |');
  L.push('|--------|-------|---------|-------|');
  for (const [m, r, d, sc] of NON_TOOL_ROUTES) L.push(`| ${m} | \`${r}\` | ${md(d, { table: true })} | ${sc} |`);
  L.push('');
  L.push('## Routes by path');
  L.push('');
  L.push('Each row is the route an MCP tool dispatches to. The tool name links to its parameters.');
  L.push('');
  for (const g of [...groups.keys()].sort()) {
    L.push(`### /${g}`);
    L.push('');
    L.push('| Method | Route | Tool | Tier | Scope |');
    L.push('|--------|-------|------|------|-------|');
    for (const r of groups.get(g).sort((a, b) => a.route.localeCompare(b.route) || a.method.localeCompare(b.method))) {
      const tools = r.tools.map((t) => `[\`${t.name}\`](/tools/${t.category}/#${t.name})`).join(', ');
      const tier = r.tools.every((t) => freeNames.has(t.name) && !facts.listedOnFree.has(t.name)) ? 'Free' : 'Pro';
      const scope = [...new Set(r.tools.map((t) => toolScope(facts, t.name)))].join(' / ');
      L.push(`| ${r.method} | \`${r.route}\` | ${tools} | ${tier} | ${scope} |`);
    }
    L.push('');
  }
  writeFileSync(join(outDir, 'reference', 'endpoints.md'), L.join('\n'));
  return rows.size;
}

function render() {
  const freeFile = arg('--free');
  const proFile = arg('--pro');
  const out = arg('--out');
  const pluginDir = arg('--plugin-dir', join(ROOT, 'mcpwp'));
  if (!freeFile || !proFile || !out) die('render needs --free FILE --pro FILE --out DIR');
  const free = JSON.parse(read(freeFile));
  const pro = JSON.parse(read(proFile));
  if (free.edition !== 'free' || pro.edition !== 'pro') die('--free must be a free snapshot and --pro a pro snapshot');
  if (pro.tool_count <= free.tool_count) die(`the Pro snapshot (${pro.tool_count}) has no more tools than Free (${free.tool_count}); was the agency marker in place?`);
  const facts = staticFacts(pluginDir);
  if (facts.version && pro.plugin_version && facts.version !== pro.plugin_version) {
    process.stderr.write(`warning: plugin source is ${facts.version} but the site ran ${pro.plugin_version}\n`);
  }
  const t = renderToolsPages(facts, free, pro, out);
  const e = renderErrors(facts, free, pro, out);
  renderMcpSurface(facts, free, pro, out);
  const nRoutes = renderEndpoints(facts, free, pro, out);
  const nReleases = renderChangelog(facts, free, pro, pluginDir, out);
  process.stdout.write(
    `tools: ${t.total} (${t.free} Free, ${t.pro} Pro) in ${t.categories.length} categories: ${t.categories.map(([c, n]) => `${c} ${n}`).join(', ')}\n` +
      `errors: ${e.hinted} hinted codes, ${facts.codes.length} codes scanned\n` +
      `endpoints: ${nRoutes} tool routes; changelog: ${nReleases} releases\n` +
      `wrote ${join(out, 'tools.md')}, ${join(out, 'tools')}/*.md, ${join(out, 'reference/errors.md')}, ${join(out, 'reference/mcp-surface.md')}\n`
  );
}

const cmd = process.argv[2];
if (cmd === 'fetch') {
  fetchSnapshot().catch((e) => die(e.message));
} else if (cmd === 'render') {
  render();
} else {
  die('usage: gen-docs-reference.mjs fetch|render [options] (see the header of this file)');
}
