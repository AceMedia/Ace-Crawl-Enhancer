<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-tracking-commerce.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-tracking-clarity.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-tracking-ui.php';
class WP_Error { public function __construct( $code, $message ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_salt( $scheme ) { return 'test-only-key'; }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function wp_date( $format, $stamp, $zone = null ) { return ( new DateTimeImmutable( '@' . $stamp ) )->setTimezone( $zone ?? wp_timezone() )->format( $format ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_woocommerce_currency() { return 'GBP'; }
function wc_get_price_decimals() { return 2; }
function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
$options = $cache = $expiries = array();
$order_calls = $http_calls = 0;
$report_failure = false;
$response = array( 'code' => 200, 'body' => '[{"metricName":"Traffic","information":[{"totalSessionCount":"42","distantUserCount":"30"}]}]' );
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function get_transient( $key ) { return $GLOBALS['cache'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['cache'][ $key ] = $value; $GLOBALS['expiries'][ $key ] = $ttl; }
function wc_get_orders( $args ) {
    ++$GLOBALS['order_calls'];
    $GLOBALS['order_args'] = $args;
    if ( $GLOBALS['report_failure'] ) { throw new RuntimeException( 'Storage unavailable' ); }
    return (object) array( 'orders' => 1 === $args['page'] ? array( new TrackingOrder( '2026-10-01T23:30:00Z', 100, 20 ), new TrackingOrder( '2026-10-02T10:00:00Z', 50, 50 ) ) : array( new TrackingOrder( '2026-10-03T10:00:00Z', 75, 0, 'EUR' ) ), 'max_num_pages' => 2 );
}
class TrackingOrder {
    private $created, $total, $refunded, $currency;
    public function __construct( $created, $total, $refunded, $currency = 'GBP' ) { $this->created = $created; $this->total = $total; $this->refunded = $refunded; $this->currency = $currency; }
    public function get_date_created() { return new DateTimeImmutable( $this->created ); }
    public function get_total() { return $this->total; }
    public function get_total_refunded() { return $this->refunded; }
    public function get_currency() { return $this->currency; }
}
function wp_remote_get( $url, $args ) { ++$GLOBALS['http_calls']; $GLOBALS['http_args'] = $args; return $GLOBALS['response']; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
$checks = 0;
function expect( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }

$dates = array( '2026-10-01', '2026-10-02', '2026-10-03' );
$commerce = AceSeoTrackingCommerce::series( $dates );
expect( $commerce['ok'] && 2 === $order_calls, 'All order pages are read.' );
expect( 0 === $commerce['days']['2026-10-01']['wc_orders'] && 2 === $commerce['days']['2026-10-02']['wc_orders'], 'Order dates use the site timezone across midnight.' );
expect( 80.0 === $commerce['days']['2026-10-02']['wc_value'], 'Partial and full refunds reduce the original order value.' );
expect( 1 === $commerce['skipped_currency'] && 0 === $commerce['days']['2026-10-03']['wc_orders'], 'Different currencies are excluded, not added as GBP.' );
expect( ! in_array( 'pending', $order_args['status'], true ) && in_array( 'refunded', $order_args['status'], true ), 'Unpaid orders are excluded and refunded orders retained.' );
AceSeoTrackingCommerce::series( $dates );
expect( 2 === $order_calls, 'The hourly report is reused.' );
$report_failure = true;
$failed = AceSeoTrackingCommerce::series( $dates, true );
expect( ! $failed['ok'] && array() === $failed['days'], 'A failed query cannot expose a partial sales total.' );

$secret = 'test-not-a-real-Clarity-token';
$stored = AceSeoTrackingClarity::encrypt( $secret, 'project1' );
expect( is_array( $stored ) && false === strpos( json_encode( $stored ), $secret ), 'Token storage contains no plaintext token.' );
expect( $secret === AceSeoTrackingClarity::decrypt( $stored, 'project1' ), 'Saved tokens decrypt for their project.' );
expect( '' === AceSeoTrackingClarity::decrypt( $stored, 'project2' ), 'Changing project does not reuse its old token.' );
$damaged = $stored; $damaged['cipher'] = base64_encode( 'tampered' );
expect( '' === AceSeoTrackingClarity::decrypt( $damaged, 'project1' ), 'Authenticated encryption rejects tampering.' );
$options['clarity_project_id'] = 'project1';
expect( ! AceSeoTrackingClarity::snapshot()['connected'] && 0 === $http_calls, 'No token means no API request.' );
$options[ AceSeoTrackingClarity::OPTION ] = $stored;
$clarity = AceSeoTrackingClarity::snapshot();
expect( $clarity['ok'] && '30' === $clarity['metrics'][0]['fields']['Distinct users'], 'The documented Clarity response is normalised.' );
expect( 0 === $http_args['redirection'], 'Bearer tokens cannot follow redirects.' );
AceSeoTrackingClarity::snapshot();
expect( 1 === $http_calls && 21600 === $expiries[ AceSeoTrackingClarity::CACHE ], 'Snapshot caching avoids repeated API requests.' );
unset( $cache[ AceSeoTrackingClarity::CACHE ] );
$response = array( 'code' => 429, 'body' => '{}' );
expect( ! AceSeoTrackingClarity::snapshot()['ok'], 'Rate limits are reported as errors.' );
AceSeoTrackingClarity::snapshot();
expect( 2 === $http_calls, 'API errors are cached too.' );
$markup = AceSeoTrackingUI::detail( 'Project fwnvysplfv; G-SE5LH3QSWL <script>alert(1)</script>' );
expect( false === strpos( $markup, '<script>' ) && 2 === substr_count( $markup, '<code' ), 'Highlighted metadata remains escaped.' );
echo "$checks tracking data checks passed.\n";
