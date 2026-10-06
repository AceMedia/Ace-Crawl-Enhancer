<?php
/** Isolated export meaning checks; no WordPress database or Google requests. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $name, $value ) { return $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function _prime_post_caches( $ids, $terms, $meta ) {}
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_status' => 'publish' ); }
function get_the_title( $post ) { return 'An example article'; }
function get_permalink( $post ) { return 'https://example.test/article'; }
function get_post_time( $format, $gmt, $post ) { return '2020-01-01'; }
function get_post_modified_time( $format, $gmt, $post ) { return '2020-01-02'; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta() { throw new RuntimeException( 'An export must not change a post.' ); }
class AceCrawlEnhancer { public static function get_meta_value( $id, $key ) { return ''; } }
class AceSeoRetentionReport { const META = '_ace_seo_retention'; }
class AceSeoRetentionActions {
    public static $states = array();
    public static function state( $id ) { return self::$states[ $id ] ?? array(); }
}
require dirname( __DIR__ ) . '/includes/class-ace-seo-export.php';
$checks = array();
function verify( $name, $condition ) { $GLOBALS['checks'][ $name ] = (bool) $condition; }
$base = array( 'bucket' => 'keep', 'reason' => 'Still earning search clicks (12 in the window).', 'window' => 90, 'built' => 1790868587 );
$r = AceSeoExport::retention_context( 1, $base );
verify( 'next step and reason explain the saved keep decision', $r[0] === 'Keep this article available.' && strpos( $r[1], '12 over the assessed 90-day period' ) !== false );
verify( 'assessment time uses the saved timestamp', $r[2] === gmdate( 'Y-m-d H:i:s T', $base['built'] ) );
verify( 'a recommendation is not reported as an applied action', $r[3] === 'No per-post retention changes recorded' );
$r = AceSeoExport::retention_context( 1, array() );
verify( 'missing assessments explicitly need checking rather than removal', $r[0] === 'Not assessed yet.' && strpos( $r[1], 'No saved retention assessment' ) === 0 && $r[2] === '' );
$r = AceSeoExport::retention_context( 1, array( 'bucket' => 'custom', 'reason' => '<b>Custom saved evidence</b>' ) );
verify( 'unknown recommendations retain their evidence without inventing an action', $r[0] === 'Review the saved assessment in WordPress.' && $r[1] === 'Custom saved evidence' );
$r = AceSeoExport::retention_context( 1, array( 'bucket' => 'consolidate' ) );
verify( 'combining still requires a destination and missing reasons stay explicit', strpos( $r[0], 'Choose a destination before setting a redirect' ) !== false && strpos( $r[1], 'no recorded explanation' ) !== false );
$r = AceSeoExport::retention_context( 1, array( 'bucket' => 'refresh', 'reason' => '80 impressions at position 9 but a 0% CTR: check it.' ) );
verify( 'search jargon is expanded without changing the recorded figures', $r[1] === '80 appearances in search results at position 9 but a 0% click-through rate: check it.' );
$r = AceSeoExport::retention_context( 1, array( 'bucket' => 'no-signal', 'reason' => 'No clicks (no page-view source configured) in the window.' ) );
verify( 'missing visitor coverage and seasonal uncertainty remain visible', strpos( $r[0], 'seasonal interest' ) !== false && strpos( $r[1], 'no page-view source configured' ) !== false );
AceSeoRetentionActions::$states[1] = array( 'noindex' => true, 'unavailable' => '2027-01-01', 'redirect' => 'gone', 'news_excl' => true, 'notice' => 'show' );
$r = AceSeoExport::retention_context( 1, $base );
verify( 'current applied states are independent of the keep recommendation', strpos( $r[3], 'Search exclusion set' ) !== false && strpos( $r[3], 'Search expiry date: 2027-01-01' ) !== false && strpos( $r[3], '410 response set; kept in WordPress' ) !== false && strpos( $r[3], 'Excluded from the news sitemap' ) !== false && strpos( $r[3], 'notice forced on' ) !== false );
AceSeoRetentionActions::$states[1] = array( 'redirect' => 'https://example.test/newer', 'notice' => 'hide' );
$r = AceSeoExport::retention_context( 1, $base );
verify( 'redirect destinations and hidden notice settings are retained', $r[3] === 'Redirect set to https://example.test/newer; Older-article notice forced off' );
$GLOBALS['meta'][1]['_ace_seo_retention'] = $base;
$headers = AceSeoExport::header(); $rows = AceSeoExport::rows( array( 1, 2 ) );
verify( 'existing column positions remain stable and context is appended', count( $headers ) === 28 && $headers[0] === 'ID' && $headers[23] === 'Search clicks (30 days)' && array_slice( $headers, 24 ) === array( 'Recommended next step', 'Why', 'Assessed at (site time)', 'Applied retention settings' ) );
verify( 'all rows match the header and missing measurements stay blank', count( $rows ) === 2 && count( $rows[0] ) === 28 && count( $rows[1] ) === 28 && $rows[1][8] === '' && $rows[1][24] === 'Not assessed yet.' );
$failed = array_keys( array_filter( $checks, static function ( $ok ) { return ! $ok; } ) );
echo json_encode( array( 'passed' => count( $checks ) - count( $failed ), 'total' => count( $checks ), 'failed' => $failed ), JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
