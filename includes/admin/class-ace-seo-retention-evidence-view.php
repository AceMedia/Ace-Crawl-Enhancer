<?php
/** Read-only, bounded review of saved report rows. Never starts a report or writes post meta. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/class-ace-seo-retention-evidence.php';
require_once dirname( __DIR__ ) . '/class-ace-seo-seasonal-window.php';
require_once dirname( __DIR__ ) . '/class-ace-seo-retention-actions.php';

final class Ace_SEO_Retention_Evidence_View {
    const LIMIT = 100;
    /** Per-post editorial timing (Advanced tab): auto, evergreen, or explicit dates. */
    const META_TIMING = '_ace_seo_retention_timing';
    const META_FROM   = '_ace_seo_relevant_from';
    const META_TO     = '_ace_seo_relevant_to';

    /**
     * The row the rules should judge: the saved row, with its traffic replaced by the exact-period
     * figures a provider supplied in the context (never the other way round).
     */
    public static function prepare( array $row, array $context ) {
        if ( isset( $context['metrics'] ) && is_array( $context['metrics'] ) ) {
            foreach ( array( 'views', 'clicks', 'impressions', 'position' ) as $k ) {
                if ( array_key_exists( $k, $context['metrics'] ) ) {
                    $row[ $k ] = $context['metrics'][ $k ];
                }
            }
        }
        return $row;
    }

    /** Generic provider contract; third-party adapters can supply edition-bound occurrences. */
    public static function context( $id, array $row, array $period, $as_of ) {
        /** Supply coverage, explicit editorial context and verified occurrence IDs here, in batches/cache where possible. */
        return (array) apply_filters( 'ace_seo_retention_evidence_context', self::base_context( $id, $row, $period, $as_of ), (int) $id, $row );
    }

    /**
     * What the site itself knows about an article's timing — editorial fields, linked events, the
     * anniversary estimate — before any traffic provider runs. Exports read this: no API calls.
     */
    public static function base_context( $id, array $row, array $period, $as_of ) {
        $context = array( 'period' => $period, 'as_of' => $as_of, 'events' => array(), 'coverage' => array(), 'metric_period' => (array) ( $row['metric_period'] ?? array() ) );
        // Editorial timing, set per post on the Advanced tab: it takes precedence over any estimate.
        $timing = (string) get_post_meta( $id, self::META_TIMING, true );
        if ( 'evergreen' === $timing ) {
            $context['content_type'] = 'evergreen';
        } elseif ( 'dates' === $timing ) {
            $from = (string) get_post_meta( $id, self::META_FROM, true );
            $to   = (string) get_post_meta( $id, self::META_TO, true );
            if ( Ace_SEO_Retention_Evidence::date( $from ) && Ace_SEO_Retention_Evidence::date( $to ) && $from <= $to ) {
                $context['override'] = array( 'start' => $from, 'end' => $to );
            } else {
                $context['timing_note'] = 'Editorial dates are set but incomplete or invalid, so they are ignored.';
            }
        }
        // Then a timing rule for one of its categories or tags: the way to classify an archive in bulk.
        if ( ! isset( $context['content_type'], $context['override'] ) && '' === $timing && class_exists( 'AceSeoRetentionActions' ) && method_exists( 'AceSeoRetentionActions', 'timing_rule_for' ) ) {
            $rule = AceSeoRetentionActions::timing_rule_for( $id );
            if ( $rule && Ace_SEO_Retention_Evidence::date( $row['published'] ?? null ) ) {
                $context['timing_rule'] = $rule;
                if ( 'evergreen' === $rule['type'] ) {
                    $context['content_type'] = 'evergreen';
                } elseif ( 'event' === $rule['type'] ) {
                    $end = ( new DateTimeImmutable( $row['published'] . ' UTC' ) )->modify( '+' . ( (int) $rule['days'] - 1 ) . ' days' )->format( 'Y-m-d' );
                    $context['override'] = array( 'start' => $row['published'], 'end' => $end, 'source' => sprintf( 'Category rule: %s, event-bound for %d day%s from publication', $rule['label'], (int) $rule['days'], 1 === (int) $rule['days'] ? '' : 's' ) );
                } elseif ( 'season' === $rule['type'] ) {
                    $context['season'] = array( 'start' => $rule['start'], 'end' => $rule['end'], 'source' => sprintf( 'Category rule: %s, in season %s to %s each year', $rule['label'], $rule['start'], $rule['end'] ) );
                }
            }
        }
        try {
            $anniversary = Ace_SEO_Seasonal_Window::preview( $row['published'], $as_of, wp_timezone() );
            $context['anniversary'] = array( 'start' => $anniversary['season_start'], 'end' => $anniversary['season_end'] );
        } catch ( InvalidArgumentException $e ) { /* Unknown dates remain unknown. */ }
        // Ace Teams & Events currently stores mutable term dates, not an article-to-edition binding.
        // Surface that evidence for editors; do not silently promote it to a verified occurrence.
        if ( taxonomy_exists( 'ace_event' ) ) {
            $terms = get_the_terms( $id, 'ace_event' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    $start = (int) get_term_meta( $term->term_id, 'ace_event_start_at', true );
                    $end = (int) get_term_meta( $term->term_id, 'ace_event_end_at', true );
                    $legacy_start = (string) get_term_meta( $term->term_id, 'ace_event_next_event_date', true );
                    $legacy_end = (string) get_term_meta( $term->term_id, 'ace_event_end_date', true );
                    $context['events'][] = array(
                        'label' => $term->name, 'provider' => 'ace-teams-events', 'term_id' => (int) $term->term_id,
                        'start' => $start > 0 ? wp_date( 'Y-m-d', $start, wp_timezone() ) : ( Ace_SEO_Retention_Evidence::date( $legacy_start ) ? $legacy_start : '' ),
                        'end' => $end > 0 ? wp_date( 'Y-m-d', $end, wp_timezone() ) : ( Ace_SEO_Retention_Evidence::date( $legacy_end ) ? $legacy_end : '' ),
                        'verified' => false, 'occurrence_id' => '',
                        'reason' => 'Linked event; the article’s edition has not been verified against these mutable dates.',
                    );
                }
            }
        }
        return $context;
    }

    /**
     * Timing facts for a spreadsheet row, so whoever decides can see the dates the article is about,
     * where they came from, and whether the saved assessment even looked at the right period. No
     * verdict is drawn here; the choice stays with the editor.
     */
    public static function timing_columns( $id, array $row, $as_of ) {
        $built  = ! empty( $row['built'] ) ? (int) $row['built'] : 0;
        $window = ! empty( $row['window'] ) ? (int) $row['window'] : 0;
        $period = $built && $window ? array( 'start' => gmdate( 'Y-m-d', $built - $window * DAY_IN_SECONDS ), 'end' => gmdate( 'Y-m-d', $built ) ) : array();
        $c      = self::base_context( $id, $row, $period, $as_of );
        $r      = Ace_SEO_Retention_Evidence::relevance( $c );

        $timing = (string) get_post_meta( $id, self::META_TIMING, true );
        $set    = 'evergreen' === $timing ? 'Evergreen (editor)' : ( 'dates' === $timing ? ( isset( $c['override'] ) ? 'Set dates (editor)' : 'Set dates (editor) — incomplete, ignored' ) : 'Automatic (anniversary estimate)' );

        $relevant = isset( $r['start'], $r['end'] ) ? $r['start'] . ' to ' . $r['end'] : ( ! empty( $r['evergreen'] ) ? 'Any period' : 'Not established' );
        $basis    = (string) $r['source'] . ( empty( $r['verified'] ) && empty( $r['ambiguous'] ) && isset( $r['start'] ) ? ' — unverified' : '' );

        $events = array();
        foreach ( (array) ( $c['events'] ?? array() ) as $e ) {
            $dates    = Ace_SEO_Retention_Evidence::date( $e['start'] ?? null ) ? ( $e['start'] . ( ! empty( $e['end'] ) && $e['end'] !== $e['start'] ? ' to ' . $e['end'] : '' ) ) : 'dates unknown';
            $events[] = $e['label'] . ' (' . $dates . ( empty( $e['verified'] ) ? ', edition unverified' : ', verified' ) . ')';
        }

        if ( ! $period ) {
            $in_season = 'No saved assessment';
        } elseif ( ! empty( $r['evergreen'] ) ) {
            $in_season = 'Not seasonal';
        } elseif ( ! isset( $r['start'], $r['end'] ) ) {
            $in_season = 'Unknown: relevant dates not established';
        } else {
            // An anniversary estimate recurs every year, so the assessed window is checked against each
            // year's season; editorial or verified event dates are one-off and checked as given.
            $recurring = empty( $r['verified'] ) && false !== strpos( (string) $r['source'], 'anniversary' );
            $overlap   = false;
            foreach ( $recurring ? range( -6, 1 ) : array( 0 ) as $years ) {
                $s = $years ? ( new DateTimeImmutable( $r['start'] . ' UTC' ) )->modify( $years . ' year' )->format( 'Y-m-d' ) : $r['start'];
                $e = $years ? ( new DateTimeImmutable( $r['end'] . ' UTC' ) )->modify( $years . ' year' )->format( 'Y-m-d' ) : $r['end'];
                if ( $period['start'] <= $e && $period['end'] >= $s ) {
                    $overlap = true;
                    break;
                }
            }
            $in_season = ( $overlap ? 'Yes' : 'No' ) . ' (assessed ' . $period['start'] . ' to ' . $period['end'] . ( empty( $r['verified'] ) ? ', relevant dates estimated' : '' ) . ')';
        }
        return array( $set, $relevant, $basis, $events ? implode( '; ', $events ) : '', $in_season );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $as_of = current_datetime()->format( 'Y-m-d' );
        $end = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
        $start = current_datetime()->modify( '-90 days' )->format( 'Y-m-d' );
        foreach ( array( 'start', 'end' ) as $key ) {
            $value = isset( $_GET['evidence_' . $key] ) ? sanitize_text_field( wp_unslash( $_GET['evidence_' . $key] ) ) : '';
            if ( Ace_SEO_Retention_Evidence::date( $value ) ) { ${$key} = $value; }
        }
        if ( $start > $end ) { echo '<p role="alert">Choose an end date on or after the start date.</p>'; return; }
        $page = max( 1, (int) ( $_GET['evidence_page'] ?? 1 ) );
        $rows = AceSeoRetentionReport::rows( '', self::LIMIT, ( $page - 1 ) * self::LIMIT );
        _prime_post_caches( array_column( $rows, 'id' ), true, true );
        $records = array();
        foreach ( $rows as $row ) {
            $context = self::context( $row['id'], $row, array( 'start' => $start, 'end' => $end ), $as_of );
            $records[] = array( 'id' => $row['id'], 'row' => $row, 'context' => $context, 'assessment' => Ace_SEO_Retention_Evidence::assess( self::prepare( $row, $context ), $context, AceSeoRetentionReport::settings() ) );
        }
        $labels = Ace_SEO_Retention_Evidence::recommendations();
        $tiers = array_merge( AceSeoRetentionReport::tier_labels(), array( 'unknown' => 'Not ready to judge' ) );
        $action_tools = array( 'consolidate' => 'redirect', 'noindex' => 'noindex' );
        ?>
        <style>
        .ace-evidence-reference td,.ace-evidence-reference th{vertical-align:top;padding:12px;min-width:145px}
        .ace-evidence-reference .ace-evidence-quiet{background:#f0f0f1;color:#50575e}
        .ace-evidence-reference .ace-evidence-review{background:#fff8e5;color:#3c2c00}
        .ace-evidence-reference .ace-evidence-useful{background:#edf5ee;color:#274a30}
        .ace-evidence-reference .ace-evidence-uncertain{background:#f6f7f7;color:#50575e;border:1px solid #dcdcde}
        .ace-evidence-status{display:inline-block;padding:5px 8px;border-radius:3px;background:#fff8e5;color:#3c2c00}
        .ace-evidence-status.ace-evidence-uncertain{background:#f6f7f7;color:#50575e;border:1px solid #646970}
        .ace-evidence-status.ace-evidence-useful{background:#edf5ee;color:#274a30}
        .ace-evidence-reference small{display:block;margin-top:5px;line-height:1.5}
        .ace-evidence-badge{display:inline-block;padding:3px 7px;border:1px solid #135e96;border-radius:3px;background:#eaf3fb;color:#12466b;font-size:12px;line-height:1.4;margin-top:6px}
        .ace-evidence-planned{display:inline-block;padding:3px 7px;border:1px solid #6b4388;border-radius:3px;background:#f5eefb;color:#56356d;font-size:12px;line-height:1.4;margin-top:6px}
        .ace-evidence-legend{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}
        .ace-evidence-legend span{padding:5px 9px;border:1px solid #8c8f94;border-radius:3px;color:#3c434a}
        </style>
        <section id="retention-evidence"><h2>Which next steps fit?</h2>
        <p>The group tells us what we know about an article. The next step tells us what might help. Use this guide to see how they fit together.</p>
        <div class="ace-evidence-legend" aria-label="Reference table key"><span style="background:#f0f0f1">— No change needed / not a good fit</span><span style="background:#edf5ee">✓ Still useful</span><span style="background:#fff8e5">△ Worth a look</span><span style="background:#ffdfb8">! A confirmed problem needs attention</span><span style="background:#f6f7f7">? We need more information</span></div>
        <div style="overflow-x:auto"><table class="widefat ace-evidence-reference"><caption>Which suggestions fit each group?</caption><thead><tr><th scope="col">What could help?</th>
        <?php $group_meanings = array( 'retained' => 'Still getting readers', 'candidate' => 'Short, with no measured visits', 'dormant' => 'Quiet, but not an obvious clean-up candidate', 'unknown' => 'We need more information' ); ?>
        <?php foreach ( $tiers as $tier => $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?><small><?php echo esc_html( $group_meanings[$tier] ); ?></small></th><?php endforeach; ?>
        </tr></thead><tbody>
        <?php foreach ( $labels as $key => $label ) : ?><tr><th scope="row"><?php echo esc_html( $label ); ?>
            <?php if ( isset( $action_tools[$key], AceSeoRetentionActions::ACTIONS[$action_tools[$key]] ) ) : ?><span class="ace-evidence-badge">Action tool available after review</span><?php endif; ?>
            <?php if ( 'consolidate' === $key ) : ?><span class="ace-evidence-planned">Automatic option — coming later</span><?php endif; ?>
            </th>
            <?php foreach ( $tiers as $tier => $group_label ) : $cell = Ace_SEO_Retention_Evidence::reference_cell( $tier, $key ); ?>
                <td class="ace-evidence-<?php echo esc_attr( $cell['status'] ); ?>"><strong><?php echo esc_html( $cell['label'] ); ?></strong><small><?php echo esc_html( $cell['why'] ); ?></small></td>
            <?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></div>
        <p>Quiet does not mean useless. We only call visits zero when we have enough data from the right period. The colours show what deserves attention, not how certain the data is. This guide does not change any article.</p>
        <details><summary>More detail: how the rules and action tools work</summary>
            <p>Groups describe readership; recommendations and management choices are separate. Missing or capped data, an unfinished period, uncertain event dates or an off-season snapshot hold back negative recommendations. A publication anniversary is only an estimate until the timing is confirmed.</p>
            <p>The existing administrator tools can apply a permanent 301 redirect to a chosen URL or set noindex while keeping the page available. Their presence does not mean they are enabled or applied. The reference guide does not automatically run either tool.</p>
            <p>Planned management choices are review only, approve once, or automatic action under explicitly configured rules. Automatic redirects will require complete relevant evidence, a verified suitable replacement, exclusions, a dry run, a recorded reason and rollback. A redirect will not switch on and off with the season. This automatic option is not implemented here.</p>
            <p>Separate age-based notices, search-expiry rules and weekly report rebuilds already work when configured. They do not automatically act on the suggestions in this table.</p>
            <p>Stronger orange is reserved for a specific confirmed problem. No cell here claims a real article is urgent. “No activity measured in a complete relevant period” is different from missing information, and does not prove the article has no value.</p>
        </details>
        <details><summary>Other useful next steps we are working on</summary><ul>
            <li><strong>Check after the event:</strong> wait until the event and its reporting period have finished.</li>
            <li><strong>Keep useful history:</strong> older information can still help readers even when visits are low.</li>
            <li><strong>Help readers find it:</strong> add useful links when a good article is difficult to discover.</li>
        </ul><p>These suggestions are not yet calculated by the preview.</p></details>
        <h3>Try the dates against saved articles</h3>
        <p>This is a preview. The saved report and Google Sheet stay as they are.</p>
        <form method="get"><input type="hidden" name="page" value="ace-seo-retention"><input type="hidden" name="evidence_preview" value="1">
            <label>From <input type="date" name="evidence_start" value="<?php echo esc_attr( $start ); ?>" required></label>
            <label>to <input type="date" name="evidence_end" value="<?php echo esc_attr( $end ); ?>" max="<?php echo esc_attr( current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ) ); ?>" required></label>
            <button class="button">Preview these dates</button>
            <?php $prev = array( 'evidence_start' => gmdate( 'Y-m-d', strtotime( $start . ' -1 year' ) ), 'evidence_end' => gmdate( 'Y-m-d', strtotime( $end . ' -1 year' ) ) ); ?>
            <a href="<?php echo esc_url( add_query_arg( $prev ) ); ?>">Same dates a year earlier</a>
        </form>
        <?php $first = $records ? $records[0]['context'] : array(); ?>
        <?php if ( ! empty( $first['sources'] ) ) : ?>
            <p>Traffic for these exact dates comes from <?php echo esc_html( implode( ' and ', $first['sources'] ) ); ?> — <?php echo esc_html( ! empty( $first['coverage']['complete'] ) ? 'complete coverage of the period' : 'coverage is incomplete, so no negative conclusions are drawn' ); ?>.<?php foreach ( (array) ( $first['coverage']['notes'] ?? array() ) as $note ) : ?> <?php echo esc_html( $note ); ?><?php endforeach; ?></p>
        <?php elseif ( $records ) : ?>
            <p>No traffic source can report these exact dates (Site Kit Analytics, Search Console or own tracking), so visitor counts stay unknown and the rules hold.</p>
        <?php endif; ?>
        <p><?php echo esc_html( sprintf( 'Showing %d saved articles on page %d. This sample is separate from the reference guide above.', count( $records ), $page ) ); ?></p>
        <h3>Why each suggestion fits</h3>
        <?php foreach ( $records as $record ) : $row = $record['row']; $assessment = $record['assessment']; ?>
            <details id="evidence-post-<?php echo (int) $record['id']; ?>" style="margin:1em 0;scroll-margin-top:40px">
                <summary><?php echo esc_html( $row['title'] ?: '(no title)' ); ?> — <?php echo esc_html( $labels[$assessment['primary']] ); ?></summary>
                <p>Saved assessment: <?php echo esc_html( ( AceSeoRetentionReport::tier_labels()[$row['tier'] ?? ''] ?? 'Unknown group' ) . ' / ' . ( AceSeoRetentionReport::recommendation_labels()[$row['bucket'] ?? ''] ?? 'Unknown recommendation' ) ); ?>. Preview: <?php echo esc_html( $tiers[$assessment['tier']] ); ?>.</p>
                <p>Suggestions: <?php echo esc_html( implode( '; ', array_map( static function ( $key ) use ( $labels ) { return $labels[$key]; }, $assessment['suggestions'] ) ) ); ?>.</p>
                <p><span class="ace-evidence-status ace-evidence-<?php echo esc_attr( $assessment['attention'] ); ?>"><?php echo esc_html( Ace_SEO_Retention_Evidence::attention_labels()[$assessment['attention']] ); ?></span> <strong>Evidence:</strong> <?php echo esc_html( $assessment['confidence'] ); ?>. <?php echo esc_html( $assessment['activity'] ); ?>.</p>
                <?php if ( ! empty( $record['context']['metrics'] ) ) : $m = $record['context']['metrics']; ?><p>For these dates: <?php echo esc_html( null === $m['views'] ? 'views unknown' : number_format_i18n( (int) $m['views'] ) . ' views' ); ?>, <?php echo esc_html( number_format_i18n( (int) $m['clicks'] ) ); ?> search clicks, <?php echo esc_html( number_format_i18n( (int) $m['impressions'] ) ); ?> impressions.</p><?php endif; ?>
                <?php if ( ! empty( $record['context']['timing_note'] ) ) : ?><p><?php echo esc_html( $record['context']['timing_note'] ); ?></p><?php endif; ?>
                <?php foreach ( $assessment['reasons'] as $reason ) : ?><p><?php echo esc_html( $reason ); ?></p><?php endforeach; ?>
                <?php self::timeline( $record ); ?>
                <p><a href="<?php echo esc_url( get_edit_post_link( $record['id'] ) ); ?>">Review this article</a></p>
            </details>
        <?php endforeach; ?>
        <p><?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'evidence_page', $page - 1 ) ); ?>#retention-evidence">Previous review page</a><?php endif; ?>
        <?php if ( count( $rows ) === self::LIMIT ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'evidence_page', $page + 1 ) ); ?>#retention-evidence">Next review page</a><?php endif; ?></p>
        </section>
        <?php
    }

    private static function timeline( array $record ) {
        $a = $record['assessment']; $context = $record['context'];
        $intervals = array( array_merge( $a['period'], array( 'label' => 'Chosen observation period' ) ) );
        if ( isset( $a['relevance']['start'], $a['relevance']['end'] ) ) { $intervals[] = array_merge( $a['relevance'], array( 'label' => $a['relevance']['source'] ) ); }
        foreach ( $context['events'] as $event ) { $event['label'] .= empty( $event['verified'] ) ? ' (edition unverified)' : ' (verified occurrence)'; $intervals[] = $event; }
        if ( ! empty( $a['coverage'] ) ) { $intervals[] = array_merge( $a['coverage'], array( 'label' => 'Recorded coverage — ' . ( ! empty( $a['coverage']['complete'] ) ? 'complete' : 'partial' ) ) ); }
        $valid = array_filter( $intervals, static function ( $range ) { return Ace_SEO_Retention_Evidence::date( $range['start'] ?? null ) && Ace_SEO_Retention_Evidence::date( $range['end'] ?? null ) && $range['start'] <= $range['end']; } );
        $min = $valid ? min( array_column( $valid, 'start' ) ) : ''; $max = $valid ? max( array_column( $valid, 'end' ) ) : '';
        $span = $min ? max( 86400, strtotime( $max . ' UTC' ) - strtotime( $min . ' UTC' ) + 86400 ) : 86400;
        echo '<details><summary>Event, traffic and snapshot timeline</summary><p>' . esc_html( $a['relevance']['source'] ) . '</p>';
        echo '<div style="overflow-x:auto"><table class="widefat"><caption>Dates supporting this preview; bars share the same date scale.</caption><thead><tr><th scope="col">Evidence</th><th scope="col">Dates</th><th scope="col">Relative period</th></tr></thead><tbody>';
        foreach ( $intervals as $range ) {
            $ok = Ace_SEO_Retention_Evidence::date( $range['start'] ?? null ) && Ace_SEO_Retention_Evidence::date( $range['end'] ?? null ) && $range['start'] <= $range['end'];
            echo '<tr><th scope="row">' . esc_html( $range['label'] ) . '</th><td>' . esc_html( $ok ? $range['start'] . ' to ' . $range['end'] : 'Dates not established' ) . '</td><td style="min-width:150px">';
            if ( $ok ) {
                $left = 100 * ( strtotime( $range['start'] . ' UTC' ) - strtotime( $min . ' UTC' ) ) / $span;
                $width = 100 * ( strtotime( $range['end'] . ' UTC' ) - strtotime( $range['start'] . ' UTC' ) + 86400 ) / $span;
                echo '<span aria-hidden="true" style="display:block;background:#2271b1;height:12px;margin-left:' . esc_attr( $left ) . '%;width:' . esc_attr( $width ) . '%"></span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p>Saved assessment: ' . esc_html( ! empty( $record['row']['built'] ) ? wp_date( 'j F Y H:i', (int) $record['row']['built'] ) : 'unknown date' ) . '. This is a snapshot timestamp, not proof of traffic coverage.</p>';
        if ( empty( $a['coverage'] ) ) { echo '<p>No dated coverage record is available. No traffic bar is drawn.</p>'; }
        echo '</details>';
    }
}
