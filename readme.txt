=== Ace Crawl Enhancer ===
Contributors: shanerounce
Tags: seo, sitemap, schema, yoast, meta
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.93
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage SEO metadata, social previews, crawl settings, XML sitemaps and content retention, with optional reporting connections.

== Description ==

I built Ace Crawl Enhancer to bring SEO metadata, social previews, crawl settings and sitemap workflows together. It supports titles, descriptions, canonicals, robots directives, structured-data providers, breadcrumb navigation, retention reviews and supported Yoast metadata imports.

The plugin follows WordPress visibility settings. Test migrations and indexing changes on staging, including rendered pages, sitemaps and any physical robots.txt or external cache. Optional integrations do not replace a site's own privacy and consent decisions.

= External services =

Core local SEO output does not require an external account. These connections are optional and require the relevant configuration or authorisation:

* OpenAI: selected writing prompts/content, model requests and optional image requests are sent when using configured writing tools. Account sign-in may use OpenAI authentication endpoints; a host can also supply its own Codex runner through a filter. Terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/
* Google PageSpeed Insights: the URL selected for a performance test, optional API key and test parameters are sent to Google. Google Search Console and Analytics connections request authorised property reports and may submit a configured sitemap or inspect a URL. Google Sheets integration reads or writes the configured spreadsheet. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy
* Anthropic: when the optional White hat check is set to Anthropic, the post headline and a short excerpt are sent with the site owner's own API key to classify the article. Terms: https://www.anthropic.com/legal/consumer-terms Privacy: https://www.anthropic.com/legal/privacy
* Wikipedia and Wikimedia: the optional Trending topics report sends the English Wikipedia article titles the site owner lists to the public Wikipedia summary and Wikimedia pageviews APIs. Terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use Privacy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy
* Microsoft Clarity: when configured, the plugin requests project reporting data with the saved API credential. Terms: https://clarity.microsoft.com/terms Privacy: https://privacy.microsoft.com/privacystatement

Site Kit, where installed and connected, supplies the relevant Google authorisation. The site operator chooses which services to enable and is responsible for account permissions, costs and disclosures. Selected content and URLs may contain personal data: review them before sending them to a service.

= Source code =

The compiled block editor files in `build/` are built from the readable source in the `src/` folder of the public repository: https://github.com/AceMedia/Ace-Crawl-Enhancer. Run `npm install` and `npm run build` there to rebuild them.

= Local data =

Settings and SEO metadata are stored in WordPress. Optional aggregate view tracking and reporting create local data; review the controls and retention policy before enabling them. Sitemap generations are cached locally. Uninstall retains metadata, settings and analytics so that removal does not silently erase SEO work. Export and remove retained data separately if required.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it through the Plugins screen.
3. Review the Ace SEO settings, visibility options and any optional connections.
4. Check rendered SEO output and sitemap URLs on staging before switching from another SEO plugin.

== Frequently Asked Questions ==

= Where do I report a bug or ask for a feature? =

Open an issue at https://github.com/AceMedia/Ace-Crawl-Enhancer/issues using a fictional reproduction. Code contributions are by invitation. GPL rights to use, modify and redistribute still apply.

== Changelog ==

= 1.0.93 =
* Add an early unsupported-PHP guard and align the candidate metadata.
* Initialise each site during network activation and when a new network site is created.
* Clear site tasks on deactivation while retaining existing SEO data and shared service settings on uninstall.
* Prepare a runtime-only release archive and contributor documentation.


= 1.0.92 =
* Hide the aggregate view-count footer panel and consent-banner note by default, with an opt-in filter. Keep counting and existing privacy signals unchanged.

= 1.0.91 =
* Retention redirect review and an opt-in approved queue on every site, with expiring approvals, bounded scheduling, audit history and guarded undo.
* Explicit recorded link review when no link-data provider is connected; unknown traffic is never assumed to be zero.
* Site-bound queue configuration prevents database clones from carrying enabled processing to another site.
* Destination search uses literal titles independently of frontend full-text search filters.

= 1.0.62 =
* Initial public release.
