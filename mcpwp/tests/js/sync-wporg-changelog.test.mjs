import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  applyChangelogSync,
  renderChangelogFromEntries,
  upsertChangelogEntry,
} from '../../../scripts/lib/version-json-changelog.mjs';

describe('version-json-changelog', () => {
  it('renders a single newest entry with <strong>', () => {
    const html = renderChangelogFromEntries([
      { version: '3.14.16', changes: ['fix: auth: example (#1)'] },
      { version: '3.14.15', changes: ['fix: other (#2)'] },
    ]);
    assert.match(html, /^<strong>3\.14\.16<\/strong>/);
    assert.match(html, /<h4>3\.14\.15<\/h4>/);
    assert.doesNotMatch(html, /<strong>3\.14\.15<\/strong>/);
  });

  it('anchors upgrade notices after the version header', () => {
    const html = renderChangelogFromEntries([
      {
        version: '3.14.9',
        changes: ['fix: approvals (#1052)'],
        upgrade_notice: ['Queue moves to a table on upgrade.'],
      },
    ]);
    assert.match(html, /<strong>3\.14\.9<\/strong> — fix: approvals/);
    assert.match(html, /<p><strong>Heads up:<\/strong> Queue moves/);
  });

  it('applyChangelogSync keeps changelog HTML in sync with entries', () => {
    const vjson = { version: '3.14.16', changelog: '<strong>3.14.15</strong> — old' };
    applyChangelogSync(vjson, '3.14.16', ['feat: test (#99)'], []);
    assert.equal(vjson.changelog_entries[0].version, '3.14.16');
    assert.match(vjson.changelog, /^<strong>3\.14\.16<\/strong>/);
    assert.ok(vjson.changelog.includes('feat: test'));
  });

  it('upsert replaces an existing version instead of duplicating', () => {
    const entries = upsertChangelogEntry(
      [{ version: '3.14.16', changes: ['fix: old'] }],
      '3.14.16',
      ['fix: new'],
      []
    );
    assert.equal(entries.length, 1);
    assert.deepEqual(entries[0].changes, ['fix: new']);
  });
});