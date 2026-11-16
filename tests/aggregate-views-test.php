<?php
require_once __DIR__ . '/wp-stubs.php';
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require dirname( __DIR__ ) . '/includes/class-ace-seo-aggregate-views.php';
$options = array( AceCrawlAggregateViews::OPTION => 1, AceCrawlAggregateViews::SCHEMA => 1 );
function apply_filters($tag, $value) { return $GLOBALS['show_privacy'] ?? $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function home_url() { return 'https://example.test'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function wp_date( $f ) { return '2026-10-14'; }
class WP_Error { public $code; public function __construct( $code, $message, $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_REST_Response { public $status; public function __construct( $body, $status ) { $this->status = $status; } public function header( $k, $v ) {} }
class AceCrawlViewTracker { public static function is_bot( $ua ) { return ! $ua || preg_match( '/bot|headless|curl/i', $ua ); } }
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
$r=new Request();check('Same-origin browser is allowed',AceCrawlAggregateViews::permission($r));
$r->headers['origin']='https://evil.test';check('Cross-origin request denied',!AceCrawlAggregateViews::permission($r));
$r->headers['origin']='https://example.test';unset($r->headers['sec-fetch-site']);check('Missing fetch metadata denied',!AceCrawlAggregateViews::permission($r));
$r=new Request();$r->body=str_repeat('a',33);check('Oversized request denied',!AceCrawlAggregateViews::permission($r));
$r=new Request();$options[AceCrawlAggregateViews::OPTION]=0;check('Disabled counter rejects stale cached beacons',!AceCrawlAggregateViews::permission($r));$options[AceCrawlAggregateViews::OPTION]=1;
$r->body='{"v":1,"url":"/private"}';check('Extra fields refused',AceCrawlAggregateViews::hit($r) instanceof WP_Error && !$wpdb->queries);
$r=new Request();$response=AceCrawlAggregateViews::hit($r);check('Valid request atomically increments only a date and counter',204===$response->status && count($wpdb->queries)===1 && $wpdb->queries[0][1]===array('2026-10-14') && false!==strpos($wpdb->queries[0][0],'ON DUPLICATE KEY UPDATE views = views + 1'));
foreach(array('dnt'=>'1','sec-gpc'=>'1','user-agent'=>'Googlebot') as $key=>$value){$r=new Request();$r->headers[$key]=$value;AceCrawlAggregateViews::hit($r);check('Excluded '.$key.' does not write',count($wpdb->queries)===1);}
$r=new Request();$wpdb->fail=true;check('Database failure is not reported as success',AceCrawlAggregateViews::hit($r) instanceof WP_Error);$wpdb->fail=false;
$wpdb->rows=array(array('day'=>'2026-10-14','views'=>'12'));
$out=AceCrawlAggregateViews::enrich(array('definitions'=>array(),'days'=>array()),array('2026-10-13','2026-10-14'));
check('New baseline has a chart definition',isset($out['definitions']['baseline_views']));
check('Unrecorded days remain null',null===$out['days']['2026-10-13']['baseline_views']);
check('Recorded daily total is numeric',12===$out['days']['2026-10-14']['baseline_views']);
$wp=(object)['query_vars'=>['rest_route'=>'/ace-seo/v1/aggregate-view']];$_SERVER['REQUEST_METHOD']='POST';$error=new WP_Error('rest_not_logged_in','Closed');
check('Only the public counter is exempt from a guest-only blanket restriction',null===AceCrawlAggregateViews::public_counter_auth($error));
$wp->query_vars['rest_route']='/wp/v2/users';check('Private routes remain protected',AceCrawlAggregateViews::public_counter_auth($error)===$error);
$wp->query_vars['rest_route']='/ace-seo/v1/aggregate-view';$invalid=new WP_Error('rest_cookie_invalid_nonce','Bad nonce');check('Other authentication failures remain protected',AceCrawlAggregateViews::public_counter_auth($invalid)===$invalid);
$_SERVER['REQUEST_METHOD']='GET';check('Only POST is exempt',AceCrawlAggregateViews::public_counter_auth($error)===$error);

// Fast path: handled before the rest of WordPress loads.
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
$fast=array('HTTP_ORIGIN'=>'https://example.test','HTTP_SEC_FETCH_SITE'=>'same-origin','HTTP_HOST'=>'example.test','HTTP_USER_AGENT'=>'Mozilla/5.0 Chrome/140.0 Safari/537.36');
$options['home']='https://example.test';$before=count($wpdb->queries);
check('Fast path counts a plain hit',AceCrawlAggregateViews::fast_hit($fast,'{"v":1}') && count($wpdb->queries)===$before+1 && end($wpdb->queries)[1]===array('2026-10-14'));
check('Fast path accepts the null origin Safari sends',AceCrawlAggregateViews::fast_hit(array('HTTP_ORIGIN'=>'null')+$fast,'{"v":1}'));
$before=count($wpdb->queries);
foreach(array('other origin'=>array('HTTP_ORIGIN'=>'https://evil.test'),'cross-site fetch'=>array('HTTP_SEC_FETCH_SITE'=>'cross-site'),'another host'=>array('HTTP_HOST'=>'topic.example.test')) as $label=>$change){check('Fast path leaves '.$label.' to the route',!AceCrawlAggregateViews::fast_hit($change+$fast,'{"v":1}'));}
foreach(array('{"v":1,"url":"/x"}','{"v":2}','',str_repeat('a',33)) as $bad){check('Fast path leaves an odd body to the route',!AceCrawlAggregateViews::fast_hit($fast,$bad));}
$options[AceCrawlAggregateViews::OPTION]=0;check('Fast path does nothing when the counter is off',!AceCrawlAggregateViews::fast_hit($fast,'{"v":1}'));$options[AceCrawlAggregateViews::OPTION]=1;
$GLOBALS['multisite']=true;check('Fast path stays out of multisite',!AceCrawlAggregateViews::fast_hit($fast,'{"v":1}'));$GLOBALS['multisite']=false;
check('Fast path wrote nothing for refused requests',count($wpdb->queries)===$before);
foreach(array('HTTP_DNT'=>'1','HTTP_SEC_GPC'=>'1','HTTP_USER_AGENT'=>'Googlebot') as $key=>$value){check('Fast path answers '.$key.' without writing',AceCrawlAggregateViews::fast_hit(array($key=>$value)+$fast,'{"v":1}') && count($wpdb->queries)===$before);}
$wpdb->fail=true;check('Fast path hands a failed write to the route',!AceCrawlAggregateViews::fast_hit($fast,'{"v":1}'));$wpdb->fail=false;

ob_start(); AceCrawlAggregateViews::privacy(); $html=ob_get_clean();
check('Visitor UI hidden by default', '' === $html && !AceCrawlAggregateViews::show_privacy());
$GLOBALS['show_privacy']=true;
check('Sites can explicitly restore visitor UI', AceCrawlAggregateViews::show_privacy());
echo $n.' checks passed'.PHP_EOL;
