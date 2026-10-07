<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-snapshot.php';
$dir = sys_get_temp_dir() . '/ace-retention-snapshot-test-' . bin2hex( random_bytes( 6 ) );
mkdir( $dir, 0700 );
$n = 0;
function snapshot_check( $ok, $why ) { global $n; ++$n; if ( ! $ok ) { throw new RuntimeException( $why ); } }
function snapshot_reject( $callback, $why ) { try { $callback(); } catch ( RuntimeException $e ) { snapshot_check( true, $why ); return; } throw new RuntimeException( $why ); }
try {
    $store = new Ace_SEO_Retention_Snapshot( $dir );
    $meta = array( 'rule_version' => 'test-1', 'scope' => 'explicit IDs', 'as_of' => '2026-10-07', 'period' => array( 'start' => '2026-01-01', 'end' => '2026-04-30' ), 'timezone' => 'Europe/London' );
    $store->begin( array( 8, 2, 8 ), $meta );
    snapshot_check( array( 2, 8 ) === $store->state()['ids'], 'Scope must be frozen, unique and stable.' );
    snapshot_reject( static function () use ( $dir ) { new Ace_SEO_Retention_Snapshot( $dir ); }, 'A second worker must not own the same run.' );
    snapshot_reject( static function () use ( $store ) { $store->append( array( array( 'id' => 8 ) ) ); }, 'Out-of-order batch rejected.' );
    $store->append( array( array( 'id' => 2, 'baseline' => array( 'tier' => 'retained', 'title' => '=example()' ) ) ) );
    snapshot_reject( static function () use ( $store ) { $store->finish(); }, 'Incomplete run cannot publish.' );
    unset( $store );
    file_put_contents( $dir . '/records.jsonl', '{"partial":', FILE_APPEND );
    $store = new Ace_SEO_Retention_Snapshot( $dir );
    snapshot_check( 1 === $store->state()['offset'], 'Resume keeps the saved cursor.' );
    $store->append( array( array( 'id' => 8, 'baseline' => array( 'tier' => 'dormant' ) ) ) );
    $manifest = $store->finish();
    snapshot_check( 2 === $manifest['records'] && 2 === count( file( $dir . '/records.jsonl' ) ), 'Interrupted bytes removed; no duplicated or missing records.' );
    snapshot_check( false === strpos( file_get_contents( $dir . '/records.jsonl' ), 'partial' ), 'Interrupted record removed.' );
    snapshot_reject( static function () use ( $store ) { $store->append( array() ); }, 'Completed snapshot immutable.' );
    snapshot_reject( static function () use ( $store, $meta ) { $store->begin( array( 9 ), $meta ); }, 'Restart cannot replace baseline.' );
    $store->export_csv( $dir . '/baseline.csv' );
    snapshot_check( 3 === count( file( $dir . '/baseline.csv' ) ), 'CSV contains header and both baseline records.' );
    $csv = fopen( $dir . '/baseline.csv', 'r' ); fgetcsv( $csv, 0, ',', '"', '' ); $csv_row = fgetcsv( $csv, 0, ',', '"', '' ); fclose( $csv );
    snapshot_check( "'=example()" === $csv_row[1], 'CSV titles cannot be evaluated as spreadsheet formulae.' );
    snapshot_reject( static function () use ( $store, $dir ) { $store->export_csv( $dir . '/baseline.csv' ); }, 'Existing CSV cannot be overwritten.' );
    $state = $store->state(); $state['status'] = 'running';
    file_put_contents( $dir . '/state.json', json_encode( $state ) );
    unset( $store );
    $store = new Ace_SEO_Retention_Snapshot( $dir );
    snapshot_check( 'complete' === $store->state()['status'], 'Manifest publication survives interruption before the final state update.' );
    snapshot_reject( static function () use ( $store ) { $store->append( array() ); }, 'Stale state cannot reopen a complete manifest.' );
    file_put_contents( $dir . '/records.jsonl', 'tamper', FILE_APPEND );
    snapshot_reject( static function () use ( $store, $dir ) { $store->export_csv( $dir . '/tampered.csv' ); }, 'Modified snapshot cannot be exported as verified.' );
    $manifest['sha256'] = hash_file( 'sha256', $dir . '/records.jsonl' );
    file_put_contents( $dir . '/manifest.json', json_encode( $manifest ) );
    try {
        $store->export_csv( $dir . '/partial.csv' );
        throw new RuntimeException( 'Malformed input was accepted.' );
    } catch ( JsonException $expected ) {
        snapshot_check( ! file_exists( $dir . '/partial.csv' ), 'Failed partial CSV is removed so resume cannot mistake it for complete.' );
    }
    echo "$n snapshot recovery checks passed.\n";
} finally {
    unset( $store );
    foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); }
    if ( is_file( $dir . '/.lock' ) ) { unlink( $dir . '/.lock' ); }
    rmdir( $dir );
}
