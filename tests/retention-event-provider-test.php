<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function taxonomy_exists( $name ) { return 'category' === $name || ( 'ace_event' === $name && ! empty( $GLOBALS['events_enabled'] ) ); }
function get_the_terms( $id, $taxonomy ) { if ( 'ace_event' === $taxonomy ) { return array( (object) array( 'term_id' => 7, 'name' => 'Example event', 'slug' => 'example-event' ) ); } return $GLOBALS['terms'][ $taxonomy ] ?? false; }
function get_term_meta( $id, $key, $single ) { return $GLOBALS['event_meta'][$key] ?? ''; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['post_meta'][$key] ?? ''; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $s ) ); }
function sanitize_title( $s ) { return sanitize_key( str_replace( ' ', '-', $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( $s ) ); }
function esc_url_raw( $s ) { return $s; }
function sanitize_html_class( $s ) { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $s ); }
function get_post_types( $a = array(), $o = 'names' ) { return array( 'post' ); }
function __( $s, $d = null ) { return $s; }
function current_time( $t, $g = false ) { return '2026-10-07 14:00:00'; }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function wp_date( $format, $timestamp, $timezone ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format ); }
function apply_filters( $name, $value, ...$unused ) { return $value; }
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-actions.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-retention-evidence-view.php';
$checks = 0;
function provider_check( $ok, $why ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $why ); } }
$GLOBALS['events_enabled'] = true;
$GLOBALS['event_meta'] = array( 'ace_event_start_at' => strtotime( '2026-07-01 23:30:00 UTC' ), 'ace_event_end_at' => strtotime( '2026-07-03 12:00:00 UTC' ) );
$row = array( 'published' => '2020-07-01' );
$period = array( 'start' => '2026-05-01', 'end' => '2026-09-01' );
$context = Ace_SEO_Retention_Evidence_View::context( 9, $row, $period, '2026-10-07' );
provider_check( '2026-07-02' === $context['events'][0]['start'], 'Event timestamps use the site timezone, including summer time.' );
provider_check( false === $context['events'][0]['verified'] && '' === $context['events'][0]['occurrence_id'], 'Current mutable term dates cannot verify an older article edition.' );
provider_check( 'Publication anniversary estimate' === Ace_SEO_Retention_Evidence::relevance( $context )['source'], 'An unverified linked event cannot silently supersede the labelled fallback.' );
$GLOBALS['event_meta'] = array( 'ace_event_next_event_date' => '2026-07-02', 'ace_event_end_date' => '2026-07-03' );
$context = Ace_SEO_Retention_Evidence_View::context( 9, $row, $period, '2026-10-07' );
provider_check( '2026-07-02' === $context['events'][0]['start'] && '2026-07-03' === $context['events'][0]['end'], 'Legacy calendar dates are surfaced without inventing an occurrence.' );
$GLOBALS['event_meta']['ace_event_next_event_date'] = '2026-02-30';
$context = Ace_SEO_Retention_Evidence_View::context( 9, $row, $period, '2026-10-07' );
provider_check( '' === $context['events'][0]['start'], 'Invalid legacy dates remain unknown.' );
$GLOBALS['events_enabled'] = false;
$context = Ace_SEO_Retention_Evidence_View::context( 9, $row, $period, '2026-10-07' );
provider_check( array() === $context['events'], 'Generic sites do not require the events taxonomy or plugin.' );
$GLOBALS['post_meta'] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-07-01', '_ace_seo_relevant_to' => '2026-07-03' );
$context = Ace_SEO_Retention_Evidence_View::context( 9, $row, $period, '2026-10-07' );
provider_check( 'Editorial override' === Ace_SEO_Retention_Evidence::relevance( $context )['source'], 'An editor\'s dates on the post outrank every estimate.' );

/* Spreadsheet timing columns: facts for the person deciding, never a verdict. */
$GLOBALS['events_enabled'] = true; $GLOBALS['post_meta'] = array();
$GLOBALS['event_meta'] = array( 'ace_event_start_at' => strtotime( '2026-03-10 12:00:00 UTC' ), 'ace_event_end_at' => strtotime( '2026-03-13 12:00:00 UTC' ) );
$saved = array( 'published' => '2019-03-10', 'built' => strtotime( '2026-10-01 15:00:00 UTC' ), 'window' => 365 );
$cols = Ace_SEO_Retention_Evidence_View::timing_columns( 9, $saved, '2026-10-07' );
provider_check( 5 === count( $cols ), 'Five timing columns.' );
provider_check( 'Automatic (anniversary estimate)' === $cols[0], 'Automatic timing is named as an estimate.' );
provider_check( false !== strpos( $cols[2], 'Publication anniversary estimate' ) && false !== strpos( $cols[2], 'unverified' ), 'The basis says the anniversary is unverified.' );
provider_check( 'Example event (2026-03-10 to 2026-03-13, edition unverified)' === $cols[3], 'Linked events are listed with their dates and marked unverified.' );
provider_check( 0 === strpos( $cols[4], 'Yes (assessed 2025-10-01 to 2026-10-01' ) && false !== strpos( $cols[4], 'estimated' ), 'A 365-day window covers the anniversary season, flagged as estimated. Got: ' . json_encode( $cols ) );
$GLOBALS['post_meta'] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-03-10', '_ace_seo_relevant_to' => '2026-03-13' );
$short = array_merge( $saved, array( 'window' => 90 ) ); // 2026-07-03 to 2026-10-01: after the event
$GLOBALS['post_meta'] = array();
$cols = Ace_SEO_Retention_Evidence_View::timing_columns( 9, $short, '2026-10-07' );
provider_check( 0 === strpos( $cols[4], 'No (assessed 2026-07-03 to 2026-10-01' ), 'A 90-day summer window misses a spring anniversary season in every year.' );
provider_check( '2027-01-10 to 2027-05-10' === $cols[1], 'The relevant window shown for an estimate is the next season.' );
$GLOBALS['post_meta'] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-03-10', '_ace_seo_relevant_to' => '2026-03-13' );
$cols = Ace_SEO_Retention_Evidence_View::timing_columns( 9, $short, '2026-10-07' );
provider_check( 'Set dates (editor)' === $cols[0] && '2026-03-10 to 2026-03-13' === $cols[1] && 'Editorial override' === $cols[2], 'Editor dates are reported as the relevant window and basis.' );
provider_check( 'No (assessed 2026-07-03 to 2026-10-01)' === $cols[4], 'A 90-day window after the event did not assess it in season, and the dates are not called estimated.' );
$GLOBALS['post_meta'] = array( '_ace_seo_retention_timing' => 'evergreen' );
$cols = Ace_SEO_Retention_Evidence_View::timing_columns( 9, $short, '2026-10-07' );
provider_check( 'Evergreen (editor)' === $cols[0] && 'Any period' === $cols[1] && 'Not seasonal' === $cols[4], 'Evergreen articles are not judged by season.' );
$GLOBALS['post_meta'] = array( '_ace_seo_retention_timing' => 'dates', '_ace_seo_relevant_from' => '2026-03-10' );
$cols = Ace_SEO_Retention_Evidence_View::timing_columns( 9, $short, '2026-10-07' );
provider_check( false !== strpos( $cols[0], 'incomplete, ignored' ), 'Incomplete editor dates are flagged in the sheet rather than silently dropped.' );
$cols = Ace_SEO_Retention_Evidence_View::timing_columns( 9, array( 'published' => '2019-03-10' ), '2026-10-07' );
provider_check( 'No saved assessment' === $cols[4], 'A post without a saved assessment says so.' );

/* Timing rules by category classify an archive in bulk; the post's own setting still wins. */
$GLOBALS['options']['ace_seo_retention_options'] = array( 'timing_rules' => array( 'category:horse-racing-tips' => array( 'type' => 'event', 'days' => 3 ), 'category:guides' => array( 'type' => 'evergreen' ), 'category:cheltenham' => array( 'type' => 'season', 'start' => '03-01', 'end' => '03-20' ) ) );
$GLOBALS['terms'] = array( 'category' => array( (object) array( 'term_id' => 3, 'slug' => 'horse-racing-tips', 'name' => 'Horse Racing Tips' ) ) );
$GLOBALS['events_enabled'] = false; $GLOBALS['post_meta'] = array();
$context = Ace_SEO_Retention_Evidence_View::context( 9, array( 'published' => '2021-03-12' ), $period, '2026-10-07' );
$rel = Ace_SEO_Retention_Evidence::relevance( $context );
provider_check( '2021-03-12' === $rel['start'] && '2021-03-14' === $rel['end'] && ! empty( $rel['verified'] ) && false !== strpos( $rel['source'], 'event-bound for 3 days' ), 'An event rule binds the article to its publication date for N days.' );
$GLOBALS['terms'] = array( 'category' => array( (object) array( 'term_id' => 4, 'slug' => 'guides', 'name' => 'Guides' ) ) );
$context = Ace_SEO_Retention_Evidence_View::context( 9, array( 'published' => '2021-03-12' ), $period, '2026-10-07' );
provider_check( 'evergreen' === ( $context['content_type'] ?? '' ), 'An evergreen rule marks the article evergreen.' );
$GLOBALS['terms'] = array( 'category' => array( (object) array( 'term_id' => 5, 'slug' => 'cheltenham', 'name' => 'Cheltenham' ) ) );
$context = Ace_SEO_Retention_Evidence_View::context( 9, array( 'published' => '2021-03-12' ), $period, '2026-10-07' );
$rel = Ace_SEO_Retention_Evidence::relevance( $context );
provider_check( ! empty( $rel['recurring'] ) && '2027-03-01' === $rel['start'], 'A season rule gives verified recurring dates.' );
$GLOBALS['post_meta'] = array( '_ace_seo_retention_timing' => 'evergreen' );
$context = Ace_SEO_Retention_Evidence_View::context( 9, array( 'published' => '2021-03-12' ), $period, '2026-10-07' );
provider_check( 'evergreen' === ( $context['content_type'] ?? '' ) && ! isset( $context['season'] ), 'The post\'s own setting wins over a category rule.' );
$GLOBALS['post_meta'] = array();
$GLOBALS['terms'] = array( 'category' => array( (object) array( 'term_id' => 3, 'slug' => 'horse-racing-tips', 'name' => 'Horse Racing Tips', 'count' => 15000 ), (object) array( 'term_id' => 5, 'slug' => 'cheltenham', 'name' => 'Cheltenham', 'count' => 1200 ) ) );
$rule = AceSeoRetentionActions::timing_rule_for( 9 );
provider_check( 'category:cheltenham' === $rule['key'] && 'season' === $rule['type'], 'When several rules match, the most specific term (fewest posts) wins.' );
$GLOBALS['terms'] = array(); $GLOBALS['post_meta'] = array();
echo "$checks event provider checks passed.\n";
