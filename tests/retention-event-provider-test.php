<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function taxonomy_exists( $name ) { return 'ace_event' === $name && ! empty( $GLOBALS['events_enabled'] ); }
function get_the_terms( $id, $taxonomy ) { return array( (object) array( 'term_id' => 7, 'name' => 'Example event' ) ); }
function get_term_meta( $id, $key, $single ) { return $GLOBALS['event_meta'][$key] ?? ''; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['post_meta'][$key] ?? ''; }
function wp_date( $format, $timestamp, $timezone ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format ); }
function apply_filters( $name, $value, ...$unused ) { return $value; }
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
echo "$checks event provider checks passed.\n";
