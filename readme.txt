=== Ace Crawl Enhancer ===
Contributors: shanerounce
Tags: seo, sitemap, schema, yoast, meta
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.95
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage SEO metadata, social previews, crawl settings, XML sitemaps and content retention, with optional reporting connections.

== Description ==

I built Ace Crawl Enhancer to bring SEO metadata, social previews, crawl settings and sitemap workflows together. It supports titles, descriptions, canonicals, robots directives, structured-data providers, breadcrumb navigation, retention reviews and supported Yoast metadata imports.

The plugin follows WordPress visibility settings. Test migrations and indexing changes on staging, including rendered pages, sitemaps and any physical robots.txt or external cache. Optional integrations do not replace a site's own privacy and consent decisions.

= External services =

Core local SEO output does not need an external account. The optional connections below send requests only when their relevant features are configured and used, including enabled scheduled jobs. Requests also reveal the server's IP address to the receiving service. Review selected content, URLs and report fields before enabling a connection.

* WordPress AI Client: the recommended text connection uses the provider and model configured in WordPress, on versions supporting that API. After saving this connection and enabling text, writing requests send the selected prompt, title, excerpt, content and instructions to that provider. White hat classification also requires selecting this connection in its own settings. The provider's own terms and privacy policy apply; review the provider plugin and WordPress connection settings before enabling it. Existing explicit API keys and ChatGPT connections are retained until changed. Missing providers and failed requests do not fall back to a different account.
* OpenAI (api.openai.com and auth.openai.com): explicit OpenAI connections send selected prompts, content, model identifiers and authorisation credentials for writing, connection checks, model listing and optional image generation. Requested images are downloaded from the URL returned by OpenAI. White hat checks send a headline and short excerpt when OpenAI is selected. Account sign-in refreshes tokens with OpenAI; a host-supplied Codex runner may handle sign-in and text generation when configured by the operator. No runner or account is supplied by this plugin. API terms: https://openai.com/policies/services-agreement/ Account terms: https://openai.com/policies/terms-of-use/ Privacy: https://openai.com/policies/privacy-policy/
* Google PageSpeed Insights (www.googleapis.com): requested performance tests send the chosen page URL, test parameters and a configured API key or authorised token. Google fetches the page to measure it. Google Search Console (www.googleapis.com and searchconsole.googleapis.com) and Analytics (analyticsdata.googleapis.com): configured reports send property identifiers, date ranges, filters and authorisation tokens; requested sitemap submission and URL inspection also send the sitemap or page URL. Google Sheets (sheets.googleapis.com and oauth2.googleapis.com): configured imports and exports send spreadsheet identifiers, ranges, selected SEO/report rows and authorisation credentials; scheduled exports run only when enabled. Site Kit can supply Google authorisation; Sheets also supports a configured service account. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy
* Anthropic (api.anthropic.com): when White hat classification is enabled with Anthropic selected, manual and scheduled checks send the post headline, short excerpt, classification instructions, model identifier and configured API key. Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/legal/privacy
* Wikipedia and Wikimedia (en.wikipedia.org and wikimedia.org): the optional Trending topics report sends the English Wikipedia article titles configured by the operator, a date range and a descriptive user agent containing the site's URL to the summary and pageviews APIs. Configured topics are refreshed by the weekly job and when a refresh is requested. Terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use Privacy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy
* Microsoft Clarity (www.clarity.ms): configured reporting requests send the saved project API credential and reporting period to retrieve project insights when the report refreshes. The plugin does not install the Clarity visitor tracking script. Terms: https://clarity.microsoft.com/terms Privacy: https://privacy.microsoft.com/privacystatement

The tracking report recognises scripts already present on your pages, such as analytics, advertising pixels and consent tools. Recognising a script does not install it or send data to that vendor. Schema.org identifiers and social profile links in SEO output are references, not server-side service requests. Local page and robots.txt checks request your own site's URLs. The operator chooses which connections and schedules to enable and remains responsible for account permissions, costs and visitor-facing disclosures.

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

= 1.0.95 =
* Keep password-protected, private and preview content out of public descriptions, social metadata and structured data.


= 1.0.94 =
* Load interface assets through WordPress and keep notices on relevant screens.
* Tighten request permissions, image imports and public recommendation visibility.
* Correct activation SQL, request sanitisation and report escaping.
* Use the core-owned response buffer and keep report buffers balanced.
* Add an optional WordPress AI Client connection and clarify external service disclosures.

= 1.0.93 =
* Add an early unsupported-PHP guard and align the candidate metadata.
* Initialise each site during network activation and when a new network site is created.
* Clear site tasks on deactivation while retaining existing SEO data and shared service settings on uninstall.
* Prepare a runtime-only release archive and contributor documentation.
* The human-readable sitemap uses the active theme's fonts and colours from theme.json instead of fixed values.
* White hat checks are fully configurable: neutral wording, empty starting lists and an editable AI instruction. Existing saved settings carry over unchanged.
* Informational robots.txt notices can be dismissed.


= 1.0.92 =
* Hide the aggregate view-count footer panel and consent-banner note by default, with an opt-in filter. Keep counting and existing privacy signals unchanged.

= 1.0.91 =
* Retention redirect review and an opt-in approved queue on every site, with expiring approvals, bounded scheduling, audit history and guarded undo.
* Explicit recorded link review when no link-data provider is connected; unknown traffic is never assumed to be zero.
* Site-bound queue configuration prevents database clones from carrying enabled processing to another site.
* Destination search uses literal titles independently of frontend full-text search filters.

= 1.0.62 =
* Initial public release.
