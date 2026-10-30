# Development and release

The current source and WordPress.org review candidate are **1.0.95**, requiring WordPress 6.0 and PHP 7.4 or later. The latest published [GitHub release remains 1.0.92](https://github.com/AceMedia/Ace-Crawl-Enhancer/releases/tag/v1.0.92). A source commit or a successful site deployment does not publish a GitHub release or approve the WordPress.org submission.

Follow the [ordered build and installation steps in the README](../../README.md#install-the-current-candidate). Run them from the repository root with Node.js/npm and Python 3.9 or later. The npm build generates the breadcrumbs and settings assets in `build/` and the admin CSS in `assets/css/admin-redis.css`. Most admin scripts remain readable files in `assets/js/`.

The release script creates `dist/ace-crawl-enhancer-1.0.95.zip` and `dist/ace-crawl-enhancer-1.0.95.zip.sha256`. It uses the explicit [release manifest](../../scripts/release-files.json), currently containing 116 runtime files. Update that manifest deliberately when runtime files change. Tests, local agent instructions, development dependencies and private site adapters stay out of the archive.

Check the exact ZIP's contents and checksum, run WordPress Plugin Check and test clean activation and reactivation before submitting it. The 1.0.95 candidate passes activation on WordPress 6.0/PHP 7.4 and includes regression checks for protected metadata. Run `tests/protected-metadata-native.php` through `wp eval-file` only on a disposable WordPress installation: it creates and deletes fictional posts.

Verify a published page's rendered title using the [worked example](../../README.md#try-one-seo-title), then compare canonical links, robots directives, sitemap responses and structured data. Clear the relevant page cache before comparing. A theme, another SEO plugin or a physical `robots.txt` can affect the response. Local metadata features need no external account; the [WordPress readme](../../readme.txt) documents optional external services.

The [hook guide](Hooks.md) records the Ace integration points. Keep existing option names, metadata keys and public hooks compatible when changing them. Record source publication, release publication, WordPress.org review and site deployments separately.

Public issues are welcome. Discuss code changes before opening a pull request; Ace Media maintains official releases. The GPL still permits forks and redistribution.
