from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class RepositoryFoundationTests(unittest.TestCase):
    def test_public_readme_identifies_the_project(self) -> None:
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertTrue(readme.startswith("# Anime Convention System\n"))

    def test_project_brief_defines_a_complete_first_vertical_slice(self) -> None:
        brief = (ROOT / "docs" / "PROJECT_BRIEF.md").read_text(encoding="utf-8")
        for requirement in (
            "administrator creates a convention",
            "detects room and time conflicts",
            "publishes the schedule",
            "desktop and mobile",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, brief)

    def test_gitignore_blocks_common_secret_files(self) -> None:
        gitignore = (ROOT / ".gitignore").read_text(encoding="utf-8").splitlines()
        for pattern in (".env", "*.pem", "*.key"):
            with self.subTest(pattern=pattern):
                self.assertIn(pattern, gitignore)


if __name__ == "__main__":
    unittest.main()
