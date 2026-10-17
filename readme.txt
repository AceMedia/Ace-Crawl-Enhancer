=== Ace Crawl Enhancer ===
Contributors: shanerounce
Tags: seo, sitemap, schema, yoast, meta
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.92
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced SEO plugin with seamless Yoast migration, modern interface, AI-powered optimization, and comprehensive SEO features.

== Description ==

Ace Crawl Enhancer is a full SEO toolkit for WordPress with a one-click Yoast migration path. It handles titles, meta descriptions, canonicals and robots directives, Open Graph and Twitter cards, a single JSON-LD schema graph per page with a provider API, extended XML sitemaps (news, authors, tags, exclusions), a breadcrumbs block, Search Console and Site Kit readers, and optional AI-assisted writing tools.

It respects WordPress's own crawl settings (Discourage search engines, core sitemaps, the wp_robots filter) rather than adding parallel switches, and ships a command-line crawl settings check so staging and live never drift apart.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it through the Plugins screen.
3. Configure it from the plugin's settings screen where one exists.

== Frequently Asked Questions ==

= Where do I report a bug or ask for a feature? =

Open an issue on the plugin's GitHub repository.

== Changelog ==

= 1.0.92 =
* Hide the aggregate view-count footer panel and consent-banner note by default, with an opt-in filter. Keep counting and existing privacy signals unchanged.

= 1.0.91 =
* Retention redirect review and an opt-in approved queue on every site, with expiring approvals, bounded scheduling, audit history and guarded undo.
* Explicit recorded link review when no link-data provider is connected; unknown traffic is never assumed to be zero.
* Site-bound queue configuration prevents database clones from carrying enabled processing to another site.
* Destination search uses literal titles independently of frontend full-text search filters.

= 1.0.62 =
* Initial public release.
