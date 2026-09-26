"""Unit tests for the issue-intake classifier, dedupe, and state/digest logic.

These exercise the pure functions directly (no `gh`, no network) — the
orchestration in main() shells out to `gh` and is covered instead by a
real, read-only dry run (see docs/INTAKE.md).
"""

import importlib.util
import json
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "intake_triage.py"

spec = importlib.util.spec_from_file_location("intake_triage", MODULE_PATH)
intake = importlib.util.module_from_spec(spec)
sys.modules["intake_triage"] = intake
spec.loader.exec_module(intake)


def make_issue(number, title, body="", labels=None, created_at="2026-09-20T00:00:00Z"):
    return {
        "number": number,
        "title": title,
        "body": body,
        "labels": [{"name": label} for label in (labels or [])],
        "url": f"https://github.com/Mumega-com/mcpwp/issues/{number}",
        "createdAt": created_at,
    }


class ClassifyRulesTest(unittest.TestCase):
    def test_security_label_wins(self):
        issue = make_issue(1, "Something", labels=["security"])
        category, dup = intake.classify_rules(issue, [issue])
        self.assertEqual("security", category)
        self.assertIsNone(dup)

    def test_security_keyword_in_body(self):
        issue = make_issue(2, "Odd behaviour", body="This looks like a pre-auth vulnerability in the REST route.")
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("security", category)

    def test_bug_label_wins(self):
        issue = make_issue(3, "Some title", labels=["bug"])
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("bug", category)

    def test_bug_keyword_in_title(self):
        issue = make_issue(4, "Elementor save fails with a 500 error")
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("bug", category)

    def test_setup_keyword(self):
        issue = make_issue(5, "Cannot connect after install", body="Followed the setup guide, api key still rejected.")
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("setup", category)

    def test_feature_keyword(self):
        issue = make_issue(6, "Feature request: bulk export", body="Would be nice to export all pages at once.")
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("feature", category)

    def test_default_fallback_is_feature(self):
        issue = make_issue(7, "Just a thought", body="No strong signal here at all.")
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("feature", category)

    def test_enhancement_label_maps_to_feature(self):
        issue = make_issue(8, "Something", labels=["enhancement"])
        category, _ = intake.classify_rules(issue, [issue])
        self.assertEqual("feature", category)


class DuplicateDetectionTest(unittest.TestCase):
    def test_near_duplicate_title_flagged(self):
        target = make_issue(10, "Elementor save fails on pages with a container layout")
        existing = make_issue(11, "Elementor save fails on pages with a container layout mode")
        category, dup = intake.classify_rules(target, [target, existing])
        self.assertEqual("duplicate", category)
        self.assertEqual(11, dup)

    def test_ignores_self_when_scanning_for_duplicates(self):
        issue = make_issue(12, "Exact same title here")
        # Only the issue itself is in the pool — must not match itself.
        dup = intake.find_duplicate(issue, [issue])
        self.assertIsNone(dup)

    def test_below_threshold_not_flagged(self):
        a = make_issue(13, "Media upload from URL times out")
        b = make_issue(14, "Menu items do not reorder on save")
        dup = intake.find_duplicate(a, [a, b])
        self.assertIsNone(dup)

    def test_picks_best_match_among_several(self):
        target = make_issue(20, "Screenshot endpoint returns a blank image")
        close = make_issue(21, "Screenshot endpoint returns a blank image sometimes")
        far = make_issue(22, "Totally unrelated menu bug")
        dup = intake.find_duplicate(target, [target, close, far])
        self.assertEqual(21, dup)


class FieldsForCategoryTest(unittest.TestCase):
    def test_security_is_p1_reliability(self):
        fields = intake.fields_for_category("security")
        self.assertEqual("P1", fields["Priority"])
        self.assertEqual("Reliability", fields["Track"])
        self.assertEqual("Todo", fields["Status"])
        self.assertEqual(intake.VERSION_OPTION, fields["Version"])

    def test_bug_is_p1_reliability(self):
        fields = intake.fields_for_category("bug")
        self.assertEqual("P1", fields["Priority"])
        self.assertEqual("Reliability", fields["Track"])

    def test_feature_is_p2_no_track(self):
        fields = intake.fields_for_category("feature")
        self.assertEqual("P2", fields["Priority"])
        self.assertIsNone(fields["Track"])

    def test_setup_is_p2_no_track(self):
        fields = intake.fields_for_category("setup")
        self.assertEqual("P2", fields["Priority"])
        self.assertIsNone(fields["Track"])


class SelectNewIssuesTest(unittest.TestCase):
    def test_filters_already_tracked(self):
        tracked = make_issue(30, "Already on the board")
        untracked = make_issue(31, "Not on the board yet")
        result = intake.select_new_issues([tracked, untracked], {tracked["url"]}, since=None)
        self.assertEqual([untracked], result)

    def test_filters_by_since(self):
        old = make_issue(32, "Old issue", created_at="2020-01-01T00:00:00Z")
        recent = make_issue(33, "Recent issue", created_at="2026-09-25T00:00:00Z")
        since = datetime(2026, 1, 1, tzinfo=timezone.utc)
        result = intake.select_new_issues([old, recent], set(), since=since)
        self.assertEqual([recent], result)

    def test_since_none_keeps_everything_untracked(self):
        a = make_issue(34, "A", created_at="2010-01-01T00:00:00Z")
        result = intake.select_new_issues([a], set(), since=None)
        self.assertEqual([a], result)


class DependabotCountingTest(unittest.TestCase):
    def test_counts_open_alerts_by_severity(self):
        alerts = [
            {"state": "open", "security_advisory": {"severity": "high"}},
            {"state": "open", "security_advisory": {"severity": "high"}},
            {"state": "open", "security_advisory": {"severity": "medium"}},
            {"state": "fixed", "security_advisory": {"severity": "high"}},
        ]
        counts = intake.count_alert_severities(alerts)
        self.assertEqual({"high": 2, "medium": 1}, counts)

    def test_no_open_alerts_yields_empty_dict(self):
        self.assertEqual({}, intake.count_alert_severities([]))

    def test_missing_severity_falls_back_to_unknown(self):
        alerts = [{"state": "open", "security_advisory": {}}]
        counts = intake.count_alert_severities(alerts)
        self.assertEqual({"unknown": 1}, counts)


class ForumFeedParsingTest(unittest.TestCase):
    FEED_TEMPLATE = """<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
<title>WordPress.org Forums</title>
{items}
</channel></rss>"""

    ITEM_TEMPLATE = """<item>
<title>{title}</title>
<link>{link}</link>
<pubDate>{pub_date}</pubDate>
</item>"""

    def test_empty_feed_returns_no_topics(self):
        xml_text = self.FEED_TEMPLATE.format(items="")
        topics = intake.parse_forum_feed(xml_text, since=None)
        self.assertEqual([], topics)

    def test_topics_after_since_are_kept(self):
        xml_text = self.FEED_TEMPLATE.format(items=self.ITEM_TEMPLATE.format(
            title="Cannot connect API key",
            link="https://wordpress.org/support/topic/example/",
            pub_date="Fri, 26 Sep 2026 10:00:00 +0000",
        ))
        since = datetime(2026, 9, 1, tzinfo=timezone.utc)
        topics = intake.parse_forum_feed(xml_text, since)
        self.assertEqual(1, len(topics))
        self.assertEqual("Cannot connect API key", topics[0]["title"])

    def test_topics_before_since_are_dropped(self):
        xml_text = self.FEED_TEMPLATE.format(items=self.ITEM_TEMPLATE.format(
            title="Old topic",
            link="https://wordpress.org/support/topic/old/",
            pub_date="Wed, 01 Jan 2020 10:00:00 +0000",
        ))
        since = datetime(2026, 1, 1, tzinfo=timezone.utc)
        topics = intake.parse_forum_feed(xml_text, since)
        self.assertEqual([], topics)

    def test_since_none_keeps_all_topics(self):
        xml_text = self.FEED_TEMPLATE.format(items=self.ITEM_TEMPLATE.format(
            title="Any topic",
            link="https://wordpress.org/support/topic/any/",
            pub_date="Wed, 01 Jan 2020 10:00:00 +0000",
        ))
        topics = intake.parse_forum_feed(xml_text, since=None)
        self.assertEqual(1, len(topics))


class StateFileTest(unittest.TestCase):
    def test_missing_file_returns_defaults(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "does-not-exist" / "state.json"
            state = intake.load_state(path)
            self.assertIsNone(state["last_run"])
            self.assertEqual({}, state["dependabot_counts"])

    def test_roundtrip(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            written = {
                "last_run": "2026-09-26T00:00:00+00:00",
                "dependabot_counts": {"high": 3},
                "forum_last_seen": "2026-09-20T00:00:00+00:00",
                "canary_last_conclusion": "success",
            }
            intake.save_state(path, written)
            read_back = intake.load_state(path)
            self.assertEqual(written, read_back)

    def test_corrupt_file_falls_back_to_defaults(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            path.write_text("not json", encoding="utf-8")
            state = intake.load_state(path)
            self.assertIsNone(state["last_run"])


class DigestNeededTest(unittest.TestCase):
    def test_cold_run_never_needs_a_digest(self):
        needed = intake.digest_is_needed({}, {"high": 5}, [{"title": "x"}], have_prior_state=False)
        self.assertFalse(needed)

    def test_dependabot_growth_triggers_digest(self):
        needed = intake.digest_is_needed({"high": 2}, {"high": 3}, [], have_prior_state=True)
        self.assertTrue(needed)

    def test_stable_dependabot_and_no_forum_skips_digest(self):
        needed = intake.digest_is_needed({"high": 2}, {"high": 2}, [], have_prior_state=True)
        self.assertFalse(needed)

    def test_new_forum_topics_trigger_digest_even_without_dependabot_growth(self):
        needed = intake.digest_is_needed({"high": 2}, {"high": 2}, [{"title": "new topic"}], have_prior_state=True)
        self.assertTrue(needed)

    def test_dependabot_unavailable_does_not_crash(self):
        needed = intake.digest_is_needed({"high": 2}, None, None, have_prior_state=True)
        self.assertFalse(needed)


class ClassifyIssueTest(unittest.TestCase):
    def test_rules_only_by_default(self):
        issue = make_issue(40, "Feature request: dark mode")
        entry = intake.classify_issue(issue, [issue], use_jev=False)
        self.assertEqual("feature", entry["category"])
        self.assertEqual("rules", entry["source"])

    def test_jev_skipped_without_api_key(self, ):
        issue = make_issue(41, "Feature request: dark mode")
        # No TYPESAFE_API_KEY in this test's environment -> jev_classify()
        # must return None and classify_issue must fall back to rules.
        entry = intake.classify_issue(issue, [issue], use_jev=True)
        self.assertEqual("feature", entry["category"])
        self.assertEqual("rules", entry["source"])


if __name__ == "__main__":
    unittest.main()
