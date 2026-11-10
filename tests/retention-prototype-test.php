<?php
/** Run only against the disposable integration site; never the real dev or live archive. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ACE_SEO_REDIRECT_TESTS' ) || empty( $argv[1] ) ) { exit( "Set ACE_SEO_REDIRECT_TESTS=1 and pass disposable wp-load.php.\n" ); }
require $argv[1];
if ( 'Ace Redirect Tests' !== get_option( 'blogname' ) ) { exit( "Disposable Ace Redirect Tests site required.\n" ); }
if ( ! class_exists( 'AceCrawlRetentionPrototype' ) ) { require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-prototype.php'; }
if ( ! class_exists( 'AceCrawl_Retention_Evidence' ) ) { require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-evidence.php'; }
$checks = 0; $ids = array(); $saved_public = get_option( 'blog_public' );
$saved_progress = get_option( AceCrawlRetentionReport::PROGRESS_OPTION, array() );
$check = static function( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks++; echo "PASS $label\n"; };
try {
    $check( AceCrawlRetentionPrototype::enabled(), 'Review available without site-specific configuration' );
    update_option( 'blog_public', 1 );
    $check( AceCrawlRetentionPrototype::enabled(), 'Review available on an indexable site' );
    update_option( 'blog_public', 0 ); AceCrawlRetentionPrototype::init();
    $check( AceCrawlRetentionPrototype::enabled(), 'Discouraged site can still review assessments' );
    wp_set_current_user( get_users( array( 'role'=>'administrator','number'=>1 ) )[0]->ID );
    $url = 'https://example.test/replacement/';
    $response = array( 'response'=>array('code'=>200), 'headers'=>array('content-type'=>'text/html'), 'body'=>'<html><head><link rel="canonical" href="'.$url.'"></head><body>Replacement</body></html>' );
    $check( ! AceCrawlRetentionPrototype::inspect_response( $response, $url ), 'Complete canonical HTML accepted by response parser' );
    $bad = $response; $bad['response']['code'] = 301;
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'Redirect response rejected' );
    $bad = $response; $bad['body'] = '<html>Challenge';
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'Incomplete challenge body rejected' );
    $bad = $response; $bad['headers']['x-amzn-waf-action'] = 'challenge';
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'WAF challenge rejected even with a 200 body' );
    $bad = $response; $bad['headers']['x-robots-tag'] = 'noindex';
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'HTTP noindex rejected' );
    $bad = $response; $bad['body'] = str_replace('</head>','<meta name="robots" content="none"></head>',$bad['body']);
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'HTML none directive rejected' );
    $bad = $response; $bad['body'] = str_replace( $url, 'https://example.test/elsewhere/', $bad['body'] );
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'Different canonical rejected' );
    $bad = $response; $bad['body'] = str_replace('</head>','<link rel="canonical" href="'.$url.'"></head>',$bad['body']);
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( $bad, $url ), 'Duplicate canonical rejected' );
    $check( (bool) AceCrawlRetentionPrototype::inspect_response( new WP_Error('network'), $url ), 'Network failure holds candidate' );
    $now = time();
    $fact = array('id'=>1,'status'=>'publish','type'=>'post','password'=>false,'published'=>'2018-01-01 00:00:00','meta'=>array(),'redirect'=>array('target'=>''));
    $s = array('generation'=>array('id'=>'fixture'),'source'=>$fact,'target'=>array_merge($fact,array('id'=>2)),'building'=>false,'site_discouraged'=>false,
        'target_holds'=>array(),'review'=>array('confirmed'=>true,'reason'=>'Reviewed the original subject and relevant historical coverage.'),
        'settings'=>array('older_than_years'=>3),'row'=>array('backlinks'=>0,'links_in'=>0),
        'context'=>array('collected_at'=>$now,'period'=>array('start'=>gmdate('Y-m-d',$now-90*DAY_IN_SECONDS),'end'=>gmdate('Y-m-d',$now-DAY_IN_SECONDS)),'sources'=>array('Google Analytics','Search Console'),'metrics'=>array('views'=>0,'clicks'=>0)),
        'assessment'=>array('ready'=>true,'suggestions'=>array('consolidate'),'reasons'=>array()));
    $s['context']['link_evidence']=array_fill_keys(array('backlinks','internal'),array('count'=>0,'complete'=>true,'source'=>'Fixture provider','checked_at'=>$now));
    $check( ! AceCrawlRetentionPrototype::holds($s,$now), 'Complete synthetic evidence can pass shadow policy' );
    foreach (array('backlinks','links_in') as $key) { $bad=$s; $bad['row'][$key]=1; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),$key.' protects source'); }
    $bad=$s; $bad['row']['backlinks']=null; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Unknown backlinks hold');
    $bad=$s; $bad['generation']=null; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Legacy or unfinished report generation holds');
    $bad=$s; $bad['context']['link_evidence']['backlinks']['checked_at']=$now-2*DAY_IN_SECONDS; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Stale backlink evidence holds');
    $bad=$s; $bad['row']['links_in']=null; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Unknown internal links hold');
    $bad=$s; $bad['context']['period']['start']=$bad['context']['period']['end']; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'One quiet day cannot authorise moving an article');
    foreach(array('views','clicks') as $key) { foreach(array(1,null) as $value) { $bad=$s; $bad['context']['metrics'][$key]=$value; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Positive or missing '.$key.' holds regardless of report threshold'); } }
    foreach(array('building','site_discouraged') as $key) { $bad=$s; $bad[$key]=true; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),$key.' holds'); }
    foreach(array('source','target') as $side) {
        $bad=$s; $bad[$side]['redirect']['target']='https://example.test/next/'; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),$side.' existing rule protected');
        $bad=$s; $bad[$side]['meta'][AceCrawlRetentionPrototype::KEEP]=array('by'=>1); $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),$side.' manual keep protected');
        $bad=$s; $bad[$side]['password']=true; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),$side.' password protected');
    }
    $bad=$s; $bad['target']['id']=1; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Self redirect held');
    $bad=$s; $bad['review']['confirmed']=false; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Unreviewed replacement held');
    $bad=$s; $bad['context']['collected_at']=$now-2*DAY_IN_SECONDS; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Stale evidence held');
    $bad=$s; $bad['context']['period']['end']='2020-01-01'; $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Old period requires recent observation too');
    $bad=$s; $bad['context']['sources']=array('Google Analytics'); $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'Missing search source held');
    $bad=$s; $bad['assessment']['suggestions']=array('hold'); $check((bool)AceCrawlRetentionPrototype::holds($bad,$now),'ready alone never authorises combining');
    foreach(array('source','replacement') as $kind) { $ids[]=wp_insert_post(array('post_type'=>'post','post_title'=>'Retention prototype fixture '.$kind,'post_status'=>'publish','post_date'=>'2018-01-01 00:00:00','post_date_gmt'=>'2018-01-01 00:00:00')); }
    list($source,$target)=$ids;
    // Capture with deterministic providers; never contact Analytics or public URLs from fixtures.
    $saved_provider=$GLOBALS['wp_filter']['acecrawl_retention_evidence_context'] ?? null;
    remove_all_filters('acecrawl_retention_evidence_context');
    $provider=static function($context) {
        $context['content_type']='evergreen'; $context['collected_at']=time();
        $context['metric_period']=$context['period'];
        $context['coverage']=array_merge($context['period'],array('complete'=>true,'capped'=>false));
        $context['sources']=array('Google Analytics','Search Console');
        $context['metrics']=array('views'=>0,'clicks'=>0,'impressions'=>10,'position'=>0);
        return $context;
    };
    add_filter('acecrawl_retention_evidence_context',$provider);
    $http=static function($pre,$args,$url) { return array('response'=>array('code'=>200),'headers'=>array('content-type'=>'text/html'),'body'=>'<html><head><link rel="canonical" href="'.esc_attr($url).'"></head><body>Fixture</body></html>'); };
    add_filter('pre_http_request',$http,10,3);
    $links=static function() use($s) { return $s['context']['link_evidence']; };
    add_filter('acecrawl_retention_link_evidence',$links);
    try {
        $period=$s['context']['period'];
        $check(is_wp_error(AceCrawlRetentionPrototype::capture($source,'https://outside.invalid/',$period['start'],$period['end'],true,'Reviewed replacement and all historical context.')),'External replacement rejected before provider access');
        $check(is_wp_error(AceCrawlRetentionPrototype::capture($source,get_permalink($target),$period['start'],gmdate('Y-m-d'),true,'Reviewed replacement and all historical context.')),'Unfinished period rejected');
        update_option(AceCrawlRetentionReport::PROGRESS_OPTION,array('phase'=>'done','generation'=>'fixture','started'=>$now-10,'finished'=>$now,'settings'=>AceCrawlRetentionReport::settings()));
        update_post_meta($source,AceCrawlRetentionReport::META,array('generation'=>'fixture','backlinks'=>0,'links_in'=>0,'words'=>500));
        $captured=AceCrawlRetentionPrototype::capture($source,get_permalink($target),$period['start'],$period['end'],true,'Reviewed replacement and all historical context.');
        $check(is_array($captured) && count($captured['holds'])===1 && false!==strpos($captured['holds'][0],'Site-wide noindex'),'Integrated capture retains dev noindex as its only hold for complete fixtures');
        update_option('blog_public',1);
        remove_filter('acecrawl_retention_link_evidence',$links);
        $review=array('confirmed'=>true,'source'=>'Reviewed external backlink report and internal crawl export','by'=>get_current_user_id(),'checked_at'=>time());
        $reviewed=AceCrawlRetentionPrototype::capture($source,get_permalink($target),$period['start'],$period['end'],true,'Reviewed replacement and all historical context.',$review);
        $check(!$reviewed['holds'] && $reviewed['context']['link_evidence']['backlinks']['by']===get_current_user_id(),'Indexable site can pass with explicit attributed link review');
        $expired=$review; $expired['checked_at']=time()-2*DAY_IN_SECONDS;
        $expired_capture=AceCrawlRetentionPrototype::capture($source,get_permalink($target),$period['start'],$period['end'],true,'Reviewed replacement and all historical context.',$expired);
        $check((bool)$expired_capture['holds'],'Expired link attestation cannot authorise automation');
        $unknown=AceCrawlRetentionPrototype::capture($source,get_permalink($target),$period['start'],$period['end'],true,'Reviewed replacement and all historical context.');
        $check((bool)$unknown['holds'],'No provider and no explicit link review stays unknown');
        $positive=static function(){return array('backlinks'=>array('count'=>2,'complete'=>true,'source'=>'Provider','checked_at'=>time()));};
        add_filter('acecrawl_retention_link_evidence',$positive);
        $protected=AceCrawlRetentionPrototype::capture($source,get_permalink($target),$period['start'],$period['end'],true,'Reviewed replacement and all historical context.',$review);
        $check((bool)$protected['holds'] && 2===$protected['row']['backlinks'],'Manual review cannot override positive provider evidence');
        remove_filter('acecrawl_retention_link_evidence',$positive);
        update_option('blog_public',0);
        $plan=AceCrawlRetentionPrototype::store($captured); $ids[]=$plan;
        $check(!is_wp_error($plan) && AceCrawlRetentionPrototype::read(get_post($plan))===$captured,'Real capture stores and verifies the complete evidence snapshot');
    } finally {
        remove_filter('pre_http_request',$http,10);
        remove_filter('acecrawl_retention_link_evidence',$links);
        remove_all_filters('acecrawl_retention_evidence_context');
        if($saved_provider) { $GLOBALS['wp_filter']['acecrawl_retention_evidence_context']=$saved_provider; }
    }
    $fingerprint=AceCrawlRetentionPrototype::fingerprint($source,$target);
    update_post_meta($source,AceCrawlRetentionPrototype::KEEP,array('by'=>get_current_user_id()));
    $check($fingerprint!==AceCrawlRetentionPrototype::fingerprint($source,$target),'Keep change invalidates snapshot');
    delete_post_meta($source,AceCrawlRetentionPrototype::KEEP);
    $fingerprint=AceCrawlRetentionPrototype::fingerprint($source,$target);
    wp_update_post(array('ID'=>$target,'post_content'=>'Replacement changed after review'));
    $check($fingerprint!==AceCrawlRetentionPrototype::fingerprint($source,$target),'Replacement content change invalidates snapshot');
    $before=get_post_meta($source); $cron=_get_cron_array();
    $s['source']=AceCrawlRetentionPrototype::facts($source); $s['target']=AceCrawlRetentionPrototype::facts($target); $s['version']=AceCrawlRetentionPrototype::VERSION;
    $s['created']=$now; $s['by']=get_current_user_id(); $s['fingerprint']=AceCrawlRetentionPrototype::fingerprint($source,$target); $s['holds']=array('Fixture: simulation only');
    $plan=AceCrawlRetentionPrototype::store($s); $ids[]=$plan;
    $check(!is_wp_error($plan) && AceCrawlRetentionPrototype::read(get_post($plan))===$s,'Frozen snapshot round-trips with integrity check');
    $copy=get_post($plan); $copy->post_content=str_replace('Fixture: simulation only','Tampered',$copy->post_content);
    $check(null===AceCrawlRetentionPrototype::read($copy),'Tampered snapshot rejected');
    $check($before===get_post_meta($source) && $cron===_get_cron_array(),'Saving preview never changes redirects or cron');
    wp_set_current_user(0); $check(is_wp_error(AceCrawlRetentionPrototype::store($s)),'Anonymous snapshot write denied');
    wp_set_current_user(get_users(array('role'=>'administrator','number'=>1))[0]->ID);
    // Exercise the existing lifecycle only on these disposable fixtures. No prototype apply API exists.
    $baseline=AceCrawlRedirects::settings($source);
    $check(true===AceCrawlRedirects::save($source,array('target'=>get_permalink($target),'code'=>301)),'Fixture-only approved 301 applies through shared lifecycle');
    $applied=AceCrawlRedirects::settings($source);
    $check(true===AceCrawlRedirects::save($source,array('target'=>get_permalink($target),'code'=>301)) && $applied===AceCrawlRedirects::settings($source),'Identical apply leaves lifecycle unchanged');
    $check(true===AceCrawlRedirects::save($source,array('operation'=>'clear')) && $baseline===AceCrawlRedirects::settings($source),'Fixture-only undo restores prior empty rule');
    $check(!AceCrawlRetentionQueue::control()['enabled'] && false===wp_next_scheduled(AceCrawlRetentionQueue::HOOK),'Dev queue defaults to paused without a scheduled worker');
    echo "$checks prototype checks passed.\n";
} finally {
    foreach($ids as $id) { if(is_int($id) && $id>0) { wp_delete_post($id,true); } }
    update_option('blog_public',$saved_public);
    update_option(AceCrawlRetentionReport::PROGRESS_OPTION,$saved_progress);
}
