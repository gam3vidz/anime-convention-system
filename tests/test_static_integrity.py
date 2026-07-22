from collections import Counter
from html.parser import HTMLParser
from pathlib import Path
import re
import unittest
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]


class DocumentParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.assets = []

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if values.get("id"):
            self.ids.append(values["id"])
        for attribute in ("src", "href"):
            value = values.get(attribute)
            if value:
                self.assets.append(value)


class StaticIntegrityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.parser = DocumentParser()
        cls.parser.feed(cls.html)

    def test_html_ids_are_unique(self):
        duplicates = sorted(name for name, count in Counter(self.parser.ids).items() if count > 1)
        self.assertEqual([], duplicates)

    def test_local_html_assets_exist(self):
        missing = []
        for reference in self.parser.assets:
            parsed = urlsplit(reference)
            if parsed.scheme or parsed.netloc or reference.startswith(("#", "data:", "mailto:", "tel:")):
                continue
            relative = parsed.path.lstrip("/")
            if not relative:
                continue
            if not (ROOT / relative).is_file():
                missing.append(reference)
        self.assertEqual([], sorted(missing))

    def test_client_api_actions_exist_on_server(self):
        client_actions = set(re.findall(r'apiRequest\(\s*["\']([a-z0-9_]+)["\']', self.client))
        server_actions = set(re.findall(r"case\s+[\"']([a-z0-9_]+)[\"']\s*:", self.api))
        self.assertEqual([], sorted(client_actions - server_actions))

    def test_obsolete_app_bundle_is_not_loaded(self):
        self.assertNotIn("app.js", self.html)
        self.assertFalse((ROOT / "app.js").exists())


if __name__ == "__main__":
    unittest.main()
