<?php
/** Disposable real-WordPress queue tests. Never run against the real dev archive. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ACE_SEO_REDIRECT_TESTS' ) || empty( $argv[1] ) ) { exit( "Disposable WordPress opt-in required.\n" ); }
require $argv[1];
if ( 'Ace Redirect Tests' !== get_option( 'blogname' ) ) { exit( "Disposable Ace Redirect Tests site required.\n" ); }
$public = get_option( 'blog_public' ); $progress = get_option( AceSeoRetentionReport::PROGRESS_OPTION, array() );
$control = get_option( 'ace_seo_retention_queue_control', null ); $ids = array(); $users = array(); $checks = 0;
$check = static function( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks++; echo "PASS $label\n"; };
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$original_provider = $GLOBALS['wp_filter']['ace_seo_retention_evidence_context'] ?? null;
$original_links = $GLOBALS['wp_filter']['ace_seo_retention_link_evidence'] ?? null;
update_option( 'blog_public', 1 ); wp_set_current_user( $admin ); AceSeoRetentionPrototype::init(); AceSeoRetentionQueue::install();
$events_start = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . AceSeoRetentionQueue::table( 'events' ) );

// Fixture-only race injector. All policy checks use the real implementation without bypasses.
class RetentionFixtureQueue extends AceSeoRetentionQueue {
    public static $owned = array(); public static $mutator = null;
    protected static function preflight( array $s ) {
        foreach ( array( $s['source']['id'], $s['target']['id'] ) as $id ) { if ( ! in_array( $id, self::$owned, true ) ) { return new WP_Error( 'fixture', 'Not an owned fixture.' ); } }
        $r = parent::preflight( $s );
        if ( self::$mutator ) { call_user_func( self::$mutator ); }
        return $r;
    }
    public static function preview( $s ) { return self::preflight( $s ); }
    public static function lock() { return parent::acquire(); }
    public static function unlock( $lease ) { parent::release( $lease ); }
}
remove_all_filters( 'ace_seo_retention_evidence_context' ); remove_all_filters( 'ace_seo_retention_link_evidence' );
add_filter( 'ace_seo_retention_evidence_context', static function( $c ) {
    $c['content_type'] = 'evergreen'; $c['collected_at'] = time(); $c['metric_period'] = $c['period'];
    $c['coverage'] = array_merge( $c['period'], array( 'complete' => true, 'capped' => false ) );
    $c['sources'] = array( 'Google Analytics', 'Search Console' ); $c['metrics'] = array( 'views' => 0, 'clicks' => 0, 'impressions' => 10, 'position' => 0 ); return $c;
} );
add_filter( 'ace_seo_retention_link_evidence', static function() { return array_fill_keys( array( 'backlinks', 'internal' ), array( 'source' => 'Fixture provider', 'complete' => true, 'count' => 0, 'checked_at' => time() ) ); } );
$http = static function( $pre, $args, $url ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<html><head><link rel="canonical" href="' . esc_attr( $url ) . '"></head><body>Fixture</body></html>' ); };
add_filter( 'pre_http_request', $http, 10, 3 );
try {
    $check( ! AceSeoRetentionQueue::control()['enabled'], 'Queue defaults to paused' );
    foreach ( array( 'source', 'target', 'second' ) as $label ) {
        $id = wp_insert_post( array( 'post_title' => 'Queue fixture ' . $label, 'post_status' => 'publish', 'post_type' => 'post', 'post_date' => '2018-01-01 00:00:00', 'post_date_gmt' => '2018-01-01 00:00:00' ) );
        $ids[] = $id; RetentionFixtureQueue::$owned[] = $id;
        update_post_meta( $id, AceSeoRetentionReport::META, array( 'generation' => 'queue-fixture', 'backlinks' => 0, 'links_in' => 0, 'words' => 500 ) );
    }
    list( $source, $target, $second ) = $ids;
    update_option( AceSeoRetentionReport::PROGRESS_OPTION, array( 'phase' => 'done', 'generation' => 'queue-fixture', 'started' => time() - 10, 'finished' => time(), 'settings' => AceSeoRetentionReport::settings() ) );
    $make = static function( $source ) use ( $target, &$ids ) {
        $s = array( 'source' => array( 'id' => $source ), 'target' => array( 'id' => $target, 'url' => get_permalink( $target ) ), 'context' => array( 'period' => array( 'start' => gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ), 'end' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ) ), 'review' => array( 'confirmed' => true, 'reason' => 'Owned fixture: verified matching subject and historical coverage.' ) );
        $snapshot = RetentionFixtureQueue::preview( $s );
        if ( is_wp_error( $snapshot ) || $snapshot['holds'] ) { throw new RuntimeException( 'Fixture unexpectedly held: ' . wp_json_encode( $snapshot ) ); }
        $id = AceSeoRetentionPrototype::store( $snapshot ); $ids[] = $id; return $id;
    };
    $check( is_wp_error( AceSeoRetentionQueue::configure( true, array(), 1 ) ), 'Empty automatic scope refused' );
    $check( is_wp_error( AceSeoRetentionQueue::configure( true, array( $source . 'typo' ), 1 ) ), 'Malformed scope cannot silently select a different source' );
    $check( is_wp_error( AceSeoRetentionQueue::configure( true, array( $source ), 4 ) ), 'Daily cap cannot exceed three' );
    $check( true === AceSeoRetentionQueue::configure( true, array( $source, $second ), 3 ), 'Explicit scope and hourly schedule enabled' );
    $check( (bool) wp_next_scheduled( AceSeoRetentionQueue::HOOK ), 'Worker scheduled only after explicit enable' );
    $plan = $make( $source );
    $check( true === AceSeoRetentionQueue::approve( $plan ), 'Real queue approves complete evidence on an indexable site without a dev flag' );
    $check( true === RetentionFixtureQueue::approve( $plan ), 'Test-only fixture approval persisted' );
    $check( true === RetentionFixtureQueue::approve( $plan ), 'Duplicate approval is idempotent' );
    $lease = RetentionFixtureQueue::lock();
    $check( (bool) $lease && false === RetentionFixtureQueue::lock(), 'Concurrent worker cannot acquire held DB lease' );
    RetentionFixtureQueue::unlock( 'not-the-owner' ); RetentionFixtureQueue::tick();
    $check( 'approved' === AceSeoRetentionQueue::job( $plan )['state'], 'Non-owner release and concurrent tick do not process job' );
    RetentionFixtureQueue::unlock( $lease );
    $fail_cache_receipt = static function( $sql ) {
        if ( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, AceSeoRetentionQueue::table( 'jobs' ) ) && false !== strpos( stripslashes( $sql ), '"cache_pending":false' ) ) { throw new RuntimeException( 'Injected post-commit receipt failure.' ); }
        return $sql;
    };
    add_filter( 'query', $fail_cache_receipt );
    try { AceSeoRetentionQueue::tick(); } finally { remove_filter( 'query', $fail_cache_receipt ); }
    $j = AceSeoRetentionQueue::job( $plan );
    $check( 'applied' === $j['state'], 'Approved fixture applies through transaction and shared lifecycle: ' . ( $j['payload']['reason'] ?? '' ) );
    $check( true === $j['payload']['cache_pending'], 'Post-commit cache failure preserves applied state and durable retry' );
    $check( get_permalink( $target ) === AceSeoRedirects::settings( $source )['target'], 'Applied redirect points to verified fixture' );
    if ( '1' === getenv( 'ACE_SEO_REDIRECT_HTTP_TESTS' ) ) {
        remove_filter( 'pre_http_request', $http, 10 );
        try {
            $response = wp_remote_get( get_permalink( $source ), array( 'redirection' => 0, 'timeout' => 15 ) );
            $check( 301 === wp_remote_retrieve_response_code( $response ) && get_permalink( $target ) === wp_remote_retrieve_header( $response, 'location' ) && false !== strpos( wp_remote_retrieve_header( $response, 'cache-control' ), 'no-store' ), 'Real public-site queue serves the intended HTTP 301 with no-store' );
        } finally { add_filter( 'pre_http_request', $http, 10, 3 ); }
    }
    $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AceSeoRetentionQueue::table( 'events' ) . " WHERE kind='apply-intent'" );
    RetentionFixtureQueue::tick();
    $check( false === AceSeoRetentionQueue::job( $plan )['payload']['cache_pending'], 'Next worker retries cache invalidation before considering new applications' );
    $check( $count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AceSeoRetentionQueue::table( 'events' ) . " WHERE kind='apply-intent'" ), 'Repeated worker does not reapply the same job' );
    $check( true === AceSeoRetentionQueue::undo( $plan ) && '' === AceSeoRedirects::settings( $source )['target'], 'Guarded undo restores empty original rule' );
    $check( 'undone' === AceSeoRetentionQueue::job( $plan )['state'], 'Undo retained in durable ledger' );
    if ( '1' === getenv( 'ACE_SEO_REDIRECT_HTTP_TESTS' ) ) {
        remove_filter( 'pre_http_request', $http, 10 );
        try { $response = wp_remote_get( get_permalink( $source ), array( 'redirection' => 0, 'timeout' => 15 ) ); $check( 200 === wp_remote_retrieve_response_code( $response ), 'Undo restores the source HTTP 200' ); }
        finally { add_filter( 'pre_http_request', $http, 10, 3 ); }
    }

    $plan2 = $make( $source ); RetentionFixtureQueue::approve( $plan2 ); RetentionFixtureQueue::tick();
    AceSeoRedirects::save( $source, array( 'operation' => 'pause' ) );
    $check( is_wp_error( AceSeoRetentionQueue::undo( $plan2 ) ) && AceSeoRedirects::settings( $source )['paused'], 'Undo refuses to overwrite a later manual change' );
    AceSeoRedirects::save( $source, array( 'operation' => 'clear' ) );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    update_post_meta( $source, AceSeoRetentionPrototype::KEEP, array( 'by' => $admin ) );
    wp_cache_set( $source, array( AceSeoRetentionPrototype::KEEP => array( '' ) ), 'post_meta' );
    RetentionFixtureQueue::tick();
    $check( 'held' === AceSeoRetentionQueue::job( $p )['state'] && ! AceSeoRedirects::settings( $source )['target'], 'Fresh DB keep exclusion beats deliberately stale object cache' );
    delete_post_meta( $source, AceSeoRetentionPrototype::KEEP );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    RetentionFixtureQueue::$mutator = static function() use ( $source ) { update_post_meta( $source, AceSeoRetentionPrototype::KEEP, array( 'by' => 1 ) ); };
    RetentionFixtureQueue::tick(); RetentionFixtureQueue::$mutator = null;
    $check( 'held' === AceSeoRetentionQueue::job( $p )['state'] && ! AceSeoRedirects::settings( $source )['target'], 'Change between HTTP preflight and commit is refused' );
    delete_post_meta( $source, AceSeoRetentionPrototype::KEEP );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    $fail_applied = static function( $sql ) {
        if ( false !== strpos( $sql, AceSeoRetentionQueue::table( 'events' ) ) && false !== strpos( $sql, "'applied'" ) && 0 === strpos( $sql, 'INSERT' ) ) { throw new RuntimeException( 'Injected audit failure after redirect metadata write.' ); }
        return $sql;
    };
    add_filter( 'query', $fail_applied );
    try { RetentionFixtureQueue::tick(); } finally { remove_filter( 'query', $fail_applied ); }
    $check( 'held' === AceSeoRetentionQueue::job( $p )['state'] && ! AceSeoRedirects::settings( $source )['target'], 'Audit failure after metadata write rolls back the redirect and action intent' );
    $check( $count + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AceSeoRetentionQueue::table( 'events' ) . " WHERE kind='apply-intent'" ), 'Rolled back write does not consume a daily application' );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    wp_update_post( array( 'ID' => $p, 'post_content' => '{}' ) ); RetentionFixtureQueue::tick();
    $check( 'held' === AceSeoRetentionQueue::job( $p )['state'] && ! AceSeoRedirects::settings( $source )['target'], 'Tampered frozen assessment is never applied' );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    AceSeoRedirects::save( $target, array( 'target' => get_permalink( $second ), 'code' => 301 ) ); RetentionFixtureQueue::tick();
    $check( 'held' === AceSeoRetentionQueue::job( $p )['state'] && ! AceSeoRedirects::settings( $source )['target'], 'Destination becoming a redirect invalidates approval' );
    AceSeoRedirects::save( $target, array( 'operation' => 'clear' ) );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    $wpdb->update( AceSeoRetentionQueue::table( 'jobs' ), array( 'expires' => time() - 1 ), array( 'plan_id' => $p ) );
    RetentionFixtureQueue::tick(); $check( 'expired' === AceSeoRetentionQueue::job( $p )['state'], 'Expired approval cannot apply' );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    $check( true === AceSeoRetentionQueue::cancel( $p ) && 'cancelled' === AceSeoRetentionQueue::job( $p )['state'], 'Pending approval can be cancelled' );

    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    $wpdb->update( AceSeoRetentionQueue::table( 'jobs' ), array( 'state' => 'applying' ), array( 'plan_id' => $p ) );
    RetentionFixtureQueue::tick(); $check( 'review' === AceSeoRetentionQueue::job( $p )['state'], 'Interrupted action is held for review without automatic retry' );

    $actor = wp_insert_user( array( 'user_login' => 'queue-fixture-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) ); $users[] = $actor;
    wp_set_current_user( $actor ); $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    ( new WP_User( $actor ) )->set_role( 'editor' ); wp_set_current_user( $admin ); RetentionFixtureQueue::tick();
    $check( 'held' === AceSeoRetentionQueue::job( $p )['state'], 'Revoked approver permission prevents execution' );

    AceSeoRetentionQueue::configure( true, array( $source, $second ), 1 );
    $p = $make( $source ); RetentionFixtureQueue::approve( $p ); RetentionFixtureQueue::tick();
    $check( 'approved' === AceSeoRetentionQueue::job( $p )['state'] && ! AceSeoRedirects::settings( $source )['target'], 'Daily cap counts prior applications even after undo' );
    AceSeoRetentionQueue::cancel( $p );

    AceSeoRetentionQueue::configure( true, array( $source, $second ), 3 );
    $p = $make( $source ); RetentionFixtureQueue::approve( $p );
    RetentionFixtureQueue::$mutator = static function() use ( $source, $second ) { AceSeoRetentionQueue::configure( false, array( $source, $second ), 3 ); };
    RetentionFixtureQueue::tick(); RetentionFixtureQueue::$mutator = null;
    $check( ! AceSeoRedirects::settings( $source )['target'] && ! wp_next_scheduled( AceSeoRetentionQueue::HOOK ), 'Kill switch during preflight stops application and removes schedule' );
    $check( ! AceSeoRetentionQueue::control()['enabled'], 'Queue remains paused after kill switch' );
    $copied=AceSeoRetentionQueue::control(); $copied['enabled']=true; $copied['site']='different-site'; $copied['scope']=array($source);
    update_option(AceSeoRetentionQueue::CONTROL,$copied);
    $check(!AceSeoRetentionQueue::control()['enabled'] && !AceSeoRetentionQueue::control()['scope'],'Cloned site settings cannot carry enabled processing or source scope');
    wp_set_current_user( 0 );
    $check( is_wp_error( AceSeoRetentionQueue::approve( $p ) ) && is_wp_error( AceSeoRetentionQueue::undo( $plan ) ), 'Anonymous callers cannot approve or undo' );
    echo "$checks queue checks passed.\n";
} catch ( Throwable $e ) { echo 'FAIL ' . $e->getMessage() . "\n"; throw $e; }
finally {
    wp_set_current_user( $admin ); RetentionFixtureQueue::$mutator = null;
    wp_clear_scheduled_hook( AceSeoRetentionQueue::HOOK ); delete_option( AceSeoRetentionQueue::LOCK );
    remove_filter( 'pre_http_request', $http, 10 );
    remove_all_filters( 'ace_seo_retention_evidence_context' ); remove_all_filters( 'ace_seo_retention_link_evidence' );
    if ( $original_provider ) { $GLOBALS['wp_filter']['ace_seo_retention_evidence_context'] = $original_provider; }
    if ( $original_links ) { $GLOBALS['wp_filter']['ace_seo_retention_link_evidence'] = $original_links; }
    foreach ( $ids as $id ) { $wpdb->delete( AceSeoRetentionQueue::table( 'jobs' ), array( 'plan_id' => $id ) ); wp_delete_post( $id, true ); }
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AceSeoRetentionQueue::table( 'events' ) . ' WHERE id>%d', $events_start ) );
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
    update_option( 'blog_public', $public ); update_option( AceSeoRetentionReport::PROGRESS_OPTION, $progress );
    if ( null === $control ) { delete_option( AceSeoRetentionQueue::CONTROL ); } else { update_option( AceSeoRetentionQueue::CONTROL, $control ); }
}
