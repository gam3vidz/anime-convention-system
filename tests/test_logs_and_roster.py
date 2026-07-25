"""Tests for bounded/searchable activity logs (item 11) and roster
visibility of pending applicants (item 12)."""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]


def php_function(source: str, name: str) -> str:
    start = source.index(f"function {name}")
    nxt = source.find("\nfunction ", start + 1)
    return source[start:] if nxt == -1 else source[start:nxt]


def switch_case(source: str, case: str) -> str:
    start = source.index(f"case '{case}':")
    end = source.index("break;", start)
    return source[start:end]


class BoundedLogSearchTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.css = (ROOT / "styles.css").read_text(encoding="utf-8")
        cls.schema = (ROOT / "api/schema.php").read_text(encoding="utf-8")

    def test_server_search_is_bounded_with_a_hard_page_ceiling(self):
        helper = php_function(self.api, "searchSystemLogs")
        # A hard maximum page size and prepared statements.
        self.assertRegex(helper, r"min\(\s*(?:50|\$?\w+)")
        self.assertIn("LIMIT", helper)
        self.assertIn("OFFSET", helper)
        self.assertIn("prepare(", helper)
        # Stable newest-first ordering.
        self.assertIn("ORDER BY created_at DESC", helper)
        # Search covers useful fields (booth/vendor/note text live in details).
        for field in ("action", "details", "actor_name"):
            self.assertIn(field, helper)
        # Pagination metadata is returned, and out-of-range pages are clamped.
        for key in ("'total'", "'page'", "'pageSize'"):
            self.assertIn(key, helper)
        self.assertIn("min($page, $totalPages)", helper)

    def test_vendor_hall_log_entries_include_searchable_vendor_and_note_text(self):
        save = switch_case(self.api, "save_vendor_hall_assignment")
        note = switch_case(self.api, "add_vendor_hall_note")
        self.assertIn("$input['vendorName']", save)
        self.assertIn("$vendorName", save)
        self.assertIn("$input['noteText']", note)
        self.assertIn("$noteExcerpt", note)

    def test_search_action_is_post_manager_scoped(self):
        get_actions = re.search(r"\$getActions = \[[^\]]*\]", self.api).group(0)
        self.assertNotIn("search_logs", get_actions)
        body = switch_case(self.api, "search_logs")
        self.assertIn("requireManager($pdo)", body)
        self.assertIn("searchSystemLogs", body)

    def test_logs_are_indexed_for_bounded_ordering(self):
        logs_table = self.schema[self.schema.index("system_logs ("):]
        logs_table = logs_table[:logs_table.index("ENGINE=InnoDB")]
        self.assertIn("idx_created_at", logs_table)

    def test_client_does_not_dump_every_row_and_offers_search(self):
        # No unbounded client-side slice of the full log array anymore.
        self.assertNotIn("systemLogs.slice(0, 40)", self.client)
        self.assertIn('apiRequest("search_logs"', self.client)
        self.assertIn('id="systemLogSearch"', self.html)
        # Loading / empty / error states exist for the paginated surface.
        render = self.client[self.client.index("function renderSystemLogs"):]
        render = render[:render.index("\nfunction ", 1)]
        for token in ("loading", "error", "No "):
            self.assertIn(token, render)
        # Pagination controls.
        self.assertIn('id="systemLogPrev"', self.html)
        self.assertIn('id="systemLogNext"', self.html)

    def test_log_cards_stack_at_narrow_panel_widths_without_text_overlap(self):
        render = self.client[self.client.index("function renderSystemLogs"):]
        render = render[:render.index("\nfunction ", 1)]
        self.assertIn("system-log-entry", render)
        self.assertRegex(self.css, r"\.system-log-entry\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)")
        self.assertIn("overflow-wrap: anywhere", self.css)


class RosterPendingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")

    def test_roster_page_includes_pending_applicants(self):
        table = self.client[self.client.index("function renderRosterPageTable"):]
        table = table[:table.index("\nfunction ", 1)]
        # Pending applicants must no longer be filtered out of the roster.
        self.assertNotIn('.filter(user => user.status === "approved")', table)
        # They surface with a clear Pending badge and stay openable.
        self.assertIn("Pending", table)
        self.assertIn("data-roster-view", table)

    def test_pending_are_searchable_and_filterable(self):
        table = self.client[self.client.index("function renderRosterPageTable"):]
        table = table[:table.index("\nfunction ", 1)]
        # The search/department/gender filters still apply to the combined set.
        self.assertIn("rosterPageSearch", table)
        self.assertIn("rosterPageDept", table)


if __name__ == "__main__":
    unittest.main()
