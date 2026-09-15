<?php
/**
 * Orphan reporting — how much content genuinely has no route in, per post type.
 *
 * Third-party crawlers report orphans by what they managed to reach, so on a large
 * archive-driven site they overstate the problem badly: a post sitting on page 1,400
 * of a category archive is reachable, just deep, and a crawler that gave up at page
 * 30 calls it orphaned. On a 41k-post news site that turns into thousands of false
 * positives and buries the handful of posts that really do have no way in.
 *
 * This separates the two:
 *   - orphaned — in no public archive at all. Nothing links to it and no archive
 *     lists it, so nothing but a sitemap will ever find it. This is the real number.
 *   - deep — reachable through an archive, but far enough back that crawl budget,
 *     not linking, is the constraint. A different problem with a different fix.
 *
 * Everything here is counted in SQL rather than by loading posts, one post type per
 * cron tick, into a cache. No admin request ever computes it.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoOrphanReport {

    /** Cached report. Transients land in the persistent object cache (Redis) where one exists. */
    const TRANSIENT = 'ace_seo_orphan_report';

    /** Scan progress, so a tick knows what the last one finished. */
    const PROGRESS_OPTION = 'ace_seo_orphan_scan_progress';

    const CRON_HOOK = 'ace_seo_orphan_scan';

    /** Report lifetime. Content moves slowly at this scale; a stale day costs nothing. */
    const TTL = DAY_IN_SECONDS;

    /** Archive page beyond which a post is treated as out of practical crawl reach. */
    const DEEP_PAGE_THRESHOLD = 30;

    /** Wall-clock budget for one tick. Cheap to resume, so stop early rather than risk a timeout. */
    const TICK_BUDGET = 15;

    const DAILY_HOOK = 'ace_seo_orphan_daily';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_scan_tick' ) );
        add_action( self::DAILY_HOOK, array( __CLASS__, 'maybe_refresh' ) );
        add_action( 'admin_post_ace_seo_scan_orphans', array( __CLASS__, 'handle_scan_request' ) );

        if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
        }
    }

    /**
     * Rebuild the report once a day, so the card is populated without anyone
     * having to ask for it.
     *
     * Deliberately NOT hooked to save_post: these counts move by one when a post
     * is published, and clearing the report on every save would leave a site that
     * publishes daily showing no report at all — the reading is a trend, not a
     * live counter.
     *
     * @return void
     */
    public static function maybe_refresh() {
        if ( self::is_scanning() ) {
            return;
        }

        self::schedule_scan();
    }

    /**
     * Queue a scan from the dashboard button.
     *
     * The request only schedules; it never scans inline, so the click returns
     * immediately however much content the site has.
     *
     * @return void
     */
    public static function handle_scan_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'ace-crawl-enhancer' ), 403 );
        }

        check_admin_referer( 'ace_seo_scan_orphans' );

        delete_transient( self::TRANSIENT );
        self::schedule_scan();

        wp_safe_redirect( add_query_arg( 'ace_seo_orphan_scan', 'queued', wp_get_referer() ?: admin_url( 'admin.php?page=ace-seo' ) ) );
        exit;
    }

    /**
     * The finished report, or null if one has not been built yet.
     *
     * Read-only and cheap by design — this never triggers a scan, so rendering the
     * dashboard cannot become the thing that times out.
     *
     * @return array|null
     */
    public static function get_report() {
        $report = get_transient( self::TRANSIENT );

        return is_array( $report ) ? $report : null;
    }

    /**
     * Queue a scan. Safe to call repeatedly; it will not stack up.
     *
     * @return bool Whether a scan is now pending.
     */
    public static function schedule_scan() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return true;
        }

        update_option( self::PROGRESS_OPTION, array( 'done' => array(), 'started' => time() ), false );

        return (bool) wp_schedule_single_event( time() + 5, self::CRON_HOOK );
    }

    /**
     * @return bool Whether a scan is queued or mid-run.
     */
    public static function is_scanning() {
        return (bool) wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * Post types worth reporting on: publicly viewable, with their own URLs.
     *
     * @return string[]
     */
    private static function post_types() {
        $types = array();

        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
            if ( 'attachment' === $type->name || ! $type->publicly_queryable ) {
                continue;
            }

            $types[] = $type->name;
        }

        /**
         * Filter the post types included in the orphan report.
         *
         * @param string[] $types
         */
        return array_values( (array) apply_filters( 'ace_seo_orphan_post_types', $types ) );
    }

    /**
     * Taxonomies that actually produce a browsable archive for a post type.
     *
     * A taxonomy without an archive is no route in, so membership of one says
     * nothing about whether a post can be reached.
     *
     * @param string $post_type
     * @return string[]
     */
    private static function archive_taxonomies( $post_type ) {
        $taxonomies = array();

        /**
         * Taxonomies that technically have archives but are not a route anyone
         * browses, so membership of one should not count as being reachable.
         *
         * @param string[] $ignored
         * @param string   $post_type
         */
        $ignored = (array) apply_filters( 'ace_seo_orphan_ignored_taxonomies', array( 'post_format' ), $post_type );

        foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
            if ( in_array( $taxonomy->name, $ignored, true ) ) {
                continue;
            }

            if ( $taxonomy->public && $taxonomy->publicly_queryable ) {
                $taxonomies[] = $taxonomy->name;
            }
        }

        return $taxonomies;
    }

    /**
     * Process one post type per tick, then reschedule until every type is done.
     *
     * @return void
     */
    public static function run_scan_tick() {
        global $wpdb;

        $progress = get_option( self::PROGRESS_OPTION );
        $progress = is_array( $progress ) ? $progress : array( 'done' => array(), 'started' => time() );
        $done     = isset( $progress['done'] ) && is_array( $progress['done'] ) ? $progress['done'] : array();

        $started = microtime( true );

        foreach ( self::post_types() as $post_type ) {
            if ( isset( $done[ $post_type ] ) ) {
                continue;
            }

            $done[ $post_type ] = self::measure_post_type( $post_type );

            // One type is usually the whole job; stop if this tick has had its share.
            if ( ( microtime( true ) - $started ) > self::TICK_BUDGET ) {
                break;
            }
        }

        $progress['done'] = $done;
        update_option( self::PROGRESS_OPTION, $progress, false );

        $remaining = array_diff( self::post_types(), array_keys( $done ) );

        if ( ! empty( $remaining ) ) {
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );

            return;
        }

        set_transient(
            self::TRANSIENT,
            array(
                'generated'  => time(),
                'per_page'   => max( 1, (int) get_option( 'posts_per_page', 10 ) ),
                'threshold'  => self::DEEP_PAGE_THRESHOLD,
                'post_types' => $done,
                'archives'   => self::measure_archives(),
            ),
            self::TTL
        );

        delete_option( self::PROGRESS_OPTION );

        // A tick run directly (WP-CLI, a manual call) leaves the queued event
        // behind; clear it so the scan does not immediately repeat itself.
        $pending = wp_next_scheduled( self::CRON_HOOK );

        if ( $pending ) {
            wp_unschedule_event( $pending, self::CRON_HOOK );
        }
    }

    /**
     * Count published posts of a type, and how many sit in no public archive.
     *
     * Counted with NOT EXISTS against term_relationships, whose primary key is
     * (object_id, term_taxonomy_id) — so this is an index lookup per row rather
     * than a scan, and no post is ever loaded into memory.
     *
     * @param string $post_type
     * @return array
     */
    private static function measure_post_type( $post_type ) {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                $post_type
            )
        );

        $taxonomies = self::archive_taxonomies( $post_type );

        // With no archive taxonomy at all, every post of the type depends on being
        // linked directly — report them as orphaned rather than pretending otherwise.
        if ( empty( $taxonomies ) ) {
            return array(
                'total'      => $total,
                'orphans'    => $total,
                'taxonomies' => array(),
                'samples'    => self::sample_orphans( $post_type, array() ),
                'note'       => 'no public taxonomy archive for this post type',
            );
        }

        $placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = p.ID AND tt.taxonomy IN ($placeholders)
             )",
            array_merge( array( $post_type ), $taxonomies )
        );

        return array(
            'total'      => $total,
            'orphans'    => (int) $wpdb->get_var( $sql ),
            'taxonomies' => $taxonomies,
            'samples'    => self::sample_orphans( $post_type, $taxonomies ),
        );
    }

    /**
     * A handful of orphaned posts, so the number leads somewhere actionable.
     *
     * @param string   $post_type
     * @param string[] $taxonomies
     * @return array[] id => title pairs.
     */
    private static function sample_orphans( $post_type, $taxonomies ) {
        global $wpdb;

        if ( empty( $taxonomies ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts}
                     WHERE post_type = %s AND post_status = 'publish'
                     ORDER BY post_date DESC LIMIT 5",
                    $post_type
                ),
                ARRAY_A
            );
        } else {
            $placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     AND NOT EXISTS (
                         SELECT 1 FROM {$wpdb->term_relationships} tr
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                         WHERE tr.object_id = p.ID AND tt.taxonomy IN ($placeholders)
                     )
                     ORDER BY p.post_date DESC LIMIT 5",
                    array_merge( array( $post_type ), $taxonomies )
                ),
                ARRAY_A
            );
        }

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * How deep the archives run — the context that explains an inflated orphan
     * count from an external crawler.
     *
     * Aggregated straight off term_taxonomy.count, so it costs one grouped query
     * regardless of how many terms or posts exist.
     *
     * @return array[]
     */
    private static function measure_archives() {
        global $wpdb;

        $per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
        $limit    = self::DEEP_PAGE_THRESHOLD * $per_page;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.taxonomy,
                        COUNT(*) AS terms,
                        MAX(tt.count) AS biggest,
                        SUM(CASE WHEN tt.count > %d THEN 1 ELSE 0 END) AS deep_terms
                 FROM {$wpdb->term_taxonomy} tt
                 WHERE tt.count > 0
                 GROUP BY tt.taxonomy
                 ORDER BY biggest DESC",
                $limit
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $public = get_taxonomies( array( 'public' => true ) );
        $out    = array();

        foreach ( $rows as $row ) {
            if ( ! in_array( $row['taxonomy'], $public, true ) ) {
                continue;
            }

            $row['pages_deep'] = (int) ceil( (int) $row['biggest'] / $per_page );
            $out[]             = $row;
        }

        return $out;
    }

}
