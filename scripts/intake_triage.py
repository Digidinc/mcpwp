#!/usr/bin/env python3
"""Pull every MCPWP issue-intake source onto the org project board, triaged.

Sources (see docs/INTAKE.md for the full picture):
  1. GitHub issues on the repo that are not yet items on org project #2.
  2. Open Dependabot alerts, counted by severity.
  3. The "Update Channel Canary" workflow's latest conclusion.
  4. (optional, needs env) PostHog tool-error-code spikes.
  5. (public, no credentials needed) WordPress.org support forum RSS.
  6. (optional, needs env) Freemius support tickets.

Dry-run by default: nothing is written to GitHub unless --apply is passed.
Every run prints a Markdown summary, apply or not.

Usage:
    python3 scripts/intake_triage.py                  # dry run, real repo
    python3 scripts/intake_triage.py --apply           # write to the board
    python3 scripts/intake_triage.py --since 2026-09-01T00:00:00Z
"""

from __future__ import annotations

import argparse
import difflib
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from typing import Any, Iterable, Optional

DEFAULT_REPO = "Digidinc/mcpwp"
DEFAULT_OWNER = "Mumega-com"
DEFAULT_PROJECT = "2"
DEFAULT_STATE_FILE = Path.home() / ".cache" / "mcpwp-intake" / "state.json"
FORUM_FEED_URL = "https://wordpress.org/support/plugin/mumega-mcp/feed/"
CANARY_WORKFLOW = "Update Channel Canary"
JEV_SCRIPT = Path("/home/mumega/scripts/jev/jev.mjs")

# GitHub issues not yet on the board older than this, with no --since given
# and no prior state file, are left alone rather than dumped in bulk on a
# cold first run. A cold run still reports every other source in full.
DEFAULT_LOOKBACK_DAYS = 7

CATEGORIES = ("security", "bug", "feature", "setup", "duplicate")
DUPLICATE_THRESHOLD = 0.85

PRIORITY_BY_CATEGORY = {
    "security": "P1",
    "bug": "P1",
    "feature": "P2",
    "setup": "P2",
    "duplicate": "P2",
}
TRACK_BY_CATEGORY = {
    "security": "Reliability",
    "bug": "Reliability",
}
VERSION_OPTION = "3.14 Launch"

SECURITY_KEYWORDS = (
    "security", "vulnerability", "cve-", "exploit", "xss",
    "sql injection", "csrf", "privilege escalation", "auth bypass",
    "authentication bypass", "remote code execution", " rce ",
    "unauthenticated access", "credential leak", "secret exposed",
    "pre-auth",
)
BUG_KEYWORDS = (
    "bug", "broken", "crash", "crashes", "fails", "failing", "failure",
    "not working", "doesn't work", "does not work", "regression",
    "500 error", "404", "exception", "traceback", "error when", "silently",
)
SETUP_KEYWORDS = (
    "install", "installation", "setup", "set up", "onboarding", "activation",
    "activate", "configure", "configuration", "can't connect",
    "cannot connect", "connection failed", "getting started", "api key",
)
FEATURE_KEYWORDS = (
    "feature request", "feature:", "would be nice", "please add",
    "enhancement", "suggestion:", "request:", "add support", "it would help",
    "roadmap",
)

LABEL_CATEGORY = {
    "security": "security",
    "bug": "bug",
    "enhancement": "feature",
    "feature": "feature",
}


# --------------------------------------------------------------------------
# gh CLI wrapper
# --------------------------------------------------------------------------


def run_gh(args: list[str], input_text: Optional[str] = None, check: bool = True) -> str:
    """Run `gh` with GITHUB_TOKEN unset (a stray token breaks auth here)."""
    env = dict(os.environ)
    env.pop("GITHUB_TOKEN", None)
    result = subprocess.run(
        ["gh", *args],
        input=input_text,
        text=True,
        capture_output=True,
        env=env,
        check=False,
    )
    if check and result.returncode != 0:
        raise RuntimeError(f"gh {' '.join(args)} failed: {result.stderr.strip()}")
    return result.stdout


def gh_json(args: list[str]) -> Any:
    return json.loads(run_gh(args))


# --------------------------------------------------------------------------
# State file
# --------------------------------------------------------------------------


def default_state() -> dict:
    return {
        "last_run": None,
        "dependabot_counts": {},
        "forum_last_seen": None,
        "canary_last_conclusion": None,
    }


def load_state(path: Path) -> dict:
    try:
        with path.open("r", encoding="utf-8") as stream:
            data = json.load(stream)
    except (FileNotFoundError, json.JSONDecodeError):
        return default_state()
    state = default_state()
    state.update(data if isinstance(data, dict) else {})
    return state


def save_state(path: Path, state: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as stream:
        json.dump(state, stream, indent=2, sort_keys=True)
        stream.write("\n")


# --------------------------------------------------------------------------
# Classification (pure — no network, fully unit-testable)
# --------------------------------------------------------------------------


def _text_has_any(text: str, keywords: Iterable[str]) -> bool:
    lowered = text.lower()
    return any(keyword in lowered for keyword in keywords)


def find_duplicate(issue: dict, open_issues: list[dict], threshold: float = DUPLICATE_THRESHOLD) -> Optional[int]:
    """Return the number of a near-duplicate open issue, or None."""
    title = (issue.get("title") or "").strip().lower()
    if not title:
        return None
    best_number = None
    best_ratio = 0.0
    for other in open_issues:
        if other.get("number") == issue.get("number"):
            continue
        other_title = (other.get("title") or "").strip().lower()
        if not other_title:
            continue
        ratio = difflib.SequenceMatcher(None, title, other_title).ratio()
        if ratio >= threshold and ratio > best_ratio:
            best_ratio = ratio
            best_number = other.get("number")
    return best_number


def classify_rules(issue: dict, open_issues: list[dict]) -> tuple[str, Optional[int]]:
    """Rule-based category + duplicate target. Always returns a category."""
    labels = [label.get("name", "").lower() for label in issue.get("labels", []) if isinstance(label, dict)]
    title = issue.get("title") or ""
    body = issue.get("body") or ""
    haystack = f"{title}\n{body}"

    dup_of = find_duplicate(issue, open_issues)

    for label in labels:
        if label in LABEL_CATEGORY:
            category = LABEL_CATEGORY[label]
            # A labelled security/bug issue that is ALSO a near-duplicate
            # is still more useful triaged as "duplicate" — that is the
            # action the triager actually takes.
            return ("duplicate", dup_of) if dup_of is not None else (category, None)

    if _text_has_any(haystack, SECURITY_KEYWORDS):
        rules_category = "security"
    elif _text_has_any(haystack, BUG_KEYWORDS):
        rules_category = "bug"
    elif _text_has_any(haystack, SETUP_KEYWORDS):
        rules_category = "setup"
    elif _text_has_any(haystack, FEATURE_KEYWORDS):
        rules_category = "feature"
    else:
        rules_category = "feature"  # safest generic default for an unlabelled ask

    if dup_of is not None:
        return "duplicate", dup_of
    return rules_category, None


def jev_classify(issue: dict) -> Optional[tuple[str, float]]:
    """Optional Jev-assisted classification. None if unavailable or unsure.

    Requires TYPESAFE_API_KEY in env and node + the jev.mjs script present.
    Never raises — any failure here just means the rules-only result stands.
    """
    if not os.environ.get("TYPESAFE_API_KEY"):
        return None
    if not JEV_SCRIPT.exists():
        return None
    title = issue.get("title") or ""
    body = issue.get("body") or ""
    payload = {
        "state": f"Title: {title}\n\nBody:\n{body}",
        "questions": {
            "category": {
                "type": "choice",
                "instructions": "Classify this MCPWP GitHub issue for triage.",
                "criteria": {
                    "setup": "Install, activation, connection, or configuration problem",
                    "bug": "Something built is broken or behaves incorrectly",
                    "feature": "A request for new or changed behaviour",
                    "duplicate": "Substantially the same ask as another open issue",
                    "security": "A vulnerability, exploit, or credential exposure",
                },
            }
        },
    }
    try:
        result = subprocess.run(
            ["node", str(JEV_SCRIPT)],
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )
        if result.returncode == 2 or not result.stdout.strip():
            return None
        parsed = json.loads(result.stdout)
        answer = parsed.get("category") or parsed.get("results", {}).get("category")
        if not answer:
            return None
        choice = answer.get("choice")
        probabilities = answer.get("probabilities") or {}
        confidence = float(probabilities.get(choice, 0.0)) if choice else 0.0
        if choice not in CATEGORIES:
            return None
        return choice, confidence
    except (subprocess.SubprocessError, json.JSONDecodeError, ValueError, OSError):
        return None


def classify_issue(issue: dict, open_issues: list[dict], use_jev: bool = False, jev_confidence_floor: float = 0.6) -> dict:
    rules_category, dup_of = classify_rules(issue, open_issues)
    result = {
        "number": issue.get("number"),
        "title": issue.get("title"),
        "url": issue.get("url"),
        "category": rules_category,
        "duplicate_of": dup_of,
        "source": "rules",
    }
    if use_jev:
        jev_result = jev_classify(issue)
        if jev_result is not None:
            choice, confidence = jev_result
            if confidence >= jev_confidence_floor and choice != rules_category:
                result["category"] = choice
                result["source"] = f"jev({confidence:.2f}), rules said {rules_category}"
    return result


def fields_for_category(category: str) -> dict:
    return {
        "Status": "Todo",
        "Priority": PRIORITY_BY_CATEGORY.get(category, "P2"),
        "Track": TRACK_BY_CATEGORY.get(category),  # may be None -> not set
        "Version": VERSION_OPTION,
    }


# --------------------------------------------------------------------------
# Source 1: GitHub issues not yet on the board
# --------------------------------------------------------------------------


def fetch_open_issues(repo: str) -> list[dict]:
    return gh_json([
        "issue", "list", "-R", repo, "--state", "open", "--limit", "500",
        "--json", "number,title,body,labels,url,createdAt",
    ])


def fetch_tracked_issue_urls(owner: str, project: str) -> set[str]:
    items = gh_json([
        "project", "item-list", project, "--owner", owner,
        "--format", "json", "--limit", "500",
    ])
    urls = set()
    for item in items.get("items", []):
        content = item.get("content") or {}
        url = content.get("url")
        if url:
            urls.add(url)
    return urls


def select_new_issues(issues: list[dict], tracked_urls: set[str], since: Optional[datetime]) -> list[dict]:
    new_issues = []
    for issue in issues:
        if issue.get("url") in tracked_urls:
            continue
        if since is not None:
            created_raw = issue.get("createdAt")
            if created_raw:
                created = datetime.fromisoformat(created_raw.replace("Z", "+00:00"))
                if created < since:
                    continue
        new_issues.append(issue)
    return new_issues


# --------------------------------------------------------------------------
# Source 2: Dependabot alerts
# --------------------------------------------------------------------------


def count_alert_severities(alerts: list[dict]) -> dict:
    counts: dict = {}
    for alert in alerts:
        if alert.get("state") != "open":
            continue
        severity = (alert.get("security_advisory", {}).get("severity") or "unknown").lower()
        counts[severity] = counts.get(severity, 0) + 1
    return counts


def fetch_dependabot_alerts(repo: str) -> Optional[list[dict]]:
    try:
        raw = run_gh([
            "api", f"repos/{repo}/dependabot/alerts", "--paginate",
            "-q", ".",
        ])
    except RuntimeError:
        return None
    alerts: list[dict] = []
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        parsed = json.loads(line)
        if isinstance(parsed, list):
            alerts.extend(parsed)
        else:
            alerts.append(parsed)
    return alerts


# --------------------------------------------------------------------------
# Source 3: Update Channel Canary
# --------------------------------------------------------------------------


def fetch_canary_status(repo: str) -> Optional[dict]:
    try:
        runs = gh_json([
            "run", "list", "-R", repo, "--workflow", CANARY_WORKFLOW,
            "--limit", "3", "--json", "databaseId,conclusion,createdAt,event,url",
        ])
    except RuntimeError:
        return None
    if not runs:
        return {"latest_conclusion": None, "runs": []}
    return {"latest_conclusion": runs[0].get("conclusion"), "runs": runs}


# --------------------------------------------------------------------------
# Source 4: PostHog error-code spikes (optional, needs env)
# --------------------------------------------------------------------------


def fetch_posthog_error_spikes() -> Optional[dict]:
    api_key = os.environ.get("POSTHOG_API_KEY")
    project_id = os.environ.get("POSTHOG_PROJECT_ID")
    host = os.environ.get("POSTHOG_HOST", "https://us.posthog.com")
    if not api_key or not project_id:
        return None  # skipped: no credentials in env
    query = {
        "query": {
            "kind": "HogQLQuery",
            "query": (
                "SELECT properties.error_code AS error_code, count() AS n "
                "FROM events WHERE event = 'mcp_tool_called' "
                "AND properties.error_code IN ('scope_denied', 'pro_required', 'tool_error') "
                "AND timestamp > now() - INTERVAL 7 DAY "
                "GROUP BY error_code ORDER BY n DESC"
            ),
        }
    }
    request = urllib.request.Request(
        f"{host}/api/projects/{project_id}/query/",
        data=json.dumps(query).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            data = json.loads(response.read().decode("utf-8"))
    except (urllib.error.URLError, ValueError, TimeoutError) as exc:
        return {"error": str(exc)}
    rows = data.get("results", [])
    return {"error_code_counts": {row[0]: row[1] for row in rows if isinstance(row, list) and len(row) >= 2}}


# --------------------------------------------------------------------------
# Source 5: WordPress.org support forum RSS (public, no credentials)
# --------------------------------------------------------------------------


def parse_forum_feed(xml_text: str, since: Optional[datetime]) -> list[dict]:
    """Pure parser: RSS text -> new topics after `since`. No network here."""
    root = ET.fromstring(xml_text)
    topics = []
    for item in root.findall("./channel/item"):
        title = (item.findtext("title") or "").strip()
        link = (item.findtext("link") or "").strip()
        pub_date_raw = (item.findtext("pubDate") or "").strip()
        pub_date = None
        if pub_date_raw:
            try:
                pub_date = parsedate_to_datetime(pub_date_raw)
                if pub_date.tzinfo is None:
                    pub_date = pub_date.replace(tzinfo=timezone.utc)
            except (TypeError, ValueError):
                pub_date = None
        if since is not None and pub_date is not None and pub_date < since:
            continue
        topics.append({
            "title": title,
            "link": link,
            "pub_date": pub_date_raw,
            "pub_date_iso": pub_date.isoformat() if pub_date is not None else None,
        })
    return topics


FORUM_FEED_MAX_BYTES = 2 * 1024 * 1024  # bound worst case before it ever reaches the XML parser


def fetch_forum_topics(since: Optional[datetime]) -> Optional[list[dict]]:
    # FORUM_FEED_URL is a fixed, hardcoded wordpress.org host (not user input),
    # and the read is capped, but xml.etree still has no entity-expansion
    # hardening of its own — this is the stdlib-only tradeoff called for by
    # the brief. Swap in defusedxml.ElementTree.fromstring here first if this
    # source is ever pointed at anything less trusted than wordpress.org.
    try:
        with urllib.request.urlopen(FORUM_FEED_URL, timeout=15) as response:
            xml_text = response.read(FORUM_FEED_MAX_BYTES).decode("utf-8", errors="replace")
    except (urllib.error.URLError, TimeoutError):
        return None
    try:
        return parse_forum_feed(xml_text, since)
    except ET.ParseError:
        return None


# --------------------------------------------------------------------------
# Source 6: Freemius tickets (optional, needs env)
# --------------------------------------------------------------------------


def fetch_freemius_tickets() -> Optional[dict]:
    token = os.environ.get("FREEMIUS_BEARER_TOKEN")
    product_id = os.environ.get("FREEMIUS_PRODUCT_ID")
    if not token or not product_id:
        return None  # skipped: no credentials in env
    # Freemius's support-ticket surface is not wired end-to-end yet — this is
    # a best-effort probe so the run degrades to "unavailable" instead of
    # crashing once someone confirms the real endpoint/shape.
    request = urllib.request.Request(
        f"https://api.freemius.com/v1/products/{product_id}/tickets.json",
        headers={"Authorization": f"Bearer {token}"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            data = json.loads(response.read().decode("utf-8"))
    except (urllib.error.URLError, ValueError, TimeoutError) as exc:
        return {"error": str(exc)}
    return {"tickets": data if isinstance(data, list) else data.get("tickets", [])}


# --------------------------------------------------------------------------
# Digest decision (pure)
# --------------------------------------------------------------------------


def digest_is_needed(prev_counts: dict, new_counts: Optional[dict], new_forum_topics: Optional[list[dict]], have_prior_state: bool) -> bool:
    if not have_prior_state:
        return False  # nothing "new" is knowable on a cold run
    dependabot_grew = False
    if new_counts is not None:
        for severity, count in new_counts.items():
            if count > prev_counts.get(severity, 0):
                dependabot_grew = True
                break
    forum_has_new = bool(new_forum_topics)
    return dependabot_grew or forum_has_new


def build_digest_body(dependabot_counts: Optional[dict], forum_topics: Optional[list[dict]], canary: Optional[dict]) -> str:
    lines = ["Automated intake digest — new since the last run.", ""]
    lines.append("### Dependabot alerts (open, by severity)")
    if dependabot_counts is None:
        lines.append("_unavailable (no repo access from this token)_")
    elif not dependabot_counts:
        lines.append("None open.")
    else:
        for severity, count in sorted(dependabot_counts.items(), key=lambda kv: -kv[1]):
            lines.append(f"- **{severity}**: {count}")
    lines.append("")
    lines.append("### WordPress.org forum — new topics")
    if not forum_topics:
        lines.append("None.")
    else:
        for topic in forum_topics:
            lines.append(f"- [{topic['title']}]({topic['link']}) — {topic['pub_date']}")
    lines.append("")
    lines.append("### Update Channel Canary")
    if canary is None:
        lines.append("_unavailable_")
    else:
        lines.append(f"Latest run: **{canary.get('latest_conclusion') or 'unknown'}**")
    return "\n".join(lines)


# --------------------------------------------------------------------------
# Apply: write to the project board / open the digest issue
# --------------------------------------------------------------------------


def resolve_project_fields(owner: str, project: str) -> tuple[str, dict]:
    fields = gh_json(["project", "field-list", project, "--owner", owner, "--format", "json"])
    project_id = gh_json(["project", "view", project, "--owner", owner, "--format", "json"])["id"]
    by_name = {}
    for field_def in fields.get("fields", []):
        options = {opt["name"]: opt["id"] for opt in field_def.get("options", [])}
        by_name[field_def["name"]] = {"id": field_def["id"], "options": options}
    return project_id, by_name


def add_issue_to_project(owner: str, project: str, url: str) -> str:
    item = gh_json(["project", "item-add", project, "--owner", owner, "--url", url, "--format", "json"])
    return item["id"]


def set_project_field(project_id: str, item_id: str, field: dict, option_name: str) -> None:
    option_id = field["options"].get(option_name)
    if option_id is None:
        raise RuntimeError(f"Unknown option {option_name!r} for field")
    run_gh([
        "project", "item-edit",
        "--id", item_id,
        "--project-id", project_id,
        "--field-id", field["id"],
        "--single-select-option-id", option_id,
    ])


def apply_new_issues(owner: str, project: str, classifications: list[dict]) -> list[str]:
    project_id, field_defs = resolve_project_fields(owner, project)
    applied = []
    for entry in classifications:
        item_id = add_issue_to_project(owner, project, entry["url"])
        for field_name, option_name in fields_for_category(entry["category"]).items():
            if option_name is None:
                continue
            field = field_defs.get(field_name)
            if field is None:
                continue
            set_project_field(project_id, item_id, field, option_name)
        applied.append(entry["url"])
    return applied


def ensure_label(repo: str, name: str) -> None:
    try:
        existing = gh_json(["label", "list", "-R", repo, "--search", name, "--json", "name"])
    except RuntimeError:
        existing = []
    if any(label.get("name") == name for label in existing):
        return
    run_gh(["label", "create", name, "-R", repo, "--color", "5319e7",
            "--description", "Automated issue-intake digest"], check=False)


def create_digest_issue(repo: str, body: str) -> str:
    ensure_label(repo, "intake")
    title = f"Intake digest: {datetime.now(timezone.utc):%Y-%m-%d}"
    output = run_gh(["issue", "create", "-R", repo, "--title", title, "--body", body, "--label", "intake"])
    return output.strip()


# --------------------------------------------------------------------------
# Summary
# --------------------------------------------------------------------------


def build_summary(
    classifications: list[dict],
    dependabot_counts: Optional[dict],
    canary: Optional[dict],
    posthog: Optional[dict],
    forum_topics: Optional[list[dict]],
    freemius: Optional[dict],
    applied: bool,
) -> str:
    lines = ["# Issue intake — triage summary", ""]

    lines.append("## GitHub issues not yet on the board")
    if not classifications:
        lines.append("None found.")
    else:
        lines.append("| # | Title | Category | Priority | Track | Dup of |")
        lines.append("|---|---|---|---|---|---|")
        for entry in classifications:
            fields = fields_for_category(entry["category"])
            dup = f"#{entry['duplicate_of']}" if entry.get("duplicate_of") else "—"
            title = (entry["title"] or "").replace("|", "\\|")
            lines.append(
                f"| [#{entry['number']}]({entry['url']}) | {title} | {entry['category']} "
                f"| {fields['Priority']} | {fields['Track'] or '—'} | {dup} |"
            )
        if applied:
            lines.append("")
            lines.append(f"_Added {len(classifications)} issue(s) to the board with Status=Todo, Version={VERSION_OPTION}._")
        else:
            lines.append("")
            lines.append("_Dry run — nothing written. Re-run with `--apply` to add these to the board._")

    lines.append("")
    lines.append("## Dependabot alerts (open, by severity)")
    if dependabot_counts is None:
        lines.append("_unavailable (no repo/security access from this token)_")
    elif not dependabot_counts:
        lines.append("None open.")
    else:
        for severity, count in sorted(dependabot_counts.items(), key=lambda kv: -kv[1]):
            lines.append(f"- **{severity}**: {count}")

    lines.append("")
    lines.append("## Update Channel Canary")
    if canary is None:
        lines.append("_unavailable_")
    else:
        lines.append(f"Latest run conclusion: **{canary.get('latest_conclusion') or 'unknown'}**")

    lines.append("")
    lines.append("## PostHog tool-error-code spikes")
    if posthog is None:
        lines.append("_skipped (no POSTHOG_API_KEY / POSTHOG_PROJECT_ID in env)_")
    elif "error" in posthog:
        lines.append(f"_query failed: {posthog['error']}_")
    else:
        counts = posthog.get("error_code_counts", {})
        if not counts:
            lines.append("No scope_denied / pro_required / tool_error events in the last 7 days.")
        else:
            for code, count in counts.items():
                lines.append(f"- **{code}**: {count}")

    lines.append("")
    lines.append("## WordPress.org forum — new topics")
    if forum_topics is None:
        lines.append("_forum feed unreachable this run_")
    elif not forum_topics:
        lines.append("None.")
    else:
        for topic in forum_topics:
            lines.append(f"- [{topic['title']}]({topic['link']}) — {topic['pub_date']}")

    lines.append("")
    lines.append("## Freemius tickets")
    if freemius is None:
        lines.append("_skipped (no FREEMIUS_BEARER_TOKEN / FREEMIUS_PRODUCT_ID in env)_")
    elif "error" in freemius:
        lines.append(f"_query failed: {freemius['error']}_")
    else:
        lines.append(f"{len(freemius.get('tickets', []))} ticket(s) returned.")

    return "\n".join(lines)


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------


def parse_since(value: Optional[str], state: dict) -> Optional[datetime]:
    if value:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    if state.get("last_run"):
        return datetime.fromisoformat(state["last_run"])
    return datetime.now(timezone.utc) - timedelta(days=DEFAULT_LOOKBACK_DAYS)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--repo", default=DEFAULT_REPO)
    parser.add_argument("--owner", default=DEFAULT_OWNER)
    parser.add_argument("--project", default=DEFAULT_PROJECT)
    parser.add_argument("--since", default=None, help="ISO-8601 timestamp; defaults to state file's last_run, else 7 days ago")
    parser.add_argument("--state-file", type=Path, default=DEFAULT_STATE_FILE)
    parser.add_argument("--apply", action="store_true", help="write to the board / open the digest issue")
    parser.add_argument("--use-jev", action="store_true", help="allow Jev to override the rules-based category when confident")
    parser.add_argument("--no-forum", action="store_true", help="skip the WordPress.org forum RSS source")
    args = parser.parse_args()

    state = load_state(args.state_file)
    since = parse_since(args.since, state)
    have_prior_state = state.get("last_run") is not None

    try:
        open_issues = fetch_open_issues(args.repo)
    except RuntimeError as exc:
        print(f"error: could not list issues on {args.repo}: {exc}", file=sys.stderr)
        return 1

    try:
        tracked_urls = fetch_tracked_issue_urls(args.owner, args.project)
    except RuntimeError as exc:
        print(f"error: could not list project #{args.project} items: {exc}", file=sys.stderr)
        return 1

    new_issues = select_new_issues(open_issues, tracked_urls, since)
    classifications = [classify_issue(issue, open_issues, use_jev=args.use_jev) for issue in new_issues]

    dependabot_alerts = fetch_dependabot_alerts(args.repo)
    dependabot_counts = count_alert_severities(dependabot_alerts) if dependabot_alerts is not None else None

    canary = fetch_canary_status(args.repo)
    posthog = fetch_posthog_error_spikes()
    forum_topics = None if args.no_forum else fetch_forum_topics(
        datetime.fromisoformat(state["forum_last_seen"]) if state.get("forum_last_seen") else since
    )
    freemius = fetch_freemius_tickets()

    applied = False
    if args.apply:
        if classifications:
            apply_new_issues(args.owner, args.project, classifications)
            applied = True
        if digest_is_needed(state.get("dependabot_counts", {}), dependabot_counts, forum_topics, have_prior_state):
            body = build_digest_body(dependabot_counts, forum_topics, canary)
            create_digest_issue(args.repo, body)

    print(build_summary(classifications, dependabot_counts, canary, posthog, forum_topics, freemius, applied))

    if args.apply:
        new_state = {
            "last_run": datetime.now(timezone.utc).isoformat(),
            "dependabot_counts": dependabot_counts if dependabot_counts is not None else state.get("dependabot_counts", {}),
            "forum_last_seen": (
                max((t["pub_date_iso"] for t in forum_topics if t.get("pub_date_iso")), default=state.get("forum_last_seen"))
                if forum_topics else state.get("forum_last_seen")
            ),
            "canary_last_conclusion": canary.get("latest_conclusion") if canary else state.get("canary_last_conclusion"),
        }
        save_state(args.state_file, new_state)

    return 0


if __name__ == "__main__":
    sys.exit(main())
