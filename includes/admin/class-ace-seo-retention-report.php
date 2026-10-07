<?php
/**
 * Retention report: what to do with old posts, decided per URL on evidence rather than by date.
 *
 * Nothing on a site should be deleted because of its age. Every published post older than the cutoff
 * is scored on four signals — search clicks and impressions (Search Console, via Site Kit), inbound
 * internal links, and optionally page views and backlinks supplied by filters — and placed in one
 * bucket that says how to keep it well:
 *
 *   keep         earning search clicks, visits or links: leave it alone
 *   refresh      real search demand but weak clicks (page 1–2, poor CTR): the cheapest wins on the site
 *   consolidate  impressions but no clicks: a stronger page on the subject should own the queries — 301 to it
 *   noindex      no search value, but still linked or visited: keep serving it, drop it from the index
 *   no-signal    nothing at all in the window: still served, still linked from its archives; noindex it,
 *                fold it into a hub, or leave it — the report does not recommend deletion
 *
 * The result is written to post meta (_ace_seo_retention) so it can be listed, filtered and exported,
 * and so a later bulk action can act on it. The build runs in batches — cron ticks from the admin
 * screen, or straight through under WP-CLI — and is resumable.
 *
 * Nothing here changes a post. It is a report.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoRetentionReport {

    const META            = '_ace_seo_retention';
    const PROGRESS_OPTION = 'ace_seo_retention_progress';
    const HISTORY_OPTION  = 'ace_seo_retention_history';
    const HISTORY_KEEP    = 26;
    /** Seconds a cron tick keeps taking batches: a site's cron fires once a minute, a batch at a time is two hours. */
    const TICK_BUDGET     = 40;
    const SIGNALS_OPTION  = 'ace_seo_retention_signals';
    /** Shorter windows read against the main one, for trends. Each is its own option: post ID => numbers. */
    const PERIODS         = array( 7, 14, 30, 90 );
    const PERIOD_OPTION   = 'ace_seo_retention_period_';
    const META_TREND      = '_ace_seo_ret_trend';
    const META_MOMENTUM   = '_ace_seo_ret_momentum';
    const TRENDS          = array( 'rising', 'steady', 'falling', 'gone', 'quiet' );
    const CRON_HOOK       = 'ace_seo_retention_tick';
    const BATCH           = 300;
    /** Hourly check that a build still has a worker, in case the tick that should follow it was lost. */
    const WATCH_HOOK      = 'ace_seo_retention_watch';
    /** The lease row: whichever worker holds it is the one allowed to run steps. */
    const LOCK_OPTION     = 'ace_seo_retention_lock';
    /** Seconds a lease lasts without renewal; a batch of 300 takes well under a minute, a tick under 80 s. */
    const LEASE_SECONDS   = 300;
    /** Consecutive failed steps before a build gives up rather than looping on a broken batch. */
    const MAX_FAILURES    = 3;

    const BUCKETS = array( 'keep', 'refresh', 'consolidate', 'noindex', 'no-signal' );

    /**
     * Tiers: a plainer reading of the same evidence, for the post list and for people outside SEO.
     *
     *   retained   old, and still getting views (or search clicks, where no views source exists)
     *   candidate  old, no views, and thin: the posts a clean-up would look at first
     *   dormant    old and unvisited, but with enough content that it is not an obvious candidate
     */
    const TIERS = array( 'retained', 'candidate', 'dormant', 'unknown' );

    /**
     * Readership bands inside Retained, best first. The cutoffs come from the traffic window so they
     * mean the same thing whatever its length: about a view a day, a week, a month, or at least one.
     */
    const RANKS     = array( 'daily', 'weekly', 'monthly', 'occasional' );
    const META_RANK = '_ace_seo_ret_rank';

    /** Flat copies of the row, one meta key each, so the post list can filter and sort on them. */
    const META_TIER  = '_ace_seo_ret_tier';
    const META_VIEWS = '_ace_seo_ret_views';
    const META_WORDS = '_ace_seo_ret_words';
    const META_LINKS = '_ace_seo_ret_links';
    const META_BUILT = '_ace_seo_ret_built';

    const WEEKLY_HOOK = 'ace_seo_retention_weekly';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
        add_action( self::WEEKLY_HOOK, array( __CLASS__, 'run_weekly' ) );
        add_action( self::WATCH_HOOK, array( __CLASS__, 'recover' ) );
        add_action( 'admin_init', array( __CLASS__, 'sync_weekly_schedule' ) );
        add_action( 'admin_init', array( __CLASS__, 'recover' ) );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
            add_action( 'admin_post_ace_seo_retention_build', array( __CLASS__, 'handle_build' ) );
            add_action( 'admin_post_ace_seo_retention_resume', array( __CLASS__, 'handle_resume' ) );
            add_action( 'admin_post_ace_seo_retention_settings_save', array( __CLASS__, 'handle_settings_save' ) );
            add_action( 'admin_post_ace_seo_retention_export', array( __CLASS__, 'handle_export' ) );
            add_action( 'admin_post_ace_seo_retention_clear', array( __CLASS__, 'handle_clear' ) );
            add_action( 'admin_post_ace_seo_retention_apply', array( __CLASS__, 'handle_apply' ) );
            add_action( 'admin_post_ace_seo_retention_options', array( __CLASS__, 'handle_options' ) );
            add_action( 'admin_post_ace_seo_retention_redirect', array( __CLASS__, 'handle_redirect' ) );
            add_action( 'admin_post_ace_seo_retention_report_settings', array( __CLASS__, 'handle_report_settings' ) );
            add_action( 'admin_post_ace_seo_retention_front', array( __CLASS__, 'handle_front_settings' ) );
        }
    }

    /* ---- Settings ------------------------------------------------------------------------------ */

    /**
     * Defaults and thresholds. All filterable, none site-specific: a site whose articles date fast
     * can shorten the cutoff, one with a long tail can raise the "demand" bar.
     */
    public static function settings( array $overrides = array() ) {
        // The cutoffs saved on the Retention screen, so a scheduled rebuild and the build form agree.
        $saved    = class_exists( 'AceSeoRetentionActions' ) ? AceSeoRetentionActions::options() : array();
        $defaults = array(
            'older_than_years'  => (int) ( $saved['report_years'] ?? 3 ),  // posts published before this many years ago are candidates
            'days'              => (int) ( $saved['report_days'] ?? 90 ),  // Search Console and Analytics window
            'post_types'        => array( 'post' ),
            // impressions that count as "there is demand": 100 per 90 days, scaled to the window, so a
            // year's window does not flag pages Google barely shows
            'demand_impressions'=> (int) round( 100 * max( 7, (int) ( $saved['report_days'] ?? 90 ) ) / 90 ),
            'refresh_max_ctr'   => 0.02,   // below this CTR, with demand, the page needs a refresh
            'refresh_max_pos'   => 20,     // and it has to be within reach: page 1 or 2
            'thin_words'        => (int) ( $saved['thin_words'] ?? 300 ),  // fewer words than this counts as thin
            'retained_views'    => (int) ( $saved['retained_views'] ?? 1 ), // views in the window that count as retained
            'timing_policy'     => 'strict' === ( $saved['timing_policy'] ?? '' ) ? 'strict' : 'estimate', // see Ace_SEO_Retention_Evidence::timing_hold()
        );
        $settings = array_merge( $defaults, array_intersect_key( $overrides, $defaults ) );
        if ( ! isset( $overrides['demand_impressions'] ) ) {
            $settings['demand_impressions'] = (int) round( 100 * max( 7, (int) $settings['days'] ) / 90 );
        }
        return apply_filters( 'ace_seo_retention_settings', $settings );
    }

    /* ---- Build ---------------------------------------------------------------------------------- */

    public static function progress() {
        $p = get_option( self::PROGRESS_OPTION, array() );
        return is_array( $p ) ? $p : array();
    }

    public static function is_building() {
        $p = self::progress();
        return ! empty( $p['phase'] ) && 'done' !== $p['phase'] && 'error' !== $p['phase'];
    }

    /** Start (or restart) a build. Under cron it proceeds a tick at a time; under CLI, call run_all(). */
    public static function start( array $overrides = array() ) {
        $settings = self::settings( $overrides );
        update_option( self::PROGRESS_OPTION, array(
            'phase'    => 'gsc',
            'settings' => $settings,
            'offset'   => 0,
            'total'    => 0,
            'started'  => time(),
            'counts'   => array_fill_keys( self::BUCKETS, 0 ),
            'tiers'    => array_fill_keys( self::TIERS, 0 ),
            'notes'    => array(),
            'tick_at'  => 0,
            'failures' => 0,
        ), false );
        update_option( self::SIGNALS_OPTION, array( 'gsc' => array(), 'links' => array() ), false );
        static::lock_delete( '' );
        self::schedule_tick();
        if ( ! wp_next_scheduled( self::WATCH_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCH_HOOK );
        }
    }

    /** True while run_all() is driving the build itself, so ticks do not also queue cron events. */
    private static $running_all = false;

    public static function run_all( $callback = null ) {
        $guard             = 0;
        self::$running_all = true;
        while ( self::is_building() && $guard++ < 100000 ) {
            self::run_tick();
            if ( $callback ) {
                call_user_func( $callback, self::progress() );
            }
        }
        self::$running_all = false;
        return self::progress();
    }

    private static function schedule_tick() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
    }

    /** Set while a tick is running, so the shutdown handler can tell a fatal mid-step from a normal exit. */
    protected static $in_tick  = false;
    protected static $worker   = '';
    private static $shutdown   = false;

    public static function run_tick() {
        if ( ! self::is_building() ) {
            return;
        }
        $owner = self::$worker ? self::$worker : self::worker_id();
        if ( ! self::take_lease( $owner ) ) {
            // Another worker holds a live lease: it will queue the next tick itself when it finishes.
            return;
        }
        self::$in_tick = true;
        self::$worker  = $owner;
        if ( ! self::$shutdown ) {
            self::$shutdown = true;
            register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
        }

        $until = microtime( true ) + ( self::$running_all ? 0 : static::tick_budget() );
        do {
            $p = self::progress();
            try {
                static::run_step();
                $p             = self::progress();
                $p['tick_at']  = time();
                $p['failures'] = 0;
                $p['worker']   = $owner;
                self::save_progress( $p );
            } catch ( \Throwable $e ) {
                self::record_failure( $p, $e->getMessage() );
                break;
            }
            static::lock_renew( $owner, time() + self::LEASE_SECONDS );
        } while ( ! self::$running_all && self::is_building() && microtime( true ) < $until );

        // Not "unless WP-CLI": a site that runs WP-Cron from a system crontab runs every tick under
        // WP-CLI, and the build stalled after its first step. The next tick is queued before the
        // lease is released, so "no tick and no lease" always means the worker was interrupted.
        if ( self::is_building() && ! self::$running_all ) {
            self::schedule_tick();
        } elseif ( ! self::is_building() ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_clear_scheduled_hook( self::WATCH_HOOK );
        }
        self::$in_tick = false;
        static::lock_delete( $owner );
    }

    /**
     * A fatal error (memory, timeout, a killed process) ends the request without run_tick() finishing,
     * which is how a build was left half-done with nothing queued. Count it like an exception and queue
     * the next tick so the build carries on from the saved offset.
     */
    public static function on_shutdown() {
        if ( ! self::$in_tick ) {
            return;
        }
        $err = static::last_error();
        if ( ! $err || ! in_array( (int) $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
            return;
        }
        self::$in_tick = false;
        self::record_failure( self::progress(), sprintf( '%s (%s:%d)', $err['message'], basename( (string) $err['file'] ), (int) $err['line'] ) );
        static::lock_delete( self::$worker );
    }

    protected static function last_error() {
        return error_get_last();
    }

    /** Seconds a cron tick keeps taking batches (overridable so checks can run one step per tick). */
    protected static function tick_budget() {
        return self::TICK_BUDGET;
    }

    /** One failed step: note it and carry on; the same step failing MAX_FAILURES times in a row stops the build. */
    private static function record_failure( array $p, $message ) {
        $p['failures']   = (int) ( $p['failures'] ?? 0 ) + 1;
        $p['last_error'] = sprintf( '%s at %s %s/%s: %s', gmdate( 'Y-m-d H:i', time() ), (string) ( $p['phase'] ?? '' ), (int) ( $p['offset'] ?? 0 ), (int) ( $p['total'] ?? 0 ), (string) $message );
        $p['tick_at']    = time();
        if ( $p['failures'] >= self::MAX_FAILURES ) {
            $p['phase']   = 'error';
            $p['notes'][] = sprintf( 'The build stopped after %d failed attempts at the same step. Last error: %s. Start it again when the cause is fixed; the previous report rows are still in place.', self::MAX_FAILURES, $p['last_error'] );
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_clear_scheduled_hook( self::WATCH_HOOK );
        } else {
            self::schedule_tick();
        }
        self::save_progress( $p );
    }

    /* ---- Worker lease and recovery ---------------------------------------------------------------- */

    private static function worker_id() {
        return substr( (string) gethostname(), 0, 40 ) . ':' . getmypid() . ':' . substr( uniqid( '', true ), -6 );
    }

    /** Take the lease if nobody holds a live one. Expired leases are taken over with a compare-and-swap. */
    private static function take_lease( $owner ) {
        $now   = time();
        $until = $now + self::LEASE_SECONDS;
        if ( static::lock_insert( $owner, $until ) ) {
            return true;
        }
        $row = static::lock_get();
        if ( ! $row ) {
            return static::lock_insert( $owner, $until );
        }
        if ( $row['owner'] === $owner || (int) $row['until'] < $now ) {
            return static::lock_replace( $row['raw'], $owner . '|' . $until );
        }
        return false;
    }

    public static function lease_active() {
        $row = static::lock_get();
        return $row && (int) $row['until'] >= time() ? $row : false;
    }

    /** The lease row, read past the object cache: another process may have written it a moment ago. */
    protected static function lock_get() {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LOCK_OPTION ) );
        if ( ! is_string( $raw ) || false === strpos( $raw, '|' ) ) {
            return null;
        }
        list( $owner, $until ) = explode( '|', $raw, 2 );
        return array( 'owner' => $owner, 'until' => (int) $until, 'raw' => $raw );
    }

    protected static function lock_insert( $owner, $until ) {
        global $wpdb;
        return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $owner . '|' . $until ) );
    }

    protected static function lock_replace( $expected, $value ) {
        global $wpdb;
        return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $expected ) );
    }

    protected static function lock_renew( $owner, $until ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $owner . '|' . $until, self::LOCK_OPTION, $wpdb->esc_like( $owner ) . '|%' ) );
    }

    /** Release a lease ('' releases whoever holds it, for a fresh start or a clear). */
    protected static function lock_delete( $owner ) {
        global $wpdb;
        if ( '' === $owner ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
        } else {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_OPTION, $wpdb->esc_like( $owner ) . '|%' ) );
        }
    }

    /**
     * What the background worker is doing right now: idle, queued (a tick is due), running (a worker
     * holds a live lease), interrupted (building, but nothing queued and nobody working), error, done.
     */
    public static function worker_state() {
        $p = self::progress();
        if ( empty( $p['phase'] ) ) {
            return 'idle';
        }
        if ( in_array( $p['phase'], array( 'done', 'error' ), true ) ) {
            return $p['phase'];
        }
        if ( self::lease_active() ) {
            return 'running';
        }
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return 'queued';
        }
        // Between a cron runner taking the tick off the queue and the worker taking the lease there is
        // a moment with neither; a build whose last step was recent is handing over, not interrupted.
        $last = max( (int) ( $p['tick_at'] ?? 0 ), (int) ( $p['started'] ?? 0 ) );
        return $last > time() - self::LEASE_SECONDS ? 'queued' : 'interrupted';
    }

    /** Queue the next tick for a build that is waiting on nothing. Returns true if it did. */
    public static function resume() {
        if ( 'interrupted' !== self::worker_state() ) {
            return false;
        }
        $p             = self::progress();
        $p['notes']    = array_slice( (array) ( $p['notes'] ?? array() ), -19 );
        $p['notes'][]  = sprintf( 'Resumed on %s: the worker had stopped at %s %s/%s with no next step queued. Nothing already scored was lost.', gmdate( 'Y-m-d H:i' ), (string) $p['phase'], number_format_i18n( (int) $p['offset'] ), number_format_i18n( (int) $p['total'] ) );
        $p['failures'] = 0;
        self::save_progress( $p );
        wp_schedule_single_event( time(), self::CRON_HOOK );
        if ( ! wp_next_scheduled( self::WATCH_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCH_HOOK );
        }
        return true;
    }

    /** The watchdog: runs hourly while a build is on, and on every admin page load. Cheap when there is nothing to do. */
    public static function recover() {
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return false; // Front-end admin-ajax traffic is not an admin looking at the dashboard.
        }
        if ( ! self::is_building() ) {
            if ( wp_next_scheduled( self::WATCH_HOOK ) ) {
                wp_clear_scheduled_hook( self::WATCH_HOOK );
            }
            return false;
        }
        return self::resume();
    }

    protected static function run_step() {
        $p = self::progress();
        if ( empty( $p['phase'] ) || in_array( $p['phase'], array( 'done', 'error' ), true ) ) {
            return;
        }

        switch ( $p['phase'] ) {
            case 'gsc':
                self::phase_gsc( $p );
                break;
            case 'ga4':
                self::phase_ga4( $p );
                break;
            case 'links':
                self::phase_links( $p );
                break;
            case 'score':
                self::phase_score( $p );
                break;
        }
    }

    private static function save_progress( array $p ) {
        update_option( self::PROGRESS_OPTION, $p, false );
    }

    /** Phase 1: one bulk Search Console pull for every page with an impression in the window. */
    private static function phase_gsc( array $p ) {
        $signals = get_option( self::SIGNALS_OPTION, array() );
        $gsc     = array();

        // A WP-Cron tick is neither admin, REST nor CLI, so the Search Console class may not be loaded.
        if ( ! class_exists( 'AceSEOSearchConsole' ) && class_exists( 'AceSEOSiteKit' ) && defined( 'ACE_SEO_PATH' ) ) {
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-search-console.php';
        }

        if ( class_exists( 'AceSEOSearchConsole' ) && AceSEOSearchConsole::is_ready() ) {
            $report = AceSEOSearchConsole::pages_report( (int) $p['settings']['days'] );
            if ( is_wp_error( $report ) ) {
                $p['notes'][] = 'Search Console: ' . $report->get_error_message() . ' — scored without search data.';
            } else {
                // Keyed by path so a URL survives http/https and host differences between the property
                // and the site.
                foreach ( $report as $url => $row ) {
                    $gsc[ self::path_key( $url ) ] = $row;
                }
            }
        } else {
            $p['notes'][] = 'Search Console is not connected (Site Kit): scored on links and page views only, so "no signal" is not trustworthy.';
        }

        // Only candidates are ever scored, so only their rows are kept. The whole property's pages (51,000
        // on a large news site) plus Analytics' paths overran MySQL's max_allowed_packet as one option, the
        // save failed quietly, and every post was scored with no search or view data at all.
        $signals['gsc'] = array_intersect_key( $gsc, self::candidate_lookup( $p['settings'] ) );
        if ( ! empty( $gsc ) ) {
            self::store_periods( 'gsc', $p['settings'] );
        }
        update_option( self::SIGNALS_OPTION, $signals, false );

        $p['phase'] = 'ga4';
        self::save_progress( $p );
    }

    /**
     * Phase 1b: page views per path from Google Analytics (Site Kit's connection), for the window,
     * plus the last seven days' share of views going to old posts. Without Analytics the report
     * falls back to the plugin's own view tracking, if that is on.
     */
    private static function phase_ga4( array $p ) {
        $signals        = get_option( self::SIGNALS_OPTION, array() );
        $signals['ga4'] = null;

        $days   = max( 1, min( 480, (int) $p['settings']['days'] ) );
        $report = self::ga4_report( gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ), gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
        $views  = is_wp_error( $report ) ? $report : $report['rows'];
        if ( is_wp_error( $views ) ) {
            $p['notes'][] = 'Google Analytics: ' . $views->get_error_message() . ( class_exists( 'AceSeoViewTracker' ) && AceSeoViewTracker::enabled() ? ' Views come from the plugin\'s own tracking instead.' : ' No views source: tiers lean on search clicks.' );
        } else {
            // Analytics listed every page with a view only if it did not hit its row ceiling. When it did,
            // a page absent from the rows is unknown, not zero, and the scorer treats it that way.
            $signals['ga4_capped'] = ! empty( $report['capped'] );
            if ( $signals['ga4_capped'] ) {
                $p['notes'][] = 'Google Analytics returned its maximum number of rows, so pages it did not list have unknown views rather than none.';
            }
            // A property younger than the window cannot say a page was quiet for the whole window.
            $from = self::ga4_first_day();
            if ( is_string( $from ) && '' !== $from ) {
                $signals['ga4_from'] = $from;
                if ( $from > gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ) ) {
                    $p['notes'][] = sprintf( 'Google Analytics only has data from %s, so the %d-day window is not fully covered: posts without readers are held as "Not ready to judge" rather than called quiet.', $from, $days );
                }
            }
            $signals['ga4'] = array_intersect_key( $views, self::candidate_lookup( $p['settings'] ) );
            self::store_periods( 'ga4', $p['settings'] );

            $week = self::ga4_page_views( 7 );
            if ( ! is_wp_error( $week ) ) {
                $lookup = self::candidate_lookup( $p['settings'] );
                $old    = 0;
                $total  = 0;
                foreach ( $week as $path => $n ) {
                    $total += $n;
                    if ( isset( $lookup[ $path ] ) ) {
                        $old += $n;
                    }
                }
                $p['share'] = array(
                    'days'   => 7,
                    'old'    => $old,
                    'total'  => $total,
                    'source' => 'Google Analytics',
                    'at'     => time(),
                );
            }
        }
        update_option( self::SIGNALS_OPTION, $signals, false );

        $p['phase']  = 'links';
        $p['offset'] = 0;
        $p['total']  = self::count_all_posts( $p['settings']['post_types'] );
        self::save_progress( $p );
    }

    /**
     * Page views per path over the last $days days, from the GA4 property Site Kit is connected to.
     * One request per 100,000 rows, cached for twelve hours.
     *
     * @return array|WP_Error path => views
     */
    public static function ga4_page_views( $days ) {
        $days   = max( 1, min( 480, (int) $days ) );
        $report = self::ga4_report( gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ), gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
        return is_wp_error( $report ) ? $report : $report['rows'];
    }

    /**
     * Page views per path between two dates (inclusive) from Google Analytics via Site Kit's token.
     * Returns array( 'rows' => path => views, 'capped' => bool ): capped means the API's row ceiling was
     * hit and the long tail is missing, so a page absent from the rows is not a measured zero.
     */
    public static function ga4_report( $start, $end ) {
        if ( ! class_exists( 'AceSEOSiteKit' ) || ! AceSEOSiteKit::is_active() ) {
            return new WP_Error( 'ga4_no_sitekit', 'Site Kit is not active.' );
        }
        $property = AceSEOSiteKit::get_analytics_property_id();
        if ( '' === $property ) {
            return new WP_Error( 'ga4_no_property', 'Site Kit has no Analytics property connected.' );
        }

        $cache_key = 'ace_seo_ga4_paths_' . md5( $property . '|' . $start . '|' . $end );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
            return $cached;
        }

        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        if ( empty( $token ) ) {
            return new WP_Error( 'ga4_no_token', 'No Site Kit token for Analytics.' );
        }

        $views  = array();
        $offset = 0;
        $limit  = 100000;
        $max    = 500000;
        do {
            $response = wp_remote_post(
                'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport',
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'    => wp_json_encode( array(
                        'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
                        'dimensions' => array( array( 'name' => 'pagePath' ) ),
                        'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
                        'limit'      => $limit,
                        'offset'     => $offset,
                    ) ),
                )
            );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            if ( $code < 200 || $code >= 300 ) {
                return new WP_Error( 'ga4_http', $body['error']['message'] ?? 'Analytics request failed with HTTP ' . $code . '.' );
            }
            $rows = isset( $body['rows'] ) && is_array( $body['rows'] ) ? $body['rows'] : array();
            foreach ( $rows as $row ) {
                $key = self::path_key( (string) ( $row['dimensionValues'][0]['value'] ?? '' ) );
                if ( '' !== $key ) {
                    $views[ $key ] = ( $views[ $key ] ?? 0 ) + (int) ( $row['metricValues'][0]['value'] ?? 0 );
                }
            }
            $offset += $limit;
        } while ( count( $rows ) === $limit && $offset < $max );

        $report = array( 'rows' => $views, 'capped' => count( $rows ) === $limit && $offset >= $max );
        set_transient( $cache_key, $report, 12 * HOUR_IN_SECONDS );
        return $report;
    }

    /**
     * The first day the Analytics property has any page view for ('' when unknown): coverage cannot
     * start earlier. One request a day; the API allows ranges back to 2015.
     */
    public static function ga4_first_day() {
        if ( ! class_exists( 'AceSEOSiteKit' ) || ! AceSEOSiteKit::is_active() ) {
            return '';
        }
        $property = AceSEOSiteKit::get_analytics_property_id();
        if ( '' === $property ) {
            return '';
        }
        $cache_key = 'ace_seo_ga4_first_' . md5( $property );
        $cached    = get_transient( $cache_key );
        if ( is_string( $cached ) ) {
            return $cached;
        }
        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
        if ( is_wp_error( $token ) || empty( $token ) ) {
            return '';
        }
        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport',
            array(
                'timeout' => 30,
                'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'dateRanges'      => array( array( 'startDate' => '2015-08-14', 'endDate' => 'yesterday' ) ),
                    'dimensions'      => array( array( 'name' => 'date' ) ),
                    'metrics'         => array( array( 'name' => 'screenPageViews' ) ),
                    'orderBys'        => array( array( 'dimension' => array( 'dimensionName' => 'date' ) ) ),
                    'limit'           => 1,
                    'keepEmptyRows'   => false,
                ) ),
            )
        );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return '';
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $raw  = (string) ( $body['rows'][0]['dimensionValues'][0]['value'] ?? '' );
        $day  = preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
        set_transient( $cache_key, $day, DAY_IN_SECONDS );
        return $day;
    }

    /**
     * Phase 2: inbound internal links. Every published post's content is read once, in batches, and
     * each internal link it contains is counted against the candidate it points at.
     */
    private static function phase_links( array $p ) {
        global $wpdb;

        $signals = get_option( self::SIGNALS_OPTION, array() );
        $links   = isset( $signals['links'] ) && is_array( $signals['links'] ) ? $signals['links'] : array();
        $lookup  = self::candidate_lookup( $p['settings'] );

        $types = array_map( 'esc_sql', (array) $p['settings']['post_types'] );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('" . implode( "','", $types ) . "') ORDER BY ID ASC LIMIT %d OFFSET %d",
            self::BATCH,
            (int) $p['offset']
        ) );

        $home = self::path_key( home_url( '/' ) );
        foreach ( $rows as $row ) {
            if ( '' === $row->post_content || false === strpos( $row->post_content, 'href' ) ) {
                continue;
            }
            if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', $row->post_content, $m ) ) {
                continue;
            }
            $seen = array();
            foreach ( array_unique( $m[1] ) as $href ) {
                $key = self::path_key( $href );
                if ( '' === $key || $key === $home || isset( $seen[ $key ] ) || ! isset( $lookup[ $key ] ) ) {
                    continue;
                }
                $target = $lookup[ $key ];
                if ( $target === (int) $row->ID ) {
                    continue;
                }
                $seen[ $key ]    = true;
                $links[ $target ] = ( $links[ $target ] ?? 0 ) + 1;
            }
        }

        $signals['links'] = $links;
        update_option( self::SIGNALS_OPTION, $signals, false );

        $p['offset'] += self::BATCH;
        if ( count( $rows ) < self::BATCH ) {
            $p['phase']  = 'score';
            $p['offset'] = 0;
            $p['total']  = self::count_candidates( $p['settings'] );
        }
        self::save_progress( $p );
    }

    /** Phase 3: score each candidate and write its bucket to post meta. */
    private static function phase_score( array $p ) {
        $settings = $p['settings'];
        $signals  = get_option( self::SIGNALS_OPTION, array() );
        $gsc      = $signals['gsc'] ?? array();
        $links    = $signals['links'] ?? array();

        $ids = self::candidate_ids( $settings, self::BATCH, (int) $p['offset'] );
        $ga4 = isset( $signals['ga4'] ) && is_array( $signals['ga4'] ) ? $signals['ga4'] : null;

        // The plugin's own tracking, where Analytics is not connected: human views in the window.
        $tracked = null;
        if ( null === $ga4 && class_exists( 'AceSeoViewTracker' ) && AceSeoViewTracker::enabled() ) {
            $tracked = AceSeoViewTracker::views_for( $ids, (int) $settings['days'] );
        }

        $words = self::word_counts( $ids );
        // The dates this build's traffic covers, for the timing rule.
        $period = array( 'start' => gmdate( 'Y-m-d', (int) $p['started'] - (int) $settings['days'] * DAY_IN_SECONDS ), 'end' => gmdate( 'Y-m-d', (int) $p['started'] - DAY_IN_SECONDS ) );
        if ( ! class_exists( 'Ace_SEO_Retention_Evidence_View' ) && defined( 'ACE_SEO_PATH' ) ) {
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-retention-evidence-view.php';
        }

        /**
         * Page views per post over the same window, from whatever analytics the site has:
         * array( post_id => views ). Takes precedence over Analytics (Site Kit) and the plugin's own
         * tracking; without any of them the signal is absent.
         */
        $views = apply_filters( 'ace_seo_retention_pageviews', array(), $ids, $settings );
        /** External backlinks per post, array( post_id => count ), if the site has a source. */
        $backlinks = apply_filters( 'ace_seo_retention_backlinks', array(), $ids, $settings );

        foreach ( $ids as $id ) {
            $key = self::path_key( get_permalink( $id ) );
            $g   = $gsc[ $key ] ?? array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );

            if ( isset( $views[ $id ] ) ) {
                $view_count = (int) $views[ $id ];
            } elseif ( null !== $ga4 ) {
                // Analytics lists every page with a view, unless its row ceiling cut the list short.
                $view_count = isset( $ga4[ $key ] ) ? (int) $ga4[ $key ] : ( empty( $signals['ga4_capped'] ) ? 0 : null );
            } elseif ( null !== $tracked ) {
                $view_count = (int) ( $tracked[ $id ] ?? 0 );
            } else {
                $view_count = null;
            }

            $row = array(
                'clicks'      => (int) $g['clicks'],
                'impressions' => (int) $g['impressions'],
                'position'    => (float) $g['position'],
                'links_in'    => (int) ( $links[ $id ] ?? 0 ),
                'views'       => $view_count,
                'backlinks'   => isset( $backlinks[ $id ] ) ? (int) $backlinks[ $id ] : null,
                'words'       => (int) ( $words[ $id ] ?? 0 ),
            );
            $row['periods'] = array();
            foreach ( self::PERIODS as $days ) {
                $v = self::period( 'ga4', $days );
                if ( $v ) {
                    $row['periods'][ 'views_' . $days ] = (int) ( $v[ $id ] ?? 0 );
                }
                $g = self::period( 'gsc', $days );
                if ( $g ) {
                    $row['periods'][ 'clicks_' . $days ]      = (int) ( $g[ $id ][0] ?? 0 );
                    $row['periods'][ 'impressions_' . $days ] = (int) ( $g[ $id ][1] ?? 0 );
                }
            }
            list( $row['trend'], $row['momentum'] ) = self::trend( $row, $settings );

            $prev = get_post_meta( $id, self::META, true );
            $prev = is_array( $prev ) ? $prev : array();
            list( $bucket, $reason ) = self::bucket( $row, $settings );
            $row['bucket'] = $bucket;
            $row['reason'] = $reason;
            $row['tier']   = self::tier( $row, $settings );
            $row['built']  = time();
            $row['window'] = (int) $settings['days'];

            // Timing: an article is only judged on a period that contained the dates it is about.
            // Editor-set dates, a verified event occurrence or (recurring) the publication anniversary.
            // Readers and clicks still count: a retained article is never held.
            if ( 'retained' !== $row['tier'] && class_exists( 'Ace_SEO_Retention_Evidence' ) ) {
                $hold = '';
                if ( ! empty( $signals['ga4_from'] ) && $signals['ga4_from'] > $period['start'] ) {
                    $hold = sprintf( 'Google Analytics only has data from %s, so the %d-day window (%s to %s) is not fully covered; a quiet result cannot be trusted yet.', $signals['ga4_from'], (int) $settings['days'], $period['start'], $period['end'] );
                }
                if ( '' === $hold ) {
                    $hold = self::timing_hold_for( $id, $period, 'strict' === ( $settings['timing_policy'] ?? 'estimate' ) );
                }
                if ( '' !== $hold ) {
                    $row['tier']   = 'unknown';
                    $row['bucket'] = 'no-signal';
                    $row['reason'] = 'Not ready to judge. ' . $hold;
                    $row['hold']   = $hold;
                }
            }
            $row['rank'] = 'retained' === $row['tier'] ? self::rank( $row, $settings ) : '';
            if ( '' !== $row['rank'] ) {
                $p['ranks'][ $row['rank'] ] = ( $p['ranks'][ $row['rank'] ] ?? 0 ) + 1;
            }

            $row = apply_filters( 'ace_seo_retention_row', $row, $id, $settings );
            update_post_meta( $id, self::META, $row );
            self::write_flat_meta( $id, $row, (int) $p['started'] );
            $p['counts'][ $row['bucket'] ] = ( $p['counts'][ $row['bucket'] ] ?? 0 ) + 1;
            // Movement since the last build, for the week-by-week history: "keep>refresh", "new>keep".
            $from = (string) ( $prev['bucket'] ?? 'new' );
            if ( $from !== $row['bucket'] ) {
                $move = $from . '>' . $row['bucket'];
                $p['moves'][ $move ] = ( $p['moves'][ $move ] ?? 0 ) + 1;
            }
            $trfrom = (string) ( $prev['trend'] ?? 'new' );
            if ( $trfrom !== $row['trend'] ) {
                $move = $trfrom . '>' . $row['trend'];
                $p['trend_moves'][ $move ] = ( $p['trend_moves'][ $move ] ?? 0 ) + 1;
            }
            $p['trends'][ $row['trend'] ] = ( $p['trends'][ $row['trend'] ] ?? 0 ) + 1;

            // Per top-level category, so sections can be compared with each other and week to week.
            $cats = get_the_category( $id );
            if ( $cats ) {
                $top = $cats[0];
                while ( $top->parent && ( $parent = get_category( $top->parent ) ) && ! is_wp_error( $parent ) ) {
                    $top = $parent;
                }
                $c = $p['cats'][ $top->term_id ] ?? array( 'posts' => 0, 'views' => 0, 'views_30' => 0, 'views_90' => 0, 'clicks' => 0, 'retained' => 0, 'rising' => 0, 'falling' => 0, 'gone' => 0 );
                $c['posts']++;
                $c['views']    += (int) $row['views'];
                $c['views_30'] += (int) ( $row['periods']['views_30'] ?? 0 );
                $c['views_90'] += (int) ( $row['periods']['views_90'] ?? 0 );
                $c['clicks']   += (int) $row['clicks'];
                foreach ( array( 'rising', 'falling', 'gone' ) as $t ) {
                    $c[ $t ] += $t === $row['trend'] ? 1 : 0;
                }
                $c['retained'] += 'retained' === $row['tier'] ? 1 : 0;
                $p['cats'][ $top->term_id ] = $c;
            }

            $tfrom = (string) ( $prev['tier'] ?? 'new' );
            if ( isset( $row['tier'] ) && $tfrom !== $row['tier'] ) {
                $move = $tfrom . '>' . $row['tier'];
                $p['tier_moves'][ $move ] = ( $p['tier_moves'][ $move ] ?? 0 ) + 1;
            }
            if ( isset( $row['tier'] ) && in_array( $row['tier'], self::TIERS, true ) ) {
                $p['tiers'][ $row['tier'] ] = ( $p['tiers'][ $row['tier'] ] ?? 0 ) + 1;
            }
        }

        $p['offset'] += self::BATCH;
        if ( count( $ids ) < self::BATCH ) {
            $p['phase']    = 'done';
            $p['finished'] = time();
            delete_option( self::SIGNALS_OPTION );
            self::forget_periods();
            self::forget_stale( (int) $p['started'] );
            self::record_history( $p );
            /** A build has finished and every row is saved; exports that follow the report hook here. */
            do_action( 'ace_seo_retention_built', $p );
        }
        self::save_progress( $p );
    }

    /** Why this build's period cannot judge the post yet ('' when it can). Site knowledge only; no API calls. */
    public static function timing_hold_for( $id, array $period, $strict = false ) {
        if ( ! class_exists( 'Ace_SEO_Retention_Evidence_View' ) ) {
            return '';
        }
        try {
            $context = Ace_SEO_Retention_Evidence_View::base_context( (int) $id, array( 'published' => get_post_time( 'Y-m-d', true, $id ) ), $period, gmdate( 'Y-m-d' ) );
            return Ace_SEO_Retention_Evidence::timing_hold( Ace_SEO_Retention_Evidence::relevance( $context ), $period, $strict );
        } catch ( Throwable $e ) {
            return '';
        }
    }

    /** One line per finished build, newest last, so the screen can show how the archive moves week to week. */
    private static function record_history( array $p ) {
        $h   = self::history();
        $h[] = array(
            'finished'   => (int) $p['finished'],
            'started'    => (int) $p['started'],
            'years'      => (int) $p['settings']['older_than_years'],
            'days'       => (int) $p['settings']['days'],
            'counts'     => (array) $p['counts'],
            'tiers'      => (array) ( $p['tiers'] ?? array() ),
            'moves'      => (array) ( $p['moves'] ?? array() ),
            'tier_moves' => (array) ( $p['tier_moves'] ?? array() ),
            'trends'     => (array) ( $p['trends'] ?? array() ),
            'trend_moves'=> (array) ( $p['trend_moves'] ?? array() ),
            'cats'       => (array) ( $p['cats'] ?? array() ),
            'share'      => $p['share'] ?? null,
        );
        update_option( self::HISTORY_OPTION, array_slice( $h, -self::HISTORY_KEEP ), false );
    }

    public static function history() {
        $h = get_option( self::HISTORY_OPTION, array() );
        return is_array( $h ) ? array_values( $h ) : array();
    }

    /** Week by week: each build's buckets and tiers with the change on the one before, and what moved. */
    private static function render_history( array $labels ) {
        $h = self::history();
        if ( ! $h ) {
            return;
        }
        $delta       = static function ( $now, $before ) {
            if ( null === $before ) {
                return '';
            }
            $d = (int) $now - (int) $before;
            return $d ? ' <span style="color:' . ( $d > 0 ? '#1a7f37' : '#b32d2e' ) . ';font-size:11px">' . ( $d > 0 ? '+' : '' ) . esc_html( number_format_i18n( $d ) ) . '</span>' : '';
        };
        ?>
        <h2>Observation history</h2>
        <p>The last 26 completed builds, including manual rebuilds. This is not necessarily 26 weeks or a full year. Compare like with like: changing the age cutoff or traffic window can also move posts between groups.</p>
        <table class="widefat striped" style="max-width:1100px"><thead><tr>
            <th>Built</th>
            <?php foreach ( self::BUCKETS as $b ) : ?><th><?php echo esc_html( $labels[ $b ] ?? $b ); ?></th><?php endforeach; ?>
            <?php foreach ( self::TIERS as $t ) : ?><th><?php echo esc_html( self::tier_labels()[ $t ] ?? $t ); ?></th><?php endforeach; ?>
            <th>Rising</th><th>Falling</th><th>Gone quiet</th>
            <th>Old posts' share of views</th><th>Biggest moves</th>
        </tr></thead><tbody>
        <?php
        foreach ( array_reverse( $h, true ) as $i => $row ) :
            $prev  = $h[ $i - 1 ] ?? null;
            $moves = array_filter( (array) $row['moves'], static function ( $n, $k ) {
                return 0 !== strpos( $k, 'new>' );
            }, ARRAY_FILTER_USE_BOTH );
            arsort( $moves );
            ?>
            <tr>
                <td><?php echo esc_html( wp_date( 'j M Y', (int) $row['finished'] ) ); ?><br><span style="font-size:11px;color:#666"><?php echo esc_html( (int) $row['years'] . 'y+, ' . (int) $row['days'] . '-day window' ); ?></span></td>
                <?php foreach ( self::BUCKETS as $b ) : ?><td><?php echo esc_html( number_format_i18n( (int) ( $row['counts'][ $b ] ?? 0 ) ) ) . $delta( $row['counts'][ $b ] ?? 0, $prev ? ( $prev['counts'][ $b ] ?? 0 ) : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?>
                <?php foreach ( self::TIERS as $t ) : ?><td><?php echo esc_html( number_format_i18n( (int) ( $row['tiers'][ $t ] ?? 0 ) ) ) . $delta( $row['tiers'][ $t ] ?? 0, $prev ? ( $prev['tiers'][ $t ] ?? 0 ) : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?>
                <?php foreach ( array( 'rising', 'falling', 'gone' ) as $t ) : ?><td><?php echo isset( $row['trends'] ) ? esc_html( number_format_i18n( (int) ( $row['trends'][ $t ] ?? 0 ) ) ) . $delta( $row['trends'][ $t ] ?? 0, $prev && isset( $prev['trends'] ) ? ( $prev['trends'][ $t ] ?? 0 ) : null ) : '–'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?>
                <td><?php echo ! empty( $row['share']['total'] ) ? esc_html( round( 100 * $row['share']['old'] / $row['share']['total'], 1 ) . '%' ) : '–'; ?></td>
                <td style="font-size:11px"><?php
                    $out = array();
                    foreach ( array_slice( $moves, 0, 4, true ) as $k => $n ) {
                        list( $a, $b ) = explode( '>', $k );
                        $out[] = esc_html( ( $labels[ $a ] ?? $a ) . ' → ' . ( $labels[ $b ] ?? $b ) . ': ' . number_format_i18n( $n ) );
                    }
                    echo $out ? implode( '<br>', $out ) : ( $prev ? 'No moves' : 'First build' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
        self::render_categories( $h );
    }

    /**
     * Sections side by side from the latest build: how much each is still read, how its last month
     * compares with its year, and how that has moved since the build before.
     */
    private static function render_categories( array $h ) {
        $last = end( $h );
        if ( empty( $last['cats'] ) ) {
            return;
        }
        $before = count( $h ) > 1 ? $h[ count( $h ) - 2 ]['cats'] ?? array() : array();
        $cats   = $last['cats'];
        uasort( $cats, static function ( $a, $b ) {
            return $b['views'] <=> $a['views'];
        } );
        $days = max( 1, (int) $last['days'] );
        $mom  = static function ( $c ) use ( $days ) {
            return $c['views'] > 0 ? ( $c['views_30'] / 30 ) / ( $c['views'] / $days ) : null;
        };
        ?>
        <h2>By section</h2>
        <p>Old posts grouped by top-level category. <strong>Momentum</strong> is the last 30 days' daily views against the <?php echo esc_html( $days ); ?>-day average: above 1 the section's old posts are being read more than usual lately, below 1 less. The arrow compares it with the build before.</p>
        <table class="widefat striped" style="max-width:1100px"><thead><tr>
            <th>Section</th><th>Old posts</th><th>Retained</th><th>Views (window)</th><th>Views (30 days)</th><th>Momentum</th><th>Rising</th><th>Falling</th><th>Gone quiet</th><th>Search clicks</th>
        </tr></thead><tbody>
        <?php foreach ( array_slice( $cats, 0, 40, true ) as $term_id => $c ) :
            $term = get_term( (int) $term_id, 'category' );
            $m    = $mom( $c );
            $pm   = isset( $before[ $term_id ] ) ? $mom( $before[ $term_id ] ) : null;
            $link = admin_url( 'edit.php?post_type=post&cat=' . (int) $term_id );
            ?>
            <tr>
                <td><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id ); ?></a></td>
                <td><?php echo esc_html( number_format_i18n( $c['posts'] ) ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['retained'] ) . ' (' . round( 100 * $c['retained'] / max( 1, $c['posts'] ) ) . '%)' ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['views'] ) ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['views_30'] ) ); ?></td>
                <td><?php echo null === $m ? '–' : esc_html( number_format_i18n( $m, 2 ) ) . ( null !== $pm && abs( $m - $pm ) >= 0.05 ? ( $m > $pm ? ' <span style="color:#1a7f37">▲</span>' : ' <span style="color:#b32d2e">▼</span>' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['rising'] ) ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['falling'] ) ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['gone'] ) ); ?></td>
                <td><?php echo esc_html( number_format_i18n( $c['clicks'] ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    /**
     * The tier: retained if it is still being read, a candidate if nobody reads it and there is
     * little to it, dormant otherwise. Where no views source exists, search clicks stand in.
     */
    /** Views needed in the window for each band, from its length; the last band is the retained floor. */
    public static function rank_cutoffs( array $s ) {
        $days = max( 7, (int) ( $s['days'] ?? 90 ) );
        return array(
            'daily'      => $days,
            'weekly'     => (int) ceil( $days / 7 ),
            'monthly'    => (int) ceil( $days / 30 ),
            'occasional' => max( 1, (int) ( $s['retained_views'] ?? 1 ) ),
        );
    }

    /**
     * The band a retained post sits in by its views in the window; search clicks alone put it in the
     * lowest band. '' for anything not retained, so bands and tiers never disagree.
     */
    public static function rank( array $r, array $s ) {
        if ( 'retained' !== self::tier( $r, $s ) ) {
            return '';
        }
        $views = isset( $r['views'] ) ? (int) $r['views'] : 0;
        foreach ( self::rank_cutoffs( $s ) as $rank => $min ) {
            if ( $views >= $min ) {
                return $rank;
            }
        }
        return 'occasional';
    }

    public static function rank_labels( array $s = array() ) {
        $c = self::rank_cutoffs( $s ?: self::settings() );
        return array(
            'daily'      => sprintf( __( 'Read daily (%s+ views in the window)', 'ace-crawl-enhancer' ), number_format_i18n( $c['daily'] ) ),
            'weekly'     => sprintf( __( 'Read weekly (%s+)', 'ace-crawl-enhancer' ), number_format_i18n( $c['weekly'] ) ),
            'monthly'    => sprintf( __( 'Read monthly (%s+)', 'ace-crawl-enhancer' ), number_format_i18n( $c['monthly'] ) ),
            'occasional' => sprintf( __( 'Read occasionally (%s+, or any search click)', 'ace-crawl-enhancer' ), number_format_i18n( $c['occasional'] ) ),
        );
    }

    public static function rank_counts() {
        global $wpdb;
        $counts = array_fill_keys( self::RANKS, 0 );
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META_RANK ) );
        foreach ( (array) $rows as $row ) {
            if ( isset( $counts[ $row->meta_value ] ) ) {
                $counts[ $row->meta_value ] = (int) $row->n;
            }
        }
        return $counts;
    }

    public static function tier( array $r, array $s ) {
        $views  = isset( $r['views'] ) ? $r['views'] : null;
        $clicks = (int) ( $r['clicks'] ?? 0 );

        if ( ( null !== $views && (int) $views >= max( 1, (int) $s['retained_views'] ) ) || $clicks > 0 ) {
            return 'retained';
        }
        // Absence of a source cannot establish that nobody reads a post.
        if ( null === $views ) {
            return 'unknown';
        }
        $unread = 0 === (int) $views;
        if ( $unread && (int) ( $r['words'] ?? 0 ) < (int) $s['thin_words'] ) {
            return 'candidate';
        }
        return 'dormant';
    }

    /** Word counts for a batch of posts in one query, markup and shortcodes stripped. */
    private static function word_counts( array $ids ) {
        global $wpdb;
        if ( ! $ids ) {
            return array();
        }
        $out  = array();
        $rows = $wpdb->get_results( 'SELECT ID, post_content FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' );
        foreach ( $rows as $row ) {
            $text = wp_strip_all_tags( strip_shortcodes( (string) $row->post_content ) );
            $out[ (int) $row->ID ] = $text === '' ? 0 : count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
        }
        return $out;
    }

    /** One meta key per signal, so the post list can filter and sort without unpacking the row. */
    public static function write_flat_meta( $id, array $row, $build ) {
        update_post_meta( $id, self::META_TIER, (string) ( $row['tier'] ?? '' ) );
        if ( ! empty( $row['rank'] ) ) {
            update_post_meta( $id, self::META_RANK, (string) $row['rank'] );
        } else {
            delete_post_meta( $id, self::META_RANK );
        }
        update_post_meta( $id, self::META_WORDS, (int) ( $row['words'] ?? 0 ) );
        update_post_meta( $id, self::META_LINKS, (int) ( $row['links_in'] ?? 0 ) );
        if ( isset( $row['views'] ) && null !== $row['views'] ) {
            update_post_meta( $id, self::META_VIEWS, (int) $row['views'] );
        } else {
            delete_post_meta( $id, self::META_VIEWS );
        }
        update_post_meta( $id, self::META_BUILT, (int) $build );
        update_post_meta( $id, self::META_TREND, (string) ( $row['trend'] ?? '' ) );
        if ( isset( $row['momentum'] ) && null !== $row['momentum'] ) {
            update_post_meta( $id, self::META_MOMENTUM, (float) $row['momentum'] );
        } else {
            delete_post_meta( $id, self::META_MOMENTUM );
        }
    }

    /** Meta keys the report owns, for clearing. */
    public static function meta_keys() {
        return array( self::META, self::META_TIER, self::META_RANK, self::META_VIEWS, self::META_WORDS, self::META_LINKS, self::META_BUILT, self::META_TREND, self::META_MOMENTUM );
    }

    /* ---- Periods and trends --------------------------------------------------------------------- */

    /**
     * Views (Analytics) or clicks and impressions (Search Console) for each shorter period, keyed by
     * post ID. Search Console runs two to three days behind, so its 7- and 14-day figures are skipped.
     */
    private static function store_periods( $source, array $settings ) {
        $lookup = self::candidate_lookup( $settings );
        foreach ( self::PERIODS as $days ) {
            if ( $days >= (int) $settings['days'] || ( 'gsc' === $source && $days < 30 ) ) {
                continue;
            }
            $data = 'ga4' === $source ? self::ga4_page_views( $days ) : ( class_exists( 'AceSEOSearchConsole' ) ? AceSEOSearchConsole::pages_report( $days ) : null );
            if ( ! is_array( $data ) ) {
                continue;
            }
            $by_id = array();
            foreach ( $data as $path => $value ) {
                $key = 'gsc' === $source ? self::path_key( $path ) : $path;
                if ( isset( $lookup[ $key ] ) ) {
                    $by_id[ $lookup[ $key ] ] = 'gsc' === $source ? array( (int) $value['clicks'], (int) $value['impressions'] ) : (int) $value;
                }
            }
            update_option( self::PERIOD_OPTION . $source . '_' . $days, $by_id, false );
        }
    }

    /** @return array post ID => views (ga4) or [clicks, impressions] (gsc); empty when absent. */
    private static function period( $source, $days ) {
        static $cache = array();
        $key = $source . '_' . $days;
        if ( ! isset( $cache[ $key ] ) ) {
            $v             = get_option( self::PERIOD_OPTION . $key, array() );
            $cache[ $key ] = is_array( $v ) ? $v : array();
        }
        return $cache[ $key ];
    }

    private static function forget_periods() {
        foreach ( array( 'ga4', 'gsc' ) as $source ) {
            foreach ( self::PERIODS as $days ) {
                delete_option( self::PERIOD_OPTION . $source . '_' . $days );
            }
        }
    }

    /**
     * Momentum: the last 30 days' daily rate against the whole window's (views where Analytics is wired
     * in, search clicks otherwise). 1 is steady, 2 is twice as busy as usual lately, 0.5 half. Too few
     * views over the window to read a trend from is "quiet", and a page that was read in the window but
     * not in the last 90 days is "gone".
     *
     * @return array{0: string, 1: float|null}
     */
    public static function trend( array $row, array $settings ) {
        $window = max( 1, (int) $settings['days'] );
        $total  = null !== $row['views'] ? (int) $row['views'] : (int) $row['clicks'];
        $recent = null !== $row['views'] ? ( $row['periods']['views_30'] ?? null ) : ( $row['periods']['clicks_30'] ?? null );
        $ninety = null !== $row['views'] ? ( $row['periods']['views_90'] ?? null ) : ( $row['periods']['clicks_90'] ?? null );
        if ( null === $recent || $window <= 30 ) {
            return array( 'quiet', null );
        }
        if ( $total >= 3 && null !== $ninety && 0 === (int) $ninety && $window > 90 ) {
            return array( 'gone', 0.0 );
        }
        if ( $total < max( 6, (int) $settings['retained_views'] ) ) {
            return array( 'quiet', null );
        }
        $momentum = round( ( (int) $recent / 30 ) / ( $total / $window ), 2 );
        if ( $momentum >= 1.5 ) {
            return array( 'rising', $momentum );
        }
        if ( $momentum <= 0.5 ) {
            return array( 'falling', $momentum );
        }
        return array( 'steady', $momentum );
    }

    public static function trend_labels() {
        return array(
            'rising'  => __( 'Rising', 'ace-crawl-enhancer' ),
            'steady'  => __( 'Steady', 'ace-crawl-enhancer' ),
            'falling' => __( 'Falling', 'ace-crawl-enhancer' ),
            'gone'    => __( 'Gone quiet', 'ace-crawl-enhancer' ),
            'quiet'   => __( 'Too quiet to tell', 'ace-crawl-enhancer' ),
        );
    }

    /**
     * After a rebuild, drop what the previous build wrote for posts this one no longer scored (the
     * cutoff moved, or the post was unpublished), so the list and its filters only show this build.
     */
    private static function forget_stale( $build ) {
        global $wpdb;
        if ( $build <= 0 ) {
            return;
        }
        $stale = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d",
            self::META_BUILT,
            $build
        ) );
        // Rows from before 1.0.41 carry no build stamp; they were scored by an older build too.
        $unstamped = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->postmeta} b ON b.post_id = pm.post_id AND b.meta_key = %s WHERE pm.meta_key = %s AND b.meta_id IS NULL",
            self::META_BUILT,
            self::META
        ) );
        foreach ( array_unique( array_merge( $stale, $unstamped ) ) as $post_id ) {
            foreach ( self::meta_keys() as $key ) {
                delete_post_meta( (int) $post_id, $key );
            }
        }
    }

    /** Counts per tier, from the flat meta. */
    public static function tier_counts() {
        global $wpdb;
        $counts = array_fill_keys( self::TIERS, 0 );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value",
            self::META_TIER
        ) );
        foreach ( $rows as $row ) {
            if ( isset( $counts[ $row->meta_value ] ) ) {
                $counts[ $row->meta_value ] = (int) $row->n;
            }
        }
        return $counts;
    }

    public static function tier_labels( array $s = array() ) {
        $floor = max( 1, (int) ( ( $s ?: self::settings() )['retained_views'] ?? 1 ) );
        return array(
            'retained'  => __( 'Still being read', 'ace-crawl-enhancer' ),
            'candidate' => 1 === $floor ? __( 'Short, with no recorded readers', 'ace-crawl-enhancer' ) : sprintf( __( 'Short, with fewer than %d views', 'ace-crawl-enhancer' ), $floor ),
            'dormant'   => 1 === $floor ? __( 'No recorded readers', 'ace-crawl-enhancer' ) : sprintf( __( 'Below the readership threshold (fewer than %d views)', 'ace-crawl-enhancer' ), $floor ),
            'unknown'   => __( 'Not ready to judge', 'ace-crawl-enhancer' ),
        );
    }

    public static function recommendation_labels() {
        return array(
            'keep'        => __( 'Keep', 'ace-crawl-enhancer' ),
            'refresh'     => __( 'Needs an update', 'ace-crawl-enhancer' ),
            'consolidate' => __( 'Consider combining', 'ace-crawl-enhancer' ),
            'noindex'     => __( 'Review whether it should appear in search', 'ace-crawl-enhancer' ),
            'no-signal'   => __( 'Not enough data yet', 'ace-crawl-enhancer' ),
        );
    }

    public static function render_message() {
        $key = 'ace_seo_retention_msg_' . get_current_user_id();
        $msg = get_transient( $key );
        if ( $msg ) {
            delete_transient( $key );
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
    }

    /** Plain explanations for administrators and editors; no controls or secrets. */
    public static function render_help() {
        ?>
        <?php $tl = self::tier_labels(); $c = self::rank_cutoffs( self::settings() ); ?>
        <h3>Groups describe how older posts are read</h3>
        <ul>
            <li><strong><?php echo esc_html( $tl['retained'] ); ?></strong> — an older post with at least <?php echo esc_html( number_format_i18n( $c['occasional'] ) ); ?> recorded visit<?php echo 1 === $c['occasional'] ? '' : 's'; ?> in the period, or any search click. Graded by how often: read daily (about a visit a day), weekly, monthly, or occasionally. The bands are averages over the period, not a promise that someone visited every day.</li>
            <li><strong><?php echo esc_html( $tl['dormant'] ); ?></strong> — no visits and no search clicks in the period. Quiet is not the same as worthless: a reference piece can be the best page on its subject and still be quiet.</li>
            <li><strong><?php echo esc_html( $tl['candidate'] ); ?></strong> — the same, with less text than the saved word limit. A review queue, never a decision to delete; short match reports have value too.</li>
            <li><strong><?php echo esc_html( $tl['unknown'] ); ?></strong> — we hold back because the evidence is not good enough yet: the article’s relevant dates are unconfirmed, the period falls outside the data we hold, or there is no visitor data. Unknown does not mean unused.</li>
        </ul>
        <h3>Suggestions say what might help</h3>
        <ul>
            <li><strong>Keep</strong> — visits, search clicks or links from other sites give a reason to keep the article as it is.</li>
            <li><strong>Needs an update</strong> — people see it in search results but few click it. Look at the article, its title and its search description. A suggestion to look, not a finding that it is wrong.</li>
            <li><strong>Consider combining</strong> — it appears in search but gets no clicks. Check whether a newer article covers the same subject; a redirect is only right if that article truly replaces this one. The report neither finds nor applies a target.</li>
            <li><strong>Review whether it should appear in search</strong> — other pages on this site link here, but we found no visits or search activity. Review whether to keep the page available but out of search results.</li>
            <li><strong>Not enough data yet</strong> — nothing useful was recorded for this period, or the post is held for timing. Check the connections and the dates before drawing a conclusion.</li>
        </ul>
        <p>A suggestion never changes a post. Changing search visibility or redirecting readers is a separate, logged administrator action. “Older than” is the article’s age; the period is the dates we asked the traffic sources for; “Previous checks” shows how the picture has moved between checks.</p>
        <?php
    }

    /* ---- Schedule ------------------------------------------------------------------------------- */

    /** A weekly rebuild with the saved cutoffs, when switched on. Off by default. */
    public static function sync_weekly_schedule() {
        $on   = class_exists( 'AceSeoRetentionActions' ) && ! empty( AceSeoRetentionActions::options()['auto_build'] );
        $next = wp_next_scheduled( self::WEEKLY_HOOK );
        if ( $on && ! $next ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::WEEKLY_HOOK );
        } elseif ( ! $on && $next ) {
            wp_clear_scheduled_hook( self::WEEKLY_HOOK );
        }
    }

    public static function run_weekly() {
        if ( self::is_building() ) {
            // An unfinished build is resumed, never skipped: skipping it was how a stalled report
            // blocked every later weekly run.
            self::recover();
            return;
        }
        self::start();
    }

    /**
     * The decision. Order matters: anything with a reason to keep is kept before anything is judged
     * unused, and "no-signal" needs every signal to be absent.
     */
    public static function bucket( array $r, array $s ) {
        $ctr    = $r['impressions'] > 0 ? $r['clicks'] / $r['impressions'] : 0;
        $demand = $r['impressions'] >= (int) $s['demand_impressions'];

        if ( $demand && $ctr < (float) $s['refresh_max_ctr'] && $r['position'] > 0 && $r['position'] <= (float) $s['refresh_max_pos'] ) {
            return array( 'refresh', sprintf( '%s impressions at position %s but a %s%% CTR: the demand is there, the page is not earning it.', number_format_i18n( $r['impressions'] ), $r['position'], round( $ctr * 100, 1 ) ) );
        }
        if ( $r['clicks'] > 0 ) {
            return array( 'keep', sprintf( 'Still earning search clicks (%s in the window).', number_format_i18n( $r['clicks'] ) ) );
        }
        if ( ! empty( $r['backlinks'] ) ) {
            return array( 'keep', sprintf( 'Has %s external backlink(s); removing it would waste them.', number_format_i18n( $r['backlinks'] ) ) );
        }
        if ( ! empty( $r['views'] ) ) {
            return array( 'keep', sprintf( 'Still visited (%s page views in the window) even without search clicks.', number_format_i18n( $r['views'] ) ) );
        }
        if ( ! isset( $r['views'] ) ) {
            return array( 'no-signal', 'Visitor counts are unknown, not zero. Check traffic coverage before suggesting a negative change.' );
        }
        if ( $r['impressions'] > 0 ) {
            return array( 'consolidate', sprintf( 'Shown %s times but never clicked: review whether another article covers the same subject. No redirect target has been selected.', number_format_i18n( $r['impressions'] ) ) );
        }
        if ( $r['links_in'] > 0 ) {
            return array( 'noindex', sprintf( 'No search or visitor activity recorded, but %s internal link(s) point here. Review whether it should stay available but out of search.', number_format_i18n( $r['links_in'] ) ) );
        }
        $why = 'No clicks, no impressions, no internal links';
        $why .= null === $r['views'] ? ' (no page-view source configured)' : ', no page views';
        return array( 'no-signal', $why . ' in the window. Check data coverage and seasonal interest before deciding what to do.' );
    }

    /* ---- Queries -------------------------------------------------------------------------------- */

    private static function cutoff_date( array $settings ) {
        return gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $settings['older_than_years'] . ' years' ) );
    }

    private static function count_all_posts( $types ) {
        global $wpdb;
        $types = array_map( 'esc_sql', (array) $types );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('" . implode( "','", $types ) . "')" );
    }

    public static function count_candidates( array $settings ) {
        global $wpdb;
        $types = array_map( 'esc_sql', (array) $settings['post_types'] );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('" . implode( "','", $types ) . "') AND post_date_gmt < %s",
            self::cutoff_date( $settings )
        ) );
    }

    private static function candidate_ids( array $settings, $limit, $offset ) {
        global $wpdb;
        $types = array_map( 'esc_sql', (array) $settings['post_types'] );
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('" . implode( "','", $types ) . "') AND post_date_gmt < %s ORDER BY ID ASC LIMIT %d OFFSET %d",
            self::cutoff_date( $settings ),
            (int) $limit,
            (int) $offset
        ) ) );
    }

    /** path => post ID for every candidate, so a link can be attributed without a query each. */
    private static function candidate_lookup( array $settings ) {
        static $lookup = null;
        if ( null !== $lookup ) {
            return $lookup;
        }
        // Built once per run and kept for the rest of it: 30,000 permalinks cost a cron tick over a
        // minute and a half each time, and the links phase asks on every tick.
        $p        = self::progress();
        $key      = 'ace_seo_retention_lookup_' . md5( (string) ( $p['started'] ?? '' ) . wp_json_encode( $settings ) );
        $lookup   = get_transient( $key );
        if ( is_array( $lookup ) ) {
            return $lookup;
        }
        $lookup = array();
        $offset = 0;
        do {
            $ids = self::candidate_ids( $settings, 2000, $offset );
            // Permalinks with %category% need each post's terms: load the batch's in a few queries.
            _prime_post_caches( $ids, true, false );
            foreach ( $ids as $id ) {
                $lookup[ self::path_key( get_permalink( $id ) ) ] = $id;
            }
            $offset += 2000;
            if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }
        } while ( count( $ids ) === 2000 );
        set_transient( $key, $lookup, DAY_IN_SECONDS );
        return $lookup;
    }

    /**
     * Hosts whose links count as internal: the site's own, plus any a staging or migrated copy still
     * links to in its content (`ace_seo_retention_internal_hosts`, e.g. the production host on a staging
     * site) — otherwise every inbound link on such a copy would be attributed to nobody.
     */
    private static function internal_hosts() {
        static $hosts = null;
        if ( null === $hosts ) {
            $own   = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
            $extra = (array) apply_filters( 'ace_seo_retention_internal_hosts', array() );
            $hosts = array_values( array_unique( array_filter( array_map( function ( $h ) {
                return strtolower( preg_replace( '/^www\./', '', (string) $h ) );
            }, array_merge( array( $own ), $extra ) ) ) ) );
        }
        return $hosts;
    }

    /** A URL reduced to its path, without scheme, host, query or trailing slash. */
    public static function path_key( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url || 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) ) {
            return '';
        }
        if ( 0 === strpos( $url, '//' ) ) {
            $url = 'https:' . $url;
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            $host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
            if ( '' !== $host && ! in_array( $host, self::internal_hosts(), true ) ) {
                return ''; // another site
            }
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        } elseif ( 0 === strpos( $url, '/' ) ) {
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        } else {
            return '';
        }
        return '/' . trim( $path, '/' );
    }

    /* ---- Reading the report ---------------------------------------------------------------------- */

    public static function counts() {
        global $wpdb;
        $counts = array_fill_keys( self::BUCKETS, 0 );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META
        ) );
        foreach ( $rows as $row ) {
            $v = maybe_unserialize( $row->meta_value );
            if ( is_array( $v ) && isset( $counts[ $v['bucket'] ?? '' ] ) ) {
                $counts[ $v['bucket'] ]++;
            }
        }
        return $counts;
    }

    /**
     * Rows for one bucket (or all), newest first: id, title, url, published and the signals.
     * $limit 0 means everything — for the CSV and the CLI.
     */
    public static function rows( $bucket = '', $limit = 200, $offset = 0 ) {
        global $wpdb;
        $sql  = "SELECT p.ID, p.post_title, p.post_date, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_status = 'publish'";
        $args = array( self::META );
        if ( '' !== $bucket && in_array( $bucket, self::BUCKETS, true ) ) {
            $sql   .= ' AND pm.meta_value LIKE %s';
            $args[] = '%' . $wpdb->esc_like( 's:6:"bucket";s:' . strlen( $bucket ) . ':"' . $bucket . '"' ) . '%';
        }
        $sql .= ' ORDER BY p.post_date DESC';
        if ( $limit > 0 ) {
            $sql   .= ' LIMIT %d OFFSET %d';
            $args[] = (int) $limit;
            $args[] = (int) $offset;
        }
        $out = array();
        foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $row ) {
            $v = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $v ) ) {
                continue;
            }
            $out[] = array_merge( array(
                'id'        => (int) $row->ID,
                'title'     => $row->post_title,
                'url'       => get_permalink( $row->ID ),
                'published' => substr( $row->post_date, 0, 10 ),
            ), $v );
        }
        return $out;
    }

    public static function clear() {
        global $wpdb;
        foreach ( self::meta_keys() as $key ) {
            $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
        }
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
            wp_cache_flush_group( 'post_meta' );
        }
        delete_option( self::PROGRESS_OPTION );
        delete_option( self::SIGNALS_OPTION );
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::WATCH_HOOK );
        static::lock_delete( '' );
    }

    public static function csv( $bucket = '' ) {
        $cols = array( 'id', 'tier', 'rank', 'bucket', 'title', 'url', 'published', 'clicks', 'impressions', 'position', 'links_in', 'views', 'words', 'backlinks', 'reason' );
        $fh   = fopen( 'php://temp', 'w+' );
        fputcsv( $fh, $cols );
        foreach ( self::rows( $bucket, 0 ) as $row ) {
            $line = array();
            foreach ( $cols as $c ) {
                $line[] = isset( $row[ $c ] ) && null !== $row[ $c ] ? $row[ $c ] : '';
            }
            fputcsv( $fh, $line );
        }
        rewind( $fh );
        $csv = stream_get_contents( $fh );
        fclose( $fh );
        return $csv;
    }

    /* ---- Admin ---------------------------------------------------------------------------------- */

    public static function add_menu() {
        add_submenu_page( 'ace-seo', 'Retention', 'Retention', 'manage_options', 'ace-seo-retention', array( __CLASS__, 'render' ) );
    }

    public static function handle_build() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_build' ) ) {
            wp_die( 'Not allowed.' );
        }
        $overrides = array();
        if ( isset( $_POST['older_than_years'] ) ) {
            $overrides['older_than_years'] = max( 1, min( 20, (int) $_POST['older_than_years'] ) );
        }
        if ( isset( $_POST['days'] ) ) {
            $overrides['days'] = max( 7, min( 480, (int) $_POST['days'] ) );
        }
        self::start( $overrides );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' ) );
        exit;
    }

    public static function handle_resume() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_resume' ) ) {
            wp_die( 'Not allowed.' );
        }
        $msg = self::resume() ? 'The build has been queued to continue from where it stopped.' : 'The build did not need resuming.';
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' ) );
        exit;
    }

    /**
     * One save for the whole Retention tab. Each section's own saver still runs (and its own action still
     * works), so nothing about how settings are stored changes; only the number of Save buttons does.
     */
    public static function handle_settings_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_settings_save' ) ) {
            wp_die( 'Not allowed.' );
        }
        $in = wp_unslash( $_POST );
        AceSeoRetentionActions::save_report_settings( $in );
        $in['settings_section'] = 'notice-lifetimes';
        AceSeoRetentionActions::save_options( $in );
        AceSeoRetentionActions::save_front_settings( $in );
        self::sync_weekly_schedule();
        if ( class_exists( 'AceSeoViewTracker' ) ) {
            AceSeoViewTracker::maybe_install();
        }
        $parts = array( 'All retention settings saved.' );
        if ( class_exists( 'AceSeoSheets' ) && array_key_exists( 'sheet', $in ) ) {
            $parts[] = 'Google Sheets: ' . AceSeoSheets::save_settings( $in );
        }
        if ( class_exists( 'AceSeoSheetsSchedule' ) && array_key_exists( 'frequency', $in ) ) {
            $schedule = AceSeoSheetsSchedule::save_settings( $in );
            if ( is_wp_error( $schedule ) ) {
                $parts[] = 'Report schedule not saved: ' . $schedule->get_error_message() . ' Everything else was saved.';
            }
        }
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), implode( ' ', $parts ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention' ) );
        exit;
    }

    public static function handle_report_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_report_settings' ) ) {
            wp_die( 'Not allowed.' );
        }
        AceSeoRetentionActions::save_report_settings( wp_unslash( $_POST ) );
        self::sync_weekly_schedule();
        if ( class_exists( 'AceSeoViewTracker' ) ) {
            AceSeoViewTracker::maybe_install();
        }
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), 'Report settings saved.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-report' ) );
        exit;
    }

    public static function handle_front_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_front' ) ) {
            wp_die( 'Not allowed.' );
        }
        AceSeoRetentionActions::save_front_settings( wp_unslash( $_POST ) );
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), 'Front-end settings for retained posts saved. Cached pages pick them up as they expire or are purged.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-readers' ) );
        exit;
    }

    /**
     * Pre-filtered post list links, to send to someone who wants the list rather than a spreadsheet.
     * They need an account that can see the post list; the retention columns are shown on these links.
     */
    public static function shareable_links() {
        $base = admin_url( 'edit.php' );
        $o    = class_exists( 'AceSeoRetentionActions' ) ? AceSeoRetentionActions::options() : array();
        $year = (int) gmdate( 'Y' ) - 2;
        $links = array(
            'retained'  => array( __( 'Retained: old posts still being read, most read first', 'ace-crawl-enhancer' ), add_query_arg( array( 'post_type' => 'post', 'ace_ret' => 'retained', 'orderby' => 'ace_seo_ret_views', 'order' => 'desc' ), $base ) ),
            'candidate' => array( __( 'Candidates for review: older posts with no recorded visits and less text', 'ace-crawl-enhancer' ), add_query_arg( array( 'post_type' => 'post', 'ace_ret' => 'candidate' ), $base ) ),
            'before'    => array( sprintf( __( 'Everything published before %d', 'ace-crawl-enhancer' ), $year ), add_query_arg( array( 'post_type' => 'post', 'post_status' => 'publish', 'ace_before' => $year . '-01-01' ), $base ) ),
        );
        return apply_filters( 'ace_seo_retention_shareable_links', $links, $o );
    }

    public static function handle_clear() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_clear' ) ) {
            wp_die( 'Not allowed.' );
        }
        self::clear();
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' ) );
        exit;
    }

    /** Bulk action: the ticked rows, or every post in a bucket. */
    public static function handle_apply() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_apply' ) ) {
            wp_die( 'Not allowed.' );
        }
        $action = sanitize_key( $_POST['retention_action'] ?? '' );
        $bucket = sanitize_key( $_POST['bucket'] ?? '' );
        $scope  = sanitize_key( $_POST['scope'] ?? 'ticked' );
        $args   = array(
            'date' => sanitize_text_field( wp_unslash( $_POST['action_date'] ?? '' ) ),
            'url'  => esc_url_raw( wp_unslash( $_POST['action_url'] ?? '' ) ),
        );
        if ( 'bucket' === $scope && in_array( $bucket, self::BUCKETS, true ) ) {
            $ids = wp_list_pluck( self::rows( $bucket, 0 ), 'id' );
        } else {
            $ids = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
        }
        $result = AceSeoRetentionActions::apply( $ids, $action, $args, 'admin' );
        $msg    = '' !== $result['error'] ? $result['error'] : sprintf( '%s: applied to %s post(s), %s skipped.', AceSeoRetentionActions::ACTIONS[ $action ] ?? $action, number_format_i18n( $result['applied'] ), number_format_i18n( $result['skipped'] ) );
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' . ( $bucket ? '&bucket=' . $bucket : '' ) ) );
        exit;
    }

    public static function handle_options() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        AceSeoRetentionActions::save_options( wp_unslash( $_POST ) );
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), 'Notice and search expiry rules saved. Cached pages pick them up as they expire or are purged.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-notice' ) );
        exit;
    }

    /** The redirect map's add form: a post (ID or its URL) to a target URL, or to 410. */
    public static function handle_redirect() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_redirect' ) ) {
            wp_die( 'Not allowed.' );
        }
        $from = trim( (string) wp_unslash( $_POST['from'] ?? '' ) );
        $id   = ctype_digit( $from ) ? (int) $from : (int) url_to_postid( $from );
        $to   = trim( (string) wp_unslash( $_POST['to'] ?? '' ) );
        if ( ! $id ) {
            $msg = 'That source is not a post on this site (give a post ID or its URL).';
        } elseif ( 'gone' === strtolower( $to ) ) {
            $r   = AceSeoRetentionActions::apply( array( $id ), 'gone', array(), 'admin' );
            $msg = $r['applied'] ? 'Now answering 410 for post ' . $id . '.' : 'Not applied (' . ( $r['error'] ?: 'skipped' ) . ').';
        } else {
            $r   = AceSeoRetentionActions::apply( array( $id ), 'redirect', array( 'url' => $to ), 'admin' );
            $msg = $r['applied'] ? 'Redirect set for post ' . $id . '.' : 'Not applied (' . ( $r['error'] ?: 'a post cannot redirect to itself' ) . ').';
        }
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention#redirects' ) );
        exit;
    }

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_export' ) ) {
            wp_die( 'Not allowed.' );
        }
        $bucket = isset( $_GET['bucket'] ) ? sanitize_key( $_GET['bucket'] ) : '';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="retention-report' . ( $bucket ? '-' . $bucket : '' ) . '-' . gmdate( 'Ymd' ) . '.csv"' );
        echo self::csv( $bucket ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV
        exit;
    }

    /** Why held posts are held, from the saved reasons: one primary reason per post so the counts add up. */
    public static function hold_reasons() {
        global $wpdb;
        $out = array( 'timing' => 0, 'coverage' => 0, 'ambiguous' => 0, 'missing' => 0 );
        $base = "SELECT COUNT(*) FROM {$wpdb->postmeta} t JOIN {$wpdb->postmeta} r ON r.post_id = t.post_id AND r.meta_key = %s WHERE t.meta_key = %s AND t.meta_value = 'unknown'";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $base, self::META, self::META_TIER ) );
        if ( ! $total ) {
            return $out;
        }
        $out['timing']    = (int) $wpdb->get_var( $wpdb->prepare( $base . ' AND r.meta_value LIKE %s', self::META, self::META_TIER, '%timing has not been confirmed%' ) );
        $out['coverage']  = (int) $wpdb->get_var( $wpdb->prepare( $base . ' AND r.meta_value LIKE %s AND r.meta_value NOT LIKE %s', self::META, self::META_TIER, '%only has data from%', '%timing has not been confirmed%' ) );
        $out['ambiguous'] = (int) $wpdb->get_var( $wpdb->prepare( $base . ' AND r.meta_value LIKE %s', self::META, self::META_TIER, '%Several linked events%' ) );
        $out['missing']   = max( 0, $total - $out['timing'] - $out['coverage'] - $out['ambiguous'] );
        return $out;
    }

    /** The plain-English summary at the top of the dashboard: what was looked at, what it found, what to do next. */
    public static function render_summary( array $p, array $settings, $built ) {
        $tiers  = self::tier_counts();
        $total  = array_sum( $tiers );
        if ( ! $total ) {
            echo '<div class="ace-retention-summary"><p><strong>No assessment yet.</strong> Use “Check older posts” to look at how older articles are read. Nothing is changed by checking.</p></div>';
            return;
        }
        $ranks   = self::rank_counts();
        $buckets = self::counts();
        $holds   = self::hold_reasons();
        $labels  = self::tier_labels( $settings );
        $rlabels = self::rank_labels( $settings );
        $days    = (int) ( $settings['days'] ?? 90 );
        $started = (int) ( $p['started'] ?? 0 );
        $from    = $started ? wp_date( 'j F Y', $started - $days * DAY_IN_SECONDS ) : '';
        $to      = $started ? wp_date( 'j F Y', $started - DAY_IN_SECONDS ) : '';
        $notes   = implode( ' ', (array) ( $p['notes'] ?? array() ) );
        $sources = array();
        if ( false === stripos( $notes, 'Google Analytics:' ) && false === stripos( $notes, 'Site Kit is not active' ) ) { $sources[] = 'Google Analytics'; }
        if ( false === stripos( $notes, 'Search Console is not connected' ) ) { $sources[] = 'Search Console'; }
        if ( class_exists( 'AceSeoViewTracker' ) && AceSeoViewTracker::enabled() ) { $sources[] = 'the site’s own visitor count'; }
        $sources  = $sources ? implode( ' and ', $sources ) : 'links between articles only';
        $quiet    = (int) $tiers['dormant'] + (int) $tiers['candidate'];
        $partial  = self::is_building();
        $pct      = $total ? round( 100 * (int) $tiers['retained'] / $total ) : 0;
        $share    = $p['share'] ?? null;
        $posts    = admin_url( 'edit.php?post_type=post' );
        ?>
        <div class="ace-retention-summary">
            <?php if ( $partial ) : ?><p><strong>Partial results:</strong> a check is still running, so these numbers will change until it finishes.</p><?php endif; ?>
            <p>We checked <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> published posts older than <?php echo esc_html( (int) $settings['older_than_years'] ); ?> years<?php echo $from ? ', looking for visits and search activity from ' . esc_html( $from ) . ' to ' . esc_html( $to ) : ''; ?>, using <?php echo esc_html( $sources ); ?>.</p>
            <div class="ace-retention-cards">
                <div class="ace-retention-card">
                    <?php echo esc_html( $labels['retained'] ); ?>
                    <span class="ace-retention-card-number"><?php echo esc_html( number_format_i18n( (int) $tiers['retained'] ) ); ?></span>
                    <?php echo esc_html( $pct ); ?>% of the posts we checked. How often:
                    <ul>
                        <?php foreach ( $rlabels as $r => $rl ) : ?><li><a href="<?php echo esc_url( add_query_arg( 'ace_ret', $r, $posts ) ); ?>"><?php echo esc_html( preg_replace( '/ \(.*$/', '', $rl ) ); ?></a>: <?php echo esc_html( number_format_i18n( (int) $ranks[ $r ] ) ); ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <div class="ace-retention-card">
                    Worth checking for an update
                    <span class="ace-retention-card-number"><?php echo esc_html( number_format_i18n( (int) ( $buckets['refresh'] ?? 0 ) ) ); ?></span>
                    People see them in search but few click. A suggestion to look, not a finding that anything is wrong.
                    <ul><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention&bucket=refresh' ) ); ?>">Review these</a></li></ul>
                </div>
                <div class="ace-retention-card">
                    <?php echo esc_html( $labels['unknown'] ); ?>
                    <span class="ace-retention-card-number"><?php echo esc_html( number_format_i18n( (int) $tiers['unknown'] ) ); ?></span>
                    <ul>
                        <?php if ( $holds['timing'] ) : ?><li><?php echo esc_html( number_format_i18n( $holds['timing'] ) ); ?> need their relevant dates confirmed</li><?php endif; ?>
                        <?php if ( $holds['coverage'] ) : ?><li><?php echo esc_html( number_format_i18n( $holds['coverage'] ) ); ?> fall outside the dates our data covers</li><?php endif; ?>
                        <?php if ( $holds['ambiguous'] ) : ?><li><?php echo esc_html( number_format_i18n( $holds['ambiguous'] ) ); ?> are linked to more than one event</li><?php endif; ?>
                        <?php if ( $holds['missing'] ) : ?><li><?php echo esc_html( number_format_i18n( $holds['missing'] ) ); ?> have no visitor data</li><?php endif; ?>
                        <li><a href="<?php echo esc_url( add_query_arg( 'ace_ret', 'unknown', $posts ) ); ?>">See these posts</a></li>
                    </ul>
                </div>
                <div class="ace-retention-card">
                    <?php echo esc_html( $labels['dormant'] ); ?>
                    <span class="ace-retention-card-number"><?php echo esc_html( number_format_i18n( $quiet ) ); ?></span>
                    No visits and no search clicks in the period<?php echo (int) $tiers['candidate'] ? ', including ' . esc_html( number_format_i18n( (int) $tiers['candidate'] ) ) . ' short posts' : ''; ?>. Quiet is not the same as worthless.
                    <ul><li><a href="<?php echo esc_url( add_query_arg( 'ace_ret', 'dormant', $posts ) ); ?>">See these posts</a></li></ul>
                </div>
            </div>
            <?php if ( is_array( $share ) && ! empty( $share['total'] ) ) : ?>
                <p>In the last <?php echo esc_html( (int) $share['days'] ); ?> days, <strong><?php echo esc_html( round( 100 * $share['old'] / $share['total'], 1 ) ); ?>%</strong> of all page views went to these older posts (<?php echo esc_html( number_format_i18n( (int) $share['old'] ) ); ?> of <?php echo esc_html( number_format_i18n( (int) $share['total'] ) ); ?>, <?php echo esc_html( $share['source'] ); ?>).</p>
            <?php endif; ?>
            <div class="ace-retention-next-steps">
                <strong>What to do next</strong>
                <ol style="margin:.5em 0 0 1.5em">
                    <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention&bucket=refresh' ) ); ?>">Start with the posts worth updating</a>: they already have an audience in search.</li>
                    <?php if ( $holds['timing'] ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-report' ) ); ?>">Say when articles in a category matter</a> (evergreen, event-bound or seasonal) so the held posts can be judged, or set it on individual posts.</li><?php endif; ?>
                    <li>Groups and suggestions overlap: a post can be read weekly and still be worth an update. Decide with the client; this report only describes.</li>
                    <?php if ( class_exists( 'AceSeoSheets' ) && AceSeoSheets::configured() ) : ?><li><a href="<?php echo esc_url( AceSeoSheets::sheet_url() ); ?>" target="_blank" rel="noopener noreferrer">Open the Google Sheet</a> for the full list with readership bands, timing and reasons.</li><?php endif; ?>
                </ol>
            </div>
        </div>
        <?php
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! empty( $_GET['evidence_preview'] ) ) {
            require_once __DIR__ . '/class-ace-seo-retention-evidence-view.php';
            echo '<div class="wrap"><h1>Retention evidence preview</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=ace-seo-retention' ) ) . '">Back to the saved report</a></p>';
            Ace_SEO_Retention_Evidence_View::render();
            echo '</div>';
            return;
        }
        $p        = self::progress();
        $settings = ! empty( $p['settings'] ) ? $p['settings'] : self::settings();
        $bucket   = isset( $_GET['bucket'] ) ? sanitize_key( $_GET['bucket'] ) : '';
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per      = 100;
        $counts   = self::counts();
        $built    = array_sum( $counts ) > 0;
        $labels   = self::recommendation_labels();
        if ( ! in_array( $bucket, self::BUCKETS, true ) ) {
            $bucket = '';
        }
        ?>
        <div class="wrap ace-retention-dashboard">
            <h1>Older posts: readership and review</h1>
            <?php self::render_message(); ?>
            <p class="ace-retention-reassure">Reading this report changes nothing. Nothing here deletes, redirects or hides a post; the only changes happen through the clearly labelled administrator actions further down, and each one is logged.</p>

            <?php $state = self::worker_state(); ?>
            <?php if ( self::is_building() ) : ?>
                <div class="notice <?php echo 'interrupted' === $state ? 'notice-warning' : 'notice-info'; ?>"><p>
                    Building: <strong><?php echo esc_html( $p['phase'] ); ?></strong>
                    <?php if ( ! empty( $p['total'] ) ) : ?>— <?php echo esc_html( number_format_i18n( min( (int) $p['offset'], (int) $p['total'] ) ) ); ?> of <?php echo esc_html( number_format_i18n( (int) $p['total'] ) ); ?><?php endif; ?>.
                    <?php if ( 'running' === $state ) : ?>
                        A worker is on it now<?php echo ! empty( $p['tick_at'] ) ? ', last step ' . esc_html( human_time_diff( (int) $p['tick_at'] ) ) . ' ago' : ''; ?>.
                    <?php elseif ( 'queued' === $state ) : ?>
                        The next step is queued on cron<?php echo ! empty( $p['tick_at'] ) ? '; last step ' . esc_html( human_time_diff( (int) $p['tick_at'] ) ) . ' ago' : ''; ?>. Reload to follow it.
                    <?php else : ?>
                        <strong>Interrupted:</strong> nothing is queued and no worker holds it<?php echo ! empty( $p['tick_at'] ) ? ' (last step ' . esc_html( human_time_diff( (int) $p['tick_at'] ) ) . ' ago)' : ''; ?>. It is resumed automatically within the hour, or now:
                    <?php endif; ?>
                    <?php if ( ! empty( $p['last_error'] ) ) : ?><br>Last error: <?php echo esc_html( $p['last_error'] ); ?><?php endif; ?>
                </p>
                <?php if ( 'interrupted' === $state ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 .6em">
                    <?php wp_nonce_field( 'ace_seo_retention_resume' ); ?>
                    <input type="hidden" name="action" value="ace_seo_retention_resume">
                    <button class="button button-primary">Resume the build</button>
                </form>
                <?php endif; ?>
                </div>
            <?php elseif ( 'error' === $state ) : ?>
                <div class="notice notice-error"><p>The last build stopped with an error<?php echo ! empty( $p['last_error'] ) ? ': ' . esc_html( $p['last_error'] ) : ''; ?>. The report shows the rows scored before it stopped; rebuild when the cause is fixed.</p></div>
            <?php elseif ( ! empty( $p['finished'] ) ) : ?>
                <div class="notice notice-success"><p>Built <?php echo esc_html( human_time_diff( (int) $p['finished'] ) ); ?> ago: posts older than <?php echo esc_html( (int) $settings['older_than_years'] ); ?> years, a <?php echo esc_html( (int) $settings['days'] ); ?>-day search window.</p></div>
            <?php endif; ?>
            <?php foreach ( (array) ( $p['notes'] ?? array() ) as $note ) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html( $note ); ?></p></div>
            <?php endforeach; ?>

            <?php self::render_summary( $p, $settings, $built ); ?>

            <div class="ace-retention-controls" style="display:flex;gap:.5em;flex-wrap:wrap;align-items:center;margin:0 0 1.5em">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                    <?php wp_nonce_field( 'ace_seo_retention_build' ); ?>
                    <input type="hidden" name="action" value="ace_seo_retention_build">
                    <button class="button" <?php disabled( self::is_building() ); ?>><?php echo $built ? 'Check older posts again' : 'Check older posts'; ?></button>
                </form>
                <?php if ( $built ) : ?>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ace_seo_retention_export' . ( $bucket ? '&bucket=' . $bucket : '' ) ), 'ace_seo_retention_export' ) ); ?>">Download as CSV<?php echo $bucket ? ' (' . esc_html( $labels[ $bucket ] ) . ')' : ''; ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-settings#retention' ) ); ?>">Settings</a>
                <a href="#retention-help">What the words mean</a>
            </div>
            <p class="description">Checking again uses the saved settings and takes a while on a large site; it runs in the background and this page shows its progress. It updates groups and suggestions, never the changes already applied.</p>

            <details class="ace-retention-detail"><summary>Previous checks</summary>
            <?php self::render_history( $labels ); ?>
            </details>
            <details class="ace-retention-detail"><summary>Advanced: timing preview and other post lists</summary>
                <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention&evidence_preview=1' ) ); ?>">Preview event timing and suggestion overlaps</a> — read-only; the saved report remains unchanged.</p>
                <ul style="list-style:disc;margin-left:2em">
                    <?php foreach ( self::shareable_links() as $link ) : ?>
                        <li><a href="<?php echo esc_url( $link[1] ); ?>"><?php echo esc_html( $link[0] ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <?php if ( $built ) : ?>
                <h2>Suggestions, post by post</h2>
                <p>Each row shows what we observed and what might help. “Applied” shows actual changes; a suggestion does not change anything.</p>
                <ul class="subsubsub" style="margin-bottom:1em">
                    <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention' ) ); ?>" <?php echo '' === $bucket ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $counts ) ) ); ?>)</span></a> |</li>
                    <?php foreach ( self::BUCKETS as $i => $b ) : ?>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention&bucket=' . $b ) ); ?>" <?php echo $bucket === $b ? 'class="current"' : ''; ?>><?php echo esc_html( $labels[ $b ] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $b ] ) ); ?>)</span></a><?php echo $i < count( self::BUCKETS ) - 1 ? ' |' : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
                <div style="clear:both"></div>

                <?php $rows = self::rows( $bucket, $per, ( $paged - 1 ) * $per ); ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ace-seo-retention-apply" onsubmit="return this.scope.value !== 'bucket' || confirm('Apply to every post in this bucket?');">
                <?php wp_nonce_field( 'ace_seo_retention_apply' ); ?>
                <input type="hidden" name="action" value="ace_seo_retention_apply">
                <input type="hidden" name="bucket" value="<?php echo esc_attr( $bucket ); ?>">
                <p><strong>Applying an action below can change search visibility or redirect visitors. It does not delete the saved post.</strong></p>
                <details class="ace-retention-detail"><summary>Administrator actions for selected posts</summary>
                <div class="tablenav top" style="display:flex;gap:.5em;align-items:center;flex-wrap:wrap;height:auto;padding:.5em 0">
                    <select name="retention_action" aria-label="Action to apply" required>
                        <option value="">Bulk action…</option>
                        <?php foreach ( AceSeoRetentionActions::ACTIONS as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="action_date" title="For unavailable_after">
                    <input type="url" name="action_url" placeholder="Redirect target URL" style="width:22em">
                    <select name="scope" aria-label="Posts to change">
                        <option value="ticked">Ticked rows</option>
                        <?php if ( '' !== $bucket ) : ?><option value="bucket">Every post in “<?php echo esc_html( $labels[ $bucket ] ); ?>” (<?php echo esc_html( number_format_i18n( $counts[ $bucket ] ) ); ?>)</option><?php endif; ?>
                    </select>
                    <button class="button">Apply</button>
                    <span class="description">Everything here is reversible and logged; nothing deletes a post.</span>
                </div>
                </details>
                <div class="ace-retention-table"><table class="widefat striped">
                    <thead><tr><td class="check-column"><input type="checkbox" aria-label="Select all posts on this page" onclick="document.querySelectorAll('#ace-seo-retention-apply input[name=\'post_ids[]\']').forEach(c=>c.checked=this.checked)"></td><th>Post</th><th>Published</th><th>Recommendation</th><th>Clicks</th><th>Search appearances</th><th>Average position</th><th>Links in</th><th>Views</th><th>Why</th><th>Applied</th></tr></thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="11">No posts match this recommendation.</td></tr>
                    <?php endif; ?>
                    <?php foreach ( $rows as $r ) : $st = AceSeoRetentionActions::state( $r['id'] ); $flags = array_filter( array( $st['noindex'] ? 'noindex' : '', $st['unavailable'] ? 'unavailable after ' . $st['unavailable'] : '', $st['redirect'] ? '301 → ' . wp_make_link_relative( $st['redirect'] ) : '', $st['news_excl'] ? 'no news sitemap' : '', $st['notice'] ? 'notice: ' . $st['notice'] : '' ) ); ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $r['id'] ); ?>"></th>
                            <td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['title'] ?: '(no title)' ); ?></a><br><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#666"><?php echo esc_html( wp_make_link_relative( $r['url'] ) ); ?></a></td>
                            <td><?php echo esc_html( $r['published'] ); ?></td>
                            <td><strong><?php echo esc_html( $labels[ $r['bucket'] ] ?? $r['bucket'] ); ?></strong></td>
                            <td><?php echo esc_html( number_format_i18n( $r['clicks'] ) ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $r['impressions'] ) ); ?></td>
                            <td><?php echo esc_html( $r['position'] ?: '–' ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $r['links_in'] ) ); ?></td>
                            <td><?php echo null === $r['views'] ? '–' : esc_html( number_format_i18n( $r['views'] ) ); ?></td>
                            <td><?php echo esc_html( $r['reason'] ); ?></td>
                            <td style="font-size:11px"><?php echo esc_html( implode( '; ', $flags ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                </form>
                <?php
                $total = '' === $bucket ? array_sum( $counts ) : $counts[ $bucket ];
                $pages = (int) ceil( $total / $per );
                if ( $pages > 1 ) {
                    echo '<p class="tablenav-pages" style="margin-top:1em">' . paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $pages,
                    ) ) . '</p>';
                }
                ?>

                <details class="ace-retention-detail"><summary>Developer tools: clear report scores</summary>
                <p>Clears the report scores. Posts and actions already applied stay unchanged.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear the report? The posts are untouched; only the scores go.');">
                    <?php wp_nonce_field( 'ace_seo_retention_clear' ); ?>
                    <input type="hidden" name="action" value="ace_seo_retention_clear">
                    <button class="button-link-delete">Clear the report</button>
                </form>
                </details>
            <?php endif; ?>

            <h2 id="redirects" style="margin-top:2em">Redirect map</h2>
            <p>Posts that answer with a 301 to a stronger page, or with 410 Gone. All of these are still in the database: clearing the entry (bulk action above, or the post's Advanced SEO tab) brings the page straight back.</p>
            <details class="ace-retention-detail"><summary>Administrator tools: redirects and unavailable pages</summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.5em;flex-wrap:wrap;align-items:center;margin-bottom:1em">
                <?php wp_nonce_field( 'ace_seo_retention_redirect' ); ?>
                <input type="hidden" name="action" value="ace_seo_retention_redirect">
                <input type="text" name="from" aria-label="Post ID or URL to change" placeholder="Post ID or its URL" style="width:22em" required>
                <span>→</span>
                <input type="text" name="to" aria-label="Redirect target URL or gone" placeholder="Target URL, or the word gone" style="width:22em" required>
                <button class="button">Add</button>
            </form>
            <?php $map = AceSeoRetentionActions::redirects( 500 ); if ( $map ) : ?>
                <table class="widefat striped" style="max-width:1100px">
                    <thead><tr><th>From</th><th>To</th><th>Post</th></tr></thead>
                    <tbody>
                    <?php foreach ( $map as $m ) : ?>
                        <tr><td><a href="<?php echo esc_url( $m['from'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_make_link_relative( $m['from'] ) ); ?></a></td><td><?php echo $m['gone'] ? '<strong>410 Gone</strong>' : '301 → ' . esc_html( $m['to'] ); ?></td><td><a href="<?php echo esc_url( get_edit_post_link( $m['id'] ) ); ?>"><?php echo esc_html( $m['title'] ?: '#' . $m['id'] ); ?></a> <span style="color:#666">(<?php echo esc_html( $m['type'] ); ?> <?php echo (int) $m['id']; ?>)</span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><em>No redirects yet.</em></p>
            <?php endif; ?>
            </details>

            <?php $log = AceSeoRetentionActions::recent_log( 30 ); if ( $log ) : ?>
                <h2 style="margin-top:2em">Recent actions</h2>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>When</th><th>Post</th><th>Action</th><th>Value</th><th>By</th></tr></thead>
                    <tbody>
                    <?php foreach ( $log as $e ) : ?>
                        <tr><td><?php echo esc_html( human_time_diff( (int) $e['time'] ) ); ?> ago</td><td><a href="<?php echo esc_url( get_edit_post_link( (int) $e['post'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $e['post'] ) ?: '#' . (int) $e['post'] ); ?></a></td><td><?php echo esc_html( AceSeoRetentionActions::ACTIONS[ $e['action'] ] ?? $e['action'] ); ?></td><td><?php echo esc_html( $e['value'] ); ?></td><td><?php echo esc_html( $e['by'] ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <section id="retention-help">
                <h2>Understand the report</h2>
                <?php self::render_help(); ?>
                <details class="ace-retention-detail"><summary>Developer details: scoring rules and data sources</summary>
                    <p>The first matching rule wins. “Needs an update” requires at least <?php echo esc_html( number_format_i18n( (int) $settings['demand_impressions'] ) ); ?> search appearances, an average position within <?php echo esc_html( (int) $settings['refresh_max_pos'] ); ?> and a click-through rate below <?php echo esc_html( round( $settings['refresh_max_ctr'] * 100, 1 ) ); ?>%. Remaining rules check clicks, backlinks, views, appearances and internal links in that order.</p>
                    <p>Thresholds use <code>ace_seo_retention_settings</code>; rows use <code>ace_seo_retention_row</code>; additional view sources use <code>ace_seo_retention_pageviews</code>. CLI: <code>wp ace-crawl retention build</code> and <code>wp ace-crawl retention report</code>.</p>
                    <p>Old rows keep the explanation recorded when they were built. Rebuild to apply updated wording or thresholds. A missing traffic source is not evidence of zero traffic.</p>
                </details>
            </section>
        </div>
        <?php
    }

    /* ---- WP-CLI --------------------------------------------------------------------------------- */

    public static function register_cli() {
        WP_CLI::add_command( 'ace-crawl retention build', array( __CLASS__, 'cli_build' ) );
        WP_CLI::add_command( 'ace-crawl retention report', array( __CLASS__, 'cli_report' ) );
        WP_CLI::add_command( 'ace-crawl retention clear', array( __CLASS__, 'cli_clear' ) );
        WP_CLI::add_command( 'ace-crawl retention apply', array( __CLASS__, 'cli_apply' ) );
        WP_CLI::add_command( 'ace-crawl retention redirects', array( __CLASS__, 'cli_redirects' ) );
        WP_CLI::add_command( 'ace-crawl retention status', array( __CLASS__, 'cli_status' ) );
        WP_CLI::add_command( 'ace-crawl retention resume', array( __CLASS__, 'cli_resume' ) );
        WP_CLI::add_command( 'ace-crawl retention suggestions', array( __CLASS__, 'cli_suggestions' ) );
    }

    /** Timing rules the site's own data suggests, per category or tag; --recompute works them out afresh. */
    public static function cli_suggestions( $args, $assoc ) {
        if ( ! class_exists( 'Ace_SEO_Timing_Suggestions' ) ) {
            WP_CLI::error( 'Suggestions are not available.' );
        }
        $s = ! empty( $assoc['recompute'] ) ? Ace_SEO_Timing_Suggestions::recompute() : Ace_SEO_Timing_Suggestions::current();
        if ( ! $s ) {
            WP_CLI::log( 'No category or tag has a clear enough shape yet.' );
            return;
        }
        $rows = array();
        foreach ( $s as $key => $x ) {
            $rows[] = array( 'term' => $key, 'posts' => $x['posts'], 'rule' => '' !== $x['rule'] ? $x['rule'] : '(no clear shape)', 'confidence' => $x['confidence'] ? round( 100 * $x['confidence'] ) . '%' : '-', 'why' => $x['why'] );
        }
        WP_CLI\Utils\format_items( 'table', $rows, array( 'term', 'posts', 'rule', 'confidence', 'why' ) );
    }

    /** Where the background build is: idle, queued, running, interrupted, error or done, with the saved offset. */
    public static function cli_status() {
        $p     = self::progress();
        $state = self::worker_state();
        $lease = self::lease_active();
        WP_CLI::log( sprintf( 'state: %s', $state ) );
        if ( ! empty( $p['phase'] ) ) {
            WP_CLI::log( sprintf( 'phase: %s %s/%s, started %s', $p['phase'], number_format_i18n( (int) ( $p['offset'] ?? 0 ) ), number_format_i18n( (int) ( $p['total'] ?? 0 ) ), gmdate( 'Y-m-d H:i', (int) ( $p['started'] ?? 0 ) ) ) );
        }
        WP_CLI::log( sprintf( 'last step: %s', ! empty( $p['tick_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $p['tick_at'] ) . ' by ' . (string) ( $p['worker'] ?? '?' ) : 'none recorded' ) );
        WP_CLI::log( sprintf( 'next tick: %s', ( $next = wp_next_scheduled( self::CRON_HOOK ) ) ? gmdate( 'Y-m-d H:i:s', $next ) : 'none' ) );
        WP_CLI::log( sprintf( 'watchdog: %s', ( $next = wp_next_scheduled( self::WATCH_HOOK ) ) ? gmdate( 'Y-m-d H:i:s', $next ) : 'none' ) );
        WP_CLI::log( sprintf( 'lease: %s', $lease ? $lease['owner'] . ' until ' . gmdate( 'H:i:s', $lease['until'] ) : 'none' ) );
        WP_CLI::log( sprintf( 'failures: %d%s', (int) ( $p['failures'] ?? 0 ), ! empty( $p['last_error'] ) ? ' (last: ' . $p['last_error'] . ')' : '' ) );
        foreach ( (array) ( $p['notes'] ?? array() ) as $note ) {
            WP_CLI::log( '  note: ' . $note );
        }
    }

    /** Queue the next step of an interrupted build; nothing already scored is redone. */
    public static function cli_resume() {
        if ( self::resume() ) {
            WP_CLI::success( 'Queued: the build continues from its saved offset on the next cron run.' );
        } else {
            WP_CLI::log( 'Nothing to resume: state is ' . self::worker_state() . '.' );
        }
    }

    /**
     * Build the retention report.
     *
     * ## OPTIONS
     *
     * [--older-than=<years>]
     * : Posts published before this many years ago. Default 3.
     *
     * [--days=<days>]
     * : Search Console window. Default 90.
     *
     * [--post-type=<types>]
     * : Comma-separated post types. Default post.
     */
    public static function cli_build( $args, $assoc ) {
        $overrides = array();
        if ( isset( $assoc['older-than'] ) ) {
            $overrides['older_than_years'] = max( 1, (int) $assoc['older-than'] );
        }
        if ( isset( $assoc['days'] ) ) {
            $overrides['days'] = max( 7, (int) $assoc['days'] );
        }
        if ( ! empty( $assoc['post-type'] ) ) {
            $overrides['post_types'] = array_filter( array_map( 'sanitize_key', explode( ',', $assoc['post-type'] ) ) );
        }
        self::start( $overrides );
        $settings = self::progress()['settings'];
        WP_CLI::log( sprintf( 'Scoring %s posts older than %d years over a %d-day search window…', number_format_i18n( self::count_candidates( $settings ) ), $settings['older_than_years'], $settings['days'] ) );
        $last = '';
        $p    = self::run_all( function ( $p ) use ( &$last ) {
            $line = $p['phase'] . ( ! empty( $p['total'] ) ? ' ' . min( (int) $p['offset'], (int) $p['total'] ) . '/' . (int) $p['total'] : '' );
            if ( $line !== $last ) {
                WP_CLI::log( '  ' . $line );
                $last = $line;
            }
        } );
        foreach ( (array) ( $p['notes'] ?? array() ) as $note ) {
            WP_CLI::warning( $note );
        }
        foreach ( self::counts() as $b => $n ) {
            WP_CLI::log( sprintf( '  %-12s %s', $b, number_format_i18n( $n ) ) );
        }
        WP_CLI::success( 'Report built.' );
    }

    /**
     * List the report.
     *
     * ## OPTIONS
     *
     * [--bucket=<bucket>]
     * : keep, refresh, consolidate, noindex or remove.
     *
     * [--limit=<n>]
     * : Rows to show. Default 50; 0 for all.
     *
     * [--format=<format>]
     * : table, csv, json or count. Default table.
     */
    public static function cli_report( $args, $assoc ) {
        $bucket = isset( $assoc['bucket'] ) ? sanitize_key( $assoc['bucket'] ) : '';
        $format = $assoc['format'] ?? 'table';
        if ( 'count' === $format ) {
            $counts = self::counts();
            WP_CLI::log( '' === $bucket ? wp_json_encode( $counts ) : (string) ( $counts[ $bucket ] ?? 0 ) );
            return;
        }
        if ( 'csv' === $format ) {
            fwrite( STDOUT, self::csv( $bucket ) );
            return;
        }
        $limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 50;
        $rows  = self::rows( $bucket, $limit );
        WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'tier', 'bucket', 'published', 'clicks', 'impressions', 'position', 'links_in', 'views', 'words', 'title', 'reason' ) );
    }

    /**
     * Apply a retention action to posts.
     *
     * ## OPTIONS
     *
     * <action>
     * : noindex, index, unavailable-after, clear-unavailable, redirect, clear-redirect, news-exclude,
     *   news-include, notice-show, notice-hide or notice-auto.
     *
     * [--bucket=<bucket>]
     * : Every post in this report bucket.
     *
     * [--ids=<ids>]
     * : Comma-separated post IDs instead.
     *
     * [--date=<date>]
     * : For unavailable-after (YYYY-MM-DD).
     *
     * [--to=<url>]
     * : For redirect. (--url is WP-CLI's own site switch, so it cannot be used here.)
     *
     * [--dry-run]
     * : Count only.
     */
    public static function cli_apply( $args, $assoc ) {
        $action = sanitize_key( $args[0] ?? '' );
        if ( ! isset( AceSeoRetentionActions::ACTIONS[ $action ] ) ) {
            WP_CLI::error( 'Unknown action. One of: ' . implode( ', ', array_keys( AceSeoRetentionActions::ACTIONS ) ) );
        }
        if ( ! empty( $assoc['bucket'] ) ) {
            $ids = wp_list_pluck( self::rows( sanitize_key( $assoc['bucket'] ), 0 ), 'id' );
        } elseif ( ! empty( $assoc['ids'] ) ) {
            $ids = array_map( 'absint', explode( ',', $assoc['ids'] ) );
        } else {
            WP_CLI::error( 'Give --bucket or --ids.' );
        }
        if ( ! empty( $assoc['dry-run'] ) ) {
            WP_CLI::success( sprintf( 'Would apply "%s" to %s post(s).', $action, number_format_i18n( count( $ids ) ) ) );
            return;
        }
        $r = AceSeoRetentionActions::apply( $ids, $action, array( 'date' => $assoc['date'] ?? '', 'url' => $assoc['to'] ?? '' ), 'cli' );
        if ( '' !== $r['error'] ) {
            WP_CLI::error( $r['error'] );
        }
        WP_CLI::success( sprintf( '%s: applied to %s post(s), %s skipped.', $action, number_format_i18n( $r['applied'] ), number_format_i18n( $r['skipped'] ) ) );
    }

    /**
     * List the redirect map (301s and 410s).
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv or json. Default table.
     */
    public static function cli_redirects( $args, $assoc ) {
        $rows = array_map( function ( $m ) { $m['to'] = $m['gone'] ? '410' : $m['to']; unset( $m['gone'] ); return $m; }, AceSeoRetentionActions::redirects( 100000 ) );
        WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'type', 'from', 'to', 'title' ) );
    }

    public static function cli_clear() {
        self::clear();
        WP_CLI::success( 'Report cleared; posts untouched.' );
    }
}
