<?php
/**
 * Regression checks for background sitemap generation.
 *
 * Runs against a real install in an isolated store (it never touches the site's own
 * artifacts), and cleans up after itself:
 *
 *     wp eval-file wp-content/plugins/Ace-Crawl-Enhancer/tests/sitemap-generations-test.php
 *
 * Exits non-zero on failure.
 */

if ( ! function_exists( 'acecrawl_sitemap_gen_get' ) ) {
    fwrite( STDERR, "Ace-Crawl-Enhancer is not loaded.\n" );
    exit( 1 );
}

$store = trailingslashit( get_temp_dir() ) . 'ace-sitemap-test-' . getmypid();
add_filter( 'acecrawl_sitemap_generation_dir', function () use ( $store ) {
    return $store;
} );

if ( acecrawl_sitemap_gen_dir() !== $store ) {
    fwrite( STDERR, "Store was initialised before the test could isolate it; run with wp eval-file.\n" );
    exit( 1 );
}

$saved_cron = wp_next_scheduled( ACECRAWL_SITEMAP_GEN_HOOK );

$failures = 0;
$check    = function ( $label, $ok ) use ( &$failures ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $ok ) {
        $failures++;
    }
};
$locs = function ( $list ) {
    return is_array( $list ) ? wp_list_pluck( $list, 'loc' ) : $list;
};

try {
    $server = wp_sitemaps_get_server();

    // 1. Parity: what the store serves is exactly what the providers build.
    $index = acecrawl_sitemap_get_cached_index_list( $server );
    $check( 'index parity with core', $locs( $index ) === $locs( $server->index->get_sitemap_list() ) );

    $checked = 0;
    foreach ( acecrawl_sitemap_custom_routes() as $route ) {
        $provider = $server->registry->get_provider( $route['provider'] );
        if ( ! $provider || $checked >= 4 ) {
            continue;
        }
        $served = acecrawl_sitemap_get_cached_url_list( $provider, $route, 1 );
        $direct = $provider->get_url_list( 1, $route['subtype'] );
        $check( "url list parity {$route['provider']}/{$route['subtype']} (" . count( $direct ) . ' urls)', $locs( $served ) === $locs( $direct ) );
        $checked++;
    }

    // 2. Stale serving: a dirty mark keeps serving the last good list, without rebuilding.
    $key   = 'url|testprov|x|1';
    $meta  = array( 'provider' => 'testprov', 'subtype' => 'x', 'page' => 1 );
    $calls = 0;
    $v1    = array( array( 'loc' => 'https://example.test/a/' ), array( 'loc' => 'https://example.test/b/' ) );
    $first = acecrawl_sitemap_gen_get( $key, $meta, function () use ( &$calls, $v1 ) { $calls++; return $v1; } );
    $check( 'cold build runs once', 1 === $calls && $locs( $first ) === $locs( $v1 ) );

    acecrawl_sitemap_gen_mark_dirty( 'all', true );
    wp_clear_scheduled_hook( ACECRAWL_SITEMAP_GEN_HOOK );
    $stale = acecrawl_sitemap_gen_get( $key, $meta, function () use ( &$calls ) { $calls++; return array(); } );
    $check( 'stale artifact served without a foreground rebuild', 1 === $calls && $locs( $stale ) === $locs( $v1 ) );
    $check( 'stale read schedules the worker', (bool) wp_next_scheduled( ACECRAWL_SITEMAP_GEN_HOOK ) );

    // 3. Fencing: an older build never replaces a newer artifact.
    $current = acecrawl_sitemap_gen_read( $key );
    $older   = array_merge( $current, array( 'seq' => (int) $current['seq'] - 1, 'data' => array( array( 'loc' => 'https://example.test/old/' ) ) ) );
    $check( 'older sequence refused', false === acecrawl_sitemap_gen_write( $older ) );
    $check( 'newer artifact intact', $locs( acecrawl_sitemap_gen_read( $key )['data'] ) === $locs( $v1 ) );

    // 4. Invalid output and exceptions keep the previous artifact and back off.
    $lock = acecrawl_sitemap_gen_lock( 'key-' . md5( $key ) );
    acecrawl_sitemap_gen_build( $key, $meta, function () { return array( array( 'nope' => 1 ) ); } );
    $check( 'invalid list keeps last good', $locs( acecrawl_sitemap_gen_read( $key )['data'] ) === $locs( $v1 ) );
    acecrawl_sitemap_gen_build( $key, $meta, function () { throw new RuntimeException( 'db gone' ); } );
    $check( 'exception keeps last good', $locs( acecrawl_sitemap_gen_read( $key )['data'] ) === $locs( $v1 ) );
    $state = acecrawl_sitemap_gen_state();
    $check( 'failure recorded with backoff', 2 === (int) $state['failures'][ $key ]['n'] && $state['failures'][ $key ]['next'] > time() );

    // 5. A list that empties keeps its contents for the grace period, then reads as empty.
    acecrawl_sitemap_gen_mark_dirty( 'all', true );
    acecrawl_sitemap_gen_build( $key, $meta, function () { return array(); } );
    acecrawl_sitemap_gen_unlock( $lock );
    $gone = acecrawl_sitemap_gen_read( $key );
    $check( 'emptied list marked gone, data retained', $gone['gone'] > 0 && $locs( $gone['data'] ) === $locs( $v1 ) );
    $check( 'within grace still served', $locs( acecrawl_sitemap_gen_get( $key, $meta, '__return_empty_array' ) ) === $locs( $v1 ) );
    add_filter( 'acecrawl_sitemap_generation_gone_grace', '__return_zero' );
    sleep( 1 );
    $check( 'after grace reads as empty', array() === acecrawl_sitemap_gen_get( $key, $meta, '__return_empty_array' ) );
    remove_filter( 'acecrawl_sitemap_generation_gone_grace', '__return_zero' );

    // 6. Urgent removal applies to stored lists straight away; republish restores it.
    $key2 = 'url|testprov|y|1';
    $meta2 = array( 'provider' => 'testprov', 'subtype' => 'y', 'page' => 1 );
    acecrawl_sitemap_gen_get( $key2, $meta2, function () use ( $v1 ) { return $v1; } );
    acecrawl_sitemap_gen_withhold( 'https://example.test/a/' );
    $check( 'withheld URL removed from served list', array( 'https://example.test/b/' ) === $locs( acecrawl_sitemap_gen_get( $key2, $meta2, '__return_empty_array' ) ) );
    acecrawl_sitemap_gen_release( 'http://EXAMPLE.test/a' );
    $check( 'released URL served again', 2 === count( acecrawl_sitemap_gen_get( $key2, $meta2, '__return_empty_array' ) ) );

    // 7. Concurrency: while another process holds the build lock for a never-built key,
    //    readers wait briefly then get null (503), never an empty list.
    $key3   = 'url|testprov|z|1';
    $meta3  = array( 'provider' => 'testprov', 'subtype' => 'z', 'page' => 1 );
    $lpath  = $store . '/.lock-' . sanitize_key( 'key-' . md5( $key3 ) );
    $holder = proc_open(
        array( PHP_BINARY, '-r', '$f=fopen($argv[1],"c"); flock($f, LOCK_EX); echo "L\n"; fflush(STDOUT); sleep(5);', $lpath ),
        array( 1 => array( 'pipe', 'w' ) ),
        $pipes
    );
    fgets( $pipes[1] );
    $t0     = microtime( true );
    $result = acecrawl_sitemap_gen_get( $key3, $meta3, function () { return array( array( 'loc' => 'https://example.test/c/' ) ); } );
    $check( 'busy cold build answers null (503), not empty', null === $result && microtime( true ) - $t0 < 4.5 );
    proc_terminate( $holder );
    proc_close( $holder );
    $check( 'dead holder frees the lock; build succeeds', 1 === count( (array) acecrawl_sitemap_gen_get( $key3, $meta3, function () { return array( array( 'loc' => 'https://example.test/c/' ) ); } ) ) );

    // 8. Worker: rebuilds only stale artifacts, pages before the index, then goes idle.
    acecrawl_sitemap_gen_mark_dirty( 'all', true );
    foreach ( array( $key, $key2, $key3 ) as $k ) {
        @unlink( acecrawl_sitemap_gen_file( $k ) ); // Test providers do not exist for the worker.
    }
    $run = acecrawl_sitemap_gen_run( array( 'force' => true ) );
    $check( 'worker pass rebuilds stale artifacts (' . $run['built'] . ')', $run['built'] > 0 && 0 === $run['failed'] );
    $again = acecrawl_sitemap_gen_run( array( 'force' => true ) );
    $check( 'second pass has nothing to do', 0 === $again['built'] && 0 === $again['remaining'] );

    // 8b. The index waits for its minimum age; out-of-range pages are never stored.
    acecrawl_sitemap_gen_mark_dirty( 'all', true );
    $held_back = acecrawl_sitemap_gen_run( array() );
    $check( 'fresh index held back by its minimum age', 0 === $held_back['built'] || ! empty( $held_back['deferred'] ) );
    $files_before = count( (array) glob( $store . '/*.json' ) );
    $junk         = acecrawl_sitemap_gen_get( 'url|posts|post|99999', array( 'provider' => 'posts', 'subtype' => 'post', 'page' => 99999 ), '__return_empty_array' );
    $check( 'out-of-range page answers empty and stores nothing', array() === $junk && count( (array) glob( $store . '/*.json' ) ) === $files_before );
    $header = acecrawl_sitemap_gen_read_header( acecrawl_sitemap_gen_file( acecrawl_sitemap_gen_index_key() ) );
    $check( 'header read matches full read', $header && $header['seq'] === acecrawl_sitemap_gen_read( acecrawl_sitemap_gen_index_key() )['seq'] );

    // 9. Scoped dirtiness: a posts:<type> change leaves other providers current.
    $scopes_tax = acecrawl_sitemap_gen_scopes_for( 'taxonomies', 'category' );
    $before     = acecrawl_sitemap_gen_required_seq( $scopes_tax );
    acecrawl_sitemap_gen_mark_dirty( 'posts:post', true );
    $check( 'post change does not dirty taxonomy lists', acecrawl_sitemap_gen_required_seq( $scopes_tax ) === $before );
    $check( 'post change dirties the index', acecrawl_sitemap_gen_required_seq( acecrawl_sitemap_gen_scopes_for( 'index', '' ) ) > $before );

    // 10. Only one worker at a time.
    $held = acecrawl_sitemap_gen_lock( 'worker' );
    $check( 'second worker yields', 'busy' === acecrawl_sitemap_gen_run( array( 'force' => true ) )['skipped'] );
    acecrawl_sitemap_gen_unlock( $held );

    // 12. Keyed scopes: a mark confined to one key leaves that scope's other keys current.
    $keyed_filter = function ( $scopes, $provider ) {
        return 'keyprov' === $provider ? array_merge( $scopes, array( 'posts:keyprov' ) ) : $scopes;
    };
    add_filter( 'acecrawl_sitemap_generation_keyed_scopes', $keyed_filter, 10, 2 );
    $scope_filter = function ( $scopes, $provider ) {
        return 'keyprov' === $provider ? array( 'posts:keyprov' ) : $scopes;
    };
    add_filter( 'acecrawl_sitemap_generation_provider_scopes', $scope_filter, 10, 2 );
    $mk = array( 'provider' => 'keyprov', 'subtype' => 'k', 'page' => 1 );
    acecrawl_sitemap_gen_mark_dirty( 'posts:keyprov', true ); // scope-wide baseline
    $req1 = acecrawl_sitemap_gen_artifact_required_seq( 'url|keyprov|k|1', $mk );
    $req2 = acecrawl_sitemap_gen_artifact_required_seq( 'url|keyprov|k|2', array_merge( $mk, array( 'page' => 2 ) ) );
    acecrawl_sitemap_gen_mark_dirty( 'posts:keyprov', true, 'url|keyprov|k|2' );
    $check( 'keyed mark leaves other keys current', acecrawl_sitemap_gen_artifact_required_seq( 'url|keyprov|k|1', $mk ) === $req1 );
    $check( 'keyed mark dirties its own key', acecrawl_sitemap_gen_artifact_required_seq( 'url|keyprov|k|2', array_merge( $mk, array( 'page' => 2 ) ) ) > $req2 );
    $check( 'keyed mark still moves the whole scope for its other dependants', acecrawl_sitemap_gen_required_seq( array( 'posts:keyprov' ) ) > $req1 );
    acecrawl_sitemap_gen_mark_dirty( 'posts:keyprov', true );
    $check( 'scope-wide mark dirties every key', acecrawl_sitemap_gen_artifact_required_seq( 'url|keyprov|k|1', $mk ) > $req1 );
    remove_filter( 'acecrawl_sitemap_generation_keyed_scopes', $keyed_filter, 10 );
    remove_filter( 'acecrawl_sitemap_generation_provider_scopes', $scope_filter, 10 );

    // 13. Max age: an artifact past it is stale even with no marks; zero disables the rule.
    $old = array( 'key' => 'url|testprov|x|1', 'meta' => $meta, 'seq' => PHP_INT_MAX, 'built' => time() - 2 * DAY_IN_SECONDS );
    $check( 'artifact past max age is stale', acecrawl_sitemap_gen_is_stale( $old ) );
    add_filter( 'acecrawl_sitemap_generation_max_age', '__return_zero' );
    $check( 'max age 0 disables the age rule', ! acecrawl_sitemap_gen_is_stale( $old ) );
    remove_filter( 'acecrawl_sitemap_generation_max_age', '__return_zero' );

    // 14. Oldest first: the worker's queue is ordered by sequence, not alphabetically by key.
    $sk  = 'url|testprov|aaa|1';
    $sk2 = 'url|testprov|zzz|1';
    $lk  = acecrawl_sitemap_gen_lock( 'key-' . md5( $sk2 ) );
    acecrawl_sitemap_gen_build( $sk2, array( 'provider' => 'testprov', 'subtype' => 'zzz', 'page' => 1 ), function () use ( $v1 ) { return $v1; } );
    acecrawl_sitemap_gen_unlock( $lk );
    acecrawl_sitemap_gen_mark_dirty( 'all', true );
    $lk = acecrawl_sitemap_gen_lock( 'key-' . md5( $sk ) );
    acecrawl_sitemap_gen_build( $sk, array( 'provider' => 'testprov', 'subtype' => 'aaa', 'page' => 1 ), function () use ( $v1 ) { return $v1; } );
    acecrawl_sitemap_gen_unlock( $lk );
    acecrawl_sitemap_gen_mark_dirty( 'all', true );
    $order = wp_list_pluck( acecrawl_sitemap_gen_scan()['dirty'], 'key' );
    $check( 'older artifact queued before a newer one', array_search( $sk2, $order, true ) < array_search( $sk, $order, true ) );
    @unlink( acecrawl_sitemap_gen_file( $sk ) );
    @unlink( acecrawl_sitemap_gen_file( $sk2 ) );

    // 15. Builds bypass the powertools object cache, so a rebuild never re-stores the old list.
    $seen = null;
    $lk   = acecrawl_sitemap_gen_lock( 'key-' . md5( $sk ) );
    acecrawl_sitemap_gen_build( $sk, $meta, function () use ( &$seen, $v1 ) { $seen = acecrawl_sitemap_cache_enabled(); return $v1; } );
    acecrawl_sitemap_gen_unlock( $lk );
    @unlink( acecrawl_sitemap_gen_file( $sk ) );
    $check( 'object cache layer off during a build', false === $seen && ! acecrawl_sitemap_gen_is_building() );

    // 16. Stable pages on a real ID-ordered type: every visible post on exactly one page, each
    //     post's page found by its ID, and an edit confined to that page.
    // The busiest qualifying type, so several pages are exercised where the site has them.
    $stable_type = '';
    $most        = 1;
    foreach ( get_post_types( array( 'public' => true ) ) as $pt ) {
        $count = acecrawl_sitemap_should_short_circuit_posts_sitemap( $pt ) && acecrawl_sitemap_stable_pages_supported( $pt ) ? acecrawl_sitemap_get_visible_post_count( $pt ) : 0;
        if ( $count > $most ) {
            $stable_type = $pt;
            $most        = $count;
        }
    }
    if ( '' === $stable_type ) {
        echo "SKIP stable pages (no ID-ordered public type with posts)\n";
    } else {
        $map     = acecrawl_sitemap_page_map( $stable_type );
        $visible = acecrawl_sitemap_visible_ids_in_range( $stable_type );
        $paged   = array();
        $placed  = true;
        for ( $p = 1; $p <= (int) $map['n']; $p++ ) {
            $range = acecrawl_sitemap_page_range( $map, $p );
            $ids   = acecrawl_sitemap_visible_ids_in_range( $stable_type, $range[0], $range[1] );
            foreach ( array( reset( $ids ), end( $ids ) ) as $probe ) {
                $placed = $placed && ( ! $probe || acecrawl_sitemap_page_for_id( $map, $probe ) === $p );
            }
            $paged = array_merge( $paged, $ids );
        }
        $check( "stable pages cover every visible {$stable_type} once ({$map['n']} pages, " . count( $visible ) . ' posts)', $paged === $visible );
        $check( 'page found from a post ID', $placed );
        $check( 'served page matches its range', acecrawl_sitemap_get_visible_post_ids_for_page( $stable_type, 1, (int) $map['per'] ) === acecrawl_sitemap_visible_ids_in_range( $stable_type, 0, acecrawl_sitemap_page_range( $map, 1 )[1] ) );
        $check( 'max pages comes from the map', (int) $map['n'] === (int) acecrawl_sitemap_posts_pre_max_num_pages( null, $stable_type ) );

        $last_post = get_post( end( $visible ) );
        $first_key = acecrawl_sitemap_gen_url_key( 'posts', $stable_type, 1 );
        $last_key  = acecrawl_sitemap_gen_url_key( 'posts', $stable_type, (int) $map['n'] );
        $pm        = array( 'provider' => 'posts', 'subtype' => $stable_type, 'page' => 1 );
        $before1   = acecrawl_sitemap_gen_artifact_required_seq( $first_key, $pm );
        acecrawl_sitemap_gen_mark_post_dirty( $last_post );
        acecrawl_sitemap_gen_mark_dirty( null, true );
        if ( (int) $map['n'] > 1 ) {
            $check( 'edit to the newest post leaves page 1 current', acecrawl_sitemap_gen_artifact_required_seq( $first_key, $pm ) === $before1 );
        }
        $check( 'edit to the newest post dirties the last page', acecrawl_sitemap_gen_artifact_required_seq( $last_key, array_merge( $pm, array( 'page' => (int) $map['n'] ) ) ) > $before1 );
    }

    // 11. Ace Redis Cache no longer warms sitemap URLs over HTTP.
    $urls = acecrawl_sitemap_gen_filter_redis_prime_urls( array( home_url( '/sitemap.xml' ), home_url( '/wp-sitemap-posts-post-1.xml' ), home_url( '/about/' ) ) );
    $check( 'redis primer keeps only non-sitemap URLs', array( home_url( '/about/' ) ) === $urls );

    // 12. A rebuild that changes what is served purges downstream page caches; one that does not, does not.
    $index_urls = acecrawl_sitemap_gen_public_urls( 'index', array( 'provider' => 'index', 'subtype' => '', 'page' => 0 ) );
    $check( 'index purge covers both index URLs', in_array( home_url( '/wp-sitemap.xml' ), $index_urls, true ) && in_array( home_url( '/sitemap.xml' ), $index_urls, true ) );

    foreach ( acecrawl_sitemap_custom_routes() as $slug => $route ) {
        $route_urls = acecrawl_sitemap_gen_public_urls( 'route', array( 'provider' => $route['provider'], 'subtype' => $route['subtype'], 'page' => 1 ) );
        $has_core   = (bool) preg_grep( '~/wp-sitemap-~', $route_urls );
        $has_clean  = (bool) preg_grep( '~/' . preg_quote( $slug, '~' ) . '(-1)?\.xml$~', $route_urls );
        $check( "route {$slug} purge covers its core and clean URLs", $has_core && $has_clean );
        break;
    }

    $purge_key  = 'url|purgeprov|x|1';
    $purge_meta = array( 'provider' => 'purgeprov', 'subtype' => 'x', 'page' => 1 );
    $purge_url  = home_url( '/ace-sitemap-purge-test.xml' );
    $purged     = array();
    $add_url    = function ( $urls, $key ) use ( $purge_key, $purge_url ) {
        return $key === $purge_key ? array_merge( $urls, array( $purge_url ) ) : $urls;
    };
    $listen     = function ( $key, $meta, $urls ) use ( &$purged, $purge_key ) {
        if ( $key === $purge_key ) {
            $purged[] = $urls;
        }
    };
    add_filter( 'acecrawl_sitemap_generation_public_urls', $add_url, 10, 2 );
    add_action( 'acecrawl_sitemap_generation_artifact_changed', $listen, 10, 3 );
    $v2 = array( array( 'loc' => 'https://example.test/c/' ) );
    acecrawl_sitemap_gen_build( $purge_key, $purge_meta, function () use ( $v1 ) { return $v1; } );
    $check( 'first build purges its URLs', 1 === count( $purged ) && array( $purge_url ) === $purged[0] );
    acecrawl_sitemap_gen_build( $purge_key, $purge_meta, function () use ( $v1 ) { return $v1; } );
    $check( 'identical rebuild does not purge', 1 === count( $purged ) );
    acecrawl_sitemap_gen_build( $purge_key, $purge_meta, function () use ( $v2 ) { return $v2; } );
    $check( 'changed rebuild purges again', 2 === count( $purged ) );
    remove_filter( 'acecrawl_sitemap_generation_public_urls', $add_url, 10 );
    remove_action( 'acecrawl_sitemap_generation_artifact_changed', $listen, 10 );
} finally {
    wp_clear_scheduled_hook( ACECRAWL_SITEMAP_GEN_HOOK );
    if ( $saved_cron ) {
        wp_schedule_single_event( $saved_cron, ACECRAWL_SITEMAP_GEN_HOOK );
    }
    foreach ( array_merge( (array) glob( $store . '/*' ), (array) glob( $store . '/.*' ) ) as $file ) {
        if ( is_file( $file ) ) {
            @unlink( $file );
        }
    }
    @rmdir( $store );
}

echo $failures ? "\n{$failures} failed\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
