<?php
/** Run with: php tests/tracking-runtime-test.php /path/to/wordpress/core */
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
define( 'ABSPATH', rtrim( $argv[1] ?? '', '/' ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
require ABSPATH . 'wp-includes/plugin.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-tracking.php';

// Use real WordPress hook dispatch; isolate storage, HTTP and email.
$options = array();
$events = array();
$mail_count = 0;
$mail_ok = true;
$html = '<script src="https://www.googletagmanager.com/gtag/js?id=GT-TEST"></script>';
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function is_multisite() { return false; }
function wp_next_scheduled( $hook ) { return $GLOBALS['events'][ $hook ]['at'] ?? false; }
function wp_schedule_event( $at, $recurrence, $hook ) { $GLOBALS['events'][ $hook ] = compact( 'at', 'recurrence' ); }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl ) {}
function home_url( $path ) { return 'https://example.test' . $path; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function wp_remote_get( $url, $args ) { return array( 'body' => $GLOBALS['html'] ); }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function is_wp_error( $value ) { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_bloginfo( $key ) { return 'Test site'; }
function wp_specialchars_decode( $value, $flags ) { return $value; }
function wp_mail( $to, $subject, $body ) { ++$GLOBALS['mail_count']; return $GLOBALS['mail_ok']; }
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

AceSeoTracking::run();
AceSeoTracking::run();
AceSeoTracking::run();
expect( 1 === $mail_count, 'An unchanged fault must not send another email on the third check.' );
expect( ! empty( $options[ AceSeoTracking::OPT_ALERTS ]['emailed'] ), 'Repeated checks preserve the sent-alert fingerprint.' );
$html = str_replace( 'GT-TEST', 'GT-CHANGED', $html );
AceSeoTracking::run();
expect( 2 === $mail_count, 'A changed fault sends a new alert.' );
$html .= '<script>gtag("config", "GT-CHANGED");</script>';
AceSeoTracking::run();
expect( empty( $options[ AceSeoTracking::OPT_ALERTS ]['emailed'] ), 'Recovery clears the sent-alert fingerprint.' );
$html = '<script src="https://www.googletagmanager.com/gtag/js?id=GT-CHANGED"></script>';
AceSeoTracking::run();
expect( 3 === $mail_count, 'A recurring fault after recovery sends an alert.' );
$html = str_replace( 'GT-CHANGED', 'GT-RETRY', $html );
$mail_ok = false;
AceSeoTracking::run();
$mail_ok = true;
AceSeoTracking::run();
expect( 5 === $mail_count, 'Failed delivery is retried.' );
echo "$checks tracking runtime checks passed.\n";
