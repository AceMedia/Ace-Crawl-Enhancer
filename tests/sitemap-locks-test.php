<?php
/**
 * Real MariaDB lease regression checks with independent PHP workers.
 *
 * Uses only a disposable MariaDB socket (never an existing site's configuration):
 * ACECRAWL_TEST_DB_SOCKET=/tmp/disposable/mysql.sock ACECRAWL_TEST_WP_CORE=/path/to/core php tests/sitemap-locks-test.php
 * The socket must belong to an isolated test server with a passwordless root user.
 * A randomly named test database is created and removed by the parent process.
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
$socket = getenv( 'ACECRAWL_TEST_DB_SOCKET' );
$core = getenv( 'ACECRAWL_TEST_WP_CORE' );
if ( ! $socket || ! $core ) { fwrite( STDERR, "Set ACECRAWL_TEST_DB_SOCKET and ACECRAWL_TEST_WP_CORE to disposable MariaDB and WordPress core paths.\n" ); exit( 1 ); }
define( 'ABSPATH', rtrim( $core, '/' ) . '/' );
define( 'WP_DEBUG', false );
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return 'query' === $hook ? $GLOBALS['wpdb']->remove_placeholder_escape( $value ) : $value; }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_load_translations_early() {}
function __( $message ) { return $message; }
function wp_debug_backtrace_summary() { return ''; }
function has_filter() { return false; }
function did_action() { return false; }
function is_multisite() { return false; }
function wp_using_ext_object_cache() { return ! empty( $GLOBALS['test_object_cache'] ); }
function ace_redis_cache() {}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function get_option( $key, $default = false ) { return $GLOBALS['test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['test_options'][ $key ] = $value; }
function wp_cache_get( $key, $group, $force = false, &$found = null ) { $found = isset( $GLOBALS['test_stale'] ) && 0 === strpos( $key, 'stale:' ); return $found ? $GLOBALS['test_stale'] : false; }
function wp_cache_set() { return true; }
function wp_cache_add() { throw new RuntimeException( 'Cache locks must not be used.' ); }
function wp_cache_delete() { throw new RuntimeException( 'Cache locks must not be used.' ); }
require ABSPATH . 'wp-includes/class-wpdb.php';
require dirname( __DIR__ ) . '/includes/ace-sitemap-powertools.php';
$db_name = $argv[2] ?? 'acecrawl_lease_test_' . bin2hex( random_bytes( 6 ) );
$wpdb = new wpdb( 'root', '', 'mysql', 'localhost:' . $socket );
$wpdb->suppress_errors( true );
if ( ! empty( $argv[1] ) && 'worker' === $argv[1] ) {
    $wpdb->select( $db_name );
    $wpdb->options = 'wp_options';
    $GLOBALS['test_object_cache'] = (bool) $argv[4];
    $deadline = microtime( true ) + 10;
    while ( ! file_exists( $argv[3] ) && microtime( true ) < $deadline ) { usleep( 1000 ); }
    echo acecrawl_sitemap_lock_acquire( 'contention', 30 ) ? 'won' : 'lost';
    exit;
}
function check( $label, $ok ) { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS $label\n"; }
$wpdb->query( "CREATE DATABASE `$db_name`" );
$wpdb->select( $db_name );
$wpdb->options = 'wp_options';
$wpdb->query( 'CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY(option_id), UNIQUE KEY option_name(option_name)) ENGINE=InnoDB' );
try {
    foreach ( array( false, true ) as $cache ) {
        $GLOBALS['test_object_cache'] = $cache;
        $wpdb->query( 'TRUNCATE wp_options' );
        foreach ( array( 'new', 'expired' ) as $state ) {
            if ( 'expired' === $state ) { $wpdb->query( "UPDATE wp_options SET option_value = 'previous-owner:1'" ); }
            $gate = tempnam( sys_get_temp_dir(), 'acecrawl-gate-' ); unlink( $gate );
            $workers = array();
            for ( $i = 0; $i < 16; $i++ ) {
                $pipes = array();
                $process = proc_open( array( PHP_BINARY, __FILE__, 'worker', $db_name, $gate, (string) (int) $cache ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
                $workers[] = array( $process, $pipes );
            }
            touch( $gate );
            $wins = 0;
            $clean_workers = true;
            foreach ( $workers as $worker ) {
                $out = stream_get_contents( $worker[1][1] );
                $err = stream_get_contents( $worker[1][2] );
                fclose( $worker[1][1] ); fclose( $worker[1][2] );
                $clean_workers = ( 0 === proc_close( $worker[0] ) && '' === $err ) && $clean_workers;
                $wins += 'won' === $out ? 1 : 0;
            }
            unlink( $gate );
            check( '16 independent workers exit cleanly', $clean_workers );
            check( 'one winner for ' . $state . ' lease, object cache ' . ( $cache ? 'on' : 'off' ), 1 === $wins );
        }
    }
    $GLOBALS['test_object_cache'] = false;
    $first = acecrawl_sitemap_lock_acquire( 'stale', 30 );
    check( 'active lease blocks contender', $first && false === acecrawl_sitemap_lock_acquire( 'stale', 30 ) );
    $name = 'acecrawl_sitemap_lease_' . md5( 'stale' );
    $wpdb->query( $wpdb->prepare( "UPDATE wp_options SET option_value = CONCAT(%s, ':', UNIX_TIMESTAMP() - 1) WHERE option_name = %s", $first, $name ) );
    $next = acecrawl_sitemap_lock_acquire( 'stale', 30 );
    check( 'expired lease replaced with new owner', $next && $next !== $first );
    check( 'expired owner cannot release successor', false === acecrawl_sitemap_lock_release( 'stale', $first ) && false === acecrawl_sitemap_lock_acquire( 'stale', 30 ) );
    check( 'successor releases its own lease', acecrawl_sitemap_lock_release( 'stale', $next ) );
    check( 'released lease reacquired', (bool) acecrawl_sitemap_lock_acquire( 'stale', 30 ) );
    $first = acecrawl_sitemap_cache_acquire_lock( 'cached' );
    check( 'cache wrappers preserve owner token', $first && ! acecrawl_sitemap_cache_release_lock( 'cached', 'wrong' ) && acecrawl_sitemap_cache_release_lock( 'cached', $first ) );
    check( 'debounce invalidates only once', acecrawl_sitemap_bump_cache_version_debounced( 'post_1' ) && ! acecrawl_sitemap_bump_cache_version_debounced( 'post_1' ) && 2 === $GLOBALS['test_options']['acecrawl_sitemap_cache_version'] );
    $wpdb->query( "CREATE TABLE wp_second_options LIKE wp_options" );
    $wpdb->options = 'wp_second_options';
    $other_site = acecrawl_sitemap_lock_acquire( 'stale', 30 );
    check( 'leases isolated by current site options table', $other_site && acecrawl_sitemap_lock_release( 'stale', $other_site ) );
    $wpdb->options = 'wp_options';
    $token = acecrawl_sitemap_lock_acquire( 'minimum', 0 );
    $expiry = $wpdb->get_var( $wpdb->prepare( "SELECT CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) - UNIX_TIMESTAMP() FROM wp_options WHERE option_name = %s", 'acecrawl_sitemap_lease_' . md5( 'minimum' ) ) );
    check( 'zero TTL becomes positive lease', $token && 1 === (int) $expiry );
    $wpdb->query( $wpdb->prepare( "UPDATE wp_options SET option_value = 'expired:1' WHERE option_name = %s", 'acecrawl_sitemap_lease_' . md5( 'minimum' ) ) );
    acecrawl_sitemap_lock_gc();
    check( 'cleanup removes expired rows and preserves live successor', null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', 'acecrawl_sitemap_lease_' . md5( 'minimum' ) ) ) && false === acecrawl_sitemap_lock_acquire( 'stale', 30 ) );
    try {
        acecrawl_sitemap_cache_remember( 'exception', function () { throw new RuntimeException( 'callback failed' ); } );
    } catch ( RuntimeException $exception ) {
        check( 'callback exception preserved', 'callback failed' === $exception->getMessage() );
    }
    check( 'finally releases failed callback lease', (bool) acecrawl_sitemap_cache_acquire_lock( 'exception' ) );
    $wpdb->options = 'missing_options';
    check( 'database write failure denies acquisition', false === acecrawl_sitemap_lock_acquire( 'failure', 30 ) );
    $before = $GLOBALS['test_options']['acecrawl_sitemap_cache_version'];
    check( 'failed debounce does not invalidate', ! acecrawl_sitemap_bump_cache_version_debounced( 'failure' ) && $before === $GLOBALS['test_options']['acecrawl_sitemap_cache_version'] );
    $ran = false;
    check( 'failed cold-cache lock returns fallback without callback', 'fallback' === acecrawl_sitemap_cache_remember( 'failure', function () use ( &$ran ) { $ran = true; }, 'fallback' ) && ! $ran );
    $GLOBALS['test_object_cache'] = true;
    $GLOBALS['test_stale'] = 'last good';
    check( 'failed lock serves stale data without callback', 'last good' === acecrawl_sitemap_cache_remember( 'failure', function () use ( &$ran ) { $ran = true; }, 'fallback' ) && ! $ran );
    $wpdb->ready = false;
    check( 'unavailable database fails closed', false === acecrawl_sitemap_lock_acquire( 'disconnected', 30 ) );
    $wpdb->ready = true;
    echo "All atomic lease checks passed.\n";
} finally {
    $wpdb->query( "DROP DATABASE `$db_name`" );
}
