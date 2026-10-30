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

The current source and WordPress.org review candidate are **1.0.95**. The latest published [GitHub release is 1.0.92](https://github.com/AceMedia/Ace-Crawl-Enhancer/releases/tag/v1.0.92); it does not include the newer review fixes. The plugin requires **WordPress 6.0+ and PHP 7.4+**. Publishing a GitHub release, updating the review submission and receiving WordPress.org approval are separate steps.

## What it does

- Edit SEO titles, descriptions, canonicals and robots directives, with Open Graph and Twitter card output.
- Build XML sitemap lists and cached generations, with exclusions and support for published content changes.
- Add breadcrumb navigation and structured-data providers.
- Review content retention and redirects, and optionally enable aggregate view counts.
- Import supported Yoast metadata and use optional Google, Microsoft Clarity and writing-service connections.

It follows WordPress's visibility settings. A physical `robots.txt`, a theme, another SEO plugin or a cache can still affect the final response: verify actual rendered pages and sitemap URLs before changing a site's indexing policy. Back up before migration and compare representative titles, canonicals, robots directives and structured data on staging.

## Install the current candidate

From this repository's root, with Node.js/npm and Python 3.9 or later installed, run these commands in order:

```sh
npm install
npm run build
python3 scripts/build-release.py
```

This creates `dist/ace-crawl-enhancer-1.0.95.zip` and its `.zip.sha256` checksum file. The ZIP contains the reviewed runtime files. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose that ZIP, install it and activate **Ace Crawl Enhancer**. If an older copy is installed, review WordPress's replacement prompt. Use the generated plugin ZIP, rather than GitHub's automatically generated source archive.

Open **Ace SEO → Settings** for site-wide options. Start on a staging site and keep a backup when replacing another SEO plugin. The [developer guide](docs/wiki/Development.md) explains the source and release checks.

## Try one SEO title

Edit a published page, such as an About page. In the **Ace SEO** box below the editor, open the **SEO** tab, enter `About our workshop` in **SEO Title**, then save the page using WordPress's **Update** or **Save** button.

Open the public page in a logged-out window and view its source. The HTML `<title>` should contain `About our workshop`; WordPress or the theme may append the site name. The page's visible heading stays as it was. This works locally without an account, API key or AI connection. Protected, private and preview content deliberately keeps its content-derived metadata out of public output.

If the old title remains, clear that page's cache and refresh. Check for a theme or another SEO plugin printing a competing title or metadata before changing more settings.

## Privacy and optional connections

Local SEO output does not need an external account. Optional integrations send the data necessary for the feature you enable, such as a tested URL to PageSpeed, authorised reporting requests to Google or Microsoft, or selected writing content to a configured AI provider. See [the service disclosure](readme.txt) and [privacy and deployment notes](docs/wiki/Privacy-and-deployment.md). Credentials stay out of GitHub and release archives.

Existing metadata, settings and analytics are retained on uninstall. Remove them separately only after exporting anything you need. Site-specific behaviour belongs in a local adapter using supported hooks, not in public defaults.

## Developers and contributors

[Developer index](docs/wiki/Home.md) · [Schema provider API](docs/schema-api.md) · [Contribution policy](CONTRIBUTING.md) · [Security reports](SECURITY.md)

I welcome public issues with reproducible, fictional examples. Code contributions are by invitation and remain subject to review. The GPL licence still permits modification and redistribution.

## More from Ace Media

[Ace Media](https://acemedia.ninja/) · [WordPress hosting](https://acemedia.host/) · [Ace Media repositories](https://github.com/AceMedia)

Related tools should be chosen for the site: Ace Revisions for revision workflows, Ace Taxonomy Tools for term management, and Ace Social Planner for preparing posts and calendar plans. None is required for basic SEO output.
