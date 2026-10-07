<?php
/**
 * Traffic for exact dates, with honest coverage, for the retention evidence preview.
 *
 * The saved report measures "the last N days". A seasonal or event-bound article needs the traffic
 * for the dates that mattered — the two months around Cheltenham, last year's final — and a verdict
 * only when the whole of that period was actually recorded. This class answers one question per
 * period, in bulk and cached: for these dates, what did each source record, and does that source
 * cover the whole period or only part of it?
 *
 *   views    Google Analytics (Site Kit), or the plugin's own tracking where Analytics is absent
 *   search   Search Console clicks, impressions and position (Site Kit)
 *
 * Each source attests its own coverage. Missing rows are never zeros: a page that no source lists
 * has unknown views, and a period a source cannot fully see is reported as incomplete so the
 * evidence rules hold back any negative judgement. Nothing here writes to the report, posts or
 * options other than a per-period cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ace_SEO_Retention_Dated_Traffic {

    /** Search Console keeps 16 months; anything earlier cannot be covered. */
    const GSC_MONTHS = 16;
    const CACHE_TTL  = 12 * HOUR_IN_SECONDS;

    /** One fetch per period per request; the transient covers the rest. */
    private static $runtime = array();

    /** Drop the in-request cache (the transient stays), for long-running CLI runs and checks. */
    public static function forget_runtime() {
        self::$runtime = array();
    }

    public static function init() {
        add_filter( 'ace_seo_retention_evidence_context', array( static::class, 'context' ), 10, 3 );
    }

    /**
     * Fill the evidence context for one post from the bulk feed for its period.
     * Providers hooked later can still replace any of it.
     */
    public static function context( $context, $post_id, $row ) {
        $context = (array) $context;
        $period  = isset( $context['period'] ) && is_array( $context['period'] ) ? $context['period'] : array();
        $as_of   = isset( $context['as_of'] ) ? (string) $context['as_of'] : '';
        if ( ! self::is_period( $period ) || ! Ace_SEO_Retention_Evidence::date( $as_of ) || $period['end'] >= $as_of ) {
            return $context; // An unfinished period has no complete traffic yet; the rules say so.
        }
        $feed = static::feed( $period );
        if ( empty( $feed['sources'] ) ) {
            return $context;
        }
        $key = AceSeoRetentionReport::path_key( get_permalink( (int) $post_id ) );
        $id  = (int) $post_id;

        $metrics = array( 'views' => null, 'clicks' => 0, 'impressions' => 0, 'position' => 0.0 );
        if ( isset( $feed['views'] ) && is_array( $feed['views'] ) ) {
            // A page the views source lists is counted; one it does not list is a measured zero only
            // when that source saw the whole period — which is what coverage below says.
            $metrics['views'] = (int) ( $feed['views_by'] === 'id' ? ( $feed['views'][ $id ] ?? 0 ) : ( $feed['views'][ $key ] ?? 0 ) );
        }
        if ( isset( $feed['search'][ $key ] ) ) {
            $metrics['clicks']      = (int) $feed['search'][ $key ]['clicks'];
            $metrics['impressions'] = (int) $feed['search'][ $key ]['impressions'];
            $metrics['position']    = (float) $feed['search'][ $key ]['position'];
        }
        $context['metrics']       = $metrics;
        $context['metric_period'] = $period;
        $context['coverage']      = $feed['coverage'];
        $context['sources']       = $feed['sources'];
        return $context;
    }

    /**
     * Everything the sources recorded for a finished period, keyed for lookup, plus the coverage
     * they can honestly claim. Cached per period.
     */
    public static function feed( array $period ) {
        $cache_key = 'ace_seo_dated_' . md5( $period['start'] . '|' . $period['end'] );
        if ( isset( self::$runtime[ $cache_key ] ) ) {
            return self::$runtime[ $cache_key ];
        }
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['coverage'] ) ) {
            self::$runtime[ $cache_key ] = $cached;
            return $cached;
        }
        $feed = static::collect( $period );
        set_transient( $cache_key, $feed, self::CACHE_TTL );
        self::$runtime[ $cache_key ] = $feed;
        return $feed;
    }

    /** Ask each source; combine their coverage. Overridable so checks can supply fixtures. */
    protected static function collect( array $period ) {
        $sources  = array();
        $notes    = array();
        $complete = true;
        $capped   = false;
        $feed     = array( 'views' => null, 'views_by' => 'path', 'search' => array() );

        $views = static::analytics( $period );
        if ( is_array( $views ) ) {
            $sources[]        = 'Google Analytics';
            $feed['views']    = $views['rows'];
            $feed['views_by'] = 'path';
            $complete         = $complete && ! empty( $views['complete'] );
            $capped           = $capped || ! empty( $views['capped'] );
            if ( ! empty( $views['note'] ) ) {
                $notes[] = $views['note'];
            }
        } else {
            $own = static::own_tracking( $period );
            if ( is_array( $own ) ) {
                $sources[]        = 'Own view tracking';
                $feed['views']    = $own['rows'];
                $feed['views_by'] = 'id';
                $complete         = $complete && ! empty( $own['complete'] );
                if ( ! empty( $own['note'] ) ) {
                    $notes[] = $own['note'];
                }
            }
        }

        $search = static::search_console( $period );
        if ( is_array( $search ) ) {
            $sources[]      = 'Search Console';
            $feed['search'] = $search['rows'];
            $complete       = $complete && ! empty( $search['complete'] );
            $capped         = $capped || ! empty( $search['capped'] );
            if ( ! empty( $search['note'] ) ) {
                $notes[] = $search['note'];
            }
        }

        $feed['sources']  = $sources;
        $feed['notes']    = $notes;
        $feed['coverage'] = $sources ? array(
            'start'    => $period['start'],
            'end'      => $period['end'],
            // Views are what a negative verdict turns on: no views source, no complete coverage.
            'complete' => $complete && null !== $feed['views'],
            'capped'   => $capped,
            'sources'  => $sources,
            'notes'    => $notes,
        ) : array();
        $feed['fetched_at'] = time();
        return $feed;
    }

    /** Google Analytics page views for the exact dates, keyed by path. null when Analytics is not connected. */
    protected static function analytics( array $period ) {
        if ( ! method_exists( 'AceSeoRetentionReport', 'ga4_report' ) ) {
            return null;
        }
        $report = AceSeoRetentionReport::ga4_report( $period['start'], $period['end'] );
        if ( is_wp_error( $report ) ) {
            return null;
        }
        $complete = ! $report['capped'];
        $note     = '';
        if ( ! $report['rows'] ) {
            // A property that recorded nothing at all for the dates was not collecting then.
            $complete = false;
            $note     = 'Google Analytics has no rows for these dates: the property was not recording, or the dates predate it.';
        }
        return array( 'rows' => $report['rows'], 'complete' => $complete, 'capped' => $report['capped'], 'note' => $note );
    }

    /** The plugin's own human-view rows for the exact dates, keyed by post ID. null when tracking is off. */
    protected static function own_tracking( array $period ) {
        if ( ! class_exists( 'AceSeoViewTracker' ) || ! AceSeoViewTracker::enabled() ) {
            return null;
        }
        $since = AceSeoViewTracker::coverage_start();
        if ( '' === $since ) {
            return null; // Switched on, but nothing recorded yet.
        }
        $complete = $since <= $period['start'];
        return array(
            'rows'     => AceSeoViewTracker::views_between( $period['start'], $period['end'] ),
            'complete' => $complete,
            'note'     => $complete ? '' : sprintf( 'Own tracking only starts on %s, after the chosen period began.', $since ),
        );
    }

    /** Search Console rows for the exact dates, keyed by path. null when Search Console is not connected. */
    protected static function search_console( array $period ) {
        if ( ! class_exists( 'AceSEOSearchConsole' ) && class_exists( 'AceSEOSiteKit' ) && defined( 'ACE_SEO_PATH' ) ) {
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-search-console.php';
        }
        if ( ! class_exists( 'AceSEOSearchConsole' ) || ! AceSEOSearchConsole::is_ready() ) {
            return null;
        }
        $report = AceSEOSearchConsole::pages_between( $period['start'], $period['end'] );
        if ( is_wp_error( $report ) ) {
            return null;
        }
        $rows = array();
        foreach ( $report['rows'] as $url => $r ) {
            $rows[ AceSeoRetentionReport::path_key( $url ) ] = $r;
        }
        $oldest   = gmdate( 'Y-m-d', strtotime( '-' . self::GSC_MONTHS . ' months' ) );
        $complete = ! $report['capped'] && $period['start'] >= $oldest;
        return array(
            'rows'     => $rows,
            'complete' => $complete,
            'capped'   => $report['capped'],
            'note'     => $period['start'] < $oldest ? sprintf( 'Search Console keeps %d months; dates before %s are not available.', self::GSC_MONTHS, $oldest ) : '',
        );
    }

    public static function is_period( $period ) {
        return is_array( $period ) && Ace_SEO_Retention_Evidence::date( $period['start'] ?? null ) && Ace_SEO_Retention_Evidence::date( $period['end'] ?? null ) && $period['start'] <= $period['end'];
    }
}
