<?php
/** Actual sitemap and stock WP switch/restore functions; disposable store and synthetic DB/cache/cron APIs. */
namespace AceCrawl_Multisite_Test;
if (PHP_SAPI !== 'cli' || \defined('ABSPATH')) { exit(1); }
\define('ABSPATH',__DIR__.'/'); \define('MINUTE_IN_SECONDS',60);
$root=\sys_get_temp_dir().'/ace-sitemap-multisite-'.\bin2hex(\random_bytes(6));\mkdir($root,0700);
$GLOBALS['blog_id']=1;$GLOBALS['_wp_switched_stack']=[];$GLOBALS['switched']=false;
$blog =& $GLOBALS['blog_id'];$stack =& $GLOBALS['_wp_switched_stack'];$shutdown=[];$scheduled=[];$checks=0;
$wpdb=new class{private $id=1;public function set_blog_id($id){$this->id=$id;}public function get_blog_prefix(){return 'fixture_'.$this->id.'_';}};
function wp_cache_switch_to_blog($id){}
function do_action(...$args){}
function add_action($name,$callback,...$args){global $shutdown;if($name==='shutdown')$shutdown[]=$callback;}
function add_filter(...$args){}
function acecrawl_sitemap_is_enabled($setting){global $blog;return $blog!==3;}
function wp_upload_dir(...$args){global $root;return ['basedir'=>$root.'/uploads'];}
function get_current_blog_id(){global $blog;return $blog;}
function trailingslashit($value){return \rtrim($value,'/').'/';}
function untrailingslashit($value){return \rtrim($value,'/');}
function apply_filters($name,$value){return $value;}
function wp_mkdir_p($path){return \is_dir($path)||\mkdir($path,0755,true);}
function wp_json_encode($value,$flags=0){return \json_encode($value,$flags);}
function wp_delete_file($path){if(\is_file($path))\unlink($path);}
function wp_generate_password(...$args){return \bin2hex(\random_bytes(4));}
function home_url($path='/'){global $blog;return 'https://brand-'.$blog.'.invalid'.$path;}
function wp_next_scheduled($hook){global $scheduled,$blog;return $scheduled[$blog]??false;}
function wp_schedule_single_event($time,$hook){global $scheduled,$blog;$scheduled[$blog]=$time;return true;}
function check($ok,$label){global $checks;if(!$ok)throw new \RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n";}
function remove_tree($path){if(\is_dir($path)){foreach(\scandir($path) as $name)if($name!=='.'&&$name!=='..')remove_tree($path.'/'.$name);\rmdir($path);}elseif(\file_exists($path))\unlink($path);}
function load_stock_function($path,$name){
 $tokens=token_get_all(file_get_contents($path));
 foreach($tokens as $i=>$token){
  if(!is_array($token)||$token[0]!==T_FUNCTION)continue;
  $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)++$j;
  if(!isset($tokens[$j])||!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING||$tokens[$j][1]!==$name)continue;
  $code='';$depth=0;$body=false;
  for($k=$i;isset($tokens[$k]);++$k){
   $part=$tokens[$k];$code.=is_array($part)?$part[1]:$part;
   if($part==='{'){++$depth;$body=true;}
   elseif(is_array($part)&&in_array($part[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))++$depth;
   elseif($part==='}'&&--$depth===0&&$body){eval('namespace AceCrawl_Multisite_Test;'.$code);return;}
  }
 }
 throw new RuntimeException('Stock function not found: ' . $name);
}
$core=\getenv('WP_MULTISITE_SOURCE');if(!$core||!\is_file($core))throw new \RuntimeException('Provide stock WordPress ms-blogs.php explicitly');
load_stock_function($core,'switch_to_blog');load_stock_function($core,'restore_current_blog');
try{
    require_once \dirname(__DIR__).'/includes/ace-json.php';
    $source=\getenv('ACECRAWL_MULTISITE_SOURCE')?:\dirname(__DIR__).'/includes/ace-sitemap-generations.php';
    $code=\preg_replace('/^<\?php\s*/','',\file_get_contents($source),1);$code=\str_replace("require_once __DIR__ . '/ace-json.php';",'',$code);
    eval('namespace AceCrawl_Multisite_Test;'.$code);
    $one=acecrawl_sitemap_gen_dir();check(\substr($one,-2)==='/1','first blog gets its sitemap store');
    acecrawl_sitemap_gen_meta_update('state',static function(){return ['brand'=>1];},$saved);check($saved,'first brand metadata saved');
    switch_to_blog(2);$two=acecrawl_sitemap_gen_dir();check($two!==$one&&\substr($two,-2)==='/2','second blog has a separate sitemap store');
    check(acecrawl_sitemap_gen_meta_get('state')===[],'second blog cannot read first brand metadata');
    acecrawl_sitemap_gen_meta_update('state',static function(){return ['brand'=>2];},$saved);check($saved&&acecrawl_sitemap_gen_meta_get('state')===['brand'=>2],'second brand metadata independent');
    restore_current_blog();check(acecrawl_sitemap_gen_dir()===$one&&acecrawl_sitemap_gen_meta_get('state')===['brand'=>1],'restoring context restores first brand store and state');
    switch_to_blog(3);check(acecrawl_sitemap_gen_dir()==='','disabled sitemap cache remains disabled for its blog');restore_current_blog();
    switch_to_blog(4);check(\substr(acecrawl_sitemap_gen_dir(),-2)==='/4','disabled neighbour cannot disable another blog');restore_current_blog();
    acecrawl_sitemap_gen_mark_dirty('posts:product',false,'brand-one-key');
    switch_to_blog(2);acecrawl_sitemap_gen_mark_dirty('taxonomies:category',false,'brand-two-key');acecrawl_sitemap_gen_mark_dirty(null,true);
    $seq=acecrawl_sitemap_gen_seqs();check(isset($seq['scopes']['taxonomies:category'],$seq['keys']['brand-two-key'])&&!isset($seq['scopes']['posts:product'])&&!isset($seq['keys']['brand-one-key']),'immediate flush contains only the current blog marks');
    restore_current_blog();acecrawl_sitemap_gen_mark_dirty(null,true);$seq=acecrawl_sitemap_gen_seqs();check(isset($seq['scopes']['posts:product'],$seq['keys']['brand-one-key'])&&!isset($seq['scopes']['taxonomies:category'])&&!isset($seq['keys']['brand-two-key']),'first blog pending marks survive other blog flush');
    acecrawl_sitemap_gen_mark_dirty('users');switch_to_blog(2);acecrawl_sitemap_gen_mark_dirty('posts:page');restore_current_blog();switch_to_blog(4);
    foreach($shutdown as $callback)$callback();check(get_current_blog_id()===4&&\count($stack)===1,'shutdown flush restores its entry context');
    check(acecrawl_sitemap_gen_seqs()['scopes']===[],'shutdown does not write foreign marks to the final blog');
    restore_current_blog();$seq=acecrawl_sitemap_gen_seqs();check(isset($seq['scopes']['users'])&&!isset($seq['scopes']['posts:page']),'shutdown writes first blog marks to first store');
    switch_to_blog(2);$seq=acecrawl_sitemap_gen_seqs();check(isset($seq['scopes']['posts:page'])&&!isset($seq['scopes']['users']),'shutdown writes second blog marks to second store');restore_current_blog();
    check(isset($scheduled[1],$scheduled[2])&&!isset($scheduled[3])&&!isset($scheduled[4]),'scheduler remains scoped to marked enabled blogs');
    check(get_current_blog_id()===1&&$stack===[]&&!$GLOBALS['switched'],'test leaves no leaked blog context');
    echo "Completed $checks multisite state checks on PHP ".PHP_VERSION."\n";
}finally{remove_tree($root);}
