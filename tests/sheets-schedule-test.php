<?php
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}

// Independent production-worker regression. Synthetic DB, cron and Sheets; no network or WP bootstrap.
define('ABSPATH', __DIR__.'/'); define('DAY_IN_SECONDS',86400); define('HOUR_IN_SECONDS',3600);
class WP_Error {public $code;public $message; function __construct($c,$m){$this->code=$c;$this->message=$m;}function get_error_message(){return $this->message;}}
class FixtureStop extends Exception{}
function is_wp_error($v){return $v instanceof WP_Error;}
function maybe_serialize($v){return serialize($v);} function maybe_unserialize($v){return unserialize($v);}
class FixtureDB {
 public $options='options';public $posts='posts';public $last_error='';public $data=[];public $ids=[];public $queries=0;public $fail_ids=false;
 function prepare($sql,...$args){return [$sql,count($args)===1&&is_array($args[0])?$args[0]:$args];}
 function get_var($p){return isset($this->data[$p[1][0]])?serialize($this->data[$p[1][0]]):null;}
 function get_col($p){$this->queries++;if($this->fail_ids){$this->last_error='synthetic failure';return [];} $this->last_error='';return $this->ids;}
 function query($p){[$q,$a]=$p;
 if(strpos($q,'INSERT IGNORE')===0){if(isset($this->data[$a[0]]))return 0;$this->data[$a[0]]=unserialize($a[1]);return 1;}
 if(strpos($q,'UPDATE ')===0){if(!isset($this->data[$a[1]])||serialize($this->data[$a[1]])!==$a[2])return 0;$this->data[$a[1]]=unserialize($a[0]);return 1;}
 if(strpos($q,'DELETE ')===0){if(!isset($this->data[$a[0]])||serialize($this->data[$a[0]])!==$a[1])return 0;unset($this->data[$a[0]]);return 1;}
 if(strpos($q,'INSERT INTO')===0){if(!isset($this->data[$a[2]])||serialize($this->data[$a[2]])!==$a[3])return 0;$this->data[$a[0]]=unserialize($a[1]);return 1;}
 throw new RuntimeException('Unknown fixture query');
 }
}
$GLOBALS['wpdb']=new FixtureDB();$GLOBALS['checks']=[];$GLOBALS['uuid']=0;$GLOBALS['primed']=[];$GLOBALS['cron']=[];$GLOBALS['cap']=true;$GLOBALS['nonce']=true;$GLOBALS['post_ids']=[];$GLOBALS['noindex']=false;
function wp_generate_uuid4(){return 'fixture-'.++$GLOBALS['uuid'];}
function wp_cache_delete($k,$g){$GLOBALS['deletes'][]=$k;return true;}
function add_filter($h,$cb){$GLOBALS['hooks'][$h]=$cb;}function add_action($h,$cb){$GLOBALS['hooks'][$h]=$cb;}
function is_admin(){return false;}function wp_doing_cron(){return true;}
function wp_next_scheduled($h){return $GLOBALS['cron'][$h]['timestamp']??false;}
function wp_schedule_single_event($t,$h,$args=[],$e=false){$GLOBALS['cron'][$h]=['timestamp'=>$t,'schedule'=>false];return true;}
function wp_schedule_event($t,$s,$h){$GLOBALS['cron'][$h]=['timestamp'=>$t,'schedule'=>$s];return true;}
function wp_get_scheduled_event($h){return isset($GLOBALS['cron'][$h])?(object)$GLOBALS['cron'][$h]:false;}
function wp_get_schedules(){return ['daily'=>['interval'=>86400],'weekly'=>['interval'=>604800],'ace_seo_four_weeks'=>['interval'=>2419200]];}
function wp_clear_scheduled_hook($h){unset($GLOBALS['cron'][$h]);}
function sanitize_key($x){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$x));}
function sanitize_text_field($x){return strip_tags($x);}function wp_unslash($x){return $x;}
function current_user_can($x){return $GLOBALS['cap'];}function check_admin_referer($x){return $GLOBALS['nonce'];}
function wp_die($m){throw new FixtureStop('die:'.$m);}function wp_safe_redirect($u){throw new FixtureStop('redirect');}
function admin_url($s){return '/admin/'.$s;}function update_option($n,$v,$a=false){$GLOBALS['wpdb']->data[$n]=$v;return true;}
function set_transient($k,$v,$t){return true;}function get_current_user_id(){return 1;}
function wp_date($f,$t=null){return gmdate($f,$t??time());}
function apply_filters($h,$v,...$args){return $v;}
function get_post_types($a,$o){return [(object)['name'=>'post','public'=>true],(object)['name'=>'product','public'=>true],(object)['name'=>'internal','public'=>false],(object)['name'=>'attachment','public'=>true]];}
function is_post_type_viewable($p){return $p->public;}
function _prime_post_caches($ids,$terms=true,$meta=true){$GLOBALS['primed'][]=['ids'=>$ids,'terms'=>$terms,'meta'=>$meta];}
function get_post($id){return $GLOBALS['post_ids'][$id]??null;}
function get_the_title($p){return 'Synthetic '.$p->ID;}function get_permalink($p){return 'https://example.test/item/'.$p->ID;}
function get_post_time($f,$g,$p){return '2026-10-01';}function get_post_modified_time($f,$g,$p){return '2026-10-02';}
function get_post_meta($id,$k,$s){return '';}
function ace_seo_site_is_discouraged(){return $GLOBALS['noindex'];}
class AceCrawlEnhancer {static function get_meta_value($id,$key){return '';}}
// Copy the actual implementation unchanged to resolve its dependency path against a fake API boundary.
$source=getenv('ACE_SCHEDULE_REVIEW_SOURCE')?:dirname(__DIR__) . '/includes';
$harness=sys_get_temp_dir().'/ace-sheets-worker-fixture-'.getmypid();mkdir($harness);mkdir($harness.'/admin');
foreach(['class-ace-seo-sheets-schedule.php','class-ace-seo-export.php'] as $f){copy($source.'/'.$f,$harness.'/'.$f);}
file_put_contents($harness.'/admin/class-ace-seo-sheets.php', <<<'STUB'
<?php
class AceSeoSheets {
 static $destination='frozen-sheet';static $configured=true;static $writes=[];static $publishes=[];static $stages=[];static $fail_next=false;static $after_write=null;
 static function configured(){return self::$configured;}static function settings(){return ['sheet_id'=>self::$destination];}
 static function ensure_snapshot_tab($d,$t,$id,$r,$c,$allow=true){self::$stages[]=$id;return true;}
 static function discard_snapshot_tab($d,$id,$t){return true;}
 static function write_snapshot_rows($d,$id,$first,$rows){self::$writes[]=[$d,$id,$first,$rows];if(self::$after_write){$f=self::$after_write;self::$after_write=null;$f();}if(self::$fail_next){self::$fail_next=false;return new WP_Error('timeout','Synthetic timeout');}return true;}
 static function publish_snapshot(...$args){self::$publishes[]=$args;return true;}
}
STUB);
require $harness.'/admin/class-ace-seo-sheets.php';require $harness.'/class-ace-seo-export.php';require $harness.'/class-ace-seo-sheets-schedule.php';
function verify($n,$v){$GLOBALS['checks'][]=['name'=>$n,'pass'=>(bool)$v];}
function reset_case($count=0){$GLOBALS['wpdb']=new FixtureDB();$GLOBALS['cron']=[];$GLOBALS['primed']=[];$GLOBALS['post_ids']=[];$GLOBALS['deletes']=[];$GLOBALS['cap']=true;$GLOBALS['nonce']=true;AceSeoSheets::$destination='frozen-sheet';AceSeoSheets::$configured=true;AceSeoSheets::$writes=[];AceSeoSheets::$publishes=[];AceSeoSheets::$stages=[];AceSeoSheets::$fail_next=false;AceSeoSheets::$after_write=null;
 for($id=1;$id<=$count;$id++){$GLOBALS['wpdb']->ids[]=$id;$GLOBALS['post_ids'][$id]=(object)['ID'=>$id,'post_type'=>'post','post_status'=>'publish'];}}
function job(){return $GLOBALS['wpdb']->data[AceSeoSheetsSchedule::JOB]??[];}
function enable($frequency='weekly'){$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::OPTION]=['frequency'=>$frequency,'post_types'=>['post'],'include_unpublished'=>0,'stop_generation'=>0];}
function tick(){unset($GLOBALS['cron'][AceSeoSheetsSchedule::TICK]);AceSeoSheetsSchedule::tick();}
function invoke($method){try{AceSeoSheetsSchedule::$method();return 'returned';}catch(FixtureStop $e){return $e->getMessage();}}
reset_case();AceSeoSheetsSchedule::init();verify('cron hooks register outside admin',isset($GLOBALS['hooks'][AceSeoSheetsSchedule::START],$GLOBALS['hooks'][AceSeoSheetsSchedule::TICK]));
AceSeoSheetsSchedule::start();verify('default off creates no job and scans no posts',AceSeoSheetsSchedule::settings()['frequency']==='off'&&!job()&&$GLOBALS['wpdb']->queries===0);
reset_case(620);AceSeoSheetsSchedule::start(true);$j=job();verify('manual run starts while automatic schedule is off',$j['manual']===true&&$j['total']===620&&$j['status']==='queued');
AceSeoSheetsSchedule::sync_schedule();verify('manual run keeps continuation while recurring schedule remains off',isset($GLOBALS['cron'][AceSeoSheetsSchedule::TICK])&&!isset($GLOBALS['cron'][AceSeoSheetsSchedule::START]));
AceSeoSheets::$destination='new-destination';$GLOBALS['wpdb']->ids[]=9999;tick();$j=job();verify('one tick writes at most 500 items without re-scanning IDs',$j['written']===500&&$j['cursor']===500&&$GLOBALS['wpdb']->queries===1&&count(AceSeoSheets::$writes)===3);
verify('in-flight destination and scope stay fixed',$j['destination']==='frozen-sheet'&&$j['types']===['post']&&$j['total']===620&&count(array_filter(AceSeoSheets::$writes,fn($w)=>$w[0]!=='frozen-sheet'))===0);
verify('bounded item batches prime posts, terms and metadata',count(array_filter($GLOBALS['primed'],fn($p)=>count($p['ids'])>250||!$p['terms']||!$p['meta']))===0);
tick();verify('last item batch defers atomic publication to its own tick',job()['written']===620&&job()['phase']==='publish'&&!AceSeoSheets::$publishes);tick();verify('completed job publishes once and drops the frozen ID payload',job()['status']==='complete'&&!isset(job()['ids'])&&count(AceSeoSheets::$publishes)===1);tick();verify('a completed job is not published again',count(AceSeoSheets::$publishes)===1);
reset_case(1);$lease=AceSeoSheetsSchedule::acquire();verify('active database lease excludes a second worker',is_array($lease)&&false===AceSeoSheetsSchedule::acquire());$expired=$lease;$expired['expires']=time()-1;$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::LOCK]=$expired;$replacement=AceSeoSheetsSchedule::acquire();AceSeoSheetsSchedule::release($lease);verify('stale owner cannot release replacement lease',is_array($replacement)&&AceSeoSheetsSchedule::owns($replacement));AceSeoSheetsSchedule::release($replacement);
reset_case(2);enable();AceSeoSheetsSchedule::start();AceSeoSheets::$fail_next=true;tick();verify('failed header enters retry without falsely advancing rows',job()['status']==='retrying'&&job()['written']===0&&job()['phase']==='header');$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::JOB]['retry_at']=time()-1;tick();verify('retry recovers same header range and continues',AceSeoSheets::$writes[0]===AceSeoSheets::$writes[1]&&job()['written']===2);
reset_case(4);enable();AceSeoSheetsSchedule::start();AceSeoSheets::$after_write=function(){AceSeoSheets::$fail_next=true;};tick(); // header fails before pending; recover then fail an actual data batch.
$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::JOB]['retry_at']=time()-1;AceSeoSheets::$after_write=function(){AceSeoSheets::$after_write=function(){AceSeoSheets::$fail_next=true;};};tick();$j=job();
verify('data failure persists exact pending payload before retry',isset($j['pending'])&&$j['written']===0&&$j['status']==='retrying');$payload=end(AceSeoSheets::$writes);unset($GLOBALS['post_ids'][1]);$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::JOB]['retry_at']=time()-1;tick();verify('retry reuses frozen payload even if a post changed meanwhile',$payload===end(AceSeoSheets::$writes)&&job()['written']===4);
reset_case(3);enable();$GLOBALS['wpdb']->fail_ids=true;AceSeoSheetsSchedule::start();verify('ID-query failure becomes visible failed job without API writes',job()['status']==='failed'&&isset(job()['error'])&&!AceSeoSheets::$writes);
reset_case(3);enable();AceSeoSheetsSchedule::start();unset($GLOBALS['post_ids'][2]);$GLOBALS['post_ids'][3]->post_status='private';tick();verify('deleted or newly excluded items are skipped',job()['written']===1&&job()['skipped']===2);
$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::OPTION]['frequency']='off';tick();verify('stop before publication leaves prior report untouched',job()['status']==='cancelled'&&!AceSeoSheets::$publishes);
reset_case(2);enable('off');AceSeoSheetsSchedule::start(true);$GLOBALS['wpdb']->data['ace_seo_sheets']=['sheet_id'=>'preserved','key'=>'synthetic-secret'];$GLOBALS['wpdb']->data['ace_seo_retention_options']=['sentinel'=>'keep'];$_POST=['frequency'=>'off','post_types'=>['product'],'include_unpublished'=>'1'];$generation=AceSeoSheetsSchedule::settings()['stop_generation'];invoke('handle_settings');verify('saving manual scope while already off does not cancel the current manual job',AceSeoSheetsSchedule::settings()['stop_generation']===$generation);verify('schedule save does not reset Sheets credentials or retention policy',$GLOBALS['wpdb']->data['ace_seo_sheets']===['sheet_id'=>'preserved','key'=>'synthetic-secret']&&$GLOBALS['wpdb']->data['ace_seo_retention_options']===['sentinel'=>'keep']);tick();verify('new scope applies only to later runs',job()['types']===['post']&&job()['statuses']===['publish']&&job()['status']!=='cancelled');
reset_case();$_POST=['frequency'=>'weekly','post_types'=>['post']];$GLOBALS['cap']=false;$denied=invoke('handle_settings');verify('settings require manage_options',strpos($denied,'die:')===0&&!isset($GLOBALS['wpdb']->data[AceSeoSheetsSchedule::OPTION]));$GLOBALS['cap']=true;$GLOBALS['nonce']=false;$denied=invoke('handle_settings');verify('settings require valid nonce',strpos($denied,'die:')===0&&!isset($GLOBALS['wpdb']->data[AceSeoSheetsSchedule::OPTION]));
reset_case();$GLOBALS['noindex']=true;$GLOBALS['post_ids'][1]=(object)['ID'=>1,'post_type'=>'post','post_status'=>'publish'];verify('shared formatter honours native site-wide search visibility',AceSeoExport::rows([1])[0][17]==='no');verify('generic viewable types work without site constants',AceSeoExport::post_types()===['post','product']);

reset_case(1);enable();AceSeoSheetsSchedule::start(true);$_POST=['frequency'=>'off','post_types'=>['post']];invoke('handle_settings');tick();verify('turning automatic schedule off does not cancel a manual refresh',job()['manual']===true&&job()['status']!=='cancelled');
reset_case(1);enable();AceSeoSheetsSchedule::start();$_POST=['frequency'=>'off','post_types'=>['post']];invoke('handle_settings');tick();verify('turning automatic schedule off cancels its existing automatic run',job()['status']==='cancelled'&&!AceSeoSheets::$publishes);
reset_case(1);enable('off');AceSeoSheetsSchedule::start(true);tick();invoke('handle_stop');tick();verify('explicit Stop cancels manual job before publication',job()['status']==='cancelled'&&!AceSeoSheets::$publishes&&!isset($GLOBALS['cron'][AceSeoSheetsSchedule::TICK]));
reset_case(1);enable();AceSeoSheetsSchedule::start();unset($GLOBALS['cron'][AceSeoSheetsSchedule::TICK]);AceSeoSheetsSchedule::sync_schedule();verify('normal scheduler bootstrap recovers dropped continuation',isset($GLOBALS['cron'][AceSeoSheetsSchedule::TICK]));
reset_case(1);enable();AceSeoSheetsSchedule::start();for($i=0;$i<5;$i++){AceSeoSheets::$fail_next=true;if(isset($GLOBALS['wpdb']->data[AceSeoSheetsSchedule::JOB]['retry_at']))$GLOBALS['wpdb']->data[AceSeoSheetsSchedule::JOB]['retry_at']=time()-1;tick();}verify('five consecutive failures pause the job without endless retries',job()['status']==='failed'&&job()['failures']===5&&!isset($GLOBALS['cron'][AceSeoSheetsSchedule::TICK]));

reset_case(3);enable();$GLOBALS['wpdb']->fail_ids=true;AceSeoSheetsSchedule::start();$failed_id=job()['id'];$GLOBALS['wpdb']->fail_ids=false;invoke('handle_retry');verify('retry after initial ID-query failure captures a fresh complete scope',job()['status']==='queued'&&job()['total']===3&&count(job()['ids'])===3&&job()['id']!==$failed_id&&!AceSeoSheets::$writes&&!AceSeoSheets::$stages);tick();verify('retried setup failure exports real items rather than an empty report',job()['written']===3&&!AceSeoSheets::$publishes);
reset_case(2);enable();AceSeoSheets::$configured=false;AceSeoSheetsSchedule::start();$before=job();AceSeoSheets::$configured=true;invoke('handle_retry');verify('restored connection retry captures scope before any API writes',$before['status']==='failed'&&job()['total']===2&&job()['status']==='queued'&&!AceSeoSheets::$writes&&!AceSeoSheets::$stages);
$failed=array_values(array_filter($GLOBALS['checks'],fn($c)=>!$c['pass']));echo json_encode(['passed'=>count($GLOBALS['checks'])-count($failed),'total'=>count($GLOBALS['checks']),'failed'=>$failed],JSON_PRETTY_PRINT).PHP_EOL;
unlink($harness.'/admin/class-ace-seo-sheets.php');rmdir($harness.'/admin');unlink($harness.'/class-ace-seo-sheets-schedule.php');unlink($harness.'/class-ace-seo-export.php');rmdir($harness);exit($failed?1:0);
