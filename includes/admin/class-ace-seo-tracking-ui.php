<?php
defined( 'ABSPATH' ) || exit;

class AceSeoTrackingUI {
    public static function detail( $text ) {
        $escaped = esc_html( $text );
        return preg_replace( '/\b(?:(?<=Project )[a-z0-9]+|GTM-[A-Z0-9]+|GT-[A-Z0-9]+|G-[A-Z0-9]+|AW-[0-9]+|[0-9]+(?:\.[0-9]+)+|[0-9]{2,}|[a-z0-9]{4,}[0-9][a-z0-9]{4,})\b/', '<code class="ace-trk-id">$0</code>', $escaped );
    }

    public static function links( $key, $row = array() ) {
        $sk = 'admin.php?page=googlesitekit-settings';
        $paths = array(
            'sitekit' => $sk, 'ga4' => $sk . '#/connected-services/analytics-4',
            'gtm' => $sk . '#/connected-services/tagmanager', 'ads' => $sk . '#/connected-services/ads',
            'consent' => $sk . '#/admin-settings', 'gla' => 'admin.php?page=wc-admin&path=/google/settings',
            'clarity' => 'admin.php?page=microsoft-clarity', 'jetpack' => 'admin.php?page=stats',
            'woo' => 'admin.php?page=wc-settings', 'woo_ga' => 'admin.php?page=wc-settings&tab=integration&section=google_analytics',
            'fb' => 'admin.php?page=wc-settings&tab=integration&section=facebook',
        );
        if ( 'gtm' === $key && false !== strpos( $row['label'] ?? '', 'GTM4WP' ) ) { $paths['gtm'] = 'options-general.php?page=duracelltomi-google-tag-manager'; }
        if ( 'banner' === $key ) {
            foreach ( array( 'CookieYes' => 'admin.php?page=cookie-law-info', 'Complianz' => 'admin.php?page=complianz', 'Cookiebot' => 'admin.php?page=cookiebot', 'GDPR Cookie Compliance' => 'admin.php?page=moove-gdpr' ) as $name => $path ) {
                if ( false !== strpos( $row['label'] ?? '', $name ) ) { $paths['banner'] = $path; break; }
            }
            if ( false !== strpos( $row['detail'] ?? '', 'theme provides' ) ) {
                return '<a href="' . esc_url( home_url( '/cookies/' ) ) . '">Cookie information</a>';
            }
        }
        $links = array( '<a href="' . esc_url( admin_url( $paths[ $key ] ?? 'plugins.php' ) ) . '">' . ( isset( $paths[ $key ] ) ? ( 'jetpack' === $key ? 'Stats' : 'Settings' ) : 'Manage plugin' ) . '</a>' );
        if ( 'ga4' === $key ) {
            $settings = (array) get_option( 'googlesitekit_analytics-4_settings', array() );
            if ( ! empty( $settings['propertyID'] ) ) { $links[] = '<a target="_blank" rel="noopener noreferrer" href="https://analytics.google.com/analytics/web/#/p' . esc_attr( $settings['propertyID'] ) . '/reports/intelligenthome">GA4 reports</a>'; }
        }
        if ( 'clarity' === $key ) { $links[] = '<a href="#clarity-data">Reporting connection</a>'; }
        if ( 'jetpack' === $key ) { $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=jetpack#/settings/traffic' ) ) . '">Settings</a>'; }
        if ( 'woo' === $key ) { $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/revenue' ) ) . '">Revenue report</a>'; }
        return implode( ' <span aria-hidden="true">·</span> ', $links );
    }

    public static function ads( $ads ) {
        $ok = 1 === count( $ads['configured'] ) && $ads['tag_found'];
        echo '<tr><td><strong>Google Ads</strong></td><td><span class="pill pill-' . ( $ok ? 'ok' : 'warn' ) . '">' . ( $ok ? 'On' : 'Check' ) . '</span></td><td>';
        foreach ( array( true, false ) as $configured_pass ) {
            foreach ( $ads['providers'] as $key => $provider ) {
                $configured = in_array( $key, $ads['configured'], true );
                if ( $configured !== $configured_pass ) { continue; }
                $name = 'ads' === $key ? 'Site Kit' : 'Google for WooCommerce';
                if ( $configured ) {
                    echo '<div class="ace-trk-provider"><strong>' . esc_html( $name ) . '</strong> <span class="pill pill-ok">Configured</span> <span>' . self::detail( $provider['detail'] ) . '</span> <span class="ace-trk-links">' . self::links( $key, $provider ) . '</span></div>';
                } else {
                    echo '<div class="ace-trk-alternative"><span>' . ( $ads['configured'] ? 'Alternative: ' : 'Not configured: ' ) . esc_html( $name ) . '</span> <span class="ace-trk-links">' . self::links( $key, $provider ) . '</span></div>';
                }
            }
        }
        if ( count( $ads['configured'] ) > 1 ) { echo '<p class="description">Both providers are configured. Check conversion destinations to avoid duplicate conversions.</p>'; }
        if ( ! $ads['tag_found'] ) { echo '<p class="description">No AW- tag configuration was verified on the home page.</p>'; }
        echo '</td></tr>';
    }
}
