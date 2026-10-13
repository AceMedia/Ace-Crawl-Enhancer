<?php
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
    function number_format_i18n( $n ) { return number_format( (float) $n ); }
}
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-tracking.php';
$checks = 0;
function expect( $cond, $msg ) {
    global $checks;
    ++$checks;
    if ( ! $cond ) {
        throw new RuntimeException( $msg );
    }
}

// --- scan_html: the September 2026 fault, a loader rewritten into a stub with no config.
$broken = '<script id="google_gtagjs-js">(function(){var s=document.createElement("script");s.src="https:\/\/www.googletagmanager.com\/gtag\/js?id=GT-TBBCP35Z";})();</script>'
    . '<script>gtag("config", "G-KLNTKEMXNH", {"groups":"GLA"});gtag( "consent", "default", { "wait_for_update": 500, "region":["GB","IE"], "analytics_storage":"denied" } );</script>'
    . '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-PXWHLVKF"></script>'
    . '<script>(function(c,l,a,r,i,t,y){})(window, document, "clarity", "script", "fwnvysplfv");</script>'
    . '<script id="cookieyes" src="https://cdn-cookieyes.com/x.js"></script><script src="/assets/plugins/wp-consent-api/assets/js/wp-consent-api.min.js"></script>';
$s = AceSeoTracking::scan_html( $broken );
expect( $s['loaders'] === array( 'GT-TBBCP35Z' ), 'loader id: ' . json_encode( $s['loaders'] ) );
expect( $s['configs'] === array( 'G-KLNTKEMXNH' ), 'config ids: ' . json_encode( $s['configs'] ) );
expect( $s['gtm'] === array( 'GTM-PXWHLVKF' ), 'gtm: ' . json_encode( $s['gtm'] ) );
expect( 'fwnvysplfv' === $s['clarity'], 'clarity id' );
expect( true === $s['consent_default'] && 2 === (int) $s['consent_regions'], 'consent: ' . json_encode( $s ) );
expect( 'CookieYes' === $s['banner'] && true === $s['wp_consent_api'], 'banner' );

// --- scan_html: a healthy page.
$ok = '<script src="https://www.googletagmanager.com/gtag/js?id=GT-TBBCP35Z" async></script><script>gtag("config", "GT-TBBCP35Z");</script>';
$h = AceSeoTracking::scan_html( $ok );
expect( $h['loaders'] === $h['configs'], 'healthy page: loader and config match' );
expect( '' === $h['clarity'] && '' === $h['banner'] && array() === $h['others'], 'healthy page: nothing else' );

// --- problems(): the broken loader is flagged, the configured-only tag is not; expected ids are checked.
$detect = array(
    'ga4'     => array( 'key' => 'ga4', 'label' => 'GA4', 'status' => 'ok', 'detail' => '', 'ids' => array( 'GT-TBBCP35Z' ) ),
    'clarity' => array( 'key' => 'clarity', 'label' => 'Clarity', 'status' => 'ok', 'detail' => '', 'ids' => array( 'fwnvysplfv' ) ),
    'gtm'     => array( 'key' => 'gtm', 'label' => 'GTM', 'status' => 'ok', 'detail' => '', 'ids' => array( 'GTM-OTHER1' ) ),
);
$page = $s + array( 'ok' => true );
$problems = AceSeoTracking::problems( $detect, $page, array( 'ga' => array( 'ok' => false, 'error' => 'x' ), 'days' => array() ) );
$texts = array_map( function ( $p ) { return $p[0] . ':' . $p[1]; }, $problems );
expect( 1 === count( preg_grep( '/^bad:The Google tag GT-TBBCP35Z loads/', $texts ) ), 'unconfigured loader flagged: ' . json_encode( $texts ) );
expect( 1 === count( preg_grep( '/^bad:GTM expects GTM-OTHER1/', $texts ) ), 'missing expected id flagged' );
expect( 0 === count( preg_grep( '/Clarity expects/', $texts ) ), 'clarity present, not flagged' );
expect( 0 === count( preg_grep( '/Two Google Analytics/', $texts ) ), 'one configured GA tag: no double-count warning' );

// --- analyse(): a 60-day series with Jetpack, Analytics dead from day 40.
$days = array();
$t0 = new DateTime( '2026-08-15' );
for ( $i = 0; $i < 60; $i++ ) {
    $d = ( clone $t0 )->modify( "+$i days" )->format( 'Y-m-d' );
    $days[ $d ] = array( 'date' => $d, 'ga_users' => $i < 40 ? 50 : 0, 'ga_views' => $i < 40 ? 120 : 0, 'jp_visitors' => 100, 'jp_views' => 220 );
}
$a = AceSeoTracking::analyse( $days, '2026-10-14' );
expect( $a['has_jp'], 'jetpack detected' );
expect( 20 === count( $a['gap_days'] ), 'gap days: ' . count( $a['gap_days'] ) );
expect( '2026-09-24' === $a['gap_days'][0], 'first gap day: ' . $a['gap_days'][0] );
expect( true === $a['ongoing'], 'ongoing outage' );
expect( 0.5 === $a['ratio'], 'ratio: ' . var_export( $a['ratio'], true ) );
expect( 1000 === $a['missing_users'], 'missing users: ' . $a['missing_users'] );

// Today is excluded, and a recovered series is not "ongoing".
$days['2026-10-13']['ga_users'] = 48;
$days['2026-10-12']['ga_users'] = 51;
$a = AceSeoTracking::analyse( $days, '2026-10-14' );
expect( 18 === count( $a['gap_days'] ) && false === $a['ongoing'], 'recovered: ' . json_encode( array( count( $a['gap_days'] ), $a['ongoing'] ) ) );

// Without Jetpack: zero after a healthy fortnight counts, zero in a quiet site does not.
$solo = array();
for ( $i = 0; $i < 30; $i++ ) {
    $d = ( clone $t0 )->modify( "+$i days" )->format( 'Y-m-d' );
    $solo[ $d ] = array( 'date' => $d, 'ga_users' => $i < 27 ? 40 : 0, 'ga_views' => 0, 'jp_visitors' => null, 'jp_views' => null );
}
$a = AceSeoTracking::analyse( $solo, '2026-09-14' );
expect( ! $a['has_jp'] && 3 === count( $a['gap_days'] ) && $a['ongoing'], 'solo outage: ' . json_encode( $a ) );
$quiet = array_map( function ( $d ) { $d['ga_users'] = 0; return $d; }, $solo );
$a = AceSeoTracking::analyse( $quiet, '2026-09-14' );
expect( array() === $a['gap_days'], 'quiet site: no gap' );

// --- svg_chart draws both lines and shades gap days.
$svg = AceSeoTracking::svg_chart( array_slice( $days, 0, 14 ), 'visitors', array( '2026-08-20' ) );
expect( 2 === substr_count( $svg, '<polyline' ), 'two polylines' );
expect( false !== strpos( $svg, 'fill="#fde8e6"' ), 'gap shading' );
expect( 14 === substr_count( $svg, '<title>' ), 'one tooltip per day' );

echo "$checks tracking checks passed.\n";
