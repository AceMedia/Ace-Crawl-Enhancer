# Build and release

Ace Crawl Enhancer 1.0.93 requires WordPress 6.0 and PHP 7.4 or later. Install the declared npm dependencies and run `npm run build` to regenerate built assets. Run `python3 scripts/build-release.py` from the repository root to create the runtime ZIP in `dist/`.

The release script uses [an explicit manifest](../../scripts/release-files.json) of the 102 reviewed runtime files. Update it when adding or removing runtime code. The script excludes tests, development tools, local agent instructions and site-specific adapters. For the 1.0.93 candidate, the expected SHA-256 is `6a807fe288773d04bdbf97b4a6703146a9787f5db0b32a583ef4d5791265bdcc`.

Before publishing a new version, check the ZIP file list and checksum, run PHP syntax checks and WordPress Plugin Check, and install and upgrade it on disposable WordPress sites at the declared minimum PHP version and a current version. Check single-site and multisite behaviour, front-end metadata, sitemaps, robots rules, and interactions with themes and optional plugins. Publishing source, submitting to WordPress.org and updating a live site are separate steps; record each result accurately.
