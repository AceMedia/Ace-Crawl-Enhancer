<?php
/**
 * Google data endpoints backed by Google Site Kit.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOGoogleData {

    /**
     * Register hooks.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'wp_ajax_ace_seo_clear_sitekit_cache', array( $this, 'ajax_clear_sitekit_cache' ) );
        add_action( 'wp_ajax_ace_seo_load_google_signals', array( $this, 'ajax_load_google_signals' ) );
    }

    /**
     * Register Google data REST routes.
     */
    public function register_routes() {
        register_rest_route(
            'ace-seo/v1',
            '/google/sitekit/status',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_sitekit_status' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/ga4/summary',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_ga4_summary' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/ga4/top-pages',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_ga4_top_pages' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
                'args'                => array(
                    'limit' => array(
                        'default'           => 10,
                        'sanitize_callback' => function( $value ) {
                            return max( 3, min( 25, absint( $value ) ) );
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/search-console/query',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_search_console_query' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
                'args'                => array(
                    'url' => array(
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                    'days' => array(
                        'default'           => 28,
                        'sanitize_callback' => function( $value ) {
                            return max( 7, min( 90, absint( $value ) ) );
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            'ace-seo/v1',
            '/google/modules/audit',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_modules_audit' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
            )
        );
    }

    /**
     * Permission callback.
     */
    public function can_manage_options() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Clear cached Site Kit token data.
     */
    public function ajax_clear_sitekit_cache() {
        check_ajax_referer( 'ace_seo_api_test', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        if ( class_exists( 'AceSEOSiteKit' ) ) {
            AceSEOSiteKit::clear_token_cache();
        }

        wp_send_json_success( array( 'cleared' => true ) );
    }

    /**
     * AJAX: Render compact dashboard Google status and metrics.
     */
    public function ajax_load_google_signals() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ace_seo_dashboard_nonce' ) ) {
            wp_die( 'Invalid nonce' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $status = AceSEOSiteKit::get_status();
        $summary = null;

        if ( ! empty( $status['modules']['analytics_4']['connected'] ) ) {
            $summary_response = $this->rest_ga4_summary();
            $summary = $summary_response instanceof WP_REST_Response ? $summary_response->get_data() : null;
        }

        wp_send_json(
            array(
                'status' => 'success',
                'data'   => array(
                    'html' => $this->render_google_signals_html( $status, $summary ),
                ),
            )
        );
    }

    /**
     * REST: Site Kit status.
     */
    public function rest_sitekit_status() {
        return rest_ensure_response(
            class_exists( 'AceSEOSiteKit' )
                ? AceSEOSiteKit::get_status()
                : array( 'plugin_active' => false, 'modules' => array() )
        );
    }

    /**
     * REST: GA4 summary.
     */
    public function rest_ga4_summary() {
        $property_id = AceSEOSiteKit::get_analytics_property_id();
        if ( $property_id === '' ) {
            return rest_ensure_response(
                array(
                    'connected' => false,
                    'message'   => 'Site Kit is active but GA4 has not been connected yet.',
                )
            );
        }

        $report = $this->run_ga4_report(
            $property_id,
            array(
                'dateRanges' => array(
                    array(
                        'startDate' => 'yesterday',
                        'endDate'   => 'today',
                    ),
                ),
                'metrics'    => array(
                    array( 'name' => 'sessions' ),
                    array( 'name' => 'screenPageViews' ),
                    array( 'name' => 'activeUsers' ),
                    array( 'name' => 'engagementRate' ),
                    array( 'name' => 'bounceRate' ),
                    array( 'name' => 'averageSessionDuration' ),
                ),
            ),
            120
        );

        if ( is_wp_error( $report ) ) {
            return rest_ensure_response(
                array(
                    'connected' => false,
                    'message'   => $report->get_error_message(),
                )
            );
        }

        $metrics = $report['rows'][0]['metricValues'] ?? array();

        return rest_ensure_response(
            array(
                'connected'            => true,
                'property_id'          => $property_id,
                'sessions'             => (int) ( $metrics[0]['value'] ?? 0 ),
                'pageviews'            => (int) ( $metrics[1]['value'] ?? 0 ),
                'active_users'         => (int) ( $metrics[2]['value'] ?? 0 ),
                'engagement_rate'      => round( (float) ( $metrics[3]['value'] ?? 0 ) * 100, 1 ),
                'bounce_rate'          => round( (float) ( $metrics[4]['value'] ?? 0 ) * 100, 1 ),
                'average_session_time' => (int) round( (float) ( $metrics[5]['value'] ?? 0 ) ),
            )
        );
    }

    /**
     * REST: GA4 top pages.
     */
    public function rest_ga4_top_pages( $request ) {
        $property_id = AceSEOSiteKit::get_analytics_property_id();
        if ( $property_id === '' ) {
            return rest_ensure_response( array( 'connected' => false, 'pages' => array() ) );
        }

        $report = $this->run_ga4_report(
            $property_id,
            array(
                'dateRanges' => array(
                    array(
                        'startDate' => 'yesterday',
                        'endDate'   => 'today',
                    ),
                ),
                'dimensions' => array(
                    array( 'name' => 'pageTitle' ),
                    array( 'name' => 'pagePath' ),
                ),
                'metrics'    => array(
                    array( 'name' => 'screenPageViews' ),
                    array( 'name' => 'activeUsers' ),
                ),
                'orderBys'   => array(
                    array(
                        'metric' => array( 'metricName' => 'screenPageViews' ),
                        'desc'   => true,
                    ),
                ),
                'limit'      => (int) $request->get_param( 'limit' ),
            ),
            120
        );

        if ( is_wp_error( $report ) ) {
            return rest_ensure_response(
                array(
                    'connected' => false,
                    'message'   => $report->get_error_message(),
                    'pages'     => array(),
                )
            );
        }

        $pages = array();
        foreach ( $this->parse_google_report_rows( $report ) as $row ) {
            $pages[] = array(
                'title'     => $row['pageTitle'] ?? '',
                'path'      => $row['pagePath'] ?? '',
                'pageviews' => (int) ( $row['screenPageViews'] ?? 0 ),
                'users'     => (int) ( $row['activeUsers'] ?? 0 ),
            );
        }

        return rest_ensure_response(
            array(
                'connected'   => true,
                'property_id' => $property_id,
                'pages'       => $pages,
            )
        );
    }

    /**
     * REST: Search Console query data.
     */
    public function rest_search_console_query( $request ) {
        $days = (int) $request->get_param( 'days' );
        $url = esc_url_raw( (string) $request->get_param( 'url' ) );
        $property_id = AceSEOSiteKit::get_search_console_property_id();

        $dimension_filter = array();
        if ( $url !== '' ) {
            $dimension_filter = array(
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
        }

        $body = array_merge(
            array(
                'startDate'  => gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ),
                'endDate'    => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
                'dimensions' => array( 'query', 'page' ),
                'rowLimit'   => 25,
            ),
            $dimension_filter
        );

        $report = $this->run_search_console_query( $property_id, $body, 300 );
        if ( is_wp_error( $report ) ) {
            return rest_ensure_response(
                array(
                    'connected' => false,
                    'message'   => $report->get_error_message(),
                    'rows'      => array(),
                )
            );
        }

        return rest_ensure_response(
            array(
                'connected'   => true,
                'property_id' => $property_id,
                'rows'        => $report['rows'] ?? array(),
            )
        );
    }

    /**
     * REST: module audit.
     */
    public function rest_modules_audit() {
        $status = AceSEOSiteKit::get_status();
        $modules = $status['modules'] ?? array();

        return rest_ensure_response(
            array(
                'sitekit'         => $status,
                'recommendations' => array(
                    'analytics_4'    => array(
                        'decision' => ! empty( $modules['analytics_4']['connected'] ) ? 'build_now' : 'defer',
                        'use'      => 'Use GA4 traffic and engagement metrics to prioritise SEO and PageSpeed work.',
                    ),
                    'search_console' => array(
                        'decision' => ! empty( $modules['search_console']['connected'] ) ? 'build_now' : 'defer',
                        'use'      => 'Use query, impression, CTR and position data for crawl and content priorities.',
                    ),
                    'pagespeed'      => array(
                        'decision' => ! empty( $modules['pagespeed']['connected'] ) ? 'build_now' : 'manual_key_fallback',
                        'use'      => 'Use PageSpeed Insights for Core Web Vitals and Lighthouse report storage.',
                    ),
                    'adsense'        => array(
                        'decision' => ! empty( $modules['adsense']['connected'] ) ? 'defer' : 'do_not_build_yet',
                        'use'      => 'Potential page value signal, but only if it supports crawl prioritisation.',
                    ),
                    'tagmanager'     => array(
                        'decision' => ! empty( $modules['tagmanager']['connected'] ) ? 'defer' : 'do_not_build_yet',
                        'use'      => 'Potential tracking/performance conflict signal, not a primary SEO data source.',
                    ),
                ),
            )
        );
    }

    /**
     * Run a GA4 Data API report.
     */
    private function run_ga4_report( $property_id, $request_body, $cache_ttl ) {
        $cache_key = 'ace_seo_ga4_' . md5( $property_id . wp_json_encode( $request_body ) );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $request_body ),
            )
        );

        $body = $this->decode_google_response( $response, 'ga4_report_error' );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        set_transient( $cache_key, $body, $cache_ttl );
        return $body;
    }

    /**
     * Run a Search Console Search Analytics query.
     */
    private function run_search_console_query( $property_id, $request_body, $cache_ttl ) {
        $cache_key = 'ace_seo_gsc_' . md5( $property_id . wp_json_encode( $request_body ) );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_SEARCH_CONSOLE ) );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_post(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property_id ) . '/searchAnalytics/query',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $request_body ),
            )
        );

        $body = $this->decode_google_response( $response, 'search_console_query_error' );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        set_transient( $cache_key, $body, $cache_ttl );
        return $body;
    }

    /**
     * Decode Google API responses consistently.
     */
    private function decode_google_response( $response, $error_code ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = $body['error']['message'] ?? 'Google API request failed with HTTP ' . $http_code . '.';
            return new WP_Error( $error_code, $message, array( 'status' => $http_code ) );
        }

        return is_array( $body ) ? $body : array();
    }

    /**
     * Parse a GA4 report into keyed rows.
     */
    private function parse_google_report_rows( $report ) {
        if ( empty( $report['rows'] ) ) {
            return array();
        }

        $dimension_headers = wp_list_pluck( $report['dimensionHeaders'] ?? array(), 'name' );
        $metric_headers = wp_list_pluck( $report['metricHeaders'] ?? array(), 'name' );
        $rows = array();

        foreach ( $report['rows'] as $row ) {
            $item = array();
            foreach ( $dimension_headers as $index => $name ) {
                $item[ $name ] = $row['dimensionValues'][ $index ]['value'] ?? '';
            }
            foreach ( $metric_headers as $index => $name ) {
                $item[ $name ] = $row['metricValues'][ $index ]['value'] ?? 0;
            }
            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * Render dashboard HTML for Google signals.
     */
    private function render_google_signals_html( $status, $summary ) {
        $modules = $status['modules'] ?? array();

        ob_start();
        ?>
        <div class="ace-google-signals">
            <p>
                <strong>Site Kit:</strong>
                <?php echo ! empty( $status['plugin_active'] ) ? 'Connected' : 'Not connected'; ?>
            </p>

            <ul>
                <li>GA4: <?php echo ! empty( $modules['analytics_4']['connected'] ) ? esc_html( $modules['analytics_4']['property_id'] ) : 'Not connected'; ?></li>
                <li>Search Console: <?php echo ! empty( $modules['search_console']['connected'] ) ? esc_html( $modules['search_console']['property_id'] ) : 'Not connected'; ?></li>
                <li>PageSpeed: <?php echo ! empty( $modules['pagespeed']['connected'] ) ? 'Available via Site Kit' : 'Use Site Kit or a manual API key'; ?></li>
            </ul>

            <?php if ( is_array( $summary ) && ! empty( $summary['connected'] ) ) : ?>
                <div class="ace-seo-stats">
                    <div class="ace-seo-stat">
                        <div class="ace-seo-stat-number"><?php echo esc_html( number_format_i18n( (int) $summary['sessions'] ) ); ?></div>
                        <div class="ace-seo-stat-label">Sessions</div>
                    </div>
                    <div class="ace-seo-stat">
                        <div class="ace-seo-stat-number"><?php echo esc_html( number_format_i18n( (int) $summary['pageviews'] ) ); ?></div>
                        <div class="ace-seo-stat-label">Page views</div>
                    </div>
                    <div class="ace-seo-stat">
                        <div class="ace-seo-stat-number"><?php echo esc_html( number_format_i18n( (int) $summary['active_users'] ) ); ?></div>
                        <div class="ace-seo-stat-label">Users</div>
                    </div>
                </div>
                <p class="description">Rolling 24-hour GA4 summary from Site Kit.</p>
            <?php elseif ( is_array( $summary ) && ! empty( $summary['message'] ) ) : ?>
                <p class="description"><?php echo esc_html( $summary['message'] ); ?></p>
            <?php else : ?>
                <p class="description">Connect GA4 in Site Kit to show traffic and engagement signals here.</p>
            <?php endif; ?>

            <button type="button" class="button-link ace-refresh-google-signals">Refresh Google Signals</button>
        </div>
        <?php

        return ob_get_clean();
    }
}
