<?php
/**
 * Own view tracking for old posts: a small beacon, one table row per post per day.
 *
 * For sites without Google Analytics connected through Site Kit, and for what Analytics cannot
 * cheaply say per post ("when was this last read"). Off by default; switched on from the Retention
 * screen's report settings.
 *
 * Only posts older than the retention cutoff are counted, so the table stays small and the writes
 * stay off the posts that are busiest. The beacon is sent from the page itself, so it counts views
 * served from a page cache too, and it changes nothing a visitor can see.
 *
 * Storage is its own table ({prefix}ace_seo_post_hits), created only when tracking is switched on.
 * A daily rollup copies each post's last viewed day into post meta for the post list, and prunes
 * rows older than the retention period.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoViewTracker {

    const TABLE        = 'ace_seo_post_hits';
    const DB_VERSION   = 1;
    const DB_OPTION    = 'ace_seo_hits_db_version';
    const ROLLUP_HOOK  = 'ace_seo_hits_rollup';
    const META_LAST    = '_ace_seo_last_viewed';
    const KEEP_DAYS    = 400;

    public static function init() {
        add_action( self::ROLLUP_HOOK, array( __CLASS__, 'rollup' ) );

        if ( ! self::enabled() ) {
            if ( wp_next_scheduled( self::ROLLUP_HOOK ) ) {
                wp_clear_scheduled_hook( self::ROLLUP_HOOK );
            }
            return;
        }

        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'wp_footer', array( __CLASS__, 'print_beacon' ), 99 );
        if ( ! wp_next_scheduled( self::ROLLUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::ROLLUP_HOOK );
        }
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Switched on, and the table exists. */
    public static function enabled() {
        if ( ! class_exists( 'AceSeoRetentionActions' ) ) {
            return false;
        }
        $o = AceSeoRetentionActions::options();
        return ! empty( $o['track_views'] ) && (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION;
    }

    /** Create or upgrade the table, only when tracking is switched on. */
    public static function maybe_install() {
        if ( ! class_exists( 'AceSeoRetentionActions' ) || empty( AceSeoRetentionActions::options()['track_views'] ) ) {
            return;
        }
        if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();
        dbDelta( "CREATE TABLE {$table} (
  post_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  humans int(10) unsigned NOT NULL DEFAULT 0,
  bots int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id,day),
  KEY day (day)
) {$charset};" );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    /** Is this post one we count? Published, a report post type, older than the cutoff. */
    public static function trackable( $post ) {
        $post = get_post( $post );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return false;
        }
        $settings = AceSeoRetentionReport::settings();
        $ok       = in_array( $post->post_type, (array) $settings['post_types'], true )
            && get_post_time( 'U', true, $post ) < strtotime( '-' . (int) $settings['older_than_years'] . ' years' );

        /**
         * Filter whether a post's views are counted by the plugin's own tracking.
         *
         * @param bool    $ok
         * @param WP_Post $post
         */
        return (bool) apply_filters( 'ace_seo_track_post', $ok, $post );
    }

    public static function print_beacon() {
        if ( ! is_singular() || is_preview() || is_user_logged_in() || is_feed() ) {
            return;
        }
        $id = (int) get_queried_object_id();
        if ( ! $id || ! self::trackable( $id ) ) {
            return;
        }
        $url = rest_url( 'ace-seo/v1/hit' );
        printf(
            '<script id="ace-seo-hit">(function(){try{var d=new FormData();d.append("p","%d");d.append("r",document.referrer||"");if(navigator.sendBeacon){navigator.sendBeacon(%s,d);}}catch(e){}})();</script>' . "\n",
            $id,
            wp_json_encode( esc_url_raw( $url ) )
        );
    }

    public static function register_routes() {
        register_rest_route( 'ace-seo/v1', '/hit', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array( __CLASS__, 'rest_hit' ),
            'args'                => array(
                'p' => array( 'type' => 'integer', 'required' => true ),
                'r' => array( 'type' => 'string', 'required' => false ),
            ),
        ) );
    }

    public static function rest_hit( WP_REST_Request $request ) {
        $id = absint( $request->get_param( 'p' ) );
        if ( $id && self::trackable( $id ) ) {
            $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
            self::record( $id, self::is_bot( $ua ) );
            do_action( 'ace_seo_tracked_hit', $id, (string) $request->get_param( 'r' ), $ua );
        }
        $response = new WP_REST_Response( null, 204 );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    /** One upsert per hit. */
    public static function record( $post_id, $is_bot ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (post_id, day, humans, bots) VALUES (%d, %s, %d, %d) ON DUPLICATE KEY UPDATE humans = humans + VALUES(humans), bots = bots + VALUES(bots)',
            (int) $post_id,
            current_time( 'Y-m-d', true ),
            $is_bot ? 0 : 1,
            $is_bot ? 1 : 0
        ) );
    }

    /**
     * A user agent that says it is automated: crawlers, link previewers, monitors, HTTP libraries,
     * headless browsers. Anything that hides it is counted as a person; there is no better signal
     * from inside the page.
     */
    public static function is_bot( $ua ) {
        $ua = strtolower( (string) $ua );
        if ( '' === $ua ) {
            return true;
        }
        $bot = (bool) preg_match( '/bot|crawl|spider|slurp|scrape|fetch|preview|facebookexternalhit|embedly|headless|phantomjs|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|httpclient|libwww|feedparser|mediapartners|adsbot|google-inspectiontool|chrome-lighthouse|bingpreview|yandex|baidu|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|perplexity|amazonbot|applebot/', $ua );
        return (bool) apply_filters( 'ace_seo_is_bot_user_agent', $bot, $ua );
    }

    /**
     * Human views per post over the last $days days.
     *
     * @return array post_id => views
     */
    public static function views_for( array $ids, $days ) {
        global $wpdb;
        $ids = array_filter( array_map( 'intval', $ids ) );
        if ( ! $ids || ! self::enabled() ) {
            return array();
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT post_id, SUM(humans) AS n FROM ' . self::table() . ' WHERE post_id IN (' . implode( ',', $ids ) . ') AND day >= %s GROUP BY post_id',
            gmdate( 'Y-m-d', strtotime( '-' . max( 1, (int) $days ) . ' days' ) )
        ) );
        $out = array();
        foreach ( $rows as $row ) {
            $out[ (int) $row->post_id ] = (int) $row->n;
        }
        return $out;
    }

    /** Daily: last viewed day into post meta for posts read since the last run; prune old rows. */
    public static function rollup() {
        global $wpdb;
        if ( ! self::enabled() ) {
            return;
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT post_id, MAX(day) AS last FROM ' . self::table() . ' WHERE day >= %s AND humans > 0 GROUP BY post_id',
            gmdate( 'Y-m-d', strtotime( '-2 days' ) )
        ) );
        foreach ( $rows as $row ) {
            if ( (string) get_post_meta( (int) $row->post_id, self::META_LAST, true ) !== $row->last ) {
                update_post_meta( (int) $row->post_id, self::META_LAST, $row->last );
            }
        }
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE day < %s',
            gmdate( 'Y-m-d', strtotime( '-' . (int) apply_filters( 'ace_seo_track_keep_days', self::KEEP_DAYS ) . ' days' ) )
        ) );
        do_action( 'ace_seo_hits_rolled_up' );
    }
}
