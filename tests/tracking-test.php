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
expect( 1 === count( preg_grep( '/^warn:The Google tag GT-TBBCP35Z has a loader/', $texts ) ), 'unconfigured loader needs browser verification: ' . json_encode( $texts ) );
expect( 1 === count( preg_grep( '/^warn:GTM is configured for GTM-OTHER1/', $texts ) ), 'missing expected id needs browser verification' );
expect( 0 === count( preg_grep( '/Clarity expects/', $texts ) ), 'clarity present, not flagged' );
expect( 0 === count( preg_grep( '/Multiple Google Analytics/', $texts ) ), 'one configured GA tag: no double-count warning' );
$multi = AceSeoTracking::scan_html( $ok . '<script>gtag("config", "G-OTHER");</script>' ) + array( 'ok' => true );
$multi_problems = AceSeoTracking::problems( array(), $multi, array() );
expect( 1 === count( $multi_problems ) && 'warn' === $multi_problems[0][0], 'multiple configured tags require destination review' );
expect( false !== strpos( $multi_problems[0][1], 'different properties report separately' ), 'separate properties are not described as automatic duplicate reporting' );

// --- analyse(): a 60-day series with Jetpack, Analytics dead from day 40.
$ads_detect = array(
    'ads' => array( 'key' => 'ads', 'label' => 'Google Ads (Site Kit)', 'status' => 'warn', 'detail' => 'No conversion ID', 'ids' => array() ),
    'gla' => array( 'key' => 'gla', 'label' => 'Google for WooCommerce', 'status' => 'ok', 'detail' => 'Ads account 123', 'ids' => array() ),
);
$ads_page = AceSeoTracking::scan_html( '<script>gtag("config", "AW-123");</script>' ) + array( 'ok' => true );
$group = AceSeoTracking::google_ads( $ads_detect, $ads_page );
expect( array( 'gla' ) === $group['configured'] && $group['tag_found'], 'WooCommerce can provide Ads without Site Kit' );
expect( array() === AceSeoTracking::problems( $ads_detect, $ads_page, array() ), 'unused Site Kit alternative does not raise a setup warning' );
$ads_page['configs'] = array();
expect( 2 === count( AceSeoTracking::problems( $ads_detect, $ads_page, array() ) ), 'missing Ads tag remains a warning; unused provider is not assumed healthy' );
$ads_detect['gla']['status'] = 'warn';
expect( array() === AceSeoTracking::google_ads( $ads_detect, $ads_page )['configured'], 'unlinked Ads account is not a configured provider' );
$ads_detect['ads']['status'] = 'ok';
$ads_detect['ads']['ids'] = array( 'AW-123' );
$ads_detect['gla']['status'] = 'ok';
expect( 2 === count( AceSeoTracking::google_ads( $ads_detect, $ads_page )['configured'] ), 'both configured providers remain visible for review' );

$native_page = AceSeoTracking::scan_html( '<script id="ace-consent-gate">gtag("consent", "default", {analytics_storage:"denied"});</script>' ) + array( 'ok' => true );
expect( 'Theme consent banner' === $native_page['banner'] && $native_page['consent_default'], 'theme-provided consent is recognised' );
$native_detect = array( 'consent' => array( 'key' => 'consent', 'label' => 'Consent mode (Site Kit)', 'status' => 'warn', 'detail' => 'Off', 'ids' => array() ) );
expect( array() === AceSeoTracking::problems( $native_detect, $native_page, array() ), 'consent provided outside Site Kit is not flagged as missing' );
$native_page['consent_default'] = false;
expect( 1 === count( AceSeoTracking::problems( $native_detect, $native_page, array() ) ), 'missing consent defaults retain the warning' );

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

// A server fetch cannot decide whether JavaScript executes or consent is granted.
$document = '<!doctype html><html><head><title>Home</title></head><body>' . $ok . '</body></html>';
$response = AceSeoTracking::page_response( 200, $document, array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
expect( $response['ok'] && 'html' === $response['state'] && $response['configs'] === array( 'GT-TBBCP35Z' ), 'complete HTML can be scanned' );
$xhtml = AceSeoTracking::page_response( 200, $document, array( 'content-type' => 'application/xhtml+xml' ) );
expect( $xhtml['ok'], 'XHTML is supported' );
$fixtures = array(
    array( 202, '', array( 'x-amzn-waf-action' => 'challenge' ) ),
    array( 200, $document, array( 'X-Amzn-Waf-Action' => 'captcha' ) ),
    array( 200, $document, array( 'CF-Mitigated' => 'challenge' ) ),
    array( 200, '<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>', array() ),
    array( 200, '<html><body><form id="challenge-form"></form></body></html>', array() ),
    array( 202, $document, array() ), array( 204, '', array() ), array( 206, $document, array() ),
    array( 301, $document, array() ), array( 403, $document, array() ), array( 429, $document, array() ), array( 503, $document, array() ),
    array( 200, '', array() ), array( 200, '{"ok":true}', array( 'content-type' => 'application/json' ) ),
    array( 200, $document, array( 'content-type' => 'text/plain' ) ), array( 200, substr( $document, 0, -14 ), array() ),
);
$available = array( 'ga' => array( 'ok' => true ), 'days' => array() );
foreach ( $fixtures as $fixture ) {
    $unverified = AceSeoTracking::page_response( $fixture[0], $fixture[1], $fixture[2] );
    expect( ! $unverified['ok'] && 'unverified' === $unverified['state'] && $unverified['error'], 'inaccessible/incomplete page is unverified: ' . json_encode( $fixture ) );
    expect( ! $unverified['loaders'] && ! $unverified['configs'], 'an unverified response is not scanned for tags' );
    $issues = AceSeoTracking::problems( $detect, $unverified, $available );
    expect( 1 === count( $issues ) && 'warn' === $issues[0][0], 'blocked check does not invent missing-tag failures' );
}
$injected = AceSeoTracking::page_response( 200, '<html><body><script src="/consent-loader.js"></script></body></html>' );
$issues = AceSeoTracking::problems( $detect, $injected, $available );
expect( 3 === count( $issues ) && ! in_array( 'bad', array_column( $issues, 0 ), true ), 'unknown dynamic/consent loaders need verification, not red alerts' );
$aliases = array( 'ga4' => array( 'key' => 'ga4', 'label' => 'GA4', 'status' => 'ok', 'ids' => array( 'GT-ALIAS' ), 'alternative_ids' => array( 'GT-ALIAS', 'G-MEASUREMENT' ) ) );
$aliased_page = AceSeoTracking::scan_html( '<script>gtag("config","G-MEASUREMENT");</script>' ) + array( 'ok' => true );
expect( ! AceSeoTracking::problems( $aliases, $aliased_page, $available ), 'a known measurement ID can stand in for its Google tag alias' );
$aliases['ga4']['alternative_ids'] = array( 'GT-ALIAS', 'G-DIFFERENT' );
expect( count( AceSeoTracking::problems( $aliases, $aliased_page, $available ) ) === 1, 'an unrelated destination does not satisfy an expected tag' );

$today = wp_date( 'Y-m-d' );
$report = array( 'ga' => array( 'ok' => true ), 'days' => array( array( 'date' => $today, 'ga_views' => 42, 'ga_users' => 20 ) ), 'at' => time() );
$blocked = AceSeoTracking::page_response( 202, '' );
expect( $today === AceSeoTracking::recent_reporting( $report ), 'recent positive reporting is recognised' );
$summary = AceSeoTracking::summary( $blocked, $report, AceSeoTracking::problems( $detect, $blocked, $report ) );
expect( 'warn' === $summary[0] && strpos( $summary[1], 'Analytics data is arriving.' ) === 0 && strpos( $summary[1], 'unavailable' ) !== false, 'reporting and blocked verification remain separate' );
$old = $report; $old['days'][0]['date'] = '2020-01-01';
expect( null === AceSeoTracking::recent_reporting( $old ), 'historical data cannot claim current reporting' );
$old = $report; $old['at'] = time() - 3 * 86400;
expect( null === AceSeoTracking::recent_reporting( $old ), 'stale cached data cannot claim current reporting' );
$unknown = $report; $unknown['ga']['ok'] = false;
expect( null === AceSeoTracking::recent_reporting( $unknown ), 'unavailable reporting cannot prove traffic' );
$unknown = $report; $unknown['days'][0]['ga_views'] = null; $unknown['days'][0]['ga_users'] = null;
expect( null === AceSeoTracking::recent_reporting( $unknown ), 'unknown coverage is not positive traffic' );
$unknown['days'][0]['ga_views'] = 0; $unknown['days'][0]['ga_users'] = 0;
expect( null === AceSeoTracking::recent_reporting( $unknown ), 'zero traffic cannot produce an arriving-data claim' );
$summary = AceSeoTracking::summary( $response, array(), array() );
expect( strpos( $summary[1], 'Browser execution has not been verified' ) !== false, 'HTML presence does not claim browser execution' );

// The security challenge must not suppress independent evidence of a real outage.
// Use current site-local dates for the outage check, independent of when this test runs.
$outage = array( 'ga' => array( 'ok' => true ), 'days' => array() );
for ( $i = 2; $i >= 1; --$i ) {
    $date = ( new DateTimeImmutable( $today ) )->modify( '-' . $i . ' days' )->format( 'Y-m-d' );
    $outage['days'][$date] = array( 'date' => $date, 'ga_users' => 0, 'ga_views' => 0, 'jp_visitors' => 100, 'jp_views' => 200 );
}
$issues = AceSeoTracking::problems( $detect, $blocked, $outage );
expect( in_array( 'bad', array_column( $issues, 0 ), true ), 'a genuine reporting outage is still bad during a blocked fetch' );
expect( 'bad' === AceSeoTracking::summary( $blocked, $outage, $issues )[0], 'reporting outage takes precedence over an inconclusive probe' );

echo "$checks tracking checks passed.\n";
