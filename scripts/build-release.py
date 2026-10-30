#!/usr/bin/env python3
"""Build the Ace Crawl runtime ZIP from the reviewed file manifest."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
SLUG = "ace-crawl-enhancer"
FILES = json.loads((ROOT / "scripts/release-files.json").read_text())
VERSION = "1.0.94"

if len(FILES) != len(set(FILES)):
    raise SystemExit("Duplicate path in release manifest")

DIST = ROOT / "dist"
DIST.mkdir(exist_ok=True)
archive = DIST / f"{SLUG}-{VERSION}.zip"
with ZipFile(archive, "w", compression=ZIP_DEFLATED) as output:
    for relative in sorted(FILES):
        path = (ROOT / relative).resolve()
        if not path.is_relative_to(ROOT) or not path.is_file():
            raise SystemExit(f"Missing or unsafe runtime file: {relative}")
        info = ZipInfo(f"{SLUG}/{relative}", (2026, 1, 1, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        output.writestr(info, path.read_bytes())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n")
print(f"{archive}\n{digest}")
