<?php
/** Minimal first-party statistics: one counter per site/day, never a visitor/event log. */
defined( 'ABSPATH' ) || exit;

class AceSeoAggregateViews {
    const OPTION = 'ace_seo_aggregate_views';
    const SCHEMA = 'ace_seo_aggregate_views_schema';
    const TABLE = 'ace_seo_daily_views';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'rest_authentication_errors', array( __CLASS__, 'public_counter_auth' ), 1000 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_footer', array( __CLASS__, 'privacy' ), 5 );
        add_action( 'admin_post_ace_seo_aggregate_views', array( __CLASS__, 'save' ) );
        add_action( 'ace_seo_tracking_check', array( __CLASS__, 'prune' ) );
    }
    public static function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }
    public static function enabled() { return (bool) get_option( self::OPTION, false ) && 1 === (int) get_option( self::SCHEMA, 0 ); }

    /** Explicit site-level enablement; never silently enabled on other consuming sites. */
    public static function enable() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
 day date NOT NULL,
 views bigint(20) unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (day)
) {$charset};" );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            return new WP_Error( 'aggregate_schema', 'The daily view table could not be created.' );
        }
        update_option( self::SCHEMA, 1, false );
        update_option( self::OPTION, 1, false );
        return true;
    }
    public static function routes() {
        register_rest_route( 'ace-seo/v1', '/aggregate-view', array(
            'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'permission' ),
            'callback' => array( __CLASS__, 'hit' ),
        ) );
    }
    /** Community sites can close REST globally. Only exempt this deliberately public counter. */
    public static function public_counter_auth( $result ) {
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
        if ( self::enabled() && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
            && '/ace-seo/v1/aggregate-view' === rtrim( $route, '/' )
            && is_wp_error( $result ) && 'rest_not_logged_in' === $result->get_error_code() ) {
            return null; // Route permission_callback still verifies origin, fetch metadata and size.
        }
        return $result;
    }
    public static function permission( $request ) {
        // A cached public beacon cannot carry a per-user nonce. Require same-origin browser
        // delivery instead; this is a public counter, not an authenticated operation.
        $origin = untrailingslashit( (string) $request->get_header( 'origin' ) );
        $home = wp_parse_url( home_url() );
        $expected = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
        return self::enabled() && $origin === $expected
            && 'same-origin' === $request->get_header( 'sec-fetch-site' )
            && strlen( $request->get_body() ) <= 32;
    }
    public static function hit( $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) || array_keys( $body ) !== array( 'v' ) || 1 !== $body['v'] ) {
            return new WP_Error( 'invalid_view', 'Invalid counter request.', array( 'status' => 400 ) );
        }
        $ua = (string) $request->get_header( 'user-agent' );
        if ( '1' !== $request->get_header( 'dnt' ) && '1' !== $request->get_header( 'sec-gpc' ) && ! AceSeoViewTracker::is_bot( $ua ) ) {
            global $wpdb;
            $ok = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (day, views) VALUES (%s, 1) ON DUPLICATE KEY UPDATE views = views + 1', wp_date( 'Y-m-d' ) ) );
            if ( false === $ok ) { return new WP_Error( 'counter_unavailable', 'Counter unavailable.', array( 'status' => 503 ) ); }
        }
        $response = new WP_REST_Response( null, 204 );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }
    public static function frontend() {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        return self::enabled() && ! is_admin() && ! is_user_logged_in() && ! is_preview() && ! is_feed()
            && ! ( defined( 'WP_LOCAL_DEV' ) && WP_LOCAL_DEV )
            && ! preg_match( '/(?:\.pi|\.test|\.local)$|^localhost$/', $host );
    }
    public static function enqueue() {
        if ( ! self::frontend() ) { return; }
        wp_enqueue_script( 'ace-aggregate-views', ACE_SEO_URL . 'assets/js/aggregate-views.js', array(), ACE_SEO_VERSION, true );
        wp_add_inline_script( 'ace-aggregate-views', 'window.aceAggregateViewsConfig=' . wp_json_encode( array( 'endpoint' => rest_url( 'ace-seo/v1/aggregate-view' ) ) ) . ';', 'before' );
    }
    public static function privacy() {
        if ( ! self::frontend() ) { return; }
        echo '<details id="ace-view-privacy" style="margin:12px auto;padding:8px 16px;max-width:70rem;font:inherit;font-size:13px"><summary style="cursor:pointer">Cookie-free view counts · Privacy choices</summary><p>We count page views to improve this website, including before you choose optional analytics. This counter keeps only a daily total: no visitor IDs, page addresses or browsing histories. It uses no tracking cookies and sends no data to Google or Microsoft. Optional analytics add richer reports if you accept them.</p><p><label><input type="checkbox" id="ace-view-optout"> Stop this basic view count on this browser</label></p><p>Your choice is remembered using a preference cookie. Rejecting analytics, Do Not Track and Global Privacy Control also stop this counter. Ordinary server access logs are separate.</p></details>';
    }
    public static function series( array $dates ) {
        global $wpdb;
        if ( ! $dates || 1 !== (int) get_option( self::SCHEMA, 0 ) ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, views FROM ' . self::table() . ' WHERE day >= %s AND day <= %s ORDER BY day', min( $dates ), max( $dates ) ), ARRAY_A );
        $out = array();
        foreach ( (array) $rows as $row ) { $out[ $row['day'] ] = (int) $row['views']; }
        // No row means unknown coverage, not a fabricated historical zero.
        return $out;
    }
    public static function enrich( array $report, array $dates ) {
        $views = self::series( $dates );
        if ( self::enabled() || $views ) {
            $report['definitions']['baseline_views'] = array( 'label' => 'Basic page views', 'group' => 'Traffic' );
            foreach ( $dates as $date ) { $report['days'][ $date ]['baseline_views'] = $views[ $date ] ?? null; }
        }
        return $report;
    }
    public static function prune() {
        if ( 1 !== (int) get_option( self::SCHEMA, 0 ) ) { return; }
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', wp_date( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
    }
    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
        check_admin_referer( 'ace_seo_aggregate_views' );
        if ( isset( $_POST['enabled'] ) ) {
            $result = self::enable();
            if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        } else { update_option( self::OPTION, 0, false ); }
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-tracking' ) ); exit;
    }
    public static function settings() {
        echo '<details class="box" id="basic-view-count"><summary><strong>Basic page views: ' . ( self::enabled() ? 'on' : 'off' ) . '</strong></summary><p>A first-party daily total, independent of GA4 consented reporting. Counts visible page loads and React history navigation. No tracking cookies, persistent IDs, URLs, IP addresses or user agents are stored in this counter. Known bots, signed-in users on uncached pages and explicit opt-outs are excluded; disguised bots and blocking affect accuracy. Blank dates mean no recorded coverage.</p><p>Use only for aggregate site-improvement statistics under the rules applicable to your visitors. A public privacy control explains the counter and provides an opt-out. Purge cached pages after changing this setting.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ace_seo_aggregate_views">';
        wp_nonce_field( 'ace_seo_aggregate_views' );
        echo '<label><input type="checkbox" name="enabled" value="1"' . checked( self::enabled(), true, false ) . '> Enable basic aggregate view counting</label> ';
        submit_button( 'Save view counting', 'secondary', 'submit', false ); echo '</form></details>';
    }
}
