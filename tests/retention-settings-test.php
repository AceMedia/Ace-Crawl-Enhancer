<?php
/**
 * Retention form round-trip checks with isolated WordPress stubs.
 * No database, network or live settings. Requires PHP DOM for HTML form parsing.
 * Run: php tests/retention-settings-test.php
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}

define('ABSPATH',__DIR__.'/'); define('HOUR_IN_SECONDS',3600);
$root=$argv[1]??dirname(__DIR__);
$GLOBALS['options']=array(); $GLOBALS['transients']=array(); $GLOBALS['admin']=true; $GLOBALS['nonce_ok']=true;
class SettingsRedirect extends Exception {public $url;function __construct($url){$this->url=$url;}}
function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function apply_filters($key,$value){return $value;}
function current_user_can($cap){return $GLOBALS['admin'];}
function check_admin_referer($action){return $GLOBALS['nonce_ok'];}
function wp_die($message){throw new RuntimeException($message);}
function wp_safe_redirect($url){throw new SettingsRedirect($url);}
function admin_url($path){return 'https://ordinary-wordpress.test/wp-admin/'.$path;}
function get_current_user_id(){return 1;}
function wp_unslash($value){return is_array($value)?array_map('wp_unslash',$value):stripslashes($value);}
function wp_slash($value){return is_array($value)?array_map('wp_slash',$value):addslashes($value);}
function sanitize_text_field($value){return trim(strip_tags($value));}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function sanitize_title($value){return sanitize_key(str_replace(' ','-',$value));}
function sanitize_html_class($value){return preg_replace('/[^a-zA-Z0-9_-]/','',$value);}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}
function esc_url($value){return esc_html($value);}
function __($value,$domain=null){return $value;}
function checked($a,$b=true,$echo=true){$out=((string)$a===(string)$b)?'checked="checked"':'';if($echo)echo $out;return $out;}
function selected($a,$b=true,$echo=true){$out=((string)$a===(string)$b)?'selected="selected"':'';if($echo)echo $out;return $out;}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
function number_format_i18n($value){return number_format($value);}
function wp_next_scheduled($hook){return false;}
function wp_schedule_event($when,$recurrence,$hook){}
function wp_clear_scheduled_hook($hook){}
function get_post_types($args=array(),$output='names'){
 $types=array();foreach(array('post','page','product')as$name){$types[$name]=(object)array('name'=>$name,'labels'=>(object)array('name'=>ucfirst($name)));}return $output==='objects'?$types:array_keys($types);
}
function post_type_exists($type){return in_array($type,array('post','page','product','private_type'),true);}
function taxonomy_exists($type){return in_array($type,array('category','post_tag','product_cat'),true);}
function current_time($type,$gmt=false){return '2026-10-06 14:00:00';}
function get_term_by($field,$value,$taxonomy){return false;}
$wpdb=new class{public $posts='wp_posts';function prepare($query,...$args){return $query;}function get_row($query){return(object)array('total'=>0,'expired'=>0);}};
require $root.'/includes/class-ace-seo-retention-actions.php';
require $root.'/includes/admin/class-ace-seo-retention-report.php';
require $root.'/includes/admin/class-ace-seo-sheets.php';
$failures=0;$checks=0;
$check=static function($label,$ok)use(&$failures,&$checks){$checks++;echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failures++;};
$baseline=AceSeoRetentionActions::options();
$baseline=array_merge($baseline,array('notice_enabled'=>1,'notice_years'=>2,'notice_text'=>"It's an older article from {date}.",'lifetimes'=>array('post'=>30,'product'=>90),'lifetime_rules'=>array('category:updates'=>60),'report_years'=>4,'report_days'=>365,'retained_views'=>17,'thin_words'=>450,'auto_build'=>1,'track_views'=>1,'retained_notice'=>1,'retained_notice_text'=>'Published {date}.','light_enabled'=>1,'light_drop'=>'sidebar, ad-slot','light_cache_hours'=>72,'light_continue'=>'none','extension_setting'=>'leave-me'));
$sheets=array('sheet_id'=>'dummy-sheet-test-only','key'=>'dummy-key-test-only','extension_setting'=>'keep-sheets');
$reset=static function($options)use($sheets){$GLOBALS['options']=array('ace_seo_retention_options'=>$options,'ace_seo_sheets'=>$sheets,'ace_seo_options'=>array('unrelated'=>'preserved'));$GLOBALS['transients']=array();$GLOBALS['admin']=true;$GLOBALS['nonce_ok']=true;};
$forms=static function()use($root){
 ob_start();include $root.'/includes/admin/views/retention-settings.php';$html=ob_get_clean();
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);$out=array();
 foreach($xpath->query('//form')as$form){$parts=array();foreach($xpath->query('.//input|.//textarea|.//select',$form)as$control){
  $name=$control->getAttribute('name');if(!$name||$control->hasAttribute('disabled'))continue;
  if($control->tagName==='input'&&in_array($control->getAttribute('type'),array('checkbox','radio'),true)&&!$control->hasAttribute('checked'))continue;
  $value=$control->getAttribute('value');
  if($control->tagName==='textarea')$value=$control->textContent;
  if($control->tagName==='select'){$option=$xpath->query('.//option[@selected]',$control)->item(0)??$xpath->query('.//option',$control)->item(0);$value=$option?$option->getAttribute('value'):'';}
  $parts[]=rawurlencode($name).'='.rawurlencode($value);
 }parse_str(implode('&',$parts),$payload);$out[$payload['action']]=$payload;}
 return $out;
};
$dispatch=static function($action,$payload){$_POST=wp_slash($payload);$map=array('ace_seo_retention_report_settings'=>'handle_report_settings','ace_seo_retention_options'=>'handle_options','ace_seo_retention_front'=>'handle_front_settings');try{AceSeoRetentionReport::{$map[$action]}();}catch(SettingsRedirect $redirect){return $redirect->url;}return '';};
$groups=array('ace_seo_retention_report_settings'=>array('report_years','report_days','retained_views','thin_words','auto_build','track_views'),'ace_seo_retention_options'=>array('notice_enabled','notice_years','notice_text','lifetimes','lifetime_rules'),'ace_seo_retention_front'=>array('retained_notice','retained_notice_text','light_enabled','light_drop','light_cache_hours','light_continue'));
foreach($groups as$action=>$keys){
 $reset($baseline);$payload=$forms()[$action];$before=$GLOBALS['options'];$url=$dispatch($action,$payload);
 $check($action.' rendered unchanged round-trip preserves every option',$GLOBALS['options']===$before);
 $check($action.' returns to retention settings',strpos($url,'page=ace-seo-settings#retention/')!==false);
 $reset($baseline);$payload=$forms()[$action];
 if($action==='ace_seo_retention_report_settings'){$payload['report_days']='180';unset($payload['auto_build'],$payload['track_views']);}
 if($action==='ace_seo_retention_options'){$payload['notice_years']='5';unset($payload['notice_enabled']);$payload['lifetimes']['post']='60';}
 if($action==='ace_seo_retention_front'){$payload['light_cache_hours']='48';unset($payload['light_enabled'],$payload['retained_notice']);}
 $dispatch($action,$payload);$saved=$GLOBALS['options']['ace_seo_retention_options'];
 $check($action.' changes stay inside their own section',array_diff_key($saved,array_flip($keys))===array_diff_key($baseline,array_flip($keys)));
 $check($action.' never changes Sheets or main SEO settings',$GLOBALS['options']['ace_seo_sheets']===$sheets&&$GLOBALS['options']['ace_seo_options']===array('unrelated'=>'preserved'));
 foreach(array('admin','nonce_ok')as$gate){$reset($baseline);$payload=$forms()[$action];$before=$GLOBALS['options'];$GLOBALS[$gate]=false;$blocked=false;try{$dispatch($action,$payload);}catch(RuntimeException$e){$blocked=true;}$check($action.' rejects missing '.$gate.' before writes',$blocked&&$GLOBALS['options']===$before);}
}
$legacy=$baseline;$legacy['lifetimes']['private_type']=12;$legacy['lifetimes']['inactive_type']=42;$legacy['lifetime_rules']['inactive_tax:archive']=24;
$reset($legacy);$payload=$forms()['ace_seo_retention_options'];$dispatch('ace_seo_retention_options',$payload);$saved=$GLOBALS['options']['ace_seo_retention_options'];
$check('unchanged notice save preserves unrendered post-type lifetime rules',$saved['lifetimes']==$legacy['lifetimes']);
$check('unchanged notice save preserves inactive taxonomy rules',$saved['lifetime_rules']===$legacy['lifetime_rules']);
$reset($legacy);$payload=$forms()['ace_seo_retention_options'];$payload['lifetimes']['post']='';$payload['lifetime_rules']='category:updates=60';$dispatch('ace_seo_retention_options',$payload);$saved=$GLOBALS['options']['ace_seo_retention_options'];
$check('blank visible lifetime removes its rule while hidden rules survive',!isset($saved['lifetimes']['post'])&&($saved['lifetimes']['private_type']??0)===12&&($saved['lifetimes']['inactive_type']??0)===42);
$check('removing an inactive taxonomy line explicitly removes that rule',!isset($saved['lifetime_rules']['inactive_tax:archive'])&&($saved['lifetime_rules']['category:updates']??0)===60);
$reset($legacy);AceSeoRetentionActions::save_options(array('notice_years'=>2,'notice_text'=>$legacy['notice_text'],'notice_enabled'=>1,'lifetimes'=>array('post'=>30),'lifetime_rules'=>'category:updates=60'));
$check('legacy callers keep the original replace-all lifetime semantics',$GLOBALS['options']['ace_seo_retention_options']['lifetimes']===array('post'=>30));
echo $checks.' checks, '.$failures." failures.\n";exit($failures?1:0);
