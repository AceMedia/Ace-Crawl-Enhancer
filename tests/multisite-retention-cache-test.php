<?php
/** Actual report/export classes with synthetic per-blog options and transient APIs. */
if(PHP_SAPI!=='cli'||defined('ABSPATH'))exit(1);
define('ABSPATH',__DIR__.'/');define('DAY_IN_SECONDS',86400);
$blog=1;$checks=0;
function wp_json_encode($value){return json_encode($value);}
function get_current_blog_id(){global $blog;return $blog;}
function get_option($name,$default=[]){global $blog;if(strpos($name,'acecrawl_retention_period_')===0)return [42=>$blog*10];if($name==='acecrawl_retention_progress')return ['started'=>'fixture'];return $default;}
function get_transient($key){global $blog;return ['/brand-'.$blog=>42];}
function home_url(){global $blog;return 'https://brand-'.$blog.'.invalid';}
function apply_filters($name,$value,...$args){global $blog;if($name==='acecrawl_retention_settings'){$value['retained_views']=$blog;$value['days']=$blog*90;}return $value;}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function get_post_meta($id,$key,$single){return 'daily';}
function __($value,$domain=''){return $value;}
function number_format_i18n($value){return number_format($value);}
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n";}
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
try{
 require getenv('ACECRAWL_RETENTION_REPORT_SOURCE')?:dirname(__DIR__).'/includes/admin/class-ace-seo-retention-report.php';
 require getenv('ACECRAWL_EXPORT_SOURCE')?:dirname(__DIR__).'/includes/class-ace-seo-export.php';
 $period=new ReflectionMethod(AceCrawlRetentionReport::class,'period');$lookup=new ReflectionMethod(AceCrawlRetentionReport::class,'candidate_lookup');
 if(PHP_VERSION_ID<80100){$period->setAccessible(true);$lookup->setAccessible(true);}
 $settings=AceCrawlRetentionReport::settings();$mode=getenv('ACECRAWL_RETENTION_CACHE_CONTROL');
 if($mode==='period'){$period->invoke(null,'ga4',30);$blog=2;check($period->invoke(null,'ga4',30)===[42=>20],'period belongs to second blog');return;}
 if($mode==='lookup'){$lookup->invoke(null,$settings);$blog=2;check($lookup->invoke(null,$settings)===['/brand-2'=>42],'lookup belongs to second blog');return;}
 if($mode==='hosts'){AceCrawlRetentionReport::path_key('https://brand-1.invalid/news');$blog=2;check(AceCrawlRetentionReport::path_key('https://brand-2.invalid/news')==='/news','host belongs to second blog');return;}
 if($mode==='labels'){AceCrawlExport::readership(42,['tier'=>'retained']);$blog=2;check(AceCrawlExport::readership(42,['tier'=>'retained'])===AceCrawlRetentionReport::rank_labels()['daily'],'labels belong to second blog');return;}
 check($period->invoke(null,'ga4',30)===[42=>10],'first blog period retained');
 check($lookup->invoke(null,$settings)===['/brand-1'=>42],'first blog lookup retained');
 check(AceCrawlRetentionReport::path_key('https://brand-1.invalid/news')==='/news','first blog internal host retained');
 $first=AceCrawlExport::readership(42,['tier'=>'retained']);check($first===AceCrawlRetentionReport::rank_labels()['daily'],'first blog labels retained');
 $blog=2;
 check($period->invoke(null,'ga4',30)===[42=>20],'period belongs to second blog');
 check($lookup->invoke(null,$settings)===['/brand-2'=>42],'lookup belongs to second blog');
 check(AceCrawlRetentionReport::path_key('https://brand-2.invalid/news')==='/news','host belongs to second blog');
 check(AceCrawlRetentionReport::path_key('https://brand-1.invalid/news')==='','first brand is external to second blog');
 check(AceCrawlExport::readership(42,['tier'=>'retained'])===AceCrawlRetentionReport::rank_labels()['daily'],'labels belong to second blog');
 check(AceCrawlExport::readership(42,['tier'=>'dormant'])===AceCrawlRetentionReport::tier_labels()['dormant'],'tier labels belong to second blog');
 $blog=1;
 check($period->invoke(null,'ga4',30)===[42=>10],'restored blog period retained');
 check($lookup->invoke(null,$settings)===['/brand-1'=>42],'restored blog lookup retained');
 check(AceCrawlRetentionReport::path_key('https://brand-1.invalid/news')==='/news','restored blog internal host retained');
 check(AceCrawlExport::readership(42,['tier'=>'retained'])===$first,'restored blog labels retained');
 echo "Completed $checks retention cache checks on PHP ".PHP_VERSION."; no hosted data, database, HTTP, mail or cron\n";
}finally{restore_error_handler();}
