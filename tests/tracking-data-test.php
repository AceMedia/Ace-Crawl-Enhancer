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
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
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
$metric_data = array(
    array( 'metricName' => 'Traffic', 'information' => array( array( 'totalSessionCount' => '42', 'distinctUserCount' => '30' ) ) ),
    array( 'metricName' => 'DeadClickCount', 'information' => array( array( 'subTotal' => '7', 'sessionsCount' => '42' ) ) ),
    array( 'metricName' => 'RageClickCount', 'information' => array( array( 'sessionsWithMetricPercentage' => '10' ) ) ),
    array( 'metricName' => 'ScriptErrorCount', 'information' => array( array( 'subTotal' => '0' ) ) ),
);
$metrics = AceSeoTrackingClarity::normalise( $metric_data );
$values = AceSeoTrackingClarity::values( $metrics );
expect( 7.0 === $values['dead'] && 30.0 === $values['users'], 'Interaction totals and correctly spelt distinct users are extracted.' );
expect( ! isset( $values['rage'] ) && 0.0 === $values['errors'], 'Missing counts stay missing; explicit zero is preserved.' );
$segmented = $metrics; $segmented[] = $metrics[0];
expect( ! isset( AceSeoTrackingClarity::values( $segmented )['users'] ), 'Segmented unique users are not summed.' );
$negative = array( array( 'label' => 'Dead Click Count', 'fields' => array( 'Sub total' => '-1' ) ) );
expect( array() === AceSeoTrackingClarity::values( $negative ), 'Invalid negative counts are not plotted.' );
$options[ AceSeoTrackingClarity::HISTORY ] = array();
$at = time() - 3 * 86400;
$sample = array( 'ok' => true, 'project' => 'project1', 'at' => $at, 'metrics' => $metrics );
AceSeoTrackingClarity::remember( $sample );
$history = AceSeoTrackingClarity::history();
expect( 1 === count( $history ) && $at === reset( $history )['at'], 'Saved points retain their exact reporting timestamp.' );
$sample['at'] += 60; $sample['metrics'] = array();
AceSeoTrackingClarity::remember( $sample );
expect( $history === AceSeoTrackingClarity::history(), 'An empty report cannot erase a good saved window.' );
$sample['metrics'] = $metrics; AceSeoTrackingClarity::remember( $sample );
expect( $history === AceSeoTrackingClarity::history(), 'The first snapshot for a date remains immutable.' );
$sample['at'] = time(); AceSeoTrackingClarity::remember( $sample );
expect( 2 === count( AceSeoTrackingClarity::history() ), 'Missed days are not filled with zero or copied values.' );
$options['clarity_project_id'] = 'project2';
expect( array() === AceSeoTrackingClarity::history(), 'A changed project cannot display the previous project history.' );
$sample['project'] = 'project2'; AceSeoTrackingClarity::remember( $sample );
expect( 1 === count( AceSeoTrackingClarity::history() ), 'The new project starts an isolated history.' );
$sample['at'] = time() - 70 * 86400; AceSeoTrackingClarity::remember( $sample );
expect( 1 === count( AceSeoTrackingClarity::history() ), 'Expired history is pruned.' );
echo "$checks tracking data checks passed.\n";
