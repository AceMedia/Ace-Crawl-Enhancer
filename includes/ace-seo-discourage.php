<?php
/**
 * Search engine visibility ("Discourage search engines from indexing this site").
 *
 * WordPress core's only reaction to blog_public = 0 is a noindex <meta> tag and a
 * Disallow in robots.txt. This plugin bolts a lot more discoverability onto a site —
 * sitemaps and custom sitemap routes, sitemap <link> tags, per-post robots meta,
 * schema graph — none of which core knows to switch off. This file makes the whole
 * plugin honour the setting from the single choke point below.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Is the site asking search engines to stay away?
 *
 * @return bool
 */
function ace_seo_site_is_discouraged() {
    /**
     * Filter the discouraged state — lets a site force crawl suppression (for
     * example on a staging host) without touching the Reading setting.
     *
     * @param bool $discouraged
     */
    return (bool) apply_filters( 'ace_seo_site_is_discouraged', '0' === (string) get_option( 'blog_public' ) );
}

/**
 * How a discouraged site should behave.
 *
 * 'block'   — keep crawlers out entirely. Right for a site that was never indexed.
 * 'deindex' — let crawlers in and tell them noindex, so pages already in the index
 *             actually leave it. This is the counterintuitive half: a crawler that
 *             is refused the page never reads the noindex on it, so blocking the
 *             site is what keeps stale results alive. Sitemaps stay served on
 *             purpose here — they are what brings a crawler back to each URL to
 *             see the directive.
 *
 * Set per environment with ACE_SEO_DISCOURAGE_MODE in wp-config.php, since which
 * one is right depends on the site's history rather than on its code. Both modes
 * are inert while the site is public.
 *
 * @return string 'block' or 'deindex'.
 */
function ace_seo_discourage_mode() {
    $mode = defined( 'ACE_SEO_DISCOURAGE_MODE' ) ? (string) ACE_SEO_DISCOURAGE_MODE : 'block';

    /**
     * Filter the discourage mode.
     *
     * @param string $mode 'block' or 'deindex'.
     */
    $mode = (string) apply_filters( 'ace_seo_discourage_mode', $mode );

    return 'deindex' === $mode ? 'deindex' : 'block';
}

/**
 * The robots directives for the current mode, as a comma-separated string.
 *
 * 'follow' in de-index mode is deliberate: the crawler should keep walking the
 * site, because every page it reaches is another one that needs to see a noindex.
 *
 * @return string
 */
function ace_seo_discourage_directives() {
    return 'deindex' === ace_seo_discourage_mode()
        ? 'noindex, follow'
        : 'noindex, nofollow, noarchive, nosnippet, noimageindex';
}

/**
 * Force a hard noindex on every page, overriding anything a theme, another plugin
 * or a per-post meta value asked for.
 *
 * @param array $robots Core's robots directives.
 * @return array
 */
function ace_seo_force_noindex_robots( $robots ) {
    if ( ! ace_seo_site_is_discouraged() ) {
        return $robots;
    }

    // Drop every positive directive before adding ours, so we never emit
    // "index, noindex" and leave the decision to the crawler.
    unset( $robots['index'], $robots['follow'], $robots['archive'], $robots['snippet'], $robots['imageindex'] );
    unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );

    $robots['noindex'] = true;

    if ( 'deindex' === ace_seo_discourage_mode() ) {
        // Core adds nofollow of its own accord once the site is discouraged, and
        // "nofollow, follow" is a directive a crawler is entitled to read either way.
        unset( $robots['nofollow'], $robots['noarchive'], $robots['nosnippet'], $robots['noimageindex'] );

        $robots['follow'] = true;

        return $robots;
    }

    $robots['nofollow']     = true;
    $robots['noarchive']    = true;
    $robots['nosnippet']    = true;
    $robots['noimageindex'] = true;

    return $robots;
}
add_filter( 'wp_robots', 'ace_seo_force_noindex_robots', PHP_INT_MAX );

/**
 * Send the same directives as an HTTP header.
 *
 * A <meta> tag only exists inside HTML, which leaves everything else on the site
 * advertising itself: RSS and Atom feeds, attachments, PDFs, images, plain files.
 * X-Robots-Tag applies to any response, so it closes that gap.
 *
 * @return void
 */
function ace_seo_send_noindex_header() {
    if ( is_admin() || headers_sent() || ! ace_seo_site_is_discouraged() ) {
        return;
    }

    header( 'X-Robots-Tag: ' . ace_seo_discourage_directives(), true );
}
add_action( 'send_headers', 'ace_seo_send_noindex_header' );

/**
 * Belt and braces for robots.txt: core already adds "Disallow: /" when the site is
 * discouraged, but it also leaves any Sitemap: lines other code appended. Replace
 * the whole body so nothing points a crawler back at the content.
 *
 * @param string $output Robots.txt body.
 * @param string $public The blog_public option.
 * @return string
 */
function ace_seo_filter_robots_txt( $output, $public ) {
    if ( ! ace_seo_site_is_discouraged() ) {
        return $output;
    }

    $path = (string) wp_parse_url( site_url(), PHP_URL_PATH );
    $path = '/' === $path ? '' : untrailingslashit( (string) $path );

    if ( 'deindex' === ace_seo_discourage_mode() ) {
        // Crawling has to be allowed for the noindex on each page to be read at
        // all, and the sitemap is what sends a crawler back round to read them.
        return "User-agent: *\n"
            . "Disallow: $path/wp-admin/\n"
            . "Allow: $path/wp-admin/admin-ajax.php\n\n"
            . 'Sitemap: ' . home_url( '/wp-sitemap.xml' ) . "\n";
    }

    return "User-agent: *\nDisallow: /\n";
}
add_filter( 'robots_txt', 'ace_seo_filter_robots_txt', PHP_INT_MAX, 2 );

// No sitemaps at all while discouraged — core's index, our providers, our routes.
add_filter( 'wp_sitemaps_enabled', function ( $enabled ) {
    if ( ! ace_seo_site_is_discouraged() ) {
        return $enabled;
    }

    // Core switches its own sitemaps off from blog_public, so de-index mode has to
    // turn them back on rather than merely decline to disable them: without the
    // registered providers the custom routes have nothing to render and 404.
    return 'deindex' === ace_seo_discourage_mode();
}, PHP_INT_MAX );

/**
 * Switch off every Sitemap Powertools feature while discouraged. This is the single
 * gate every powertools feature check runs through (custom routes, sitemap headers,
 * index links, legacy redirects), so one filter covers the lot.
 *
 * @param bool   $enabled Whether the feature is on.
 * @param string $key     Feature key.
 * @return bool
 */
add_filter( 'ace_sitemap_powertools_is_enabled', function ( $enabled, $key ) {
    return ( ace_seo_site_is_discouraged() && 'block' === ace_seo_discourage_mode() ) ? false : $enabled;
}, PHP_INT_MAX, 2 );

/**
 * Clear caches when the visibility setting is toggled.
 *
 * Without this, a page cache keeps serving the HTML that was generated while the
 * site was still public — meta robots tag and all — so the setting appears to do
 * nothing. Bumping our sitemap cache version and asking the Redis page cache to
 * flush makes the change take effect on the next request.
 *
 * @param mixed $old Previous value.
 * @param mixed $new New value.
 * @return void
 */
function ace_seo_visibility_changed( $old, $new ) {
    if ( (string) $old === (string) $new ) {
        return;
    }

    if ( function_exists( 'ace_sitemap_powertools_bump_cache_version' ) ) {
        ace_sitemap_powertools_bump_cache_version();
    }

    // Rewrite rules gate the sitemap routes; they change with visibility.
    if ( function_exists( 'flush_rewrite_rules' ) ) {
        flush_rewrite_rules( false );
    }

    // The page cache is the reason this setting looks like it does nothing: it
    // keeps serving HTML generated while the site was still public, noindex-free.
    // Guarded twice over: purging is a convenience, and it must never be able to
    // break saving the setting itself. Without the Redis extension (the CLI SAPI
    // often lacks it) the cache manager throws on connect.
    if ( function_exists( 'ace_redis_cache' ) && class_exists( 'Redis' ) ) {
        try {
            $redis = ace_redis_cache();
            if ( $redis && method_exists( $redis, 'get_cache_manager' ) ) {
                $manager = $redis->get_cache_manager();
                if ( $manager && method_exists( $manager, 'clear_all_cache' ) ) {
                    $manager->clear_all_cache();
                }
            }
        } catch ( \Throwable $e ) {
            // Leave the cache alone; the admin notice tells the user to clear it.
        }
    }

    /**
     * Fires when search engine visibility changes. Other page caches should flush here.
     *
     * @param bool $discouraged Whether the site is now discouraged.
     */
    do_action( 'ace_seo_visibility_changed', '0' === (string) $new );
}
add_action( 'update_option_blog_public', 'ace_seo_visibility_changed', 10, 2 );

/**
 * Tell the admin, on our own screens, that nothing this plugin does will be crawled.
 *
 * @return void
 */
function ace_seo_discouraged_admin_notice() {
    if ( ! ace_seo_site_is_discouraged() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || false === strpos( (string) $screen->id, 'ace-seo' ) ) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
        esc_html__( 'Search engines are discouraged.', 'ace-crawl-enhancer' ),
        wp_kses_post(
            sprintf(
                /* translators: %s: link to the Reading settings screen. */
                __( 'Sitemaps and indexing are switched off site-wide, and every page is served <code>noindex</code>. Change this under %s.', 'ace-crawl-enhancer' ),
                '<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Settings → Reading', 'ace-crawl-enhancer' ) . '</a>'
            )
        )
    );
}
add_action( 'admin_notices', 'ace_seo_discouraged_admin_notice' );
