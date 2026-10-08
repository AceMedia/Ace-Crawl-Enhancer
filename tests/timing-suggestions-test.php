<?php
/** Generic timing suggestions from synthetic assessment rows. Run: php tests/timing-suggestions-test.php */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $s ) ); }
function sanitize_title( $s ) { return sanitize_key( str_replace( ' ', '-', $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function taxonomy_exists( $t ) { return true; }
function post_type_exists( $t ) { return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; }
function get_post_types( $a = array(), $o = 'names' ) { return array( 'post' ); }
function __( $s, $d = null ) { return $s; }
function apply_filters( $h, $v ) { return $v; }
function current_time( $t, $g = false ) { return '2026-10-07 14:00:00'; }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
require dirname( __DIR__ ) . '/includes/class-ace-seo-retention-actions.php';
require dirname( __DIR__ ) . '/includes/class-ace-seo-timing-suggestions.php';
$checks = 0; $failures = 0;
$check = static function ( $label, $ok ) use ( &$checks, &$failures ) { $checks++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $failures++; } };
mt_srand( 7 );
$rows = array();
// Seasonal: 240 posts, 4 years, 8–20 March, few read later.
for ( $y = 2019; $y <= 2022; $y++ ) { for ( $i = 0; $i < 60; $i++ ) { $rows[] = array( 'key' => 'category:festival', 'label' => 'Festival', 'date' => sprintf( '%d-03-%02d', $y, 8 + ( $i % 13 ) ), 'rank' => $i % 50 ? '' : 'monthly', 'tier' => $i % 3 ? 'retained' : 'dormant' ); } }
// Evergreen: 120 posts spread over the year, 30% monthly+.
for ( $i = 0; $i < 120; $i++ ) { $rows[] = array( 'key' => 'category:guides', 'label' => 'Guides', 'date' => gmdate( 'Y-m-d', strtotime( '2019-01-01 UTC' ) + ( $i * 11 ) * DAY_IN_SECONDS ), 'rank' => $i % 10 < 3 ? 'monthly' : 'occasional', 'tier' => 'retained' ); }
// Event-bound: 1,500 posts, two a day, 0.4% monthly+.
for ( $i = 0; $i < 1500; $i++ ) { $rows[] = array( 'key' => 'category:tips', 'label' => 'Tips', 'date' => gmdate( 'Y-m-d', strtotime( '2019-01-01 UTC' ) + intdiv( $i, 2 ) * DAY_IN_SECONDS ), 'rank' => $i % 250 ? ( $i % 3 ? 'occasional' : '' ) : 'monthly', 'tier' => $i % 3 ? 'retained' : 'dormant' ); }
// Too few.
for ( $i = 0; $i < 12; $i++ ) { $rows[] = array( 'key' => 'post_tag:rare', 'label' => 'Rare', 'date' => '2020-05-0' . ( 1 + $i % 9 ), 'rank' => 'monthly', 'tier' => 'retained' ); }
// Middling: spread, 8% persistence — nothing stands out.
for ( $i = 0; $i < 200; $i++ ) { $rows[] = array( 'key' => 'category:news', 'label' => 'News', 'date' => gmdate( 'Y-m-d', strtotime( '2019-01-01 UTC' ) + ( $i * 6 ) * DAY_IN_SECONDS ), 'rank' => $i % 100 < 8 ? 'monthly' : 'occasional', 'tier' => 'retained' ); }
for ( $i = 0; $i < 5; $i++ ) { $rows[] = array( 'key' => 'category:tips', 'label' => 'Tips', 'date' => '0000-00-00 00:00:00', 'rank' => '', 'tier' => 'dormant' ); }
$s = Ace_SEO_Timing_Suggestions::infer( $rows );
$check( 'zero dates do not stretch the cadence', isset( $s['category:tips'] ) && $s['category:tips']['cadence_days'] < 1 );
$check( 'the seasonal term gets a season rule covering March', isset( $s['category:festival'] ) && 'season' === $s['category:festival']['type'] && '03-01' <= $s['category:festival']['rule'] && preg_match( '/^season 0[23]-\d\d 03-\d\d$/', $s['category:festival']['rule'] ) );
$check( 'the season explanation names the months and years', false !== strpos( $s['category:festival']['why'], 'March' ) && false !== strpos( $s['category:festival']['why'], '4 years' ) );
$check( 'the reference term is suggested evergreen', isset( $s['category:guides'] ) && 'evergreen' === $s['category:guides']['rule'] );
$check( 'the day-of-event term gets a short event rule from its cadence', isset( $s['category:tips'] ) && preg_match( '/^event [2-3]$/', $s['category:tips']['rule'] ) );
for ( $i = 0; $i < 60; $i++ ) { $rows[] = array( 'key' => 'post_tag:venue', 'label' => 'Venue', 'date' => gmdate( 'Y-m-d', strtotime( '2016-01-01 UTC' ) + ( $i * 40 ) * DAY_IN_SECONDS ), 'rank' => '', 'tier' => 'dormant' ); }
for ( $i = 0; $i < 3000; $i++ ) { $rows[] = array( 'key' => 'category:daily-tips', 'label' => 'Daily tips', 'date' => gmdate( 'Y-m-d', strtotime( '2016-01-01 UTC' ) + intdiv( $i, 3 ) * DAY_IN_SECONDS ), 'rank' => $i % 20 ? 'occasional' : 'monthly', 'tier' => 'retained' ); }
$s = Ace_SEO_Timing_Suggestions::infer( $rows );
$check( 'a sparse tag with no readers is not called event-bound', ! isset( $s['post_tag:venue'] ) );
$check( 'a very frequent category with 5% persistence is event-bound', isset( $s['category:daily-tips'] ) && 'event' === $s['category:daily-tips']['type'] && false !== strpos( $s['category:daily-tips']['why'], 'more than once a day' ) );
$check( 'suggestions are ordered by how many posts they settle', array_keys( $s )[0] === 'category:daily-tips' );
$check( 'too few posts: no suggestion', ! isset( $s['post_tag:rare'] ) );
$check( 'nothing stands out and under 500 posts: no row', ! isset( $s['category:news'] ) );
for ( $i = 0; $i < 600; $i++ ) { $rows[] = array( 'key' => 'category:big-mixed', 'label' => 'Big mixed', 'date' => gmdate( 'Y-m-d', strtotime( '2016-01-01 UTC' ) + ( $i * 4 ) * DAY_IN_SECONDS ), 'rank' => $i % 100 < 11 ? 'monthly' : 'occasional', 'tier' => 'retained' ); }
$s2 = Ace_SEO_Timing_Suggestions::infer( $rows );
$check( 'a big term with no clear shape says so and suggests no rule', isset( $s2['category:big-mixed'] ) && 'mixed' === $s2['category:big-mixed']['type'] && '' === $s2['category:big-mixed']['rule'] && false !== strpos( $s2['category:big-mixed']['why'], 'No clear shape' ) );
$check( 'biggest term first', array_keys( $s )[0] === 'category:daily-tips' );
$check( 'every suggestion carries plain-English evidence and a confidence', count( array_filter( $s, static function ( $x ) { return ! empty( $x['why'] ) && $x['confidence'] > 0 && $x['confidence'] <= 1; } ) ) === count( $s ) );
$band = Ace_SEO_Timing_Suggestions::season_band( array_merge( array_fill( 0, 30, '2021-12-20' ), array_fill( 0, 30, '2022-01-04' ), array( '2021-06-01', '2021-08-01' ) ) );
$check( 'a season across New Year is found as one band', $band && '12-' === substr( $band['start'], 0, 3 ) && '01-' === substr( $band['end'], 0, 3 ) );
$check( 'cadence: two posts a day is about half a day', abs( 0.5 - Ace_SEO_Timing_Suggestions::median_gap_days( array_map( static function ( $i ) { return gmdate( 'Y-m-d', strtotime( '2020-01-01 UTC' ) + intdiv( $i, 2 ) * DAY_IN_SECONDS ); }, range( 0, 39 ) ) ) ) < 0.05 );
/* Accepting and ignoring suggestions through the report-settings save. */
$GLOBALS['opt'] = array();
AceSeoRetentionActions::save_report_settings( array( 'timing_rules' => "category:guides = evergreen", 'timing_suggestions_present' => 1, 'timing_suggested' => array( 'category:tips' => 'event 2', 'category:guides' => 'evergreen', 'category:festival' => 'season 03-01 03-20' ), 'timing_accept' => array( 'category:tips' => 1, 'category:guides' => 1 ), 'timing_ignore' => array( 'category:festival' => 1 ) ) );
$o = AceSeoRetentionActions::options();
$check( 'accepting adds the suggested rule', array( 'type' => 'event', 'days' => 2 ) === $o['timing_rules']['category:tips'] );
$check( 'a hand-written rule is kept as written', array( 'type' => 'evergreen' ) === $o['timing_rules']['category:guides'] );
$check( 'ignored keys are remembered', array( 'category:festival' ) === $o['timing_ignored'] );
AceSeoRetentionActions::save_report_settings( array( 'timing_rules' => AceSeoRetentionActions::timing_rules_text( $o['timing_rules'] ), 'timing_suggestions_present' => 1, 'timing_suggested' => array( 'category:tips' => 'event 2' ), 'timing_accept' => array(), 'timing_ignore' => array() ) );
$o = AceSeoRetentionActions::options();
$check( 'unticking a rule that was in use as suggested removes it', ! isset( $o['timing_rules']['category:tips'] ) && isset( $o['timing_rules']['category:guides'] ) );
$check( 'unticking ignore forgets it', array() === $o['timing_ignored'] );
AceSeoRetentionActions::save_report_settings( array( 'timing_rules' => "category:tips = event 7", 'timing_suggestions_present' => 1, 'timing_suggested' => array( 'category:tips' => 'event 2' ), 'timing_accept' => array( 'category:tips' => 1 ) ) );
$check( 'a hand-written rule for a suggested term wins over the suggestion', array( 'type' => 'event', 'days' => 7 ) === AceSeoRetentionActions::options()['timing_rules']['category:tips'] );
AceSeoRetentionActions::save_report_settings( array( 'timing_rules' => "category:tips = event 7" ) );
$check( 'a save without the suggestions table leaves ignored keys alone', array() === AceSeoRetentionActions::options()['timing_ignored'] && isset( AceSeoRetentionActions::options()['timing_rules']['category:tips'] ) );
/* No readership source: only seasons are suggested. */
$blind = array();
foreach ( $rows as $r ) { $r['rank'] = ''; $r['tier'] = 'dormant'; $blind[] = $r; }
$sb = Ace_SEO_Timing_Suggestions::infer( $blind );
$check( 'without readership data, no event-bound or evergreen guesses', ! array_filter( $sb, static function ( $x ) { return in_array( $x['type'], array( 'event', 'evergreen' ), true ); } ) );
$check( 'without readership data, seasons are still found from dates alone', isset( $sb['category:festival'] ) && 'season' === $sb['category:festival']['type'] );
/* The modal's explicit choices. */
$GLOBALS['opt'] = array();
$r = AceSeoRetentionActions::set_timing_rules( array( 'category:tips' => 'event 2', 'category:guides' => 'evergreen', 'category:festival' => 'season 02-24 03-25', 'category:news' => '', 'post_tag:bad' => 'season 13-99 01-01' ), array( 'category:news' ) );
$o = $r['options'];
$check( 'explicit rules of each type are saved', array( 'type' => 'event', 'days' => 2 ) === $o['timing_rules']['category:tips'] && array( 'type' => 'evergreen' ) === $o['timing_rules']['category:guides'] && 'season' === $o['timing_rules']['category:festival']['type'] );
$check( 'an invalid rule is reported and not saved', array( 'post_tag:bad' ) === $r['invalid'] && ! isset( $o['timing_rules']['post_tag:bad'] ) );
$check( 'an empty choice removes the rule and the ignore list is kept', ! isset( $o['timing_rules']['category:news'] ) && array( 'category:news' ) === $o['timing_ignored'] );
$r = AceSeoRetentionActions::set_timing_rules( array( 'category:tips' => '' ), array() );
$check( 'terms not mentioned keep their rules', ! isset( $r['options']['timing_rules']['category:tips'] ) && isset( $r['options']['timing_rules']['category:guides'] ) );
$check( 'rules explain themselves in plain words', false !== strpos( Ace_SEO_Timing_Suggestions::explain_rule( 'event 2' ), '2 days from the day it was published' ) && false !== strpos( Ace_SEO_Timing_Suggestions::explain_rule( 'season 02-24 03-25' ), '24 February to 25 March' ) && false !== strpos( Ace_SEO_Timing_Suggestions::explain_rule( 'evergreen' ), 'Always relevant' ) && false !== strpos( Ace_SEO_Timing_Suggestions::explain_rule( '' ), 'No rule' ) );
echo "\n", $checks - $failures, ' of ', $checks, " timing suggestion checks passed.\n";
exit( $failures ? 1 : 0 );
