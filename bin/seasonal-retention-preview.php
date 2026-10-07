<?php
/**
 * Read-only full published-post inventory preview; no traffic assessment or retention actions.
 * wp eval-file <plugin>/bin/seasonal-retention-preview.php 2026-10-07 > preview.csv
 * This deliberately does not replace the existing retention report or Google Sheet.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit;
}
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-seasonal-window.php';
$as_of = $args[0] ?? wp_date( 'Y-m-d' );
$timezone = wp_timezone();
try {
    Ace_SEO_Seasonal_Window::preview( $as_of, $as_of, $timezone );
} catch ( InvalidArgumentException $error ) {
    WP_CLI::error( $error->getMessage() );
}
global $wpdb;
$cursor = 0;
$stream = fopen( 'php://stdout', 'w' );
fputcsv( $stream, array( 'post_id', 'published', 'as_of', 'timezone', 'anniversary', 'season_start', 'season_end', 'status', 'reason', 'rule' ), ',', '"', '' );
do {
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_date FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND ID > %d ORDER BY ID LIMIT 500",
        $cursor
    ) );
    if ( $wpdb->last_error ) {
        WP_CLI::error( 'Could not read the next post batch; this export is incomplete.' );
    }
    foreach ( $rows as $row ) {
        $cursor = (int) $row->ID;
        $published = substr( $row->post_date, 0, 10 );
        try {
            $window = Ace_SEO_Seasonal_Window::preview( $published, $as_of, $timezone );
            fputcsv( $stream, array( $cursor, $published, $as_of, $timezone->getName(), $window['anniversary'], $window['season_start'], $window['season_end'], $window['in_season'] ? 'in_season' : 'hold_out_of_season', $window['reason'], $window['rule'] ), ',', '"', '' );
        } catch ( InvalidArgumentException $error ) {
            fputcsv( $stream, array( $cursor, $published, $as_of, $timezone->getName(), '', '', '', 'needs_date_review', $error->getMessage(), 'publication-anniversary-v1-preview' ), ',', '"', '' );
        }
    }
} while ( count( $rows ) === 500 );
fclose( $stream );
