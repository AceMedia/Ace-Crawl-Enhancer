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
    /** Fewer assessed posts than this and a term tells us nothing reliable. */
    const MIN_POSTS = 30;

    public static function init() {
        add_action( 'ace_seo_retention_built', array( __CLASS__, 'recompute' ) );
        if ( is_admin() ) {
            add_action( 'admin_post_ace_seo_timing_suggest', array( __CLASS__, 'handle_recompute' ) );
            add_action( 'wp_ajax_ace_seo_timing_rules', array( __CLASS__, 'ajax_save' ) );
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
        $out = array();
        foreach ( $terms as $k => $t ) {
            if ( $t['n'] < $min_posts ) {
                continue;
            }
            $s = self::suggest_for( $t );
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
    public static function suggest_for( array $t ) {
        $n           = (int) $t['n'];
        $persistence = $n ? $t['persistent'] / $n : 0;      // share still read at least monthly, years later
        $read_share  = $n ? $t['read'] / $n : 0;
        sort( $t['dates'] );
        $season = self::season_band( $t['dates'] );
        $gap    = self::median_gap_days( $t['dates'] );
        $years  = count( array_unique( array_map( static function ( $d ) { return substr( $d, 0, 4 ); }, $t['dates'] ) ) );
        $base   = array( 'key' => $t['key'], 'label' => $t['label'], 'posts' => $n, 'persistence' => round( 100 * $persistence, 1 ), 'read_share' => round( 100 * $read_share, 1 ), 'years' => $years, 'cadence_days' => $gap );

        // Seasonal: most posts land in one part of the year, in more than one year.
        if ( $season && $years >= 2 ) {
            return $base + array(
                'type'       => 'season',
                'rule'       => 'season ' . $season['start'] . ' ' . $season['end'],
                'confidence' => min( 0.95, 0.5 + $season['share'] / 2 + min( 0.2, $years / 20 ) ),
                'why'        => sprintf( '%d%% of its %s posts were published between %s and %s, across %d years. Older posts should be judged in that season, not in the quiet months.', (int) round( 100 * $season['share'] ), number_format_i18n( $n ), self::md_label( $season['start'] ), self::md_label( $season['end'] ), $years ),
            );
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
        update_option( self::OPTION, array( 'computed' => time(), 'suggestions' => $suggestions ), false );
        return $suggestions;
    }

    /** Stored suggestions, computed now if there are none yet or the report has moved on. */
    public static function current() {
        $stored = get_option( self::OPTION, array() );
        $built  = (int) ( AceSeoRetentionReport::progress()['finished'] ?? 0 );
        if ( ! is_array( $stored ) || ! isset( $stored['suggestions'] ) || (int) ( $stored['computed'] ?? 0 ) < $built ) {
            return self::recompute();
        }
        return (array) $stored['suggestions'];
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

    /**
     * Inside the settings form: a short overview and a button. The table itself lives in a modal
     * rendered by render_modal() outside the form, which saves on its own and closes, or cancels.
     */
    public static function render_fields( array $options ) {
        $suggestions = self::current();
        $o           = self::overview( $suggestions, $options );
        $computed    = self::computed_at();
        wp_enqueue_script( 'ace-seo-timing-rules', ACE_SEO_URL . 'assets/js/timing-rules.js', array(), ACE_SEO_VERSION, true );
        wp_localize_script( 'ace-seo-timing-rules', 'aceSeoTimingRules', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ace_seo_timing_rules' ) ) );
        ?>
        <div id="ace-timing-overview" class="ace-timing-overview">
            <?php if ( ! $suggestions ) : ?>
                <p><em>No suggestions yet: there is no completed assessment, or no category or tag has a clear enough shape.</em></p>
            <?php else : ?>
                <p class="ace-timing-overview-line"><strong><span data-overview="total"><?php echo esc_html( number_format_i18n( $o['total'] ) ); ?></span> suggested rules</strong> from this site's own data:
                    <span data-overview="season"><?php echo esc_html( number_format_i18n( $o['season'] ) ); ?></span> seasonal,
                    <span data-overview="evergreen"><?php echo esc_html( number_format_i18n( $o['evergreen'] ) ); ?></span> evergreen,
                    <span data-overview="event"><?php echo esc_html( number_format_i18n( $o['event'] ) ); ?></span> event-bound.
                    <span data-overview="in_use"><?php echo esc_html( number_format_i18n( $o['in_use'] ) ); ?></span> in use (covering <span data-overview="in_use_posts"><?php echo esc_html( number_format_i18n( $o['in_use_posts'] ) ); ?></span> posts),
                    <span data-overview="ignored"><?php echo esc_html( number_format_i18n( $o['ignored'] ) ); ?></span> ignored<?php echo $o['mixed'] ? ', ' . esc_html( number_format_i18n( $o['mixed'] ) ) . ' large terms with no clear shape' : ''; ?>.</p>
                <p><button type="button" class="button" id="ace-timing-open">Manage suggested rules</button>
                    <span class="description">Worked out <?php echo $computed ? esc_html( human_time_diff( $computed ) ) . ' ago' : 'now'; ?> from the saved assessment; refreshed after every check. <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ace_seo_timing_suggest' ), 'ace_seo_timing_suggest' ) ); ?>">Recalculate now</a></span></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The modal: rendered outside any form. Saves through admin-ajax and closes, or cancels. */
    public static function render_modal( array $options ) {
        $suggestions = self::current();
        if ( ! $suggestions ) {
            return;
        }
        $rules   = (array) ( $options['timing_rules'] ?? array() );
        $ignored = (array) ( $options['timing_ignored'] ?? array() );
        $labels  = array( 'season' => 'Seasonal', 'evergreen' => 'Evergreen', 'event' => 'Event-bound', 'mixed' => 'No clear shape' );
        ?>
        <dialog id="ace-timing-modal" class="ace-timing-dialog" aria-labelledby="ace-timing-modal-title">
            <div class="ace-modal-head">
                <h2 id="ace-timing-modal-title">Timing rules by category and tag</h2>
                <button type="button" class="ace-modal-close" data-ace-modal-cancel aria-label="Close without saving">&times;</button>
            </div>
            <p class="ace-modal-lead">A timing rule tells the report <em>when</em> the posts in a category or tag matter, so it judges them on the right period instead of calling a seasonal piece quiet in the off-season. Each row shows what this site's own data suggests and why, and what that rule would do. Pick the suggestion, your own rule, or no rule; Ignore just hides a suggestion you do not want to see again. Nothing here changes a post; the rules apply from the next check.</p>
            <div class="ace-modal-tools">
                <label>Search <input type="search" id="ace-timing-filter" placeholder="Category or tag"></label>
                <label>Show <select id="ace-timing-type"><option value="">all</option><option value="season">seasonal suggestions</option><option value="evergreen">evergreen suggestions</option><option value="event">event-bound suggestions</option><option value="rule">with a rule set</option><option value="ignored">ignored</option><option value="mixed">no clear shape</option></select></label>
                <span class="ace-modal-count" id="ace-timing-count"></span>
            </div>
            <div class="ace-modal-body">
                <table class="widefat striped ace-timing-table">
                    <thead><tr><th scope="col">Category or tag</th><th scope="col">What the data suggests, and why</th><th scope="col">Rule to use</th><th scope="col">Ignore</th></tr></thead>
                    <tbody>
                    <?php foreach ( $suggestions as $key => $s ) :
                        $current_rule = isset( $rules[ $key ] ) ? AceSeoRetentionActions::rule_to_text( $rules[ $key ] ) : '';
                        $is_ignored   = in_array( $key, $ignored, true );
                        $has_sugg     = 'mixed' !== $s['type'];
                        $mode         = '' === $current_rule ? 'none' : ( $has_sugg && $current_rule === $s['rule'] ? 'suggested' : 'custom' );
                        $cur          = $current_rule ? AceSeoRetentionActions::parse_timing_rules( 'x:y = ' . $current_rule )['x:y'] : array();
                        ?>
                        <tr data-key="<?php echo esc_attr( $key ); ?>" data-type="<?php echo esc_attr( $s['type'] ); ?>" data-label="<?php echo esc_attr( strtolower( $s['label'] . ' ' . $key ) ); ?>" data-suggested="<?php echo esc_attr( $s['rule'] ); ?>" data-ignored="<?php echo $is_ignored ? '1' : '0'; ?>">
                            <th scope="row"><strong><?php echo esc_html( $s['label'] ); ?></strong><br><small><code><?php echo esc_html( $key ); ?></code> · <?php echo esc_html( number_format_i18n( $s['posts'] ) ); ?> assessed posts</small></th>
                            <td class="ace-timing-why">
                                <?php if ( $has_sugg ) : ?>
                                    <span class="ace-timing-type ace-timing-type-<?php echo esc_attr( $s['type'] ); ?>"><?php echo esc_html( $labels[ $s['type'] ] ); ?></span> <code><?php echo esc_html( $s['rule'] ); ?></code> <small>(<?php echo esc_html( (int) round( 100 * $s['confidence'] ) ); ?>% confidence)</small>
                                    <p class="ace-timing-meaning"><?php echo esc_html( self::explain_rule( $s['rule'] ) ); ?></p>
                                <?php else : ?>
                                    <span class="ace-timing-type"><?php echo esc_html( $labels['mixed'] ); ?></span>
                                <?php endif; ?>
                                <details><summary>Why the data says so</summary><p><?php echo esc_html( $s['why'] ); ?></p></details>
                            </td>
                            <td class="ace-timing-pick">
                                <select class="ace-timing-mode" aria-label="Rule for <?php echo esc_attr( $s['label'] ); ?>">
                                    <?php if ( $has_sugg ) : ?><option value="suggested" <?php selected( $mode, 'suggested' ); ?>>Use the suggestion (<?php echo esc_html( $s['rule'] ); ?>)</option><?php endif; ?>
                                    <option value="none" <?php selected( $mode, 'none' ); ?>>No rule</option>
                                    <option value="evergreen" <?php selected( 'custom' === $mode && 'evergreen' === ( $cur['type'] ?? '' ) ); ?>>Evergreen: always relevant</option>
                                    <option value="event" <?php selected( 'custom' === $mode && 'event' === ( $cur['type'] ?? '' ) ); ?>>Event-bound: N days from publication</option>
                                    <option value="season" <?php selected( 'custom' === $mode && 'season' === ( $cur['type'] ?? '' ) ); ?>>Yearly season: from … to …</option>
                                </select>
                                <span class="ace-timing-custom ace-timing-custom-event" hidden><input type="number" class="small-text ace-timing-days" min="1" max="366" value="<?php echo esc_attr( 'event' === ( $cur['type'] ?? '' ) ? (int) $cur['days'] : 3 ); ?>" aria-label="Days from publication"> days</span>
                                <span class="ace-timing-custom ace-timing-custom-season" hidden><input type="text" class="ace-timing-md ace-timing-start" pattern="[0-1][0-9]-[0-3][0-9]" placeholder="MM-DD" value="<?php echo esc_attr( $cur['start'] ?? '' ); ?>" aria-label="Season start, MM-DD"> to <input type="text" class="ace-timing-md ace-timing-end" pattern="[0-1][0-9]-[0-3][0-9]" placeholder="MM-DD" value="<?php echo esc_attr( $cur['end'] ?? '' ); ?>" aria-label="Season end, MM-DD"></span>
                                <p class="ace-timing-meaning ace-timing-chosen"></p>
                            </td>
                            <td><?php if ( $has_sugg ) : ?><label><input type="checkbox" class="ace-timing-ignore" value="<?php echo esc_attr( $key ); ?>" <?php checked( $is_ignored ); ?>> Ignore</label><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="ace-modal-foot">
                <span class="ace-modal-status" role="status" aria-live="polite"></span>
                <button type="button" class="button" data-ace-modal-cancel>Cancel</button>
                <button type="button" class="button button-primary" id="ace-timing-save">Save and close</button>
            </div>
        </dialog>
        <?php
    }
}
