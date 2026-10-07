<?php
/** Pure, read-only interpretation of retention evidence. No WordPress or storage side effects. */
final class Ace_SEO_Retention_Evidence {
    const VERSION = 'event-evidence-preview-2';

    public static function recommendations() {
        return array(
            'keep' => 'Keep it',
            'refresh' => 'Improve it',
            'seasonal-refresh' => 'Get ready for the next event',
            'consolidate' => 'Combine with a better page',
            'noindex' => 'Keep it off search',
            'hold' => 'Check again later',
        );
    }

    /** Suitability explains possibilities, never permission to apply an action. */
    public static function suitability() {
        return array(
            'retained' => array( 'keep' => 'Usually suitable', 'refresh' => 'When search demand is weakly served', 'seasonal-refresh' => 'Before a relevant event', 'consolidate' => 'Only after editorial comparison', 'noindex' => 'Exceptional editorial decision', 'hold' => 'When timing or evidence is uncertain' ),
            'candidate' => array( 'keep' => 'When links or other value justify it', 'refresh' => 'When there is useful demand', 'seasonal-refresh' => 'Before a relevant event', 'consolidate' => 'Only with a genuinely overlapping page', 'noindex' => 'Only after a complete relevant assessment', 'hold' => 'When timing or evidence is uncertain' ),
            'dormant' => array( 'keep' => 'When it still has useful value', 'refresh' => 'When there is useful demand', 'seasonal-refresh' => 'Before a relevant event', 'consolidate' => 'Only with a genuinely overlapping page', 'noindex' => 'Only after a complete relevant assessment', 'hold' => 'When timing or evidence is uncertain' ),
            'unknown' => array( 'keep' => 'Positive evidence can still justify keeping', 'refresh' => 'Positive search evidence only', 'seasonal-refresh' => 'Verified future event only', 'consolidate' => 'Not enough evidence', 'noindex' => 'Not enough evidence', 'hold' => 'Check the missing evidence first' ),
        );
    }

    public static function reference_cell( $tier, $suggestion ) {
        $text = self::suitability()[$tier][$suggestion];
        if ( 'keep' === $suggestion && 'retained' === $tier ) { return array( 'status' => 'useful', 'label' => 'Leave it as it is', 'why' => 'It is still helping readers.' ); }
        if ( 'unknown' === $tier && in_array( $suggestion, array( 'consolidate', 'noindex' ), true ) ) { return array( 'status' => 'quiet', 'label' => 'Not enough information yet', 'why' => 'Check the missing data first.' ); }
        if ( 'retained' === $tier && 'noindex' === $suggestion ) { return array( 'status' => 'quiet', 'label' => 'Not usually appropriate', 'why' => $text ); }
        if ( 'hold' === $suggestion ) { return array( 'status' => 'uncertain', 'label' => 'Check again later', 'why' => 'Wait for the right season or fill the gaps in the data.' ); }
        if ( 'consolidate' === $suggestion ) { return array( 'status' => 'review', 'label' => 'Find a suitable replacement first', 'why' => 'It must cover the same subject and help the same readers.' ); }
        if ( 'refresh' === $suggestion ) { return array( 'status' => 'review', 'label' => 'Worth improving if…', 'why' => 'People see it in search but few choose to read it.' ); }
        if ( 'seasonal-refresh' === $suggestion ) { return array( 'status' => 'review', 'label' => 'Before the right event', 'why' => 'Confirm which event the article covers, then check its details.' ); }
        if ( 'noindex' === $suggestion ) { return array( 'status' => 'review', 'label' => 'Consider after a proper check', 'why' => 'Keep the page available if useful, but review whether it belongs in search.' ); }
        return array( 'status' => 'review', 'label' => 'Keep it if it still helps', 'why' => 'Useful information and links can matter even when visits are low.' );
    }

    public static function attention_labels() {
        return array( 'quiet' => '— No change or not applicable', 'useful' => '✓ Useful: keep helping readers', 'review' => '△ Review needed', 'uncertain' => '? Timing or evidence uncertain', 'urgent' => '! Urgent only with a verified problem' );
    }

    public static function date( $date ) {
        if ( ! is_string( $date ) ) { return false; }
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
        return $parsed && $parsed->format( 'Y-m-d' ) === $date;
    }

    private static function interval( $value ) {
        return is_array( $value ) && self::date( $value['start'] ?? null ) && self::date( $value['end'] ?? null ) && $value['start'] <= $value['end'];
    }

    /** Resolve provenance before using dates. A mutable taxonomy's next event is NOT a verified edition. */
    public static function relevance( array $context ) {
        if ( self::interval( $context['override'] ?? null ) ) {
            return array_merge( $context['override'], array( 'source' => $context['override']['source'] ?? 'Editorial override', 'verified' => true ) );
        }
        if ( 'evergreen' === ( $context['content_type'] ?? '' ) ) {
            return array( 'source' => 'Evergreen: chosen observation period', 'verified' => true, 'evergreen' => true );
        }
        // A season from a category rule recurs every year: verified timing, with the occurrence that
        // is current or next relative to as_of shown as its dates.
        $season = $context['season'] ?? null;
        if ( is_array( $season ) && preg_match( '/^\d{2}-\d{2}$/', (string) ( $season['start'] ?? '' ) ) && preg_match( '/^\d{2}-\d{2}$/', (string) ( $season['end'] ?? '' ) ) ) {
            $year  = self::date( $context['as_of'] ?? '' ) ? (int) substr( $context['as_of'], 0, 4 ) : (int) gmdate( 'Y' );
            $start = $year . '-' . $season['start'];
            $end   = ( $season['end'] < $season['start'] ? $year + 1 : $year ) . '-' . $season['end'];
            if ( self::date( $context['as_of'] ?? '' ) && $end < $context['as_of'] ) {
                $start = ( $year + 1 ) . '-' . $season['start'];
                $end   = ( $season['end'] < $season['start'] ? $year + 2 : $year + 1 ) . '-' . $season['end'];
            }
            if ( self::date( $start ) && self::date( $end ) ) {
                return array( 'start' => $start, 'end' => $end, 'source' => $season['source'] ?? 'Category rule: season', 'verified' => true, 'recurring' => true );
            }
        }
        $verified = array();
        foreach ( (array) ( $context['events'] ?? array() ) as $event ) {
            if ( self::interval( $event ) && ! empty( $event['verified'] ) && ! empty( $event['occurrence_id'] ) ) {
                $verified[] = $event;
            }
        }
        if ( 1 === count( $verified ) ) {
            return array_merge( $verified[0], array( 'source' => 'Verified event occurrence', 'verified' => true ) );
        }
        if ( count( $verified ) > 1 ) {
            return array( 'source' => 'Several event occurrences: choose the relevant one', 'verified' => false, 'ambiguous' => true );
        }
        if ( self::interval( $context['anniversary'] ?? null ) ) {
            return array_merge( $context['anniversary'], array( 'source' => 'Publication anniversary estimate', 'verified' => false ) );
        }
        return array( 'source' => 'Relevant dates not established', 'verified' => false );
    }

    /**
     * Why a period cannot judge an article yet, or '' when it can. Used by the saved report as well as the
     * preview: an article is only judged on a period that contained the dates it is about. An anniversary
     * estimate recurs yearly, so any year's season inside the period counts; editorial dates and verified
     * event occurrences are one-off. Positive evidence (readers, clicks) is never held back by this.
     */
    public static function timing_hold( array $relevance, array $period, $strict = false ) {
        if ( ! self::interval( $period ) ) {
            return 'The assessed period is unknown.';
        }
        if ( ! empty( $relevance['ambiguous'] ) ) {
            return 'Several linked events could be the one this article covers; confirm which before judging it.';
        }
        if ( ! empty( $relevance['evergreen'] ) ) {
            return '';
        }
        // Strict: only confirmed timing (an editor's dates, evergreen, or a verified event) can judge.
        if ( $strict && empty( $relevance['verified'] ) ) {
            return 'Its timing has not been confirmed: set "When this article matters" on the post (evergreen, or the dates of the event or season it covers) before it is judged. ' . ( isset( $relevance['start'] ) ? 'The current estimate is ' . $relevance['start'] . ' to ' . $relevance['end'] . ' (' . strtolower( (string) $relevance['source'] ) . ').' : 'No relevant dates are known.' );
        }
        if ( ! isset( $relevance['start'], $relevance['end'] ) || ! self::interval( $relevance ) ) {
            return '';
        }
        $recurring = ! empty( $relevance['recurring'] ) || ( empty( $relevance['verified'] ) && false !== stripos( (string) ( $relevance['source'] ?? '' ), 'anniversary' ) );
        // A one-off event that ended before the period cannot be re-measured: the period measures its
        // readership after the event, which is a fair judgement, not a hold.
        if ( ! $recurring && $relevance['end'] < $period['start'] ) {
            return '';
        }
        // The period must contain a whole occurrence: half an event tells half a story.
        foreach ( $recurring ? range( -6, 1 ) : array( 0 ) as $years ) {
            $start = $years ? ( new DateTimeImmutable( $relevance['start'] . ' UTC' ) )->modify( $years . ' year' )->format( 'Y-m-d' ) : $relevance['start'];
            $end   = $years ? ( new DateTimeImmutable( $relevance['end'] . ' UTC' ) )->modify( $years . ' year' )->format( 'Y-m-d' ) : $relevance['end'];
            if ( $period['start'] <= $start && $period['end'] >= $end ) {
                return '';
            }
        }
        $when = $relevance['start'] > $period['end'] ? 'Judge it after that period has passed and been recorded.' : 'Judge it on a period that includes those dates.';
        return sprintf( 'The assessed period (%s to %s) did not include the dates this article is about (%s to %s, %s). %s', $period['start'], $period['end'], $relevance['start'], $relevance['end'], strtolower( (string) ( $relevance['source'] ?? 'relevant dates' ) ), $when );
    }

    /** A source must explicitly attest full coverage of this exact interval; absent rows are not zeros. */
    public static function covered( array $coverage, array $period ) {
        return self::interval( $period ) && self::interval( $coverage )
            && true === ( $coverage['complete'] ?? false ) && empty( $coverage['capped'] )
            && $coverage['start'] <= $period['start'] && $coverage['end'] >= $period['end'];
    }

    public static function assess( array $row, array $context, array $settings ) {
        $period = $context['period'] ?? array();
        $relevance = self::relevance( $context );
        $as_of = $context['as_of'] ?? '';
        $reasons = array();
        $coverage = (array) ( $context['coverage'] ?? array() );
        $covered = self::covered( $coverage, $period );
        $metric_period = $context['metric_period'] ?? array();
        $metrics_match = self::interval( $metric_period ) && self::interval( $period ) && $metric_period['start'] === $period['start'] && $metric_period['end'] === $period['end'];
        if ( ! $metrics_match ) {
            $reasons[] = 'Saved traffic totals belong to a different or unknown period; they cannot be reused for these dates.';
            $row['views'] = null; $row['clicks'] = 0; $row['impressions'] = 0;
        }
        if ( ! self::interval( $period ) || ! self::date( $as_of ) || $period['end'] >= $as_of ) {
            $reasons[] = 'The chosen period has not finished or its dates are invalid.';
        }
        if ( ! $covered ) { $reasons[] = 'Complete traffic coverage has not been established for the chosen dates.'; }
        if ( empty( $relevance['verified'] ) ) { $reasons[] = $relevance['source'] . ': confirm the timing before drawing a negative conclusion.'; }
        $post_event = false;
        if ( empty( $relevance['evergreen'] ) && isset( $relevance['start'], $relevance['end'] ) ) {
            if ( ! empty( $relevance['verified'] ) && empty( $relevance['recurring'] ) && self::interval( $period ) && $relevance['end'] < $period['start'] ) {
                $post_event = true; // the event is over; this period measures what the article is worth afterwards
            } elseif ( ! self::interval( $period ) || $period['start'] > $relevance['start'] || $period['end'] < $relevance['end'] ) {
                $reasons[] = 'The chosen period does not cover the full relevant event or season.';
            }
        }
        // Positive observations still matter out of season. They do not prove a complete window.
        $views = isset( $row['views'] ) ? (int) $row['views'] : null;
        $clicks = (int) ( $row['clicks'] ?? 0 );
        $retained = $clicks > 0 || ( null !== $views && $views >= max( 1, (int) ( $settings['retained_views'] ?? 1 ) ) );
        $ready = ! $reasons && null !== $views;
        if ( null === $views && ! $retained ) { $reasons[] = 'Visitor counts are unknown, not zero.'; }
        $tier = $retained ? 'retained' : ( $ready ? ( 0 === $views && (int) ( $row['words'] ?? 0 ) < (int) ( $settings['thin_words'] ?? 300 ) ? 'candidate' : 'dormant' ) : 'unknown' );
        $suggestions = array();
        $impressions = (int) ( $row['impressions'] ?? 0 );
        if ( $impressions >= (int) ( $settings['demand_impressions'] ?? 100 ) && $impressions > 0
            && $clicks / $impressions < (float) ( $settings['refresh_max_ctr'] ?? .02 )
            && (float) ( $row['position'] ?? 0 ) > 0 && (float) $row['position'] <= (float) ( $settings['refresh_max_pos'] ?? 20 ) ) {
            $suggestions[] = 'refresh';
        }
        if ( $retained || ! empty( $row['backlinks'] ) ) { $suggestions[] = 'keep'; }
        if ( ! empty( $relevance['verified'] ) && isset( $relevance['start'] ) && self::date( $as_of ) && $relevance['start'] > $as_of ) {
            $suggestions[] = 'seasonal-refresh';
        }
        if ( ! $ready ) { $suggestions[] = 'hold'; }
        if ( $ready && ! $retained && empty( $row['backlinks'] ) ) {
            if ( $impressions > 0 && ! empty( $context['overlap_verified'] ) ) { $suggestions[] = 'consolidate'; }
            if ( 0 === $impressions && ! empty( $row['links_in'] ) ) { $suggestions[] = 'noindex'; }
        }
        if ( ! $suggestions ) { $suggestions[] = 'hold'; $reasons[] = 'An editorial review is needed; silence alone does not establish the best action.'; }
        $attention = ! $ready ? 'uncertain' : ( array( 'keep' ) === $suggestions ? 'useful' : 'review' );
        $activity = $ready && 0 === $views && 0 === $clicks && 0 === $impressions ? 'No activity measured in a complete relevant period' : ( $retained ? 'Positive readership evidence' : 'No conclusion about inactivity' );
        return array( 'version' => self::VERSION, 'post_event' => $post_event, 'tier' => $tier, 'primary' => $suggestions[0], 'suggestions' => array_values( array_unique( $suggestions ) ), 'ready' => $ready, 'attention' => $attention, 'activity' => $activity, 'confidence' => $ready ? 'Complete relevant coverage established' : 'Evidence or timing still needs checking', 'reasons' => array_values( array_unique( $reasons ) ), 'relevance' => $relevance, 'period' => $period, 'coverage' => $coverage, 'as_of' => $as_of );
    }

}
