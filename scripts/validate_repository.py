#!/usr/bin/env python3
"""Fail loudly when the repository foundation is incomplete."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REQUIRED_FILES = (
    ROOT / "README.md",
    ROOT / "docs" / "PROJECT_BRIEF.md",
    ROOT / ".gitignore",
    ROOT / ".github" / "workflows" / "validate.yml",
)


def main() -> int:
    missing = [str(path.relative_to(ROOT)) for path in REQUIRED_FILES if not path.is_file()]
    if missing:
        raise SystemExit("Missing required repository files: " + ", ".join(missing))

    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    required_sections = (
        "# Anime Convention System",
        "## Planned MVP",
        "## Development",
    )
    absent = [section for section in required_sections if section not in readme]
    if absent:
        raise SystemExit("README is missing required sections: " + ", ".join(absent))

    print(f"Repository foundation valid: {len(REQUIRED_FILES)} required files present")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
