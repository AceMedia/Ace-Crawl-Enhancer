<?php
/**
 * Read-only, resumable review snapshot with an unchanged baseline for every scoped post.
 * wp eval-file <plugin>/bin/retention-evidence-snapshot.php /private/new-run 2026-01-01 2026-04-30 2026-10-07
 * Resume: repeat with the SAME arguments. Restart/compare: choose a NEW directory.
 * Does not replace the report, apply actions or update Google Sheets. Dated traffic is read
 * through the evidence-context providers (cached per period); the baseline row is kept unchanged.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-retention-report.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-retention-evidence-view.php';
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-snapshot.php';

try {
    $directory = $args[0] ?? '';
    $start = $args[1] ?? ''; $end = $args[2] ?? ''; $as_of = $args[3] ?? wp_date( 'Y-m-d' );
    if ( ! Ace_SEO_Retention_Evidence::date( $start ) || ! Ace_SEO_Retention_Evidence::date( $end ) || ! Ace_SEO_Retention_Evidence::date( $as_of ) || $start > $end || $end >= $as_of ) {
        throw new InvalidArgumentException( 'Choose valid start/end dates for a finished observation period, followed by the assessment date.' );
    }
    if ( ! $directory || '/' !== $directory[0] ) { throw new InvalidArgumentException( 'Use an absolute path to a new private directory outside the web root.' ); }
    $parent = realpath( dirname( $directory ) );
    // Resolve core-in-a-subdirectory layouts without assuming where wp-content lives.
    $web_root = rtrim( ABSPATH, '/' );
    $site_path = trim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
    $home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
    if ( '' !== $site_path && 0 === strpos( $site_path, $home_path ) ) {
        $suffix = '/' . trim( substr( $site_path, strlen( $home_path ) ), '/' );
        if ( '/' !== $suffix && substr( $web_root, -strlen( $suffix ) ) === $suffix ) { $web_root = substr( $web_root, 0, -strlen( $suffix ) ); }
    }
    foreach ( array_filter( array( realpath( $web_root ), defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false ) ) as $served ) {
        if ( ! $parent || $parent === $served || 0 === strpos( $parent . '/', rtrim( $served, '/' ) . '/' ) ) { throw new InvalidArgumentException( 'Snapshot files must be outside the served site tree.' ); }
    }
    if ( ! $parent ) { throw new InvalidArgumentException( 'The private parent directory does not exist.' ); }
    if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Could not create the private snapshot directory.' ); }
    $store = new Ace_SEO_Retention_Snapshot( $directory );
    global $wpdb;
    $period = array( 'start' => $start, 'end' => $end );
    if ( ! $store->state() ) {
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_status='publish' ORDER BY p.ID", AceSeoRetentionReport::META ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Could not freeze the assessment scope.' ); }
        $store->begin( $ids, array( 'rule_version' => Ace_SEO_Retention_Evidence::VERSION, 'scope' => 'Published posts with an existing saved assessment; fixed post IDs', 'as_of' => $as_of, 'period' => $period, 'timezone' => wp_timezone()->getName(), 'settings' => AceSeoRetentionReport::settings(), 'source_capture' => 'Per-batch read of existing assessments; each row records its capture time. This is not a transaction-wide database backup.' ) );
    }
    $state = $store->state();
    if ( $state['provenance']['period'] !== $period || $state['provenance']['as_of'] !== $as_of || $state['provenance']['rule_version'] !== Ace_SEO_Retention_Evidence::VERSION ) { throw new RuntimeException( 'The dates or rule version differ from this frozen run. Start a new directory instead.' ); }
    if ( 'complete' === $state['status'] ) {
        if ( ! is_file( rtrim( $directory, '/' ) . '/assessment-comparison.csv' ) ) { $store->export_csv( rtrim( $directory, '/' ) . '/assessment-comparison.csv' ); }
        WP_CLI::success( 'This snapshot is already complete and remains unchanged.' );
        return;
    }
    do {
        $state = $store->state();
        $ids = array_slice( $state['ids'], $state['offset'], 200 );
        if ( ! $ids ) { break; }
        _prime_post_caches( $ids, true, true );
        $sql = $wpdb->prepare( "SELECT p.ID,p.post_title,p.post_date,pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY p.ID,pm.meta_id', AceSeoRetentionReport::META );
        $source_rows = $wpdb->get_results( $sql );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Could not read the next snapshot batch. The completed batches are resumable.' ); }
        $by_id = array();
        foreach ( $source_rows as $source ) {
            $saved = maybe_unserialize( $source->meta_value );
            if ( ! is_array( $saved ) ) { continue; }
            $by_id[(int) $source->ID] = array_merge( $saved, array( 'id' => (int) $source->ID, 'title' => $source->post_title, 'published' => substr( $source->post_date, 0, 10 ) ) );
        }
        $records = array();
        foreach ( $ids as $id ) {
            if ( ! isset( $by_id[$id] ) ) { throw new RuntimeException( 'A scoped assessment disappeared or became invalid. Preserve this run and start a new snapshot after reviewing the source change.' ); }
            $row = $by_id[$id];
            $context = Ace_SEO_Retention_Evidence_View::context( $id, $row, $period, $as_of );
            $records[] = array( 'id' => $id, 'captured_at' => gmdate( 'c' ), 'baseline' => $row, 'context' => $context, 'assessment' => Ace_SEO_Retention_Evidence::assess( Ace_SEO_Retention_Evidence_View::prepare( $row, $context ), $context, $state['provenance']['settings'] ) );
        }
        $store->append( $records );
        WP_CLI::log( 'Saved ' . $store->state()['offset'] . ' of ' . count( $state['ids'] ) . ' scoped posts.' );
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) { wp_cache_flush_runtime(); }
    } while ( $ids );
    $manifest = $store->finish();
    $store->export_csv( rtrim( $directory, '/' ) . '/assessment-comparison.csv' );
    WP_CLI::success( $manifest['records'] . ' baseline and preview records saved with a verified manifest and CSV. Existing assessments and Sheets are unchanged.' );
} catch ( Throwable $error ) {
    WP_CLI::error( $error->getMessage() );
}
