#!/usr/bin/env node
// Sync the newest CHANGELOG.md release section into WP.org changelog surfaces:
//   - mcpwp/readme.txt  (== Changelog == block)
//   - version.json      (HTML "changelog" blob + "tested" field)
// Idempotent: re-running for an already-synced version is a no-op.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const CHANGELOG = join(ROOT, 'mcpwp', 'CHANGELOG.md');
const README = join(ROOT, 'mcpwp', 'readme.txt');
const VERSION_JSON = join(ROOT, 'version.json');

function arg(name) {
  const i = process.argv.indexOf(name);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : null;
}

const version = arg('--version');
if (!version || !/^\d+\.\d+\.\d+$/.test(version)) {
  console.error('Usage: sync-wporg-changelog.mjs --version X.Y.Z');
  process.exit(1);
}

// Map Keep-a-Changelog / release-please headings to conventional-commit prefixes.
const HEADING_TO_PREFIX = {
  'Features': 'feat',
  'Bug Fixes': 'fix',
  'Performance Improvements': 'perf',
  'Reverts': 'revert',
};

// 1. Extract the "## [X.Y.Z] ..." section from CHANGELOG.md (until next "## [" or EOF).
function extractSection(md, ver) {
  const escaped = ver.replace(/\./g, '\\.');
  const re = new RegExp(`^## \\[${escaped}\\][^\\n]*\\n([\\s\\S]*?)(?=^## \\[|\\Z)`, 'm');
  const m = md.match(re);
  return m ? m[1].trim() : null;
}

// 2. Parse "### Heading" + "* bullet ([hash](url))" into "prefix: description" lines.
function parseBullets(section) {
  const lines = [];
  let prefix = null;
  for (const raw of section.split('\n')) {
    const h = raw.match(/^###\s+(.+?)\s*$/);
    if (h) { prefix = HEADING_TO_PREFIX[h[1].trim()] || null; continue; }
    const b = raw.match(/^\*\s+(.+?)\s*$/);
    if (b && prefix) {
      // strip trailing "([hash](url))" commit link(s)
      const desc = b[1].replace(/\s*\(\[[0-9a-f]+\]\([^)]*\)\)\s*$/i, '').trim();
      if (desc) lines.push(`${prefix}: ${desc}`);
    }
  }
  return lines;
}

const changelogMd = readFileSync(CHANGELOG, 'utf8');
const section = extractSection(changelogMd, version);
if (!section) {
  console.error(`No "## [${version}]" section in CHANGELOG.md; nothing to sync.`);
  process.exit(0);
}
const bullets = parseBullets(section);
if (bullets.length === 0) {
  console.error(`Section for ${version} had no parseable bullets; nothing to sync.`);
  process.exit(0);
}

// ---- readme.txt ----
let readme = readFileSync(README, 'utf8');
const readmeBlockHeader = `= ${version} =`;
if (!readme.includes(`\n${readmeBlockHeader}\n`)) {
  const entry = `${readmeBlockHeader}\n` + bullets.map((l) => `* ${l}`).join('\n') + '\n\n';
  readme = readme.replace(/(== Changelog ==\n\n)/, `$1${entry}`);
  writeFileSync(README, readme, 'utf8');
  console.log(`readme.txt: inserted ${readmeBlockHeader}`);
} else {
  console.log(`readme.txt: ${readmeBlockHeader} already present, skipped`);
}

// Read Tested up to for version.json sync.
const testedMatch = readme.match(/^Tested up to:\s*(.+?)\s*$/m);
const tested = testedMatch ? testedMatch[1] : null;

// ---- version.json ----
const vjson = JSON.parse(readFileSync(VERSION_JSON, 'utf8'));
const joined = bullets.join('; ');
const newestTag = `<strong>${version}</strong> — ${joined}`;
if (!vjson.changelog.startsWith(`<strong>${version}</strong>`)) {
  // Demote the previous newest <strong>…</strong> to <h4>…</h4>, then prepend.
  const demoted = vjson.changelog.replace(/^<strong>(.*?)<\/strong>/, '<h4>$1</h4>');
  vjson.changelog = `${newestTag}${demoted}`;
  console.log(`version.json: prepended ${version} changelog entry`);
} else {
  console.log(`version.json: ${version} already newest, skipped changelog`);
}
if (tested && vjson.tested !== tested) {
  vjson.tested = tested;
  console.log(`version.json: tested -> ${tested}`);
}
writeFileSync(VERSION_JSON, JSON.stringify(vjson, null, 2) + '\n', 'utf8');

console.log('sync-wporg-changelog: done');
