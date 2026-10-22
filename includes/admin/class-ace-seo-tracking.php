<?php
/**
 * Tracking: which analytics and marketing tags this site runs, whether they are
 * actually on the page, and whether Google Analytics agrees with an independent
 * counter (Jetpack Stats) day by day.
 *
 * Everything here is read-only against the site. Detection reads plugin state
 * and options; the page probe fetches the site's own home page as a browser
 * would and scans static HTML without executing JavaScript. The series come from
 * the GA4 Data API (through the Site Kit token bridge) and Jetpack's stats endpoint. A daily cron tick repeats
 * the checks. Inconclusive page checks need browser verification; an admin notice
 * (and optionally an email) is reserved for a sustained reporting gap.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/class-ace-seo-tracking-commerce.php';
require_once __DIR__ . '/class-ace-seo-tracking-clarity.php';
require_once __DIR__ . '/class-ace-seo-tracking-ui.php';
require_once __DIR__ . '/class-ace-seo-tracking-google.php';

class AceSeoTracking {
    const SLUG       = 'ace-seo-tracking';
    const CRON       = 'ace_seo_tracking_check';
    const OPT_ALERTS = 'ace_seo_tracking_alerts';
    const OPT_EMAIL  = 'ace_seo_tracking_email';
    const T_PAGE     = 'ace_seo_tracking_page';
    const PROBE_VERSION = 2;
    const T_SERIES   = 'ace_seo_tracking_series';
    const DAYS       = 60;
    const MIN_TRAFFIC = 10;

    public static function init() {
        // This component is loaded from the plugin's init callback. A callback
        // added at the same priority there will not run on the current request.
        if ( did_action( 'init' ) ) {
            self::schedule();
        } else {
            add_action( 'init', array( __CLASS__, 'schedule' ) );
        }
        add_action( self::CRON, array( __CLASS__, 'run' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
        add_action( 'admin_post_ace_seo_tracking_refresh', array( __CLASS__, 'admin_refresh' ) );
        add_action( 'admin_post_ace_seo_tracking_email', array( __CLASS__, 'admin_email_toggle' ) );
        add_action( 'admin_post_ace_seo_tracking_csv', array( __CLASS__, 'admin_csv' ) );
        add_action( 'admin_post_ace_seo_tracking_clarity', array( 'AceSeoTrackingClarity', 'save' ) );
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON ) ) {
            $at = new DateTime( 'tomorrow 05:10', wp_timezone() );
            wp_schedule_event( $at->getTimestamp(), 'daily', self::CRON );
        }
    }

    // ------------------------------------------------------------ detection

    /**
     * Is a plugin active on this site (or network-wide)?
     */
    public static function plugin_on( $file ) {
        if ( in_array( $file, (array) get_option( 'active_plugins', array() ), true ) ) {
            return true;
        }
        return is_multisite() && array_key_exists( $file, (array) get_site_option( 'active_sitewide_plugins', array() ) );
    }

    private static function sitekit_option( $name ) {
        $v = get_option( 'googlesitekit_' . $name, array() );
        return is_array( $v ) ? $v : array();
    }

    /**
     * Everything the site thinks it is running. Each row: key, label, status
     * (ok|warn|bad|off), detail, ids (what should appear on the page).
     */
    public static function detect() {
        $rows = array();
        $add  = function ( $key, $label, $status, $detail, $ids = array() ) use ( &$rows ) {
            $rows[ $key ] = compact( 'key', 'label', 'status', 'detail', 'ids' );
        };

        // Google Site Kit.
        $sk_on = class_exists( 'AceSEOSiteKit' ) && AceSEOSiteKit::is_active();
        $modules = (array) get_option( 'googlesitekit_active_modules', array() );
        $a4 = self::sitekit_option( 'analytics-4_settings' );
        $tm = self::sitekit_option( 'tagmanager_settings' );
        $ads = self::sitekit_option( 'ads_settings' );
        $consent = self::sitekit_option( 'consent_mode' );
        $sharing = self::sitekit_option( 'dashboard_sharing' );
        $owner = (int) get_option( 'googlesitekit_owner_id', 0 );
        $connected = (bool) get_option( 'googlesitekit_has_connected_admins', false );
        if ( ! $sk_on ) {
            $add( 'sitekit', 'Google Site Kit', 'off', 'Not installed or not active on this site.' );
        } else {
            $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
            $token_ok = ! is_wp_error( $token ) && $token;
            $detail = 'Modules: ' . ( $modules ? implode( ', ', $modules ) : 'none' ) . '. ';
            $detail .= $owner ? 'Owner: user ' . $owner . '. ' : '';
            $detail .= $connected ? 'A connected admin exists. ' : 'No admin is connected. ';
            $detail .= $token_ok ? 'Analytics read token available.' : 'No usable Analytics token: ' . ( is_wp_error( $token ) ? $token->get_error_message() : 'none' );
            $add( 'sitekit', 'Google Site Kit', $connected && $token_ok ? 'ok' : 'warn', $detail );
        }

        // Google Analytics tag (Site Kit).
        $ga_ids = array_values( array_filter( array( $a4['googleTagID'] ?? '', $a4['measurementID'] ?? '' ) ) );
        if ( $sk_on && in_array( 'analytics-4', $modules, true ) && ! empty( $a4['propertyID'] ) ) {
            $place = ! empty( $a4['useSnippet'] );
            $expect = $place ? array( $ga_ids[0] ) : array();
            $add( 'ga4', 'Google Analytics 4 (Site Kit)', 'ok', 'Property ' . $a4['propertyID'] . ', stream ' . ( $a4['measurementID'] ?? '?' ) . ( ! empty( $a4['googleTagID'] ) ? ', Google tag ' . $a4['googleTagID'] : '' ) . ( $place ? '. Site Kit places the tag.' : '. Site Kit does NOT place the tag (another plugin must).' ), $expect );
            $rows['ga4']['alternative_ids'] = $place ? $ga_ids : array();
        } elseif ( $sk_on ) {
            $add( 'ga4', 'Google Analytics 4 (Site Kit)', 'off', 'Analytics module not connected.' );
        }

        // Google Tag Manager.
        if ( $sk_on && in_array( 'tagmanager', $modules, true ) && ! empty( $tm['containerID'] ) ) {
            $add( 'gtm', 'Google Tag Manager (Site Kit)', 'ok', 'Container ' . $tm['containerID'] . ', account ' . ( $tm['accountID'] ?? '?' ) . ( ! empty( $tm['useSnippet'] ) ? '. Site Kit places the snippet.' : '. Snippet placement off.' ), ! empty( $tm['useSnippet'] ) ? array( $tm['containerID'] ) : array() );
        } elseif ( self::plugin_on( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' ) ) {
            $o = (array) get_option( 'gtm4wp-options', array() );
            $add( 'gtm', 'Google Tag Manager (GTM4WP)', 'ok', 'Container ' . ( $o['gtm-code'] ?? '?' ), array_filter( array( $o['gtm-code'] ?? '' ) ) );
        }

        // Google Ads.
        if ( $sk_on && in_array( 'ads', $modules, true ) ) {
            $cid = (string) ( $ads['conversionID'] ?? '' );
            $add( 'ads', 'Google Ads (Site Kit)', $cid ? 'ok' : 'warn', $cid ? 'Conversion ID ' . $cid : 'Ads module is on but has no conversion ID saved, so it places nothing.', array_filter( array( $cid ) ) );
        }
        if ( self::plugin_on( 'google-listings-and-ads/google-listings-and-ads.php' ) ) {
            $gla = (string) get_option( 'gla_ads_id', '' );
            $add( 'gla', 'Google for WooCommerce', $gla ? 'ok' : 'warn', $gla ? 'Ads account ' . $gla . '.' : 'Active; no Ads account linked yet.' );
        }

        // Consent.
        if ( $sk_on ) {
            $on = ! empty( $consent['enabled'] );
            $add( 'consent', 'Consent mode (Site Kit)', $on ? 'ok' : 'warn', $on ? count( (array) ( $consent['regions'] ?? array() ) ) . ' regions default to denied until the banner answers.' : 'Site Kit does not manage consent defaults. Another banner or integration may provide them; check the page and browser consent behaviour.' );
        }
        $add( 'wp_consent_api', 'WP Consent API', function_exists( 'wp_has_consent' ) ? 'ok' : 'off', function_exists( 'wp_has_consent' ) ? 'Present: cookie banners can hand their answer to Site Kit and other plugins.' : 'Not installed. Site Kit consent mode then relies on the banner updating Google itself.' );
        $banners = array(
            'cookie-law-info/cookie-law-info.php'      => 'CookieYes',
            'complianz-gdpr/complianz-gpdr.php'        => 'Complianz',
            'cookiebot/cookiebot.php'                  => 'Cookiebot',
            'beautiful-and-responsive-cookie-consent/nsc_bar-cookie-consent.php' => 'Beautiful Cookie Consent',
            'gdpr-cookie-compliance/moove-gdpr.php'    => 'GDPR Cookie Compliance',
        );
        $banner = '';
        foreach ( $banners as $file => $name ) {
            if ( self::plugin_on( $file ) ) {
                $banner = $name;
                break;
            }
        }
        if ( 'CookieYes' === $banner ) {
            $gcm = (array) get_option( 'cky_gcm_settings', array() );
            $add( 'banner', 'Cookie banner: CookieYes', 'ok', ! empty( $gcm['status'] ) ? 'Google Consent Mode integration on (its own default: ' . ( $gcm['default_settings'][0]['analytics'] ?? '?' ) . ' for ' . ( $gcm['default_settings'][0]['regions'] ?? '?' ) . ' regions).' : 'Google Consent Mode integration is OFF in CookieYes.' );
        } elseif ( $banner ) {
            $add( 'banner', 'Cookie banner: ' . $banner, 'ok', 'Active.' );
        } else {
            $add( 'banner', 'Cookie banner', 'off', 'No known consent banner plugin is active.' );
        }

        // Microsoft Clarity.
        if ( self::plugin_on( 'microsoft-clarity/clarity.php' ) ) {
            $pid = (string) get_option( 'clarity_project_id', '' );
            $add( 'clarity', 'Microsoft Clarity', $pid ? 'ok' : 'warn', $pid ? 'Project ' . $pid : 'Plugin active but no project ID saved.', array_filter( array( $pid ) ) );
        }

        // Jetpack Stats.
        $jp_stats = class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && Jetpack::is_module_active( 'stats' );
        $add( 'jetpack', 'Jetpack Stats', $jp_stats ? 'ok' : 'off', $jp_stats ? 'Counting on WordPress.com; used here as the independent check on Analytics.' : 'Not active, so there is no second counter to compare Analytics against.' );

        // WooCommerce and its tags.
        if ( class_exists( 'WooCommerce' ) ) {
            $add( 'woo', 'WooCommerce', 'ok', 'Version ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . '. Orders and revenue are recorded regardless of any tag.' );
            if ( self::plugin_on( 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' ) ) {
                $w = (array) get_option( 'woocommerce_google_analytics_settings', array() );
                $gid = (string) ( $w['ga_id'] ?? '' );
                $add( 'woo_ga', 'WooCommerce Google Analytics', $gid ? 'ok' : 'warn', $gid ? 'Measurement ID ' . $gid . '. Check its destination against Site Kit: multiple page-view events reaching the same property can double count page views.' : 'Active with no measurement ID.', array_filter( array( $gid ) ) );
            }
            if ( self::plugin_on( 'facebook-for-woocommerce/facebook-for-woocommerce.php' ) ) {
                $px = (string) get_option( 'wc_facebook_pixel_id', '' );
                $add( 'fb', 'Facebook for WooCommerce', $px ? 'ok' : 'warn', $px ? 'Pixel ' . $px : 'Active with no pixel ID.', array_filter( array( $px ) ) );
            }
        }

        // Other analytics plugins that place their own tag.
        $others = array(
            'google-analytics-for-wordpress/googleanalytics.php' => 'MonsterInsights',
            'ga-google-analytics/ga-google-analytics.php'        => 'GA Google Analytics',
            'pixelyoursite/pixelyoursite.php'                    => 'PixelYourSite',
            'matomo/matomo.php'                                  => 'Matomo',
            'plausible-analytics/plausible-analytics.php'        => 'Plausible',
            'hotjar/hotjar.php'                                  => 'Hotjar',
            'independent-analytics/iawp.php'                     => 'Independent Analytics',
            'burst-statistics/burst.php'                         => 'Burst Statistics',
            'koko-analytics/koko-analytics.php'                  => 'Koko Analytics',
            'wp-statistics/wp-statistics.php'                    => 'WP Statistics',
        );
        foreach ( $others as $file => $name ) {
            if ( self::plugin_on( $file ) ) {
                $add( 'other_' . sanitize_key( $name ), $name, 'ok', 'Active. Places its own tracking; check it is not a second copy of a tag above.' );
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------ page probe

    /**
     * Fetch static home-page HTML. This cannot verify JavaScript or consent behaviour.
     */
    public static function probe_page( $force = false ) {
        if ( ! $force ) {
            $c = get_transient( self::T_PAGE );
            if ( is_array( $c ) && self::PROBE_VERSION === ( $c['probe_version'] ?? null ) ) {
                return $c;
            }
        }
        $url = add_query_arg( 'ace_nocache', (string) time(), home_url( '/' ) );
        $r = wp_remote_get( $url, array(
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 AceSEO-tracking-check',
            'sslverify'  => ! ( defined( 'WP_LOCAL_DEV' ) && WP_LOCAL_DEV ),
            'headers'    => array( 'Cache-Control' => 'no-cache', 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $r ) ) {
            $out = array( 'ok' => false, 'state' => 'unverified', 'error' => $r->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $r );
            $out = self::page_response( $code, (string) wp_remote_retrieve_body( $r ), array(
                'content-type' => wp_remote_retrieve_header( $r, 'content-type' ),
                'x-amzn-waf-action' => wp_remote_retrieve_header( $r, 'x-amzn-waf-action' ),
                'cf-mitigated' => wp_remote_retrieve_header( $r, 'cf-mitigated' ),
            ) );
        }
        $out['probe_version'] = self::PROBE_VERSION;
        $out['at'] = time();
        set_transient( self::T_PAGE, $out, HOUR_IN_SECONDS );
        return $out;
    }

    /** Pure: a successful HTTP status alone does not mean we received the site. */
    public static function page_response( $code, $html, array $headers = array() ) {
        $headers = array_change_key_case( $headers, CASE_LOWER );
        $code = (int) $code;
        $error = '';
        if ( in_array( strtolower( (string) ( $headers['x-amzn-waf-action'] ?? '' ) ), array( 'challenge', 'captcha' ), true )
            || 'challenge' === strtolower( (string) ( $headers['cf-mitigated'] ?? '' ) )
            || preg_match( '~(?:<title>\s*(?:Just a moment|Attention Required!\s*\|\s*Cloudflare)|id=[\'"]challenge-form[\'"])~i', $html ) ) {
            $error = 'The site security protection challenged the check (HTTP ' . $code . ').';
        } elseif ( 200 !== $code ) {
            $error = 'The check did not receive a complete home page (HTTP ' . $code . ').';
        } elseif ( '' === trim( $html ) ) {
            $error = 'The check received an empty response.';
        } elseif ( ! empty( $headers['content-type'] ) && ! preg_match( '~^(?:text/html|application/xhtml\+xml)(?:\s*;|\s*$)~i', (string) $headers['content-type'] ) ) {
            $error = 'The check received a non-HTML response.';
        } elseif ( ! preg_match( '~<html(?:\s|>)~i', $html ) || ! preg_match( '~<body(?:\s|>)~i', $html )
            || false === stripos( $html, '</body>' ) || false === stripos( $html, '</html>' ) ) {
            $error = 'The check did not receive a complete HTML document.';
        }
        // Do not scan a challenge or partial response for missing or present tags.
        $out = self::scan_html( '' === $error ? $html : '' );
        return $out + array( 'ok' => '' === $error, 'state' => '' === $error ? 'html' : 'unverified', 'error' => $error, 'http' => $code );
    }

    public static function tag_found( $id, array $page ) {
        return in_array( $id, array_merge( $page['loaders'] ?? array(), $page['configs'] ?? array(), $page['gtm'] ?? array(), array( $page['clarity'] ?? '', $page['fb_pixel'] ?? '' ) ), true );
    }

    /** Recent positive reporting is separate evidence, never proof of every tag or visit. */
    public static function recent_reporting( array $series ) {
        if ( empty( $series['ga']['ok'] ) || ( isset( $series['at'] ) && $series['at'] < time() - 2 * 86400 ) ) { return null; }
        $today = wp_date( 'Y-m-d' );
        $since = ( new DateTimeImmutable( $today ) )->modify( '-2 days' )->format( 'Y-m-d' );
        $latest = null;
        foreach ( $series['days'] ?? array() as $day ) {
            $date = $day['date'] ?? '';
            if ( $date >= $since && $date <= $today && ( ( $day['ga_views'] ?? 0 ) > 0 || ( $day['ga_users'] ?? 0 ) > 0 ) && ( null === $latest || $date > $latest ) ) { $latest = $date; }
        }
        return $latest;
    }

    /** The page and reporting checks must not make claims about each other's coverage. */
    public static function summary( array $page, array $series, array $problems ) {
        $levels = array_column( $problems, 0 );
        if ( in_array( 'bad', $levels, true ) ) { return array( 'bad', 'Tracking needs attention.' ); }
        if ( self::recent_reporting( $series ) ) {
            return array( empty( $page['ok'] ) || in_array( 'warn', $levels, true ) ? 'warn' : 'ok', empty( $page['ok'] )
                ? 'Analytics data is arriving. Automatic tag verification is unavailable.'
                : 'Analytics data is arriving. HTML tag checks do not verify every tag or visit.' );
        }
        if ( empty( $page['ok'] ) ) { return array( 'warn', 'Automatic tag verification is unavailable.' ); }
        if ( in_array( 'warn', $levels, true ) ) { return array( 'warn', 'Some tracking checks need browser verification.' ); }
        return array( 'ok', 'Static HTML check completed. Browser execution has not been verified.' );
    }

    /**
     * Pure: what tracking is actually in a page's HTML.
     */
    public static function scan_html( $html ) {
        $o = array( 'loaders' => array(), 'configs' => array(), 'gtm' => array(), 'clarity' => '', 'fb_pixel' => '', 'consent_default' => false, 'consent_regions' => 0, 'banner' => '', 'wp_consent_api' => false, 'others' => array() );
        $html = (string) $html;
        if ( preg_match_all( '#googletagmanager\.com(?:\\\\?/)gtag(?:\\\\?/)js\?id=([A-Z]{1,3}-[A-Z0-9]+)#', $html, $m ) ) {
            $o['loaders'] = array_values( array_unique( $m[1] ) );
        }
        if ( preg_match_all( '#gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]([A-Z]{1,3}-[A-Z0-9]+)[\'"]#', $html, $m ) ) {
            $o['configs'] = array_values( array_unique( $m[1] ) );
        }
        if ( preg_match_all( '#(GTM-[A-Z0-9]+)#', $html, $m ) ) {
            $o['gtm'] = array_values( array_unique( $m[1] ) );
        }
        if ( preg_match( '#clarity\.ms/tag/([a-z0-9]+)#i', $html, $m ) || preg_match( '#"clarity"\s*,\s*"script"\s*,\s*"([a-z0-9]+)"#i', $html, $m ) ) {
            $o['clarity'] = $m[1];
        }
        if ( preg_match( '#fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d+)[\'"]#', $html, $m ) ) {
            $o['fb_pixel'] = $m[1];
        }
        if ( preg_match_all( '#gtag\(\s*[\'"]consent[\'"]\s*,\s*[\'"]default[\'"]\s*,\s*(\{.{0,2000}?\})\s*\)#s', $html, $m ) ) {
            $o['consent_default'] = true;
            foreach ( $m[1] as $json ) {
                if ( preg_match( '#"region"\s*:\s*\[([^\]]*)\]#', $json, $rm ) ) {
                    $o['consent_regions'] = max( $o['consent_regions'], substr_count( $rm[1], '"' ) / 2 );
                }
            }
        }
        $o['wp_consent_api'] = false !== strpos( $html, 'wp-consent-api' );
        foreach ( array( 'ace-consent-gate' => 'Theme consent banner', 'cookieyes' => 'CookieYes', 'cmplz' => 'Complianz', 'Cookiebot' => 'Cookiebot', 'moove_gdpr' => 'GDPR Cookie Compliance' ) as $needle => $name ) {
            if ( false !== strpos( $html, $needle ) ) {
                $o['banner'] = $name;
                break;
            }
        }
        foreach ( array( 'static.hotjar.com' => 'Hotjar', 'plausible.io' => 'Plausible', 'matomo.js' => 'Matomo', 'stats.wp.com' => 'Jetpack Stats', 'connect.facebook.net' => 'Meta Pixel', 'snap.licdn.com' => 'LinkedIn Insight', 'analytics.tiktok.com' => 'TikTok Pixel', 'static.ads-twitter.com' => 'X Pixel', 'bat.bing.com' => 'Microsoft Ads UET' ) as $needle => $name ) {
            if ( false !== strpos( $html, $needle ) ) {
                $o['others'][] = $name;
            }
        }
        return $o;
    }

    // ------------------------------------------------------------ series

    public static function series( $force = false ) {
        if ( ! $force ) {
            $c = get_transient( self::T_SERIES );
            if ( is_array( $c ) ) {
                return $c;
            }
        }
        $tz = wp_timezone();
        $end = new DateTime( 'today', $tz );
        $start = ( clone $end )->modify( '-' . ( self::DAYS - 1 ) . ' days' );
        $days = array();
        for ( $d = clone $start; $d <= $end; $d->modify( '+1 day' ) ) {
            $days[ $d->format( 'Y-m-d' ) ] = array( 'date' => $d->format( 'Y-m-d' ), 'ga_users' => null, 'ga_views' => null, 'jp_visitors' => null, 'jp_views' => null );
        }
        $out = array( 'days' => $days, 'ga' => array( 'ok' => false, 'error' => '' ), 'jp' => array( 'ok' => false, 'error' => '' ), 'at' => time() );

        // GA4 via the Site Kit token bridge.
        $a4 = self::sitekit_option( 'analytics-4_settings' );
        if ( class_exists( 'AceSEOSiteKit' ) && AceSEOSiteKit::is_active() && ! empty( $a4['propertyID'] ) ) {
            $token = AceSEOSiteKit::get_access_token( array( AceSEOSiteKit::SCOPE_ANALYTICS ) );
            if ( is_wp_error( $token ) ) {
                $out['ga']['error'] = $token->get_error_message();
            } else {
                $body = array(
                    'dateRanges' => array( array( 'startDate' => $start->format( 'Y-m-d' ), 'endDate' => $end->format( 'Y-m-d' ) ) ),
                    'dimensions' => array( array( 'name' => 'date' ) ),
                    'metrics'    => array( array( 'name' => 'activeUsers' ), array( 'name' => 'screenPageViews' ) ),
                    'limit'      => 400,
                );
                $r = wp_remote_post( 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $a4['propertyID'] ) . ':runReport', array(
                    'timeout' => 20,
                    'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( $body ),
                ) );
                if ( is_wp_error( $r ) ) {
                    $out['ga']['error'] = $r->get_error_message();
                } else {
                    $j = json_decode( wp_remote_retrieve_body( $r ), true );
                    if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
                        $out['ga']['error'] = $j['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
                    } else {
                        foreach ( $days as $k => $row ) {
                            $out['days'][ $k ]['ga_users'] = 0;
                            $out['days'][ $k ]['ga_views'] = 0;
                        }
                        foreach ( (array) ( $j['rows'] ?? array() ) as $row ) {
                            $d = $row['dimensionValues'][0]['value'] ?? '';
                            $k = substr( $d, 0, 4 ) . '-' . substr( $d, 4, 2 ) . '-' . substr( $d, 6, 2 );
                            if ( isset( $out['days'][ $k ] ) ) {
                                $out['days'][ $k ]['ga_users'] = (int) ( $row['metricValues'][0]['value'] ?? 0 );
                                $out['days'][ $k ]['ga_views'] = (int) ( $row['metricValues'][1]['value'] ?? 0 );
                            }
                        }
                        $out['ga']['ok'] = true;
                    }
                }
            }
        } else {
            $out['ga']['error'] = 'Site Kit Analytics is not connected.';
        }

        // Jetpack Stats.
        if ( class_exists( 'Automattic\\Jetpack\\Stats\\WPCOM_Stats' ) && class_exists( 'Jetpack' ) && Jetpack::is_module_active( 'stats' ) ) {
            $r = ( new Automattic\Jetpack\Stats\WPCOM_Stats() )->get_visits( array( 'unit' => 'day', 'quantity' => self::DAYS ) );
            if ( is_wp_error( $r ) ) {
                $out['jp']['error'] = $r->get_error_message();
            } else {
                $r = json_decode( wp_json_encode( $r ), true );
                $fields = array_flip( (array) ( $r['fields'] ?? array() ) );
                foreach ( (array) ( $r['data'] ?? array() ) as $row ) {
                    $k = (string) ( $row[ $fields['period'] ?? 0 ] ?? '' );
                    if ( isset( $out['days'][ $k ] ) ) {
                        $out['days'][ $k ]['jp_visitors'] = (int) ( $row[ $fields['visitors'] ?? 2 ] ?? 0 );
                        $out['days'][ $k ]['jp_views'] = (int) ( $row[ $fields['views'] ?? 1 ] ?? 0 );
                    }
                }
                $out['jp']['ok'] = true;
            }
        } else {
            $out['jp']['error'] = 'Jetpack Stats is not active.';
        }

        set_transient( self::T_SERIES, $out, HOUR_IN_SECONDS );
        return $out;
    }

    /**
     * Pure: compare the two counters. A gap day is one where the independent
     * counter saw real traffic and Analytics saw nothing; without Jetpack, a day
     * where Analytics reports zero after a healthy fortnight.
     */
    public static function analyse( array $days, $today = null ) {
        $today = $today ?: wp_date( 'Y-m-d' );
        $has_jp = false;
        foreach ( $days as $d ) {
            if ( null !== ( $d['jp_visitors'] ?? null ) ) {
                $has_jp = true;
                break;
            }
        }
        $gap = array();
        $ga_sum = $jp_sum = 0;
        $ratio_ga = $ratio_jp = 0;
        $recent = array();
        foreach ( $days as $d ) {
            if ( $d['date'] >= $today ) {
                continue; // today is incomplete
            }
            $gu = $d['ga_users'] ?? null;
            if ( null === $gu ) {
                continue;
            }
            if ( $has_jp ) {
                $jv = (int) $d['jp_visitors'];
                $ga_sum += $gu;
                $jp_sum += $jv;
                if ( $jv >= self::MIN_TRAFFIC && $gu < max( 1, $jv * 0.1 ) ) {
                    $gap[] = $d['date'];
                } elseif ( $jv > 0 && $gu > 0 ) {
                    $ratio_ga += $gu;
                    $ratio_jp += $jv;
                }
            } else {
                $recent[] = $gu;
                $n = count( $recent );
                if ( $n > 14 ) {
                    $avg = array_sum( array_slice( $recent, $n - 15, 14 ) ) / 14;
                    if ( 0 === $gu && $avg >= self::MIN_TRAFFIC ) {
                        $gap[] = $d['date'];
                    }
                }
            }
        }
        $ratio = $ratio_jp > 0 ? $ratio_ga / $ratio_jp : null;
        $missing = 0;
        if ( $has_jp && null !== $ratio ) {
            foreach ( $days as $d ) {
                if ( in_array( $d['date'], $gap, true ) ) {
                    $missing += (int) round( (int) $d['jp_visitors'] * $ratio );
                }
            }
        }
        $yesterday = ( new DateTime( $today ) )->modify( '-1 day' )->format( 'Y-m-d' );
        $day_before = ( new DateTime( $today ) )->modify( '-2 days' )->format( 'Y-m-d' );
        return array(
            'has_jp'        => $has_jp,
            'gap_days'      => $gap,
            'ongoing'       => in_array( $yesterday, $gap, true ) && in_array( $day_before, $gap, true ),
            'ga_total'      => $ga_sum,
            'jp_total'      => $jp_sum,
            'ratio'         => $ratio,
            'missing_users' => $missing,
        );
    }

    /**
     * What is wrong right now. Each problem: [level, text]. Levels: bad, warn.
     */
    public static function problems( array $detect, array $page, array $series ) {
        $p = array();
        if ( ! empty( $page['ok'] ) ) {
            foreach ( $page['loaders'] as $id ) {
                if ( ! in_array( $id, $page['configs'], true ) && 0 !== strpos( $id, 'GTM-' ) ) {
                    $p[] = array( 'warn', 'The Google tag ' . $id . ' has a loader in the home-page HTML, but no matching inline configuration was found. It may be configured by JavaScript, Tag Manager or after consent. Verify it in a browser.' );
                }
            }
            foreach ( $detect as $row ) {
                foreach ( $row['ids'] as $id ) {
                    $on_page = self::tag_found( $id, $page );
                    foreach ( $row['alternative_ids'] ?? array() as $alternative ) {
                        $on_page = $on_page || self::tag_found( $alternative, $page );
                    }
                    if ( ! $on_page ) {
                        $p[] = array( 'warn', $row['label'] . ' is configured for ' . $id . ', but it was not found in the home-page HTML. JavaScript, Tag Manager or consent may load it later. Browser verification is needed.' );
                    }
                }
            }
            $ga_tags = array_values( array_filter( $page['configs'], function ( $id ) {
                return 0 === strpos( $id, 'G-' ) || 0 === strpos( $id, 'GT-' );
            } ) );
            if ( count( $ga_tags ) > 1 ) {
                $p[] = array( 'warn', 'Multiple Google Analytics tags are configured on the page (' . implode( ', ', $ga_tags ) . '). Check their destinations and page-view events: the same property may receive duplicate events; different properties report separately.' );
            }
            if ( ! empty( $detect['consent'] ) && 'ok' === $detect['consent']['status'] && empty( $page['consent_default'] ) ) {
                $p[] = array( 'warn', 'Consent mode is on in Site Kit but no consent default was found on the home page.' );
            }
        } elseif ( isset( $page['ok'] ) ) {
            $p[] = array( 'warn', 'Automatic tag verification is unavailable: ' . ( $page['error'] ?? ( 'HTTP ' . ( $page['http'] ?? '?' ) ) ) . ' This does not establish a tracking failure.' );
        }
        if ( ! empty( $series['ga']['ok'] ) ) {
            $a = self::analyse( $series['days'] );
            if ( $a['ongoing'] ) {
                $p[] = array( 'bad', $a['has_jp'] ? 'Google Analytics has reported almost nothing for the last two days while Jetpack counted visitors.' : 'Google Analytics has reported nothing for the last two days after a normal fortnight.' );
            } elseif ( $a['gap_days'] ) {
                $p[] = array( 'warn', 'Google Analytics missed ' . count( $a['gap_days'] ) . ' day' . ( 1 === count( $a['gap_days'] ) ? '' : 's' ) . ' in the last ' . self::DAYS . ' (last: ' . end( $a['gap_days'] ) . ').' );
            }
        } elseif ( ! empty( $detect['ga4'] ) && 'ok' === $detect['ga4']['status'] ) {
            $p[] = array( 'warn', 'Analytics is connected but its report could not be read: ' . ( $series['ga']['error'] ?? 'report unavailable' ) );
        }
        $ads = self::google_ads( $detect, $page );
        if ( $ads && ! empty( $page['ok'] ) && $ads['configured'] && ! $ads['tag_found'] ) {
            $p[] = array( 'warn', 'Google Ads has a configured provider, but no AW- tag configuration was found on the home page.' );
        }
        foreach ( $detect as $row ) {
            if ( 'consent' === $row['key'] && ! empty( $page['ok'] ) && ! empty( $page['consent_default'] ) ) {
                continue; // Consent may be provided outside Site Kit.
            }
            if ( 'ads' === $row['key'] && empty( $row['ids'] ) && $ads && $ads['tag_found'] && in_array( 'gla', $ads['configured'], true ) ) {
                continue; // Site Kit is an unused alternative to the configured WooCommerce provider.
            }
            if ( 'warn' === $row['status'] ) {
                $p[] = array( 'warn', $row['label'] . ': ' . $row['detail'] );
            }
        }
        return $p;
    }

    /** Group alternative Google Ads providers without treating both as required. */
    public static function google_ads( $detect, $page ) {
        $providers = array_intersect_key( $detect, array_flip( array( 'ads', 'gla' ) ) );
        if ( ! $providers ) {
            return null;
        }
        $configured = array_keys( array_filter( $providers, function ( $row ) { return 'ok' === $row['status']; } ) );
        $tag_found = ! empty( $page['ok'] ) && (bool) array_filter( $page['configs'] ?? array(), function ( $id ) { return 0 === strpos( $id, 'AW-' ); } );
        return compact( 'providers', 'configured', 'tag_found' );
    }

    // ------------------------------------------------------------ cron

    public static function run() {
        AceSeoTrackingClarity::snapshot();
        $detect = self::detect();
        $page = self::probe_page( true );
        $series = self::series( true );
        $problems = self::problems( $detect, $page, $series );
        $bad = array_filter( $problems, function ( $x ) {
            return 'bad' === $x[0];
        } );
        $prev = (array) get_option( self::OPT_ALERTS, array() );
        $key = md5( wp_json_encode( array_values( $bad ) ) );
        $alerts = array( 'at' => time(), 'problems' => $problems, 'check_version' => self::PROBE_VERSION );
        // Keep the delivery marker while this fault persists; clear it on
        // recovery or a changed fault so a recurrence can notify again.
        if ( $bad && ( $prev['emailed'] ?? '' ) === $key ) {
            $alerts['emailed'] = $key;
        }
        update_option( self::OPT_ALERTS, $alerts, false );
        if ( $bad && get_option( self::OPT_EMAIL, 1 ) && ( $prev['emailed'] ?? '' ) !== $key ) {
            $lines = array_map( function ( $x ) {
                return '- ' . $x[1];
            }, $bad );
            $sent = wp_mail(
                get_option( 'admin_email' ),
                sprintf( '[%s] Tracking problem: %d issue%s need attention', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), count( $bad ), 1 === count( $bad ) ? '' : 's' ),
                "Ace SEO found these on its daily tracking check:\n\n" . implode( "\n", $lines ) . "\n\nDetails: " . admin_url( 'admin.php?page=' . self::SLUG ) . "\n"
            );
            if ( $sent ) {
                update_option( self::OPT_ALERTS, array( 'at' => time(), 'problems' => $problems, 'emailed' => $key, 'check_version' => self::PROBE_VERSION ), false );
            }
        }
        return $problems;
    }

    public static function notice() {
        if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) ) {
            return;
        }
        $a = (array) get_option( self::OPT_ALERTS, array() );
        if ( $a && self::PROBE_VERSION !== ( $a['check_version'] ?? null ) ) {
            echo '<div class="notice notice-info"><p>Tracking checks have changed. Previous results need verification. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Open Tracking</a></p></div>';
            return;
        }
        $bad = array_filter( (array) ( $a['problems'] ?? array() ), function ( $x ) {
            return 'bad' === $x[0];
        } );
        if ( ! $bad ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Tracking problem.</strong> ' . esc_html( reset( $bad )[1] ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Open Tracking</a></p></div>';
    }

    // ------------------------------------------------------------ admin actions

    public static function menu() {
        add_submenu_page( 'ace-seo', 'Tracking', 'Tracking', 'manage_options', self::SLUG, array( __CLASS__, 'render_admin' ) );
    }

    public static function assets() {
        if ( sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) !== self::SLUG ) { return; }
        wp_enqueue_style( 'ace-seo-tracking', ACE_SEO_URL . 'assets/css/tracking.css', array(), ACE_SEO_VERSION );
        wp_enqueue_script( 'ace-seo-tracking', ACE_SEO_URL . 'assets/js/tracking.js', array(), ACE_SEO_VERSION, true );
    }

    public static function admin_refresh() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'ace_seo_tracking_refresh' );
        delete_transient( self::T_PAGE );
        delete_transient( self::T_SERIES );
        delete_transient( AceSeoTrackingCommerce::CACHE );
        delete_transient( AceSeoTrackingGoogle::CACHE );
        if ( class_exists( 'AceSEOSiteKit' ) ) {
            AceSEOSiteKit::clear_token_cache();
        }
        self::run();
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&refreshed=1' ) );
        exit;
    }

    public static function admin_email_toggle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'ace_seo_tracking_email' );
        update_option( self::OPT_EMAIL, empty( $_POST['on'] ) ? 0 : 1, false );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
        exit;
    }

    public static function admin_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'ace_seo_tracking_csv' );
        $s = self::series();
        $commerce = AceSeoTrackingCommerce::series( array_keys( $s['days'] ) );
        $google = AceSeoTrackingGoogle::series( array_keys( $s['days'] ) );
        $google = AceSeoAggregateViews::enrich( $google, array_keys( $s['days'] ) );
        $clarity = AceSeoTrackingClarity::history();
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="tracking-' . sanitize_file_name( $host ) . '-' . wp_date( 'Y-m-d' ) . '.csv"' );
        $fh = fopen( 'php://output', 'w' );
        $columns = array( 'date', 'ga4_active_users', 'ga4_page_views', 'jetpack_visitors', 'jetpack_views' );
        if ( $commerce['ok'] ) { $columns = array_merge( $columns, array( 'orders', 'order_value_after_refunds', 'currency' ) ); }
        foreach ( $google['definitions'] as $definition ) { $columns[] = $definition['label']; }
        foreach ( AceSeoTrackingClarity::definitions() as $definition ) { $columns[] = 'Clarity ' . $definition[0] . ' (24h)'; }
        $columns[] = 'Clarity window start UTC'; $columns[] = 'Clarity window end UTC';
        fputcsv( $fh, $columns );
        foreach ( $s['days'] as $d ) {
            $row = array( $d['date'], $d['ga_users'] ?? '', $d['ga_views'] ?? '', $d['jp_visitors'] ?? '', $d['jp_views'] ?? '' );
            if ( $commerce['ok'] ) { $row = array_merge( $row, array( $commerce['days'][ $d['date'] ]['wc_orders'], $commerce['days'][ $d['date'] ]['wc_value'], $commerce['currency'] ) ); }
            foreach ( $google['definitions'] as $key => $definition ) { $row[] = $google['days'][ $d['date'] ][ $key ] ?? ''; }
            $saved = $clarity[ $d['date'] ] ?? array();
            foreach ( AceSeoTrackingClarity::definitions() as $key => $definition ) { $row[] = $saved['values'][ $key ] ?? ''; }
            $row[] = isset( $saved['at'] ) ? gmdate( 'c', $saved['at'] - 86400 ) : '';
            $row[] = isset( $saved['at'] ) ? gmdate( 'c', $saved['at'] ) : '';
            fputcsv( $fh, $row );
        }
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output CSV stream, not a filesystem path.
        exit;
    }

    // ------------------------------------------------------------ page

    public static function render_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $metric = ( isset( $_GET['metric'] ) && 'views' === $_GET['metric'] ) ? 'views' : 'visitors';
        $detect = self::detect();
        $page = self::probe_page();
        if ( ! empty( $page['ok'] ) && ! empty( $page['consent_default'] ) && isset( $detect['consent'] ) && 'ok' !== $detect['consent']['status'] ) {
            $detect['consent']['label'] = 'Consent mode';
            $detect['consent']['status'] = 'ok';
            $detect['consent']['detail'] = 'Consent defaults are present on the page, managed outside Site Kit. Verify acceptance and rejection in a browser.';
        }
        if ( 'Theme consent banner' === ( $page['banner'] ?? '' ) && ! empty( $page['ok'] ) ) {
            $detect['banner'] = array( 'key' => 'banner', 'label' => 'Cookie banner', 'status' => 'ok', 'detail' => 'The theme provides the consent banner and sends Google consent updates directly.', 'ids' => array() );
        }
        $series = self::series();
        $commerce = AceSeoTrackingCommerce::series( array_keys( $series['days'] ) );
        $google = AceSeoTrackingGoogle::series( array_keys( $series['days'] ) );
        $google = AceSeoAggregateViews::enrich( $google, array_keys( $series['days'] ) );
        if ( isset( $detect['clarity'] ) ) { AceSeoTrackingClarity::snapshot(); }
        $problems = self::problems( $detect, $page, $series );
        $a = ! empty( $series['ga']['ok'] ) ? self::analyse( $series['days'] ) : null;
        $email_on = (bool) get_option( self::OPT_EMAIL, 1 );
        $next = wp_next_scheduled( self::CRON );

        echo '<style>.ace-trk .pill{display:inline-block;font:600 11px/1 sans-serif;letter-spacing:.04em;text-transform:uppercase;padding:5px 8px;border-radius:999px;white-space:nowrap}.ace-trk .pill-ok{background:#e6f4ea;color:#137333}.ace-trk .pill-warn{background:#fef3e0;color:#9a5b00}.ace-trk .pill-bad{background:#fde8e6;color:#b3261e}.ace-trk .pill-off{background:#eceff1;color:#5f6b73}.ace-trk .box{background:#fff;border:1px solid #c3c4c7;border-left-width:4px;padding:12px 16px;margin:12px 0;max-width:1100px}.ace-trk .box-bad{border-left-color:#d63638}.ace-trk .box-warn{border-left-color:#dba617}.ace-trk .box-ok{border-left-color:#00a32a}.ace-trk .box ul{margin:6px 0 0 18px}.ace-trk .tiles{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0}.ace-trk .tile{background:#fff;border:1px solid #c3c4c7;padding:10px 14px;min-width:160px}.ace-trk .tile b{display:block;font-size:22px;line-height:1.2}.ace-trk .tile span{color:#50575e;font-size:12px}.ace-trk svg{max-width:1100px;width:100%;height:auto;display:block;background:#fff;border:1px solid #c3c4c7}.ace-trk .legend{font-size:13px;color:#50575e;margin:6px 0}.ace-trk .legend i{display:inline-block;width:14px;height:3px;vertical-align:middle;margin-right:4px}.ace-trk table.widefat{max-width:1100px}.ace-trk td.n{text-align:right;font-variant-numeric:tabular-nums}.ace-trk code{font-size:12px}</style>';
        echo '<div class="wrap ace-trk"><h1>Tracking</h1>';
        if ( ! empty( $_GET['refreshed'] ) ) {
            echo '<div class="notice notice-success"><p>Checks re-run.</p></div>';
        }
        echo '<p>Tracking configuration, evidence in home-page HTML, and reported traffic are separate checks. This server check does not execute JavaScript or accept consent. Checked daily at 05:10; next run ' . esc_html( $next ? wp_date( 'D j M H:i', $next ) : 'not scheduled' ) . '. Page probed ' . esc_html( ! empty( $page['at'] ) ? wp_date( 'D j M H:i', $page['at'] ) : 'never' ) . '.</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em">';
        wp_nonce_field( 'ace_seo_tracking_refresh' );
        echo '<input type="hidden" name="action" value="ace_seo_tracking_refresh"><button class="button">Re-run checks now</button></form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em">';
        wp_nonce_field( 'ace_seo_tracking_email' );
        echo '<input type="hidden" name="action" value="ace_seo_tracking_email"><input type="hidden" name="on" value="' . ( $email_on ? '0' : '1' ) . '"><button class="button">' . ( $email_on ? 'Email alerts: on (turn off)' : 'Email alerts: off (turn on)' ) . '</button></form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
        wp_nonce_field( 'ace_seo_tracking_csv' );
        echo '<input type="hidden" name="action" value="ace_seo_tracking_csv"><button class="button">Download daily numbers (CSV)</button></form>';

        // Problems.
        $bad = array_filter( $problems, function ( $x ) {
            return 'bad' === $x[0];
        } );
        $warn = array_filter( $problems, function ( $x ) {
            return 'warn' === $x[0];
        } );
        $summary = self::summary( $page, $series, $problems );
        echo '<div class="box box-' . esc_html( $summary[0] ) . '"><strong>' . esc_html( $summary[1] ) . '</strong>';
        if ( $bad ) {
            echo '<ul>';
            foreach ( $bad as $x ) {
                echo '<li>' . esc_html( $x[1] ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        $report_date = self::recent_reporting( $series );
        if ( $report_date ) {
            echo '<p>Google Analytics reports traffic for ' . esc_html( $report_date ) . '. This is evidence for the connected property; it does not verify every tag, page, consent choice or visit.</p>';
        } else {
            echo '<p>Recent Google Analytics traffic has not been established by this report. Missing or unavailable data is not proof of zero visitors.</p>';
        }
        if ( $warn ) {
            echo '<div class="box box-warn"><strong>Worth a look.</strong><ul>';
            foreach ( $warn as $x ) {
                echo '<li>' . esc_html( $x[1] ) . '</li>';
            }
            echo '</ul></div>';
        }

        AceSeoAggregateViews::settings();

        // Chart.
        if ( ! empty( $series['ga']['ok'] ) || ! empty( $series['jp']['ok'] ) || $commerce['ok'] || AceSeoTrackingClarity::history() || $google['definitions'] ) {
            $jp_on = ! empty( $series['jp']['ok'] );
            echo '<h2>Last ' . (int) self::DAYS . ' days: traffic' . ( $commerce['ok'] ? ' and sales' : '' ) . '</h2>';
            if ( $a ) {
                echo '<div class="tiles">';
                echo '<div class="tile"><b>' . esc_html( number_format_i18n( $a['ga_total'] ) ) . '</b><span>GA4 active users' . ( $jp_on ? ', complete days' : '' ) . '</span></div>';
                if ( $jp_on ) {
                    echo '<div class="tile"><b>' . esc_html( number_format_i18n( $a['jp_total'] ) ) . '</b><span>Jetpack visitors, same days</span></div>';
                    if ( null !== $a['ratio'] ) {
                        echo '<div class="tile"><b>' . esc_html( number_format( $a['ratio'], 2 ) ) . '</b><span>GA4 users per Jetpack visitor on healthy days</span></div>';
                    }
                }
                echo '<div class="tile"><b>' . esc_html( count( $a['gap_days'] ) ) . '</b><span>days Analytics missed' . ( $a['missing_users'] ? ', about ' . esc_html( number_format_i18n( $a['missing_users'] ) ) . ' users unrecorded' : '' ) . '</span></div>';
                echo '</div>';
            }
            echo '<p class="legend"><i style="background:#fde8e6;height:10px;border:1px dashed #d63638"></i>Possible Analytics gap</p>';
            self::render_chart( $series, $commerce, $metric, $a ? $a['gap_days'] : array(), $google );
            if ( ! empty( $series['ga']['error'] ) ) {
                echo '<p>Analytics: ' . esc_html( $series['ga']['error'] ) . '</p>';
            }
            if ( ! empty( $series['jp']['error'] ) ) {
                echo '<p>Jetpack: ' . esc_html( $series['jp']['error'] ) . '</p>';
            }
        } else {
            echo '<h2>Daily numbers</h2><p>No counter is readable yet. Analytics: ' . esc_html( $series['ga']['error'] ) . ' Jetpack: ' . esc_html( $series['jp']['error'] ) . '</p>';
        }
        if ( $commerce['enabled'] && ! $commerce['ok'] && ! empty( $commerce['error'] ) ) { echo '<p>WooCommerce: ' . esc_html( $commerce['error'] ) . '</p>'; }

        if ( $google['status'] ) {
            echo '<details class="ace-trk-google-status"><summary>Google reporting connections</summary><ul>';
            foreach ( $google['status'] as $name => $status ) { echo '<li><strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $status ) . '</li>'; }
            echo '</ul><a href="' . esc_url( admin_url( 'admin.php?page=googlesitekit-settings' ) ) . '">Site Kit settings</a></details>';
        }

        // Integrations.
        echo '<h2>Set up on this site</h2><table class="widefat striped"><thead><tr><th>Integration</th><th>Setup state</th><th>Details</th></tr></thead><tbody>';
        $ads = self::google_ads( $detect, $page );
        $ads_shown = false;
        foreach ( $detect as $row ) {
            if ( in_array( $row['key'], array( 'ads', 'gla' ), true ) ) {
                if ( $ads_shown ) { continue; }
                $ads_shown = true;
                AceSeoTrackingUI::ads( $ads );
                continue;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AceSeoTrackingUI and svg_chart() escape every dynamic value as they build the markup.
            echo '<tr><td><strong>' . esc_html( $row['label'] ) . '</strong></td><td><span class="pill pill-' . esc_html( $row['status'] ) . '">' . esc_html( array( 'ok' => 'On', 'warn' => 'Check', 'bad' => 'Broken', 'off' => 'Off' )[ $row['status'] ] ) . '</span></td><td>' . AceSeoTrackingUI::detail( $row['detail'] ) . '<span class="ace-trk-row-links">' . AceSeoTrackingUI::links( $row['key'], $row ) . '</span></td></tr>';
        }
        echo '</tbody></table>';
        if ( isset( $detect['clarity'] ) ) { AceSeoTrackingClarity::render(); }

        // On the page.
        echo '<h2>Found in home-page HTML</h2>';
        if ( empty( $page['ok'] ) ) {
            echo '<p>The home-page HTML could not be verified: ' . esc_html( $page['error'] ?? ( 'HTTP ' . ( $page['http'] ?? '?' ) ) ) . '</p>';
        } else {
            $found = array();
            foreach ( $page['loaders'] as $id ) {
                $found[] = array( 'Google tag loader', $id, in_array( $id, $page['configs'], true ) ? 'ok' : 'warn', in_array( $id, $page['configs'], true ) ? 'Loader and configuration found in HTML' : 'No matching inline configuration found; verify JavaScript, Tag Manager and consent in a browser' );
            }
            foreach ( $page['configs'] as $id ) {
                if ( ! in_array( $id, $page['loaders'], true ) ) {
                    $found[] = array( 'Google tag config', $id, 'ok', 'Configuration found in HTML; loader and browser execution need verification' );
                }
            }
            foreach ( $page['gtm'] as $id ) {
                $found[] = array( 'Tag Manager container', $id, 'ok', 'Container reference found in HTML; contained tags need browser verification' );
            }
            if ( $page['clarity'] ) {
                $found[] = array( 'Microsoft Clarity', $page['clarity'], 'ok', 'Clarity reference found in HTML; browser execution has not been verified' );
            }
            if ( $page['fb_pixel'] ) {
                $found[] = array( 'Meta Pixel', $page['fb_pixel'], 'ok', 'fbq init present' );
            }
            $found[] = array( 'Consent default', $page['consent_default'] ? ( $page['consent_regions'] ? $page['consent_regions'] . ' regions' : 'No region restriction found' ) : 'none', $page['consent_default'] ? 'ok' : 'off', $page['consent_default'] ? 'gtag consent default is present; verify its ordering and updates in a browser' : 'No consent default found; verify whether the banner blocks tracking until consent' );
            $found[] = array( 'Cookie banner', $page['banner'] ?: 'none', $page['banner'] ? 'ok' : 'off', $page['wp_consent_api'] ? 'WP Consent API script present' : 'No WP Consent API script' );
            foreach ( $page['others'] as $name ) {
                $found[] = array( 'Third-party script', $name, 'ok', 'Present' );
            }
            echo '<table class="widefat striped"><thead><tr><th>Item</th><th>ID</th><th>State</th><th>Note</th></tr></thead><tbody>';
            foreach ( $found as $f ) {
                echo '<tr><td>' . esc_html( $f[0] ) . '</td><td><code>' . esc_html( $f[1] ) . '</code></td><td><span class="pill pill-' . esc_html( $f[2] ) . '">' . esc_html( array( 'ok' => 'Found', 'warn' => 'Verify', 'bad' => 'Check', 'off' => 'Not found' )[ $f[2] ] ) . '</span></td><td>' . esc_html( $f[3] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Daily table.
        $has_baseline = isset( $google['definitions']['baseline_views'] );
        if ( ! empty( $series['ga']['ok'] ) || ! empty( $series['jp']['ok'] ) || $commerce['ok'] || $has_baseline ) {
            echo '<details style="margin-top:1.5em"><summary><strong>Daily numbers</strong></summary><table class="widefat striped" style="margin-top:8px"><thead><tr><th>Date</th><th style="text-align:right">GA4 users</th><th style="text-align:right">GA4 views</th><th style="text-align:right">Jetpack visitors</th><th style="text-align:right">Jetpack views</th>' . ( $has_baseline ? '<th>Basic page views</th>' : '' ) . ( $commerce['ok'] ? '<th>Orders</th><th>Order value (' . esc_html( $commerce['currency'] ) . ')</th>' : '' ) . '</tr></thead><tbody>';
            $gap = $a ? $a['gap_days'] : array();
            foreach ( array_reverse( $series['days'] ) as $d ) {
                echo '<tr' . ( in_array( $d['date'], $gap, true ) ? ' style="background:#fde8e6"' : '' ) . '><td>' . esc_html( $d['date'] ) . '</td><td class="n">' . esc_html( null === $d['ga_users'] ? '–' : number_format_i18n( $d['ga_users'] ) ) . '</td><td class="n">' . esc_html( null === $d['ga_views'] ? '–' : number_format_i18n( $d['ga_views'] ) ) . '</td><td class="n">' . esc_html( null === $d['jp_visitors'] ? '–' : number_format_i18n( $d['jp_visitors'] ) ) . '</td><td class="n">' . esc_html( null === $d['jp_views'] ? '–' : number_format_i18n( $d['jp_views'] ) ) . '</td>';
                if ( $has_baseline ) { $count = $google['days'][ $d['date'] ]['baseline_views'] ?? null; echo '<td class="n">' . esc_html( null === $count ? '–' : number_format_i18n( $count ) ) . '</td>'; }
                if ( $commerce['ok'] ) { $sale = $commerce['days'][ $d['date'] ]; echo '<td class="n">' . esc_html( number_format_i18n( $sale['wc_orders'] ) ) . '</td><td class="n">' . esc_html( number_format_i18n( $sale['wc_value'], 2 ) ) . '</td>'; }
                echo '</tr>';
            }
            echo '</tbody></table></details>';
        }
        echo '</div>';
    }

    /**
     * Interactive chart with an SVG fallback and optional WooCommerce series.
     */
    public static function render_chart( $series, $commerce, $metric, $gap, $google = array() ) {
        $days = $series['days'];
        $clarity_history = AceSeoTrackingClarity::history();
        $clarity_available = array();
        foreach ( $days as $date => &$day ) {
            if ( ! isset( $clarity_history[ $date ] ) ) { continue; }
            $saved = $clarity_history[ $date ];
            foreach ( $saved['values'] as $key => $value ) { $day[ 'clarity_' . $key ] = $value; $clarity_available[ $key ] = true; }
            $day['clarity_window'] = wp_date( 'j M H:i T', $saved['at'] - 86400 ) . ' – ' . wp_date( 'j M H:i T', $saved['at'] );
        }
        unset( $day );
        foreach ( $days as $date => &$day ) { $day = array_merge( $day, $commerce['days'][ $date ] ?? array(), $google['days'][ $date ] ?? array() ); }
        unset( $day );
        $ga = ! empty( $series['ga']['ok'] );
        $jp = ! empty( $series['jp']['ok'] );
        echo '<div class="ace-trk-chart"><div class="ace-trk-chart-controls" hidden><label class="ace-trk-traffic-control">Traffic <select name="traffic_metric"><option value="visitors"' . ( 'views' !== $metric ? ' selected' : '' ) . '>Visitors</option><option value="views"' . ( 'views' === $metric ? ' selected' : '' ) . '>Page views</option></select></label>';
        if ( $ga ) { echo '<label><input type="checkbox" name="ga" checked> Google Analytics</label>'; }
        if ( $jp ) { echo '<label><input type="checkbox" name="jp" checked> Jetpack</label>'; }
        if ( $commerce['ok'] ) {
            echo '<label><input type="checkbox" name="orders"' . ( ! $ga && ! $jp ? ' checked' : '' ) . '> Orders</label><label><input type="checkbox" name="value"> Order value (' . esc_html( $commerce['currency'] ) . ')</label>';
        }
        foreach ( AceSeoTrackingClarity::definitions() as $key => $definition ) {
            if ( ! isset( $clarity_available[ $key ] ) ) { continue; }
            echo '<label><input type="checkbox" name="clarity_' . esc_attr( $key ) . '" data-clarity-label="' . esc_attr( $definition[0] ) . '" data-description="' . esc_attr( $definition[3] ) . '"' . ( 'sessions' === $key ? ' checked' : '' ) . '> Clarity ' . esc_html( strtolower( $definition[0] ) ) . '</label>';
        }
        $has_sources = false;
        foreach ( $google['definitions'] ?? array() as $key => $definition ) {
            $has_sources = $has_sources || ! empty( $definition['area'] );
            echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" data-google-label="' . esc_attr( $definition['label'] ) . '" data-group="' . esc_attr( $definition['group'] ) . '"' . ( ! empty( $definition['area'] ) ? ' data-area="1"' : '' ) . ( 'baseline_views' === $key ? ' checked' : '' ) . '> ' . esc_html( $definition['label'] ) . '</label>';
        }
        if ( $has_sources ) { echo '<button type="button" class="button ace-trk-show-sources">Show traffic mix</button><label class="ace-trk-source-style">Sources <select name="source_style"><option value="area">Filled</option><option value="lines">Lines</option></select></label>'; }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AceSeoTrackingUI and svg_chart() escape every dynamic value as they build the markup.
        echo '</div><div class="ace-trk-chart-stage">' . self::svg_chart( $days, $metric, $gap ) . '<div class="ace-trk-tooltip" role="status" aria-live="polite" hidden></div></div><div class="ace-trk-chart-guides" aria-label="About the selected chart data"><p class="ace-trk-chart-hint">Hover or touch to inspect a date. Use the arrow keys when the chart is focused. Missing data stays blank.</p>';
        echo '<p data-guide="ga"><strong>Google Analytics</strong> Dates use ' . esc_html( $google['timezone'] ?? 'the property timezone' ) . '. Today’s figures may still be processing.</p>';
        echo '<p data-guide="baseline"><strong>Basic page views</strong> Daily first-party counts in ' . esc_html( wp_timezone_string() ) . '. No visitor IDs or tracking cookies. Includes React history navigation; excludes known bots and opt-outs. These are views, not unique people. Unrecorded dates stay blank.</p>';
        if ( ! empty( $google['definitions'] ) ) {
            echo '<p data-guide="sources"><strong>Traffic sources</strong> <span class="ace-trk-source-explanation">The coloured bands stack session counts. Their combined height is the total for the selected sources, not unique visitors.</span></p>';
            echo '<p data-guide="ai"><strong>AI traffic</strong> Google’s AI Assistant channel plus recognised AI referral hosts. Visits without an identifiable AI source remain in other channels.</p>';
            echo '<p data-guide="advertising"><strong>Advertising</strong> Clicks and impressions reported through GA4’s linked or imported ad data. An ad click is not the same as a site visit.</p>';
            echo '<p data-guide="search"><strong>Google Search</strong> Final web-search figures, using Pacific dates. Recent or unreported days may be unavailable.</p>';
        }
        if ( $clarity_available ) { echo '<p data-guide="clarity"><strong>Clarity</strong> Dashed lines show saved rolling 24-hour windows, not calendar-day totals. Hover for the exact window; adjacent windows can overlap.</p>'; }
        if ( $commerce['ok'] ) {
            echo '<p data-guide="commerce"><strong>Orders</strong> Processing, completed and refunded orders, grouped by creation date in the site timezone.</p>';
            echo '<p data-guide="value"><strong>Order value</strong> Total less refunds, including tax and delivery. Refunds reduce the original order day. ' . esc_html( $commerce['currency'] ) . ' uses the right axis.</p>';
            if ( $commerce['skipped_currency'] ) { echo '<p data-guide="commerce"><strong>Other currencies</strong> ' . esc_html( number_format_i18n( $commerce['skipped_currency'] ) ) . ' orders are excluded.</p>'; }
        }
        echo '</div>';
        $data = array( 'days' => array_values( $days ), 'gaps' => array_values( $gap ), 'today' => wp_date( 'Y-m-d' ), 'currency' => $commerce['currency'] ?? '', 'preferences' => home_url( '/' ) );
        echo '<script type="application/json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script></div>';
    }

    public static function svg_chart( array $days, $metric, array $gap ) {
        $days = array_values( $days );
        $n = count( $days );
        if ( $n < 2 ) {
            return '';
        }
        $ka = 'views' === $metric ? 'ga_views' : 'ga_users';
        $kj = 'views' === $metric ? 'jp_views' : 'jp_visitors';
        $W = 1000;
        $H = 300;
        $pl = 48;
        $pr = 16;
        $pt = 16;
        $pb = 34;
        $max = 1;
        foreach ( $days as $d ) {
            $max = max( $max, (int) $d[ $ka ], (int) $d[ $kj ] );
        }
        $step = $max > 2000 ? 500 : ( $max > 800 ? 200 : ( $max > 300 ? 100 : ( $max > 120 ? 50 : ( $max > 50 ? 20 : 10 ) ) ) );
        $top = (int) ceil( $max / $step ) * $step;
        $x = function ( $i ) use ( $n, $pl, $pr, $W ) {
            return round( $pl + ( $i / ( $n - 1 ) ) * ( $W - $pl - $pr ), 1 );
        };
        $y = function ( $v ) use ( $top, $pt, $pb, $H ) {
            return round( $pt + ( 1 - $v / $top ) * ( $H - $pt - $pb ), 1 );
        };
        $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Daily ' . esc_attr( $metric ) . '">';
        $cw = ( $W - $pl - $pr ) / ( $n - 1 );
        foreach ( $days as $i => $d ) {
            if ( in_array( $d['date'], $gap, true ) ) {
                $s .= '<rect x="' . ( $x( $i ) - $cw / 2 ) . '" y="' . $pt . '" width="' . round( $cw, 1 ) . '" height="' . ( $H - $pt - $pb ) . '" fill="#fde8e6"/>';
            }
        }
        for ( $v = 0; $v <= $top; $v += $step ) {
            $s .= '<line x1="' . $pl . '" x2="' . ( $W - $pr ) . '" y1="' . $y( $v ) . '" y2="' . $y( $v ) . '" stroke="#e0e0e0"/><text x="' . ( $pl - 6 ) . '" y="' . ( $y( $v ) + 4 ) . '" text-anchor="end" font-size="11" fill="#50575e">' . $v . '</text>';
        }
        foreach ( $days as $i => $d ) {
            if ( 0 === $i % 7 || $i === $n - 1 ) {
                $s .= '<text x="' . $x( $i ) . '" y="' . ( $H - $pb + 16 ) . '" text-anchor="' . ( $i === $n - 1 ? 'end' : 'middle' ) . '" font-size="11" fill="#50575e">' . esc_html( wp_date( 'j M', strtotime( $d['date'] . ' 12:00:00' ) ) ) . '</text>';
            }
        }
        foreach ( array( array( $kj, '#eb6834' ), array( $ka, '#2a78d6' ) ) as $line ) {
            $pts = array();
            foreach ( $days as $i => $d ) {
                if ( null !== $d[ $line[0] ] ) {
                    $pts[] = $x( $i ) . ',' . $y( (int) $d[ $line[0] ] );
                }
            }
            if ( count( $pts ) > 1 ) {
                $s .= '<polyline fill="none" stroke="' . $line[1] . '" stroke-width="2" stroke-linejoin="round" points="' . implode( ' ', $pts ) . '"/>';
            }
        }
        foreach ( $days as $i => $d ) {
            $tip = wp_date( 'D j M Y', strtotime( $d['date'] . ' 12:00:00' ) ) . ': GA4 ' . ( null === $d[ $ka ] ? 'n/a' : number_format_i18n( $d[ $ka ] ) ) . ( null === $d[ $kj ] ? '' : ', Jetpack ' . number_format_i18n( $d[ $kj ] ) ) . ( in_array( $d['date'], $gap, true ) ? ' (Analytics missed this day)' : '' );
            $s .= '<rect x="' . ( $x( $i ) - $cw / 2 ) . '" y="' . $pt . '" width="' . round( $cw, 1 ) . '" height="' . ( $H - $pt - $pb ) . '" fill="transparent"><title>' . esc_html( $tip ) . '</title></rect>';
        }
        return $s . '</svg>';
    }
}
