# WordPress.org review, 30 October 2026

The review covers the submitted 1.0.93 archive. Issues #41–#52 track each category separately. Automated examples are evidence to investigate, rather than proof that every matching line is faulty.

## Public credits and external links (#41)

I checked the runtime frontend renderers, breadcrumb output, retention notices, aggregate-view output and both sitemap renderers. They do not add an AceMedia promotional link or public attribution. Sitemap branding uses the site's own name, logo and homepage. The fallback sitemap explanation links to sitemaps.org for documentation; XML namespace URLs identify the sitemap format. These are functional documentation and format identifiers, not advertising or plugin credits.

There is no public credit switch to enable by default and no new attribution has been introduced. Future optional promotional credits must require an explicit, default-off site-owner setting. Admin-only provider descriptions are not public-site credits.

## Prefixes and compatibility (#47)

The plugin's runtime functions use `ace_` families such as `ace_sitemap_powertools_` and `ace_ai_`. Runtime classes use the distinct `AceSeo`, `AceSEO`, `AceCrawlEnhancer` or `ACE_` names. Constants use `ACE_`; plugin-owned stored options, transients, table suffixes, cron actions, script handles and REST namespaces use the established Ace families. The shared AI registry intentionally uses the same guarded `ace_ai_` contract across Ace plugins.

I have kept those existing names. Renaming stored settings or public hooks merely to silence a prefix heuristic would break existing installations and integrations. Yoast-shaped form fields and imported Yoast metadata are interoperability identifiers; WordPress options, core globals and core asset dependencies are owned by WordPress. They must not be renamed to a plugin prefix. Variables inside class methods and included view scopes are local implementation details, not new public globals.

New runtime declarations and stored data must continue these distinct families. The release manifest excludes the standalone development test harnesses, whose fixture functions are not loaded by the distributed plugin.

## Delivery boundary

The corrected package must pass local runtime and archive checks before the first AceHost deployment. Rollout order is TalkFuse, Sheffield Events, Uni Carts and William Unicycle, then IEG, then PPN dev. PPN live requires separate manual confirmation. Uploading a revised archive to WordPress.org and replying to the review email are separate actions from this code rollout.
