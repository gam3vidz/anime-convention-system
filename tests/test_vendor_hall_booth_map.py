"""Tests for the interactive Vendor Hall booth map feature.

The Vendor Hall surface must render the supplied floor plan as an interactive
map with click targets over every real booth rectangle, persist position-specific
vendor names, and keep append-only notes tied to each booth id.
"""
from pathlib import Path
import json
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


# The fixed booth allowlist required by the floor plan (115 positions).
EXPECTED_BOOTHS = []
for prefix, start, end in (
    ("A", 1, 15), ("A", 101, 115), ("A", 201, 215), ("A", 301, 312),
    ("D", 1, 6), ("D", 101, 106), ("D", 201, 210), ("D", 301, 310), ("D", 401, 406),
):
    EXPECTED_BOOTHS += [f"{prefix}{n:03d}" for n in range(start, end + 1)]
EXPECTED_BOOTHS += [f"SG{n}" for n in range(1, 21)]


class BoothAllowlistTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")

    def test_expected_set_is_115_unique_booths(self):
        self.assertEqual(len(EXPECTED_BOOTHS), 115)
        self.assertEqual(len(set(EXPECTED_BOOTHS)), 115)

    def test_php_allowlist_matches_the_real_floor_plan(self):
        spots = php_function(self.api, "vendorHallSpotCodes")
        # The placeholder A-D x12 grid must be gone.
        self.assertNotIn("$n <= 12", spots)
        for booth in EXPECTED_BOOTHS:
            self.assertIn(f"'{booth}'", spots, f"missing {booth} in PHP allowlist")
        # No accidental extras such as B/C sections or A013+ style typos.
        quoted = set(re.findall(r"'([A-Z]{1,2}\d{1,3})'", spots))
        self.assertEqual(quoted, set(EXPECTED_BOOTHS))

    def test_js_booth_layout_covers_every_booth_with_normalized_coords(self):
        layout = self.client[self.client.index("const VENDOR_HALL_LAYOUT"):]
        layout = layout[:layout.index("];") + 2]
        ids = re.findall(r'id:\s*"([A-Z0-9]+)"', layout)
        self.assertEqual(set(ids), set(EXPECTED_BOOTHS))
        self.assertEqual(len(ids), 115)
        # Coordinates are normalized percentages (0-100) so targets track scaling.
        coords = re.findall(r'x:\s*([\d.]+),y:\s*([\d.]+),w:\s*([\d.]+),h:\s*([\d.]+)', layout.replace(" ", ""))
        self.assertEqual(len(coords), 115)
        for x, y, w, h in coords:
            for v in (x, y, w, h):
                self.assertLessEqual(float(v), 100.0)
                self.assertGreaterEqual(float(v), 0.0)

    def test_js_spots_are_derived_from_layout(self):
        # Client allowlist and the layout must be the same 115 positions.
        self.assertIn("VENDOR_HALL_SPOTS", self.client)
        self.assertNotIn('["A", "B", "C", "D"].flatMap', self.client)


class NotesBackendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.schema = (ROOT / "api/schema.php").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")
        migrations = sorted((ROOT / "migrations").glob("*.sql"))
        cls.migration_text = "\n".join(p.read_text(encoding="utf-8") for p in migrations)

    def test_append_only_notes_table_is_defined(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS vendor_hall_notes", self.schema)
        table = self.schema[self.schema.index("vendor_hall_notes"):]
        table = table[:table.index("ENGINE=InnoDB")]
        for column in ("id", "spot_code", "note_text", "created_by", "author_name", "created_at"):
            self.assertIn(column, table)
        self.assertIn("AUTO_INCREMENT", table)
        # Bounded newest-first retrieval needs an index on (spot_code, created_at).
        self.assertIn("spot_code, created_at", table)

    def test_migration_is_idempotent_and_present(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS vendor_hall_notes", self.migration_text)
        self.assertIn("CREATE TABLE IF NOT EXISTS vendor_hall_assignments", self.migration_text)

    def test_add_note_helper_validates_and_appends(self):
        helper = php_function(self.api, "addVendorHallNote")
        self.assertIn("isValidVendorHallSpot", helper)
        self.assertIn("cleanManagementText", helper)
        # Empty notes are rejected.
        self.assertRegex(helper, r"===\s*''.*fail\(")
        # Append via INSERT, never UPDATE/overwrite.
        self.assertIn("INSERT INTO vendor_hall_notes", helper)
        self.assertNotIn("ON DUPLICATE KEY", helper)
        self.assertNotIn("UPDATE vendor_hall_notes", helper)

    def test_note_action_is_post_csrf_authorized_and_logged(self):
        get_actions = re.search(r"\$getActions = \[[^\]]*\]", self.api).group(0)
        self.assertNotIn("add_vendor_hall_note", get_actions)
        body = switch_case(self.api, "add_vendor_hall_note")
        self.assertIn("requireVendorHall($pdo, $config)", body)
        self.assertIn("logAction($pdo", body)

    def test_notes_are_loaded_into_state_chronologically_for_capable_users(self):
        loader = php_function(self.api, "vendorHallNotes")
        self.assertIn("FROM vendor_hall_notes", loader)
        self.assertIn("ORDER BY", loader)
        self.assertIn("created_at", loader)
        self.assertIn("'vendorHallNotes'", self.api)
        self.assertIn("vendorHallNotes = data.vendorHallNotes", self.client)


class InteractiveMapUITests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")
        cls.css = (ROOT / "styles.css").read_text(encoding="utf-8")
        cls.build = (ROOT / "scripts/build-release.py").read_text(encoding="utf-8")

    def test_floor_plan_asset_is_preserved_locally(self):
        asset = ROOT / "assets/vendor-hall-floor-plan.webp"
        self.assertTrue(asset.is_file(), "floor plan asset must be committed to the repo")
        self.assertGreater(asset.stat().st_size, 1000)

    def test_view_renders_the_floor_plan_image_with_overlay_container(self):
        self.assertIn('src="./assets/vendor-hall-floor-plan.webp"', self.html)
        # Image needs descriptive alt text.
        self.assertRegex(self.html, r'id="vendorHallImage"[^>]*alt="[^"]+"')
        self.assertIn('id="vendorHallStage"', self.html)
        self.assertIn('id="vendorHallMap"', self.html)
        # Pan container so the map never overflows the page.
        self.assertIn('id="vendorHallMapFrame"', self.html)

    def test_legend_distinguishes_three_states_with_text_not_color_only(self):
        for label in ("Unassigned", "Assigned", "Has notes"):
            self.assertIn(label, self.html)

    def test_zoom_controls_exist(self):
        for marker in ("vendorHallZoomIn", "vendorHallZoomOut", "vendorHallZoomReset"):
            self.assertIn(f'id="{marker}"', self.html)

    def test_searchable_booth_list_fallback_exists(self):
        self.assertIn('id="vendorHallSearch"', self.html)
        self.assertIn('id="vendorHallList"', self.html)
        self.assertIn("renderVendorHallList", self.client)

    def test_booth_drawer_is_an_accessible_modal(self):
        drawer = self.html[self.html.index('id="vendorHallDrawer"'):]
        drawer = drawer[:2000]
        self.assertIn('role="dialog"', drawer)
        self.assertIn('aria-modal="true"', drawer)
        for marker in (
            'id="vendorHallDrawerTitle"',
            'id="vendorHallNoteList"',
            'id="vendorHallNoteForm"',
            'id="vendorHallNoteText"',
            'id="vendorHallVendorName"',
        ):
            self.assertIn(marker, self.html)
        # aria-modal must be backed by a real keyboard focus trap, not only Escape.
        self.assertIn('event.key !== "Tab"', self.client)
        self.assertIn("drawer.querySelectorAll", self.client)
        self.assertIn("vendorHallLastFocus.focus", self.client)

    def test_save_and_note_success_messages_are_not_immediately_cleared(self):
        for function_name in ("saveVendorHallAssignment", "clearVendorHallAssignment", "addVendorHallNote"):
            body = self.client[self.client.index(f"function {function_name}"):]
            body = body[:body.index("\nfunction ", 1)]
            self.assertNotIn("renderVendorHallDrawer();", body)

    def test_targets_render_from_layout_as_accessible_buttons(self):
        render = self.client[self.client.index("function renderVendorHall"):]
        render = render[:render.index("\nfunction ", 1)]
        self.assertIn("VENDOR_HALL_LAYOUT", render)
        # Percentage positioning so targets track image scaling.
        self.assertIn("%", render)
        self.assertIn('type="button"', render)
        self.assertIn("aria-label", render)
        self.assertIn("data-vendor-spot", render)
        # State classes distinguish assigned / notes / selected.
        self.assertIn("is-occupied", render)
        self.assertIn("has-notes", render)

    def test_add_note_is_append_only_from_client(self):
        self.assertIn('apiRequest("add_vendor_hall_note"', self.client)
        self.assertIn('apiRequest("save_vendor_hall_assignment"', self.client)
        # Notes render with author and timestamp, oldest first.
        noterender = self.client[self.client.index("function renderVendorHallDrawer"):]
        noterender = noterender[:noterender.index("\nfunction ", 1)]
        self.assertIn("authorName", noterender)
        self.assertIn("createdAt", noterender)

    def test_release_package_ships_the_floor_plan_asset(self):
        self.assertIn("assets/vendor-hall-floor-plan.webp", self.build)

    def test_css_targets_are_stateful_and_map_pans_without_page_overflow(self):
        for marker in (
            ".vendor-hall-map",
            ".vendor-hall-spot",
            ".vendor-hall-spot.is-occupied",
            ".vendor-hall-spot.is-selected",
            ".vendor-hall-spot.has-notes",
            ".vendor-hall-mapframe",
            ".vendor-hall-drawer",
        ):
            self.assertIn(marker, self.css)
        # Pan container scrolls internally rather than overflowing the page.
        frame = self.css[self.css.index(".vendor-hall-mapframe"):]
        frame = frame[:frame.index("}")]
        self.assertIn("overflow", frame)
        self.assertRegex(self.css, r"@media\s*\(max-width:\s*\d+px\)")

    def test_asset_version_bumped(self):
        self.assertNotIn("styles.css?v=46", self.html)
        self.assertNotIn("core.js?v=46", self.html)
        self.assertRegex(self.html, r"styles\.css\?v=4[7-9]")
        self.assertRegex(self.html, r"core\.js\?v=4[7-9]")


if __name__ == "__main__":
    unittest.main()
