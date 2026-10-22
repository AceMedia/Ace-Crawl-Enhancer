# Compatibility

The declared minimum is WordPress 6.0 and PHP 7.4. Verify each release ZIP on the minimum versions and current supported versions. Test fresh installation and upgrades, single-site and multisite activation, a site with no optional integrations, and WooCommerce where relevant.

Preserve existing `_ace_seo_*` post metadata, `ace_seo_options`, sitemap options, block names and attributes, public hooks and their arguments. Existing Yoast-shaped form inputs are intentional compatibility behaviour. A site integration can use [documented hooks](Hooks.md) for local policy; avoid baking client-specific content or rules into the shared plugin.

Check the rendered title, description, canonical, robots directives, sitemap, schema and breadcrumbs against the site's previous behaviour. A physical `robots.txt`, WordPress's `blog_public` setting, page caches, theme output and other SEO plugins can change what visitors and crawlers see. Unsupported PHP versions must fail gracefully without loading incompatible runtime code.
