<!-- ACE_SUITE_BRAND:START -->
<p align="center">
  <a href="https://acemedia.ninja/"><img src="docs/branding/ace-suite-header.svg" alt="Ace Media WordPress plugins, by Shane Rounce" width="960"></a>
</p>

<p align="center">
  <a href="https://acemedia.ninja/">Ace Media</a> · <a href="https://acemedia.host/">WordPress hosting</a>
</p>
<!-- ACE_SUITE_BRAND:END -->

# Ace Crawl Enhancer

I built Ace Crawl Enhancer to manage WordPress SEO metadata, social previews, crawl settings and sitemap workflows in one place. It is part of my Ace Media plugin suite.

Version **1.0.93** is a local release candidate. It requires **WordPress 6.0+ and PHP 7.4+**. Release verification and remaining checks are recorded separately; a candidate is not a WordPress.org approval or a live deployment.

## What it does

- Edit SEO titles, descriptions, canonicals and robots directives, with Open Graph and Twitter card output.
- Build XML sitemap lists and cached generations, with exclusions and support for published content changes.
- Add breadcrumb navigation and structured-data providers.
- Review content retention and redirects, and optionally enable aggregate view counts.
- Import supported Yoast metadata and use optional Google, Microsoft Clarity and writing-service connections.

It follows WordPress's visibility settings. A physical `robots.txt`, a theme, another SEO plugin or a cache can still affect the final response: verify actual rendered pages and sitemap URLs before changing a site's indexing policy. Back up before migration and compare representative titles, canonicals, robots directives and structured data on staging.

## Install and build

Install the ZIP produced by `python3 scripts/build-release.py`, then activate the plugin and review its settings. The ZIP contains runtime files only. GitHub keeps the readable source, tests and build instructions in [the developer guide](docs/wiki/Development.md).

For development, use Node/npm with the declared build dependencies, run `npm install` and `npm run build`. The build produces the breadcrumbs and settings assets plus admin CSS. Most admin scripts are readable files in `assets/js/`.

## Privacy and optional connections

Local SEO output does not need an external account. Optional integrations send the data necessary for the feature you enable, such as a tested URL to PageSpeed, authorised reporting requests to Google or Microsoft, or selected writing content to a configured AI provider. See [the service disclosure](readme.txt) and [privacy and deployment notes](docs/wiki/Privacy-and-deployment.md). Credentials stay out of GitHub and release archives.

Existing metadata, settings and analytics are retained on uninstall. Remove them separately only after exporting anything you need. Site-specific behaviour belongs in a local adapter using supported hooks, not in public defaults.

## Developers and contributors

[Developer index](docs/wiki/Home.md) · [Schema provider API](docs/schema-api.md) · [Contribution policy](CONTRIBUTING.md) · [Security reports](SECURITY.md)

I welcome public issues with reproducible, fictional examples. Code contributions are by invitation and remain subject to review. The GPL licence still permits modification and redistribution.

## More from Ace Media

[Ace Media](https://acemedia.ninja/) · [WordPress hosting](https://acemedia.host/) · [Ace Media repositories](https://github.com/AceMedia)

Related tools should be chosen for the site: Ace Revisions for revision workflows, Ace Taxonomy Tools for term management, and Ace Social Planner for preparing posts and calendar plans. None is required for basic SEO output.
