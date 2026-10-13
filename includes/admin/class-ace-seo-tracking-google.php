<?php
defined( 'ABSPATH' ) || exit;

/** Additional Google reports: independent failures must not hide working integrations. */
class AceSeoTrackingGoogle {
    const CACHE = 'ace_seo_tracking_google';

    public static function channel( $channel, $source ) {
        $host = strtolower( rtrim( (string) $source, '.' ) );
        $host = preg_replace( '#^https?://#', '', $host );
        $host = explode( '/', $host )[0];
        $ai = in_array( strtolower( $channel ), array( 'ai assistant', 'ai assistants' ), true );
        foreach ( array( 'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com' ) as $domain ) {
            if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) { $ai = true; }
        }
        if ( $ai ) { return array( 'source_ai', 'AI assistant' ); }
        $label = trim( (string) $channel );
        if ( '' === $label || '(not set)' === $label ) { $label = 'Unassigned'; }
        return array( 'source_' . substr( sha1( $label ), 0, 10 ), $label );
    }

    /** Paginated report. Reject an incomplete result rather than chart partial totals. */
    public static function report( $property, $token, $dates, $dimensions, $metrics ) {
        $rows = array(); $metadata = array();
        for ( $page = 0; $page < 10; ++$page ) {
            $body = array( 'dateRanges' => array( array( 'startDate' => reset( $dates ), 'endDate' => end( $dates ) ) ),
                'dimensions' => array_map( function ( $name ) { return array( 'name' => $name ); }, $dimensions ),
                'metrics' => array_map( function ( $name ) { return array( 'name' => $name ); }, $metrics ),
                'limit' => 5000, 'offset' => $page * 5000,
                'orderBys' => array_map( function ( $name ) { return array( 'dimension' => array( 'dimensionName' => $name ) ); }, $dimensions ),
            );
            $response = wp_remote_post( 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport', array(
                'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ),
            ) );
            if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'error' => 'Google could not be reached. Try again later.' ); }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || isset( $data['error'] ) || ( ! isset( $data['metricHeaders'] ) && ! array_key_exists( 'rows', $data ) ) ) {
                return array( 'ok' => false, 'error' => 'Google could not supply this report. Check the linked property and reporting permissions (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
            }
            $batch = $data['rows'] ?? array();
            if ( ! is_array( $batch ) ) { return array( 'ok' => false, 'error' => 'Google returned an unreadable report.' ); }
            $rows = array_merge( $rows, $batch );
            $metadata = array_merge( $metadata, (array) ( $data['metadata'] ?? array() ) );
            if ( count( $rows ) >= (int) ( $data['rowCount'] ?? count( $rows ) ) ) { return array( 'ok' => true, 'rows' => $rows, 'metadata' => $metadata ); }
            if ( ! $batch ) { break; }
        }
        return array( 'ok' => false, 'error' => 'Google returned an incomplete report. No partial totals are plotted.' );
    }

    public static function date( $row ) {
        $date = $row['dimensionValues'][0]['value'] ?? '';
        return preg_match( '/^\d{8}$/', $date ) ? substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 ) : '';
    }

    public static function sources( $report, $dates ) {
        if ( empty( $report['ok'] ) ) { return $report; }
        $defs = $days = array();
        foreach ( $dates as $date ) { $days[ $date ] = array(); }
        foreach ( $report['rows'] as $row ) {
            $date = self::date( $row );
            if ( ! isset( $days[ $date ] ) ) { continue; }
            $count = $row['metricValues'][0]['value'] ?? null;
            if ( ! is_numeric( $count ) || (float) $count < 0 || ! is_finite( (float) $count ) ) { return array( 'ok' => false, 'error' => 'Google returned an invalid session count.' ); }
            list( $key, $label ) = self::channel( $row['dimensionValues'][1]['value'] ?? '', $row['dimensionValues'][2]['value'] ?? '' );
            $defs[ $key ] = array( 'label' => $label . ' sessions', 'group' => 'Traffic sources', 'area' => true );
            $days[ $date ][ $key ] = ( $days[ $date ][ $key ] ?? 0 ) + (float) $count;
        }
        uasort( $defs, function ( $a, $b ) { return strcmp( $a['label'], $b['label'] ); } );
        foreach ( $days as &$day ) { $day = array_merge( array_fill_keys( array_keys( $defs ), 0 ), $day ); }
        unset( $day );
        return array( 'ok' => true, 'days' => $days, 'definitions' => $defs, 'metadata' => $report['metadata'] ?? array() );
    }

    public static function counts( $report, $dates, $keys ) {
        if ( empty( $report['ok'] ) ) { return $report; }
        $days = array_fill_keys( $dates, array_fill_keys( $keys, 0 ) );
        foreach ( $report['rows'] as $row ) {
            $date = self::date( $row );
            if ( ! isset( $days[ $date ] ) ) { continue; }
            foreach ( $keys as $i => $key ) {
                $value = $row['metricValues'][ $i ]['value'] ?? null;
                if ( ! is_numeric( $value ) || (float) $value < 0 || ! is_finite( (float) $value ) ) { return array( 'ok' => false, 'error' => 'Google returned an invalid metric value.' ); }
                $days[ $date ][ $key ] += (float) $value;
            }
        }
        return array( 'ok' => true, 'days' => $days, 'metadata' => $report['metadata'] ?? array() );
    }

    public static function search( $dates ) {
        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_SEARCH_CONSOLE ) );
        if ( is_wp_error( $token ) || ! $token ) { return array( 'ok' => false, 'error' => 'Connect Search Console in Site Kit to read search performance.' ); }
        $property = AceSEOSiteKit::get_search_console_property_id();
        $response = wp_remote_post( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property ) . '/searchAnalytics/query', array(
            'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( array( 'startDate' => reset( $dates ), 'endDate' => end( $dates ), 'dimensions' => array( 'date' ), 'type' => 'web', 'dataState' => 'final', 'rowLimit' => 25000 ) ),
        ) );
        if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'error' => 'Search Console could not be reached.' ); }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) { return array( 'ok' => false, 'error' => 'Search Console reporting is unavailable. Check the property connection and access in Site Kit.' ); }
        $days = array_fill_keys( $dates, array( 'search_clicks' => null, 'search_impressions' => null ) );
        $found = false;
        foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
            $date = $row['keys'][0] ?? '';
            if ( ! isset( $days[ $date ] ) ) { continue; }
            if ( ! isset( $row['clicks'], $row['impressions'] ) || ! is_numeric( $row['clicks'] ) || ! is_numeric( $row['impressions'] ) || (float) $row['clicks'] < 0 || (float) $row['impressions'] < 0 || ! is_finite( (float) $row['clicks'] ) || ! is_finite( (float) $row['impressions'] ) ) { return array( 'ok' => false, 'error' => 'Search Console returned invalid metric values. No partial totals are plotted.' ); }
            $days[ $date ] = array( 'search_clicks' => (float) $row['clicks'], 'search_impressions' => (float) $row['impressions'] ); $found = true;
        }
        return array( 'ok' => $found, 'days' => $days, 'error' => $found ? '' : 'No final Search Console data was returned for this period.' );
    }

    public static function series( $dates, $force = false ) {
        $out = array( 'days' => array_fill_keys( $dates, array() ), 'definitions' => array(), 'status' => array(), 'at' => time() );
        if ( ! $dates || ! class_exists( 'AceSEOSiteKit' ) || ! AceSEOSiteKit::is_active() ) { return $out; }
        $property = AceSEOSiteKit::get_analytics_property_id();
        $key = md5( wp_json_encode( array( $dates, $property, AceSEOSiteKit::get_search_console_property_id() ) ) );
        $cache = get_transient( self::CACHE );
        if ( ! $force && is_array( $cache ) && ( $cache['key'] ?? '' ) === $key ) { return $cache; }
        $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
        $reports = array();
        if ( $property && ! is_wp_error( $token ) && $token ) {
            $reports['Traffic sources'] = self::sources( self::report( $property, $token, $dates, array( 'date', 'sessionDefaultChannelGroup', 'sessionSource' ), array( 'sessions' ) ), $dates );
            $reports['Engagement'] = self::counts( self::report( $property, $token, $dates, array( 'date' ), array( 'engagedSessions', 'keyEvents' ) ), $dates, array( 'ga_engaged', 'ga_key_events' ) );
            $reports['Advertising'] = self::counts( self::report( $property, $token, $dates, array( 'date', 'sessionCampaignName' ), array( 'advertiserAdClicks', 'advertiserAdImpressions' ) ), $dates, array( 'ads_clicks', 'ads_impressions' ) );
            if ( ! empty( $reports['Advertising']['ok'] ) && ! array_sum( array_map( 'array_sum', $reports['Advertising']['days'] ) ) ) {
                $reports['Advertising'] = array( 'ok' => false, 'error' => 'No advertising clicks or impressions were returned by GA4. Link the advertising account to this Analytics property; a conversion tag alone does not provide these reports.' );
            }
        } else { $out['status']['Analytics'] = 'Connect Analytics in Site Kit to read traffic sources and engagement.'; }
        $reports['Google Search'] = self::search( $dates );
        $definitions = array(
            'Engagement' => array( 'ga_engaged' => 'GA4 engaged sessions', 'ga_key_events' => 'GA4 key events' ),
            'Advertising' => array( 'ads_clicks' => 'Advertising clicks (GA4)', 'ads_impressions' => 'Advertising impressions (GA4)' ),
            'Google Search' => array( 'search_clicks' => 'Google Search clicks', 'search_impressions' => 'Google Search impressions' ),
        );
        foreach ( $reports as $name => $report ) {
            if ( empty( $report['ok'] ) ) { $out['status'][ $name ] = $report['error'] ?? 'No data available.'; continue; }
            $out['status'][ $name ] = 'Connected';
            if ( ! empty( $report['metadata']['subjectToThresholding'] ) || ! empty( $report['metadata']['dataLossFromOtherRow'] ) || ! empty( $report['metadata']['samplingMetadatas'] ) ) { $out['status'][ $name ] .= ' · Google applies reporting thresholds, aggregation or sampling.'; }
            if ( ! empty( $report['metadata']['timeZone'] ) ) { $out['timezone'] = $report['metadata']['timeZone']; }
            foreach ( $report['days'] as $date => $day ) { $out['days'][ $date ] = array_merge( $out['days'][ $date ], $day ); }
            foreach ( $definitions[ $name ] ?? array() as $id => $label ) { $out['definitions'][ $id ] = array( 'label' => $label, 'group' => $name, 'area' => false ); }
            $out['definitions'] = array_merge( $out['definitions'], $report['definitions'] ?? array() );
        }
        $out['key'] = $key;
        set_transient( self::CACHE, $out, HOUR_IN_SECONDS );
        return $out;
    }
}
