<?php
/** Calendar-only retention preview. This class does not change report or crawl state. */
final class Ace_SEO_Seasonal_Window {
    /** Clamp a date to the final valid day of its target month; PHP's overflow is unsuitable here. */
    private static function date( $year, $month, $day, DateTimeZone $timezone ) {
        $first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $timezone );
        return $first->setDate( $year, $month, min( $day, (int) $first->format( 't' ) ) );
    }

    private static function shift_months( DateTimeImmutable $date, $months ) {
        $first = $date->modify( 'first day of this month' )->modify( sprintf( '%+d months', $months ) );
        return self::date( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), (int) $date->format( 'd' ), $date->getTimezone() );
    }

    private static function parse( $value, DateTimeZone $timezone ) {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
        if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
            throw new InvalidArgumentException( 'Use a valid calendar date in YYYY-MM-DD format.' );
        }
        return $date;
    }

    /**
     * Preview the current season, or the next season if today is outside it.
     * Both boundary days are included. Leap-day anniversaries use 28 February in non-leap years.
     * Being in season is NOT sufficient evidence for an adverse retention recommendation.
     */
    public static function preview( $published, $as_of, DateTimeZone $timezone ) {
        $publication = self::parse( $published, $timezone );
        $today = self::parse( $as_of, $timezone );
        if ( $publication > $today ) {
            throw new InvalidArgumentException( 'The publication date is later than the assessment date.' );
        }
        $year = (int) $today->format( 'Y' );
        for ( $candidate = $year - 1; $candidate <= $year + 1; ++$candidate ) {
            $anniversary = self::date( $candidate, (int) $publication->format( 'm' ), (int) $publication->format( 'd' ), $timezone );
            $start = self::shift_months( $anniversary, -2 );
            $end = self::shift_months( $anniversary, 2 );
            if ( $end < $today ) {
                continue;
            }
            $in_season = $today >= $start;
            return array(
                'rule' => 'publication-anniversary-v1-preview',
                'as_of' => $today->format( 'Y-m-d' ),
                'timezone' => $timezone->getName(),
                'anniversary' => $anniversary->format( 'Y-m-d' ),
                'season_start' => $start->format( 'Y-m-d' ),
                'season_end' => $end->format( 'Y-m-d' ),
                'in_season' => $in_season,
                'reason' => $in_season
                    ? 'In season: check traffic coverage before making a recommendation.'
                    : 'Out of season: hold for review when the relevant season returns.',
            );
        }
        throw new LogicException( 'No seasonal window could be calculated.' );
    }
}
