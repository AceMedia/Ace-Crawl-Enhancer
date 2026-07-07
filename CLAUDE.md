# Ace Crawl Enhancer ("Ace SEO" / `ace_seo`)

Full-stack SEO plugin and Yoast replacement. Canonical repo: `git@github.com:AceMedia/Ace-Crawl-Enhancer.git`.
For AceMedia-wide conventions (British English, commit rules, deploy patterns) use the **speedforce** skill —
this file only holds plugin-specific facts.

**Canonical plan = [docs/ROADMAP.md](docs/ROADMAP.md)** plus GitHub issues #1 (WP 7.0) and #7 (Site Kit
indexing follow-up). Agents pick up roadmap tasks from there; keep both in sync when scope changes.

## Consumers (submodule in 6 sites — treat the public surface as frozen)

ppnews (news/sport), EgbertTaylor/IEG (WooCommerce), SheffEvents (Ace-Community-Events: events/businesses/jobs),
TalkFuse.com, unicarts (WooCommerce), william-unicycle. Also rsynced (not submoduled) into SPKF
(sheffieldparkour.org). A `git push` here gets pulled into live sites on their next submodule bump —
**never break**: `_ace_seo_*` meta keys, `ace_seo_options` / `ace_sitemap_powertools_options` shapes,
`yoast_wpseo_` form-field prefix, existing `ace_seo_*` / `ace_sitemap_powertools_*` filters, or the
theme-append title convention (see gotchas).

## Architecture map

- `accelerated-crawl-enhancer.php` (2.6k lines, singleton `AceCrawlEnhancer`) — meta fields definition,
  title/meta-desc/canonical/robots pipeline, template variables, Yoast migration, background DB optimisation.
- `includes/ace-sitemap-powertools.php` (3.4k lines, procedural `ace_sitemap_powertools_*`) — core-sitemap
  extensions, caching (Ace-Redis-Cache aware), news/authors/tags routes, exclusions, legacy redirects.
- `includes/frontend/` — `class-ace-seo-frontend.php` (head output + OG/Twitter + Jetpack override +
  JSON-LD graph), `class-ace-seo-schema.php` (Organization/Person/LocalBusiness + orphaned Product/FAQ
  builders), `class-ace-seo-breadcrumbs.php` (visual trail only), `class-ace-seo-performance.php`
  (guest meta cache + deferred footer schema).
- `includes/admin/` — settings/metabox/dashboard views (PHP + vanilla JS in `assets/js/`),
  `class-ace-seo-api-helper.php` (OpenAI + PageSpeed), `class-ace-seo-ai-assistant.php` (AJAX AI endpoints),
  Site Kit readers (`class-ace-seo-sitekit.php`, `class-ace-seo-google-data.php`).
- One DB table: `{prefix}ace_seo_analytics`. Crons: `ace_seo_optimize_database` (once),
  `ace_seo_daily_performance_check`.

## Build & verify

- `npm run build` — wp-scripts (breadcrumbs block + SaveBar into `build/`) + sass for admin CSS.
  Most admin JS is unbundled vanilla in `assets/js/` — edit directly, no build needed.
- Verify head output on a consuming site: `curl -s <url> | grep -iE 'og:|twitter:|canonical|ld\+json'`;
  parse JSON-LD blocks and validate types. The WordPress Playground MCP works for isolated plugin testing.
- Live sites sit behind Varnish/Cloudflare — origin-check with `?v=RANDOM`, then
  `sudo /usr/local/sbin/acehost-cache-warm <domain>` after any live change.

## Gotchas (hard-won — do not rediscover these)

- **Manual `_ace_seo_title` on singular posts must be stored WITHOUT the brand** — the theme appends
  the site name. All live sites (unicarts, SPKF…) store brand-free titles relying on this. Since
  1.0.6 the `is_singular` branch clears `site`/`tagline` ONLY for template-derived titles (templates
  contain `{site_name}`); manual titles keep the theme-append behaviour. Do not make that clearing
  unconditional — it strips the brand from every consumer site's manual titles.
- The frontend de-dupes OG tags via a full-page output buffer + regex (`filter_head_output`) — fragile;
  archives that want og:description must mark output `data-ace-seo="1"` to survive it.
- Sitemap hardcodes an `ace_event` taxonomy exclusion (powertools lines ~33/102).
- Schema is emitted from FOUR places (frontend graph, schema class, performance footer cache, breadcrumbs
  none) — the guest footer path can double-emit Article. Roadmap phase 1 consolidates this; until then,
  add new types into the frontend `@graph`, not a new emitter.
- ace_seo's core `rel_canonical` removal mis-times (hooked too late) — consumer themes work around it;
  keep that in mind when touching canonical output.
- `test-*.php` / `fix-slashes.php` in the root are legacy dev scripts scheduled for removal (roadmap 0) —
  don't extend them.
