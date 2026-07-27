#!/usr/bin/env node
// Sync the newest CHANGELOG.md release section into WP.org changelog surfaces:
//   - mcpwp/readme.txt  (== Changelog == block, == Upgrade Notice == block)
//   - version.json      (HTML "changelog" blob + "tested" field)
// Idempotent: re-running for an already-synced version is a no-op.
//
// A release sometimes changes what a site DOES, not just what it contains — a
// default that flips, a control that stops covering what it used to cover. That
// is the one thing an administrator must read before clicking update, and a
// changelog bullet is the wrong place for it: WordPress renders == Upgrade
// Notice == prominently on the updates screen and buries the changelog behind a
// modal.
//
// This block went unwritten from 3.4.1 to 3.9.3 — across two security releases,
// including the 3.7.2 write-to-admin escalation fix — because nothing generated
// it and remembering was the whole mechanism. An `### Upgrade Notes` section in
// CHANGELOG.md now carries through to it, so saying it once is enough.

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

// Heading whose bullets are upgrade notices rather than changelog entries.
const UPGRADE_HEADING = 'Upgrade Notes';

// 2b. Parse the "### Upgrade Notes" bullets, which carry no commit-prefix and
// are addressed to an administrator deciding whether to click update.
function parseUpgradeNotes(section) {
  const notes = [];
  let inside = false;
  for (const raw of section.split('\n')) {
    const h = raw.match(/^###\s+(.+?)\s*$/);
    if (h) { inside = h[1].trim() === UPGRADE_HEADING; continue; }
    if (!inside) continue;
    const b = raw.match(/^\*\s+(.+?)\s*$/);
    if (b) {
      const desc = b[1].replace(/\s*\(\[[0-9a-f]+\]\([^)]*\)\)\s*$/i, '').trim();
      if (desc) notes.push(desc);
    }
  }
  return notes;
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
const upgradeNotes = parseUpgradeNotes(section);
if (bullets.length === 0 && upgradeNotes.length === 0) {
  console.error(`Section for ${version} had no parseable bullets; nothing to sync.`);
  process.exit(0);
}

// ---- readme.txt ----
let readme = readFileSync(README, 'utf8');
const readmeBlockHeader = `= ${version} =`;

// "= 3.9.3 =" can legitimately appear under BOTH == Changelog == and
// == Upgrade Notice ==, so presence must be tested within the target section.
// A whole-file includes() check would let whichever section synced first
// suppress the other, silently and only for releases that carry both.
function sectionBody(txt, heading) {
  const m = txt.match(new RegExp(`^== ${heading} ==\\n\\n([\\s\\S]*?)(?=^== |\\Z)`, 'm'));
  return m ? m[1] : '';
}

function insertIntoSection(txt, heading, entry) {
  return txt.replace(new RegExp(`(== ${heading} ==\\n\\n)`), `$1${entry}`);
}

if (bullets.length > 0) {
  if (!sectionBody(readme, 'Changelog').includes(`${readmeBlockHeader}\n`)) {
    const entry = `${readmeBlockHeader}\n` + bullets.map((l) => `* ${l}`).join('\n') + '\n\n';
    readme = insertIntoSection(readme, 'Changelog', entry);
    console.log(`readme.txt: inserted ${readmeBlockHeader} into == Changelog ==`);
  } else {
    console.log(`readme.txt: ${readmeBlockHeader} already in == Changelog ==, skipped`);
  }
}

if (upgradeNotes.length > 0) {
  if (!sectionBody(readme, 'Upgrade Notice').includes(`${readmeBlockHeader}\n`)) {
    // WP.org renders one paragraph per version here, not a bullet list.
    const entry = `${readmeBlockHeader}\n${upgradeNotes.join(' ')}\n\n`;
    readme = insertIntoSection(readme, 'Upgrade Notice', entry);
    console.log(`readme.txt: inserted ${readmeBlockHeader} into == Upgrade Notice ==`);
  } else {
    console.log(`readme.txt: ${readmeBlockHeader} already in == Upgrade Notice ==, skipped`);
  }
}

writeFileSync(README, readme, 'utf8');

// Read Tested up to for version.json sync.
const testedMatch = readme.match(/^Tested up to:\s*(.+?)\s*$/m);
const tested = testedMatch ? testedMatch[1] : null;

// ---- version.json ----
// readme.txt keeps Markdown — WP.org renders it. version.json does not: its
// "changelog" is raw HTML dropped into the update-details modal, so **bold**
// and `code` arrive as literal asterisks and backticks. Convert on the way in.
function mdToHtml(s) {
  return s
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>');
}

const vjson = JSON.parse(readFileSync(VERSION_JSON, 'utf8'));
const joined = bullets.map(mdToHtml).join('; ');
// Self-hosted sites render this blob in the update-details modal — it is the
// only place they see release notes at all, so an upgrade notice leads.
const notice = upgradeNotes.length > 0
  ? `<p><strong>Heads up:</strong> ${upgradeNotes.map(mdToHtml).join(' ')}</p>`
  : '';
const newestTag = bullets.length > 0
  ? `${notice}<strong>${version}</strong> — ${joined}`
  : `${notice}<strong>${version}</strong>`;
// Both tests below have to tolerate a leading <p>…</p> notice: anchoring them
// on "^<strong>" would re-prepend this version on every re-run (breaking
// idempotency) and would leave the previous entry bolded (two "newest" entries).
const alreadyNewest = new RegExp(`^(?:<p>.*?</p>)?<strong>${version.replace(/\./g, '\\.')}</strong>`);
if (!alreadyNewest.test(vjson.changelog)) {
  // Demote the previous newest <strong>…</strong> to <h4>…</h4>, then prepend.
  const demoted = vjson.changelog.replace(
    /^((?:<p>.*?<\/p>)?)<strong>(.*?)<\/strong>/,
    '$1<h4>$2</h4>'
  );
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
