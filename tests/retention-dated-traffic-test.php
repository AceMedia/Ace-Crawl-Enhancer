<?php
/**
 * Exact-date traffic for the evidence preview: coverage, capping, source precedence and the editorial
 * timing fields, with isolated WordPress stubs. Run: php tests/retention-dated-traffic-test.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' ); define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['transients'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['filters'] = array();
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][ $k ] = $v; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value, ...$args ) { foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$args ); } return $value; }
function get_permalink( $id ) { return 'https://ordinary-wordpress.test/sport/post-' . (int) $id . '/'; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function taxonomy_exists( $name ) { return false; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } }
class AceSEOSiteKit {}
class AceSeoRetentionReport { public static function path_key( $url ) { $p = parse_url( $url, PHP_URL_PATH ); return rtrim( (string) $p, '/' ) ?: '/'; } public static function settings() { return array( 'retained_views' => 1, 'thin_words' => 300 ); } }
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-evidence.php';
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-seasonal-window.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-retention-evidence-view.php';
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-dated-traffic.php';

class DatedStub extends Ace_SEO_Retention_Dated_Traffic {
    public static $ga4 = null; public static $own = null; public static $gsc = null; public static $collects = 0;
    protected static function analytics( array $period ) { return self::$ga4; }
    protected static function own_tracking( array $period ) { return self::$own; }
    protected static function search_console( array $period ) { return self::$gsc; }
    protected static function collect( array $period ) { self::$collects++; return parent::collect( $period ); }
    public static function reset() { self::$ga4 = self::$own = self::$gsc = null; self::$collects = 0; $GLOBALS['transients'] = array(); $GLOBALS['filters'] = array(); self::forget_runtime(); self::init(); }
}

$checks = 0; $failures = 0;
$check = static function ( $label, $ok ) use ( &$checks, &$failures ) { $checks++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $failures++; } };
$period = array( 'start' => '2026-03-01', 'end' => '2026-04-30' );
$as_of  = '2026-10-07';
$row    = array( 'id' => 9, 'published' => '2019-03-10', 'views' => 120, 'clicks' => 3, 'impressions' => 40, 'position' => 8.0, 'links_in' => 0, 'words' => 90 );
$s      = AceSeoRetentionReport::settings();
$ctx    = static function ( $id, $row, $period, $as_of ) { return Ace_SEO_Retention_Evidence_View::context( $id, $row, $period, $as_of ); };

/* 1. An unfinished period fetches nothing: the rules already say it cannot be judged. */
DatedStub::reset(); DatedStub::$ga4 = array( 'rows' => array( '/sport/post-9' => 5 ), 'complete' => true, 'capped' => false, 'note' => '' );
$c = $ctx( 9, $row, array( 'start' => '2026-09-01', 'end' => '2026-10-07' ), $as_of );
$check( 'unfinished period: no metrics and no fetch', ! isset( $c['metrics'] ) && 0 === DatedStub::$collects );

/* 2. No source at all: context untouched, saved totals cannot be reused for other dates. */
DatedStub::reset();
$c = $ctx( 9, $row, $period, $as_of );
$a = Ace_SEO_Retention_Evidence::assess( Ace_SEO_Retention_Evidence_View::prepare( $row, $c ), $c, $s );
$check( 'no source: coverage empty and metrics absent', array() === $c['coverage'] && ! isset( $c['metrics'] ) );
$check( 'no source: saved totals are not reused and the verdict holds', ! $a['ready'] && 'unknown' === $a['tier'] );

/* 3. Analytics and Search Console both complete: exact figures replace the saved ones and the article can be judged. */
DatedStub::reset();
DatedStub::$ga4 = array( 'rows' => array( '/sport/post-9' => 7, '/other' => 100 ), 'complete' => true, 'capped' => false, 'note' => '' );
DatedStub::$gsc = array( 'rows' => array( '/sport/post-9' => array( 'clicks' => 2, 'impressions' => 900, 'position' => 6.5 ) ), 'complete' => true, 'capped' => false, 'note' => '' );
$GLOBALS['meta'][9] = array( '_ace_seo_retention_timing' => 'evergreen' );
$c = $ctx( 9, $row, $period, $as_of );
$prepared = Ace_SEO_Retention_Evidence_View::prepare( $row, $c );
$check( 'exact-date metrics replace the saved row figures', 7 === $prepared['views'] && 2 === $prepared['clicks'] && 900 === $prepared['impressions'] && 6.5 === $prepared['position'] );
$check( 'metric period equals the chosen period', $period === $c['metric_period'] );
$check( 'coverage is complete and names both sources', true === $c['coverage']['complete'] && false === $c['coverage']['capped'] && array( 'Google Analytics', 'Search Console' ) === $c['coverage']['sources'] );
$a = Ace_SEO_Retention_Evidence::assess( $prepared, $c, $s );
$check( 'with complete coverage the evergreen article is judged on its real dates', $a['ready'] && 'retained' === $a['tier'] );
$check( 'the saved row itself is untouched', 120 === $row['views'] );

/* 4. A page the complete sources do not list is a measured zero, not unknown. */
$quiet = array_merge( $row, array( 'id' => 11, 'views' => null, 'clicks' => 0, 'impressions' => 0 ) );
$GLOBALS['meta'][11] = array( '_ace_seo_retention_timing' => 'evergreen' );
$c = $ctx( 11, $quiet, $period, $as_of );
$p = Ace_SEO_Retention_Evidence_View::prepare( $quiet, $c );
$a = Ace_SEO_Retention_Evidence::assess( $p, $c, $s );
$check( 'absent from complete sources: views are a measured zero', 0 === $p['views'] );
$check( 'a measured zero with thin text is a review candidate, not unknown', $a['ready'] && 'candidate' === $a['tier'] );
$check( 'one fetch served both posts', 1 === DatedStub::$collects );

/* 5. A capped Analytics feed cannot claim complete coverage. */
DatedStub::reset();
DatedStub::$ga4 = array( 'rows' => array( '/sport/post-9' => 7 ), 'complete' => false, 'capped' => true, 'note' => '' );
$c = $ctx( 9, $row, $period, $as_of );
$a = Ace_SEO_Retention_Evidence::assess( Ace_SEO_Retention_Evidence_View::prepare( $row, $c ), $c, $s );
$check( 'capped Analytics: coverage incomplete and capped', false === $c['coverage']['complete'] && true === $c['coverage']['capped'] );
$check( 'capped Analytics: positive views still count, but nothing is ready', ! $a['ready'] && 'retained' === $a['tier'] );

/* 6. Without Analytics, own tracking is used, keyed by post ID, and its start date bounds coverage. */
DatedStub::reset();
DatedStub::$own = array( 'rows' => array( 9 => 4 ), 'complete' => false, 'note' => 'Own tracking only starts on 2026-03-15, after the chosen period began.' );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'own tracking supplies views by post ID', 4 === $c['metrics']['views'] && array( 'Own view tracking' ) === $c['sources'] );
$check( 'tracking that began mid-period is incomplete and says so', false === $c['coverage']['complete'] && false !== strpos( $c['coverage']['notes'][0], 'only starts on 2026-03-15' ) );
DatedStub::reset();
DatedStub::$own = array( 'rows' => array( 9 => 4 ), 'complete' => true, 'note' => '' );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'tracking that predates the period is complete', true === $c['coverage']['complete'] );

/* 7. Search Console alone: clicks arrive but views stay unknown, so the verdict still holds. */
DatedStub::reset();
DatedStub::$gsc = array( 'rows' => array( '/sport/post-9' => array( 'clicks' => 0, 'impressions' => 10, 'position' => 30 ) ), 'complete' => true, 'capped' => false, 'note' => '' );
$c = $ctx( 9, $row, $period, $as_of );
$p = Ace_SEO_Retention_Evidence_View::prepare( $row, $c );
$a = Ace_SEO_Retention_Evidence::assess( $p, $c, $s );
$check( 'search only: views unknown, coverage not complete', null === $p['views'] && false === $c['coverage']['complete'] );
$check( 'search only: no adverse judgement', ! $a['ready'] && 'unknown' === $a['tier'] );

/* 8. Search Console older than its 16 months is incomplete with a note (real method logic). */
if ( ! class_exists( 'GscOld' ) ) { // declared here, not hoisted, so the earlier checks see no Search Console
class GscOld extends Ace_SEO_Retention_Dated_Traffic {
    protected static function analytics( array $period ) { return null; }
    protected static function own_tracking( array $period ) { return null; }
}
class AceSEOSearchConsole { public static function is_ready() { return true; } public static function pages_between( $s, $e ) { return array( 'rows' => array( 'https://ordinary-wordpress.test/sport/post-9/' => array( 'clicks' => 1, 'impressions' => 5, 'position' => 2 ) ), 'capped' => false ); } }
}
$GLOBALS['transients'] = array();
$old = GscOld::feed( array( 'start' => '2023-01-01', 'end' => '2023-02-28' ) );
$check( 'Search Console before its 16-month window is incomplete', false === $old['coverage']['complete'] && false !== strpos( $old['coverage']['notes'][0], '16 months' ) );
$check( 'Search Console URLs are keyed by path', isset( $old['search']['/sport/post-9'] ) );
$recent = GscOld::feed( array( 'start' => gmdate( 'Y-m-d', strtotime( '-3 months' ) ), 'end' => gmdate( 'Y-m-d', strtotime( '-2 months' ) ) ) );
$check( 'recent Search Console dates carry no note; views still missing so not complete', array() === $recent['coverage']['notes'] && false === $recent['coverage']['complete'] );

/* 9. Editorial timing fields. */
DatedStub::reset();
$GLOBALS['meta'][9] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-03-10', '_ace_seo_relevant_to' => '2026-03-13' );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'set dates become an editorial override', array( 'start' => '2026-03-10', 'end' => '2026-03-13' ) === $c['override'] );
$check( 'the override is the verified relevance', 'Editorial override' === Ace_SEO_Retention_Evidence::relevance( $c )['source'] );
$GLOBALS['meta'][9] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-03-10', '_ace_seo_relevant_to' => '' );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'incomplete dates are ignored with a note', ! isset( $c['override'] ) && false !== strpos( $c['timing_note'], 'ignored' ) );
$GLOBALS['meta'][9] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-04-10', '_ace_seo_relevant_to' => '2026-03-13' );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'reversed dates are ignored', ! isset( $c['override'] ) );
$GLOBALS['meta'][9] = array( '_ace_seo_retention_timing' => 'evergreen' );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'evergreen sets the content type', 'evergreen' === $c['content_type'] && ! isset( $c['override'] ) );
$GLOBALS['meta'][9] = array();
$c = $ctx( 9, $row, $period, $as_of );
$check( 'automatic keeps the anniversary estimate only', ! isset( $c['content_type'] ) && ! isset( $c['override'] ) && 'Publication anniversary estimate' === Ace_SEO_Retention_Evidence::relevance( $c )['source'] );

/* 10. A later provider can still replace what the built-in one supplied. */
DatedStub::reset();
DatedStub::$ga4 = array( 'rows' => array( '/sport/post-9' => 7 ), 'complete' => true, 'capped' => false, 'note' => '' );
add_filter( 'ace_seo_retention_evidence_context', static function ( $c ) { $c['coverage']['complete'] = false; $c['coverage']['notes'][] = 'Overridden by a site provider.'; return $c; }, 20, 3 );
$c = $ctx( 9, $row, $period, $as_of );
$check( 'a later provider overrides coverage', false === $c['coverage']['complete'] && 'Overridden by a site provider.' === end( $c['coverage']['notes'] ) );

echo "\n", $checks - $failures, ' of ', $checks, " dated traffic checks passed.\n";
exit( $failures ? 1 : 0 );
