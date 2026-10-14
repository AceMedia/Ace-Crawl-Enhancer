<?php
/** Run with: php tests/tracking-runtime-test.php /path/to/wordpress/core */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
define( 'ABSPATH', rtrim( $argv[1] ?? '', '/' ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-tracking.php';

// Use real WordPress hook dispatch; isolate storage, HTTP and email.
$options = array(
    'googlesitekit_analytics-4_settings' => array( 'propertyID' => '123', 'measurementID' => 'G-TEST', 'googleTagID' => 'GT-TEST', 'useSnippet' => true ),
    'googlesitekit_active_modules' => array( 'analytics-4' ),
    'googlesitekit_has_connected_admins' => true,
);
$events = array();
$transients = array();
$mail_count = 0;
$mail_ok = true;
$fault = false;
$jetpack_active = false;
$http_code = 200;
$http_headers = array( 'content-type' => 'text/html' );
$http_error = false;
$html = '<html><body><script src="https://www.googletagmanager.com/gtag/js?id=GT-TEST"></script></body></html>';
class AceSEOSiteKit {
    const SCOPE_ANALYTICS = 'test';
    public static function is_active() { return true; }
    public static function get_access_token( $scopes ) { return 'isolated-test-token'; }
}
class Jetpack {
    public static function is_module_active( $module ) { return $GLOBALS['jetpack_active']; }
}
class TestTrackingStats {
    public function get_visits( $args ) {
        $data = array();
        foreach ( report_rows() as $row ) { $data[] = array( $row['date'], 200, 100 ); }
        return array( 'fields' => array( 'period', 'views', 'visitors' ), 'data' => $data );
    }
}
class_alias( 'TestTrackingStats', 'Automattic\\Jetpack\\Stats\\WPCOM_Stats' );
function report_rows() {
    $rows = array();
    for ( $i = 59; $i >= 0; --$i ) {
        $date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . $i . ' days' )->format( 'Y-m-d' );
        $users = $GLOBALS['fault'] && $i <= 2 ? 0 : 40;
        $rows[] = array( 'date' => $date, 'users' => $users );
    }
    return $rows;
}
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function is_multisite() { return false; }
function wp_next_scheduled( $hook ) { return $GLOBALS['events'][ $hook ]['at'] ?? false; }
function wp_schedule_event( $at, $recurrence, $hook ) { $GLOBALS['events'][ $hook ] = compact( 'at', 'recurrence' ); }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function wp_date( $format, $time = null ) { return ( new DateTimeImmutable( '@' . ( $time ?? time() ) ) )->setTimezone( wp_timezone() )->format( $format ); }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
function home_url( $path ) { return 'https://example.test' . $path; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function wp_remote_get( $url, $args ) { return $GLOBALS['http_error'] ? new WP_Error( 'http_request_failed', 'Timed out' ) : array( 'body' => $GLOBALS['html'], 'code' => $GLOBALS['http_code'], 'headers' => $GLOBALS['http_headers'] ); }
function wp_remote_post( $url, $args ) {
    $rows = array();
    foreach ( report_rows() as $row ) {
        $rows[] = array( 'dimensionValues' => array( array( 'value' => str_replace( '-', '', $row['date'] ) ) ), 'metricValues' => array( array( 'value' => (string) $row['users'] ), array( 'value' => (string) ( $row['users'] * 2 ) ) ) );
    }
    return array( 'code' => 200, 'body' => json_encode( array( 'rows' => $rows ) ) );
}
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][$name] ?? ''; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_bloginfo( $key ) { return 'Test site'; }
function wp_specialchars_decode( $value, $flags ) { return $value; }
function wp_mail( $to, $subject, $body ) { ++$GLOBALS['mail_count']; return $GLOBALS['mail_ok']; }
function current_user_can( $capability ) { return true; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
$checks = 0;
function expect( $condition, $message ) {
    ++$GLOBALS['checks'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

// The plugin loads this component inside its own priority-10 init callback.
add_action( 'init', array( 'AceSeoTracking', 'init' ) );
do_action( 'init' );
expect( false !== wp_next_scheduled( AceSeoTracking::CRON ), 'Daily tracking check must be scheduled during plugin initialisation.' );
$event = $events[ AceSeoTracking::CRON ];
expect( 'daily' === $event['recurrence'], 'Tracking check repeats daily.' );
expect( '05:10' === ( new DateTimeImmutable( '@' . $event['at'] ) )->setTimezone( wp_timezone() )->format( 'H:i' ), 'Check uses site-local 05:10.' );
AceSeoTracking::schedule();
expect( $event === $events[ AceSeoTracking::CRON ], 'Scheduling preserves the existing event.' );

// Cached results from the old checker must be reclassified, never trusted as HTML.
$transients[AceSeoTracking::T_PAGE] = array( 'ok' => true, 'http' => 202 );
$http_code = 202; $html = ''; $http_headers['x-amzn-waf-action'] = 'challenge';
$page = AceSeoTracking::probe_page();
expect( ! $page['ok'] && $page['probe_version'] === AceSeoTracking::PROBE_VERSION, 'Old cached 202 results are invalidated.' );
$options[AceSeoTracking::OPT_ALERTS] = array( 'problems' => array( array( 'bad', 'Old missing-tag alert' ) ) );
ob_start(); AceSeoTracking::notice(); $notice = ob_get_clean();
expect( strpos( $notice, 'Previous results need verification' ) !== false && strpos( $notice, 'Old missing-tag alert' ) === false, 'Old alert classifications cannot survive the checker upgrade.' );
AceSeoTracking::run();
expect( 0 === $mail_count, 'A WAF challenge must not send a broken-tracking email.' );
expect( AceSeoTracking::PROBE_VERSION === $options[AceSeoTracking::OPT_ALERTS]['check_version'], 'New alert state records the checker version.' );
$http_code = 200; $http_headers = array( 'content-type' => 'text/html' );
$html = '<html><body><script src="/consent-loader.js"></script></body></html>';
AceSeoTracking::run();
expect( 0 === $mail_count, 'A dynamically loaded tag missing from static HTML must not send an email.' );
$html = '<html><body><script src="https://www.googletagmanager.com/gtag/js?id=GT-TEST"></script></body></html>';
AceSeoTracking::run();
expect( 0 === $mail_count, 'Missing inline configuration needs browser verification, not an email.' );
$html = '<html><body><script>gtag("config","G-TEST");</script></body></html>';
$detected = AceSeoTracking::detect();
expect( array( 'GT-TEST', 'G-TEST' ) === $detected['ga4']['alternative_ids'], 'Site Kit supplies known Google tag aliases.' );
$problems = AceSeoTracking::run();
expect( ! array_filter( $problems, function ( $p ) { return strpos( $p[1], 'is configured for GT-TEST' ) !== false; } ), 'Known measurement ID satisfies the Site Kit tag check.' );
$http_error = true;
$page = AceSeoTracking::probe_page( true );
expect( ! $page['ok'] && 'unverified' === $page['state'] && 'Timed out' === $page['error'], 'Network failures remain unverified.' );
$http_error = false;

// Real report outages still alert, including when the page check is blocked.
$http_code = 202; $html = ''; $fault = true;
AceSeoTracking::run(); AceSeoTracking::run(); AceSeoTracking::run();
expect( 1 === $mail_count, 'An unchanged fault must not send another email on the third check.' );
expect( ! empty( $options[ AceSeoTracking::OPT_ALERTS ]['emailed'] ), 'Repeated checks preserve the sent-alert fingerprint.' );
$jetpack_active = true;
AceSeoTracking::run();
expect( 2 === $mail_count, 'A changed fault sends a new alert.' );
$fault = false;
AceSeoTracking::run();
expect( empty( $options[ AceSeoTracking::OPT_ALERTS ]['emailed'] ), 'Recovery clears the sent-alert fingerprint.' );
$fault = true;
AceSeoTracking::run();
expect( 3 === $mail_count, 'A recurring fault after recovery sends an alert.' );
$jetpack_active = false;
$mail_ok = false;
AceSeoTracking::run();
$mail_ok = true;
AceSeoTracking::run();
expect( 5 === $mail_count, 'Failed delivery is retried.' );
echo "$checks tracking runtime checks passed.\n";
