<?php
/**
 * Timing rules suggested from the site's own data, one per category or tag.
 *
 * Nothing here knows what a sport, a festival or a tournament is. For every term with enough assessed
 * posts it looks at three generic things the saved report already holds: how many of the term's older
 * posts are still read at least monthly (persistence), how the term's publication dates fall across the
 * year (seasonality), and how often the term publishes (cadence). From those it proposes one of the
 * three rule types the report understands — evergreen, event-bound for N days, or a yearly season — and
 * says in plain words what it saw. An administrator accepts or ignores each suggestion; accepted rules
 * live in the same timing_rules option as hand-written ones, which always win. Suggestions recompute
 * after every completed check, so they follow the archive as it changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ace_SEO_Timing_Suggestions {

    const OPTION   = 'ace_seo_timing_suggestions';

    /** Whether the last infer() had enough readership data for day-of-event and evergreen suggestions. */
    public static $readership_known = true;
    public static $readership_share = 0.0;
    /** Fewer assessed posts than this and a term tells us nothing reliable. */
    const MIN_POSTS = 30;

    public static function init() {
        add_action( 'ace_seo_retention_built', array( __CLASS__, 'recompute' ) );
        if ( is_admin() ) {
            add_action( 'admin_post_ace_seo_timing_suggest', array( __CLASS__, 'handle_recompute' ) );
            add_action( 'wp_ajax_ace_seo_timing_rules', array( __CLASS__, 'ajax_save' ) );
            add_action( 'wp_ajax_ace_seo_timing_detail', array( __CLASS__, 'ajax_detail' ) );
        }
    }

    /** The modal's "Save and close": one chosen rule (or none) per term, plus the ignore list. */
    public static function ajax_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ace_seo_timing_rules', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        }
        $choices = array();
        foreach ( (array) wp_unslash( $_POST['rule'] ?? array() ) as $key => $text ) {
            $choices[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $text );
        }
        $ignore = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ignore'] ?? array() ) );
        $result = AceSeoRetentionActions::set_timing_rules( $choices, $ignore );
        if ( $result['invalid'] ) {
            wp_send_json_error( array( 'message' => 'These rules were not understood and were left unchanged: ' . implode( ', ', $result['invalid'] ) . '. An event needs a number of days; a season needs two dates as MM-DD.' ) );
        }
        wp_send_json_success( array(
            'message'  => 'Timing rules saved.',
            'rules'    => AceSeoRetentionActions::timing_rules_text( (array) $result['options']['timing_rules'] ),
            'overview' => self::overview( self::current(), $result['options'] ),
        ) );
    }

    /** Recent assessed posts in one category or tag, for the dialog's worked example. */
    public static function ajax_detail() {
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ace_seo_timing_rules', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        }
        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
        if ( ! preg_match( '/^([a-z0-9_-]+):([a-z0-9_-]+)$/', $key, $m ) ) {
            wp_send_json_error( array( 'message' => 'Unknown term.' ) );
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, t.meta_value AS tier, r.meta_value AS rank_value
             FROM {$wpdb->term_taxonomy} tt
             JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id AND tm.slug = %s
             JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
             WHERE tt.taxonomy = %s
             ORDER BY p.post_date DESC LIMIT 6",
            $m[2], AceSeoRetentionReport::META_TIER, AceSeoRetentionReport::META_RANK, $m[1]
        ), ARRAY_A );
        $tiers = AceSeoRetentionReport::tier_labels();
        $ranks = array( 'daily' => 'read daily', 'weekly' => 'read weekly', 'monthly' => 'read monthly', 'occasional' => 'read occasionally' );
        $out   = array();
        foreach ( (array) $rows as $r ) {
            $out[] = array(
                'title' => html_entity_decode( $r['post_title'] ?: '(no title)', ENT_QUOTES, 'UTF-8' ),
                'date'  => substr( $r['post_date'], 0, 10 ),
                'group' => ( $ranks[ $r['rank_value'] ] ?? '' ) ?: ( $tiers[ $r['tier'] ] ?? $r['tier'] ),
                'edit'  => get_edit_post_link( (int) $r['ID'], 'raw' ),
            );
        }
        wp_send_json_success( array( 'posts' => $out ) );
    }

    /** What a rule means for the posts it covers, in plain words. Accepts the stored array or the text form. */
    public static function explain_rule( $rule ) {
        if ( is_string( $rule ) ) {
            $parsed = AceSeoRetentionActions::parse_timing_rules( 'x:y = ' . $rule );
            $rule   = $parsed['x:y'] ?? null;
        }
        if ( ! is_array( $rule ) ) {
            return 'No rule: the report judges these posts on the traffic period alone, holding only those whose own dates fall outside it.';
        }
        switch ( $rule['type'] ) {
            case 'evergreen':
                return 'Always relevant: judge each post on any period, with no seasonal allowance.';
            case 'event':
                $d = (int) $rule['days'];
                return sprintf( 'Day-of-event content: each post matters for %d day%s from the day it was published. Once that has passed, how it is read afterwards is a fair judgement, so old posts are never held for it.', $d, 1 === $d ? '' : 's' );
            case 'season':
                return sprintf( 'A yearly season from %s to %s: judge each post only on a period that contains a whole season; outside it, hold rather than call it quiet.', self::md_label( $rule['start'] ), self::md_label( $rule['end'] ) );
        }
        return '';
    }

    /** Plain counts for the overview line. */
    public static function overview( array $suggestions, array $options ) {
        $rules   = (array) ( $options['timing_rules'] ?? array() );
        $ignored = (array) ( $options['timing_ignored'] ?? array() );
        $types   = array( 'season' => 0, 'evergreen' => 0, 'event' => 0, 'mixed' => 0 );
        $in_use  = 0;
        $posts   = 0;
        foreach ( $suggestions as $key => $s ) {
            $types[ $s['type'] ] = ( $types[ $s['type'] ] ?? 0 ) + 1;
            if ( isset( $rules[ $key ] ) && AceSeoRetentionActions::rule_to_text( $rules[ $key ] ) === $s['rule'] ) {
                $in_use++;
                $posts += (int) $s['posts'];
            }
        }
        return array(
            'total'      => count( $suggestions ) - $types['mixed'],
            'season'     => $types['season'],
            'evergreen'  => $types['evergreen'],
            'event'      => $types['event'],
            'mixed'      => $types['mixed'],
            'in_use'     => $in_use,
            'in_use_posts' => $posts,
            'ignored'    => count( array_intersect( $ignored, array_keys( $suggestions ) ) ),
        );
    }

    /* ---- Inference (pure) ------------------------------------------------------------------------- */

    /**
     * Rows: array( array( 'key' => 'category:slug', 'label' => 'Name', 'date' => 'Y-m-d', 'rank' => 'monthly'|'' , 'tier' => 'retained'|... ) ).
     * Returns suggestions keyed by term key, best evidence first.
     */
    public static function infer( array $rows, $min_posts = self::MIN_POSTS ) {
        $terms = array();
        foreach ( $rows as $r ) {
            $k = (string) $r['key'];
            if ( ! isset( $terms[ $k ] ) ) {
                $terms[ $k ] = array( 'key' => $k, 'label' => (string) $r['label'], 'n' => 0, 'persistent' => 0, 'read' => 0, 'dates' => array() );
            }
            $terms[ $k ]['n']++;
            if ( in_array( $r['rank'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ) {
                $terms[ $k ]['persistent']++;
            }
            if ( 'retained' === ( $r['tier'] ?? '' ) ) {
                $terms[ $k ]['read']++;
            }
            // A zero or legacy date (0000-00-00, year 1970) would stretch the cadence maths across centuries.
            if ( preg_match( '/^((?:19|20)\d{2})-(\d{2})-(\d{2})/', (string) ( $r['date'] ?? '' ), $m ) && (int) $m[1] >= 1995 && (int) $m[1] <= (int) gmdate( 'Y' ) + 1 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                $terms[ $k ]['dates'][] = substr( $r['date'], 0, 10 );
            }
        }
        // Persistence needs a readership source. If almost nothing on the whole site counts as read
        // (no Analytics, tracking only just switched on), "nobody reads it later" is an artefact of
        // missing data, so only the date-based seasonal suggestions are offered.
        $all = 0;
        $read = 0;
        foreach ( $terms as $t ) {
            $all  += $t['n'];
            $read += $t['read'];
        }
        $readership_known = $all > 0 && $read / $all >= 0.02;
        self::$readership_known = $readership_known;
        self::$readership_share = $all > 0 ? round( 100 * $read / $all, 2 ) : 0.0;
        $out = array();
        foreach ( $terms as $k => $t ) {
            if ( $t['n'] < $min_posts ) {
                continue;
            }
            $s = self::suggest_for( $t, $readership_known );
            if ( $s ) {
                $out[ $k ] = $s;
            }
        }
        // Impact first: the rule that settles 15,000 posts matters more than one that settles 30.
        uasort( $out, static function ( $a, $b ) {
            return ( $b['posts'] <=> $a['posts'] ) ?: ( $b['confidence'] <=> $a['confidence'] );
        } );
        return $out;
    }

    /** One term's shape → one suggestion (or null when nothing stands out). */
    public static function suggest_for( array $t, $readership_known = true ) {
        $n           = (int) $t['n'];
        $persistence = $n ? $t['persistent'] / $n : 0;      // share still read at least monthly, years later
        $read_share  = $n ? $t['read'] / $n : 0;
        sort( $t['dates'] );
        $season = self::season_band( $t['dates'] );
        $gap    = self::median_gap_days( $t['dates'] );
        $years  = count( array_unique( array_map( static function ( $d ) { return substr( $d, 0, 4 ); }, $t['dates'] ) ) );
        $months = array_fill( 1, 12, 0 );
        foreach ( $t['dates'] as $d ) {
            $months[ (int) substr( $d, 5, 2 ) ]++;
        }
        $base   = array(
            'key'          => $t['key'],
            'label'        => $t['label'],
            'posts'        => $n,
            'persistence'  => round( 100 * $persistence, 1 ),
            'read_share'   => round( 100 * $read_share, 1 ),
            'years'        => $years,
            'first_year'   => $t['dates'] ? (int) substr( $t['dates'][0], 0, 4 ) : 0,
            'last_year'    => $t['dates'] ? (int) substr( end( $t['dates'] ), 0, 4 ) : 0,
            'cadence_days' => $gap,
            'months'       => array_values( $months ),
            'season'       => $season ? array( 'start' => $season['start'], 'end' => $season['end'], 'share' => round( 100 * $season['share'] ) ) : null,
        );

        // Seasonal: most posts land in one part of the year, in more than one year.
        if ( $season && $years >= 2 ) {
            return $base + array(
                'type'       => 'season',
                'rule'       => 'season ' . $season['start'] . ' ' . $season['end'],
                'confidence' => min( 0.95, 0.5 + $season['share'] / 2 + min( 0.2, $years / 20 ) ),
                'why'        => sprintf( '%d%% of its %s posts were published between %s and %s, across %d years. Older posts should be judged in that season, not in the quiet months.', (int) round( 100 * $season['share'] ), number_format_i18n( $n ), self::md_label( $season['start'] ), self::md_label( $season['end'] ), $years ),
            );
        }
        if ( ! $readership_known ) {
            return null; // Evergreen and event-bound both read persistence, which needs real readership data.
        }
        // Evergreen: a clear share of its old posts is still read monthly or better, all year round.
        if ( $persistence >= 0.15 ) {
            return $base + array(
                'type'       => 'evergreen',
                'rule'       => 'evergreen',
                'confidence' => min( 0.95, 0.4 + $persistence ),
                'why'        => sprintf( '%s%% of its %s older posts are still read at least monthly, years after publication, with no particular season. It behaves like reference content.', number_format_i18n( round( 100 * $persistence, 1 ) ), number_format_i18n( $n ) ),
            );
        }
        // Event-bound: publishes at least weekly, all year round, and its older posts are rarely read later.
        // A sparse tag with no readers is just old, not day-of-event content: it gets no rule.
        if ( $persistence < 0.08 && null !== $gap && $gap <= 7 ) {
            $days    = max( 2, min( 14, (int) ceil( $gap * 1.5 ) + 1 ) );
            $cadence = $gap < 0.75 ? 'more than once a day' : ( $gap < 1.5 ? 'about once a day' : sprintf( 'about every %s days', number_format_i18n( $gap, $gap < 10 ? 1 : 0 ) ) );
            return $base + array(
                'type'       => 'event',
                'rule'       => 'event ' . $days,
                'confidence' => min( 0.9, 0.45 + ( 0.08 - $persistence ) * 4 + min( 0.25, $n / 4000 ) ),
                'why'        => sprintf( 'Only %s%% of its %s older posts are still read at least monthly, and it publishes %s all year round. That is the shape of day-of-event content, relevant for about %d days from publication.', number_format_i18n( round( 100 * $persistence, 1 ) ), number_format_i18n( $n ), $cadence, $days ),
            );
        }
        // A big term with no clear shape is worth saying so about, rather than leaving it out.
        if ( $n >= 500 ) {
            return $base + array(
                'type'       => 'mixed',
                'rule'       => '',
                'confidence' => 0,
                'why'        => sprintf( 'No clear shape: %s%% of its %s older posts are still read at least monthly (between the event-bound and evergreen marks), published all year round%s. Probably a mix of dated and lasting pieces; a narrower tag may suit a rule better, or set timing on the posts that matter.', number_format_i18n( round( 100 * $persistence, 1 ) ), number_format_i18n( $n ), null !== $gap ? ( $gap < 0.75 ? ', more than once a day' : ( $gap < 1.5 ? ', about daily' : sprintf( ', about every %s days', number_format_i18n( $gap, $gap < 10 ? 1 : 0 ) ) ) ) : '' ),
            );
        }
        return null;
    }

    /** The shortest window of the year (as MM-DD .. MM-DD) holding ≥ 60% of the dates, if ≤ 90 days long. */
    public static function season_band( array $dates ) {
        $days = array();
        foreach ( $dates as $d ) {
            $ts = strtotime( $d . ' UTC' );
            if ( $ts ) {
                $days[] = (int) gmdate( 'z', $ts ); // 0..365
            }
        }
        $n = count( $days );
        if ( $n < 10 ) {
            return null;
        }
        sort( $days );
        $need = (int) ceil( $n * 0.6 );
        $best = null;
        // Circular: try every start day; the window wraps past New Year.
        $doubled = array_merge( $days, array_map( static function ( $d ) { return $d + 366; }, $days ) );
        for ( $i = 0; $i < $n; $i++ ) {
            $end_index = $i + $need - 1;
            $len       = $doubled[ $end_index ] - $doubled[ $i ];
            if ( null === $best || $len < $best['len'] ) {
                $best = array( 'len' => $len, 'from' => $doubled[ $i ] % 366, 'to' => $doubled[ $end_index ] % 366 );
            }
        }
        if ( ! $best || $best['len'] > 90 ) {
            return null;
        }
        // Widen slightly so the edges of the season are inside it, then count the share it actually covers.
        $from  = ( $best['from'] - 7 + 366 ) % 366;
        $to    = ( $best['to'] + 7 ) % 366;
        $in    = 0;
        foreach ( $days as $d ) {
            $inside = $from <= $to ? ( $d >= $from && $d <= $to ) : ( $d >= $from || $d <= $to );
            $in    += $inside ? 1 : 0;
        }
        return array( 'start' => self::md_from_yday( $from ), 'end' => self::md_from_yday( $to ), 'share' => $in / $n, 'len' => $best['len'] + 14 );
    }

    /** Typical days between posts: the span from first to last divided by the gaps (null with fewer than 10 dates). */
    public static function median_gap_days( array $dates ) {
        if ( count( $dates ) < 10 ) {
            return null;
        }
        $ts = array_map( static function ( $d ) { return (int) strtotime( $d . ' UTC' ); }, $dates );
        sort( $ts );
        $span = ( end( $ts ) - $ts[0] ) / DAY_IN_SECONDS;
        return round( $span / ( count( $ts ) - 1 ), 2 );
    }

    private static function md_from_yday( $yday ) {
        return gmdate( 'm-d', strtotime( '2024-01-01 UTC' ) + max( 0, min( 365, (int) $yday ) ) * DAY_IN_SECONDS ); // leap year: 366 days
    }

    public static function md_label( $md ) {
        return gmdate( 'j F', strtotime( '2024-' . $md . ' UTC' ) );
    }

    /* ---- Data ------------------------------------------------------------------------------------ */

    /** Every assessed post with its public terms: the rows infer() reads. One query per site. */
    public static function rows() {
        global $wpdb;
        $taxonomies = array();
        foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
            if ( ! empty( $tax->object_type ) && array_intersect( (array) $tax->object_type, (array) AceSeoRetentionReport::settings()['post_types'] ) ) {
                $taxonomies[] = $tax->name;
            }
        }
        $taxonomies = (array) apply_filters( 'ace_seo_timing_suggestion_taxonomies', $taxonomies );
        if ( ! $taxonomies ) {
            return array();
        }
        $in   = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $sql  = "SELECT tt.taxonomy, tm.slug, tm.name, p.post_date, tier.meta_value AS tier, r.meta_value AS rank_value
                 FROM {$wpdb->postmeta} tier
                 JOIN {$wpdb->posts} p ON p.ID = tier.post_id
                 LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ($in)
                 JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id
                 WHERE tier.meta_key = %s";
        $args = array_merge( array( AceSeoRetentionReport::META_RANK ), $taxonomies, array( AceSeoRetentionReport::META_TIER ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $row ) {
            $out[] = array( 'key' => $row['taxonomy'] . ':' . $row['slug'], 'label' => $row['name'], 'date' => $row['post_date'], 'rank' => (string) $row['rank_value'], 'tier' => (string) $row['tier'] );
        }
        return $out;
    }

    /** Work the suggestions out from the current assessment and store them. */
    public static function recompute() {
        $suggestions = self::infer( self::rows() );
        update_option( self::OPTION, array( 'computed' => time(), 'suggestions' => $suggestions, 'readership_known' => self::$readership_known, 'readership_share' => self::$readership_share ), false );
        return $suggestions;
    }

    /** Stored suggestions, computed now if there are none yet or the report has moved on. */
    public static function current() {
        $stored = get_option( self::OPTION, array() );
        $built  = (int) ( AceSeoRetentionReport::progress()['finished'] ?? 0 );
        $first  = is_array( $stored['suggestions'] ?? null ) ? reset( $stored['suggestions'] ) : null;
        if ( ! is_array( $stored ) || ! isset( $stored['suggestions'], $stored['readership_known'] ) || (int) ( $stored['computed'] ?? 0 ) < $built || ( is_array( $first ) && ! isset( $first['months'] ) ) ) {
            return self::recompute();
        }
        return (array) $stored['suggestions'];
    }

    /** Readership coverage behind the stored suggestions: array( known, share% ). */
    public static function readership() {
        $stored = get_option( self::OPTION, array() );
        return array( 'known' => ! isset( $stored['readership_known'] ) || (bool) $stored['readership_known'], 'share' => (float) ( $stored['readership_share'] ?? 0 ) );
    }

    public static function computed_at() {
        $stored = get_option( self::OPTION, array() );
        return (int) ( $stored['computed'] ?? 0 );
    }

    public static function handle_recompute() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_timing_suggest' ) ) {
            wp_die( 'Not allowed.' );
        }
        $n = count( self::recompute() );
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), sprintf( 'Suggestions recalculated from the current assessment: %d categories or tags have a clear enough shape to suggest a rule.', $n ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-report' ) );
        exit;
    }

    /* ---- UI --------------------------------------------------------------------------------------- */

    /** The plain name of a rule, for chips and lists ("Day-of-event, 2 days"). */
    public static function rule_name( $text ) {
        if ( '' === (string) $text ) {
            return 'No rule';
        }
        if ( 'evergreen' === $text ) {
            return 'Evergreen';
        }
        if ( preg_match( '/^event (\d+)$/', $text, $m ) ) {
            return 'Day-of-event, ' . $m[1] . ' day' . ( '1' === $m[1] ? '' : 's' );
        }
        if ( preg_match( '/^season (\d{2}-\d{2}) (\d{2}-\d{2})$/', $text, $m ) ) {
            return 'Yearly season, ' . self::md_label( $m[1] ) . ' to ' . self::md_label( $m[2] );
        }
        return $text;
    }

    /**
     * Inside the settings form: what the three kinds of rule mean, a one-line overview and a button.
     * The manager itself is a dialog rendered by render_modal() outside the form.
     */
    public static function render_fields( array $options ) {
        $suggestions = self::current();
        $o           = self::overview( $suggestions, $options );
        $computed    = self::computed_at();
        wp_enqueue_script( 'ace-seo-timing-rules', ACE_SEO_URL . 'assets/js/timing-rules.js', array(), ACE_SEO_VERSION, true );
        wp_localize_script( 'ace-seo-timing-rules', 'aceSeoTimingRules', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ace_seo_timing_rules' ) ) );
        ?>
        <div id="ace-timing-overview" class="ace-timing-overview">
            <div class="ace-timing-kinds">
                <div class="ace-timing-kind"><span class="ace-timing-type ace-timing-type-event">Day-of-event</span><p>Matters for a set number of days after it is published, like tips for a race or a match. Once that has passed it is judged on how it is read since, so it is never held back for being old.</p></div>
                <div class="ace-timing-kind"><span class="ace-timing-type ace-timing-type-season">Yearly season</span><p>Matters at the same time every year, like a festival or a tournament. It is only judged on a period that contains a whole season, so a quiet off-season never counts against it.</p></div>
                <div class="ace-timing-kind"><span class="ace-timing-type ace-timing-type-evergreen">Evergreen</span><p>Matters all year round, like a guide or reference page. It is judged on any period.</p></div>
            </div>
            <?php if ( ! $suggestions ) : ?>
                <p><em>No suggestions yet: there is no completed assessment, or no category or tag has a clear enough shape.</em></p>
            <?php else : ?>
                <p class="ace-timing-overview-line"><strong><span data-overview="total"><?php echo esc_html( number_format_i18n( $o['total'] ) ); ?></span> suggestions</strong> from this site's own data:
                    <span data-overview="event"><?php echo esc_html( number_format_i18n( $o['event'] ) ); ?></span> day-of-event,
                    <span data-overview="season"><?php echo esc_html( number_format_i18n( $o['season'] ) ); ?></span> seasonal,
                    <span data-overview="evergreen"><?php echo esc_html( number_format_i18n( $o['evergreen'] ) ); ?></span> evergreen.
                    <span data-overview="in_use"><?php echo esc_html( number_format_i18n( $o['in_use'] ) ); ?></span> in use, covering <span data-overview="in_use_posts"><?php echo esc_html( number_format_i18n( $o['in_use_posts'] ) ); ?></span> posts.</p>
                <p><button type="button" class="button button-primary" id="ace-timing-open">Review and choose timing rules</button>
                    <span class="description">Worked out <?php echo $computed ? esc_html( human_time_diff( $computed ) ) . ' ago' : 'now'; ?> from the saved assessment and refreshed after every check. <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ace_seo_timing_suggest' ), 'ace_seo_timing_suggest' ) ); ?>">Recalculate now</a></span></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The manager: a full-screen dialog with the categories and tags on the left and the selected one's
     * evidence, rule and choices on the right. Data travels as JSON; the panel is drawn by timing-rules.js.
     */
    public static function render_modal( array $options ) {
        $suggestions = self::current();
        if ( ! $suggestions ) {
            return;
        }
        $rules   = (array) ( $options['timing_rules'] ?? array() );
        $ignored = (array) ( $options['timing_ignored'] ?? array() );
        $items   = array();
        foreach ( $suggestions as $key => $s ) {
            $items[] = array(
                'key'        => $key,
                'label'      => $s['label'],
                'posts'      => (int) $s['posts'],
                'type'       => $s['type'],
                'rule'       => $s['rule'],
                'ruleName'   => self::rule_name( $s['rule'] ),
                'confidence' => (int) round( 100 * $s['confidence'] ),
                'why'        => $s['why'],
                'persistence' => $s['persistence'] ?? 0,
                'readShare'  => $s['read_share'] ?? 0,
                'cadence'    => $s['cadence_days'] ?? null,
                'years'      => $s['years'] ?? 0,
                'firstYear'  => $s['first_year'] ?? 0,
                'lastYear'   => $s['last_year'] ?? 0,
                'months'     => $s['months'] ?? array(),
                'season'     => $s['season'] ?? null,
                'current'    => isset( $rules[ $key ] ) ? AceSeoRetentionActions::rule_to_text( $rules[ $key ] ) : '',
                'ignored'    => in_array( $key, $ignored, true ),
            );
        }
        $rd = self::readership();
        ?>
        <dialog id="ace-timing-modal" class="ace-timing-dialog" aria-labelledby="ace-timing-modal-title" data-readership-known="<?php echo $rd['known'] ? '1' : '0'; ?>" data-readership-share="<?php echo esc_attr( $rd['share'] ); ?>">
            <div class="ace-modal-head">
                <div>
                    <h2 id="ace-timing-modal-title">Timing rules by category and tag</h2>
                    <p class="ace-modal-lead">Tell the report <strong>when</strong> each section's posts matter, so a seasonal piece is never called quiet in its off-season. Pick a category or tag on the left to see what its own data shows and what each rule would do. Nothing here changes a post; rules apply from the next check.</p>
                </div>
                <button type="button" class="ace-modal-close" data-ace-modal-cancel aria-label="Close without saving">&times;</button>
            </div>
            <?php if ( ! $rd['known'] ) : ?>
            <div class="ace-timing-banner" role="note"><strong>Only seasonal suggestions on this site.</strong> Just <?php echo esc_html( number_format_i18n( $rd['share'], 2 ) ); ?>% of assessed posts count as read, because there is no Google Analytics or enough of the site's own visitor counting yet. Day-of-event and evergreen suggestions depend on knowing which old posts are still read, so none are made rather than guessing. Seasons only need publication dates, so they still appear. You can still choose any rule yourself.</div>
            <?php endif; ?>
            <div class="ace-timing-layout">
                <div class="ace-timing-list-pane">
                    <div class="ace-timing-list-tools">
                        <input type="search" id="ace-timing-filter" placeholder="Search categories and tags" aria-label="Search categories and tags">
                        <select id="ace-timing-type" aria-label="Show">
                            <option value="">All suggestions</option>
                            <option value="event">Day-of-event</option>
                            <option value="season">Yearly season</option>
                            <option value="evergreen">Evergreen</option>
                            <option value="chosen">Rule chosen</option>
                            <option value="open">No rule yet</option>
                            <option value="ignored">Ignored</option>
                            <option value="mixed">No clear shape</option>
                        </select>
                        <span class="ace-modal-count" id="ace-timing-count"></span>
                    </div>
                    <ul class="ace-timing-list" id="ace-timing-list" role="listbox" aria-label="Categories and tags"></ul>
                </div>
                <div class="ace-timing-detail" id="ace-timing-detail" aria-live="polite"></div>
            </div>
            <div class="ace-modal-foot">
                <span class="ace-modal-status" role="status" aria-live="polite"></span>
                <span class="ace-timing-changes" id="ace-timing-changes"></span>
                <button type="button" class="button" data-ace-modal-cancel>Cancel</button>
                <button type="button" class="button button-primary" id="ace-timing-save">Save and close</button>
            </div>
            <script type="application/json" id="ace-timing-data"><?php echo wp_json_encode( $items, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
        </dialog>
        <?php
    }
}
