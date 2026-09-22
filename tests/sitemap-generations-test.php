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

if ( ! function_exists( 'ace_sitemap_gen_get' ) ) {
    fwrite( STDERR, "Ace-Crawl-Enhancer is not loaded.\n" );
    exit( 1 );
}

$store = trailingslashit( get_temp_dir() ) . 'ace-sitemap-test-' . getmypid();
add_filter( 'ace_sitemap_generation_dir', function () use ( $store ) {
    return $store;
} );

if ( ace_sitemap_gen_dir() !== $store ) {
    fwrite( STDERR, "Store was initialised before the test could isolate it; run with wp eval-file.\n" );
    exit( 1 );
}

$saved_cron = wp_next_scheduled( ACE_SITEMAP_GEN_HOOK );

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
    $index = ace_sitemap_powertools_get_cached_index_list( $server );
    $check( 'index parity with core', $locs( $index ) === $locs( $server->index->get_sitemap_list() ) );

    $checked = 0;
    foreach ( ace_sitemap_powertools_custom_routes() as $route ) {
        $provider = $server->registry->get_provider( $route['provider'] );
        if ( ! $provider || $checked >= 4 ) {
            continue;
        }
        $served = ace_sitemap_powertools_get_cached_url_list( $provider, $route, 1 );
        $direct = $provider->get_url_list( 1, $route['subtype'] );
        $check( "url list parity {$route['provider']}/{$route['subtype']} (" . count( $direct ) . ' urls)', $locs( $served ) === $locs( $direct ) );
        $checked++;
    }

    // 2. Stale serving: a dirty mark keeps serving the last good list, without rebuilding.
    $key   = 'url|testprov|x|1';
    $meta  = array( 'provider' => 'testprov', 'subtype' => 'x', 'page' => 1 );
    $calls = 0;
    $v1    = array( array( 'loc' => 'https://example.test/a/' ), array( 'loc' => 'https://example.test/b/' ) );
    $first = ace_sitemap_gen_get( $key, $meta, function () use ( &$calls, $v1 ) { $calls++; return $v1; } );
    $check( 'cold build runs once', 1 === $calls && $locs( $first ) === $locs( $v1 ) );

    ace_sitemap_gen_mark_dirty( 'all', true );
    wp_clear_scheduled_hook( ACE_SITEMAP_GEN_HOOK );
    $stale = ace_sitemap_gen_get( $key, $meta, function () use ( &$calls ) { $calls++; return array(); } );
    $check( 'stale artifact served without a foreground rebuild', 1 === $calls && $locs( $stale ) === $locs( $v1 ) );
    $check( 'stale read schedules the worker', (bool) wp_next_scheduled( ACE_SITEMAP_GEN_HOOK ) );

    // 3. Fencing: an older build never replaces a newer artifact.
    $current = ace_sitemap_gen_read( $key );
    $older   = array_merge( $current, array( 'seq' => (int) $current['seq'] - 1, 'data' => array( array( 'loc' => 'https://example.test/old/' ) ) ) );
    $check( 'older sequence refused', false === ace_sitemap_gen_write( $older ) );
    $check( 'newer artifact intact', $locs( ace_sitemap_gen_read( $key )['data'] ) === $locs( $v1 ) );

    // 4. Invalid output and exceptions keep the previous artifact and back off.
    $lock = ace_sitemap_gen_lock( 'key-' . md5( $key ) );
    ace_sitemap_gen_build( $key, $meta, function () { return array( array( 'nope' => 1 ) ); } );
    $check( 'invalid list keeps last good', $locs( ace_sitemap_gen_read( $key )['data'] ) === $locs( $v1 ) );
    ace_sitemap_gen_build( $key, $meta, function () { throw new RuntimeException( 'db gone' ); } );
    $check( 'exception keeps last good', $locs( ace_sitemap_gen_read( $key )['data'] ) === $locs( $v1 ) );
    $state = ace_sitemap_gen_state();
    $check( 'failure recorded with backoff', 2 === (int) $state['failures'][ $key ]['n'] && $state['failures'][ $key ]['next'] > time() );

    // 5. A list that empties keeps its contents for the grace period, then reads as empty.
    ace_sitemap_gen_mark_dirty( 'all', true );
    ace_sitemap_gen_build( $key, $meta, function () { return array(); } );
    ace_sitemap_gen_unlock( $lock );
    $gone = ace_sitemap_gen_read( $key );
    $check( 'emptied list marked gone, data retained', $gone['gone'] > 0 && $locs( $gone['data'] ) === $locs( $v1 ) );
    $check( 'within grace still served', $locs( ace_sitemap_gen_get( $key, $meta, '__return_empty_array' ) ) === $locs( $v1 ) );
    add_filter( 'ace_sitemap_generation_gone_grace', '__return_zero' );
    sleep( 1 );
    $check( 'after grace reads as empty', array() === ace_sitemap_gen_get( $key, $meta, '__return_empty_array' ) );
    remove_filter( 'ace_sitemap_generation_gone_grace', '__return_zero' );

    // 6. Urgent removal applies to stored lists straight away; republish restores it.
    $key2 = 'url|testprov|y|1';
    $meta2 = array( 'provider' => 'testprov', 'subtype' => 'y', 'page' => 1 );
    ace_sitemap_gen_get( $key2, $meta2, function () use ( $v1 ) { return $v1; } );
    ace_sitemap_gen_withhold( 'https://example.test/a/' );
    $check( 'withheld URL removed from served list', array( 'https://example.test/b/' ) === $locs( ace_sitemap_gen_get( $key2, $meta2, '__return_empty_array' ) ) );
    ace_sitemap_gen_release( 'http://EXAMPLE.test/a' );
    $check( 'released URL served again', 2 === count( ace_sitemap_gen_get( $key2, $meta2, '__return_empty_array' ) ) );

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
    $result = ace_sitemap_gen_get( $key3, $meta3, function () { return array( array( 'loc' => 'https://example.test/c/' ) ); } );
    $check( 'busy cold build answers null (503), not empty', null === $result && microtime( true ) - $t0 < 4.5 );
    proc_terminate( $holder );
    proc_close( $holder );
    $check( 'dead holder frees the lock; build succeeds', 1 === count( (array) ace_sitemap_gen_get( $key3, $meta3, function () { return array( array( 'loc' => 'https://example.test/c/' ) ); } ) ) );

    // 8. Worker: rebuilds only stale artifacts, pages before the index, then goes idle.
    ace_sitemap_gen_mark_dirty( 'all', true );
    foreach ( array( $key, $key2, $key3 ) as $k ) {
        @unlink( ace_sitemap_gen_file( $k ) ); // Test providers do not exist for the worker.
    }
    $run = ace_sitemap_gen_run( array( 'force' => true ) );
    $check( 'worker pass rebuilds stale artifacts (' . $run['built'] . ')', $run['built'] > 0 && 0 === $run['failed'] );
    $again = ace_sitemap_gen_run( array( 'force' => true ) );
    $check( 'second pass has nothing to do', 0 === $again['built'] && 0 === $again['remaining'] );

    // 8b. The index waits for its minimum age; out-of-range pages are never stored.
    ace_sitemap_gen_mark_dirty( 'all', true );
    $held_back = ace_sitemap_gen_run( array() );
    $check( 'fresh index held back by its minimum age', 0 === $held_back['built'] || ! empty( $held_back['deferred'] ) );
    $files_before = count( (array) glob( $store . '/*.json' ) );
    $junk         = ace_sitemap_gen_get( 'url|posts|post|99999', array( 'provider' => 'posts', 'subtype' => 'post', 'page' => 99999 ), '__return_empty_array' );
    $check( 'out-of-range page answers empty and stores nothing', array() === $junk && count( (array) glob( $store . '/*.json' ) ) === $files_before );
    $header = ace_sitemap_gen_read_header( ace_sitemap_gen_file( ace_sitemap_gen_index_key() ) );
    $check( 'header read matches full read', $header && $header['seq'] === ace_sitemap_gen_read( ace_sitemap_gen_index_key() )['seq'] );

    // 9. Scoped dirtiness: a posts:<type> change leaves other providers current.
    $scopes_tax = ace_sitemap_gen_scopes_for( 'taxonomies', 'category' );
    $before     = ace_sitemap_gen_required_seq( $scopes_tax );
    ace_sitemap_gen_mark_dirty( 'posts:post', true );
    $check( 'post change does not dirty taxonomy lists', ace_sitemap_gen_required_seq( $scopes_tax ) === $before );
    $check( 'post change dirties the index', ace_sitemap_gen_required_seq( ace_sitemap_gen_scopes_for( 'index', '' ) ) > $before );

    // 10. Only one worker at a time.
    $held = ace_sitemap_gen_lock( 'worker' );
    $check( 'second worker yields', 'busy' === ace_sitemap_gen_run( array( 'force' => true ) )['skipped'] );
    ace_sitemap_gen_unlock( $held );

    // 11. Ace Redis Cache no longer warms sitemap URLs over HTTP.
    $urls = ace_sitemap_gen_filter_redis_prime_urls( array( home_url( '/sitemap.xml' ), home_url( '/wp-sitemap-posts-post-1.xml' ), home_url( '/about/' ) ) );
    $check( 'redis primer keeps only non-sitemap URLs', array( home_url( '/about/' ) ) === $urls );
} finally {
    wp_clear_scheduled_hook( ACE_SITEMAP_GEN_HOOK );
    if ( $saved_cron ) {
        wp_schedule_single_event( $saved_cron, ACE_SITEMAP_GEN_HOOK );
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
