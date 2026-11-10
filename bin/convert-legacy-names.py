#!/usr/bin/env python3
"""Rewrite a theme or plugin from Ace Crawl Enhancer's old names to the acecrawl ones.

The old => new lists come from compat/legacy-names.php, so the converter and the compatibility
layer can never disagree. Only exact, whole names are replaced (plus the prefixed forms WordPress
builds from them, such as wp_ajax_<action> and pre_option_<option>); a theme's own names that
merely start with ace_seo_ are left alone.

    bin/convert-legacy-names.py PATH [PATH ...]           # dry run: what would change
    bin/convert-legacy-names.py --write PATH [PATH ...]   # change the files

Built or vendored files are never edited; matches in them are listed so the source can be rebuilt.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPAT = ROOT / "compat" / "legacy-names.php"
EDIT = {".php", ".js", ".jsx", ".mjs", ".ts", ".tsx"}
SKIP_DIRS = {".git", "node_modules", "vendor", "vendor_prefixed", "core", "upgrade", "cache"}
BUILT_DIRS = {"build", "dist"}
# WordPress builds these hook names from an AJAX action, option or transient name.
GLUE = (
    "wp_ajax_nopriv_", "wp_ajax_", "pre_update_option_", "update_option_", "pre_option_", "default_option_",
    "sanitize_option_", "add_option_", "delete_option_", "pre_site_option_", "site_option_", "option_",
    "_transient_timeout_", "_transient_", "pre_transient_", "transient_",
)
# The shared AI library's service function is defined by older bundled copies, so compat leaves it
# alone; a converted consumer moves to the new name together with the newer library.
EXTRA = {"ace_ai_connection_service": "acemedia_ai_connection_service"}


def load_map() -> dict[str, str]:
    text = COMPAT.read_text()
    pairs = dict(re.findall(r"^\t\t'([A-Za-z0-9_]+)' => '([A-Za-z0-9_]+)',$", text, re.M))
    if len(pairs) < 300:
        raise SystemExit(f"Only {len(pairs)} names read from {COMPAT}; refusing to continue.")
    pairs.update(EXTRA)
    return pairs


def files(paths: list[Path]):
    for base in paths:
        if base.is_file():
            yield base, False
            continue
        for path in sorted(base.rglob("*")):
            parts = set(path.relative_to(base).parts[:-1])
            if not path.is_file() or path.suffix not in EDIT or parts & SKIP_DIRS:
                continue
            built = bool(parts & BUILT_DIRS) or path.name.endswith(".min.js")
            yield path, built


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("paths", nargs="+", type=Path)
    parser.add_argument("--write", action="store_true", help="change the files (default: dry run)")
    args = parser.parse_args()

    names = load_map()
    alternatives = "|".join(map(re.escape, sorted(names, key=len, reverse=True)))
    glue = "|".join(map(re.escape, GLUE))
    pattern = re.compile(rf"(?<![A-Za-z0-9_$])({glue})?({alternatives})(?![A-Za-z0-9_])")

    total = 0
    built_hits = []
    for path, built in files(args.paths):
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        found: dict[str, int] = {}

        def swap(match: re.Match) -> str:
            found[match.group(2)] = found.get(match.group(2), 0) + 1
            return (match.group(1) or "") + names[match.group(2)]

        converted = pattern.sub(swap, text)
        if not found:
            continue
        count = sum(found.values())
        if built:
            built_hits.append((path, count))
            continue
        total += count
        shown = ", ".join(sorted(found)[:4]) + (" ..." if len(found) > 4 else "")
        print(f"{count:4d}  {path}  ({shown})")
        if args.write:
            path.write_text(converted, encoding="utf-8")

    for path, count in built_hits:
        print(f"BUILT {count:3d}  {path}  (rebuild from source; not edited)")
    print(f"{'Changed' if args.write else 'Would change'} {total} names.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
