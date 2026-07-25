#!/usr/bin/env python3
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / "dist"
ARCHIVE = DIST / "delta-h-release.zip"

RUNTIME_FILES = [
    ".htaccess",
    "index.html",
    "styles.css",
    "core.js",
    "CREDITS.txt",
    "assets/anime-dancer.gif",
    "assets/vendor-hall-floor-plan.webp",
    "delta-h-shift-import-template.xlsx",
    "api/.htaccess",
    "api/api.php",
    "api/config.example.php",
    "api/discord-callback.php",
    "api/discord-interactions.php",
    "api/schema.php",
    "api/stripe.php",
    "api/uploads/.htaccess",
    "api/uploads/.gitkeep",
    "scripts/migrate.php",
]

DOCUMENTATION_FILES = {
    "docs/EVENTENY_INSPIRED_OVERHAUL.md": "EVENTENY_INSPIRED_OVERHAUL.md",
}


def build() -> Path:
    source_files = [*RUNTIME_FILES, *DOCUMENTATION_FILES]
    missing = [name for name in source_files if not (ROOT / name).is_file()]
    if missing:
        raise SystemExit(f"Missing runtime files: {', '.join(missing)}")

    DIST.mkdir(exist_ok=True)
    with ZipFile(ARCHIVE, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for name in RUNTIME_FILES:
            archive.write(ROOT / name, arcname=name)
        for source, archive_name in DOCUMENTATION_FILES.items():
            archive.write(ROOT / source, arcname=archive_name)

    digest = hashlib.sha256(ARCHIVE.read_bytes()).hexdigest()
    manifest = {
        "archive": ARCHIVE.name,
        "sha256": digest,
        "files": [*RUNTIME_FILES, *DOCUMENTATION_FILES.values()],
    }
    (DIST / "release-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    print(f"Built {ARCHIVE} ({ARCHIVE.stat().st_size} bytes, sha256={digest})")
    return ARCHIVE


if __name__ == "__main__":
    build()
