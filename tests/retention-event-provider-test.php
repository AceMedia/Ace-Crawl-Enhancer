<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
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
echo "$checks event provider checks passed.\n";
