# WordPress.org namespace review, 20 November 2026

The review refers to the uploaded 1.0.98 archive. The current release keeps the already distinct `acecrawl_`, `AceCrawl` and `ACECRAWL_` names. Examples such as `AceCrawlPageSpeed`, `AceCrawl_Database_Optimizer`, `acecrawl_settings`, `acecrawl_admin` and `acecrawl_tracking_clarity_token` already use that prefix.

Remaining internal names now use `acecrawl`: script/style handles, nonce actions and fields, settings section IDs, the performance dashboard widget and bulk action, disposable API/report transient keys, and sitemap object-cache groups and option-cache prefixes. The shared library keeps its distinct `acemedia_ai_` functions and `AceMedia\SharedAI\V1` namespace. A missed frontend class reference now calls `AceCrawl_Frontend_Performance`, the class already declared by the plugin.

The source repository retains its compatibility layer for earlier installations. Asset aliases preserve consumers which enqueue old handles, including the suite savebar. These aliases and all other `acecrawl-source-only` blocks are removed by `scripts/build-release.py`; `compat/` is absent from the release manifest.

Saved SEO post/term metadata, retention plan post types, user preferences and database table/index names retain their existing schemas. Renaming those records would require moving existing customer data and risks making saved titles, redirects and analytics inaccessible. No new data schema is introduced. The existing source-only migration copies legacy settings and cron names while preserving old rows and newer settings. Disposable cache namespaces rebuild on demand.

The shared AI library retains its `ace_ai_bundles` registry contract so independently bundled copies negotiate a single loader. Renaming that registry only in this bundle would split the shared library and can load incompatible copies. This is a shared-library contract, rather than a plugin-owned global.

`ace_redis_cache_*` and `ace_rc_*` hooks/constants belong to the optional external cache plugin. `ace_event` is an optional external event post type. They are integration contracts rather than identifiers declared by Crawl Enhancer. DOM classes, file names, REST URLs, localStorage keys and request-scoped `WP_Error` codes are not shared PHP registries and remain stable.

Verification:

- `php8.4 tests/legacy-prefix-test.php`: saved nested settings, non-autoloaded options, newer settings, rollback rows, repeat migration, source asset aliases.
- `python3 tests/release-prefix-test.py dist/ace-crawl-enhancer-VERSION.zip`: scans the actual upload archive for old declarations/registry names/nonce actions/asset handles, missing referenced assets and compatibility leakage; asserts saved metadata and retention post type contracts remain stable.
- Existing asset-buffer, aggregate-view and tracking suites cover the changed runtime paths. Native WordPress and Plugin Check results are recorded with the final release verification.

## Final release verification

Version 1.0.103 was built twice with identical SHA-256 `90797c98953edac8791df28f0954a0766b714aac9d4761b877a34bf9d6e53c17`. All 117 installed files matched the release ZIP before and after testing in disposable WordPress 7.1.3 with WP_DEBUG enabled and mail suppressed.

- Plugin Check 2.1.0: 0 errors, 617 warnings. Existing SQL/cache patterns, included-view variables, permission checks delegated to callers and external/core hooks remain warnings; this is not a claim that every warning was eliminated.
- Atomic-lock regression: four rounds of 16 independent PHP processes using genuine wpdb and an isolated MariaDB server, including fresh and expired leases with persistent-cache mode off and on. Exactly one worker acquired the lease in every round. Owner release, successor protection, multisite isolation and database-failure fallback also passed.
- Native WordPress asset and editor capability/nonce checks passed. The sitemap index and news sitemap returned valid XML; frontend JSON-LD parsed correctly.
- Authenticated settings saves succeeded; invalid nonces were denied. All seven admin asset URLs returned 200. Chromium auto-save persisted after reload, with zero JavaScript errors and one pre-existing block API deprecation warning.
- A clean repeat produced no new debug-log bytes. Earlier test-harness setup failures were corrected and were not plugin runtime failures.

No production site, client database, outgoing email or parent plugin reference was changed for this review work. The corrected ZIP still needs uploading to the WordPress.org review before the review reply is sent.
