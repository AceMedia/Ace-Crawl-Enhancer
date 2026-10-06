<?php
/** Shared CSV/Sheets row format; no admin hooks or external writes. */
defined( 'ABSPATH' ) || exit;

class AceSeoExport {
    public static function header() {
        $header = array( 'ID', 'Title', 'URL', 'Status', 'Published', 'Modified', 'Tier', 'Bucket', 'Views', 'Last viewed', 'Links in', 'Words', 'Search clicks', 'Search impressions', 'People (30 days)', 'Bots %', 'White hat', 'Indexable', 'Trend', 'Momentum', 'Views (7 days)', 'Views (30 days)', 'Views (90 days)', 'Search clicks (30 days)' );
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
            $rows[] = array_values( (array) apply_filters( 'ace_seo_list_export_row', $line, $id ) );
        }

        return $rows;
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
