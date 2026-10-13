<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define('ABSPATH',__DIR__.'/');define('HOUR_IN_SECONDS',3600);
require dirname(__DIR__).'/includes/admin/class-ace-seo-tracking-google.php';
class WP_Error {}
function is_wp_error($r){return $r instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function wp_remote_post($url,$args){$GLOBALS['calls'][]=array($url,json_decode($args['body'],true));return array_shift($GLOBALS['responses']);}
function wp_remote_retrieve_body($r){return json_encode($r['body']);}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function get_transient($k){return $GLOBALS['cache'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['cache'][$k]=$v;}
class AceSEOSiteKit {
 const SCOPE_ANALYTICS='analytics';const SCOPE_SEARCH_CONSOLE='search';
 static function is_active(){return true;}static function get_analytics_property_id(){return '123';}
 static function get_search_console_property_id(){return 'https://example.com/';}static function get_access_token($s){return 'test';}
}
$checks=0;$calls=$responses=$cache=array();
function expect($ok,$message){++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($message);}
function row($date,$channel,$source,$value){return array('dimensionValues'=>array(array('value'=>$date),array('value'=>$channel),array('value'=>$source)),'metricValues'=>array(array('value'=>$value)));}
function response($rows,$count=null){return array('code'=>200,'body'=>array('rows'=>$rows,'rowCount'=>$count??count($rows),'metadata'=>array('timeZone'=>'Europe/London')));}
$dates=array('2026-10-01','2026-10-02','2026-10-03');
expect('source_ai'===AceSeoTrackingGoogle::channel('AI Assistant','(not set)')[0],'Native Google AI Assistant channel is recognised.');
expect('source_ai'===AceSeoTrackingGoogle::channel('Referral','www.perplexity.ai')[0],'Recognised AI subdomains are classified.');
expect('source_ai'!==AceSeoTrackingGoogle::channel('Referral','chatgpt.com.evil.test')[0],'Lookalike AI domains are not classified as AI.');
expect('source_ai'!==AceSeoTrackingGoogle::channel('Organic Search','google.com')[0],'Unidentified Google search traffic is not claimed as AI.');
$r=array('ok'=>true,'rows'=>array(row('20261001','Referral','chatgpt.com','4'),row('20261001','AI Assistant','(not set)','3'),row('20261001','Direct','(direct)','8'),row('20261002','Organic Social','facebook.com','2')));
$s=AceSeoTrackingGoogle::sources($r,$dates);
expect(7.0===$s['days'][$dates[0]]['source_ai'],'Native and identified AI referral rows aggregate into one band.');
expect(15.0===array_sum($s['days'][$dates[0]]),'Every source row appears once in the stacked total.');
expect(0===array_sum($s['days'][$dates[2]]),'Successful empty dates have zero source sessions.');
$r['rows'][]=row('20261001','Direct','(direct)','bad');
expect(!AceSeoTrackingGoogle::sources($r,$dates)['ok'],'Invalid metrics do not produce partial sources.');
$responses=array(response(array(row('20261001','Direct','(direct)','5')),2),response(array(row('20261002','Referral','example.org','6'))));
$r=AceSeoTrackingGoogle::report('123','test',$dates,array('date','sessionDefaultChannelGroup','sessionSource'),array('sessions'));
expect($r['ok']&&count($r['rows'])===2&&$calls[1][1]['offset']===5000,'All pages are requested and merged.');
$responses=array(response(array(row('20261001','Direct','(direct)','5')),2),array('code'=>403,'body'=>array()));
$r=AceSeoTrackingGoogle::report('123','test',$dates,array('date'),array('sessions'));
expect(!$r['ok']&&!isset($r['rows']),'A failed later page cannot leak a partial report.');
$r=array('ok'=>true,'rows'=>array(array('dimensionValues'=>array(array('value'=>'20261001'),array('value'=>'Campaign A')),'metricValues'=>array(array('value'=>'10'),array('value'=>'100'))),array('dimensionValues'=>array(array('value'=>'20261001'),array('value'=>'Campaign B')),'metricValues'=>array(array('value'=>'4'),array('value'=>'50')))));
$ads=AceSeoTrackingGoogle::counts($r,$dates,array('ads_clicks','ads_impressions'));
expect(14.0===$ads['days'][$dates[0]]['ads_clicks']&&150.0===$ads['days'][$dates[0]]['ads_impressions'],'Campaign dimensions aggregate into daily ad clicks and impressions.');
$responses=array(array('code'=>200,'body'=>array('rows'=>array(array('keys'=>array('2026-10-01'),'clicks'=>0,'impressions'=>15)))));
$search=AceSeoTrackingGoogle::search($dates);
expect($search['ok']&&0.0===$search['days'][$dates[0]]['search_clicks'],'Explicit zero search clicks are retained.');
expect(null===$search['days'][$dates[2]]['search_clicks'],'Unreported or delayed Search Console days remain missing.');
expect('final'===end($calls)[1]['dataState'],'Search Console only requests final daily data.');
$responses=array(response(array(row('20261001','Direct','(direct)','3'))),response(array()),array('code'=>403,'body'=>array()),array('code'=>200,'body'=>array('rows'=>array(array('keys'=>array('2026-10-01'),'clicks'=>2,'impressions'=>20)))));
$g=AceSeoTrackingGoogle::series($dates);
expect(isset($g['definitions']['search_clicks'])&&!isset($g['definitions']['ads_clicks']),'Failed advertising access does not hide working SEO reports or invent zero ad clicks.');
$before=count($calls);AceSeoTrackingGoogle::series($dates);
expect(count($calls)===$before,'Additional Google reports reuse their hourly cache.');
expect(in_array('sessionCampaignName',array_column($calls[$before-2][1]['dimensions'],'name'),true),'Ads reporting requests the required campaign dimension.');
$responses=array(array('code'=>200,'body'=>array()));
expect(!AceSeoTrackingGoogle::report('123','test',$dates,array('date'),array('sessions'))['ok'],'Malformed successful responses are not treated as zero traffic.');
$responses=array(array('code'=>200,'body'=>array('rows'=>array(array('keys'=>array('2026-10-01'),'clicks'=>-1,'impressions'=>20)))));
expect(!AceSeoTrackingGoogle::search($dates)['ok'],'Invalid Search Console values do not return a partial report.');
$cache=array();$responses=array(response(array()),response(array()),response(array()),array('code'=>200,'body'=>array()));
$empty=AceSeoTrackingGoogle::series($dates);
expect(!isset($empty['definitions']['ads_clicks'])&&isset($empty['status']['Advertising']),'No advertising report is distinct from a measured zero day.');
echo "$checks Google tracking checks passed.\n";
