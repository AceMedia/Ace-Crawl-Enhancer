<?php
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}

// Independent isolated API boundaries. No WordPress DB, network, service-account key or live sheet.
define('ABSPATH', __DIR__.'/');
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code,$message,$data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_transient($key){return 'synthetic-token';}
function get_option($key,$default=false){return ['sheet_id'=>'default-sheet-must-not-be-used'];}
function wp_json_encode($value){return json_encode($value);}
function wp_remote_retrieve_body($response){return $response['body'];}
function wp_remote_retrieve_response_code($response){return $response['code'];}
$GLOBALS['responses']=[];$GLOBALS['calls']=[];$GLOBALS['checks']=[];
function wp_remote_request($url,$args){
    $GLOBALS['calls'][]=['url'=>$url,'method'=>$args['method'],'body'=>isset($args['body'])?json_decode($args['body'],true):null,'raw_body'=>$args['body']??null];
    if(!$GLOBALS['responses'])throw new RuntimeException('Unexpected synthetic HTTP request.');
    $next=array_shift($GLOBALS['responses']);
    return is_callable($next)?$next(end($GLOBALS['calls'])):$next;
}
function response($body,$code=200){$GLOBALS['responses'][]=['code'=>$code,'body'=>is_string($body)?$body:json_encode($body)];}
function reset_fixture(){$GLOBALS['responses']=[];$GLOBALS['calls']=[];}
function verify($name,$result){$GLOBALS['checks'][]=['name'=>$name,'pass'=>(bool)$result];}
require getenv('ACE_SHEETS_REVIEW_SOURCE') ?: dirname(__DIR__) . '/includes/admin/class-ace-seo-sheets.php';

function sheet($id,$title,$extra=[]){return array_merge(['properties'=>['sheetId'=>$id,'title'=>$title]],$extra);}
function confirm_publish($run='run-one',$id=88){
    $GLOBALS['responses'][]=function($call)use($run,$id){
        $replies=array_fill(0,count($call['body']['requests']),[]);
        $replies[count($replies)-1]=['createDeveloperMetadata'=>['developerMetadata'=>['metadataValue'=>$run,'location'=>['sheetId'=>$id]]]];
        return ['code'=>200,'body'=>json_encode(['replies'=>$replies])];
    };
}
function request_of($kind,$requests){foreach($requests as $r){if(isset($r[$kind]))return $r[$kind];}return null;}
function basic_report($extra=[]){
    return array_replace_recursive(sheet(88,'Ace SEO report'),[
        'properties'=>['gridProperties'=>['rowCount'=>100,'columnCount'=>30]],
        'developerMetadata'=>[
            ['metadataId'=>1,'metadataKey'=>'ace_seo_report_run','metadataValue'=>'old-run'],
            ['metadataId'=>2,'metadataKey'=>'ace_seo_report_columns','metadataValue'=>'5'],
            ['metadataId'=>3,'metadataKey'=>'ace_seo_report_rows','metadataValue'=>'20'],
        ],
        'basicFilter'=>['range'=>['sheetId'=>88,'endRowIndex'=>20,'endColumnIndex'=>5], 'criteria'=>[4=>['hiddenValues'=>['old']]],'sortSpecs'=>[['dimensionIndex'=>4,'sortOrder'=>'ASCENDING']]],
    ],$extra);
}
reset_fixture(); response(['sheets'=>[]]); response(['replies'=>[['addSheet'=>['properties'=>['sheetId'=>77,'title'=>'working']]]]]);
$r=AceSeoSheets::ensure_snapshot_tab('explicit-destination','working',77,1501,24);
$add=$GLOBALS['calls'][1]['body']['requests'][0]['addSheet']['properties'];
verify('new stage is hidden with sufficient explicit grid and frozen header',true===$r && $add['hidden']===true && $add['gridProperties']===['rowCount'=>1501,'columnCount'=>24,'frozenRowCount'=>1]);
verify('all calls use frozen destination not connection default',count(array_filter($GLOBALS['calls'],fn($c)=>strpos($c['url'],'explicit-destination')!==false))===2);
foreach([sheet(77,'renamed'),sheet(999,'working')] as $collision){reset_fixture();response(['sheets'=>[$collision]]);$r=AceSeoSheets::ensure_snapshot_tab('frozen','working',77,20,24);verify('identity conflict prevents creation '. $collision['properties']['sheetId'],is_wp_error($r)&&count($GLOBALS['calls'])===1);}
reset_fixture();response(['sheets'=>[]]);$r=AceSeoSheets::ensure_snapshot_tab('frozen','working',77,20,24,false);verify('removed partial stage is never recreated',is_wp_error($r)&&count($GLOBALS['calls'])===1);
reset_fixture();response(['sheets'=>[]]);$GLOBALS['responses'][]=new WP_Error('timeout','Synthetic timeout');$r=AceSeoSheets::ensure_snapshot_tab('frozen','working',77,20,24);response(['sheets'=>[sheet(77,'working')]]);$again=AceSeoSheets::ensure_snapshot_tab('frozen','working',77,20,24);verify('lost stage creation reply resumes without duplicate tab',is_wp_error($r)&&true===$again&&count(array_filter($GLOBALS['calls'],fn($c)=>$c['method']==='POST'))===1);
reset_fixture();$rows=[['=1+1',null,0],['Café','plain',7]];$GLOBALS['responses'][]=new WP_Error('timeout','Synthetic timeout');$r=AceSeoSheets::write_snapshot_rows('frozen',77,251,$rows);response(['totalUpdatedRows'=>2]);$again=AceSeoSheets::write_snapshot_rows('frozen',77,251,$rows);
$body=$GLOBALS['calls'][0]['body'];verify('lost write reply retries exact payload and fixed numeric cells',is_wp_error($r)&&true===$again&&$GLOBALS['calls'][0]===$GLOBALS['calls'][1]&&$body['data'][0]['dataFilter']['gridRange']===['sheetId'=>77,'startRowIndex'=>250,'startColumnIndex'=>0]);
verify('writes use RAW so formula-shaped text stays text; null and zero stay distinct',$body['valueInputOption']==='RAW'&&$body['data'][0]['values'][0]===['=1+1','',0]&&strpos($GLOBALS['calls'][0]['url'],'batchUpdateByDataFilter')!==false);
foreach([['totalUpdatedRows'=>1],[], '<html>gateway</html>'] as $i=>$ack){reset_fixture();response($ack);$r=AceSeoSheets::write_snapshot_rows('frozen',77,1,$rows);verify('partial or malformed write acknowledgement '.$i,is_wp_error($r));}
reset_fixture();$r=AceSeoSheets::write_snapshot_rows('frozen',77,1,[]);verify('empty batch makes no HTTP request',true===$r&&!$GLOBALS['calls']);
reset_fixture();response(['sheets'=>[sheet(77,'working'),basic_report()]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);$requests=$GLOBALS['calls'][1]['body']['requests'];
verify('publish is a single batch to frozen spreadsheet',true===$r&&count($GLOBALS['calls'])===2&&$GLOBALS['calls'][1]['method']==='POST'&&strpos($GLOBALS['calls'][1]['url'],'frozen:batchUpdate')!==false);
$copy=request_of('copyPaste',$requests);verify('publish copies only values from exact stage range',$copy['pasteType']==='PASTE_VALUES'&&$copy['source']===['sheetId'=>77,'startRowIndex'=>0,'endRowIndex'=>10,'startColumnIndex'=>0,'endColumnIndex'=>3]&&$copy['destination']['sheetId']===88);
$grid=request_of('updateSheetProperties',$requests)['properties']['gridProperties'];verify('publishing retains physical grid to preserve extra columns/rows',$grid['rowCount']===100&&$grid['columnCount']===30);
$clear=request_of('updateCells',$requests);verify('stale values cleared only within old/current managed rectangle',$clear['range']===['sheetId'=>88,'startRowIndex'=>0,'endRowIndex'=>20,'startColumnIndex'=>0,'endColumnIndex'=>5]&&$clear['fields']==='userEnteredValue');
$deleted=array_values(array_filter($requests,fn($r)=>isset($r['deleteSheet'])));verify('only the owned staging tab is deleted',$deleted===[['deleteSheet'=>['sheetId'=>77]]]);
$filter=request_of('setBasicFilter',$requests)['filter'];verify('filter trims unavailable columns without invalid empty map',!isset($filter['criteria'])||is_object($filter['criteria'])||count($filter['criteria'])>0);
verify('filter ranges match completed rows',$filter['range']['endRowIndex']===10&&$filter['range']['endColumnIndex']===3);
reset_fixture();response(['sheets'=>[sheet(77,'working'),basic_report()]]);$GLOBALS['responses'][]=new WP_Error('timeout','Synthetic timeout');$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);
$committed=basic_report();$committed['developerMetadata'][]=['metadataId'=>55,'metadataKey'=>'ace_seo_report_run','metadataValue'=>'run-one'];response(['sheets'=>[$committed]]);$again=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);verify('lost final response recovered from run marker with no second clear',is_wp_error($r)&&true===$again&&count($GLOBALS['calls'])===3&&$GLOBALS['calls'][2]['method']==='GET');
foreach([
    'missing owned report'=>[sheet(77,'working')],
    'existing title belongs to another ID'=>[sheet(77,'working'),sheet(99,'Ace SEO report')],
    'working stage renamed'=>[sheet(77,'someone else'),basic_report()],
] as $name=>$sheets){reset_fixture();response(['sheets'=>$sheets]);$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);verify($name.' refuses mutations',is_wp_error($r)&&count($GLOBALS['calls'])===1);}
reset_fixture();response(['sheets'=>[sheet(77,'working')]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,true);$requests=$GLOBALS['calls'][1]['body']['requests'];verify('first publication creates pinned primary tab at index zero',true===$r&&request_of('addSheet',$requests)['properties']['sheetId']===88&&request_of('addSheet',$requests)['properties']['index']===0);
reset_fixture();response(['sheets'=>[sheet(77,'working'),basic_report()]]);response(['replies'=>[]]);$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);verify('missing publication marker never reports success',is_wp_error($r));
foreach([['title'=>'renamed','hidden'=>true],['title'=>'working','hidden'=>false]] as $props){reset_fixture();response(['sheets'=>[['properties'=>array_merge(['sheetId'=>77],$props)]]]);$r=AceSeoSheets::discard_snapshot_tab('frozen',77,'working');verify('changed staging tab is not deleted '. $props['title'],is_wp_error($r)&&count($GLOBALS['calls'])===1);}
reset_fixture();response(['sheets'=>[sheet(99,'unrelated')]]);$r=AceSeoSheets::discard_snapshot_tab('frozen',77,'working');verify('missing old staging tab cleanup is harmless',true===$r&&count($GLOBALS['calls'])===1);

reset_fixture();$report=basic_report();unset($report['developerMetadata']);response(['sheets'=>[sheet(77,'working'),$report]]);$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);verify('unknown managed extent refuses to overwrite existing cells',is_wp_error($r)&&count($GLOBALS['calls'])===1);
reset_fixture();$report=basic_report();$report['developerMetadata']=[['metadataId'=>1,'metadataKey'=>'ace_seo_report_rows','metadataValue'=>'20']];response(['sheets'=>[sheet(77,'working'),$report]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);verify('incomplete extent metadata refuses to overwrite existing cells',is_wp_error($r)&&count($GLOBALS['calls'])===1);
reset_fixture();$report=basic_report();unset($report['developerMetadata']);response(['sheets'=>[sheet(77,'working'),$report]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false,20,5);$clear=request_of('updateCells',$GLOBALS['calls'][1]['body']['requests']);verify('explicit imported report extent enables bounded first replacement',true===$r&&$clear['range']['endRowIndex']===20&&$clear['range']['endColumnIndex']===5);
reset_fixture();$report=basic_report();$report['basicFilter']['criteria']=[0=>['hiddenValues'=>['old']],1=>['hiddenValues'=>['also old']]];response(['sheets'=>[sheet(77,'working'),$report]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false);$encoded=json_decode($GLOBALS['calls'][1]['raw_body']);$filter=null;foreach($encoded->requests as $req){if(isset($req->setBasicFilter)){$filter=$req->setBasicFilter->filter;}}verify('column-zero filter criteria serialize as map object not JSON array',true===$r&&is_object($filter->criteria));
reset_fixture();$report=basic_report();unset($report['developerMetadata']);$report['properties']['gridProperties']=['rowCount'=>15,'columnCount'=>4];response(['sheets'=>[sheet(77,'working'),$report]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false,20,5);$clear=request_of('updateCells',$GLOBALS['calls'][1]['body']['requests']);$grid=request_of('updateSheetProperties',$GLOBALS['calls'][1]['body']['requests'])['properties']['gridProperties'];verify('imported extent does not clear beyond shortened physical grid',true===$r&&$clear['range']['endRowIndex']<= $grid['rowCount']&&$clear['range']['endColumnIndex']<= $grid['columnCount']);

reset_fixture();response(['sheets'=>[sheet(77,'working'),basic_report()]]);confirm_publish();$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'new-run',10,3,true);verify('first-run numeric collision cannot claim an existing report with old metadata',is_wp_error($r)&&count($GLOBALS['calls'])===1);
reset_fixture();response(['sheets'=>[sheet(77,'working'),basic_report()]]);$r=AceSeoSheets::publish_snapshot('frozen',77,'working',88,'run-one',10,3,false,0,0,fn()=>false);verify('late stop guard prevents the atomic publication request',is_wp_error($r)&&count($GLOBALS['calls'])===1);
$failed=array_values(array_filter($GLOBALS['checks'],fn($t)=>!$t['pass']));
echo json_encode(['passed'=>count($GLOBALS['checks'])-count($failed),'total'=>count($GLOBALS['checks']),'failed'=>$failed],JSON_PRETTY_PRINT).PHP_EOL;
exit($failed?1:0);
