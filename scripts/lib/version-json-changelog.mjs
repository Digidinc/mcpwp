/**
 * Structured changelog helpers for version.json (#572).
 * HTML in "changelog" is derived from changelog_entries for backward compatibility.
 */

/** @typedef {{ version: string, changes: string[], upgrade_notice?: string[] }} ChangelogEntry */

export const VERSION_HEADER = /<strong>(\d+\.\d+\.\d+)<\/strong>/g;

export function mdToHtml(s) {
  return s
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>')
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/`([^`]+)`/g, '<code>$1</code>');
}

/**
 * Render the self-hosted update modal blob from structured entries (newest first).
 *
 * @param {ChangelogEntry[]} entries
 * @returns {string}
 */
export function renderChangelogFromEntries(entries) {
  if (!Array.isArray(entries) || entries.length === 0) {
    return '';
  }
  let html = '';
  entries.forEach((entry, index) => {
    const ver = entry.version;
    const joined = (entry.changes || []).map(mdToHtml).join('; ');
    const notice =
      entry.upgrade_notice && entry.upgrade_notice.length
        ? `<p><strong>Heads up:</strong> ${entry.upgrade_notice.map(mdToHtml).join(' ')}</p>`
        : '';
    const header = index === 0 ? `<strong>${ver}</strong>` : `<h4>${ver}</h4>`;
    const sep = joined ? ` — ${joined}` : '';
    html += `${header}${sep}${notice}`;
  });
  return html;
}

/**
 * @param {ChangelogEntry[]} entries
 * @param {string} version
 * @param {string[]} bullets
 * @param {string[]} upgradeNotes
 * @returns {ChangelogEntry[]}
 */
export function upsertChangelogEntry(entries, version, bullets, upgradeNotes) {
  const next = Array.isArray(entries) ? entries.filter((e) => e && e.version !== version) : [];
  const entry = { version, changes: bullets.slice() };
  if (upgradeNotes.length > 0) {
    entry.upgrade_notice = upgradeNotes.slice();
  }
  return [entry, ...next];
}

/**
 * Best-effort migration when only the legacy HTML blob exists.
 *
 * @param {string} html
 * @returns {ChangelogEntry[]}
 */
export function parseHtmlChangelogToEntries(html) {
  if (!html || typeof html !== 'string') {
    return [];
  }
  const re = /(?:<strong>|<h4>)(\d+\.\d+\.\d+)(?:<\/strong>|<\/h4>)/g;
  const hits = [];
  let m;
  while ((m = re.exec(html)) !== null) {
    hits.push({ index: m.index, version: m[1] });
  }
  if (hits.length === 0) {
    return [];
  }
  const entries = [];
  for (let i = 0; i < hits.length; i++) {
    const start = hits[i].index;
    const end = i + 1 < hits.length ? hits[i + 1].index : html.length;
    let body = html.slice(start, end);
    body = body.replace(/^<(strong|h4)>\d+\.\d+\.\d+<\/(strong|h4)>\s*(?:—\s*)?/, '');
    const noticeMatch = body.match(/<p><strong>Heads up:<\/strong>\s*([\s\S]*?)<\/p>/);
    let upgrade_notice = [];
    if (noticeMatch) {
      upgrade_notice = [noticeMatch[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()];
      body = body.replace(noticeMatch[0], '');
    }
    const changes = body
      .replace(/<h4>\d+\.\d+\.\d+<\/h4>/g, '')
      .split(';')
      .map((s) => s.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim())
      .filter(Boolean);
    entries.push({ version: hits[i].version, changes, ...(upgrade_notice.length ? { upgrade_notice } : {}) });
  }
  return entries;
}

/**
 * @param {object} vjson
 * @param {string} version
 * @param {string[]} bullets
 * @param {string[]} upgradeNotes
 */
export function applyChangelogSync(vjson, version, bullets, upgradeNotes) {
  let entries = Array.isArray(vjson.changelog_entries) ? vjson.changelog_entries : [];
  if (entries.length === 0 && typeof vjson.changelog === 'string' && vjson.changelog.trim()) {
    entries = parseHtmlChangelogToEntries(vjson.changelog);
  }
  entries = upsertChangelogEntry(entries, version, bullets, upgradeNotes);
  vjson.changelog_entries = entries;
  vjson.changelog = renderChangelogFromEntries(entries);
  return { staleDemoted: 0, alreadyPresent: entries.findIndex((e) => e.version === version) > 0 };
}