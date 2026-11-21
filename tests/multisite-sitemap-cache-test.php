<?php
/** Selected actual sitemap functions with synthetic provider, option and per-blog cache APIs. */
if(PHP_SAPI!=='cli'||defined('ABSPATH')){exit(1);}define('ABSPATH',__DIR__.'/');
$blog=1;$checks=0;$persistent=[];$type_reads=[];
function get_current_blog_id(){global $blog;return $blog;}
function acecrawl_sitemap_is_enabled($key){global $blog;return $key==='enable_custom_routes'||($key==='enable_news_provider'&&$blog===1);}
function acecrawl_sitemap_excluded_sitemap_post_types(){return [];}
function acecrawl_sitemap_excluded_sitemap_taxonomies(){return [];}
function apply_filters($name,$value,...$args){return $value;}
function get_taxonomies(...$args){global $blog;return ['brand_tax'=>(object)['rewrite'=>['slug'=>'brand-tax-'.$blog]]];}
function get_post_types(...$args){global $blog,$type_reads;$type_reads[$blog]=($type_reads[$blog]??0)+1;return ['post'=>(object)['rewrite'=>['slug'=>'stories-'.$blog]],'product'=>(object)['rewrite'=>['slug'=>'products-'.$blog]]];}
function acecrawl_sitemap_get_cache_version(){return 1;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower($value));}
function acecrawl_sitemap_cache_get($key){global $blog,$persistent;return $persistent[$blog][$key]??false;}
function acecrawl_sitemap_cache_set($key,$value){global $blog,$persistent;$persistent[$blog][$key]=$value;}
function acecrawl_sitemap_stable_pages_supported($type){return $type==='stable_product';}
function acecrawl_sitemap_stored_page_map($type){global $blog;return ['brand'=>$blog];}
function acecrawl_sitemap_page_counts($type,$map){return [['m'=>'2026-10-0'.$map['brand'].' 12:00:00']];}
function wp_list_pluck($values,$field){return array_column($values,$field);}
function get_lastpostmodified($timezone,$type){global $blog;return '2026-11-0'.$blog.' 12:00:00';}
function get_date_from_gmt($value,$format){return $value;}
function wp_sitemaps_get_server(){return (object)['registry'=>new class{public function get_provider($name){return new class{public function get_max_num_pages($type){global $blog;return $blog+1;}};}}];}
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n";}
function load_function($path,$name){
    $tokens=token_get_all(file_get_contents($path));
    foreach($tokens as $i=>$token){
        if(!is_array($token)||$token[0]!==T_FUNCTION)continue;
        $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)++$j;
        if(!isset($tokens[$j])||!is_array($tokens[$j])||$tokens[$j][1]!==$name)continue;
        $code='';$depth=0;$body=false;
        for($k=$i;isset($tokens[$k]);++$k){$part=$tokens[$k];$code.=is_array($part)?$part[1]:$part;
            if($part==='{'){++$depth;$body=true;}elseif(is_array($part)&&in_array($part[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))++$depth;
            elseif($part==='}'&&--$depth===0&&$body){eval($code);return;}
        }
    }
    throw new RuntimeException('Actual sitemap function not found: '.$name);
}
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
try{
    $source=getenv('ACECRAWL_POWERTOOLS_SOURCE')?:dirname(__DIR__).'/includes/ace-sitemap-powertools.php';
    foreach(['acecrawl_sitemap_custom_routes','acecrawl_sitemap_route_lookup','acecrawl_sitemap_get_index_entry_lastmod','acecrawl_sitemap_get_max_pages_for_route'] as $name)load_function($source,$name);
    $mode=getenv('ACECRAWL_SITEMAP_CACHE_CONTROL');
    if($mode){
        $call=function()use($mode){if($mode==='routes')return acecrawl_sitemap_custom_routes();if($mode==='lookup')return acecrawl_sitemap_route_lookup();if($mode==='lastmod')return acecrawl_sitemap_get_index_entry_lastmod('posts','product',1);if($mode==='stable')return acecrawl_sitemap_get_index_entry_lastmod('posts','stable_product',1);return acecrawl_sitemap_get_max_pages_for_route('posts','product');};
        $call();$blog=2;$value=$call();
        $ok=$mode==='routes'?isset($value['products-2']):($mode==='lookup'?($value['posts|product']??'')==='products-2':($mode==='lastmod'?$value==='2026-11-02 12:00:00':($mode==='stable'?$value==='2026-10-02 12:00:00':$value===3)));
        check($ok,'second blog keeps its own '.$mode.' cache');return;
    }
    foreach([1,2] as $id){
        $blog=$id;$routes=acecrawl_sitemap_custom_routes();
        check(isset($routes['products-'.$id],$routes['brand-tax-'.$id]),'blog '.$id.' routes use its own rewrites');
        check(acecrawl_sitemap_route_lookup()['posts|product']==='products-'.$id,'blog '.$id.' lookup uses its own routes');
        check(acecrawl_sitemap_get_index_entry_lastmod('posts','product',1)==='2026-11-0'.$id.' 12:00:00','blog '.$id.' last-modified cache stays local');
        check(acecrawl_sitemap_get_index_entry_lastmod('posts','stable_product',1)==='2026-10-0'.$id.' 12:00:00','blog '.$id.' stable-page dates stay local');
        check(acecrawl_sitemap_get_max_pages_for_route('posts','product')===$id+1,'blog '.$id.' page count stays local');
    }
    $blog=1;check(isset(acecrawl_sitemap_custom_routes()['products-1'])&&acecrawl_sitemap_get_max_pages_for_route('posts','product')===2,'restoring context restores first blog cache');
    check($type_reads===[1=>1,2=>1],'same-blog route lookup avoids repeated type discovery');
    echo "Completed $checks sitemap cache checks on PHP ".PHP_VERSION."; no WordPress bootstrap, hosted data, HTTP, mail or cron\n";
}finally{restore_error_handler();}
