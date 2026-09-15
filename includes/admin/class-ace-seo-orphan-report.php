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

    /** How long an explicit "scan now" request may spend before handing the rest to cron. */
    const REQUEST_BUDGET = 10;

    /** Above this many archive-less candidates, skip the menu/hierarchy refinement. */
    const REFINE_CAP = 100000;

    const DAILY_HOOK = 'ace_seo_orphan_daily';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_scan_tick' ) );
        add_action( self::DAILY_HOOK, array( __CLASS__, 'maybe_refresh' ) );
        add_action( 'wp_ajax_ace_seo_load_orphan_report', array( __CLASS__, 'ajax_report' ) );

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
     * Return the report as rendered HTML, optionally scanning first.
     *
     * The card loads through the same progressive AJAX as the rest of the
     * dashboard, so asking for a scan never reloads the page.
     *
     * @return void
     */
    public static function ajax_report() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ace_seo_dashboard_nonce' ) ) {
            wp_send_json( array( 'status' => 'error', 'message' => __( 'Invalid nonce', 'ace-crawl-enhancer' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( array( 'status' => 'error', 'message' => __( 'Insufficient permissions', 'ace-crawl-enhancer' ) ) );
        }

        $scan = ! empty( $_POST['scan'] );

        if ( $scan ) {
            delete_transient( self::TRANSIENT );
            delete_option( self::PROGRESS_OPTION );

            // Run it here rather than queue it. WP-Cron only fires on an uncached
            // front-end hit, so on a cached or quiet site a queued scan can sit due
            // indefinitely. This is an explicit request and the work is a handful of
            // indexed counts.
            $deadline = microtime( true ) + self::REQUEST_BUDGET;

            do {
                self::run_scan_tick();

                if ( self::get_report() ) {
                    break;
                }
            } while ( microtime( true ) < $deadline );

            if ( ! self::get_report() ) {
                self::schedule_scan();
            }
        }

        wp_send_json(
            array(
                'status' => 'success',
                'data'   => array(
                    'html'    => self::render_html(),
                    'scanned' => (bool) self::get_report(),
                ),
            )
        );
    }

    /**
     * The card's inner HTML, for the AJAX container.
     *
     * @return string
     */
    public static function render_html() {
        $report = self::get_report();

        ob_start();

        if ( null === $report ) {
            ?>
            <p class="description">
                <?php esc_html_e( 'No reachability scan yet. It counts, per post type, how much content sits in no public archive at all — the pages nothing but a sitemap can reach.', 'ace-crawl-enhancer' ); ?>
            </p>
            <p><button type="button" class="button ace-scan-orphans"><?php esc_html_e( 'Run reachability scan', 'ace-crawl-enhancer' ); ?></button></p>
            <?php

            return (string) ob_get_clean();
        }

        $deepest = (int) ( $report['archives'][0]['pages_deep'] ?? 0 );
        ?>
        <table class="widefat striped ace-seo-orphan-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Post type', 'ace-crawl-enhancer' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Published', 'ace-crawl-enhancer' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Orphaned', 'ace-crawl-enhancer' ); ?></th>
                    <th><?php esc_html_e( 'Route in', 'ace-crawl-enhancer' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $report['post_types'] as $type => $data ) : ?>
                <?php $obj = get_post_type_object( $type ); ?>
                <tr>
                    <td><?php echo esc_html( $obj ? $obj->labels->name : $type ); ?></td>
                    <td class="num"><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></td>
                    <td class="num<?php echo $data['orphans'] > 0 ? ' ace-orphan-warn' : ''; ?>">
                        <?php echo esc_html( number_format_i18n( $data['orphans'] ) ); ?>
                    </td>
                    <td class="description">
                        <?php
                        if ( ! empty( $data['route'] ) ) {
                            echo esc_html( $data['route'] );
                        } elseif ( ! empty( $data['taxonomies'] ) ) {
                            /* translators: %s: comma-separated taxonomy names. */
                            printf( esc_html__( 'archives: %s', 'ace-crawl-enhancer' ), esc_html( implode( ', ', $data['taxonomies'] ) ) );
                        } else {
                            esc_html_e( 'menu / hierarchy', 'ace-crawl-enhancer' );
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="margin-top:10px">
            <?php
            printf(
                /* translators: %d: archive page number. */
                esc_html__( 'Orphaned means the post is in no public archive, so only a sitemap can reach it. Posts sitting deep in an archive are NOT counted — they are reachable, just far back, which is why external crawlers report far higher numbers. The deepest archive here runs to page %d.', 'ace-crawl-enhancer' ),
                $deepest
            );
            ?>
        </p>

        <?php if ( ! empty( $report['archives'] ) ) : ?>
            <table class="widefat striped ace-seo-orphan-table" style="margin-top:8px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Archive', 'ace-crawl-enhancer' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Terms', 'ace-crawl-enhancer' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Largest', 'ace-crawl-enhancer' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Pages deep', 'ace-crawl-enhancer' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( array_slice( $report['archives'], 0, 5 ) as $archive ) : ?>
                    <tr>
                        <td><?php echo esc_html( $archive['taxonomy'] ); ?></td>
                        <td class="num"><?php echo esc_html( number_format_i18n( (int) $archive['terms'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( number_format_i18n( (int) $archive['biggest'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( number_format_i18n( (int) $archive['pages_deep'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="description" style="margin-top:10px">
            <?php
            printf(
                /* translators: %s: human-readable time difference. */
                esc_html__( 'Scanned %s ago.', 'ace-crawl-enhancer' ),
                esc_html( human_time_diff( (int) $report['generated'] ) )
            );
            ?>
            <button type="button" class="button-link ace-scan-orphans"><?php esc_html_e( 'Rescan', 'ace-crawl-enhancer' ); ?></button>
        </p>
        <?php

        return (string) ob_get_clean();
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

        if ( false === get_option( self::PROGRESS_OPTION ) ) {
            update_option( self::PROGRESS_OPTION, array( 'done' => array(), 'started' => time() ), false );
        }

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

        foreach ( get_post_types( array(), 'objects' ) as $type ) {
            // is_post_type_viewable(), not publicly_queryable: core registers
            // 'page' with publicly_queryable = false (pages resolve by path, not
            // query var) and testing that flag silently drops every page.
            if ( 'attachment' === $type->name || ! is_post_type_viewable( $type ) ) {
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

            if ( is_taxonomy_viewable( $taxonomy ) ) {
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

        if ( 0 === $total ) {
            return array( 'total' => 0, 'orphans' => 0, 'taxonomies' => $taxonomies, 'samples' => array() );
        }

        // A post type archive lists every post of the type, so it is a route in on
        // its own — no taxonomy or menu needed. Checked first because it settles the
        // whole type in one go: /glossary/ makes every glossary entry reachable,
        // and counting those as orphans would repeat the mistake this report exists
        // to correct.
        $archive = get_post_type_archive_link( $post_type );

        if ( $archive ) {
            return array(
                'total'      => $total,
                'orphans'    => 0,
                'taxonomies' => $taxonomies,
                'samples'    => array(),
                // Name the archive rather than say "post type archive": for 'post'
                // core resolves this to the blog index, which is a different page
                // from a CPT archive and worth seeing plainly.
                'route'      => 'archive: ' . wp_make_link_relative( $archive ),
            );
        }

        // Step one, and the cheap one: anything in a public archive is reachable.
        // For a well-categorised post type this returns nothing and we stop here.
        $candidates = self::posts_without_archive( $post_type, $taxonomies );

        if ( empty( $candidates ) ) {
            return array( 'total' => $total, 'orphans' => 0, 'taxonomies' => $taxonomies, 'samples' => array() );
        }

        if ( count( $candidates ) > self::REFINE_CAP ) {
            return array(
                'total'      => $total,
                'orphans'    => count( $candidates ),
                'taxonomies' => $taxonomies,
                'samples'    => self::titles_for( array_slice( $candidates, 0, 5 ) ),
                'note'       => 'too many to refine against menus and hierarchy',
            );
        }

        // Step two: an archive is not the only way in. A page is reached through a
        // menu or its parent, never through a taxonomy — without this every page on
        // the site reports as orphaned, which is the false positive this exists to avoid.
        $reachable = self::reachable_without_archive( $post_type );
        $orphans   = array_values( array_diff( $candidates, $reachable ) );

        return array(
            'total'      => $total,
            'orphans'    => count( $orphans ),
            'taxonomies' => $taxonomies,
            'samples'    => self::titles_for( array_slice( $orphans, 0, 5 ) ),
        );
    }

    /**
     * Published IDs of a post type that sit in no public archive.
     *
     * @param string   $post_type
     * @param string[] $taxonomies
     * @return int[]
     */
    private static function posts_without_archive( $post_type, $taxonomies ) {
        global $wpdb;

        if ( empty( $taxonomies ) ) {
            return array_map(
                'intval',
                (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                        $post_type
                    )
                )
            );
        }

        $placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

        return array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     AND NOT EXISTS (
                         SELECT 1 FROM {$wpdb->term_relationships} tr
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                         WHERE tr.object_id = p.ID AND tt.taxonomy IN ($placeholders)
                     )",
                    array_merge( array( $post_type ), $taxonomies )
                )
            )
        );
    }

    /**
     * IDs reachable by something other than a taxonomy archive: a nav menu, the
     * front or posts page, or an ancestor that is itself reachable.
     *
     * Two queries whatever the size; the ancestor walk runs in memory over an
     * (ID, parent) map rather than as repeated lookups.
     *
     * @param string $post_type
     * @return int[]
     */
    private static function reachable_without_archive( $post_type ) {
        global $wpdb;

        $seeds = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );

        // Anything a nav menu points at has a route in by definition.
        $menu_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT oid.meta_value
                 FROM {$wpdb->postmeta} oid
                 INNER JOIN {$wpdb->postmeta} obj
                         ON obj.post_id = oid.post_id AND obj.meta_key = '_menu_item_object'
                 INNER JOIN {$wpdb->posts} mi
                         ON mi.ID = oid.post_id AND mi.post_type = 'nav_menu_item'
                 WHERE oid.meta_key = '_menu_item_object_id' AND obj.meta_value = %s",
                $post_type
            )
        );

        $reachable = array_flip( array_filter( array_map( 'intval', array_merge( $seeds, (array) $menu_ids ) ) ) );

        // Block themes do not use nav_menu_item at all — navigation lives in
        // wp_navigation, templates and template parts. Missing this reports pages
        // linked from the site header as orphaned.
        $blocks = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type IN ( 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_block' )
             AND post_status = 'publish' AND post_content <> ''"
        );

        foreach ( (array) $blocks as $content ) {
            // wp:page-list renders every published page, so its presence anywhere
            // in the navigation means no page can be orphaned.
            if ( 'page' === $post_type && false !== strpos( $content, 'wp:page-list' ) ) {
                return array_map(
                    'intval',
                    (array) $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                            $post_type
                        )
                    )
                );
            }

            // Explicit navigation links carry the id they point at.
            if ( preg_match_all( '~<!--\s+wp:navigation-link\s+(\{.*?\})\s*/?-->~s', $content, $matches ) ) {
                foreach ( $matches[1] as $json ) {
                    $attrs = json_decode( $json, true );

                    if ( ! is_array( $attrs ) || empty( $attrs['id'] ) ) {
                        continue;
                    }

                    $kind = $attrs['kind'] ?? '';
                    $type = $attrs['type'] ?? '';

                    if ( 'post-type' === $kind && $type !== $post_type ) {
                        continue;
                    }

                    $reachable[ (int) $attrs['id'] ] = true;
                }
            }
        }

        /**
         * Filter IDs treated as reachable without an archive — for sites whose
         * navigation this cannot see, such as links hard-coded in a template.
         *
         * @param int[]  $ids
         * @param string $post_type
         */
        foreach ( (array) apply_filters( 'ace_seo_orphan_reachable_ids', array(), $post_type ) as $extra ) {
            $reachable[ (int) $extra ] = true;
        }

        // A child is reachable when its parent is: parents link to their children,
        // and that is how a page tree is navigated.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_parent FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = 'publish' AND post_parent <> 0",
                $post_type
            ),
            ARRAY_A
        );

        // Walk down until a pass adds nothing; bounded so a parent cycle cannot spin.
        for ( $depth = 0; $depth < 20; $depth++ ) {
            $added = 0;

            foreach ( (array) $rows as $row ) {
                $id = (int) $row['ID'];

                if ( isset( $reachable[ $id ] ) ) {
                    continue;
                }

                if ( isset( $reachable[ (int) $row['post_parent'] ] ) ) {
                    $reachable[ $id ] = true;
                    $added++;
                }
            }

            if ( 0 === $added ) {
                break;
            }
        }

        return array_keys( $reachable );
    }

    /**
     * @param int[] $ids
     * @return array[]
     */
    private static function titles_for( $ids ) {
        global $wpdb;

        if ( empty( $ids ) ) {
            return array();
        }

        $ids          = array_map( 'intval', $ids );
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $ids ),
            ARRAY_A
        );

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
