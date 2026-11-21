<?php
/** Actual decoder/API code with synthetic HTTP, options and cache. No WordPress bootstrap. */
if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) { exit(1); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$checks=0; $response=[]; $cached=false; $writes=[]; $requests=[];
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code,$message,$data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function add_action(...$args){}
function is_wp_error($value){return $value instanceof WP_Error;}
function get_option($name,$default=[]){return ['performance'=>['pagespeed_api_key'=>'fixture-key-not-real']];}
function get_transient($key){global $cached;return $cached;}
function set_transient($key,$value,$ttl){global $writes;$writes[]=[$key,$value,$ttl];return true;}
function wp_remote_get($url,$args){global $response,$requests;if(strpos($url,'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?')!==0)throw new RuntimeException('Unexpected fixture URL');$requests[]=[$url,$args];return $response;}
function wp_remote_retrieve_body($value){return $value['body'];}
function wp_remote_retrieve_response_code($value){return $value['status'];}
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n";}
function request_fixture($body,$status=200){global $response;$response=['body'=>$body,'status'=>$status];return AceCrawlApiHelper::make_pagespeed_request('https://example.invalid/path/?token=private-fixture-token');}
function error_has_no_payload($error){$text=$error->get_error_message().json_encode($error->get_error_data());foreach(['private-fixture-token','secret@example.invalid','private-fixture-key','https://example.invalid','rawBodyMarker'] as $secret){if(strpos($text,$secret)!==false)return false;}return true;}
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
try{
    require dirname(__DIR__).'/includes/ace-json.php';
    require getenv('ACECRAWL_API_SOURCE') ?: dirname(__DIR__).'/includes/admin/class-ace-seo-api-helper.php';
    if(getenv('ACECRAWL_JSON_CONTROL')==='shape'){
        $error=request_fixture('{"lighthouseResult":42}');
        check(is_wp_error($error)&&count($writes)===0,'invalid Lighthouse schema is not cached');
        return;
    }
    foreach(['[]'=>[], '{}'=>[], '{"a":1}'=>['a'=>1], '[false,null,0]'=>[false,null,0]] as $raw=>$expected){
        $value=acecrawl_json_array_result($raw);
        check($value['ok']&&$value['value']===$expected&&$value['error']===null,'valid JSON array/object retained '.$raw);
    }
    foreach(['null','false','0','1.5','"private-fixture-token"'] as $raw){
        $value=acecrawl_json_array_result($raw);
        check(!$value['ok']&&$value['value']===null&&$value['error']['code']==='unexpected_json_shape'&&$value['error']['json_error_code']===0,'valid scalar distinguished from parse failure');
    }
    foreach(['{','{"token":"private-fixture-token",}','"unterminated',"{\"value\":\"\xB1\"}",'[1,]'] as $raw){
        $value=acecrawl_json_array_result($raw);
        check(!$value['ok']&&$value['value']===null&&$value['error']['code']==='invalid_json'&&$value['error']['json_error_code']>0,'malformed JSON has a parser code');
        check(strpos(json_encode($value),'private-fixture-token')===false&&!isset($value['error']['message']),'diagnostic omits body and native message');
    }
    $value=acecrawl_json_array_result(null);
    check(!$value['ok']&&$value['error']['code']==='invalid_json_input','non-string input safely rejected');
    $value=acecrawl_json_array_result('{');
    if(PHP_VERSION_ID>=80600){check(($value['error']['line']??null)===1&&($value['error']['column']??null)===2,'PHP 8.6 native JSON location available without exposing message');}
    $start=count($writes);
    $error=request_fixture('{"rawBodyMarker":"private-fixture-token",}');
    $detail=is_wp_error($error)?$error->get_error_data():null;
    check(is_wp_error($error)&&$error->get_error_code()==='pagespeed_invalid_response'&&is_array($detail)&&isset($detail['json_error']['json_error_code'])&&$detail['json_error']['json_error_code']===JSON_ERROR_SYNTAX,'API exposes bounded parser diagnostic');
    check(error_has_no_payload($error),'malformed API error redacts credentials and body');
    foreach(['null','[]','{}','42','"secret@example.invalid"','{"lighthouseResult":42}','{"lighthouseResult":"rawBodyMarker"}','{"lighthouseResult":{"categories":42}}'] as $raw){
        $error=request_fixture($raw);
        check(is_wp_error($error)&&error_has_no_payload($error),'wrong API schema returns safe error');
    }
    check(count($writes)===$start,'malformed and wrong-schema API results never cached');
    $error=request_fixture('{"error":{"message":"secret@example.invalid https://example.invalid/?token=private-fixture-token rawBodyMarker"}}',403);
    check(is_wp_error($error)&&$error->get_error_code()==='pagespeed_request_failed'&&$error->get_error_data()['status']===403&&error_has_no_payload($error),'HTTP failure retains status without upstream payload');
    $error=request_fixture('rawBodyMarker private-fixture-token',429);
    check(is_wp_error($error)&&$error->get_error_data()['status']===429&&isset($error->get_error_data()['json_error'])&&error_has_no_payload($error),'non-JSON HTTP error retains status and safe JSON diagnostic');
    $good=['lighthouseResult'=>['categories'=>['performance'=>['score'=>0.92],'seo'=>['score'=>null]]]];
    $result=request_fixture(json_encode($good));
    check($result===$good&&count($writes)===$start+1&&$writes[count($writes)-1][1]===$good,'valid structured report retained and cached');
    check($writes[count($writes)-1][2]===600,'cache TTL unchanged');
    $request=$requests[count($requests)-1];parse_str(parse_url($request[0],PHP_URL_QUERY),$query);
    check($query['url']==='https://example.invalid/path/?token=private-fixture-token'&&$query['strategy']==='mobile'&&$query['key']==='fixture-key-not-real'&&$request[1]['timeout']===90,'request URL encoding, strategy, authentication and timeout preserved');
    $cached=$good;$before=count($requests);check(AceCrawlApiHelper::make_pagespeed_request('https://example.invalid/')===$good&&count($requests)===$before,'existing cache hit avoids HTTP');$cached=false;
    $response=new WP_Error('http_request_failed','Synthetic timed out');$error=AceCrawlApiHelper::make_pagespeed_request('https://example.invalid/');
    check(is_wp_error($error)&&$error->get_error_code()==='pagespeed_timeout','existing timeout contract preserved');
    echo "Completed $checks JSON/API checks on PHP ".PHP_VERSION."; no real HTTP, WordPress data, mail or cron\n";
}finally{restore_error_handler();}
