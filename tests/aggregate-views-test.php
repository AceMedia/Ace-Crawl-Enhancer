<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require dirname( __DIR__ ) . '/includes/class-ace-seo-aggregate-views.php';
$options = array( AceSeoAggregateViews::OPTION => 1, AceSeoAggregateViews::SCHEMA => 1 );
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function home_url() { return 'https://example.test'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function wp_date( $f ) { return '2026-10-14'; }
class WP_Error { public function __construct( public $code, $message, $data = null ) {} public function get_error_code() { return $this->code; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_REST_Response { public function __construct( $body, public $status ) {} public function header( $k, $v ) {} }
class AceSeoViewTracker { public static function is_bot( $ua ) { return ! $ua || preg_match( '/bot|headless|curl/i', $ua ); } }
class Request {
    public $headers = array( 'origin'=>'https://example.test', 'sec-fetch-site'=>'same-origin', 'user-agent'=>'Mozilla/5.0 Chrome/140.0 Safari/537.36' );
    public $body = '{"v":1}';
    public function get_header( $key ) { return $this->headers[ $key ] ?? ''; }
    public function get_body() { return $this->body; }
    public function get_json_params() { return json_decode( $this->body, true ); }
}
class DB {
    public $prefix='wp_test_', $queries=array(), $fail=false, $rows=array();
    public function prepare( $q, ...$args ) { return array( $q, $args ); }
    public function query( $q ) { $this->queries[]=$q; return $this->fail ? false : 1; }
    public function get_results( $q, $format ) { return $this->rows; }
}
$wpdb = new DB(); $n=0;
function check( $label, $ok ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL '.$label.PHP_EOL ); exit(1); } ++$GLOBALS['n'];echo 'PASS '.$label.PHP_EOL; }
$r=new Request();check('Same-origin browser is allowed',AceSeoAggregateViews::permission($r));
$r->headers['origin']='https://evil.test';check('Cross-origin request denied',!AceSeoAggregateViews::permission($r));
$r->headers['origin']='https://example.test';unset($r->headers['sec-fetch-site']);check('Missing fetch metadata denied',!AceSeoAggregateViews::permission($r));
$r=new Request();$r->body=str_repeat('a',33);check('Oversized request denied',!AceSeoAggregateViews::permission($r));
$r=new Request();$options[AceSeoAggregateViews::OPTION]=0;check('Disabled counter rejects stale cached beacons',!AceSeoAggregateViews::permission($r));$options[AceSeoAggregateViews::OPTION]=1;
$r->body='{"v":1,"url":"/private"}';check('Extra fields refused',AceSeoAggregateViews::hit($r) instanceof WP_Error && !$wpdb->queries);
$r=new Request();$response=AceSeoAggregateViews::hit($r);check('Valid request atomically increments only a date and counter',204===$response->status && count($wpdb->queries)===1 && $wpdb->queries[0][1]===array('2026-10-14') && str_contains($wpdb->queries[0][0],'ON DUPLICATE KEY UPDATE views = views + 1'));
foreach(array('dnt'=>'1','sec-gpc'=>'1','user-agent'=>'Googlebot') as $key=>$value){$r=new Request();$r->headers[$key]=$value;AceSeoAggregateViews::hit($r);check('Excluded '.$key.' does not write',count($wpdb->queries)===1);}
$r=new Request();$wpdb->fail=true;check('Database failure is not reported as success',AceSeoAggregateViews::hit($r) instanceof WP_Error);$wpdb->fail=false;
$wpdb->rows=array(array('day'=>'2026-10-14','views'=>'12'));
$out=AceSeoAggregateViews::enrich(array('definitions'=>array(),'days'=>array()),array('2026-10-13','2026-10-14'));
check('New baseline has a chart definition',isset($out['definitions']['baseline_views']));
check('Unrecorded days remain null',null===$out['days']['2026-10-13']['baseline_views']);
check('Recorded daily total is numeric',12===$out['days']['2026-10-14']['baseline_views']);
$wp=(object)['query_vars'=>['rest_route'=>'/ace-seo/v1/aggregate-view']];$_SERVER['REQUEST_METHOD']='POST';$error=new WP_Error('rest_not_logged_in','Closed');
check('Only the public counter is exempt from a guest-only blanket restriction',null===AceSeoAggregateViews::public_counter_auth($error));
$wp->query_vars['rest_route']='/wp/v2/users';check('Private routes remain protected',AceSeoAggregateViews::public_counter_auth($error)===$error);
$wp->query_vars['rest_route']='/ace-seo/v1/aggregate-view';$invalid=new WP_Error('rest_cookie_invalid_nonce','Bad nonce');check('Other authentication failures remain protected',AceSeoAggregateViews::public_counter_auth($invalid)===$invalid);
$_SERVER['REQUEST_METHOD']='GET';check('Only POST is exempt',AceSeoAggregateViews::public_counter_auth($error)===$error);
echo $n.' checks passed'.PHP_EOL;
