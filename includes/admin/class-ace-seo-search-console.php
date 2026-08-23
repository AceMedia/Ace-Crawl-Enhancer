<?php
/**
 * Google Search Console sitemap management and page-performance signals.
 *
 * Everything here is Site Kit-gated and additive: it borrows the site's
 * connected Site Kit OAuth token via AceSEOSiteKit, and degrades silently
 * (returns a WP_Error or a "not connected" shape) whenever Site Kit is
 * absent, disconnected, or has no Search Console property. No Google call
 * happens on a front-end render path - reads are lazy (REST/AJAX/CLI only)
 * and cached in transients.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOSearchConsole {

    /** Transient TTLs. */
    const TTL_SITEMAPS = 600;        // 10 minutes.
    const TTL_INSPECT  = 3600;       // 1 hour.
    const TTL_METRICS  = 21600;      // 6 hours.

    /**
     * Register REST routes.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /* ---------------------------------------------------------------------
     * Readiness helpers
     * ------------------------------------------------------------------- */

    /**
     * True when Site Kit is active and a Search Console property is known.
     */
    public static function is_ready() {
        if ( ! class_exists( 'AceSEOSiteKit' ) || ! AceSEOSiteKit::is_active() ) {
            return false;
        }
        return self::get_property() !== '';
    }

    /**
     * Resolve the Search Console property (site URL or sc-domain:... token).
     */
    public static function get_property() {
        if ( ! class_exists( 'AceSEOSiteKit' ) ) {
            return '';
        }
        return (string) AceSEOSiteKit::get_search_console_property_id();
    }

    /**
     * Permission callback shared by all routes.
     */
    public function can_manage_options() {
        return current_user_can( 'manage_options' );
    }

    /* ---------------------------------------------------------------------
     * Shared HTTP helper
     * ------------------------------------------------------------------- */

    /**
     * Perform an authorised Google API request and decode the JSON body.
     *
     * @param string      $method      HTTP method (GET, POST, PUT).
     * @param string      $url         Fully-qualified endpoint URL.
     * @param array       $scopes      Required OAuth scopes.
     * @param array|null  $body        Request body to JSON-encode, or null for none.
     * @return array|WP_Error Decoded response (empty array on 2xx with no body) or WP_Error.
     */
    private static function google_request( $method, $url, $scopes, $body = null ) {
        if ( ! class_exists( 'AceSEOSiteKit' ) || ! AceSEOSiteKit::is_active() ) {
            return new WP_Error( 'sitekit_inactive', 'Google Site Kit is not installed or active.' );
        }

        $token = AceSEOSiteKit::get_access_token( $scopes );
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        if ( empty( $token ) ) {
            return new WP_Error( 'sitekit_no_token', 'No valid Site Kit OAuth token is available.' );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
        );

        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => $headers,
        );

        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        } elseif ( 'PUT' === $method || 'POST' === $method ) {
            // Google's sitemap submit (PUT) rejects an empty body with HTTP 411
            // unless Content-Length: 0 is sent explicitly. WordPress' HTTP API
            // will not add it for an empty body, so set it by hand.
            $args['headers']['Content-Length'] = '0';
            $args['body']                      = '';
        }

        $response = wp_remote_request( $url, $args );
        return self::decode_response( $response, 'gsc_request_error' );
    }

    /**
     * Decode a Google API response into an array or WP_Error.
     */
    private static function decode_response( $response, $error_code ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw       = wp_remote_retrieve_body( $response );
        $body      = json_decode( $raw, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = $body['error']['message'] ?? ( 'Google API request failed with HTTP ' . $http_code . '.' );
            return new WP_Error( $error_code, $message, array( 'status' => $http_code ) );
        }

        return is_array( $body ) ? $body : array();
    }

    /* ---------------------------------------------------------------------
     * (a) Sitemaps: list
     * ------------------------------------------------------------------- */

    /**
     * List the sitemaps Google Search Console knows about for this property.
     *
     * @param bool $force Skip the cache.
     * @return array|WP_Error Normalised rows or WP_Error.
     */
    public static function list_sitemaps( $force = false ) {
        $property = self::get_property();
        if ( '' === $property ) {
            return new WP_Error( 'gsc_no_property', 'No Search Console property is configured in Site Kit.' );
        }

        $cache_key = 'ace_seo_gsc_sitemaps_' . md5( $property );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $url  = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property ) . '/sitemaps';
        $body = self::google_request( 'GET', $url, array( AceSEOSiteKit::SCOPE_SEARCH_CONSOLE ) );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $rows = array();
        foreach ( (array) ( $body['sitemap'] ?? array() ) as $item ) {
            $contents = array();
            foreach ( (array) ( $item['contents'] ?? array() ) as $content ) {
                $contents[] = array(
                    'type'      => (string) ( $content['type'] ?? '' ),
                    'submitted' => (int) ( $content['submitted'] ?? 0 ),
                    'indexed'   => (int) ( $content['indexed'] ?? 0 ),
                );
            }

            $rows[] = array(
                'path'            => (string) ( $item['path'] ?? '' ),
                'isSitemapsIndex' => (bool) ( $item['isSitemapsIndex'] ?? false ),
                'lastSubmitted'   => (string) ( $item['lastSubmitted'] ?? '' ),
                'lastDownloaded'  => (string) ( $item['lastDownloaded'] ?? '' ),
                'isPending'       => (bool) ( $item['isPending'] ?? false ),
                'warnings'        => (int) ( $item['warnings'] ?? 0 ),
                'errors'          => (int) ( $item['errors'] ?? 0 ),
                'contents'        => $contents,
            );
        }

        set_transient( $cache_key, $rows, self::TTL_SITEMAPS );
        return $rows;
    }

    /**
     * Clear the cached sitemaps list.
     */
    public static function clear_sitemaps_cache() {
        $property = self::get_property();
        if ( '' !== $property ) {
            delete_transient( 'ace_seo_gsc_sitemaps_' . md5( $property ) );
        }
    }

    /* ---------------------------------------------------------------------
     * (b) Sitemaps: submit
     * ------------------------------------------------------------------- */

    /**
     * Submit (or resubmit) a single sitemap feed URL to Search Console.
     *
     * @param string $feed Fully-qualified sitemap URL.
     * @return true|WP_Error
     */
    public static function submit_sitemap( $feed ) {
        $property = self::get_property();
        if ( '' === $property ) {
            return new WP_Error( 'gsc_no_property', 'No Search Console property is configured in Site Kit.' );
        }

        $feed = esc_url_raw( trim( (string) $feed ) );
        if ( '' === $feed ) {
            return new WP_Error( 'gsc_bad_feed', 'A sitemap URL is required.' );
        }

        $url    = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property )
            . '/sitemaps/' . rawurlencode( $feed );
        $result = self::google_request( 'PUT', $url, array( AceSEOSiteKit::SCOPE_SEARCH_CONSOLE_WRITE ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        self::clear_sitemaps_cache();
        return true;
    }

    /* ---------------------------------------------------------------------
     * (c) Sitemaps: resubmit all declared feeds
     * ------------------------------------------------------------------- */

    /**
     * Discover the site's declared sitemaps and submit each one.
     *
     * Discovery is generic: parse Sitemap: lines from the live robots.txt,
     * then add the WordPress core (/wp-sitemap.xml) and conventional
     * (/sitemap.xml) indexes when they respond.
     *
     * @return array|WP_Error List of { feed, ok, error } rows, or WP_Error when nothing was found.
     */
    public static function resubmit_all() {
        if ( ! self::is_ready() ) {
            return new WP_Error( 'gsc_not_ready', 'Search Console is not connected in Site Kit.' );
        }

        $feeds = self::discover_sitemaps();
        if ( empty( $feeds ) ) {
            return new WP_Error( 'gsc_no_feeds', 'No sitemaps were discovered for this site.' );
        }

        $results = array();
        foreach ( $feeds as $feed ) {
            $result    = self::submit_sitemap( $feed );
            $results[] = array(
                'feed'  => $feed,
                'ok'    => ( true === $result ),
                'error' => is_wp_error( $result ) ? $result->get_error_message() : '',
            );
        }

        return $results;
    }

    /**
     * Discover declared sitemap URLs for this site (generic, no hardcoding).
     *
     * @return string[] De-duplicated list of absolute sitemap URLs.
     */
    public static function discover_sitemaps() {
        $feeds = array();

        // 1) Sitemap: lines in the live robots.txt.
        $robots = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $robots ) && 200 === (int) wp_remote_retrieve_response_code( $robots ) ) {
            $body = wp_remote_retrieve_body( $robots );
            if ( preg_match_all( '/^\s*Sitemap:\s*(\S+)\s*$/mi', $body, $matches ) ) {
                foreach ( $matches[1] as $line ) {
                    $line = esc_url_raw( trim( $line ) );
                    if ( '' !== $line ) {
                        $feeds[] = $line;
                    }
                }
            }
        }

        // 2) Conventional indexes, added only when they actually respond.
        foreach ( array( '/wp-sitemap.xml', '/sitemap.xml' ) as $path ) {
            $candidate = home_url( $path );
            if ( in_array( $candidate, $feeds, true ) ) {
                continue;
            }
            $head = wp_remote_head( $candidate, array( 'timeout' => 10, 'redirection' => 2 ) );
            if ( ! is_wp_error( $head ) && 200 === (int) wp_remote_retrieve_response_code( $head ) ) {
                $feeds[] = $candidate;
            }
        }

        return array_values( array_unique( $feeds ) );
    }

    /* ---------------------------------------------------------------------
     * (d) URL inspection
     * ------------------------------------------------------------------- */

    /**
     * Inspect a URL's index status via the URL Inspection API.
     *
     * @param string $url Absolute URL on this property.
     * @return array|WP_Error Normalised verdict or WP_Error.
     */
    public static function inspect_url( $url ) {
        $property = self::get_property();
        if ( '' === $property ) {
            return new WP_Error( 'gsc_no_property', 'No Search Console property is configured in Site Kit.' );
        }

        $url = esc_url_raw( trim( (string) $url ) );
        if ( '' === $url ) {
            return new WP_Error( 'gsc_bad_url', 'A URL to inspect is required.' );
        }

        $cache_key = 'ace_seo_gsc_inspect_' . md5( $property . '|' . $url );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $body   = array(
            'inspectionUrl' => $url,
            'siteUrl'       => $property,
        );
        $result = self::google_request(
            'POST',
            'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
            array( AceSEOSiteKit::SCOPE_SEARCH_CONSOLE ),
            $body
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $index = $result['inspectionResult']['indexStatusResult'] ?? array();
        $out   = array(
            'url'           => $url,
            'verdict'       => (string) ( $index['verdict'] ?? '' ),
            'coverageState' => (string) ( $index['coverageState'] ?? '' ),
            'lastCrawlTime' => (string) ( $index['lastCrawlTime'] ?? '' ),
            'robotsTxtState' => (string) ( $index['robotsTxtState'] ?? '' ),
            'indexingState' => (string) ( $index['indexingState'] ?? '' ),
        );

        set_transient( $cache_key, $out, self::TTL_INSPECT );
        return $out;
    }

    /* ---------------------------------------------------------------------
     * (Part 3a) Search-performance signals
     * ------------------------------------------------------------------- */

    /**
     * Per-page search performance over the trailing window.
     *
     * @param string $url  Absolute page URL.
     * @param int    $days Trailing window in days.
     * @return array|WP_Error { clicks, impressions, ctr, position, top_queries[] } or WP_Error.
     */
    public static function page_metrics( $url, $days = 28 ) {
        $property = self::get_property();
        if ( '' === $property ) {
            return new WP_Error( 'gsc_no_property', 'No Search Console property is configured in Site Kit.' );
        }

        $url = esc_url_raw( trim( (string) $url ) );
        if ( '' === $url ) {
            return new WP_Error( 'gsc_bad_url', 'A page URL is required.' );
        }

        $days      = max( 1, min( 90, (int) $days ) );
        $cache_key = 'ace_seo_gsc_page_' . md5( $property . '|' . $url . '|' . $days );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $request = array(
            'startDate'             => gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ),
            'endDate'               => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
            'dimensions'            => array( 'query' ),
            'rowLimit'              => 10,
            'dimensionFilterGroups' => array(
                array(
                    'filters' => array(
                        array(
                            'dimension'  => 'page',
                            'operator'   => 'equals',
                            'expression' => $url,
                        ),
                    ),
                ),
            ),
        );

        $report = self::search_analytics( $property, $request );
        if ( is_wp_error( $report ) ) {
            return $report;
        }

        $clicks      = 0;
        $impressions = 0;
        $position_w  = 0.0; // Impression-weighted position accumulator.
        $top         = array();

        foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
            $row_clicks      = (int) ( $row['clicks'] ?? 0 );
            $row_impressions = (int) ( $row['impressions'] ?? 0 );
            $clicks         += $row_clicks;
            $impressions    += $row_impressions;
            $position_w     += ( (float) ( $row['position'] ?? 0 ) ) * $row_impressions;

            $top[] = array(
                'query'       => (string) ( $row['keys'][0] ?? '' ),
                'clicks'      => $row_clicks,
                'impressions' => $row_impressions,
                'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
            );
        }

        $position = $impressions > 0 ? round( $position_w / $impressions, 1 ) : 0.0;
        $ctr      = $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : 0.0;

        $out = array(
            'connected'   => true,
            'days'        => $days,
            'clicks'      => $clicks,
            'impressions' => $impressions,
            'ctr'         => $ctr,
            'position'    => $position,
            'top_queries' => $top,
            'hint'        => self::performance_hint( $clicks, $impressions, $position, $top ),
        );

        set_transient( $cache_key, $out, self::TTL_METRICS );
        return $out;
    }

    /**
     * Site-wide search totals over the trailing window (no dimension).
     *
     * @param int $days Trailing window in days.
     * @return array|WP_Error { clicks, impressions, ctr, position } or WP_Error.
     */
    public static function site_totals( $days = 28 ) {
        $property = self::get_property();
        if ( '' === $property ) {
            return new WP_Error( 'gsc_no_property', 'No Search Console property is configured in Site Kit.' );
        }

        $days      = max( 1, min( 90, (int) $days ) );
        $cache_key = 'ace_seo_gsc_totals_' . md5( $property . '|' . $days );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $request = array(
            'startDate' => gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ),
            'endDate'   => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
            'rowLimit'  => 1,
        );

        $report = self::search_analytics( $property, $request );
        if ( is_wp_error( $report ) ) {
            return $report;
        }

        $row = $report['rows'][0] ?? array();
        $out = array(
            'connected'   => true,
            'days'        => $days,
            'clicks'      => (int) ( $row['clicks'] ?? 0 ),
            'impressions' => (int) ( $row['impressions'] ?? 0 ),
            'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 1 ),
            'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
        );

        set_transient( $cache_key, $out, self::TTL_METRICS );
        return $out;
    }

    /**
     * Run a Search Analytics query against a property.
     *
     * @return array|WP_Error Raw decoded report or WP_Error.
     */
    private static function search_analytics( $property, $request ) {
        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
            . rawurlencode( $property ) . '/searchAnalytics/query';
        return self::google_request( 'POST', $url, array( AceSEOSiteKit::SCOPE_SEARCH_CONSOLE ), $request );
    }

    /**
     * Derive a generic, one-line optimisation hint from a page's metrics.
     */
    private static function performance_hint( $clicks, $impressions, $position, $top ) {
        if ( $impressions <= 0 ) {
            return 'No search impressions recorded yet for this page in the selected window.';
        }

        $top_query = isset( $top[0]['query'] ) && '' !== $top[0]['query'] ? $top[0]['query'] : '';
        $for_query = $top_query !== '' ? ' for "' . $top_query . '"' : '';

        if ( $position >= 11 && $position <= 20 ) {
            return 'On page 2' . $for_query . '; strengthen the title and intro to push onto page 1.';
        }

        if ( $position > 20 ) {
            return 'Ranking beyond page 2' . $for_query . '; the page needs stronger, more focused content to compete.';
        }

        // On page 1 but earning few clicks relative to impressions.
        $ctr = $impressions > 0 ? ( $clicks / $impressions ) : 0;
        if ( $impressions >= 20 && $ctr < 0.01 ) {
            return 'Seen but rarely clicked' . $for_query . '; sharpen the title and meta description to earn the click.';
        }

        if ( $position > 0 && $position <= 3 ) {
            return 'Ranking in the top 3' . $for_query . '; keep it fresh to hold the position.';
        }

        return 'On page 1' . $for_query . '; small title and content tweaks can lift clicks further.';
    }

    /* ---------------------------------------------------------------------
     * (e) REST routes
     * ------------------------------------------------------------------- */

    /**
     * Register Search Console REST routes on the shared namespace.
     */
    public function register_routes() {
        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/sitemaps',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_list_sitemaps' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/sitemaps/submit',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_submit_sitemap' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
                'args'                => array(
                    'feed' => array(
                        'required'          => true,
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/sitemaps/resubmit-all',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_resubmit_all' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/inspect',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_inspect' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
                'args'                => array(
                    'url' => array(
                        'required'          => true,
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/page-metrics',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_page_metrics' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
                'args'                => array(
                    'url'  => array(
                        'required'          => true,
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                    'days' => array(
                        'default'           => 28,
                        'sanitize_callback' => function ( $value ) {
                            return max( 7, min( 90, absint( $value ) ) );
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/totals',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_totals' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
                'args'                => array(
                    'days' => array(
                        'default'           => 28,
                        'sanitize_callback' => function ( $value ) {
                            return max( 7, min( 90, absint( $value ) ) );
                        },
                    ),
                ),
            )
        );
    }

    /**
     * Wrap a possible WP_Error into a consistent "not connected" REST shape.
     */
    private function error_response( $error, $extra = array() ) {
        return rest_ensure_response(
            array_merge(
                array(
                    'connected' => false,
                    'message'   => $error->get_error_message(),
                ),
                $extra
            )
        );
    }

    public function rest_list_sitemaps( $request ) {
        if ( ! self::is_ready() ) {
            return rest_ensure_response(
                array(
                    'connected' => false,
                    'message'   => 'Search Console is not connected in Site Kit.',
                    'sitemaps'  => array(),
                )
            );
        }

        $force = (bool) $request->get_param( 'refresh' );
        $rows  = self::list_sitemaps( $force );
        if ( is_wp_error( $rows ) ) {
            return $this->error_response( $rows, array( 'sitemaps' => array() ) );
        }

        return rest_ensure_response(
            array(
                'connected' => true,
                'property'  => self::get_property(),
                'sitemaps'  => $rows,
            )
        );
    }

    public function rest_submit_sitemap( $request ) {
        $feed   = (string) $request->get_param( 'feed' );
        $result = self::submit_sitemap( $feed );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result, array( 'feed' => $feed ) );
        }

        return rest_ensure_response(
            array(
                'connected' => true,
                'ok'        => true,
                'feed'      => $feed,
            )
        );
    }

    public function rest_resubmit_all() {
        $results = self::resubmit_all();
        if ( is_wp_error( $results ) ) {
            return $this->error_response( $results, array( 'results' => array() ) );
        }

        return rest_ensure_response(
            array(
                'connected' => true,
                'results'   => $results,
            )
        );
    }

    public function rest_inspect( $request ) {
        $result = self::inspect_url( (string) $request->get_param( 'url' ) );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return rest_ensure_response( array_merge( array( 'connected' => true ), $result ) );
    }

    public function rest_page_metrics( $request ) {
        $result = self::page_metrics( (string) $request->get_param( 'url' ), (int) $request->get_param( 'days' ) );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result, array( 'top_queries' => array() ) );
        }

        return rest_ensure_response( $result );
    }

    public function rest_totals( $request ) {
        $result = self::site_totals( (int) $request->get_param( 'days' ) );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return rest_ensure_response( $result );
    }
}
