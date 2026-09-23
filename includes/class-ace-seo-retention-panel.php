<?php
/**
 * Readers panel in the block editor: who reads an old post, and where they come from.
 *
 * Shown in the document sidebar for posts the retention report scored. Nothing is fetched until the
 * panel's button is pressed, because it is a lot of data: people and bots per day (the plugin's own
 * tracking), referrers by source and host, the posts on this site that link here, and, where Site
 * Kit has Google Analytics connected, the page's traffic sources from Analytics.
 *
 * All of it comes from one REST route, `GET ace-seo/v1/retention/audience?post=ID`, for anyone who
 * can edit the post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoRetentionPanel {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $post   = get_post();
        if ( ! $screen || 'post' !== $screen->base || ! $post || ! in_array( $post->post_type, (array) AceSeoRetentionReport::settings()['post_types'], true ) ) {
            return;
        }
        $tier = (string) get_post_meta( $post->ID, AceSeoRetentionReport::META_TIER, true );
        if ( '' === $tier ) {
            return;
        }
        $deps = array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-data' );
        $deps[] = wp_script_is( 'wp-editor', 'registered' ) ? 'wp-editor' : 'wp-edit-post';
        wp_enqueue_script(
            'ace-seo-retention-panel',
            ACE_SEO_URL . 'assets/js/retention-panel.js',
            $deps,
            ACE_SEO_VERSION,
            true
        );
        wp_add_inline_script( 'ace-seo-retention-panel', 'window.aceSeoRetentionPanel = ' . wp_json_encode( array(
            'postId'    => (int) $post->ID,
            'tier'      => $tier,
            'tierLabel' => AceSeoRetentionReport::tier_labels()[ $tier ] ?? $tier,
            'tracking'  => class_exists( 'AceSeoViewTracker' ) && AceSeoViewTracker::enabled(),
        ) ) . ';', 'before' );
    }

    public static function register_routes() {
        register_rest_route( 'ace-seo/v1', '/retention/audience', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_audience' ),
            'permission_callback' => function ( WP_REST_Request $request ) {
                return current_user_can( 'edit_post', absint( $request->get_param( 'post' ) ) );
            },
            'args'                => array(
                'post' => array( 'type' => 'integer', 'required' => true ),
                'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 7, 'maximum' => 365 ),
            ),
        ) );
    }

    public static function rest_audience( WP_REST_Request $request ) {
        $post = get_post( absint( $request->get_param( 'post' ) ) );
        if ( ! $post ) {
            return new WP_Error( 'ace_seo_not_found', 'No such post.', array( 'status' => 404 ) );
        }
        $days     = (int) $request->get_param( 'days' );
        $tracking = class_exists( 'AceSeoViewTracker' ) && AceSeoViewTracker::enabled();
        $series   = $tracking ? AceSeoViewTracker::series( $post->ID, $days ) : array();
        $refs     = $tracking ? AceSeoViewTracker::referrers( $post->ID, $days, 50 ) : array();

        $humans = array_sum( wp_list_pluck( $series, 'humans' ) );
        $bots   = array_sum( wp_list_pluck( $series, 'bots' ) );
        $by_source = array();
        foreach ( $refs as $r ) {
            $by_source[ $r['source'] ] = ( $by_source[ $r['source'] ] ?? 0 ) + $r['hits'];
        }
        arsort( $by_source );

        $row = get_post_meta( $post->ID, AceSeoRetentionReport::META, true );

        return rest_ensure_response( array(
            'tracking'  => $tracking,
            'days'      => $days,
            'humans'    => $humans,
            'bots'      => $bots,
            'bot_pct'   => ( $humans + $bots ) > 0 ? (int) round( 100 * $bots / ( $humans + $bots ) ) : null,
            'series'    => $series,
            'sources'   => $by_source,
            'referrers' => $refs,
            'links_in'  => self::linking_posts( $post ),
            'analytics' => self::analytics_sources( $post ),
            'report'    => is_array( $row ) ? array_intersect_key( $row, array_flip( array( 'tier', 'bucket', 'reason', 'clicks', 'impressions', 'views', 'links_in', 'words', 'built' ) ) ) : null,
        ) );
    }

    /**
     * Published posts whose content links to this one (by path, so any host form counts). One scan
     * of the posts table, cached for an hour; it only runs when someone asks.
     */
    private static function linking_posts( WP_Post $post ) {
        global $wpdb;
        $path = '/' . trim( (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ), '/' );
        if ( '/' === $path ) {
            return array();
        }
        $key    = 'ace_seo_links_in_' . $post->ID;
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID <> %d AND post_content LIKE %s ORDER BY post_date DESC LIMIT 25",
            $post->ID,
            '%' . $wpdb->esc_like( $path ) . '%'
        ) );
        $out = array();
        foreach ( $ids as $id ) {
            $out[] = array( 'id' => (int) $id, 'title' => get_the_title( $id ), 'url' => get_permalink( $id ), 'date' => get_the_date( 'Y-m-d', $id ) );
        }
        set_transient( $key, $out, HOUR_IN_SECONDS );
        return $out;
    }

    /**
     * Where Google Analytics says this page's views came from over the last 28 days (source and
     * medium), when Site Kit has Analytics connected. Cached for six hours.
     */
    private static function analytics_sources( WP_Post $post ) {
        if ( ! class_exists( 'AceSEOSiteKit' ) || ! AceSEOSiteKit::is_active() ) {
            return array( 'available' => false, 'message' => 'Site Kit is not active.' );
        }
        $property = AceSEOSiteKit::get_analytics_property_id();
        if ( '' === $property ) {
            return array( 'available' => false, 'message' => 'Site Kit has no Analytics property connected.' );
        }
        $path   = wp_make_link_relative( get_permalink( $post ) );
        $key    = 'ace_seo_ga4_src_' . md5( $property . '|' . $path );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
        if ( is_wp_error( $token ) || empty( $token ) ) {
            return array( 'available' => false, 'message' => is_wp_error( $token ) ? $token->get_error_message() : 'No Site Kit token for Analytics.' );
        }
        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport',
            array(
                'timeout' => 15,
                'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'dateRanges'      => array( array( 'startDate' => '28daysAgo', 'endDate' => 'yesterday' ) ),
                    'dimensions'      => array( array( 'name' => 'sessionSource' ), array( 'name' => 'sessionMedium' ) ),
                    'metrics'         => array( array( 'name' => 'screenPageViews' ) ),
                    'dimensionFilter' => array( 'filter' => array( 'fieldName' => 'pagePath', 'stringFilter' => array( 'matchType' => 'EXACT', 'value' => $path ) ) ),
                    'orderBys'        => array( array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ) ),
                    'limit'           => 25,
                ) ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return array( 'available' => false, 'message' => $response->get_error_message() );
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return array( 'available' => false, 'message' => $body['error']['message'] ?? 'Analytics request failed.' );
        }
        $rows = array();
        foreach ( (array) ( $body['rows'] ?? array() ) as $r ) {
            $rows[] = array(
                'source' => (string) ( $r['dimensionValues'][0]['value'] ?? '' ),
                'medium' => (string) ( $r['dimensionValues'][1]['value'] ?? '' ),
                'views'  => (int) ( $r['metricValues'][0]['value'] ?? 0 ),
            );
        }
        $out = array( 'available' => true, 'days' => 28, 'rows' => $rows );
        set_transient( $key, $out, 6 * HOUR_IN_SECONDS );
        return $out;
    }
}
