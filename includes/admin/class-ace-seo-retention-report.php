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
    const SIGNALS_OPTION  = 'ace_seo_retention_signals';
    const CRON_HOOK       = 'ace_seo_retention_tick';
    const BATCH           = 300;

    const BUCKETS = array( 'keep', 'refresh', 'consolidate', 'noindex', 'no-signal' );

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
            add_action( 'admin_post_ace_seo_retention_build', array( __CLASS__, 'handle_build' ) );
            add_action( 'admin_post_ace_seo_retention_export', array( __CLASS__, 'handle_export' ) );
            add_action( 'admin_post_ace_seo_retention_clear', array( __CLASS__, 'handle_clear' ) );
        }
    }

    /* ---- Settings ------------------------------------------------------------------------------ */

    /**
     * Defaults and thresholds. All filterable, none site-specific: a site whose articles date fast
     * can shorten the cutoff, one with a long tail can raise the "demand" bar.
     */
    public static function settings( array $overrides = array() ) {
        $defaults = array(
            'older_than_years'  => 3,      // posts published before this many years ago are candidates
            'days'              => 90,     // Search Console window
            'post_types'        => array( 'post' ),
            'demand_impressions'=> 100,    // impressions in the window that count as "there is demand"
            'refresh_max_ctr'   => 0.02,   // below this CTR, with demand, the page needs a refresh
            'refresh_max_pos'   => 20,     // and it has to be within reach: page 1 or 2
        );
        $settings = array_merge( $defaults, array_intersect_key( $overrides, $defaults ) );
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
            'notes'    => array(),
        ), false );
        update_option( self::SIGNALS_OPTION, array( 'gsc' => array(), 'links' => array() ), false );
        self::schedule_tick();
    }

    public static function run_all( $callback = null ) {
        $guard = 0;
        while ( self::is_building() && $guard++ < 100000 ) {
            self::run_tick();
            if ( $callback ) {
                call_user_func( $callback, self::progress() );
            }
        }
        return self::progress();
    }

    private static function schedule_tick() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
    }

    public static function run_tick() {
        $p = self::progress();
        if ( empty( $p['phase'] ) || in_array( $p['phase'], array( 'done', 'error' ), true ) ) {
            return;
        }

        switch ( $p['phase'] ) {
            case 'gsc':
                self::phase_gsc( $p );
                break;
            case 'links':
                self::phase_links( $p );
                break;
            case 'score':
                self::phase_score( $p );
                break;
        }

        if ( self::is_building() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            self::schedule_tick();
        }
    }

    private static function save_progress( array $p ) {
        update_option( self::PROGRESS_OPTION, $p, false );
    }

    /** Phase 1: one bulk Search Console pull for every page with an impression in the window. */
    private static function phase_gsc( array $p ) {
        $signals = get_option( self::SIGNALS_OPTION, array() );
        $gsc     = array();

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

        $signals['gsc'] = $gsc;
        update_option( self::SIGNALS_OPTION, $signals, false );

        $p['phase']  = 'links';
        $p['offset'] = 0;
        $p['total']  = self::count_all_posts( $p['settings']['post_types'] );
        self::save_progress( $p );
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

        /**
         * Page views per post over the same window, from whatever analytics the site has:
         * array( post_id => views ). Nothing is assumed; without a provider the signal is absent.
         */
        $views = apply_filters( 'ace_seo_retention_pageviews', array(), $ids, $settings );
        /** External backlinks per post, array( post_id => count ), if the site has a source. */
        $backlinks = apply_filters( 'ace_seo_retention_backlinks', array(), $ids, $settings );

        foreach ( $ids as $id ) {
            $key = self::path_key( get_permalink( $id ) );
            $g   = $gsc[ $key ] ?? array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );

            $row = array(
                'clicks'      => (int) $g['clicks'],
                'impressions' => (int) $g['impressions'],
                'position'    => (float) $g['position'],
                'links_in'    => (int) ( $links[ $id ] ?? 0 ),
                'views'       => isset( $views[ $id ] ) ? (int) $views[ $id ] : null,
                'backlinks'   => isset( $backlinks[ $id ] ) ? (int) $backlinks[ $id ] : null,
            );
            list( $bucket, $reason ) = self::bucket( $row, $settings );
            $row['bucket'] = $bucket;
            $row['reason'] = $reason;
            $row['built']  = time();
            $row['window'] = (int) $settings['days'];

            $row = apply_filters( 'ace_seo_retention_row', $row, $id, $settings );
            update_post_meta( $id, self::META, $row );
            $p['counts'][ $row['bucket'] ] = ( $p['counts'][ $row['bucket'] ] ?? 0 ) + 1;
        }

        $p['offset'] += self::BATCH;
        if ( count( $ids ) < self::BATCH ) {
            $p['phase']    = 'done';
            $p['finished'] = time();
            delete_option( self::SIGNALS_OPTION );
        }
        self::save_progress( $p );
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
        if ( $r['impressions'] > 0 ) {
            return array( 'consolidate', sprintf( 'Shown %s times but never clicked: a stronger page on the same subject should own these queries; fold this one into it with a 301.', number_format_i18n( $r['impressions'] ) ) );
        }
        if ( $r['links_in'] > 0 ) {
            return array( 'noindex', sprintf( 'No search value, but %s internal link(s) still point here: keep serving it, drop it from the index.', number_format_i18n( $r['links_in'] ) ) );
        }
        $why = 'No clicks, no impressions, no internal links';
        $why .= null === $r['views'] ? ' (no page-view source configured)' : ', no page views';
        return array( 'no-signal', $why . ' in the window: nothing on the site or in search is using it. Noindex it, fold it into a hub, or leave it.' );
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
        $lookup = array();
        $offset = 0;
        do {
            $ids = self::candidate_ids( $settings, 2000, $offset );
            foreach ( $ids as $id ) {
                $lookup[ self::path_key( get_permalink( $id ) ) ] = $id;
            }
            $offset += 2000;
        } while ( count( $ids ) === 2000 );
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
        $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::META ) );
        delete_option( self::PROGRESS_OPTION );
        delete_option( self::SIGNALS_OPTION );
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function csv( $bucket = '' ) {
        $cols = array( 'id', 'bucket', 'title', 'url', 'published', 'clicks', 'impressions', 'position', 'links_in', 'views', 'backlinks', 'reason' );
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
        $overrides = array(
            'older_than_years' => isset( $_POST['older_than_years'] ) ? max( 1, (int) $_POST['older_than_years'] ) : 3,
            'days'             => isset( $_POST['days'] ) ? max( 7, (int) $_POST['days'] ) : 90,
        );
        self::start( $overrides );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' ) );
        exit;
    }

    public static function handle_clear() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_retention_clear' ) ) {
            wp_die( 'Not allowed.' );
        }
        self::clear();
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' ) );
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

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $p        = self::progress();
        $settings = ! empty( $p['settings'] ) ? $p['settings'] : self::settings();
        $bucket   = isset( $_GET['bucket'] ) ? sanitize_key( $_GET['bucket'] ) : '';
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per      = 100;
        $counts   = self::counts();
        $built    = array_sum( $counts ) > 0;
        $labels   = array(
            'keep'        => 'Keep',
            'refresh'     => 'Refresh',
            'consolidate' => 'Consolidate',
            'noindex'     => 'Noindex',
            'no-signal'      => 'No signal',
        );
        ?>
        <div class="wrap">
            <h1>Retention report</h1>
            <p>Every published post older than the cutoff, scored on search clicks and impressions, inbound internal links and (where a source is wired in) page views and backlinks, and placed in a bucket that says how to keep it well. It is a report: nothing here changes a post, and nothing in it recommends deleting one.</p>

            <?php if ( self::is_building() ) : ?>
                <div class="notice notice-info"><p>
                    Building: <strong><?php echo esc_html( $p['phase'] ); ?></strong>
                    <?php if ( ! empty( $p['total'] ) ) : ?>— <?php echo esc_html( number_format_i18n( min( (int) $p['offset'], (int) $p['total'] ) ) ); ?> of <?php echo esc_html( number_format_i18n( (int) $p['total'] ) ); ?><?php endif; ?>.
                    It runs on cron in the background; reload to follow it.
                </p></div>
            <?php elseif ( ! empty( $p['finished'] ) ) : ?>
                <div class="notice notice-success"><p>Built <?php echo esc_html( human_time_diff( (int) $p['finished'] ) ); ?> ago: posts older than <?php echo esc_html( (int) $settings['older_than_years'] ); ?> years, a <?php echo esc_html( (int) $settings['days'] ); ?>-day search window.</p></div>
            <?php endif; ?>
            <?php foreach ( (array) ( $p['notes'] ?? array() ) as $note ) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html( $note ); ?></p></div>
            <?php endforeach; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
                <?php wp_nonce_field( 'ace_seo_retention_build' ); ?>
                <input type="hidden" name="action" value="ace_seo_retention_build">
                <label>Posts older than <input type="number" name="older_than_years" min="1" max="20" value="<?php echo esc_attr( (int) $settings['older_than_years'] ); ?>" style="width:4em"> years</label>
                &nbsp; <label>Search window <input type="number" name="days" min="7" max="480" value="<?php echo esc_attr( (int) $settings['days'] ); ?>" style="width:5em"> days</label>
                &nbsp; <button class="button button-primary" <?php disabled( self::is_building() ); ?>><?php echo $built ? 'Rebuild' : 'Build the report'; ?></button>
                <?php if ( $built ) : ?>
                    &nbsp; <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ace_seo_retention_export' . ( $bucket ? '&bucket=' . $bucket : '' ) ), 'ace_seo_retention_export' ) ); ?>">Export CSV<?php echo $bucket ? ' (' . esc_html( $labels[ $bucket ] ?? $bucket ) . ')' : ''; ?></a>
                <?php endif; ?>
            </form>

            <?php if ( $built ) : ?>
                <ul class="subsubsub" style="margin-bottom:1em">
                    <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention' ) ); ?>" <?php echo '' === $bucket ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $counts ) ) ); ?>)</span></a> |</li>
                    <?php foreach ( self::BUCKETS as $i => $b ) : ?>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention&bucket=' . $b ) ); ?>" <?php echo $bucket === $b ? 'class="current"' : ''; ?>><?php echo esc_html( $labels[ $b ] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $b ] ) ); ?>)</span></a><?php echo $i < count( self::BUCKETS ) - 1 ? ' |' : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
                <div style="clear:both"></div>

                <?php $rows = self::rows( $bucket, $per, ( $paged - 1 ) * $per ); ?>
                <table class="widefat striped">
                    <thead><tr><th>Post</th><th>Published</th><th>Bucket</th><th>Clicks</th><th>Impr.</th><th>Pos.</th><th>Links in</th><th>Views</th><th>Why</th></tr></thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="9">Nothing in this bucket.</td></tr>
                    <?php endif; ?>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['title'] ?: '(no title)' ); ?></a><br><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#666"><?php echo esc_html( wp_make_link_relative( $r['url'] ) ); ?></a></td>
                            <td><?php echo esc_html( $r['published'] ); ?></td>
                            <td><strong><?php echo esc_html( $labels[ $r['bucket'] ] ?? $r['bucket'] ); ?></strong></td>
                            <td><?php echo esc_html( number_format_i18n( $r['clicks'] ) ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $r['impressions'] ) ); ?></td>
                            <td><?php echo esc_html( $r['position'] ?: '–' ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $r['links_in'] ) ); ?></td>
                            <td><?php echo null === $r['views'] ? '–' : esc_html( number_format_i18n( $r['views'] ) ); ?></td>
                            <td><?php echo esc_html( $r['reason'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:2em" onsubmit="return confirm('Clear the report? The posts are untouched; only the scores go.');">
                    <?php wp_nonce_field( 'ace_seo_retention_clear' ); ?>
                    <input type="hidden" name="action" value="ace_seo_retention_clear">
                    <button class="button-link-delete">Clear the report</button>
                </form>
            <?php endif; ?>

            <h2 style="margin-top:2em">How the buckets are decided</h2>
            <ol>
                <li><strong>Refresh</strong> — at least <?php echo esc_html( number_format_i18n( (int) $settings['demand_impressions'] ) ); ?> impressions, within position <?php echo esc_html( (int) $settings['refresh_max_pos'] ); ?>, but a CTR under <?php echo esc_html( round( $settings['refresh_max_ctr'] * 100, 1 ) ); ?>%. The demand is there; the page is not earning it.</li>
                <li><strong>Keep</strong> — any search clicks, backlinks or page views in the window.</li>
                <li><strong>Consolidate</strong> — impressions but no clicks: a stronger page should own those queries; 301 this one to it.</li>
                <li><strong>Noindex</strong> — no search value, but still linked from the site: keep serving it, drop it from the index.</li>
                <li><strong>No signal</strong> — nothing at all in the window. Still served and still reachable from its archives; noindex it, fold it into a hub, or leave it. Only trustworthy with Search Console connected and a page-view source wired in (the <code>ace_seo_retention_pageviews</code> filter).</li>
            </ol>
            <p>Thresholds and the cutoff are filterable (<code>ace_seo_retention_settings</code>); each row can be adjusted before it is stored (<code>ace_seo_retention_row</code>). From the command line: <code>wp ace-crawl retention build</code> and <code>wp ace-crawl retention report</code>.</p>
        </div>
        <?php
    }

    /* ---- WP-CLI --------------------------------------------------------------------------------- */

    public static function register_cli() {
        WP_CLI::add_command( 'ace-crawl retention build', array( __CLASS__, 'cli_build' ) );
        WP_CLI::add_command( 'ace-crawl retention report', array( __CLASS__, 'cli_report' ) );
        WP_CLI::add_command( 'ace-crawl retention clear', array( __CLASS__, 'cli_clear' ) );
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
        WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'bucket', 'published', 'clicks', 'impressions', 'position', 'links_in', 'views', 'title', 'reason' ) );
    }

    public static function cli_clear() {
        self::clear();
        WP_CLI::success( 'Report cleared; posts untouched.' );
    }
}
