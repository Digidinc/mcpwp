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
import { applyChangelogSync, mdToHtml } from './lib/version-json-changelog.mjs';

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

// JavaScript has no \Z. In a regex it is an identity escape for the literal
// letter Z, so `(?=^## \[|\Z)` terminated a lazy body at the next section
// heading OR at the first capital Z in the text — whichever came first. On the
// real readme that silently truncated the Changelog body by 1,167 characters.
// `(?![\s\S])` is the actual end-of-input assertion and, unlike `$`, is not
// weakened to end-of-line by the `m` flag.
const END_OF_INPUT = '(?![\\s\\S])';

// 1. Extract the "## [X.Y.Z] ..." section from CHANGELOG.md (until next "## [" or EOF).
function extractSection(md, ver) {
  const escaped = ver.replace(/\./g, '\\.');
  const re = new RegExp(`^## \\[${escaped}\\][^\\n]*\\n([\\s\\S]*?)(?=^## \\[|${END_OF_INPUT})`, 'm');
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
  const m = txt.match(new RegExp(`^== ${heading} ==\\n\\n([\\s\\S]*?)(?=^== |${END_OF_INPUT})`, 'm'));
  return m ? m[1] : '';
}

function insertIntoSection(txt, heading, entry) {
  // String.replace returns the input unchanged when nothing matches. Returning
  // that silently, while the caller logs "inserted", would drop an upgrade
  // notice on any readme whose section header is missing or differently spaced
  // — the exact silent-omission failure this whole block exists to prevent.
  const out = txt.replace(new RegExp(`(== ${heading} ==\\n\\n)`), `$1${entry}`);
  if (out === txt) {
    console.error(`FAIL: readme.txt has no "== ${heading} ==" section — nothing was inserted.`);
    process.exit(1);
  }
  return out;
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
// Structured changelog_entries (#572) is the editable source; the legacy HTML
// "changelog" string is regenerated for consumers that still read the blob.
const vjson = JSON.parse(readFileSync(VERSION_JSON, 'utf8'));
const hadEntries = Array.isArray(vjson.changelog_entries) && vjson.changelog_entries.length > 0;
const beforeHtml = typeof vjson.changelog === 'string' ? vjson.changelog : '';
applyChangelogSync(vjson, version, bullets, upgradeNotes);
if (!hadEntries && beforeHtml) {
  console.log(`version.json: migrated legacy HTML changelog into changelog_entries (${vjson.changelog_entries.length} versions)`);
} else if (vjson.changelog_entries[0]?.version === version) {
  console.log(`version.json: upserted ${version} in changelog_entries and refreshed changelog HTML`);
}
if (tested && vjson.tested !== tested) {
  vjson.tested = tested;
  console.log(`version.json: tested -> ${tested}`);
}
writeFileSync(VERSION_JSON, JSON.stringify(vjson, null, 2) + '\n', 'utf8');

console.log('sync-wporg-changelog: done');
