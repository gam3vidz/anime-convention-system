"""Static regression tests for the volunteer dashboard design sketches."""

from html.parser import HTMLParser
from pathlib import Path
import re
import unittest
from urllib.parse import unquote


ROOT = Path(__file__).resolve().parents[1]
SKETCH_ROOT = ROOT / "sketches" / "volunteer-dashboard"
HUB = SKETCH_ROOT / "index.html"
VARIANTS = (
    SKETCH_ROOT / "01-command-center" / "index.html",
    SKETCH_ROOT / "02-my-con-weekend" / "index.html",
    SKETCH_ROOT / "03-shift-marketplace" / "index.html",
    SKETCH_ROOT / "04-calm-check-in" / "index.html",
)


class PageFacts(HTMLParser):
    """Collect the small amount of DOM data needed by these static tests."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.body_layout = None
        self.hrefs = []
        self.ids = set()
        self.text_parts = []

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "body":
            self.body_layout = attributes.get("data-layout")
        if attributes.get("id"):
            self.ids.add(attributes["id"])
        if attributes.get("name"):
            self.ids.add(attributes["name"])
        if tag == "a" and attributes.get("href") is not None:
            self.hrefs.append(attributes["href"])

    def handle_data(self, data):
        self.text_parts.append(data)

    @property
    def normalized_text(self):
        return re.sub(r"\s+", " ", " ".join(self.text_parts)).strip()


def parse_html(path):
    parser = PageFacts()
    parser.feed(path.read_text(encoding="utf-8"))
    return parser


class DashboardSketchTests(unittest.TestCase):
    def test_required_files_exist(self):
        expected = (
            HUB,
            SKETCH_ROOT / "shared.css",
            *VARIANTS,
            SKETCH_ROOT / "README.md",
        )
        for path in expected:
            with self.subTest(path=path.relative_to(ROOT)):
                self.assertTrue(path.is_file(), f"missing dashboard sketch file: {path}")

    def test_variants_share_the_review_dataset(self):
        shared_tokens = (
            "Jamie Rivera",
            "Guest Relations",
            "Delta H Con 2027",
            "Registration Desk A",
            "Guest Services",
            "Line Control",
            "Badge Pickup",
            "Panel Runner",
            "Green Room Support",
            "12:30 PM",
            "Ballroom C",
            "Room 814",
            "Friday after 4:00 PM",
            "Operations Desk",
            "extension 911",
        )
        for path in VARIANTS:
            facts = parse_html(path)
            text = facts.normalized_text
            with self.subTest(variant=path.parent.name):
                for token in shared_tokens:
                    self.assertIn(token, text, f"{path.parent.name} missing shared data: {token}")
                self.assertRegex(text.lower(), r"\bapproved\b")
                for number in ("6", "12", "16"):
                    self.assertRegex(text, rf"\b{number}\b", f"missing shared hour total {number}")

    def test_variants_expose_shared_actions(self):
        action_tokens = (
            'data-action="clock-toggle"',
            'data-action="view-shift"',
            'data-action="browse-open"',
            'data-action="claim-shift"',
            'data-action="mark-read"',
            'data-action="set-day"',
            'data-action="toggle-menu"',
        )
        for path in VARIANTS:
            html = path.read_text(encoding="utf-8")
            with self.subTest(variant=path.parent.name):
                for token in action_tokens:
                    self.assertIn(token, html, f"{path.parent.name} missing action: {token}")
                for day in ("friday", "saturday", "sunday"):
                    self.assertIn(f'data-day="{day}"', html)
                self.assertIn('href="../index.html"', html, "missing back-to-hub link")

    def test_variants_include_accessibility_and_sample_markers(self):
        tokens = (
            '<meta name="viewport"',
            'class="skip-link"',
            "Non-production design sample",
            "aria-live=",
            "focus-visible",
            "prefers-reduced-motion",
        )
        for path in VARIANTS:
            html = path.read_text(encoding="utf-8")
            with self.subTest(variant=path.parent.name):
                for token in tokens:
                    self.assertIn(token, html, f"{path.parent.name} missing marker: {token}")
                self.assertRegex(html, r'data-action="clock-toggle"[^>]*aria-pressed=')

    def test_variants_are_self_contained(self):
        for path in VARIANTS:
            html = path.read_text(encoding="utf-8")
            with self.subTest(variant=path.parent.name):
                self.assertIn("<style>", html)
                self.assertIn("<script>", html)
                self.assertNotRegex(html, r"<(?:script|img)\b[^>]*\bsrc\s*=")
                self.assertNotRegex(html, r"<link\b")

    def test_layout_markers_are_present_and_distinct(self):
        pages = (HUB, *VARIANTS)
        layouts = []
        for path in pages:
            layout = parse_html(path).body_layout
            with self.subTest(page=path.relative_to(SKETCH_ROOT)):
                self.assertIsNotNone(layout, "body must have a data-layout marker")
                self.assertRegex(layout, r"^[a-z0-9-]+$")
            layouts.append(layout)
        self.assertEqual(len(layouts), len(set(layouts)), "data-layout markers must be distinct")

    def test_sketch_html_and_css_have_no_external_urls(self):
        sources = sorted(SKETCH_ROOT.rglob("*.html")) + sorted(SKETCH_ROOT.rglob("*.css"))
        for path in sources:
            content = path.read_text(encoding="utf-8").lower()
            with self.subTest(path=path.relative_to(ROOT)):
                self.assertNotIn("http://", content)
                self.assertNotIn("https://", content)

    def test_all_internal_href_targets_resolve(self):
        pages = sorted(SKETCH_ROOT.rglob("*.html"))
        parsed = {path.resolve(): parse_html(path) for path in pages}
        for source in pages:
            facts = parsed[source.resolve()]
            for href in facts.hrefs:
                with self.subTest(source=source.relative_to(ROOT), href=href):
                    path_part, separator, fragment = href.partition("#")
                    if path_part:
                        target = (source.parent / unquote(path_part)).resolve()
                        self.assertTrue(target.is_file(), f"unresolved href target: {href}")
                    else:
                        target = source.resolve()
                    if separator and fragment:
                        self.assertEqual(target.suffix, ".html", f"fragment targets non-HTML file: {href}")
                        target_facts = parsed.get(target) or parse_html(target)
                        self.assertIn(unquote(fragment), target_facts.ids, f"unresolved fragment: {href}")


if __name__ == "__main__":
    unittest.main()
