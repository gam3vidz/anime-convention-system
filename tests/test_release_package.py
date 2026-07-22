from pathlib import Path
from zipfile import ZipFile
import subprocess
import sys
import unittest


ROOT = Path(__file__).resolve().parents[1]


class ReleasePackageTests(unittest.TestCase):
    def test_release_archive_contains_only_allowlisted_runtime_files(self):
        subprocess.run([sys.executable, "scripts/build-release.py"], cwd=ROOT, check=True)
        archive_path = ROOT / "dist/delta-h-release.zip"
        with ZipFile(archive_path) as archive:
            names = set(archive.namelist())

        self.assertNotIn("api/config.php", names)
        for forbidden in (
            "api/setup_admin.php",
            "api/debug.php",
            "api/check_db.php",
            "api/db_upgrade.php",
            "api/test.php",
            "api/db.php",
        ):
            self.assertNotIn(forbidden, names)

        self.assertIn("api/config.example.php", names)
        self.assertIn("scripts/migrate.php", names)
        self.assertIn("api/uploads/.htaccess", names)
        self.assertFalse(any(name.startswith("tests/") for name in names))
        self.assertFalse(any(name.startswith("docs/") for name in names))


if __name__ == "__main__":
    unittest.main()
