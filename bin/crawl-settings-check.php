<?php
/**
 * Crawl settings consistency check — run before and after any change that touches
 * indexing, robots directives or sitemaps, on every environment.
 *
 *   php bin/crawl-settings-check.php https://example.com/ [https://dev.example.com/]
 *
 * This plugin decides what crawlers are told, but it is not the only thing that
 * gets a say: WordPress core has its own settings, and the web server outranks
 * both. When those disagree the site does something nobody asked for, silently —
 * a ticked "Discourage search engines" that a stale robots.txt overrides, a
 * noindex page still advertised in a sitemap, a feed with no directive at all.
 *
 * Each of those is a contradiction between layers rather than a bug in any one of
 * them, which is why they survive code review. This checks the layers against each
 * other and exits 1 on a real conflict.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

$urls = array_slice( $argv, 1 );

if ( empty( $urls ) ) {
    fwrite( STDERR, "Usage: php bin/crawl-settings-check.php <url> [<url>...]\n" );
    exit( 1 );
}

/**
 * Fetch a URL, returning status, headers (lowercased keys) and body.
 */
function acscc_fetch( $url ) {
    $ctx = stream_context_create(
        array(
            'http' => array(
                'timeout'         => 20,
                // A bot-shaped user agent gets blocked by bot firewalls (AWS WAF fronts live),
                // and a 403 from a firewall looks exactly like a broken URL. Ask as a browser.
                'user_agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
                'follow_location'  => 1,
                'ignore_errors'   => 1,
            ),
            'ssl'  => array( 'verify_peer' => false, 'verify_peer_name' => false ),
        )
    );

    $body    = @file_get_contents( $url, false, $ctx );
    $status  = 0;
    $headers = array();

    foreach ( (array) ( $http_response_header ?? array() ) as $line ) {
        if ( preg_match( '~^HTTP/\S+\s+(\d{3})~', $line, $m ) ) {
            $status  = (int) $m[1];
            $headers = array();
            continue;
        }

        $parts = explode( ':', $line, 2 );
        if ( 2 === count( $parts ) ) {
            $headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
        }
    }

    return array(
        'status'  => $status,
        'headers' => $headers,
        'body'    => false === $body ? '' : $body,
    );
}

$exit = 0;

foreach ( $urls as $url ) {
    $base = rtrim( $url, '/' );
    echo "== $base\n";

    $problems = array();
    $notes    = array();

    // --- Layer 1: robots.txt, which the web server can serve without WordPress.
    $robots     = acscc_fetch( $base . '/robots.txt' );
    $robots_txt = $robots['body'];
    $blocks_all = (bool) preg_match( '~^\s*Disallow:\s*/\s*$~mi', $robots_txt );
    $sitemaps   = array();
    preg_match_all( '~^\s*Sitemap:\s*(\S+)~mi', $robots_txt, $m );
    $sitemaps = $m[1];

    // WordPress always names wp-admin in a public robots.txt, and a discouraged one
    // is exactly "Disallow: /". A file with neither is almost certainly a real file
    // on disk shadowing the generated one — the failure mode that looks like the
    // plugin ignoring its own settings.
    // Core generates only a wp-admin block plus Sitemap lines, or exactly
    // "Disallow: /" when discouraged. Anything else — extra Disallow rules, or
    // neither of those shapes — means something other than WordPress wrote this.
    preg_match_all( '~^\s*Disallow:\s*(\S+)~mi', $robots_txt, $dm );
    $foreign_rules = array_filter(
        $dm[1],
        static function ( $rule ) {
            return ! preg_match( '~^/(?:[a-z0-9_-]+/)?wp-(admin|login)~i', $rule ) && '/' !== $rule;
        }
    );

    $looks_static = 200 === $robots['status']
        && ( ! empty( $foreign_rules ) || ( false === stripos( $robots_txt, 'wp-admin' ) && ! $blocks_all ) );

    // --- Layer 2: what the HTML and headers say.
    $home    = acscc_fetch( $base . '/' );
    $meta    = preg_match( '~<meta\s+name=[\'"]robots[\'"][^>]*content=[\'"]([^\'"]*)[\'"]~i', $home['body'], $m )
        ? strtolower( $m[1] )
        : '';
    $header  = strtolower( $home['headers']['x-robots-tag'] ?? '' );
    $noindex = false !== strpos( $meta, 'noindex' ) || false !== strpos( $header, 'noindex' );

    // --- Layer 3: what is being advertised for crawling.
    $index_status = acscc_fetch( $base . '/wp-sitemap.xml' )['status'];
    $feed         = acscc_fetch( $base . '/feed/' );
    $feed_noindex = false !== stripos( $feed['headers']['x-robots-tag'] ?? '', 'noindex' );

    printf(
        "   robots.txt %d%s | meta robots: %s | X-Robots-Tag: %s | wp-sitemap.xml %d | feed %d\n",
        $robots['status'],
        $blocks_all ? ' (Disallow: /)' : '',
        '' !== $meta ? $meta : '(none)',
        '' !== $header ? $header : '(none)',
        $index_status,
        $feed['status']
    );

    // --- The checks that matter are between the layers, not inside them.
    if ( $blocks_all && 200 === $index_status ) {
        $problems[] = 'robots.txt blocks everything but wp-sitemap.xml still serves 200 — the site is advertising URLs it forbids anyone to fetch.';
    } elseif ( $noindex && 200 === $index_status ) {
        $notes[] = 'noindex pages, crawlable, sitemap served — the de-index posture: the sitemap is what brings a crawler back to each URL to read the noindex. '
            . 'Switch back to blocking once the pages have dropped out.';
    }

    if ( ! $noindex && $blocks_all ) {
        $problems[] = 'robots.txt blocks everything but pages carry no noindex — crawlers are shut out without being told not to index, so known URLs can stay listed.';
    }

    if ( $noindex && $feed['status'] < 400 && ! $feed_noindex ) {
        $problems[] = 'Pages say noindex but /feed/ returns no X-Robots-Tag — a meta tag cannot reach a feed, so the content is still offered.';
    }

    if ( $looks_static && $noindex ) {
        $problems[] = 'Pages say noindex but robots.txt is not WordPress output — a file on disk is served before PHP runs, '
            . 'so the crawl settings in the plugin are being ignored. This is what makes a ticked "Discourage search engines" do nothing.';
    } elseif ( $looks_static ) {
        $notes[] = 'robots.txt is not WordPress output — it is a file on disk, or a filter outside this plugin. Edit it there; '
            . 'the plugin cannot change it, and ticking "Discourage search engines" would not either. Leave it alone if its rules are deliberate.';
    }

    foreach ( $sitemaps as $sitemap ) {
        $status = acscc_fetch( $sitemap )['status'];

        if ( 403 === $status ) {
            $notes[] = sprintf( '%s returned 403 — usually a bot firewall rather than a broken sitemap. Verify from an allowed address.', $sitemap );
        } elseif ( $status >= 400 ) {
            $problems[] = sprintf( 'robots.txt advertises %s but it returns %d.', $sitemap, $status );
        }
    }

    // Not a fault, but the distinction decides whether indexed pages can ever leave
    // the index, and it is the one people get backwards.
    if ( $noindex && $blocks_all ) {
        $notes[] = 'Crawling is blocked AND noindex is set. Right for a site that was never indexed; wrong if you want indexed pages removed, '
            . 'because a crawler that cannot fetch the page never reads the noindex. To de-index, allow crawling and keep noindex until the pages drop out.';
    }

    foreach ( $problems as $problem ) {
        echo "   FAIL  $problem\n";
        $exit = 1;
    }

    foreach ( $notes as $note ) {
        echo "   note  $note\n";
    }

    if ( empty( $problems ) ) {
        echo "   OK    layers agree\n";
    }
}

exit( $exit );
