<?php
/** Real frontend class and runtime class declaration; synthetic WordPress options/term APIs. */
if(PHP_SAPI!=='cli'||defined('ABSPATH')){exit(1);}
define('ABSPATH',__DIR__.'/');define('ACECRAWL_META_PREFIX','_ace_seo_');define('ACECRAWL_VERSION','fixture');
$blog=1;$checks=0;$reads=[];$written=[];
function get_current_blog_id(){global $blog;return $blog;}
function get_option($name,$default=[]){global $blog,$reads;$reads[$blog][$name]=($reads[$blog][$name]??0)+1;if($name==='acecrawl_options')return ['organization'=>['name'=>'Brand '.$blog,'url'=>'https://brand-'.$blog.'.invalid']];if($name==='wpseo_taxonomy_meta')return ['product_cat'=>[$blog===1?44:45=>['wpseo_title'=>'Legacy '.$blog]]];return $default;}
function get_term_meta($id,$key,$single){global $blog,$written;if(isset($written[$blog][$id][$key]))return $written[$blog][$id][$key];return $id===42?'Term '.$blog:'';}
function update_term_meta($id,$key,$value){global $blog,$written;$written[$blog][$id][$key]=$value;return true;}
function is_admin(){return true;}
function get_bloginfo($name){global $blog;return $name==='name'?'Brand '.$blog:'';}
function home_url($path=''){global $blog;return 'https://brand-'.$blog.'.invalid'.$path;}
function trailingslashit($value){return rtrim($value,'/').'/';}
function apply_filters($name,$value,...$args){return $value;}
function esc_url_raw($value){return $value;}
function wp_strip_all_tags($value){return strip_tags($value);}
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n";}
function load_runtime_class($path){
    $tokens=token_get_all(file_get_contents($path));
    foreach($tokens as $i=>$token){
        if(!is_array($token)||$token[0]!==T_CLASS)continue;
        $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)++$j;
        if(!isset($tokens[$j])||!is_array($tokens[$j])||$tokens[$j][1]!=='AceCrawlEnhancer')continue;
        $code='';$depth=0;$body=false;
        for($k=$i;isset($tokens[$k]);++$k){$part=$tokens[$k];$code.=is_array($part)?$part[1]:$part;
            if($part==='{'){++$depth;$body=true;}elseif(is_array($part)&&in_array($part[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))++$depth;
            elseif($part==='}'&&--$depth===0&&$body){eval($code);return;}
        }
    }
    throw new RuntimeException('Actual runtime class declaration not found');
}
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
try{
    require getenv('ACECRAWL_FRONTEND_SOURCE')?:dirname(__DIR__).'/includes/frontend/class-ace-seo-frontend.php';
    load_runtime_class(getenv('ACECRAWL_PLUGIN_RUNTIME_SOURCE')?:dirname(__DIR__).'/includes/plugin-runtime.php');
    $class=new ReflectionClass(AceCrawlFrontend::class);$frontend=$class->newInstanceWithoutConstructor();
    $options=$class->getMethod('get_seo_options');$publisher=$class->getMethod('get_publisher_schema');
    if(PHP_VERSION_ID<80100){$options->setAccessible(true);$publisher->setAccessible(true);}
    $mode=getenv('ACECRAWL_CACHE_CONTROL');
    if($mode==='options'){$options->invoke($frontend);$blog=2;check($options->invoke($frontend)['organization']['name']==='Brand 2','options belong to second blog');return;}
    if($mode==='publisher'){$publisher->invoke($frontend);$blog=2;check($publisher->invoke($frontend)['name']==='Brand 2','publisher belongs to second blog');return;}
    if($mode==='taxonomy'){AceCrawlEnhancer::get_taxonomy_meta(42,'product_cat','desc');$blog=2;check(AceCrawlEnhancer::get_taxonomy_meta(42,'product_cat','desc')==='Term 2','same term ID keeps second blog metadata');return;}
    if($mode==='legacy'){AceCrawlEnhancer::get_taxonomy_meta(44,'product_cat','title');$blog=2;check(AceCrawlEnhancer::get_taxonomy_meta(45,'product_cat','title')==='Legacy 2','legacy option belongs to second blog');return;}
    $one=$options->invoke($frontend);check($one['organization']['name']==='Brand 1','first blog options retained');
    check($options->invoke($frontend)===$one&&$reads[1]['acecrawl_options']===1,'same blog option reads cached');
    $publisher_one=$publisher->invoke($frontend);check($publisher_one['name']==='Brand 1'&&$publisher_one['url']==='https://brand-1.invalid','first publisher retained');
    check(AceCrawlEnhancer::get_taxonomy_meta(42,'product_cat','desc')==='Term 1','first term metadata retained');
    check(AceCrawlEnhancer::get_taxonomy_meta(44,'product_cat','title')==='Legacy 1','first legacy metadata retained');
    $blog=2;check($options->invoke($frontend)['organization']['name']==='Brand 2','options belong to second blog');
    $publisher_two=$publisher->invoke($frontend);check($publisher_two['name']==='Brand 2'&&$publisher_two['url']==='https://brand-2.invalid','publisher belongs to second blog');
    check(AceCrawlEnhancer::get_taxonomy_meta(42,'product_cat','desc')==='Term 2','same term ID keeps second blog metadata');
    check(AceCrawlEnhancer::get_taxonomy_meta(45,'product_cat','title')==='Legacy 2','legacy option belongs to second blog');
    check(isset($written[1][44]['_ace_seo_title'],$written[2][45]['_ace_seo_title'])&&!isset($written[2][44]),'legacy migration writes only the current blog');
    $blog=1;check($options->invoke($frontend)===$one&&$publisher->invoke($frontend)===$publisher_one,'restoring context restores first options and publisher');
    check(AceCrawlEnhancer::get_taxonomy_meta(42,'product_cat','desc')==='Term 1','restored term metadata retained');
    check(AceCrawlEnhancer::get_taxonomy_meta(99,'product_cat','desc')==='','legitimate empty metadata retained');
    echo "Completed $checks SEO cache checks on PHP ".PHP_VERSION."; no WordPress bootstrap, hosted data, HTTP, mail or cron\n";
}finally{restore_error_handler();}
