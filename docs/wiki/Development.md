# Development and release

Ace Crawl Enhancer 1.0.93 requires WordPress 6.0 and PHP 7.4 or later. The [WordPress readme](../../readme.txt) lists the optional external services. The [hook guide](Hooks.md) records the Ace integration points; keep existing option names, metadata keys and public hooks compatible when changing them.

Install the declared npm dependencies, run `npm run build`, then `python3 scripts/build-release.py`. The release script uses an explicit manifest of the 102 runtime files reviewed for 1.0.93. Update that manifest deliberately when runtime files change. It excludes tests, local agent instructions, development dependencies and private site adapters. Confirm the resulting ZIP contents, checksum and WordPress Plugin Check results before submitting it.

The current candidate was checked on disposable WordPress installations including PHP 7.4 and multisite. Its WordPress.org submission is separate from publishing this source repository or deploying a consuming site. Record each state separately. Review the actual rendered title, canonical, robots, sitemap and structured data output when upgrading a production site; themes, other plugins, physical `robots.txt` files and caches affect the result.

Public issues are welcome. Discuss code changes before opening a pull request; Ace Media maintains official releases. The GPL still permits forks and redistribution.
