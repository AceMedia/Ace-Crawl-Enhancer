<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-retention-evidence.php';
$n = 0;
function evidence_check( $condition, $message ) { global $n; ++$n; if ( ! $condition ) { throw new RuntimeException( $message ); } }
$period = array( 'start' => '2026-01-01', 'end' => '2026-04-30' );
$context = array( 'period' => $period, 'metric_period' => $period, 'as_of' => '2026-05-01', 'coverage' => array_merge( $period, array( 'complete' => true ) ), 'content_type' => 'evergreen' );
$row = array( 'views' => 0, 'clicks' => 0, 'impressions' => 0, 'links_in' => 2, 'words' => 80 );
$s = array( 'retained_views' => 1, 'thin_words' => 300 );
$a = Ace_SEO_Retention_Evidence::assess( $row, $context, $s );
evidence_check( $a['ready'] && 'candidate' === $a['tier'] && in_array( 'noindex', $a['suggestions'], true ), 'Complete, finished evergreen evidence is eligible for editorial visibility review.' );
foreach ( array( 'missing', 'capped', 'partial', 'late-start', 'early-end', 'wrong-period', 'unfinished', 'unknown-views' ) as $case ) {
    $c = $context; $r = $row;
    if ( 'missing' === $case ) { unset( $c['coverage'] ); }
    if ( 'capped' === $case ) { $c['coverage']['capped'] = true; }
    if ( 'partial' === $case ) { $c['coverage']['complete'] = false; }
    if ( 'late-start' === $case ) { $c['coverage']['start'] = '2026-02-01'; }
    if ( 'early-end' === $case ) { $c['coverage']['end'] = '2026-04-29'; }
    if ( 'wrong-period' === $case ) { $c['metric_period']['start'] = '2026-01-02'; }
    if ( 'unfinished' === $case ) { $c['as_of'] = '2026-04-30'; }
    if ( 'unknown-views' === $case ) { $r['views'] = null; }
    $a = Ace_SEO_Retention_Evidence::assess( $r, $c, $s );
    evidence_check( ! $a['ready'] && 'unknown' === $a['tier'] && array( 'hold' ) === $a['suggestions'], $case . ' must not produce an adverse judgement.' );
}
$c = $context; unset( $c['content_type'] ); $c['anniversary'] = $period;
$a = Ace_SEO_Retention_Evidence::assess( $row, $c, $s );
evidence_check( ! $a['ready'], 'Anniversary heuristic is not verified seasonality.' );
$event = array_merge( $period, array( 'verified' => true, 'occurrence_id' => 'event-2026' ) );
$c['events'] = array( $event );
evidence_check( Ace_SEO_Retention_Evidence::assess( $row, $c, $s )['ready'], 'Verified complete occurrence can be assessed.' );
$c['events'][] = array_merge( $event, array( 'occurrence_id' => 'other-2026' ) );
evidence_check( ! Ace_SEO_Retention_Evidence::assess( $row, $c, $s )['ready'], 'Multiple occurrences need explicit editorial resolution.' );
$c['override'] = $period;
evidence_check( Ace_SEO_Retention_Evidence::assess( $row, $c, $s )['ready'], 'Editorial override has precedence.' );
unset( $c['override'] ); $c['events'] = array( array_merge( $event, array( 'occurrence_id' => '' ) ) );
evidence_check( ! Ace_SEO_Retention_Evidence::assess( $row, $c, $s )['ready'], 'A date without an occurrence identity is not verified.' );
$r = array_merge( $row, array( 'views' => 4, 'impressions' => 1000, 'clicks' => 1, 'position' => 4 ) );
$a = Ace_SEO_Retention_Evidence::assess( $r, $context, $s );
evidence_check( 'retained' === $a['tier'] && array( 'refresh', 'keep' ) === $a['suggestions'], 'Retained articles can need a refresh and have supporting keep evidence.' );
$c = $context; unset( $c['coverage'] );
$held = Ace_SEO_Retention_Evidence::assess( $r, $c, $s );
evidence_check( 'retained' === $held['tier'] && in_array( 'hold', $held['suggestions'], true ), 'Positive evidence survives incomplete coverage; adverse judgement waits.' );
$c['metric_period'] = array();
evidence_check( 'unknown' === Ace_SEO_Retention_Evidence::assess( $r, $c, $s )['tier'], 'Do not relabel totals from unknown dates as selected-period traffic.' );
evidence_check( 'useful' === Ace_SEO_Retention_Evidence::reference_cell( 'retained', 'keep' )['status'], 'Keeping a useful retained article needs no automatic change.' );
evidence_check( 'quiet' === Ace_SEO_Retention_Evidence::reference_cell( 'unknown', 'noindex' )['status'], 'Unknown evidence does not justify a visibility change.' );
evidence_check( 'uncertain' === Ace_SEO_Retention_Evidence::reference_cell( 'unknown', 'hold' )['status'], 'Holding requires evidence checks or a future review, not abandonment.' );
evidence_check( ! Ace_SEO_Retention_Evidence::covered( array_merge( $period, array( 'complete' => 'yes' ) ), $period ), 'Coverage must be explicitly attested, not loosely truthy.' );
evidence_check( ! Ace_SEO_Retention_Evidence::date( '2026-02-30' ), 'Invalid dates fail closed.' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( $n ); } }
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-retention-report.php';
evidence_check( 'unknown' === AceSeoRetentionReport::tier( array( 'views' => null, 'words' => 5 ), $s ), 'Legacy scorer must not label missing visits as an unread candidate.' );
evidence_check( 'retained' === AceSeoRetentionReport::tier( array( 'views' => null, 'clicks' => 1 ), $s ), 'Positive clicks can establish readership without a visitor source.' );
$legacy_settings = array_merge( $s, array( 'demand_impressions' => 100, 'refresh_max_ctr' => .02, 'refresh_max_pos' => 20 ) );
$missing = array_merge( $row, array( 'views' => null, 'position' => 0 ) );
evidence_check( 'no-signal' === AceSeoRetentionReport::bucket( $missing, $legacy_settings )[0], 'Legacy scorer must not suggest noindex from absent visitor evidence.' );
$assessed = array( 'start' => '2025-10-01', 'end' => '2026-09-30' );
$hold = 'Ace_SEO_Retention_Evidence::timing_hold';
evidence_check( '' === $hold( array( 'source' => 'Publication anniversary estimate', 'verified' => false, 'start' => '2027-01-10', 'end' => '2027-05-10' ), $assessed ), 'A year-long period always contains one season of a recurring anniversary estimate.' );
evidence_check( '' !== $hold( array( 'source' => 'Publication anniversary estimate', 'verified' => false, 'start' => '2027-01-10', 'end' => '2027-05-10' ), array( 'start' => '2026-07-01', 'end' => '2026-09-30' ) ), 'A summer-only period misses a spring season in every year.' );
evidence_check( false !== strpos( $hold( array( 'source' => 'Editorial override', 'verified' => true, 'start' => '2027-03-10', 'end' => '2027-03-13' ), $assessed ), 'after that period has passed' ), 'Editor dates in the future hold the judgement until afterwards.' );
evidence_check( '' === $hold( array( 'source' => 'Verified event occurrence', 'verified' => true, 'start' => '2024-03-10', 'end' => '2024-03-13' ), $assessed ), 'A one-off event that is over is judged on its readership since.' );
evidence_check( false !== strpos( $hold( array( 'source' => 'Verified event occurrence', 'verified' => true, 'start' => '2026-03-10', 'end' => '2026-03-13' ), array( 'start' => '2026-03-12', 'end' => '2026-06-30' ) ), 'period that includes those dates' ), 'A period that starts mid-event does not judge it.' );
evidence_check( '' === $hold( array( 'source' => 'Verified event occurrence', 'verified' => true, 'start' => '2026-03-10', 'end' => '2026-03-13' ), $assessed ), 'A verified event inside the period can be judged.' );
evidence_check( '' === $hold( array( 'source' => 'Evergreen: chosen observation period', 'verified' => true, 'evergreen' => true ), $assessed ), 'Evergreen articles are never held for timing.' );
evidence_check( '' === $hold( array( 'source' => 'Relevant dates not established', 'verified' => false ), $assessed ), 'Unknown relevance does not hold: the period stands on its own.' );
evidence_check( false !== strpos( $hold( array( 'source' => 'Several event occurrences: choose the relevant one', 'verified' => false, 'ambiguous' => true ), $assessed ), 'confirm which' ), 'Ambiguous linked events hold until an editor confirms the edition.' );
evidence_check( false !== strpos( $hold( array( 'source' => 'Publication anniversary estimate', 'verified' => false, 'start' => '2026-01-10', 'end' => '2026-05-10' ), $assessed, true ), 'timing has not been confirmed' ), 'Strict policy holds an anniversary estimate even inside the period.' );
evidence_check( false !== strpos( $hold( array( 'source' => 'Relevant dates not established', 'verified' => false ), $assessed, true ), 'No relevant dates are known' ), 'Strict policy holds unknown timing.' );
evidence_check( '' === $hold( array( 'source' => 'Editorial override', 'verified' => true, 'start' => '2026-03-10', 'end' => '2026-03-13' ), $assessed, true ), 'Strict policy judges confirmed dates inside the period.' );
evidence_check( '' === $hold( array( 'source' => 'Evergreen: chosen observation period', 'verified' => true, 'evergreen' => true ), $assessed, true ), 'Strict policy judges evergreen articles.' );
$year = array_merge( $legacy_settings, array( 'days' => 365, 'retained_views' => 1 ) );
evidence_check( array( 'daily' => 365, 'weekly' => 53, 'monthly' => 13, 'occasional' => 1 ) === AceSeoRetentionReport::rank_cutoffs( $year ), 'Band cutoffs follow the window: a view a day, a week, a month, or at least one.' );
evidence_check( array( 'daily' => 90, 'weekly' => 13, 'monthly' => 3, 'occasional' => 12 ) === AceSeoRetentionReport::rank_cutoffs( array( 'days' => 90, 'retained_views' => 12 ) ), 'A 90-day window scales the bands; the lowest band is the retained floor.' );
evidence_check( 'daily' === AceSeoRetentionReport::rank( array( 'views' => 400 ), $year ) && 'weekly' === AceSeoRetentionReport::rank( array( 'views' => 60 ), $year ) && 'monthly' === AceSeoRetentionReport::rank( array( 'views' => 13 ), $year ) && 'occasional' === AceSeoRetentionReport::rank( array( 'views' => 1 ), $year ), 'Views place a retained post in the right band.' );
evidence_check( 'occasional' === AceSeoRetentionReport::rank( array( 'views' => null, 'clicks' => 2 ), $year ), 'Search clicks alone keep a post retained in the lowest band.' );
evidence_check( '' === AceSeoRetentionReport::rank( array( 'views' => 0, 'words' => 800 ), $year ) && 'dormant' === AceSeoRetentionReport::tier( array( 'views' => 0, 'words' => 800 ), $year ), 'Zero views and no clicks is Dormant with no band.' );
evidence_check( '' === AceSeoRetentionReport::rank( array( 'views' => null ), $year ), 'Unknown views have no band.' );
evidence_check( 'retained' === AceSeoRetentionReport::tier( array( 'views' => 1 ), $year ), 'With the floor at one view, any recorded reader is retained.' );
evidence_check( '' === $hold( array( 'source' => 'Category rule: Horse Racing Tips, event-bound for 3 days from publication', 'verified' => true, 'start' => '2021-03-12', 'end' => '2021-03-14' ), $assessed, true ), 'A one-off event that is over is judged on its readership since, even under strict.' );
evidence_check( '' !== $hold( array( 'source' => 'Category rule: Cheltenham, in season 03-01 to 03-20 each year', 'verified' => true, 'recurring' => true, 'start' => '2027-03-01', 'end' => '2027-03-20' ), array( 'start' => '2026-06-01', 'end' => '2026-09-30' ), true ), 'A recurring season outside a summer-only period holds.' );
evidence_check( '' === $hold( array( 'source' => 'Category rule: Cheltenham, in season 03-01 to 03-20 each year', 'verified' => true, 'recurring' => true, 'start' => '2027-03-01', 'end' => '2027-03-20' ), $assessed, true ), 'A recurring season inside a year-long period is judged.' );
$rule_ctx = array( 'period' => $assessed, 'as_of' => '2026-10-07', 'season' => array( 'start' => '03-01', 'end' => '03-20', 'source' => 'Category rule: season' ) );
$rel = Ace_SEO_Retention_Evidence::relevance( $rule_ctx );
evidence_check( '2027-03-01' === $rel['start'] && '2027-03-20' === $rel['end'] && ! empty( $rel['verified'] ) && ! empty( $rel['recurring'] ), 'A season rule resolves to the next occurrence and is verified and recurring.' );
$rel = Ace_SEO_Retention_Evidence::relevance( array( 'as_of' => '2026-02-01', 'season' => array( 'start' => '03-01', 'end' => '03-20' ) ) );
evidence_check( '2026-03-01' === $rel['start'], 'Before the season, this year is the next occurrence.' );
$rel = Ace_SEO_Retention_Evidence::relevance( array( 'as_of' => '2026-12-20', 'season' => array( 'start' => '12-15', 'end' => '01-05' ) ) );
evidence_check( '2026-12-15' === $rel['start'] && '2027-01-05' === $rel['end'], 'A season across New Year keeps its dates in order.' );
$rel = Ace_SEO_Retention_Evidence::relevance( array( 'override' => array( 'start' => '2021-03-12', 'end' => '2021-03-14', 'source' => 'Category rule: event-bound' ) ) );
evidence_check( 'Category rule: event-bound' === $rel['source'] && ! empty( $rel['verified'] ), 'An override carries its own source label.' );
$a = Ace_SEO_Retention_Evidence::assess( array( 'views' => 0, 'clicks' => 0, 'impressions' => 0, 'links_in' => 0, 'words' => 500 ), array( 'period' => $assessed, 'metric_period' => $assessed, 'as_of' => '2026-10-07', 'coverage' => array_merge( $assessed, array( 'complete' => true ) ), 'override' => array( 'start' => '2021-03-12', 'end' => '2021-03-14' ) ), $s );
evidence_check( $a['ready'] && ! empty( $a['post_event'] ) && 'dormant' === $a['tier'], 'The preview judges a finished one-off event on its readership since, flagged post-event.' );
echo "$n retention evidence checks passed.\n";
