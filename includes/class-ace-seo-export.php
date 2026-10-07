<?php
/** Shared CSV/Sheets row format; no admin hooks or external writes. */
defined( 'ABSPATH' ) || exit;

class AceSeoExport {
    public static function header() {
        $header = array( 'ID', 'Title', 'URL', 'Status', 'Published', 'Modified', 'Tier', 'Bucket', 'Views', 'Last viewed', 'Links in', 'Words', 'Search clicks', 'Search impressions', 'People (30 days)', 'Bots %', 'White hat', 'Indexable', 'Trend', 'Momentum', 'Views (7 days)', 'Views (30 days)', 'Views (90 days)', 'Search clicks (30 days)', 'Recommended next step', 'Why', 'Assessed at (site time)', 'Applied retention settings', 'When it matters', 'Relevant window', 'Timing basis', 'Linked events', 'Assessed in season?' );
        return array_values( (array) apply_filters( 'ace_seo_list_export_header', $header ) );
    }

    public static function rows( array $ids ) {
        if ( $ids ) { _prime_post_caches( $ids, true, true ); }
        $rows   = array();
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) { continue; }
            $row  = class_exists( 'AceSeoRetentionReport' ) ? get_post_meta( $id, AceSeoRetentionReport::META, true ) : '';
            $row  = is_array( $row ) ? $row : array();
            $line = array(
                $id,
                html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                get_permalink( $post ),
                $post->post_status,
                get_post_time( 'Y-m-d', false, $post ),
                get_post_modified_time( 'Y-m-d', false, $post ),
                $row['tier'] ?? '',
                $row['bucket'] ?? '',
                get_post_meta( $id, '_ace_seo_ret_views', true ),
                get_post_meta( $id, '_ace_seo_last_viewed', true ),
                get_post_meta( $id, '_ace_seo_ret_links', true ),
                get_post_meta( $id, '_ace_seo_ret_words', true ),
                $row['clicks'] ?? '',
                $row['impressions'] ?? '',
                get_post_meta( $id, '_ace_seo_humans', true ),
                get_post_meta( $id, '_ace_seo_bot_pct', true ),
                get_post_meta( $id, '_ace_seo_whitehat', true ),
                self::post_is_noindex( $id ) ? 'no' : 'yes',
                $row['trend'] ?? '',
                $row['momentum'] ?? '',
                $row['periods']['views_7'] ?? '',
                $row['periods']['views_30'] ?? '',
                $row['periods']['views_90'] ?? '',
                $row['periods']['clicks_30'] ?? '',
            );

            /**
             * Filter one exported row; add a value and a matching header with ace_seo_list_export_header.
             *
             * @param array $line
             * @param int   $id
             */
            $line = array_merge( $line, self::retention_context( $id, $row ), self::timing_context( $id, $row ) );
            $rows[] = array_values( (array) apply_filters( 'ace_seo_list_export_row', $line, $id ) );
        }

        return $rows;
    }

    /** Explain the saved recommendation; do not rescore or apply it during an export. */
    public static function retention_context( $id, array $row ) {
        $next_steps = array(
            'keep' => 'Keep this article available.',
            'refresh' => 'Review and update the article, title and search description.',
            'consolidate' => 'Check whether a newer article covers this subject. Choose a destination before setting a redirect.',
            'noindex' => 'Review whether this should stay available but out of search results.',
            'no-signal' => 'Check data coverage and seasonal interest before deciding what to do.',
        );
        $bucket = (string) ( $row['bucket'] ?? '' );
        $next = $next_steps[ $bucket ] ?? ( '' === $bucket ? 'Not assessed yet.' : 'Review the saved assessment in WordPress.' );
        $why = trim( wp_strip_all_tags( (string) ( $row['reason'] ?? '' ) ) );
        if ( '' === $why ) {
            $why = '' === $bucket
                ? 'No saved retention assessment. Check the report status and which content it covers.'
                : 'The saved recommendation has no recorded explanation. Review it before acting.';
        } else {
            $period = ! empty( $row['window'] ) ? 'over the assessed ' . (int) $row['window'] . '-day period' : 'over the assessed period';
            $why = strtr( $why, array(
                'impressions' => 'appearances in search results',
                'CTR' => 'click-through rate',
                'external backlink(s)' => 'link(s) from other websites',
                'internal link(s)' => 'link(s) from other pages on this site',
                'in the window' => $period,
            ) );
        }
        $assessed = ! empty( $row['built'] ) && is_numeric( $row['built'] ) && (int) $row['built'] > 0
            ? wp_date( 'Y-m-d H:i:s T', (int) $row['built'] ) : '';
        $applied = array();
        if ( class_exists( 'AceSeoRetentionActions' ) ) {
            $state = AceSeoRetentionActions::state( $id );
            if ( ! empty( $state['noindex'] ) ) { $applied[] = 'Search exclusion set (noindex)'; }
            if ( ! empty( $state['unavailable'] ) ) { $applied[] = 'Search expiry date: ' . $state['unavailable']; }
            if ( 'gone' === ( $state['redirect'] ?? '' ) ) { $applied[] = '410 response set; kept in WordPress'; }
            elseif ( ! empty( $state['redirect'] ) ) { $applied[] = 'Redirect set to ' . $state['redirect']; }
            if ( ! empty( $state['news_excl'] ) ) { $applied[] = 'Excluded from the news sitemap'; }
            if ( 'show' === ( $state['notice'] ?? '' ) ) { $applied[] = 'Older-article notice forced on'; }
            elseif ( 'hide' === ( $state['notice'] ?? '' ) ) { $applied[] = 'Older-article notice forced off'; }
            $applied = $applied ? implode( '; ', $applied ) : 'No per-post retention changes recorded';
        } else {
            $applied = 'Applied retention settings unavailable';
        }
        return array( $next, $why, $assessed, $applied );
    }

    /**
     * The dates an article is about and whether the saved assessment looked at them. Site knowledge
     * only (editor fields, linked events, anniversary estimate): an export never calls an analytics API.
     */
    public static function timing_context( $id, array $row ) {
        if ( ! class_exists( 'AceSeoRetentionReport' ) ) {
            return array( '', '', '', '', '' );
        }
        if ( ! class_exists( 'Ace_SEO_Retention_Evidence_View' ) ) {
            require_once __DIR__ . '/admin/class-ace-seo-retention-evidence-view.php';
        }
        $row['published'] = $row['published'] ?? get_post_time( 'Y-m-d', false, $id );
        try {
            return Ace_SEO_Retention_Evidence_View::timing_columns( $id, $row, wp_date( 'Y-m-d' ) );
        } catch ( Throwable $e ) {
            return array( 'Unavailable', '', 'Timing could not be worked out: ' . $e->getMessage(), '', '' );
        }
    }

    public static function post_types() {
        $types = array();

        foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
            if ( 'attachment' === $type->name || ! is_post_type_viewable( $type ) ) {
                continue;
            }

            $types[] = $type->name;
        }

        /**
         * Filter the post types that get SEO list columns.
         *
         * @param string[] $types
         */
        return (array) apply_filters( 'ace_seo_admin_column_post_types', $types );
    }

    public static function post_is_noindex( $post_id ) {
        if ( function_exists( 'ace_seo_site_is_discouraged' ) && ace_seo_site_is_discouraged() ) { return true; }
        if ( '1' === (string) AceCrawlEnhancer::get_meta_value( $post_id, 'meta-robots-noindex' ) ) {
            return true;
        }

        $advanced = (string) AceCrawlEnhancer::get_meta_value( $post_id, 'meta-robots-adv' );

        return false !== stripos( $advanced, 'noindex' );
    }
}
